<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);
$assetsDir = $root . '/local/components/draxter/aichat.chat/templates/.default';

if (preg_match('#^/assets/(style\.css|script\.js)$#', $uri, $m)) {
    $file = $assetsDir . '/' . $m[1];
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    header('Content-Type: ' . ($m[1] === 'style.css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    readfile($file);
    return true;
}

if ($uri === '/api/health' || ($uri === '/local/ajax/draxter_aichat.php' && ($_GET['action'] ?? '') === 'health')) {
    $_GET['action'] = 'health';
    require __DIR__ . '/public/ajax.php';
    return true;
}

if ($uri === '/api/transcribe' || $uri === '/api/tts') {
    if ($uri === '/api/transcribe') {
        $_GET['action'] = 'transcribe';
    } else {
        $_GET['action'] = 'tts';
    }
    require __DIR__ . '/public/ajax.php';
    return true;
}

if ($uri === '/api/chat' || $uri === '/local/ajax/draxter_aichat.php') {
    require __DIR__ . '/public/ajax.php';
    return true;
}

if ($uri === '/' || $uri === '/index.php') {
    require __DIR__ . '/public/index.php';
    return true;
}

return false;
