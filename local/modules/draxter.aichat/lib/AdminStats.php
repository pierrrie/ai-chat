<?php

namespace Draxter\Aichat;

class AdminStats
{
    /**
     * @return array{
     *   sessions: int,
     *   messages: int,
     *   errors: int,
     *   leads: int,
     *   topPhrases: array<int, array{phrase: string, count: int}>,
     *   recent: array<int, array{sessionId: string, date: string, phone: ?string, error: bool, turns: int}>
     * }
     */
    public static function aggregate(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = strtotime('-' . $days . ' days');
        $dir = ChatLog::logDir();
        $sessions = 0;
        $messages = 0;
        $errors = 0;
        $leads = 0;
        $phraseCounts = [];
        $recent = [];

        if (!is_dir($dir)) {
            return self::emptyResult();
        }

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode(file_get_contents($file) ?: '{}', true);
            if (!is_array($data)) {
                continue;
            }
            $updated = strtotime((string)($data['updatedAt'] ?? $data['createdAt'] ?? ''));
            if ($updated === false || $updated < $since) {
                continue;
            }

            $sessions++;
            $sessionId = (string)($data['id'] ?? basename($file, '.json'));
            $turns = is_array($data['turns'] ?? null) ? $data['turns'] : [];
            $sessionError = false;
            $lastPhone = $data['lead']['phone'] ?? null;

            foreach ($turns as $turn) {
                $messages++;
                if (!empty($turn['error'])) {
                    $errors++;
                    $sessionError = true;
                }
                $userMsg = trim((string)($turn['userMessage'] ?? ''));
                if ($userMsg !== '') {
                    $phrase = mb_substr($userMsg, 0, 80);
                    $phraseCounts[$phrase] = ($phraseCounts[$phrase] ?? 0) + 1;
                }
            }

            if (!empty($data['lead']['created'])) {
                $leads++;
            }

            $recent[] = [
                'sessionId' => $sessionId,
                'date' => (string)($data['updatedAt'] ?? ''),
                'phone' => is_string($lastPhone) ? $lastPhone : null,
                'error' => $sessionError,
                'turns' => count($turns),
                'updatedTs' => $updated,
            ];
        }

        usort($recent, static fn($a, $b) => ($b['updatedTs'] ?? 0) <=> ($a['updatedTs'] ?? 0));
        $recent = array_slice($recent, 0, 20);
        foreach ($recent as &$row) {
            unset($row['updatedTs']);
        }
        unset($row);

        arsort($phraseCounts);
        $topPhrases = [];
        foreach (array_slice($phraseCounts, 0, 10, true) as $phrase => $count) {
            $topPhrases[] = ['phrase' => $phrase, 'count' => $count];
        }

        return [
            'sessions' => $sessions,
            'messages' => $messages,
            'errors' => $errors,
            'leads' => $leads,
            'topPhrases' => $topPhrases,
            'recent' => $recent,
        ];
    }

  /**
   * @return array{sessions: int, messages: int, errors: int, leads: int, topPhrases: array, recent: array}
   */
    private static function emptyResult(): array
    {
        return [
            'sessions' => 0,
            'messages' => 0,
            'errors' => 0,
            'leads' => 0,
            'topPhrases' => [],
            'recent' => [],
        ];
    }

    public static function formatSessionForAdmin(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        $path = ChatLog::logDir() . '/' . $safe . '.json';
        if (!is_file($path)) {
            return 'Сессия не найдена.';
        }
        $data = json_decode(file_get_contents($path) ?: '{}', true);
        if (!is_array($data)) {
            return 'Некорректный JSON.';
        }

        $out = "Сессия: {$sessionId}\n";
        $out .= 'Создана: ' . ($data['createdAt'] ?? '—') . "\n";
        $out .= 'Обновлена: ' . ($data['updatedAt'] ?? '—') . "\n";
        if (!empty($data['lead'])) {
            $out .= 'Лид: ' . json_encode($data['lead'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $out .= "\n--- Диалог ---\n";
        foreach ($data['turns'] ?? [] as $i => $turn) {
            $n = $i + 1;
            $out .= "\n[{$n}] " . ($turn['at'] ?? '') . "\n";
            $out .= "USER: " . ($turn['userMessage'] ?? '') . "\n";
            $out .= "BOT: " . ($turn['assistantMessage'] ?? '') . "\n";
            if (!empty($turn['error'])) {
                $out .= "ERROR: {$turn['error']}\n";
            }
        }

        return $out;
    }
}
