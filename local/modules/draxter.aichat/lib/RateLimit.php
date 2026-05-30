<?php

namespace Draxter\Aichat;

class RateLimit
{
    public static function check(string $bucket = 'chat'): bool
    {
        $limit = Settings::rateLimitPerMinute();
        if ($limit <= 0) {
            return true;
        }

        $ip = self::clientIp();
        if ($ip === '') {
            return true;
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true;
        }

        $bucket = preg_replace('/[^a-z0-9_-]/', '', strtolower($bucket)) ?: 'chat';
        $file = $dir . '/' . md5($ip . ':' . $bucket) . '.json';
        $now = time();
        $windowStart = $now - 60;
        $hits = [];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['hits']) && is_array($data['hits'])) {
                foreach ($data['hits'] as $ts) {
                    if ((int)$ts >= $windowStart) {
                        $hits[] = (int)$ts;
                    }
                }
            }
        }

        if (count($hits) >= $limit) {
            return false;
        }

        $hits[] = $now;
        @file_put_contents($file, json_encode(['hits' => $hits]), LOCK_EX);

        return true;
    }

    public static function storageDir(): string
    {
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        $base = $docRoot !== '' ? $docRoot . '/upload/draxter_aichat_ratelimit' : sys_get_temp_dir() . '/draxter_aichat_ratelimit';

        return $base;
    }

    private static function clientIp(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $val = (string)$_SERVER[$key];
            if (str_contains($val, ',')) {
                $val = trim(explode(',', $val)[0]);
            }
            if (filter_var($val, FILTER_VALIDATE_IP)) {
                return $val;
            }
        }

        return '';
    }
}
