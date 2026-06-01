<?php
/**
 * Диагностика: почему модуль не виден в «Модули».
 * URL: /local/modules/draxter.aichat/install/check.php (под администратором)
 */

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $USER, $APPLICATION;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ только для администратора');
}

$moduleId = 'draxter.aichat';
$root = $_SERVER['DOCUMENT_ROOT'];
$installFile = $root . '/local/modules/' . $moduleId . '/install/index.php';
$lines = [];

$lines[] = 'PHP: ' . PHP_VERSION;
$lines[] = 'Путь install/index.php: ' . $installFile;
$lines[] = 'Файл существует: ' . (is_file($installFile) ? 'да' : 'НЕТ');
$lines[] = 'Читается: ' . (is_readable($installFile) ? 'да' : 'нет');

if (is_file($installFile)) {
    $lines[] = 'Размер файла: ' . filesize($installFile) . ' байт (ожидается ~3500–5000, не сотни КБ HTML)';
}

$lines[] = 'Уже в реестре Bitrix: ' . (\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId) ? 'установлен' : 'не установлен');

$objectOk = false;
if (is_file($installFile)) {
    try {
        $module = CModule::CreateModuleObject($moduleId);
        if ($module && $module instanceof CModule) {
            $objectOk = true;
            $lines[] = 'CModule::CreateModuleObject: OK';
            $lines[] = 'MODULE_NAME: ' . ($module->MODULE_NAME ?? '—');
            $lines[] = 'MODULE_VERSION: ' . ($module->MODULE_VERSION ?? '—');
        } else {
            $lines[] = 'CModule::CreateModuleObject: вернул пусто — Bitrix НЕ видит модуль (часто синтаксис PHP в install/index.php)';
        }
    } catch (Throwable $e) {
        $lines[] = 'Ошибка при загрузке модуля: ' . $e->getMessage();
    }
}

if (!$objectOk) {
    $lines[] = '---';
    $lines[] = 'Проверьте: папка строго /local/modules/draxter.aichat/ (не вложенная draxter.aichat/draxter.aichat/)';
    $lines[] = 'Класс в install/index.php должен называться draxter_aichat (точка → подчёркивание)';
    $lines[] = 'После исправления: Настройки → Производительность → Очистить кэш';
}

$APPLICATION->SetTitle('Проверка draxter.aichat');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

echo '<pre style="font:14px/1.5 monospace;padding:16px;background:#f5f5f5">';
echo htmlspecialcharsbx(implode("\n", $lines));
echo '</pre>';

echo '<p><a class="adm-btn" href="setup.php">Перейти к установке (setup.php)</a></p>';

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
