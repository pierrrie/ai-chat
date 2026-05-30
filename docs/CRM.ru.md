# CRM и лиды (v2.1)

При получении телефона в чате модуль может отправить лид в **несколько каналов** одновременно.

## Каналы

| Канал | Настройка | Описание |
|-------|-----------|----------|
| Bitrix24 | `BITRIX24_LEAD_ENABLED` + webhook | REST `crm.lead.add`, контакт |
| CRM сайта | `CRM_LOCAL_ENABLED` | `CCrmLead::Add` (модуль `crm`) |
| Email | `CRM_EMAIL_ENABLED` + `CRM_EMAIL_TO` | Письмо через `Bitrix\Main\Mail\Mail` |

Ошибка одного канала не отменяет остальные. Результаты пишутся в `lead-channels.log`.

## URL страницы

Виджет передаёт `page_url` и `page_title` в каждом запросе чата. Они попадают в комментарий лида и в маппинг UF-полей.

## Маппинг полей Bitrix24

Опция **CRM_B24_FIELD_MAP** (JSON):

```json
[
  {"field": "UF_CRM_LEAD_SOURCE", "source": "source_label"},
  {"field": "UF_CRM_SOURCE_URL", "source": "page_url"},
  {"field": "UTM_SOURCE", "source": "utm_source"}
]
```

Источники: `phone`, `name`, `comment`, `page_url`, `page_title`, `http_referer`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `_ym_uid`, `session_id`, `products`, `site_url`, `site_host`, `source_label`, `source_id`.

Кнопки в админке:

- **Подставить шаблон Draxter** — стандартные UF и UTM.
- **Загрузить коды полей из Bitrix24** — `crm.lead.fields` по webhook (подсказка кодов).

## Источник лида в Bitrix24

- **SOURCE_ID** — ищется в справочнике CRM по **имени** из `BITRIX24_SOURCE_LABEL` (например `aichat`).
- Поле «Источник» в карточке — **название** из справочника, не домен сайта.
- `UF_CRM_LEAD_SOURCE` — подпись из настроек (маппер).

## Локальный CRM

**CRM_LOCAL_FIELD_MAP** — те же правила, поля `CCrmLead`.

## Яндекс.Метрика

При успешном лиде виджет вызывает `ym(счётчик,'reachGoal',цель)` — настройки `YANDEX_METRIKA_*`.
