<?php

declare(strict_types=1);

if (!function_exists('htmlspecialcharsbx')) {
    function htmlspecialcharsbx($string, $flags = ENT_COMPAT)
    {
        return htmlspecialchars((string) $string, $flags, 'UTF-8');
    }
}
