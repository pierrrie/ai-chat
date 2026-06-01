<?php

namespace Draxter\Aichat;

class CatalogPrompt
{
    /** Сколько позиций можно вывести в промпт целиком (больше — указатель + подбор). */
    private const INLINE_FULL_MAX = 250;

    private const LARGE_RELEVANT_LIMIT = 50;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function detectCatalogScope(string $lastUserText, array $messages = []): string
    {
        if (CatalogProfile::isFullCatalog()) {
            return 'overview';
        }

        $recent = $lastUserText;
        $n = 0;
        for ($i = count($messages) - 1; $i >= 0 && $n < 2; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $recent .= ' ' . ($messages[$i]['content'] ?? '');
            $n++;
        }

        if (CatalogProfile::isGardenOnly()) {
            return 'garden';
        }

        if (CatalogProfile::scopeEnabled('motorcycle')
            && (Search::isMotorcycleQuery($recent)
                || (preg_match('/лес|бездорож/ui', $recent) && preg_match('/мото|мотик/ui', $recent)))
        ) {
            return 'motorcycle';
        }
        if (CatalogProfile::scopeEnabled('snow')
            && preg_match('/мотобукс|мотособак|снегокат|снегоход|\bзим/ui', $recent)
        ) {
            return 'snow';
        }
        if (preg_match('/щепорез|измельчит|мульч|пму|ветк|садов|утилизатор|древесин/ui', $recent)) {
            return 'garden';
        }
        if (CatalogProfile::scopeEnabled('tractor') && preg_match(self::tractorQueryPattern(), $recent)) {
            return 'tractor';
        }
        if (CatalogProfile::scopeEnabled('grill') && preg_match('/гриль|мангал|барбекю/ui', $recent)) {
            return 'grill';
        }

        return CatalogProfile::isGardenOnly() ? 'garden' : 'overview';
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function buildForAiPrompt(string $siteUrl, array $messages = []): string
    {
        if (CatalogProfile::isFullCatalog()) {
            return self::fullCatalogBlock($siteUrl, $messages);
        }

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }

        $recentText = self::recentDialogText($messages, 5);
        $compareContext = trim($recentText . ' ' . $lastUser);
        if (self::needsFocusedCatalog($compareContext)) {
            $focused = Search::findRelevantProducts($compareContext, 15);
            if (count($focused) >= 2) {
                return '=== Товары для сравнения (' . count($focused) . ') ===' . "\n"
                    . self::compactLines($focused, $siteUrl);
            }
        }

        $scope = self::detectCatalogScope($lastUser, $messages);
        $all = Catalog::getAllProducts();

        if ($scope === 'motorcycle' && CatalogProfile::scopeEnabled('motorcycle')) {
            $motos = array_values(array_filter($all, static fn(Product $p) => Search::isRealMotorcycleProduct($p)));

            return '=== МОТОЦИКЛЫ (' . count($motos) . ' позиций; «мотик»/«мот» = только эта секция) ===' . "\n"
                . self::compactLines($motos, $siteUrl) . "\n\n"
                . "=== Другие категории (не подставлять вместо мотоцикла) ===\n"
                . self::categoryIndex();
        }

        if ($scope === 'snow' && CatalogProfile::scopeEnabled('snow')) {
            return self::compactLines(self::filterProducts($all, '/мотобукс|снегокат|снегоход/ui'), $siteUrl);
        }
        if ($scope === 'garden') {
            $garden = self::filterProducts(
                $all,
                '/щепорез|измельчит|мульч|пму|ветк|садов|утилизатор|древесин/ui'
            );
            if ($garden === [] && CatalogProfile::isGardenOnly()) {
                $garden = $all;
            }

            return '=== Каталог измельчителей и щепорезов (' . count($garden) . ") ===\n"
                . self::compactLines($garden, $siteUrl);
        }
        if ($scope === 'tractor') {
            $items = self::sortTractorsFirst(self::filterProducts($all, self::tractorProductPattern()));

            return '=== Минитракторы и навесное (' . count($items) . ") ===\n"
                . self::compactLines($items, $siteUrl);
        }
        if ($scope === 'grill') {
            return self::compactLines(self::filterProducts($all, '/гриль|мангал|барбекю/ui'), $siteUrl);
        }

        return self::overviewCatalogBlock($siteUrl, $all, $messages);
    }

    /**
     * @param Product[] $all
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function fullCatalogBlock(string $siteUrl, array $messages): string
    {
        return self::overviewCatalogBlock($siteUrl, Catalog::getAllProducts(), $messages);
    }

    /**
     * @param Product[] $all
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function overviewCatalogBlock(string $siteUrl, array $all, array $messages): string
    {
        $total = count($all);
        $index = self::categoryIndexFromProducts($all);

        if ($total <= self::INLINE_FULL_MAX) {
            return 'Указатель категорий:' . "\n" . $index
                . "\n\n=== Полный каталог (" . $total . ") ===\n"
                . self::compactLines($all, $siteUrl, false);
        }

        $query = trim(self::extractLastUserText($messages) . ' ' . self::recentDialogText($messages, 3));
        $relevant = $query !== ''
            ? Search::findRelevantProducts($query, self::LARGE_RELEVANT_LIMIT)
            : [];

        $block = 'В каталоге ' . $total . ' товаров. В промпт нельзя загрузить все позиции сразу — '
            . "используй указатель категорий и список ниже.\n"
            . "Указатель категорий:\n" . $index;

        if ($relevant !== []) {
            $block .= "\n\n=== Подбор по запросу (" . count($relevant) . ") ===\n"
                . self::compactLines($relevant, $siteUrl, true);
        } else {
            $block .= "\n\n=== Примеры из категорий ===\n"
                . self::compactLines(self::samplePerCategory($all, 2, 80), $siteUrl, true);
        }

        $block .= "\n\nНе пиши «нет в каталоге», пока не проверил подходящую категорию в указателе.";

        return $block;
    }

    /**
     * @param Product[] $products
     */
    private static function compactLines(array $products, string $siteUrl, bool $minimal = false): string
    {
        $lines = [];
        foreach ($products as $p) {
            $stock = $p->inStock ? 'в наличии' : 'нет';
            $url = Catalog::productLink($p, $siteUrl);
            $line = '[' . $p->id . '] ' . $p->name . ' | ' . $p->category . ' | '
                . Catalog::formatPrice($p) . ' | ' . $stock . ' | URL: ' . $url;
            if (!$minimal) {
                $specs = [];
                $n = 0;
                foreach ($p->specs as $k => $v) {
                    if ($n++ >= 5) {
                        break;
                    }
                    $specs[] = $k . ': ' . $v;
                }
                $desc = trim(preg_replace('/\s+/u', ' ', $p->description) ?? '');
                if (mb_strlen($desc) > 80) {
                    $desc = mb_substr($desc, 0, 80);
                }
                $tail = implode(' | ', array_filter([implode('; ', $specs), $desc]));
                if ($tail !== '') {
                    $line .= ' | ' . $tail;
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param Product[] $products
     */
    private static function categoryIndexFromProducts(array $products): string
    {
        $byCat = [];
        foreach ($products as $p) {
            $c = $p->category !== '' ? $p->category : 'Прочее';
            $byCat[$c][] = $p;
        }
        $lines = [];
        foreach ($byCat as $cat => $items) {
            $sample = [];
            foreach (array_slice($items, 0, 2) as $item) {
                $sample[] = $item->name;
            }
            $suffix = count($items) > 2 ? '…' : '';
            $lines[] = '• ' . $cat . ' (' . count($items) . '): ' . implode('; ', $sample) . $suffix;
        }

        return implode("\n", $lines);
    }

    private static function categoryIndex(): string
    {
        return self::categoryIndexFromProducts(Catalog::getAllProducts());
    }

    /**
     * @param Product[] $all
     * @return Product[]
     */
    private static function samplePerCategory(array $all, int $perCategory, int $maxTotal): array
    {
        $byCat = [];
        foreach ($all as $p) {
            $c = $p->category !== '' ? $p->category : 'Прочее';
            $byCat[$c][] = $p;
        }
        $out = [];
        foreach ($byCat as $items) {
            foreach (array_slice($items, 0, $perCategory) as $p) {
                $out[] = $p;
                if (count($out) >= $maxTotal) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function extractLastUserText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string)($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    private static function tractorQueryPattern(): string
    {
        return '/минитрактор|мини[\s\-–—]*трактор|мотоблок|косилк.*трактор|трактор.*косилк/ui';
    }

    private static function tractorProductPattern(): string
    {
        return '/минитрактор|мини[\s\-–—]*трактор|мотоблок/ui';
    }

    /**
     * @param Product[] $products
     * @return Product[]
     */
    private static function sortTractorsFirst(array $products): array
    {
        usort($products, static function (Product $a, Product $b): int {
            $rank = static function (Product $p): int {
                $n = mb_strtolower($p->name);
                if (preg_match('/^мини[\s\-–—]*трактор/ui', $n)) {
                    return 0;
                }
                if (preg_match('/мотоблок/ui', $n) && !preg_match('/приставк|пму/ui', $n)) {
                    return 1;
                }

                return 2;
            };

            return $rank($a) <=> $rank($b);
        });

        return $products;
    }

    /**
     * @param Product[] $products
     * @return Product[]
     */
    private static function filterProducts(array $products, string $pattern): array
    {
        return array_values(array_filter($products, static function (Product $p) use ($pattern) {
            $hay = $p->name . ' ' . $p->category . ' ' . $p->description;

            return (bool)preg_match($pattern, $hay);
        }));
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function recentDialogText(array $messages, int $maxMsgs): string
    {
        $parts = [];
        $n = 0;
        for ($i = count($messages) - 1; $i >= 0 && $n < $maxMsgs; $i--) {
            $content = trim((string)($messages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $parts[] = $content;
            $n++;
        }

        return implode(' ', array_reverse($parts));
    }

    private static function needsFocusedCatalog(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (ModelSeries::isComparisonQuery($text)) {
            return true;
        }

        return (bool)(preg_match('/отлича|разниц|чем\s+лучше/ui', $text)
            && preg_match('/\b(лайт|light|про|pro|sport|спорт|стандарт|базов|classic)\b/ui', $text));
    }
}
