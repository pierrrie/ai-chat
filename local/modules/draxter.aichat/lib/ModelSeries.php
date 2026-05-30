<?php

namespace Draxter\Aichat;

class ModelSeries
{
    public static function normalizeModelQuery(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[yY](?=[-\s]?\d)/u', 'у', $text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /** @return string[] */
    public static function extractModelSeries(string $query): array
    {
        $q = self::normalizeModelQuery($query);
        $found = [];

        $patterns = [
            '/\b(у|щ|тмг)\s*[-–]?\s*(\d{2,4})\b/ui',
            '/\b(у|щ)\s*(\d{2,4})\b/ui',
        ];

        foreach ($patterns as $re) {
            if (preg_match_all($re, $q, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $prefix = mb_strtolower($m[1]);
                    $num = $m[2];
                    $found["{$prefix}-{$num}"] = true;
                    $found["{$prefix} {$num}"] = true;
                }
            }
        }

        return array_keys($found);
    }

    public static function productMatchesSeries(string $productName, string $seriesCode): bool
    {
        $name = preg_replace('/[-\s]/u', '', self::normalizeModelQuery($productName)) ?? '';
        $code = preg_replace('/[-\s]/u', '', self::normalizeModelQuery($seriesCode)) ?? '';
        return $name !== '' && $code !== '' && mb_strpos($name, $code) !== false;
    }

    public static function isComparisonQuery(string $query): bool
    {
        return (bool)preg_match('/лучше|или|сравн|что\s+выбрать|разниц|против|vs/ui', $query);
    }
}
