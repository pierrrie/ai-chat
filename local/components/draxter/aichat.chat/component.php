<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

\CBitrixComponent::includeComponentClass('draxter:aichat.chat');

$component = new DraxterAichatChatComponent($this);
$component->executeComponent();
