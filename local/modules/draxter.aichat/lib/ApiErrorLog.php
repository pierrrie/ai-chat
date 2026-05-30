<?php

namespace Draxter\Aichat;

class ApiErrorLog
{
    public static function write(
        string $provider,
        string $action,
        int $httpCode,
        string $message,
        ?string $sessionId = null
    ): void {
        $dir = ChatLog::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $line = date('Y-m-d H:i:s')
            . "\t" . $provider
            . "\t" . $action
            . "\t" . $httpCode
            . "\t" . str_replace(["\r", "\n", "\t"], ' ', $message)
            . "\t" . ($sessionId ?? '')
            . "\n";

        file_put_contents(self::pathForDate(date('Y-m-d')), $line, FILE_APPEND | LOCK_EX);
    }

    public static function pathForDate(string $date): string
    {
        return ChatLog::logDir() . '/api-' . $date . '.log';
    }

    /**
     * @return string[]
     */
    public static function listLogFiles(): array
    {
        $dir = ChatLog::logDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/api-*.log') ?: [];
        rsort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    public static function tailLines(string $filePath, int $limit = 200, ?string $filter = null): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];
        $lines = array_reverse($lines);
        if ($filter !== null && $filter !== '') {
            $filter = mb_strtolower($filter);
            $lines = array_values(array_filter(
                $lines,
                static fn($line) => str_contains(mb_strtolower($line), $filter)
            ));
        }

        return array_slice($lines, 0, $limit);
    }

    public static function purgeOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $cutoff = strtotime('-' . $days . ' days');
        $removed = 0;
        foreach (self::listLogFiles() as $file) {
            if (preg_match('/api-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $ts = strtotime($m[1] . ' 00:00:00');
                if ($ts !== false && $ts < $cutoff && @unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
