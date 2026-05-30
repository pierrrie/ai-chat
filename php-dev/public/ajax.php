<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Bitrix\Main\Web\Json;
use Draxter\Aichat\ChatHandler;
use Draxter\Aichat\RateLimit;
use Draxter\Aichat\Settings;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Expose-Headers: X-Chat-Session-Id, X-Chat-Lead-Created');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$handler = new ChatHandler();
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
if ($path === '/api/health' || $action === 'health') {
    $handler->handleHealth();
    exit;
}

if ($action === 'transcribe' || $path === '/api/transcribe') {
    if (!RateLimit::check('voice')) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Слишком много голосовых запросов. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $handler->handleTranscribe();
    exit;
}

if ($action === 'tts' || $path === '/api/tts') {
    if (!RateLimit::check('voice')) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo Json::encode(['error' => 'Слишком много голосовых запросов. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $handler->handleTts();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Settings::isWidgetEnabled()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Виджет отключён'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!RateLimit::check('chat')) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['error' => 'Слишком много запросов к чату. Подождите минуту.', 'code' => 'RATE_LIMIT'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = [];
if ($raw !== false && $raw !== '') {
    try {
        $body = Json::decode($raw);
    } catch (\Throwable $e) {
        $body = $_POST;
    }
} else {
    $body = $_POST;
}

$handler->handleChat(is_array($body) ? $body : []);
