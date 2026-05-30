<?php

namespace Draxter\Aichat;

class LeadDispatcher
{
    /**
     * @param Product[] $products
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, string|null> $tracking
     * @return array{ok: bool, leadId: ?int, contactId: ?int, error: ?string, channels: array<string, array>}
     */
    public static function dispatch(
        string $sessionId,
        array $messages,
        array $products,
        string $phone,
        ?string $clientName,
        array $tracking = []
    ): array {
        $channels = [];
        $leadId = null;
        $contactId = null;
        $anyOk = false;
        $errors = [];

        if (Config::isBitrix24LeadEnabled()) {
            $r = Bitrix24Lead::createFromChat($sessionId, $messages, $products, $phone, $clientName, $tracking);
            $channels['bitrix24'] = $r;
            if (!empty($r['ok'])) {
                $anyOk = true;
                $leadId = $r['leadId'] ?? $leadId;
                $contactId = $r['contactId'] ?? $contactId;
            } elseif (!empty($r['error'])) {
                $errors[] = 'bitrix24: ' . $r['error'];
            }
        }

        if (Settings::isCrmLocalEnabled()) {
            $r = BitrixCrmLead::createFromChat($sessionId, $messages, $products, $phone, $clientName, $tracking);
            $channels['bitrix_crm'] = $r;
            if (!empty($r['ok'])) {
                $anyOk = true;
                $leadId = $r['leadId'] ?? $leadId;
            } elseif (!empty($r['error'])) {
                $errors[] = 'bitrix_crm: ' . $r['error'];
            }
        }

        if (Settings::isCrmEmailEnabled()) {
            $r = LeadMailer::sendLead($sessionId, $messages, $products, $phone, $clientName, $tracking);
            $channels['email'] = $r;
            if (!empty($r['ok'])) {
                $anyOk = true;
            } elseif (!empty($r['error'])) {
                $errors[] = 'email: ' . $r['error'];
            }
        }

        self::logChannels($sessionId, $channels);

        return [
            'ok' => $anyOk,
            'leadId' => $leadId,
            'contactId' => $contactId,
            'error' => $anyOk ? null : ($errors[0] ?? 'no_channel'),
            'channels' => $channels,
        ];
    }

    /**
     * @param array<string, array{ok?: bool, error?: string}> $channels
     */
    private static function logChannels(string $sessionId, array $channels): void
    {
        foreach ($channels as $name => $result) {
            $status = !empty($result['ok']) ? 'ok' : 'error';
            $detail = $status === 'ok'
                ? ('lead=' . ($result['leadId'] ?? '—'))
                : ($result['error'] ?? 'unknown');
            ChatLog::appendChannelLog($sessionId, (string)$name, $status, $detail);
        }
    }
}
