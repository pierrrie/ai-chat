<?php

namespace Draxter\Aichat;

class Phone
{
    public static function extract(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match(
            '/(?:\+7|8|7)[\s\-()]*(\d{3})[\s\-()]*(\d{3})[\s\-()]*(\d{2})[\s\-()]*(\d{2})/u',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $phone = self::normalizeRuMobile($m[1][0] . $m[2][0] . $m[3][0] . $m[4][0]);
            if ($phone !== null && !self::isPriceContext($text, (int)$m[0][1])) {
                return $phone;
            }
        }

        if (preg_match(
            '/(?:^|[^\d])(\d{3})[\s\-()]*(\d{3})[\s\-()]*(\d{2})[\s\-()]*(\d{2})(?:[^\d]|$)/u',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $phone = self::normalizeRuMobile($m[1][0] . $m[2][0] . $m[3][0] . $m[4][0]);
            if ($phone !== null && !self::isPriceContext($text, (int)$m[0][1])) {
                return $phone;
            }
        }

        return null;
    }

    private static function normalizeRuMobile(string $digits10): ?string
    {
        if (!preg_match('/^9\d{9}$/', $digits10)) {
            return null;
        }
        return '+7' . $digits10;
    }

    private static function isPriceContext(string $text, int $index): bool
    {
        $start = max(0, $index - 24);
        $window = mb_strtolower(mb_substr($text, $start, 48));
        return (bool)preg_match('/(?:руб|₽|р\.?\b|бюджет|цена|стоит|за\s|до\s|\d\s*000)/u', $window);
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function extractFromMessages(array $messages): ?string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $phone = self::extract($messages[$i]['content'] ?? '');
            if ($phone) {
                return $phone;
            }
        }
        return null;
    }

    public static function extractName(string $text): ?string
    {
        if (preg_match('/(?:меня\s+зовут|я\s+)([а-яёa-z][а-яёa-z\s\-]{1,40})/ui', $text, $m)) {
            $name = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
            if (mb_strlen($name) >= 2 && mb_strlen($name) <= 60) {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function extractNameFromMessages(array $messages): ?string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            $name = self::extractName($m['content'] ?? '');
            if ($name) {
                return $name;
            }
        }
        return null;
    }
}
