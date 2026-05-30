<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class CrmFieldMapper
{
    /**
     * @return array<int, array{field: string, source: string}>
     */
    public static function getB24Map(): array
    {
        return self::parseMap(Settings::get('CRM_B24_FIELD_MAP', ''));
    }

    /**
     * @return array<int, array{field: string, source: string}>
     */
    public static function getLocalMap(): array
    {
        $map = self::parseMap(Settings::get('CRM_LOCAL_FIELD_MAP', ''));
        if ($map !== []) {
            return $map;
        }

        return self::defaultLocalMap();
    }

    /**
     * @param array<int, array{field: string, source: string}> $map
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function applyMap(array $map, array $context): array
    {
        $out = [];
        foreach ($map as $row) {
            $field = trim((string)($row['field'] ?? ''));
            $source = trim((string)($row['source'] ?? ''));
            if ($field === '' || $source === '') {
                continue;
            }
            $value = self::resolveSource($source, $context);
            if ($value === null || $value === '') {
                continue;
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * @param Product[] $products
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, string|null> $tracking
     * @return array<string, mixed>
     */
    public static function buildContext(
        string $sessionId,
        string $phone,
        ?string $clientName,
        string $title,
        string $comment,
        array $messages,
        array $products,
        array $tracking = []
    ): array {
        $productNames = array_map(static fn(Product $p) => $p->name, $products);
        $siteUrl = Config::siteUrl();
        $resolved = ['id' => Settings::bitrix24SourceId(), 'name' => ''];

        return array_merge($tracking, [
            'session_id' => $sessionId,
            'phone' => $phone,
            'name' => $clientName ?: 'Клиент AI-чата',
            'title' => $title,
            'comment' => $comment,
            'products' => implode('; ', array_slice($productNames, 0, 5)),
            'site_url' => $siteUrl,
            'site_host' => parse_url($siteUrl, PHP_URL_HOST) ?: '',
            'source_id' => $resolved['id'] ?: Settings::bitrix24SourceId(),
            'source_label' => Settings::bitrix24SourceLabel(),
            'page_url' => trim((string)($tracking['page_url'] ?? '')),
            'page_title' => trim((string)($tracking['page_title'] ?? '')),
            'http_referer' => trim((string)($tracking['http_referer'] ?? '')),
        ]);
    }

    /**
     * @return array<int, array{field: string, source: string}>
     */
    public static function defaultB24Map(): array
    {
        return [
            ['field' => 'SOURCE_ID', 'source' => 'source_id'],
            ['field' => 'SOURCE_DESCRIPTION', 'source' => 'page_url'],
            ['field' => 'UF_CRM_LEAD_SOURCE', 'source' => 'source_label'],
            ['field' => 'UF_CRM_SOURCE_URL', 'source' => 'page_url'],
            ['field' => 'UF_CRM_SITE', 'source' => 'site_host'],
            ['field' => 'UF_CRM_HTTP_REFERER', 'source' => 'http_referer'],
            ['field' => 'UTM_SOURCE', 'source' => 'utm_source'],
            ['field' => 'UTM_MEDIUM', 'source' => 'utm_medium'],
            ['field' => 'UTM_CAMPAIGN', 'source' => 'utm_campaign'],
            ['field' => 'UTM_TERM', 'source' => 'utm_term'],
            ['field' => 'UTM_CONTENT', 'source' => 'utm_content'],
            ['field' => 'UF_CRM_YMCLIENTID', 'source' => '_ym_uid'],
        ];
    }

    /**
     * @return array<int, array{field: string, source: string}>
     */
    public static function defaultLocalMap(): array
    {
        return [
            ['field' => 'TITLE', 'source' => 'title'],
            ['field' => 'COMMENTS', 'source' => 'comment'],
            ['field' => 'SOURCE_ID', 'source' => 'source_id'],
            ['field' => 'SOURCE_DESCRIPTION', 'source' => 'page_url'],
        ];
    }

    public static function encodeMap(array $map): string
    {
        return Json::encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, array{field: string, source: string}>
     */
    private static function parseMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        try {
            $data = Json::decode($raw);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $field = trim((string)($row['field'] ?? ''));
            $source = trim((string)($row['source'] ?? ''));
            if ($field !== '' && $source !== '') {
                $out[] = ['field' => $field, 'source' => $source];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     * @return string|array<int, array<string, string>>|null
     */
    private static function resolveSource(string $source, array $context)
    {
        if ($source === 'phone') {
            $phone = trim((string)($context['phone'] ?? ''));
            if ($phone === '') {
                return null;
            }

            return [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }

        if (!array_key_exists($source, $context)) {
            return null;
        }
        $val = $context[$source];
        if (is_array($val)) {
            return $val;
        }
        $str = trim((string)$val);

        return $str !== '' ? $str : null;
    }
}
