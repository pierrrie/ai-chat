<?php
/**
 * Установка модуля, если в module_admin.php модуль не виден или ссылка install=Y ничего не делает.
 * Откройте в браузере под администратором (после загрузки файлов модуля на сервер).
 *
 * URL: /local/modules/draxter.aichat/install/setup.php
 */

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $USER, $APPLICATION;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ только для администратора');
}

$moduleId = 'draxter.aichat';
$installPath = __DIR__ . '/index.php';
$messages = [];
$errors = [];

if (!is_readable($installPath)) {
    $errors[] = 'Не найден файл ' . $installPath;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && ($_POST['action'] ?? '') === 'install' && $errors === []) {
    $module = CModule::CreateModuleObject($moduleId);
    if (!$module) {
        $errors[] = 'Bitrix не смог загрузить класс модуля. Проверьте PHP '
            . PHP_VERSION . ' (нужен 7.4+) и файл install/index.php.';
    } elseif (\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId)) {
        $messages[] = 'Модуль уже установлен.';
    } else {
        $module->DoInstall();
        if (\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId)) {
            $messages[] = 'Модуль draxter.aichat успешно установлен.';
            $messages[] = 'Дальше: Настройки → Настройки модулей → draxter.aichat';
            $messages[] = 'Проверка: /local/ajax/draxter_aichat.php?action=health';
        } else {
            $errors[] = 'DoInstall() выполнен, но модуль не зарегистрирован. Смотрите лог PHP.';
        }
    }
}

$isInstalled = \Bitrix\Main\ModuleManager::isModuleInstalled($moduleId);
$canLoad = is_readable($installPath);

$APPLICATION->SetTitle('Установка draxter.aichat');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($messages !== []) {
    CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => implode('<br>', $messages), 'HTML' => true]);
}
if ($errors !== []) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => implode('<br>', $errors), 'HTML' => true]);
}
?>
<div class="adm-info-message-wrap">
    <p><strong>ID модуля:</strong> <?= htmlspecialcharsbx($moduleId) ?></p>
    <p><strong>Установлен:</strong> <?= $isInstalled ? 'да' : 'нет' ?></p>
    <p><strong>install/index.php:</strong> <?= $canLoad ? 'найден' : 'не найден' ?></p>
    <p><strong>PHP:</strong> <?= htmlspecialcharsbx(PHP_VERSION) ?></p>
</div>
<?php if (!$isInstalled && $canLoad): ?>
<form method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="action" value="install">
    <input type="submit" class="adm-btn-save" value="Установить draxter.aichat">
</form>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
