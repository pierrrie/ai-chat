<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('DisableEventsCheck', true);

@set_time_limit(300);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Draxter\Aichat\ChatHandler;
use Draxter\Aichat\RateLimit;
use Draxter\Aichat\Settings;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Expose-Headers: X-Chat-Session-Id, X-Chat-Lead-Created');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    die;
}

if (!Loader::includeModule('draxter.aichat')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Модуль draxter.aichat не установлен'], JSON_UNESCAPED_UNICODE);
    die;
}

$handler = new ChatHandler();
$action = isset($_GET['action']) ? (string)$_GET['action'] : 'chat';

if ($action === 'health') {
    $handler->handleHealth();
    die;
}

if ($action === 'transcribe') {
    if (!RateLimit::check('voice')) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Слишком много голосовых запросов. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
        die;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        die;
    }
    $handler->handleTranscribe();
    die;
}

if ($action === 'faq_preview') {
    global $USER;
    if (!is_object($USER) || !$USER->IsAdmin()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Доступ только для администратора'], JSON_UNESCAPED_UNICODE);
        die;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        die;
    }
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];
    if (!is_array($body)) {
        $body = [];
    }
    $sessid = (string)($body['sessid'] ?? $_POST['sessid'] ?? '');
    if ($sessid === '' || !check_bitrix_sessid($sessid)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Неверный sessid'], JSON_UNESCAPED_UNICODE);
        die;
    }
    $handler->handleFaqPreview($body);
    die;
}

if ($action === 'tts') {
    if (!RateLimit::check('voice')) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Слишком много голосовых запросов. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
        die;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        die;
    }
    $handler->handleTts();
    die;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    die;
}

if (!Settings::isWidgetEnabled()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Виджет отключён в настройках модуля', 'code' => 'WIDGET_DISABLED'], JSON_UNESCAPED_UNICODE);
    die;
}

if (!RateLimit::check('chat')) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Слишком много запросов к чату. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
    die;
}

$raw = file_get_contents('php://input');
$body = [];
if ($raw) {
    try {
        $body = Json::decode($raw);
    } catch (\Throwable $e) {
        $body = $_POST;
    }
} else {
    $body = $_POST;
}

$handler->handleChat(is_array($body) ? $body : []);
die;
