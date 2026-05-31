<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Draxter\Aichat\Config;
use Draxter\Aichat\Settings;
use Draxter\Aichat\WidgetInject;

class DraxterAichatChatComponent extends CBitrixComponent
{
    public function onPrepareComponentParams($params): array
    {
        $params['SHOP_NAME'] = trim((string)($params['SHOP_NAME'] ?? ''));
        $params['EMBEDDED'] = ($params['EMBEDDED'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $params['AJAX_URL'] = trim((string)($params['AJAX_URL'] ?? '/local/ajax/draxter_aichat.php'));

        return $params;
    }

    public function executeComponent(): void
    {
        if (!Loader::includeModule('draxter.aichat')) {
            ShowError('Модуль draxter.aichat не установлен');
            return;
        }

        if (WidgetInject::isAdminArea()) {
            return;
        }

        if (!Settings::isWidgetEnabled()) {
            return;
        }

        $this->arResult['SHOP_NAME'] = $this->arParams['SHOP_NAME'] !== ''
            ? $this->arParams['SHOP_NAME']
            : Config::shopName();
        $this->arResult['AGENT_NAME'] = Settings::widgetAgentName();
        $this->arResult['STATUS_TEXT'] = Settings::widgetStatusText();
        $this->arResult['SHOW_ONLINE'] = Settings::showOnlineStatus() ? '1' : '0';
        $this->arResult['WELCOME'] = Settings::welcomeMessage();
        $this->arResult['EMBEDDED'] = $this->arParams['EMBEDDED'];
        $this->arResult['AJAX_URL'] = $this->arParams['AJAX_URL'];
        $this->arResult['AVATAR_URL'] = Settings::avatarUrl();
        $this->arResult['ACCENT_COLOR'] = Settings::accentColor();
        $this->arResult['FAB_COLOR'] = Settings::fabButtonColor();
        $this->arResult['FAB_TITLE'] = Settings::fabTitle();
        $this->arResult['FAB_SUBTITLE'] = Settings::fabSubtitle();
        $this->arResult['FAB_ALIGN_H'] = Settings::fabHorizontalAlign();
        $this->arResult['FAB_ALIGN_V'] = Settings::fabVerticalAlign();
        $this->arResult['FAB_OFFSET_H'] = (string)Settings::fabHorizontalOffset();
        $this->arResult['FAB_OFFSET_V'] = (string)Settings::fabVerticalOffset();
        $this->arResult['VOICE_ENABLED'] = Settings::isVoiceEnabled() ? '1' : '0';
        $this->arResult['VOICE_REPLY'] = Settings::isVoiceReplyEnabled() ? '1' : '0';
        $this->arResult['VOICE_MAX_SECONDS'] = (string)Settings::voiceMaxSeconds();
        $this->arResult['VOICE_TTS_MAX_CHARS'] = (string)Settings::voiceTtsMaxChars();
        $this->arResult['VOICE_TTS_RATE'] = (string)Settings::voiceTtsRate();
        try {
            $this->arResult['VOICE_STT'] = Settings::resolveVoiceSttProvider();
        } catch (\Throwable $e) {
            $this->arResult['VOICE_STT'] = Settings::voiceSttProvider() === 'browser' ? 'browser' : 'gemini';
        }
        $this->arResult['STATUS_LINE'] = Settings::widgetStatusLine();
        $this->arResult['YM_ENABLED'] = Settings::isYandexMetrikaEnabled() ? '1' : '0';
        $this->arResult['YM_COUNTER'] = (string)Settings::yandexMetrikaCounterId();
        $this->arResult['YM_GOAL'] = Settings::yandexMetrikaGoal();

        $this->includeComponentTemplate();
    }
}
