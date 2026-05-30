<?php

/**
 * Совместимость с PHP 7.4 (функции появились в PHP 8.0).
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle): bool
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        if ($len > strlen($haystack)) {
            return false;
        }

        return substr($haystack, -$len) === $needle;
    }
}
