<?php

namespace Draxter\Aichat;

use Bitrix\Main\Web\Json;

class Bitrix24Lead
{
    /** @var array<int, array{STATUS_ID: string, NAME: string}>|null */
    private static ?array $leadSourcesCache = null;

    /**
     * @param Product[] $products
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, string|null> $tracking
     * @return array{ok: bool, leadId: ?int, contactId: ?int, error: ?string}
     */
    public static function createFromChat(
        string $sessionId,
        array $messages,
        array $products,
        string $phone,
        ?string $clientName,
        array $tracking = []
    ): array {
        if (!Config::isBitrix24LeadEnabled()) {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'disabled'];
        }

        $webhook = rtrim(Config::get('BITRIX24_WEBHOOK_URL', ''), '/');
        if ($webhook === '') {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'no_webhook'];
        }
        if (!str_ends_with($webhook, '/')) {
            $webhook .= '/';
        }

        $name = $clientName ?: 'Клиент AI-чата';
        $productNames = array_map(static fn(Product $p) => $p->name, $products);
        $prefix = trim(Settings::get('CRM_LEAD_TITLE_PREFIX', ''));
        if ($prefix === '') {
            $prefix = trim((string)SiteConfig::get('crm.lead_title_prefix', 'AI-чат'));
        }
        $brand = Config::brand();
        $title = ($prefix !== '' ? $prefix : 'AI-чат') . ' ' . $brand . ': ' . ($productNames[0] ?? 'консультация по каталогу');

        $comment = self::buildComment($sessionId, $messages, $productNames, $phone, $tracking);

        $fields = array_merge($tracking, [
            'session_id' => $sessionId,
            'phone' => $phone,
            'name' => $name,
            'comment' => $comment,
            'tovar' => implode('; ', array_slice($productNames, 0, 5)),
            'UF_CRM_1760540018298' => implode('; ', array_slice($productNames, 0, 5)),
        ]);

        try {
            $out = self::createLead($webhook, $title, $fields, $messages, $products);
            if ($out['ok']) {
                self::log("OK lead={$out['leadId']} contact={$out['contactId']} phone={$phone}");
            } else {
                self::log('ERR ' . ($out['error'] ?? 'unknown'));
            }

            return $out;
        } catch (\Throwable $e) {
            self::log('ERR ' . $e->getMessage());
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    /**
     * @return array<int, string>
     */
    public static function fetchLeadFieldCodes(): array
    {
        $webhook = rtrim(Config::get('BITRIX24_WEBHOOK_URL', ''), '/');
        if ($webhook === '') {
            return [];
        }
        if (!str_ends_with($webhook, '/')) {
            $webhook .= '/';
        }
        try {
            $result = self::request($webhook, 'crm.lead.fields', []);
            $fields = $result['result'] ?? [];
            if (!is_array($fields)) {
                return [];
            }

            return array_keys($fields);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function ping(): array
    {
        if (!Config::isBitrix24LeadEnabled()) {
            return ['ok' => false, 'error' => 'disabled'];
        }
        $webhook = rtrim(Config::get('BITRIX24_WEBHOOK_URL', ''), '/');
        if ($webhook === '') {
            return ['ok' => false, 'error' => 'no_webhook'];
        }
        if (!str_ends_with($webhook, '/')) {
            $webhook .= '/';
        }
        try {
            self::request($webhook, 'crm.lead.fields', []);
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            self::log('PING ERR ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param string[] $productNames
     * @param array<string, string|null> $tracking
     */
    public static function buildCommentPublic(
        string $sessionId,
        array $messages,
        array $productNames,
        string $phone,
        array $tracking = []
    ): string {
        return self::buildComment($sessionId, $messages, $productNames, $phone, $tracking);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param string[] $productNames
     * @param array<string, string|null> $tracking
     */
    private static function buildComment(
        string $sessionId,
        array $messages,
        array $productNames,
        string $phone,
        array $tracking = []
    ): string {
        $sourceId = Settings::bitrix24SourceId();
        $sourceLabel = Settings::bitrix24SourceLabel();
        $lines = [
            'Источник: ' . $sourceLabel . ' (' . $sourceId . ', AI-чат на сайте)',
            'Сессия: ' . $sessionId,
            'Телефон: ' . $phone,
        ];
        $pageUrl = trim((string)($tracking['page_url'] ?? ''));
        if ($pageUrl !== '') {
            $lines[] = 'Страница: ' . $pageUrl;
        }
        $pageTitle = trim((string)($tracking['page_title'] ?? ''));
        if ($pageTitle !== '') {
            $lines[] = 'Заголовок страницы: ' . $pageTitle;
        }
        if ($productNames) {
            $lines[] = 'Интерес к товарам: ' . implode(', ', $productNames);
        }
        $lines[] = '';
        $lines[] = 'Диалог:';
        $slice = array_slice($messages, -12);
        foreach ($slice as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'Консультант' : 'Клиент';
            $content = trim($m['content'] ?? '');
            if ($content !== '') {
                $lines[] = $role . ': ' . $content;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, string|null> $fields
     * @return array{ok: bool, leadId: ?int, contactId: ?int, error: ?string}
     */
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param Product[] $products
     */
    private static function createLead(string $webhookUrl, string $title, array $fields, array $messages = [], array $products = []): array
    {
        $tracking = [];
        foreach ([
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            '_ym_uid', 'page_url', 'page_title', 'http_referer',
        ] as $key) {
            if (!empty($fields[$key])) {
                $tracking[$key] = $fields[$key];
            }
        }

        $resolved = self::resolveLeadSource($webhookUrl);
        $sourceId = $resolved['id'];
        $sourceName = $resolved['name'];
        if ($sourceName !== '' && mb_strtolower($sourceName) !== mb_strtolower(Settings::bitrix24SourceLabel())) {
            self::log(
                "SOURCE_ID={$sourceId} ({$sourceName}) — в CRM переименуйте источник в «"
                . Settings::bitrix24SourceLabel()
                . '» или создайте источник с таким названием'
            );
        }

        $contactData = [
            'NAME' => $fields['name'] ?? 'Клиент',
            'ASSIGNED_BY_ID' => (int)Config::get('BITRIX24_ASSIGNED_ID', '119'),
            'SOURCE_ID' => $sourceId,
            'TYPE_ID' => 'CLIENT',
            'PHONE' => [['VALUE' => $fields['phone'], 'VALUE_TYPE' => 'WORK']],
        ];
        if (!empty($fields['email'])) {
            $contactData['EMAIL'] = [['VALUE' => $fields['email'], 'VALUE_TYPE' => 'WORK']];
        }

        $contactResult = self::request($webhookUrl, 'crm.contact.add', ['fields' => $contactData]);
        if (!isset($contactResult['result'])) {
            throw new \RuntimeException('Не удалось создать контакт: ' . json_encode($contactResult, JSON_UNESCAPED_UNICODE));
        }
        $contactId = (int)$contactResult['result'];

        $context = CrmFieldMapper::buildContext(
            (string)($fields['session_id'] ?? ''),
            (string)$fields['phone'],
            is_string($fields['name'] ?? null) ? $fields['name'] : null,
            $title,
            (string)($fields['comment'] ?? ''),
            $messages,
            $products,
            $tracking
        );
        $context['source_id'] = $sourceId;

        $map = CrmFieldMapper::getB24Map();
        if ($map === []) {
            $map = CrmFieldMapper::defaultB24Map();
        }

        $leadData = [
            'TITLE' => $title,
            'STATUS_ID' => 'NEW',
            'ASSIGNED_BY_ID' => (int)Config::get('BITRIX24_ASSIGNED_ID', '119'),
            'SOURCE_ID' => $sourceId,
            'COMMENTS' => $fields['comment'] ?? '',
            'PHONE' => [['VALUE' => $fields['phone'], 'VALUE_TYPE' => 'WORK']],
        ];

        $mapped = CrmFieldMapper::applyMap($map, $context);
        foreach ($mapped as $key => $val) {
            if ($key === 'PHONE' || $key === 'TITLE' || $key === 'COMMENTS' || $key === 'SOURCE_ID') {
                continue;
            }
            $leadData[$key] = $val;
        }
        if (isset($mapped['SOURCE_ID'])) {
            $leadData['SOURCE_ID'] = $mapped['SOURCE_ID'];
        }
        if (!empty($fields['UF_CRM_1760540018298'])) {
            $leadData['UF_CRM_1760540018298'] = $fields['UF_CRM_1760540018298'];
        }

        $leadResult = self::requestLeadAdd($webhookUrl, $leadData);
        $leadId = (int)$leadResult['result'];

        self::request($webhookUrl, 'crm.lead.contact.add', [
            'id' => $leadId,
            'fields' => ['CONTACT_ID' => $contactId, 'IS_PRIMARY' => 'Y'],
        ]);

        return ['ok' => true, 'leadId' => $leadId, 'contactId' => $contactId, 'error' => null];
    }

    /**
     * Поле «Источник» в карточке лида = название из справочника CRM по SOURCE_ID (не UF и не домен).
     *
     * @return array{id: string, name: string}
     */
    private static function resolveLeadSource(string $webhookUrl): array
    {
        $configuredId = Settings::bitrix24SourceId();
        $label = Settings::bitrix24SourceLabel();
        $sources = self::loadLeadSources($webhookUrl);

        $matchRow = static function (callable $predicate) use ($sources): ?array {
            foreach ($sources as $row) {
                if ($predicate($row)) {
                    return $row;
                }
            }

            return null;
        };

        $byLabelName = $matchRow(static function (array $row) use ($label): bool {
            return mb_strtolower(trim($row['NAME'])) === mb_strtolower($label);
        });
        if ($byLabelName !== null) {
            return ['id' => $byLabelName['STATUS_ID'], 'name' => $byLabelName['NAME']];
        }

        $byStatusId = $matchRow(static function (array $row) use ($label): bool {
            return strcasecmp($row['STATUS_ID'], $label) === 0;
        });
        if ($byStatusId !== null) {
            return ['id' => $byStatusId['STATUS_ID'], 'name' => $byStatusId['NAME']];
        }

        foreach (['ai-чат', 'ai чат', 'ai chat', 'ai-чат на сайте'] as $alt) {
            $found = $matchRow(static function (array $row) use ($alt): bool {
                return mb_strtolower(trim($row['NAME'])) === mb_strtolower($alt);
            });
            if ($found !== null) {
                return ['id' => $found['STATUS_ID'], 'name' => $found['NAME']];
            }
        }

        $configured = $matchRow(static function (array $row) use ($configuredId): bool {
            return $row['STATUS_ID'] === $configuredId;
        });
        if ($configured !== null
            && mb_strtolower(trim($configured['NAME'])) === mb_strtolower($label)
        ) {
            return ['id' => $configured['STATUS_ID'], 'name' => $configured['NAME']];
        }

        if ($configured !== null) {
            return ['id' => $configured['STATUS_ID'], 'name' => $configured['NAME']];
        }

        return ['id' => $configuredId, 'name' => ''];
    }

    /**
     * @return array<int, array{STATUS_ID: string, NAME: string}>
     */
    private static function loadLeadSources(string $webhookUrl): array
    {
        if (self::$leadSourcesCache !== null) {
            return self::$leadSourcesCache;
        }

        self::$leadSourcesCache = [];
        try {
            $response = self::request($webhookUrl, 'crm.status.list', [
                'filter' => ['ENTITY_ID' => 'SOURCE'],
                'order' => ['SORT' => 'ASC'],
            ]);
            $rows = $response['result'] ?? [];
            if (!is_array($rows)) {
                return self::$leadSourcesCache;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['STATUS_ID'] ?? ''));
                if ($id === '') {
                    continue;
                }
                self::$leadSourcesCache[] = [
                    'STATUS_ID' => $id,
                    'NAME' => trim((string)($row['NAME'] ?? '')),
                ];
            }
        } catch (\Throwable $e) {
            self::log('crm.status.list SOURCE: ' . $e->getMessage());
        }

        return self::$leadSourcesCache;
    }

    /**
     * @param array<string, mixed> $leadData
     * @return array<string, mixed>
     */
    private static function requestLeadAdd(string $webhookUrl, array $leadData): array
    {
        try {
            return self::request($webhookUrl, 'crm.lead.add', ['fields' => $leadData]);
        } catch (\Throwable $e) {
            self::log('crm.lead.add full fields failed: ' . $e->getMessage());
            $minimal = [
                'TITLE' => $leadData['TITLE'] ?? 'AI-чат',
                'STATUS_ID' => 'NEW',
                'ASSIGNED_BY_ID' => $leadData['ASSIGNED_BY_ID'] ?? 1,
                'SOURCE_ID' => Settings::bitrix24SourceId(),
                'COMMENTS' => $leadData['COMMENTS'] ?? '',
                'PHONE' => $leadData['PHONE'] ?? [],
            ];

            return self::request($webhookUrl, 'crm.lead.add', ['fields' => $minimal]);
        }
    }

    private static function request(string $webhookUrl, string $method, array $params = []): array
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep(400000);
            }
            try {
                return self::requestOnce($webhookUrl, $method, $params);
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt === 0 && self::isTransientCrmError($e->getMessage())) {
                    continue;
                }
                throw $e;
            }
        }
        throw $lastError ?? new \RuntimeException('Bitrix24: неизвестная ошибка');
    }

    private static function isTransientCrmError(string $message): bool
    {
        $m = mb_strtolower($message);
        foreach (
            [
                'ssl certificate',
                'self-signed certificate',
                'could not resolve host',
                'failed to connect',
                'connection timed out',
                'operation timed out',
                'recv failure',
            ] as $needle
        ) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestOnce(string $webhookUrl, string $method, array $params = []): array
    {
        $url = $webhookUrl . $method;
        $body = Json::encode($params);
        $ch = curl_init($url);
        CurlHelper::applySslOptions($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlHelper::close($ch);

        if ($result === false) {
            throw new \RuntimeException('Bitrix24 CURL: ' . $error);
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            ApiErrorLog::write('bitrix24', $method, $code, 'JSON error');
            throw new \RuntimeException('Bitrix24 JSON error HTTP ' . $code . ': ' . substr((string)$result, 0, 300));
        }
        if (isset($decoded['error'])) {
            $errMsg = (string)($decoded['error_description'] ?? $decoded['error']);
            ApiErrorLog::write('bitrix24', $method, $code, $errMsg);
            throw new \RuntimeException('Bitrix24: ' . $errMsg);
        }
        if (!isset($decoded['result'])) {
            throw new \RuntimeException('Bitrix24: нет result в ответе ' . substr((string)$result, 0, 300));
        }

        return $decoded;
    }

    private static function log(string $message): void
    {
        $dir = ChatLog::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/bitrix24.log',
            date('c') . ' ' . $message . "\n",
            FILE_APPEND
        );
    }
}
