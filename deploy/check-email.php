<?php
$_SERVER['DOCUMENT_ROOT'] = '/var/www/a0601335/data/www/draxter.ru';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('draxter.aichat');

use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Mail;
use Draxter\Aichat\ApiErrorLog;
use Draxter\Aichat\ChatLog;
use Draxter\Aichat\Config;
use Draxter\Aichat\LeadMailer;
use Draxter\Aichat\Settings;

echo 'email_enabled=' . (Settings::isCrmEmailEnabled() ? 'Y' : 'N') . PHP_EOL;
echo 'email_to=' . Settings::get('CRM_EMAIL_TO', '') . PHP_EOL;
echo 'recipients=' . implode(',', Settings::crmEmailRecipients()) . PHP_EOL;
echo 'email_from=' . Option::get('main', 'email_from', '') . PHP_EOL;
echo 'b24_enabled=' . (Config::isBitrix24LeadEnabled() ? 'Y' : 'N') . PHP_EOL;

$dir = ChatLog::logDir();
$api = glob($dir . '/api-*.log') ?: [];
rsort($api);
if ($api !== []) {
    echo "--- tail " . basename($api[0]) . " ---" . PHP_EOL;
    $lines = file($api[0], FILE_IGNORE_NEW_LINES) ?: [];
    echo implode(PHP_EOL, array_slice($lines, -20)) . PHP_EOL;
}

if (($argv[1] ?? '') === 'send-test') {
    $to = Settings::crmEmailRecipients();
    if ($to === []) {
        echo "no recipients\n";
        exit(1);
    }
    $mail = [
        'TO' => implode(',', $to),
        'SUBJECT' => 'AI-chat test ' . date('Y-m-d H:i:s'),
        'BODY' => '<p>Test lead mail from draxter.aichat</p>',
        'CONTENT_TYPE' => 'text/html',
        'CHARSET' => 'UTF-8',
        'HEADERS' => [],
    ];
    $from = trim((string)Option::get('main', 'email_from', ''));
    if ($from !== '') {
        $mail['FROM'] = $from;
    }
    try {
        $sent = Mail::send($mail);
        echo 'send_result=' . ($sent ? 'ok' : 'false') . PHP_EOL;
    } catch (Throwable $e) {
        echo 'send_error=' . $e->getMessage() . PHP_EOL;
    }
}
