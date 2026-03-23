# Документация для мерчантов — дизайн

Дата: 2026-03-24

## Цель

Создать документацию для мерчантов по интеграции Payswitch: страницу в дашборде и MD-файлы.

## 1. Страница "Интеграция" в дашборде

Новая страница `/integration` в секции "Разработка" (между "Тест-платёж" и "Логи событий").

### Содержимое

**Блок "Ваши ключи"**
- Publishable key и Secret key с кнопками копирования
- Подтягиваются из текущего мерчанта (useApiKeys + useMerchantDetail)

**Блок "Webhook"**
- Текущий webhook_url из бизнес-профиля
- Signing key (payment_response_hash_key) с копированием

**Секция "Создание платежа"**
- HTTP-запрос: POST /api/v1/payments
- Headers, body, пример ответа с client_secret
- Все примеры с реальными ключами мерчанта (подставляются)
- Кнопка "Попробовать" → переход на /test-payment

**Секция "Подключение виджета"**
- JS-код: import, loadPayswitch, widgets, create, mount
- Live-превью виджета на странице (переиспользуем PaymentWidgetPreview)

**Секция "Обработка вебхуков"**
- Формат payload (JSON пример)
- Проверка подписи x-webhook-signature-512 (HMAC-SHA512)
- Список event types: payment_succeeded, payment_failed, payment_cancelled, payment_captured

### Язык

Русский (i18n ключи в ru.json, en.json)

## 2. MD-файлы

```
docs/merchant/
├── quickstart.md      — быстрый старт: ключи → платёж → виджет → вебхук
├── widget.md          — полное API @payswitch/js (loadPayswitch, widgets, events, types)
├── webhooks.md        — формат, подпись HMAC-SHA512, ретраи, event types
└── api-reference.md   — HTTP endpoints: payments, refunds, customers, capture, cancel
```

## 3. Что НЕ делаем

- Не рендерим MD в дашборде — страница нативная React
- Не публикуем SDK в npm
- Не делаем hosted docs site
- Примеры серверного кода — чистый HTTP (метод, URL, headers, body), без привязки к языку
