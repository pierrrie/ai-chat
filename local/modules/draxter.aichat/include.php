<?php

use Bitrix\Main\Loader;

require_once __DIR__ . '/lib/polyfill74.php';

Loader::registerAutoLoadClasses('draxter.aichat', [
    'Draxter\\Aichat\\CurlHelper' => 'lib/CurlHelper.php',
    'Draxter\\Aichat\\Product' => 'lib/Product.php',
    'Draxter\\Aichat\\YmlCatalog' => 'lib/YmlCatalog.php',
    'Draxter\\Aichat\\BitrixCatalog' => 'lib/BitrixCatalog.php',
    'Draxter\\Aichat\\Catalog' => 'lib/Catalog.php',
    'Draxter\\Aichat\\ModelSeries' => 'lib/ModelSeries.php',
    'Draxter\\Aichat\\Search' => 'lib/Search.php',
    'Draxter\\Aichat\\ContextSearch' => 'lib/ContextSearch.php',
    'Draxter\\Aichat\\Prompts' => 'lib/Prompts.php',
    'Draxter\\Aichat\\FaqKnowledge' => 'lib/FaqKnowledge.php',
    'Draxter\\Aichat\\CustomPrompts' => 'lib/CustomPrompts.php',
    'Draxter\\Aichat\\CustomProviders' => 'lib/CustomProviders.php',
    'Draxter\\Aichat\\CatalogPrompt' => 'lib/CatalogPrompt.php',
    'Draxter\\Aichat\\CatalogProfile' => 'lib/CatalogProfile.php',
    'Draxter\\Aichat\\LlmClient' => 'lib/LlmClient.php',
    'Draxter\\Aichat\\GeminiChat' => 'lib/GeminiChat.php',
    'Draxter\\Aichat\\ChatLog' => 'lib/ChatLog.php',
    'Draxter\\Aichat\\ChatHandler' => 'lib/ChatHandler.php',
    'Draxter\\Aichat\\SiteConfig' => 'lib/SiteConfig.php',
    'Draxter\\Aichat\\Settings' => 'lib/Settings.php',
    'Draxter\\Aichat\\Config' => 'lib/Config.php',
    'Draxter\\Aichat\\VoiceService' => 'lib/VoiceService.php',
    'Draxter\\Aichat\\RateLimit' => 'lib/RateLimit.php',
    'Draxter\\Aichat\\Phone' => 'lib/Phone.php',
    'Draxter\\Aichat\\CrmFieldMapper' => 'lib/CrmFieldMapper.php',
    'Draxter\\Aichat\\Bitrix24Lead' => 'lib/Bitrix24Lead.php',
    'Draxter\\Aichat\\BitrixCrmLead' => 'lib/BitrixCrmLead.php',
    'Draxter\\Aichat\\LeadMailer' => 'lib/LeadMailer.php',
    'Draxter\\Aichat\\LeadDispatcher' => 'lib/LeadDispatcher.php',
    'Draxter\\Aichat\\LeadFlow' => 'lib/LeadFlow.php',
    'Draxter\\Aichat\\TtsService' => 'lib/TtsService.php',
    'Draxter\\Aichat\\ApiErrorLog' => 'lib/ApiErrorLog.php',
    'Draxter\\Aichat\\AdminStats' => 'lib/AdminStats.php',
    'Draxter\\Aichat\\ChatApi' => 'lib/ChatApi.php',
    'Draxter\\Aichat\\ResponseEnrich' => 'lib/ResponseEnrich.php',
    'Draxter\\Aichat\\WidgetInject' => 'lib/WidgetInject.php',
]);

if (class_exists('Draxter\\Aichat\\WidgetInject')) {
    Draxter\Aichat\WidgetInject::registerEventsIfNeeded();
    Draxter\Aichat\WidgetInject::ensurePublicAccess();
}
