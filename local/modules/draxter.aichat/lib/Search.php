<?php

namespace Draxter\Aichat;

class Search
{
    public static function isPmuQuery(string $query): bool
    {
        $q = ModelSeries::normalizeModelQuery($query);
        return (bool)preg_match('/пму|\(пму\)|приставк[аи]?\s*(?:для\s+)?мотоблок/ui', $q);
    }

    public static function isPmuProduct(Product $product): bool
    {
        $hay = self::productSearchText($product);
        return (bool)preg_match('/\(пму\)|\bпму\b|приставк.*мотоблок/ui', $hay);
    }

    /** @return Product[] */
    public static function findRelevantProducts(string $query, int $limit = 6): array
    {
        $tokens = self::tokenize($query);
        $priceFilter = self::parsePriceFilter($query);
        $hasPriceFilter = $priceFilter['min'] !== null || $priceFilter['max'] !== null;

        $pool = Catalog::getAllProducts();
        if ($hasPriceFilter) {
            $pool = array_values(array_filter($pool, static function (Product $p) use ($priceFilter) {
                if ($priceFilter['max'] !== null && $p->price > $priceFilter['max']) {
                    return false;
                }
                if ($priceFilter['min'] !== null && $p->price < $priceFilter['min']) {
                    return false;
                }
                return true;
            }));
        }

        if (!$tokens) {
            return array_slice($pool, 0, $limit);
        }

        $seriesCodes = ModelSeries::extractModelSeries($query);
        $q = ModelSeries::normalizeModelQuery($query);
        $scored = [];

        foreach ($pool as $product) {
            $haystack = self::productSearchText($product);
            $name = mb_strtolower($product->name);
            $score = 0;

            foreach ($seriesCodes as $code) {
                if (ModelSeries::productMatchesSeries($product->name, $code)) {
                    $score += 40;
                } elseif (mb_strpos($haystack, ModelSeries::normalizeModelQuery($code)) !== false) {
                    $score += 25;
                }
            }

            foreach ($tokens as $token) {
                if (preg_match('/^\d+$/', $token)) {
                    foreach ($seriesCodes as $c) {
                        if (mb_strpos($c, $token) !== false) {
                            $score += 8;
                        }
                    }
                    continue;
                }
                if (mb_strpos($haystack, $token) !== false) {
                    $score += 2;
                }
                foreach ($product->tags as $t) {
                    if (mb_strpos($t, $token) !== false || mb_strpos($token, $t) !== false) {
                        $score += 3;
                    }
                }
                if (mb_strpos(mb_strtolower($product->category), $token) !== false) {
                    $score += 3;
                }
                if (mb_strpos($name, $token) !== false) {
                    $score += 5;
                }
                foreach ($product->specs as $val) {
                    if (mb_strpos(mb_strtolower($val), $token) !== false) {
                        $score += 2;
                    }
                }
            }

            $cat = mb_strtolower($product->category);
            $desc = mb_strtolower($product->description);

            if (self::isPmuQuery($q) && self::isPmuProduct($product)) {
                $score += 100;
            }
            if (preg_match('/мульч|щеп|ветк|измельч/ui', $q) && !self::isPmuQuery($q)) {
                if (mb_strpos($name, 'измельчит') !== false || mb_strpos($name, 'утилизатор') !== false || mb_strpos($cat, 'измельч') !== false) {
                    $score += 14;
                }
                if (mb_strpos($name, 'трицикл') !== false || mb_strpos($name, 'мотоцикл') !== false) {
                    $score -= 20;
                }
            }

            if (mb_strpos($q, 'мотоцикл') !== false && mb_strpos($q, 'коляск') === false && mb_strpos($name, 'коляск') !== false) {
                $score -= 15;
            }

            if (preg_match('/груз|грузопод|возить|вмещ|подъем|подъём/ui', $q)) {
                if (mb_strpos($name, 'измельчит') !== false || mb_strpos($cat, 'измельч') !== false) {
                    $score -= 25;
                }
                if (mb_strpos($name, 'трицикл') !== false || mb_strpos($cat, 'трицикл') !== false) {
                    $score += 12;
                }
                if (mb_strpos($desc, 'грузопод') !== false || !empty($product->specs['Грузоподъемность'])) {
                    $score += 15;
                }
            }

            if (preg_match('/\bкг\b/ui', $q) && !preg_match('/измельч|щепорез|станок/ui', $q)) {
                if (mb_strpos($name, 'измельчит') !== false || !empty($product->specs['Вес станка, кг'])) {
                    $score -= 20;
                }
                if (mb_strpos($name, 'трицикл') !== false || !empty($product->specs['Грузоподъемность'])) {
                    $score += 12;
                }
            }

            if (mb_strpos($q, 'трицикл') !== false && (mb_strpos($name, 'трицикл') !== false || mb_strpos($cat, 'трицикл') !== false)) {
                $score += 10;
            }

            $score = self::applyIntentScoring($q, $product, $score, $name, $cat, $desc);

            if ($hasPriceFilter) {
                $score += 1;
            }

            if ($score > 0) {
                $scored[] = ['product' => $product, 'score' => $score];
            }
        }

        usort($scored, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['product']->price <=> $b['product']->price;
            }
            return $b['score'] <=> $a['score'];
        });

        if (self::isMotorcycleQuery($q)) {
            $motoRanked = array_values(array_filter($scored, static fn($s) => self::isRealMotorcycleProduct($s['product'])));
            if ($motoRanked) {
                return array_map(static fn($s) => $s['product'], array_slice($motoRanked, 0, $limit));
            }
            $motoPool = array_values(array_filter(Catalog::getAllProducts(), static fn(Product $p) => self::isRealMotorcycleProduct($p)));
            usort($motoPool, static fn(Product $a, Product $b) => $a->price <=> $b->price);
            if ($motoPool && $hasPriceFilter && $priceFilter['max'] !== null) {
                $inBudget = array_values(array_filter($motoPool, static fn(Product $p) => $p->price <= $priceFilter['max']));
                if ($inBudget) {
                    return array_slice($inBudget, 0, $limit);
                }
            }
            if ($motoPool) {
                return array_slice($motoPool, 0, $limit);
            }
            return [];
        }

        if ($scored) {
            return array_map(static fn($s) => $s['product'], array_slice($scored, 0, $limit));
        }

        if ($hasPriceFilter) {
            usort($pool, static fn(Product $a, Product $b) => $a->price <=> $b->price);
            return array_slice($pool, 0, $limit);
        }

        return [];
    }

    /** @return string[] */
    private static function tokenize(string $text): array
    {
        $prepared = preg_replace('/\b(у|щ|тмг)\s*[-–]?\s*(\d{2,4})\b/ui', '$1-$2', ModelSeries::normalizeModelQuery($text)) ?? '';
        $parts = preg_split('/[^\p{L}\p{N}-]+/u', $prepared, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, static fn($w) => mb_strlen($w) > 1));
    }

    /** @return array{min: ?float, max: ?float} */
    private static function parsePriceFilter(string $query): array
    {
        $lower = preg_replace('/\s+/u', ' ', mb_strtolower($query)) ?? '';
        $min = null;
        $max = null;

        if (preg_match('/(?:до|не\s+более|максимум|дешевле|до\s*)\s*(\d[\d\s]*)\s*(?:руб|₽|р\.?)?/ui', $lower, $m)) {
            $max = (float)preg_replace('/\s+/u', '', $m[1]);
        }
        if (preg_match('/(?:от|не\s+менее|минимум|дороже)\s*(\d[\d\s]*)\s*(?:руб|₽|р\.?)?/ui', $lower, $m)) {
            $min = (float)preg_replace('/\s+/u', '', $m[1]);
        }
        if ($max === null && preg_match('/(?:^|\s)(?:за|около|примерно)\s+(\d[\d\s]{4,})(?:\s*(?:руб|₽|р\.?))?(?:\s|$)/ui', $lower, $m)) {
            $max = (float)preg_replace('/\s+/u', '', $m[1]);
        }
        if ($max === null && preg_match('/(?:бюджет\s*)?(\d[\d\s]{4,})\s*(?:руб|₽|р\.?)\b/ui', $lower, $m)) {
            $max = (float)preg_replace('/\s+/u', '', $m[1]);
        }

        return ['min' => $min, 'max' => $max];
    }

    public static function isRealMotorcycleProduct(Product $product): bool
    {
        $n = mb_strtolower($product->name);
        if (preg_match('/снегокат|снегоход|мотобукс|мотоснегокат|коляск/ui', $n)) {
            return false;
        }
        return (bool)preg_match('/мотоцикл|мини\s*байк|питбайк/ui', $n);
    }

    public static function isMotorcycleQuery(string $text): bool
    {
        $q = ModelSeries::normalizeModelQuery($text);
        if (CatalogProfile::isOffCatalogVehicleQuery($q)) {
            return false;
        }
        if (CatalogProfile::isGardenOnly()) {
            return false;
        }
        $rx = Settings::regex('motorcycle_query_regex', 'мотоцикл|мотик|мини\s*байк|питбайк|пит\s*байк');
        if (preg_match('/' . $rx . '/ui', $q)) {
            return true;
        }
        if (preg_match('/у\s+вас\s+есть.*мото|есть\s+ли.*мото/ui', $q)) {
            return true;
        }
        return (bool)preg_match('/нужен\s+мот\b|нужна\s+мот\b|хочу\s+мот\b|ищу\s+мот\b|надо\s+мот\b/ui', $q);
    }

    private static function applyIntentScoring(
        string $q,
        Product $product,
        int $score,
        string $name,
        string $cat,
        string $desc
    ): int {
        $hay = "{$name} {$cat} {$desc}";
        $wantsTrackRacing = preg_match('/гоноч|кольцев|трасс|треков|пит.?байк|supermoto|супермото/ui', $q)
            && preg_match('/мото|байк/ui', $q);
        $wantsSnow = preg_match('/снег|зим|снегоход|снегокат/ui', $q);

        if ($wantsTrackRacing) {
            if (mb_strpos($name, 'внедорож') !== false) {
                $score -= 35;
            }
            if (mb_strpos($name, 'мотовездеход') !== false || mb_strpos($name, 'тирекс') !== false) {
                $score -= 35;
            }
            if (!empty($product->specs['Грузоподъемность'])) {
                $score -= 25;
            }
            if (preg_match('/мини\s*байк|питбайк/ui', $name)) {
                $score += 28;
            }
            if (mb_strpos($name, 'фурия') !== false && mb_strpos($name, 'трио') === false) {
                $score += 8;
            }
            if (preg_match('/снего|снегокат|мотобукс/ui', $hay) && !$wantsSnow) {
                $score -= 30;
            }
            if (mb_strpos($name, 'коляск') !== false) {
                $score -= 20;
            }
        }

        if (self::isMotorcycleQuery($q)
            && !preg_match('/груз|коляск|трицикл|мотовездеход/ui', $q)
            && mb_strpos($name, 'коляск') !== false
        ) {
            $score -= 15;
        }

        if (preg_match('/внедорож|бездор|лесн|охот/ui', $q) && preg_match('/мото/ui', $q) && mb_strpos($name, 'внедорож') !== false) {
            $score += 15;
        }

        if (self::isMotorcycleQuery($q) && !preg_match('/снего|снегокат|мотобукс/ui', $q)) {
            if (mb_strpos($name, 'мотоцикл') !== false || preg_match('/мини\s*байк|питбайк/ui', $name)) {
                $score += 28;
            }
            if (preg_match('/мотоснегокат|снегокат|снегоход|мотобукс/ui', $name)) {
                $score -= 50;
            }
            if (mb_strpos($name, 'коляск') !== false) {
                $score -= 18;
            }
        }

        if (self::isPmuQuery($q)) {
            if (self::isPmuProduct($product)) {
                $score += 50;
            }
            return $score;
        }

        if (preg_match('/мульч/ui', $q)) {
            $purpose = mb_strtolower($product->specs['Назначение'] ?? '');
            if (preg_match('/садовый измельчит/ui', $name) || (mb_strpos($name, 'измельчит') !== false && mb_strpos($cat, 'садов') !== false)) {
                $score += 24;
            }
            if (mb_strpos($purpose, 'мульч') !== false || mb_strpos($desc, 'мульч') !== false) {
                $score += 8;
            }
            if (preg_match('/промышлен|утилизатор/ui', $name) && !preg_match('/усм|садов/ui', $name)) {
                $score -= 32;
            }
            if (preg_match('/щепорез|пеллет|вом\b|топливн/ui', $hay)) {
                $score -= 28;
            }
            if (preg_match('/навесн|мотоблок|приставк|минитрактор/ui', $name)) {
                $score -= 24;
            }
            if (mb_strpos($purpose, 'копчен') !== false) {
                $score -= 18;
            }
        }

        return $score;
    }

    private static function productSearchText(Product $product): string
    {
        $specs = [];
        foreach ($product->specs as $k => $v) {
            $specs[] = "{$k} {$v}";
        }
        return mb_strtolower(implode(' ', [
            $product->name,
            $product->category,
            $product->description,
            implode(' ', $specs),
            implode(' ', $product->tags),
            (string)$product->price,
        ]));
    }
}
