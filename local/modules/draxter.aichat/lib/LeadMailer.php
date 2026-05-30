<?php

namespace Draxter\Aichat;

use Bitrix\Main\Mail\Mail;

class LeadMailer
{
    /**
     * @param Product[] $products
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, string|null> $tracking
     * @return array{ok: bool, leadId: ?int, contactId: ?int, error: ?string}
     */
    public static function sendLead(
        string $sessionId,
        array $messages,
        array $products,
        string $phone,
        ?string $clientName,
        array $tracking = []
    ): array {
        if (!Settings::isCrmEmailEnabled()) {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'disabled'];
        }

        $to = Settings::crmEmailRecipients();
        if ($to === []) {
            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'no_email'];
        }

        $productNames = array_map(static fn(Product $p) => $p->name, $products);
        $comment = Bitrix24Lead::buildCommentPublic($sessionId, $messages, $productNames, $phone, $tracking);
        $name = $clientName ?: 'Клиент AI-чата';
        $prefix = trim(Settings::get('CRM_LEAD_TITLE_PREFIX', 'AI-чат'));
        $subject = ($prefix !== '' ? $prefix : 'AI-чат') . ' — ' . $phone;

        $body = '<h2>Новый лид из AI-чата</h2>'
            . '<p><strong>Имя:</strong> ' . htmlspecialcharsbx($name) . '</p>'
            . '<p><strong>Телефон:</strong> ' . htmlspecialcharsbx($phone) . '</p>'
            . '<p><strong>Сессия:</strong> ' . htmlspecialcharsbx($sessionId) . '</p>'
            . '<pre style="white-space:pre-wrap">' . htmlspecialcharsbx($comment) . '</pre>';

        $sent = Mail::send([
            'TO' => implode(',', $to),
            'SUBJECT' => $subject,
            'BODY' => $body,
            'CONTENT_TYPE' => 'text/html',
            'CHARSET' => 'UTF-8',
        ]);

        if (!$sent) {
            ApiErrorLog::write('email', 'lead.mail', 0, 'Mail::send failed', $sessionId);

            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'send_failed'];
        }

        return ['ok' => true, 'leadId' => null, 'contactId' => null, 'error' => null];
    }
}
