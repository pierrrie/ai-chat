<?php

namespace Draxter\Aichat;

class ResponseEnrich
{
    /**
     * Дописывает в поток блок ссылок, если модель их не вставила.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function buildStreamSuffix(string $text, array $messages, string $siteUrl): string
    {
        $text = trim($text);
        if ($text === '' || !Settings::getBool('ENRICH_LINKS_ENABLED', false)) {
            return '';
        }

        $lastUser = self::lastUserMessage($messages);
        if ($lastUser !== '' && Settings::getBool('ENRICH_LINKS_SKIP_PHRASE', true)
            && CustomPrompts::hasPhraseMatch($lastUser)) {
            return '';
        }

        if (!self::shouldAppendProductLinks($text, $messages, $lastUser)) {
            return '';
        }

        $products = self::collectProductsForSuffix($text, $messages, $lastUser);
        if ($products === []) {
            return '';
        }

        $max = max(1, Settings::getInt('ENRICH_LINKS_MAX', 6));

        $enriched = self::injectProductLinks($text, $products, $siteUrl);
        if ($enriched !== $text && self::hasMarkdownLinks($enriched)) {
            return mb_substr($enriched, mb_strlen($text));
        }

        if (self::hasMarkdownLinks($text)) {
            $missing = [];
            foreach ($products as $p) {
                $url = Catalog::productLink($p, $siteUrl);
                if (!str_contains($text, $url)) {
                    $missing[] = $p;
                }
            }
            if ($missing === []) {
                return '';
            }
            $products = $missing;
        }

        return self::formatLinkBlockSuffix($products, $siteUrl, $max);
    }

    /**
     * @param Product[] $products
     */
    public static function enrich(string $text, array $products, string $siteUrl): string
    {
        if ($text === '' || !$products || !Settings::getBool('ENRICH_LINKS_ENABLED', false)) {
            return $text;
        }
        $withLinks = self::injectProductLinks($text, $products, $siteUrl);
        if (self::hasMarkdownLinks($withLinks)) {
            return $withLinks;
        }

        $max = max(1, Settings::getInt('ENRICH_LINKS_MAX', 6));

        return $withLinks . self::formatLinkBlockSuffix($products, $siteUrl, $max);
    }

    private static function shouldAppendProductLinks(string $assistantText, array $messages, string $lastUser): bool
    {
        if (Settings::getBool('ENRICH_LINKS_ON_CONTEXT', false) && $lastUser !== '') {
            if (ContextSearch::findProductsForChat($messages, 1) !== []) {
                return true;
            }
        }

        if ($lastUser !== '' && Settings::getBool('ENRICH_LINKS_ON_REQUEST', true)
            && self::userWantsProductLinks($lastUser)) {
            return true;
        }

        if (Settings::getBool('ENRICH_LINKS_ON_MENTION', true)
            && self::productsMentionedInText($assistantText) !== []) {
            return true;
        }

        if ($lastUser !== '' && Settings::getBool('ENRICH_LINKS_ON_SHOPPING', true)
            && LeadFlow::hasProductShoppingIntent($lastUser)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return Product[]
     */
    private static function collectProductsForSuffix(string $assistantText, array $messages, string $lastUser): array
    {
        $byId = [];
        foreach (self::productsMentionedInText($assistantText) as $p) {
            $byId[$p->id] = $p;
        }

        if ($lastUser !== '' && self::userWantsProductLinks($lastUser)) {
            foreach (ContextSearch::findProductsForChat($messages, 8) as $p) {
                $byId[$p->id] = $p;
            }
            $lastAssistant = self::lastAssistantMessage($messages);
            if ($lastAssistant !== '') {
                foreach (self::productsMentionedInText($lastAssistant) as $p) {
                    $byId[$p->id] = $p;
                }
            }
        } elseif ($lastUser !== '' && LeadFlow::hasProductShoppingIntent($lastUser)) {
            foreach (ContextSearch::findProductsForChat($messages, 6) as $p) {
                $byId[$p->id] = $p;
            }
        }

        return array_values($byId);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string)($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function lastAssistantMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant') {
                return trim((string)($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    public static function userWantsProductLinks(string $text): bool
    {
        return (bool)preg_match(
            '/ссылк|url\b|где\s+купить|на\s+сайт|страниц.{0,10}товар|прям.{0,6}ссыл/ui',
            $text
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return Product[]
     */
    public static function collectProducts(string $assistantText, array $messages): array
    {
        $byId = [];
        foreach (ContextSearch::findProductsForChat($messages, 12) as $p) {
            $byId[$p->id] = $p;
        }
        foreach (self::productsMentionedInText($assistantText) as $p) {
            $byId[$p->id] = $p;
        }

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        if ($lastUser !== '' && self::userWantsProductLinks($lastUser)) {
            $lastAssistant = '';
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'assistant') {
                    $lastAssistant = $messages[$i]['content'] ?? '';
                    break;
                }
            }
            if ($lastAssistant !== '') {
                foreach (self::productsMentionedInText($lastAssistant) as $p) {
                    $byId[$p->id] = $p;
                }
            }
        }

        return array_values($byId);
    }

    /**
     * @return Product[]
     */
    public static function productsMentionedInText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];
        $lower = mb_strtolower($text);
        foreach (Catalog::getAllProducts() as $p) {
            $nameLower = mb_strtolower($p->name);
            if (mb_strpos($lower, $nameLower) !== false) {
                $found[$p->id] = $p;
                continue;
            }
            $short = mb_substr($p->name, 0, 28);
            if (mb_strlen($short) >= 12 && mb_stripos($text, $short) !== false) {
                $found[$p->id] = $p;
                continue;
            }
            if (preg_match('/[сc]\s*[-–]?\s*380/ui', $p->name) && preg_match('/[сc]\s*[-–]?\s*380/ui', $text)) {
                $wantsSport = (bool)preg_match('/sport|спорт/ui', $text);
                $wantsExtreme = (bool)preg_match('/extreme|экстрим/ui', $text);
                $wantsLite = (bool)preg_match('/\blite\b|лайт/ui', $text);
                $isSport = (bool)preg_match('/sport|спорт/ui', $p->name);
                $isExtreme = (bool)preg_match('/extreme|экстрим/ui', $p->name);
                $isLite = (bool)preg_match('/\blite\b|лайт/ui', $p->name);
                if (($wantsSport && $isSport) || ($wantsExtreme && $isExtreme) || ($wantsLite && $isLite)
                    || (!$wantsSport && !$wantsExtreme && !$wantsLite)) {
                    $found[$p->id] = $p;
                }
            }
        }

        return array_values($found);
    }

    private static function hasMarkdownLinks(string $text): bool
    {
        return (bool)preg_match('/\[[^\]]+\]\(https?:\/\/[^\s)]+\)/ui', $text);
    }

    /**
     * @param Product[] $products
     */
    private static function injectProductLinks(string $text, array $products, string $siteUrl): string
    {
        $result = $text;
        usort($products, static fn(Product $a, Product $b) => mb_strlen($b->name) <=> mb_strlen($a->name));

        foreach ($products as $p) {
            $url = Catalog::productLink($p, $siteUrl);
            if ($url === '' || str_contains($result, $url)) {
                continue;
            }
            $variants = [$p->name, str_replace(['«', '»'], '"', $p->name)];
            if (preg_match('/«[^»]+»/u', $p->name, $m)) {
                $variants[] = trim($m[0]);
            }
            foreach ($variants as $variant) {
                if (mb_strlen($variant) < 10) {
                    continue;
                }
                $esc = preg_quote($variant, '/');
                $re = '/(?<!\[)' . $esc . '(?!\]\()/u';
                if (preg_match($re, $result)) {
                    $result = preg_replace($re, '[' . $variant . '](' . $url . ')', $result, 1);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param Product[] $products
     */
    private static function formatLinkBlockSuffix(array $products, string $siteUrl, int $max = 6): string
    {
        $block = self::formatLinkBlock($products, $siteUrl, $max);
        if ($block === '') {
            return '';
        }

        return "\n\n" . $block . "\n";
    }

    /**
     * @param Product[] $products
     */
    private static function formatLinkBlock(array $products, string $siteUrl, int $max = 6): string
    {
        $title = trim(Settings::get('ENRICH_LINKS_TITLE', 'Ссылки на товары:'));
        if ($title === '') {
            $title = 'Ссылки на товары:';
        }
        $lines = ['**' . $title . '**', ''];
        foreach (array_slice($products, 0, $max) as $p) {
            $url = Catalog::productLink($p, $siteUrl);
            if ($url === '') {
                continue;
            }
            $stock = $p->inStock ? 'в наличии' : 'нет в наличии';
            $lines[] = '**[' . $p->name . '](' . $url . ')** — ' . Catalog::formatPrice($p) . ' · ' . $stock;
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }
}
