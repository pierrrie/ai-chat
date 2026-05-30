<?php

namespace Draxter\Aichat;

use Bitrix\Main\Loader;

class BitrixCrmLead
{
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
        if (!Settings::isCrmLocalEnabled()) {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'disabled'];
        }
        if (!Loader::includeModule('crm')) {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'no_crm_module'];
        }

        $name = $clientName ?: 'Клиент AI-чата';
        $productNames = array_map(static fn(Product $p) => $p->name, $products);
        $prefix = trim(Settings::get('CRM_LEAD_TITLE_PREFIX', 'AI-чат'));
        $brand = Config::brand();
        $title = ($prefix !== '' ? $prefix : 'AI-чат') . ' ' . $brand . ': ' . ($productNames[0] ?? 'консультация');

        $comment = Bitrix24Lead::buildCommentPublic($sessionId, $messages, $productNames, $phone, $tracking);
        $context = CrmFieldMapper::buildContext($sessionId, $phone, $name, $title, $comment, $messages, $products, $tracking);
        $map = CrmFieldMapper::getLocalMap();
        $mapped = CrmFieldMapper::applyMap($map, $context);

        $fields = array_merge([
            'TITLE' => $title,
            'NAME' => $name,
            'COMMENTS' => $comment,
            'OPENED' => 'Y',
            'SOURCE_ID' => Settings::bitrix24SourceId(),
            'SOURCE_DESCRIPTION' => trim((string)($tracking['page_url'] ?? '')) ?: Config::siteUrl(),
            'PHONE' => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
            'ASSIGNED_BY_ID' => (int)Settings::get('BITRIX24_ASSIGNED_ID', '1'),
        ], $mapped);

        if (isset($mapped['TITLE'])) {
            $fields['TITLE'] = $mapped['TITLE'];
        }
        if (isset($mapped['COMMENTS'])) {
            $fields['COMMENTS'] = $mapped['COMMENTS'];
        }

        $lead = new \CCrmLead(false);
        $leadId = (int)$lead->Add($fields);
        if ($leadId <= 0) {
            $err = $lead->LAST_ERROR ?: 'Add failed';
            ApiErrorLog::write('bitrix_crm', 'lead.add', 0, $err, $sessionId);

            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => $err];
        }

        return ['ok' => true, 'leadId' => $leadId, 'contactId' => null, 'error' => null];
    }
}
