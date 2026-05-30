<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'GROUPS' => [],
    'PARAMETERS' => [
        'SHOP_NAME' => [
            'PARENT' => 'BASE',
            'NAME' => 'Название в шапке чата',
            'TYPE' => 'STRING',
            'DEFAULT' => '',
        ],
        'EMBEDDED' => [
            'PARENT' => 'BASE',
            'NAME' => 'Встроенный режим (без кнопки FAB)',
            'TYPE' => 'CHECKBOX',
            'DEFAULT' => 'N',
        ],
        'AJAX_URL' => [
            'PARENT' => 'BASE',
            'NAME' => 'URL AJAX-обработчика',
            'TYPE' => 'STRING',
            'DEFAULT' => '/local/ajax/draxter_aichat.php',
        ],
    ],
];
