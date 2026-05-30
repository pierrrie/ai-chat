<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Draxter\Aichat\Config;
use Draxter\Aichat\Settings;

$shopName = htmlspecialchars(Config::shopName(), ENT_QUOTES, 'UTF-8');
$agentName = htmlspecialchars(Settings::widgetAgentName(), ENT_QUOTES, 'UTF-8');
$statusText = htmlspecialchars(Settings::widgetStatusText(), ENT_QUOTES, 'UTF-8');
$welcome = htmlspecialchars(Settings::welcomeMessage(), ENT_QUOTES, 'UTF-8');
$accent = htmlspecialchars(Settings::accentColor(), ENT_QUOTES, 'UTF-8');
$avatarUrl = htmlspecialchars(Settings::avatarUrl(), ENT_QUOTES, 'UTF-8');
$fabRight = htmlspecialchars(Settings::get('WIDGET_FAB_RIGHT', '24'), ENT_QUOTES, 'UTF-8');
$fabBottom = htmlspecialchars(Settings::get('WIDGET_FAB_BOTTOM', '24'), ENT_QUOTES, 'UTF-8');
$voiceEnabled = Settings::isVoiceEnabled() ? '1' : '0';
$voiceReply = Settings::isVoiceReplyEnabled() ? '1' : '0';
$showOnline = Settings::showOnlineStatus() ? '1' : '0';
$voiceMax = (string) Settings::voiceMaxSeconds();
$ymEnabled = Settings::isYandexMetrikaEnabled() ? '1' : '0';
$ymCounter = (string) Settings::yandexMetrikaCounterId();
$ymGoal = htmlspecialchars(Settings::yandexMetrikaGoal(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $shopName ?> — AI-чат (локально)</title>
    <link rel="stylesheet" href="/assets/style.css?v=2.0.5">
</head>
<body>
    <p style="font-family:system-ui,sans-serif;padding:1rem;color:#334155;font-size:14px;">
        Локальный просмотр виджета. Чат — кнопка в правом нижнем углу.
    </p>
    <div
        class="draxter-aichat-root"
        style="--drax-accent: <?= $accent ?>; --drax-fab-right: <?= $fabRight ?>px; --drax-fab-bottom: <?= $fabBottom ?>px;"
        data-shop-name="<?= $shopName ?>"
        data-agent-name="<?= $agentName ?>"
        data-status-text="<?= $statusText ?>"
        data-show-online="<?= $showOnline ?>"
        data-welcome="<?= $welcome ?>"
        data-ajax-url="/api/chat"
        data-embedded="0"
        data-avatar-url="<?= $avatarUrl ?>"
        data-voice-enabled="<?= $voiceEnabled ?>"
        data-voice-reply="<?= $voiceReply ?>"
        data-voice-max-seconds="<?= htmlspecialchars($voiceMax, ENT_QUOTES, 'UTF-8') ?>"
        data-ym-enabled="<?= $ymEnabled ?>"
        data-ym-counter="<?= htmlspecialchars($ymCounter, ENT_QUOTES, 'UTF-8') ?>"
        data-ym-goal="<?= $ymGoal ?>"
    ></div>
    <script src="/assets/script.js?v=2.0.5"></script>
</body>
</html>
