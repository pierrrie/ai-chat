<?php

namespace Draxter\Aichat;

class CustomPrompts
{
    public static function raw(): string
    {
        return trim(Settings::get('PROMPT_CUSTOM_BLOCKS', ''));
    }

    /**
     * @return array<int, array{title: string, mode: string, phrase: string, body: string}>
     */
    public static function parseBlocks(string $raw): array
    {
        $sections = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $current = null;

        $flush = static function () use (&$sections, &$current): void {
            if ($current === null) {
                return;
            }
            $body = trim((string)($current['body'] ?? ''));
            if ($body === '' && trim((string)($current['title'] ?? '')) === '') {
                $current = null;
                return;
            }
            $sections[] = [
                'title' => trim((string)($current['title'] ?? '')),
                'mode' => self::normalizeMode((string)($current['mode'] ?? 'always')),
                'phrase' => trim((string)($current['phrase'] ?? '')),
                'body' => $body,
            ];
            $current = null;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed === '---') {
                $flush();
                continue;
            }

            if (preg_match('/^#\s*(?:название|title)\s*:\s*(.+)$/ui', $trimmed, $m)) {
                $flush();
                $current = ['title' => trim($m[1]), 'mode' => 'always', 'phrase' => '', 'body' => ''];
                continue;
            }

            if (preg_match('/^#\s*(?:режим|mode)\s*:\s*(.+)$/ui', $trimmed, $m)) {
                if ($current === null) {
                    $current = ['title' => '', 'mode' => 'always', 'phrase' => '', 'body' => ''];
                }
                $current['mode'] = self::normalizeMode(trim($m[1]));
                continue;
            }

            if (preg_match('/^#\s*(?:вопрос|фраза|предложение|ключевые\s*слова|keywords)\s*:\s*(.+)$/ui', $trimmed, $m)) {
                if ($current === null) {
                    $current = ['title' => '', 'mode' => 'phrase', 'phrase' => '', 'body' => ''];
                }
                $current['phrase'] = trim($m[1]);
                if ($current['mode'] === 'always') {
                    $current['mode'] = 'phrase';
                }
                continue;
            }

            if ($current === null) {
                $current = ['title' => '', 'mode' => 'always', 'phrase' => '', 'body' => ''];
            }
            $current['body'] .= ($current['body'] !== '' ? "\n" : '') . $line;
        }

        $flush();

        return $sections;
    }

    public static function buildPromptSection(string $shopName, bool $smallTalk, string $lastUser = ''): string
    {
        $vars = [
            'shop_name' => $shopName,
            'brand' => Config::brand(),
        ];

        $parts = [];
        foreach (self::parseBlocks(self::raw()) as $block) {
            if ($block['body'] === '') {
                continue;
            }
            if (in_array($block['mode'], ['phrase', 'keywords'], true)) {
                continue;
            }
            if (!self::matchesMode($block, $smallTalk, $lastUser)) {
                continue;
            }

            $text = Settings::format($block['body'], $vars);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    public static function buildPhraseOverrideSection(
        string $shopName,
        bool $smallTalk,
        string $lastUser = '',
        ?string $rawOverride = null
    ): string {
        if ($smallTalk || trim($lastUser) === '') {
            return '';
        }

        $vars = [
            'shop_name' => $shopName,
            'brand' => Config::brand(),
        ];

        $parts = [];
        foreach (self::parseBlocks($rawOverride ?? self::raw()) as $block) {
            if ($block['body'] === '' || !in_array($block['mode'], ['phrase', 'keywords'], true)) {
                continue;
            }
            if (!self::matchesPhrase($lastUser, $block['phrase'])) {
                continue;
            }

            $text = Settings::format($block['body'], $vars);
            if ($text === '') {
                continue;
            }

            $title = $block['title'] !== '' ? $block['title'] : 'Ответ по вопросу клиента';
            $parts[] = "--- {$title} ---\n"
                . "Клиент задал вопрос по этой теме. Ответь по этой инструкции — она важнее справочника FAQ и каталога. "
                . "Не пиши, что информации нет, если она указана ниже.\n"
                . $text
                . "\n--- Конец {$title} ---";
        }

        return implode("\n\n", $parts);
    }

    public static function hasPhraseMatch(string $lastUser, bool $smallTalk = false, ?string $rawOverride = null): bool
    {
        if ($smallTalk || trim($lastUser) === '') {
            return false;
        }

        foreach (self::parseBlocks($rawOverride ?? self::raw()) as $block) {
            if (!in_array($block['mode'], ['phrase', 'keywords'], true) || $block['body'] === '') {
                continue;
            }
            if (self::matchesPhrase($lastUser, $block['phrase'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{title: string, phrase: string, body: string}>
     */
    public static function matchedPhraseBlocks(string $lastUser, ?string $rawOverride = null): array
    {
        if (trim($lastUser) === '') {
            return [];
        }

        $matched = [];
        foreach (self::parseBlocks($rawOverride ?? self::raw()) as $block) {
            if ($block['body'] === '' || !in_array($block['mode'], ['phrase', 'keywords'], true)) {
                continue;
            }
            if (!self::matchesPhrase($lastUser, $block['phrase'])) {
                continue;
            }
            $matched[] = [
                'title' => $block['title'],
                'phrase' => $block['phrase'],
                'body' => $block['body'],
            ];
        }

        return $matched;
    }

    /**
     * @param array{mode: string, phrase: string} $block
     */
    private static function matchesMode(array $block, bool $smallTalk, string $lastUser): bool
    {
        switch ($block['mode']) {
            case 'catalog':
                return !$smallTalk;
            case 'small_talk':
                return $smallTalk;
            case 'purchase':
                return $lastUser !== '' && LeadFlow::isPurchaseIntent($lastUser);
            case 'phrase':
            case 'keywords':
                return self::matchesPhrase($lastUser, $block['phrase']);
            default:
                return true;
        }
    }

    private static function matchesPhrase(string $text, string $phraseRaw): bool
    {
        if ($phraseRaw === '' || trim($text) === '') {
            return false;
        }

        $userNorm = self::normalizeText($text);
        foreach (self::splitPhrases($phraseRaw) as $phrase) {
            $phraseNorm = self::normalizeText($phrase);
            if ($phraseNorm === '') {
                continue;
            }

            if (mb_strpos($userNorm, $phraseNorm) !== false) {
                return true;
            }

            if (mb_strlen($userNorm) <= mb_strlen($phraseNorm) + 8 && mb_strpos($phraseNorm, $userNorm) !== false) {
                return true;
            }

            similar_text($userNorm, $phraseNorm, $percent);
            if ($percent >= 55.0) {
                return true;
            }

            $words = self::significantWords($phraseNorm);
            if ($words !== [] && self::wordOverlapRatio($userNorm, $words) >= 0.6) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function splitPhrases(string $raw): array
    {
        $parts = preg_split('/[\r\n|]+/u', $raw) ?: [];
        $phrases = [];
        foreach ($parts as $part) {
            $phrase = trim($part);
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    private static function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return string[]
     */
    private static function significantWords(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $result = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) >= 3) {
                $result[] = $word;
            }
        }

        return $result;
    }

    /**
     * @param string[] $words
     */
    private static function wordOverlapRatio(string $userNorm, array $words): float
    {
        if ($words === []) {
            return 0.0;
        }

        $found = 0;
        foreach ($words as $word) {
            if (mb_strpos($userNorm, $word) !== false) {
                $found++;
            }
        }

        return $found / count($words);
    }

    private static function normalizeMode(string $mode): string
    {
        $m = mb_strtolower(trim($mode));
        if (in_array($m, ['catalog', 'с каталогом', 'каталог', 'товары'], true)) {
            return 'catalog';
        }
        if (in_array($m, ['small_talk', 'small talk', 'приветствие', 'приветствия', 'smalltalk'], true)) {
            return 'small_talk';
        }
        if (in_array($m, ['purchase', 'покупка', 'купить', 'заказ', 'клиент готов купить'], true)) {
            return 'purchase';
        }
        if (in_array($m, ['phrase', 'вопрос', 'фраза', 'предложение', 'по вопросу', 'свой вопрос', 'по вопросу клиента'], true)) {
            return 'phrase';
        }
        if (in_array($m, ['keywords', 'ключевые слова', 'ключевые', 'свои слова', 'по словам', 'свои ключевые слова'], true)) {
            return 'phrase';
        }
        if (str_contains($m, 'вопрос') || str_contains($m, 'фраз') || str_contains($m, 'предложен')) {
            return 'phrase';
        }

        return 'always';
    }
}
