# Установка draxter.aichat (v2.1)

## Требования

- PHP 7.4+ (рекомендуется 8.1+)
- Расширения: `curl`, `mbstring`, `json`
- 1С-Битрикс: Управление сайтом
- Для локального CRM: модуль `crm`
- Для каталога из инфоблока: модуль `iblock`

## Шаги

1. Скопируйте в корень сайта:
   - `local/modules/draxter.aichat/`
   - `local/components/draxter/`
   - `local/ajax/draxter_aichat.php`
2. **Настройки → Настройки модулей → draxter.aichat** — укажите провайдер AI, ключ API, каталог.
3. Подключите виджет в footer: `<?$APPLICATION->IncludeComponent('draxter:aichat.chat', '', []);?>`  
   или включите **Автоподключение на всех страницах**.
4. Проверка: откройте `/local/ajax/draxter_aichat.php?action=health` — в JSON должно быть `hasApiKey: true`.

## Опционально: aichat.config.php

Файл `config/aichat.config.php` (вне git) может дублировать настройки для dev. Приоритет у полей в админке (база `b_option`).

Подробнее: [ADMIN.ru.md](ADMIN.ru.md), [CRM.ru.md](CRM.ru.md).
