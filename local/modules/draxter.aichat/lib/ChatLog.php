<?php

namespace Draxter\Aichat;

class ChatLog
{
    public static function logDir(): string
    {
        $fromOption = trim(Config::get('CHAT_LOG_DIR', ''));
        if ($fromOption !== '') {
            return rtrim($fromOption, '/\\');
        }
        return $_SERVER['DOCUMENT_ROOT'] . '/upload/draxter_aichat_logs';
    }

    public static function ensureSession(string $sessionId): void
    {
        if (!Config::isChatLogEnabled()) {
            return;
        }
        $path = self::sessionJsonPath($sessionId);
        if (is_file($path)) {
            return;
        }
        $now = date('c');
        self::writeSession([
            'id' => $sessionId,
            'createdAt' => $now,
            'updatedAt' => $now,
            'turns' => [],
        ]);
    }

    /**
     * @param Product[] $products
     */
    public static function appendTurn(
        string $sessionId,
        string $userMessage,
        string $assistantMessage,
        bool $detailed,
        array $products,
        ?string $error = null
    ): void {
        if (!Config::isChatLogEnabled()) {
            return;
        }

        $session = self::readSession($sessionId) ?? [
            'id' => $sessionId,
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'turns' => [],
        ];

        $entry = [
            'id' => 't_' . time() . '_' . bin2hex(random_bytes(4)),
            'at' => date('c'),
            'userMessage' => $userMessage,
            'assistantMessage' => $assistantMessage,
            'error' => $error,
            'detailed' => $detailed,
            'provider' => Config::aiProvider(),
            'model' => Config::currentModel(),
            'products' => array_map(static fn(Product $p) => ['id' => $p->id, 'name' => $p->name], $products),
        ];

        $session['turns'][] = $entry;
        $session['updatedAt'] = $entry['at'];
        self::writeSession($session);
        self::appendTxt($sessionId, $entry);
    }

    private static function sessionJsonPath(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        return self::logDir() . '/' . $safe . '.json';
    }

    private static function historyTxtPath(): string
    {
        return self::logDir() . '/history.txt';
    }

    private static function ensureLogDir(): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private static function readSession(string $sessionId): ?array
    {
        $file = self::sessionJsonPath($sessionId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file) ?: '{}', true);
        return is_array($data) ? $data : null;
    }

    private static function writeSession(array $session): void
    {
        self::ensureLogDir();
        file_put_contents(
            self::sessionJsonPath($session['id']),
            json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private static function appendTxt(string $sessionId, array $turn): void
    {
        self::ensureLogDir();
        $products = $turn['products'] ?? [];
        $names = $products
            ? implode(', ', array_map(static fn($p) => $p['name'] ?? '', $products))
            : '—';

        $block = "\n════════════════════════════════════════\n"
            . "[{$turn['at']}]  сессия: {$sessionId}\n"
            . "модель: {$turn['provider']}/{$turn['model']}\n"
            . "каталог: {$names}\n\n"
            . "ПОЛЬЗОВАТЕЛЬ:\n{$turn['userMessage']}\n\n"
            . "БОТ:\n" . ($turn['assistantMessage'] ?: '—')
            . (!empty($turn['error']) ? "\n\nОШИБКА: {$turn['error']}" : '')
            . "\n";

        file_put_contents(self::historyTxtPath(), $block, FILE_APPEND);
    }

    /**
     * @return array{created: bool, phone: ?string, leadId: ?int, contactId: ?int}
     */
    public static function getLeadMeta(string $sessionId): array
    {
        $session = self::readSession($sessionId);
        $lead = is_array($session['lead'] ?? null) ? $session['lead'] : [];

        return [
            'created' => !empty($lead['created']),
            'phone' => $lead['phone'] ?? null,
            'leadId' => isset($lead['leadId']) ? (int)$lead['leadId'] : null,
            'contactId' => isset($lead['contactId']) ? (int)$lead['contactId'] : null,
        ];
    }

    public static function appendChannelLog(string $sessionId, string $channel, string $status, string $detail): void
    {
        if (!Config::isChatLogEnabled()) {
            return;
        }
        self::ensureLogDir();
        $line = date('Y-m-d H:i:s') . "\t{$sessionId}\t{$channel}\t{$status}\t{$detail}\n";
        file_put_contents(self::logDir() . '/lead-channels.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function markLeadCreated(string $sessionId, string $phone, ?int $leadId, ?int $contactId): void
    {
        $session = self::readSession($sessionId) ?? [
            'id' => $sessionId,
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'turns' => [],
        ];
        $session['lead'] = [
            'created' => true,
            'phone' => $phone,
            'leadId' => $leadId,
            'contactId' => $contactId,
            'at' => date('c'),
        ];
        $session['updatedAt'] = date('c');
        self::writeSession($session);
    }
}
