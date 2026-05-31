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

        $from = trim((string)\Bitrix\Main\Config\Option::get('main', 'email_from', ''));
        $header = [];
        if ($from !== '') {
            $header['From'] = $from;
        }

        $mail = [
            'TO' => implode(',', $to),
            'SUBJECT' => $subject,
            'BODY' => $body,
            'CONTENT_TYPE' => 'html',
            'CHARSET' => 'UTF-8',
            'HEADER' => $header,
        ];
        if ($from !== '') {
            $mail['FROM'] = $from;
        }

        try {
            $sent = Mail::send($mail);
        } catch (\Throwable $e) {
            ApiErrorLog::write('email', 'lead.mail', 0, $e->getMessage(), $sessionId);

            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'send_failed'];
        }

        if (!$sent) {
            ApiErrorLog::write('email', 'lead.mail', 0, 'Mail::send failed', $sessionId);

            return ['ok' => false, 'leadId' => null, 'contactId' => null, 'error' => 'send_failed'];
        }

        return ['ok' => true, 'leadId' => null, 'contactId' => null, 'error' => null];
    }
}
