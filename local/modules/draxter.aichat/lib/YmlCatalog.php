<?php

namespace Draxter\Aichat;

class YmlCatalog
{
    /**
     * @return Product[]
     */
    public static function parseXml(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            throw new \RuntimeException('Некорректный YML XML');
        }

        $shop = $doc->shop ?? null;
        if ($shop === null) {
            throw new \RuntimeException('Некорректный YML: не найден yml_catalog/shop');
        }

        $categoryMap = self::buildCategoryMap($shop->categories ?? null);
        $products = [];

        foreach ($shop->offers->offer ?? [] as $offer) {
            $attrs = $offer->attributes();
            $id = (string)($attrs['id'] ?? '');
            $name = trim((string)($offer->name ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $specs = [];
            foreach ($offer->param ?? [] as $param) {
                $pAttrs = $param->attributes();
                $key = trim((string)($pAttrs['name'] ?? ''));
                $val = trim((string)$param);
                if ($key === '' || $val === '' || mb_strpos($key, 'Ед-ца') === 0 || $key === 'Видео' || $key === 'Сопутствующие товары') {
                    continue;
                }
                $specs[$key] = $val;
            }

            $categoryId = (string)($offer->categoryId ?? '');
            $category = self::categoryPath($categoryId, $categoryMap);
            $fullDescription = self::stripHtml((string)($offer->description ?? ''));
            $description = mb_substr($fullDescription, 0, 400);
            $specs = array_merge($specs, self::extractSpecsFromDescription($fullDescription));

            $price = (float)preg_replace('/\s+/u', '', (string)($offer->price ?? '0'));
            $available = (string)($attrs['available'] ?? 'true');

            $products[] = Product::fromArray([
                'id' => $id,
                'name' => $name,
                'category' => $category,
                'price' => $price,
                'currency' => trim((string)($offer->currencyId ?? 'RUB')) ?: 'RUB',
                'url' => trim((string)($offer->url ?? '')),
                'inStock' => $available !== 'false',
                'description' => $description,
                'specs' => $specs,
                'tags' => self::buildTags($name, $category, trim((string)($offer->vendor ?? '')), $specs),
            ]);
        }

        return $products;
    }

    /** @return array{count: int, shopName?: string, shopUrl?: string} */
    public static function metaFromXml(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return ['count' => 0];
        }
        $shop = $doc->shop ?? null;
        $count = isset($shop->offers->offer) ? count($shop->offers->offer) : 0;
        return [
            'count' => $count,
            'shopName' => trim((string)($shop->name ?? '')),
            'shopUrl' => trim((string)($shop->url ?? '')),
        ];
    }

    /** @param \SimpleXMLElement|null $categories */
    private static function buildCategoryMap($categories): array
    {
        $map = [];
        if ($categories === null) {
            return $map;
        }
        foreach ($categories->category ?? [] as $cat) {
            $attrs = $cat->attributes();
            $id = (string)($attrs['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = [
                'id' => $id,
                'name' => trim((string)$cat),
                'parentId' => (string)($attrs['parentId'] ?? ''),
            ];
        }
        return $map;
    }

    private static function categoryPath(string $categoryId, array $map): string
    {
        $parts = [];
        $current = $categoryId;
        $seen = [];
        while ($current !== '' && isset($map[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $node = $map[$current];
            array_unshift($parts, $node['name']);
            $current = $node['parentId'] ?? '';
        }
        return $parts ? implode(' / ', $parts) : 'Без категории';
    }

    private static function stripHtml(string $text): string
    {
        $text = strip_tags($text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** @return array<string, string> */
    private static function extractSpecsFromDescription(string $description): array
    {
        $patterns = [
            '/грузоподъ[её]мность[:\s]*(?:до\s*)?(\d+)\s*кг/ui',
            '/грузоподъемник на (\d+)\s*кг/ui',
            '/грузы до (\d+)\s*кг/ui',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $description, $m)) {
                return ['Грузоподъемность' => $m[1] . ' кг'];
            }
        }
        return [];
    }

    /** @param array<string, string> $specs */
    private static function buildTags(string $name, string $category, string $vendor, array $specs): array
    {
        $tags = [];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower("{$name} {$category} {$vendor}"), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if (mb_strlen($w) > 2) {
                $tags[$w] = true;
            }
        }
        foreach (['Назначение', 'Тип двигателя', 'Мощность', 'Модификация'] as $key) {
            if (empty($specs[$key])) {
                continue;
            }
            $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($specs[$key]), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $w) {
                if (mb_strlen($w) > 2) {
                    $tags[$w] = true;
                }
            }
        }
        return array_slice(array_keys($tags), 0, 24);
    }
}
