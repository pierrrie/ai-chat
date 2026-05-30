<?php

namespace Draxter\Aichat;

class ContextSearch
{
    /** @return string[] */
    private static function topicWords(): array
    {
        $words = Settings::topicWords();
        if ($words !== []) {
            return $words;
        }

        if (CatalogProfile::isGardenOnly()) {
            return ['измельчит', 'щепорез', 'утилизатор', 'мульч', 'древесин', 'веткорез'];
        }

        return [
            'трицикл', 'мотоцикл', 'измельчит', 'щепорез', 'снегоход',
            'снегокат', 'минитрактор', 'мотобукс', 'гриль', 'мангал',
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function buildSearchQuery(array $messages): string
    {
        $userMsgs = array_values(array_map(
            static fn($m) => $m['content'],
            array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user')
        ));
        $last = $userMsgs[count($userMsgs) - 1] ?? '';
        $trimmed = trim($last);

        $hasBudget = static function (string $text): bool {
            return (bool)(preg_match('/\d[\d\s]{3,}\s*(?:руб|₽|р\.?)/ui', $text)
                || preg_match('/(?:бюджет|до)\s*\d/ui', $text));
        };

        $isFollowUp = count($userMsgs) > 1 && (
            preg_match(
                '/^(до\s+скольк|сколько|какой|какая|какие|а\s|он\s|она\s|они\s|это\s|эту\s|этой\s|этот\s|груз|кг|вес|размер|цена|может|вмещ|подъ|расскаж|подробн)/ui',
                $trimmed
            )
            || preg_match('/^(для\s+|нужен\s+|нужна\s+|нужно\s+|хочу\s+|чтобы\s+|под\s+)/ui', $trimmed)
            || (
                mb_strlen($trimmed) < 60
                && array_filter(array_slice($userMsgs, 0, -1), $hasBudget)
                && !$hasBudget($trimmed)
            )
        );

        if ($isFollowUp) {
            return trim(implode(' ', array_slice($userMsgs, -3)));
        }

        $appendKw = Settings::getString('intent.motorcycle_append_keyword', 'мотоцикл');
        if ($appendKw !== '' && Search::isMotorcycleQuery($last) && mb_strpos(mb_strtolower($last), $appendKw) === false) {
            return $last . ' ' . $appendKw;
        }

        return $last;
    }

    public static function detectTopic(string $text): ?string
    {
        if (Search::isMotorcycleQuery($text)) {
            return 'мотоцикл';
        }
        $lower = mb_strtolower($text);
        foreach (self::topicWords() as $word) {
            if (mb_strpos($lower, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    public static function isLoadCapacityQuestion(string $query): bool
    {
        return (bool)preg_match('/груз|кг|возить|вмещ|подъем|подъём|грузопод/ui', $query);
    }

    public static function isRacingTrackMotorcycleQuery(string $text): bool
    {
        return (bool)(preg_match('/гоноч|кольцев|трасс|треков|пит.?байк|supermoto|супермото/ui', $text)
            && preg_match('/мото|байк/ui', $text));
    }

    private static function productMatchesTopic(Product $product, string $topic): bool
    {
        $hay = mb_strtolower("{$product->name} {$product->category} {$product->description}");
        if ($topic === 'мотоцикл') {
            if (preg_match('/снегокат|снегоход|мотобукс|мотоснегокат/ui', $hay)) {
                return false;
            }
            return (bool)preg_match('/мотоцикл|мини\s*байк|питбайк|фурия|мотовездеход/ui', $hay);
        }
        return mb_strpos($hay, $topic) !== false;
    }

    /**
     * @param Product[] $products
     * @return Product[]
     */
    private static function filterForRacingTrackMotorcycle(array $products): array
    {
        $suitable = array_values(array_filter($products, static function (Product $p) {
            $n = mb_strtolower($p->name);
            if (preg_match('/мини\s*байк|питбайк/ui', $n)) {
                return true;
            }
            if (preg_match('/внедорож|мотовездеход|тирекс/ui', $n)) {
                return false;
            }
            if (!empty($p->specs['Грузоподъемность'])) {
                return false;
            }
            return mb_strpos($n, 'мотоцикл') !== false;
        }));

        if ($suitable) {
            return $suitable;
        }

        return array_values(array_filter($products, static function (Product $p) {
            return (bool)preg_match('/мини\s*байк|питбайк/ui', mb_strtolower($p->name));
        }));
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function wantsDetailedAnswer(string $query, array $messages): bool
    {
        $parts = array_map(static fn($m) => $m['content'] ?? '', array_slice($messages, -6));
        $parts[] = $query;
        $recent = implode(' ', $parts);
        return (bool)preg_match(
            '/подробн|расскаж|опиши|характеристик|детальн|что\s+входит|плюсы|минусы|сравни|в\s+чём\s+разниц|почему\s+именно|эта\s+модель|про\s+модель|о\s+модели|преимуществ|недостат|за\s+что\s+взять|для\s+чего\s+подойд|кому\s+подойд/ui',
            $recent
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return string[]
     */
    public static function extractMentionedProductNames(array $messages): array
    {
        $lastAssistant = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant') {
                $lastAssistant = $messages[$i]['content'] ?? '';
                break;
            }
        }
        if ($lastAssistant === null) {
            return [];
        }

        $names = [];
        if (preg_match_all('/\[([^\]]+)\]\([^)]+\)/u', $lastAssistant, $matches)) {
            foreach ($matches[1] as $name) {
                $name = trim($name);
                if (mb_strlen($name) > 8) {
                    $names[] = $name;
                }
            }
        }
        return $names;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return Product[]
     */
    public static function findProductsForChat(array $messages, int $limit = 5): array
    {
        $userMsgs = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user'));
        $lastUser = $userMsgs[count($userMsgs) - 1]['content'] ?? '';
        if (LeadFlow::isSmallTalkUserMessage($lastUser)) {
            return [];
        }
        $detailed = self::wantsDetailedAnswer($lastUser, $messages);

        if ($detailed) {
            $mentioned = self::extractMentionedProductNames($messages);
            $nameQuery = $mentioned[0] ?? (mb_strlen($lastUser) > 35 ? $lastUser : null);
            if ($nameQuery) {
                $focused = Search::findRelevantProducts($nameQuery, 3);
                if ($focused) {
                    return array_slice($focused, 0, 1);
                }
            }
        }

        $searchQuery = self::buildSearchQuery($messages);
        $series = ModelSeries::extractModelSeries($searchQuery);
        $productLimit = $detailed ? 3 : $limit;
        $products = [];

        if (count($series) >= 2 && ModelSeries::isComparisonQuery($searchQuery)) {
            $merged = [];
            $perSeries = max(3, (int)ceil($productLimit / count($series)));
            foreach ($series as $code) {
                foreach (Search::findRelevantProducts($code, $perSeries) as $p) {
                    $exists = false;
                    foreach ($merged as $x) {
                        if ($x->id === $p->id) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $merged[] = $p;
                    }
                }
            }
            if ($merged) {
                $products = array_slice($merged, 0, max($productLimit, 6));
            }
        }

        if (!$products) {
            $products = Search::findRelevantProducts(
                $searchQuery,
                count($series) === 1 ? max($productLimit, 5) : $productLimit
            );
        }

        $topic = self::detectTopic($searchQuery);
        if ($topic) {
            $filtered = array_values(array_filter($products, static function (Product $p) use ($topic) {
                return self::productMatchesTopic($p, $topic);
            }));
            if ($filtered) {
                $products = $filtered;
            }
        }

        if (!CatalogProfile::isGardenOnly()
            && (Search::isMotorcycleQuery($searchQuery) || Search::isMotorcycleQuery($lastUser))) {
            $motos = array_values(array_filter($products, static fn(Product $p) => Search::isRealMotorcycleProduct($p)));
            if (count($motos) < 2) {
                $motos = array_values(array_filter(
                    Search::findRelevantProducts('мотоцикл внедорожный profi тирекс мини байк', $limit),
                    static fn(Product $p) => Search::isRealMotorcycleProduct($p)
                ));
            }
            if ($motos) {
                $products = array_slice($motos, 0, $limit);
            }
        }

        if (self::isRacingTrackMotorcycleQuery($searchQuery)) {
            $products = self::filterForRacingTrackMotorcycle($products);
            if (!$products) {
                $products = Search::findRelevantProducts($searchQuery . ' мини байк', $limit);
                $products = self::filterForRacingTrackMotorcycle($products);
            }
        }

        if (Search::isPmuQuery($searchQuery) || Search::isPmuQuery($lastUser)) {
            $pmu = array_values(array_filter($products, static fn(Product $p) => Search::isPmuProduct($p)));
            if ($pmu) {
                $rest = array_values(array_filter($products, static fn(Product $p) => !in_array($p->id, array_map(static fn(Product $x) => $x->id, $pmu), true)));
                $products = array_slice(array_merge($pmu, $rest), 0, $limit);
            }
        } elseif (preg_match('/мульч/ui', $searchQuery)) {
            $garden = array_values(array_filter($products, static function (Product $p) {
                $n = mb_strtolower($p->name);
                $c = mb_strtolower($p->category);
                return (bool)preg_match('/садовый измельчит|садов.*измельчит/ui', $n)
                    || (mb_strpos($n, 'измельчит') !== false && mb_strpos($c, 'садов') !== false);
            }));
            if ($garden) {
                $products = $garden;
            }
        }

        return $products;
    }
}
