<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class draxter_aichat extends CModule
{
    public $MODULE_ID = 'draxter.aichat';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME = 'Draxter';
    public $PARTNER_URI = 'https://draxter.ru';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('DRAXTER_AICHAT_MODULE_NAME') ?: 'AI-консультант по каталогу';
        $this->MODULE_DESCRIPTION = Loc::getMessage('DRAXTER_AICHAT_MODULE_DESC') ?: 'Виджет чата с подбором товаров';
    }

    public function DoInstall(): void
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->installFiles();
        $this->installOptions();
        $this->installEvents();
        $this->installModuleRights();
    }

    public function DoUninstall(): void
    {
        $this->uninstallEvents();
        $this->uninstallFiles();
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    public function installEvents(): bool
    {
        RegisterModuleDependences('main', 'OnEndBufferContent', $this->MODULE_ID, '\\Draxter\\Aichat\\WidgetInject', 'onEndBufferContent');
        RegisterModuleDependences('main', 'OnEpilog', $this->MODULE_ID, '\\Draxter\\Aichat\\WidgetInject', 'onEpilog');
        Option::set($this->MODULE_ID, 'WIDGET_EVENTS_V2', 'Y');

        return true;
    }

    private function installModuleRights(): void
    {
        global $APPLICATION;
        if (isset($APPLICATION) && is_object($APPLICATION) && method_exists($APPLICATION, 'SetGroupRight')) {
            $APPLICATION->SetGroupRight($this->MODULE_ID, 2, 'R');
            $APPLICATION->SetGroupRight($this->MODULE_ID, 1, 'W');
        }
        Option::set($this->MODULE_ID, 'MODULE_RIGHTS_V1', 'Y');
    }

    public function uninstallEvents(): bool
    {
        UnRegisterModuleDependences('main', 'OnEndBufferContent', $this->MODULE_ID, '\\Draxter\\Aichat\\WidgetInject', 'onEndBufferContent');
        UnRegisterModuleDependences('main', 'OnEpilog', $this->MODULE_ID, '\\Draxter\\Aichat\\WidgetInject', 'onEpilog');
        Option::delete($this->MODULE_ID, 'WIDGET_EVENTS_V2');

        return true;
    }

    public function GetModuleRightList(): array
    {
        return [
            'reference_id' => ['D', 'R', 'W'],
            'reference' => [
                '[D] ' . (Loc::getMessage('DRAXTER_AICHAT_RIGHT_DENIED') ?: 'Доступ закрыт'),
                '[R] ' . (Loc::getMessage('DRAXTER_AICHAT_RIGHT_READ') ?: 'Чтение'),
                '[W] ' . (Loc::getMessage('DRAXTER_AICHAT_RIGHT_WRITE') ?: 'Запись'),
            ],
        ];
    }

    public function installFiles(): bool
    {
        CopyDirFiles(
            __DIR__ . '/components',
            $_SERVER['DOCUMENT_ROOT'] . '/local/components',
            true,
            true
        );
        CopyDirFiles(
            __DIR__ . '/ajax',
            $_SERVER['DOCUMENT_ROOT'] . '/local/ajax',
            true,
            true
        );
        return true;
    }

    public function uninstallFiles(): bool
    {
        DeleteDirFilesEx('/local/components/draxter/aichat.chat');
        @unlink($_SERVER['DOCUMENT_ROOT'] . '/local/ajax/draxter_aichat.php');
        return true;
    }

    private function installOptions(): void
    {
        global $draxter_aichat_default_option;
        include __DIR__ . '/../default_options.php';
        if (!is_array($draxter_aichat_default_option)) {
            return;
        }
        foreach ($draxter_aichat_default_option as $name => $value) {
            if (Option::get($this->MODULE_ID, $name, '') === '') {
                Option::set($this->MODULE_ID, $name, $value);
            }
        }
    }
}
