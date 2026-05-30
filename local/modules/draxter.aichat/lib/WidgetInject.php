<?php

namespace Draxter\Aichat;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class WidgetInject
{
    private const EVENTS_OPTION = 'WIDGET_EVENTS_V2';
    private const MODULE_RIGHTS_OPTION = 'MODULE_RIGHTS_V1';

    public static function isAdminArea(): bool
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION) {
            return true;
        }

        foreach (['REQUEST_URI', 'SCRIPT_NAME', 'PHP_SELF'] as $key) {
            if (self::pathIsAdmin((string)($_SERVER[$key] ?? ''))) {
                return true;
            }
        }

        global $APPLICATION;
        if (isset($APPLICATION) && is_object($APPLICATION)) {
            if (self::pathIsAdmin((string)$APPLICATION->GetCurPage())) {
                return true;
            }
        }

        return false;
    }

    public static function stripWidgetFromHtml(string &$content): void
    {
        if ($content === '' || stripos($content, 'draxter-aichat-root') === false) {
            return;
        }

        $content = preg_replace(
            '/<link[^>]*draxter[^>]*aichat[^>]*style\.css[^>]*>\s*/i',
            '',
            $content
        ) ?? $content;
        $content = preg_replace(
            '/<div[^>]*\bdraxter-aichat-root\b[^>]*>\s*<\/div>\s*<script[^>]*aichat[^>]*script\.js[^>]*><\/script>\s*/is',
            '',
            $content
        ) ?? $content;
    }

    private static function pathIsAdmin(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $pathOnly = parse_url($path, PHP_URL_PATH);
        if (!is_string($pathOnly) || $pathOnly === '') {
            $pathOnly = strtok($path, '?') ?: $path;
        }

        $pathOnly = strtolower(rawurldecode($pathOnly));

        if (preg_match('#^/local/ajax(/|$)#i', $pathOnly)) {
            return true;
        }

        return (bool)preg_match('#^/bitrix/(admin|tools)(/|$)#i', $pathOnly);
    }

    private static function isAjaxApiRequest(): bool
    {
        foreach (['SCRIPT_NAME', 'PHP_SELF', 'REQUEST_URI'] as $key) {
            $path = (string)($_SERVER[$key] ?? '');
            if ($path === '') {
                continue;
            }
            $pathOnly = parse_url($path, PHP_URL_PATH);
            if (!is_string($pathOnly) || $pathOnly === '') {
                $pathOnly = strtok($path, '?') ?: $path;
            }
            if (preg_match('#/local/ajax/#i', $pathOnly)) {
                return true;
            }
        }

        return false;
    }

    /** Admin layout marker — not present on public storefront pages. */
    private static function isStrictAdminHtml(string $content): bool
    {
        return $content !== '' && str_contains($content, 'adm-main-wrap');
    }

    public static function registerEventsIfNeeded(): void
    {
        if (!function_exists('RegisterModuleDependences')) {
            return;
        }
        if (Option::get('draxter.aichat', self::EVENTS_OPTION, 'N') === 'Y') {
            return;
        }

        RegisterModuleDependences(
            'main',
            'OnEndBufferContent',
            'draxter.aichat',
            '\\Draxter\\Aichat\\WidgetInject',
            'onEndBufferContent'
        );
        RegisterModuleDependences(
            'main',
            'OnEpilog',
            'draxter.aichat',
            '\\Draxter\\Aichat\\WidgetInject',
            'onEpilog'
        );
        Option::set('draxter.aichat', self::EVENTS_OPTION, 'Y');
    }

    /** Guest/public read access so IncludeComponent is not admin-only. */
    public static function ensurePublicAccess(): void
    {
        if (Option::get('draxter.aichat', self::MODULE_RIGHTS_OPTION, 'N') === 'Y') {
            return;
        }

        global $APPLICATION;
        if (!isset($APPLICATION) || !is_object($APPLICATION) || !method_exists($APPLICATION, 'SetGroupRight')) {
            return;
        }

        $APPLICATION->SetGroupRight('draxter.aichat', 2, 'R');
        Option::set('draxter.aichat', self::MODULE_RIGHTS_OPTION, 'Y');
    }

    public static function onEpilog(): void
    {
        if (self::isAjaxApiRequest() || self::isAdminArea()) {
            return;
        }

        $html = self::renderComponentHtml();
        if ($html !== '') {
            echo $html;
        }
    }

    /**
     * @param string $content
     */
    public static function onEndBufferContent(&$content): void
    {
        if (self::isAjaxApiRequest()) {
            return;
        }

        if (self::isAdminArea() || self::isStrictAdminHtml($content)) {
            self::stripWidgetFromHtml($content);
            return;
        }

        if (!self::shouldInject()) {
            return;
        }
        if (stripos($content, 'draxter-aichat-root') !== false) {
            return;
        }

        $html = self::renderComponentHtml();
        if ($html === '') {
            return;
        }

        if (preg_match('/<\/body>/i', $content)) {
            $content = preg_replace('/<\/body>/i', $html . '</body>', $content, 1);
            return;
        }

        $content .= $html;
    }

    private static function shouldInject(): bool
    {
        if (self::isAdminArea()) {
            return false;
        }
        if (defined('BX_CRONTAB') && BX_CRONTAB === true) {
            return false;
        }
        if (!Loader::includeModule('draxter.aichat')) {
            return false;
        }
        if (!Settings::isWidgetEnabled() || !Settings::getBool('WIDGET_AUTO_INJECT', false)) {
            return false;
        }

        return true;
    }

    private static function renderComponentHtml(): string
    {
        if (!self::shouldInject()) {
            return '';
        }

        global $APPLICATION;
        if (!is_object($APPLICATION)) {
            return '';
        }

        if (stripos((string)$APPLICATION->GetProperty('draxter_aichat_injected'), 'Y') === 0) {
            return '';
        }

        ob_start();
        $APPLICATION->IncludeComponent(
            'draxter:aichat.chat',
            '',
            [],
            false,
            ['HIDE_ICONS' => 'Y']
        );
        $html = (string)ob_get_clean();
        if ($html !== '') {
            $APPLICATION->SetPageProperty('draxter_aichat_injected', 'Y');
        }

        return $html;
    }
}
