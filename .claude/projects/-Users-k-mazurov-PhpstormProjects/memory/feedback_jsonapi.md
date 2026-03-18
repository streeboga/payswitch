---
name: jsonapi_format
description: Project uses JSON:API v1.1 format (not Hyperswitch wire-compatible). Use timacdonald/json-api for resources, Scramble for OpenAPI docs. All responses follow {data: {type, id, attributes, relationships}}.
type: feedback
---

Проект использует JSON:API v1.1 формат вместо Hyperswitch wire-compatible plain JSON.

**Why:** Стандартизация API по JSON:API v1.1 spec важнее wire-совместимости с Hyperswitch. Клиенты Hyperswitch всё равно потребуют адаптер.

**How to apply:**
- Пакет `timacdonald/json-api` для ресурсов (JsonApiResource)
- `dedoc/scramble` для автогенерации OpenAPI из кода
- Middleware `ForceJsonApiContentType` для `application/vnd.api+json`
- Ответы: `{data: {type, id, attributes, relationships, links}}`
- Ошибки: `{errors: [{status, code, title, detail, source?}]}`
- Пагинация: `?page[number]=1&page[size]=20`
- Фильтрация: `?filter[status]=active`
- Сортировка: `?sort=-created_at`
- PATCH для обновлений (не PUT)
- 201 + Location header для создания
- 204 No Content для удаления
