<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('DisableEventsCheck', true);
define('B_PROLOG_INCLUDED', true);

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require __DIR__ . '/globals.php';
require __DIR__ . '/bitrix-stub.php';

if (!\Bitrix\Main\Loader::includeModule('draxter.aichat')) {
    throw new RuntimeException('Не удалось подключить модуль draxter.aichat');
}
