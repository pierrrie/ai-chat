<?php

namespace Draxter\Aichat;

use Bitrix\Main\Loader;

class BitrixCatalog
{
    /**
     * @return Product[]
     */
    public static function load(int $iblockId): array
    {
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            return [];
        }

        $hasCatalog = Loader::includeModule('catalog');
        $products = [];

        $select = [
            'ID',
            'NAME',
            'DETAIL_PAGE_URL',
            'DETAIL_TEXT',
            'PREVIEW_TEXT',
            'IBLOCK_SECTION_ID',
        ];

        $max = max(0, Settings::getInt('CATALOG_MAX_PRODUCTS', 3000));
        $nav = $max > 0 ? ['nTopCount' => $max] : false;

        $rs = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'],
            false,
            $nav,
            $select
        );

        while ($row = $rs->GetNext()) {
            $id = (string)$row['ID'];
            $price = 0.0;
            $currency = 'RUB';
            $inStock = true;

            if ($hasCatalog) {
                $priceRow = \CPrice::GetBasePrice($row['ID']);
                if (is_array($priceRow)) {
                    $price = (float)($priceRow['PRICE'] ?? 0);
                    $currency = (string)($priceRow['CURRENCY'] ?? 'RUB');
                }
                $qty = \CCatalogProduct::GetByID($row['ID']);
                if (is_array($qty)) {
                    $inStock = ((float)($qty['QUANTITY'] ?? 0)) > 0 || ($qty['AVAILABLE'] ?? 'Y') === 'Y';
                }
            }

            $specs = [];
            $propRs = \CIBlockElement::GetProperty($iblockId, $row['ID'], ['sort' => 'asc'], ['ACTIVE' => 'Y']);
            while ($prop = $propRs->Fetch()) {
                $name = trim((string)($prop['NAME'] ?? ''));
                $val = trim(is_array($prop['VALUE']) ? implode(', ', $prop['VALUE']) : (string)($prop['VALUE'] ?? ''));
                if ($name !== '' && $val !== '') {
                    $specs[$name] = $val;
                }
            }

            $description = trim(strip_tags((string)($row['DETAIL_TEXT'] ?: $row['PREVIEW_TEXT'])));
            $description = mb_substr(preg_replace('/\s+/u', ' ', $description) ?? '', 0, 400);

            $sectionName = 'Без категории';
            if (!empty($row['IBLOCK_SECTION_ID'])) {
                $section = \CIBlockSection::GetByID($row['IBLOCK_SECTION_ID'])->Fetch();
                if ($section) {
                    $sectionName = (string)$section['NAME'];
                }
            }

            $url = (string)($row['DETAIL_PAGE_URL'] ?? '');
            if ($url !== '' && $url[0] === '/') {
                $url = Config::siteUrl() . $url;
            }

            $products[] = Product::fromArray([
                'id' => $id,
                'name' => (string)$row['NAME'],
                'category' => $sectionName,
                'price' => $price,
                'currency' => $currency,
                'inStock' => $inStock,
                'url' => $url,
                'description' => $description,
                'specs' => $specs,
                'tags' => self::buildTags((string)$row['NAME'], $sectionName, $specs),
            ]);
        }

        return $products;
    }

    /** @param array<string, string> $specs */
    private static function buildTags(string $name, string $category, array $specs): array
    {
        $text = $name . ' ' . $category;
        foreach ($specs as $k => $v) {
            $text .= ' ' . $k . ' ' . $v;
        }
        $tags = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) > 2) {
                $tags[$w] = true;
            }
        }
        return array_slice(array_keys($tags), 0, 24);
    }
}
