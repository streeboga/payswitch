# Payswitch Dashboard — Дизайн-документ

## 1. Общее

**Тип:** Standalone React SPA (отдельное приложение, не Inertia)
**Связь с бэкендом:** REST JSON:API v1.1 (`/api/v1/`)
**Язык интерфейса:** Русский (i18n-ready, тексты через файлы локализации)
**Тема:** Light / Dark / System

### Стек

| Назначение | Библиотека |
|------------|-----------|
| UI-фреймворк | React 19 + TypeScript 5.7 |
| Сборка | Vite 7 |
| Роутинг | TanStack Router |
| Data fetching + кэш | TanStack Query |
| Таблицы | TanStack Table |
| Глобальный стейт | Zustand (контекст Org/Merchant/Profile, auth, preferences) |
| UI-компоненты | Radix UI primitives |
| Стили | Tailwind CSS 4 |
| Формы | React Hook Form + Zod |
| Графики | Recharts |
| HTTP-клиент | ky (обёртка над fetch) |
| Уведомления | Sonner |
| Иконки | Lucide React |
| Даты + таймзоны | date-fns + date-fns-tz |
| Drag & Drop | @dnd-kit (для routing rules, priority sort) |
| Unit-тесты | Vitest + Testing Library |
| E2E-тесты | Playwright |
| Компонент каталог | Storybook (опционально) |

### Структура проекта

```
src/
├── api/                  # HTTP-клиент, эндпоинты, типы ответов
│   ├── client.ts         # Базовый клиент с auth, CSRF, error handling
│   ├── endpoints/        # По одному файлу на ресурс
│   └── types/            # TypeScript-типы из JSON:API
├── app/                  # Корневой layout, providers, router
├── components/
│   ├── ui/               # Radix UI primitives
│   ├── data-table/       # Универсальная таблица
│   ├── context-switcher/ # Org → Merchant → Profile
│   └── shared/           # StatusBadge, CopyButton, JsonViewer, MoneyFormat
├── hooks/                # useCurrentContext, useAuth, usePermissions
├── pages/                # Страницы по роутам
├── stores/               # Zustand (context, auth, preferences)
└── lib/                  # Утилиты (formatMoney, formatDate)
```

---

## 2. Аутентификация

Laravel Sanctum SPA authentication (cookie-based, same-domain).

- `GET /sanctum/csrf-cookie` → CSRF-токен
- `POST /login` → создание сессии
- `POST /logout` → завершение сессии
- `GET /api/v1/user` → текущий пользователь + роль + доступные организации

Поддержка 2FA через существующий Fortify flow.

При 401/403 — редирект на `/login`.

---

## 3. Мультитенантность

### Иерархия

```
Organization
  └─ Merchant Account
       └─ Business Profile
            └─ Connectors, Routing Rules
```

### Контекстный переключатель в Header

```
[Org: Acme Corp ▾]  [Merchant: Shop #1 ▾]  [Profile: Default ▾]  [🟡 Test ⇄ 🟢 Live]
```

Три каскадных dropdown. Выбор организации загружает её мерчантов, выбор мерчанта — его профили.

**Хранение:** Zustand store + localStorage (persist между сессиями).

**Поведение:**
- При логине — загрузка списка организаций. Если одна — автовыбор
- Мерчант-пользователь не видит picker'ы (один контекст)
- Админ видит все три уровня
- Переключение контекста инвалидирует кэш TanStack Query
- Все API-запросы скоупятся к выбранному мерчанту

**Test/Live toggle:**
- Влияет на фильтрацию данных по `test_mode`
- Live-режим — красная индикация, confirm при переключении
- Тестовые данные помечены фиолетовым badge

---

## 4. Layout

### Header Bar

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ [Logo]  │  [Org ▾] [Merchant ▾] [Profile ▾]  │  [⌘K Поиск]  │  [🔔]  │  [🟡 Test ⇄ Live]  │  [👤 User ▾]  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

**Элементы:**
- **Логотип** — Payswitch, ссылка на главную
- **Контекстный переключатель** — три каскадных dropdown (см. раздел 3)
- **Глобальный поиск (⌘K)** — Command Palette (см. раздел 4.2)
- **Notification Center (🔔)** — колокольчик с badge count (см. раздел 4.3)
- **Test/Live toggle** — переключатель окружения
- **Меню пользователя** — имя, email, настройки, внешний вид, таймзона, выход

### Sidebar

```
── Операции ──
   📊 Обзор                /overview
   💳 Платежи              /payments
   ↩️  Возвраты            /refunds
   ⚠️  Споры               /disputes          [badge: кол-во открытых]
   👥 Клиенты              /customers

── Конфигурация ──
   🔌 Коннекторы           /connectors
   🔀 Маршрутизация        /routing
   📡 Вебхуки              /webhooks           [badge: failed deliveries]

── Разработка ──
   🔑 API-ключи            /api-keys
   🧪 Тестовый платёж      /test-payment
   📋 Логи событий         /event-logs

── Управление (админ) ──
   🏢 Организации          /organizations
   🏪 Мерчанты             /merchants
   📁 Бизнес-профили       /profiles
   👤 Пользователи         /users
   📜 Аудит-лог            /audit-log

── Аккаунт ──
   ⚙️  Настройки           /settings
```

**Поведение:**
- Сворачивается в icon-only
- На мобильных — выдвижная панель (sheet)
- Активный пункт подсвечен
- Счётчики (badges) на Споры и Вебхуки
- Секция "Управление" видна только админам

### Breadcrumbs

Все вложенные страницы показывают breadcrumbs под header bar:

```
Платежи → pay_abc123
Платежи → pay_abc123 → Возврат
Клиенты → cust_xyz789
Мерчанты → merchant_abc → Коннекторы
```

- Каждый сегмент — кликабельная ссылка (кроме последнего)
- Последний сегмент — текущая страница (не кликабельный, bold)
- Навигация "назад" через breadcrumbs, не кнопка браузера

### 4.2 Глобальный поиск (Command Palette)

**Хоткей:** `⌘K` (macOS) / `Ctrl+K` (Windows/Linux)
**Триггер:** также иконка поиска в header bar

**Поведение:**
- Оверлей с полем ввода (как в GitHub, Linear, Raycast)
- Поиск по ID любой сущности: `pay_*`, `ref_*`, `cust_*`, `merchant_*`, `mca_*`, `org_*`, `bp_*`, `rr_*`, `we_*`
- Поиск по имени: мерчанты, клиенты, организации, правила маршрутизации
- Поиск по email клиента
- Быстрые действия: "Создать платёж", "Подключить коннектор", "Создать клиента"
- Навигация: "Перейти к Платежам", "Перейти к Настройкам"
- Результаты группируются по типу: Платежи, Клиенты, Мерчанты, Действия, Навигация
- Клавиатурная навигация: ↑↓ выбор, Enter — открыть, Escape — закрыть
- Debounced поиск (300ms)
- Кэширование последних результатов

**API:** `GET /api/v1/search?q=...&types=payment,customer,merchant`

### 4.3 Notification Center

**Расположение:** иконка колокольчика в header bar с badge count.

**Типы уведомлений:**
- Неуспешные платежи (failed payments)
- Открытые споры / новые чарджбэки
- Неудачные доставки вебхуков (failed webhook deliveries)
- Коннектор стал недоступен (connector health alert)
- Отозванные или истекающие API-ключи
- Системные уведомления (maintenance, updates)

**UI:**
- Клик на колокольчик — dropdown panel (не отдельная страница)
- Список уведомлений с timestamp, иконкой типа, кратким текстом
- Клик на уведомление — переход к соответствующей сущности
- Кнопка "Отметить всё как прочитанное"
- Непрочитанные — выделены фоном
- Максимум 50 последних, ссылка "Все уведомления" → `/notifications`

**Обновление:** WebSocket (preferred) или polling каждые 30 секунд.

**API:**
- `GET /api/v1/notifications` — список
- `POST /api/v1/notifications/mark-read` — пометить прочитанными
- `GET /api/v1/notifications/unread-count` — количество непрочитанных

---

## 5. Онбординг

**Роут:** `/onboarding`
**Условие:** показывается если у мерчанта нет ни одного коннектора.

### Визард

```
Шаг 1: Создать организацию (если нет)
  ↓
Шаг 2: Создать мерчанта
  ↓
Шаг 3: Подключить первый коннектор (визард)
  ↓
Шаг 4: Настроить маршрутизацию (можно пропустить)
  ↓
Шаг 5: Провести тестовый платёж
  ↓
✅ Готово → Dashboard
```

Прогресс-бар на каждом шаге. Шаги 4-5 можно пропустить.

### Чеклист-виджет на Overview

Показывается пока не все шаги выполнены:

- ✅ Организация создана
- ✅ Мерчант создан
- ⬜ Подключите коннектор
- ⬜ Проведите тестовый платёж

---

## 6. Страницы

### 6.1 Обзор (`/overview`)

Главный дашборд. Все данные в контексте текущего Org/Merchant/Profile.

**Фильтр периода:** Сегодня / 7 дней / 30 дней / Квартал / Произвольный (date range picker)

**Карточки метрик (верхний ряд):**

| Метрика | Значение | Дополнительно |
|---------|----------|--------------|
| Оборот | сумма | ↑12% vs прошлый период |
| Успешных платежей | число | конверсия % |
| Неуспешных | число | % от общего |
| Возвратов | сумма + число | |
| Средний чек | сумма | тренд |
| Споры | число открытых | |

**Графики (ряд 1):**
- Платежи по дням — линейный (успешные / неуспешные / всего)
- Оборот по дням — столбчатая

**Графики (ряд 2):**
- Воронка конверсии — created → confirmed → succeeded (визуальная воронка с % dropout на каждом шаге)
- Конверсия по коннекторам — bar chart (% успешных)
- Распределение по методам оплаты — donut chart
- Топ причин отказов — horizontal bar chart

**Таблица "Последние платежи":** 10 записей, ссылка "Все платежи →".

**Чеклист онбординга** — виджет (если не завершён).

---

### 6.2 Платежи (`/payments`)

#### Список

**Фильтры (сворачиваемая панель):**
- Статус — мульти-select
- Коннектор — select
- Валюта — select
- Сумма — от / до
- Дата — date range picker
- Capture method — select (automatic / manual)
- Поиск — по ID платежа

**Таблица:**

| Колонка | Тип | Сортировка |
|---------|-----|------------|
| ID | ссылка `pay_*` | — |
| Сумма | форматированная | да |
| Валюта | badge | — |
| Статус | цветной badge | да |
| Коннектор | текст + иконка | — |
| Клиент | ссылка или "—" | — |
| Capture method | badge | — |
| Попыток | число | — |
| Дата | relative + tooltip | да |

Пагинация: 20/50/100, кнопки + номера страниц.
Экспорт CSV с текущими фильтрами.

#### Детальная (`/payments/:paymentKey`)

**Шапка:** ID (крупно, copy) + статус badge + кнопки действий:
- **Capture** — если `requires_capture`. Диалог с суммой, partial capture
- **Cancel** — если статус позволяет. Confirm dialog
- **Refund** — если `succeeded`/`partially_captured`. Диалог: сумма + причина

**Информация (2 колонки):**

| Левая | Правая |
|-------|--------|
| Сумма / Валюта | Коннектор |
| Net amount | Клиент (ссылка) |
| Amount capturable | Return URL |
| Amount received | Описание |
| Capture method | Authentication type |
| Session expiry | Дата создания |
| Expires on | Cancellation reason |

**Секции:**
- **Попытки оплаты** — таблица: #, коннектор, статус, сумма, transaction_id (mono+copy), ошибка, дата
- **Возвраты** — таблица: ID (ссылка), сумма, статус, причина, коннектор, refund_id, дата
- **Timeline** — хронология: создан → подтверждён → попытка #1 (failed) → попытка #2 (succeeded) → captured. Вертикальная timeline
- **Метаданные** — JSON-viewer (collapsible), скрыта если пустой
- **Ошибка** — alert с error_code + error_message, скрыта если нет ошибки

---

### 6.3 Возвраты (`/refunds`)

**Таблица:**

| Колонка | Тип |
|---------|-----|
| ID | `ref_*`, ссылка |
| Платёж | `pay_*`, ссылка |
| Сумма | форматированная |
| Валюта | badge |
| Статус | badge |
| Причина | текст или "—" |
| Коннектор | текст |
| Дата | relative |

**Фильтры:** статус, дата от/до, поиск по ID.

---

### 6.4 Споры (`/disputes`)

> Требует доработки бэкенда: модель Dispute, API-эндпоинты.

**Таблица:**

| Колонка | Тип |
|---------|-----|
| ID | `dispute_*` |
| Платёж | `pay_*`, ссылка |
| Сумма | форматированная |
| Тип | badge (chargeback, inquiry, retrieval) |
| Статус | badge (open, won, lost, under_review) |
| Причина | текст |
| Дедлайн ответа | дата (красный если < 3 дней) |
| Коннектор | текст |
| Дата | relative |

**Детальная (`/disputes/:disputeKey`):**
- Информация о споре + связанный платёж
- Загрузка доказательств: файлы + текст
- Timeline: opened → evidence submitted → resolved

---

### 6.5 Клиенты (`/customers`)

#### Список

**Таблица:** ID, имя, email, телефон, кол-во методов, кол-во платежей, дата.
**Фильтры:** поиск по имени / email / ID.
**Кнопка "Создать клиента"** — диалог: name, email, phone, description.

#### Детальная (`/customers/:customerKey`)

**Шапка:** ID + имя, кнопки "Редактировать" / "Удалить" (confirm).
**Информация:** email, телефон, описание, метаданные (JSON), дата.

**Секция "Способы оплаты":**
Таблица: ID (`pm_*`), тип, бренд (иконка), last4, срок, по умолчанию (badge), коннектор.
Действия: "Сделать по умолчанию", "Удалить".

**Секция "Платежи клиента":**
Таблица платежей с фильтром по customer_id (переиспользовать PaymentsTable).

---

### 6.6 Коннекторы (`/connectors`)

#### Список — карточная сетка

```
┌───────────────────────────────┐
│  [Stripe Logo]  🟢 Активен    │
│  Stripe                       │
│  mca_abc123...  [copy]        │
│                               │
│  Методы: 💳 Card, 🏦 Bank    │
│  Режим: 🟡 Test              │
│                               │
│  Health: 🟢 99.8% | 120ms    │
│                               │
│  [Настроить]  [⋮ Меню]       │
└───────────────────────────────┘
```

Меню (⋮): Редактировать, Включить/Отключить, Health Dashboard, Удалить.

#### Connector Health (встроено в карточку + детальная страница)

Каждая карточка коннектора показывает:
- **Статус:** 🟢 Healthy / 🟡 Degraded / 🔴 Down
- **Uptime:** % за последние 24 часа
- **Latency:** средняя за последний час (ms)

**Health Dashboard (`/connectors/:connectorKey/health`):**
- График latency за 24h / 7d / 30d
- График error rate за период
- Успешность по типам операций (authorize, capture, refund)
- Последние ошибки (таблица: время, операция, error_code, message)
- Сравнение с другими коннекторами (bar chart)

**API:**
- `GET /api/v1/connectors/{key}/health` — текущий статус + метрики
- `GET /api/v1/connectors/{key}/health/history?period=24h` — история

#### Визард подключения

```
Шаг 1: Выбор коннектора
  → Карточки: Stripe, CloudPayments, Test
  → Описание, поддерживаемые методы оплаты

Шаг 2: Реквизиты подключения
  → Типизированные поля по коннектору:
    Stripe: API Key, Webhook Secret
    CloudPayments: Public ID, API Secret
    Test: (ничего)
  → Кнопка "Проверить подключение"

Шаг 3: Методы оплаты
  → Multi-select: card, bank_account, ...
  → Toggle: тест-режим

Шаг 4: Привязка к профилю (optional)
  → Select бизнес-профиля или "Все профили"
```

#### Редактирование (`/connectors/:connectorKey`)

Полная страница с теми же полями, предзаполненными.

---

### 6.7 Маршрутизация (`/routing`)

#### Список правил

**Таблица:** название, тип (badge), приоритет, профиль, toggle активности, дата, действия.
Отсортированы по приоритету.

#### Редактор правил

**Priority:**
```
Порядок коннекторов (drag & drop):
  ≡ 1. Stripe        [×]
  ≡ 2. CloudPayments [×]
  ≡ 3. Test          [×]
  [+ Добавить]
```

**Rule-Based:**
```
Условия:
  IF [currency ▾] [== ▾] [USD]    → [Stripe ▾]      [×]
  IF [amount ▾]   [>= ▾] [100000] → [CloudPay ▾]    [×]
  [+ Добавить условие]

  По умолчанию: [Stripe ▾]
```

**Volume Split:**
```
Распределение трафика:
  Stripe        [═══████████════] 60%
  CloudPayments [═══████════════] 40%
  [+ Добавить]

  ████████████████████████████████
  ^^^^ Stripe 60%  ^^^^ CloudPay 40%
```

Слайдеры + визуальная полоска. Сумма = 100%.

---

### 6.8 Вебхуки (`/webhooks`)

**Таблица:**

| Колонка | Тип |
|---------|-----|
| ID | `we_*` |
| Тип события | badge |
| Платёж | ссылка |
| Доставлен | badge (✅ / ❌ / 🔄) |
| Попыток | число |
| Последняя ошибка | текст (truncated) |
| Дата | relative |

**Фильтры:** тип события, статус доставки, дата.

**Expandable row:**
- Полный payload (JSON-viewer)
- HTTP-статусы каждой попытки
- Кнопка "Отправить повторно"

---

### 6.9 API-ключи (`/api-keys`)

**Таблица:** название, префикс (monospace), истекает, статус (badge), дата.

**Создание** — диалог: название (optional).
**После создания** — модалка с raw key (показывается один раз):

```
┌─────────────────────────────────────┐
│  ⚠️  Сохраните ваш API-ключ        │
│                                     │
│  sk_test_abc123def456ghi789...      │
│  [📋 Копировать]                    │
│                                     │
│  Этот ключ показывается один раз.   │
│  Сохраните его в безопасном месте.  │
│                                     │
│  [Я сохранил ключ, закрыть]        │
└─────────────────────────────────────┘
```

**Отзыв** — красная кнопка, confirm dialog.

---

### 6.10 Тестовый платёж (`/test-payment`)

**Поля:** сумма (default 10000), валюта (default RUB), метод оплаты, тестовые данные карты (предзаполнены), capture method toggle.

**Preset-сценарии:**
- ✅ Успешный платёж
- ❌ Отклонённый (карта 4000000000000002)
- 🔄 Платёж с 3DS (карта 4000000000003220)

**Результат:** карточка со статусом, ID (ссылка), суммой, коннектором. Кнопки: "Ещё один", "Детали".

---

### 6.11 Логи событий (`/event-logs`)

Timeline всех событий: webhook events + payment state changes.

**Таблица:** время, тип (badge), ресурс (ссылка), описание, коннектор.
**Фильтры:** тип события, ресурс, дата, поиск по ID.

---

### 6.12 Организации (`/organizations`)

> Только для админов.

**Таблица:** ID (`org_*`), название, кол-во мерчантов, дата.
**Создание** — диалог: название.
**Детальная:** информация + список мерчантов организации.

---

### 6.13 Мерчанты (`/merchants`)

> Только для админов.

**Таблица:** ID, название, publishable key (mono+copy), кол-во профилей, кол-во коннекторов, дата.
**Создание** — диалог: название.

**Детальная (`/merchants/:merchantKey`):**
**Шапка:** ID, название, publishable_key (copy).

**Табы:**
- Обзор — основная информация, организация, дата
- Бизнес-профили — список + создание
- API-ключи — список с управлением
- Коннекторы — карточки коннекторов
- Маршрутизация — правила

---

### 6.14 Бизнес-профили (`/profiles`)

**Таблица:** ID (`bp_*`), webhook URL, кол-во коннекторов, кол-во правил, дата.
**Создание** — диалог: webhook_url (optional).

**Детальная:**
- Редактирование webhook_url
- Привязанные коннекторы
- Привязанные правила маршрутизации

---

### 6.15 Пользователи (`/users`)

> Требует доработки бэкенда: роли, приглашения.

**Таблица:** имя, email, роль (badge), 2FA, последний вход, статус.
**Приглашение** — диалог: email, роль.

**Роли:**
- **Admin** — полный доступ, управление пользователями
- **Operator** — CRUD платежей, возвратов, клиентов. Без доступа к настройкам коннекторов и ключам
- **Viewer** — только чтение

---

### 6.16 Аудит-лог (`/audit-log`)

> Данные из spatie/laravel-activitylog.

**Таблица:** время, пользователь, действие, ресурс (ссылка), изменения (expandable diff), IP.
**Фильтры:** пользователь, действие, тип ресурса, дата.

---

### 6.17 Настройки (`/settings`)

**Табы:**
- **Профиль** — имя, email, аватар
- **Безопасность** — смена пароля, 2FA (вкл/откл), recovery codes
- **Внешний вид** — тема (light/dark/system), data density (compact/comfortable/spacious)
- **Региональные** — таймзона (select из IANA list, default: Europe/Moscow), формат даты, формат чисел, базовая валюта для аналитики
- **Уведомления** — email per event type, настройка каналов (email, in-app, webhook)

### 6.18 Уведомления (`/notifications`)

Полная страница всех уведомлений (расширение Notification Center из хедера).

**Таблица:**

| Колонка | Тип |
|---------|-----|
| Тип | иконка + badge |
| Текст | описание события |
| Ресурс | ссылка на сущность |
| Время | relative |
| Статус | прочитано / непрочитано |

**Фильтры:** тип, статус (прочитано/нет), дата.
**Bulk actions:** "Отметить все как прочитанные", "Удалить прочитанные".

### 6.19 Saved Filters (Сохранённые фильтры)

На каждой странице с таблицей:
- Кнопка "Сохранить фильтр" рядом с панелью фильтров
- Диалог: название фильтра
- Dropdown "Мои фильтры" для быстрого применения
- Управление: переименовать, удалить

**Хранение:** localStorage + опционально серверная синхронизация (`GET/POST /api/v1/user/saved-filters`).

**Предустановленные фильтры (системные):**
- Платежи: "Неуспешные за сегодня", "Требуют capture", "Большие суммы (>100k)"
- Возвраты: "На ревью", "Неуспешные"
- Вебхуки: "Недоставленные"

---

## 7. UI-компоненты

### Статус-бейджи

| Статус | Цвет |
|--------|------|
| succeeded | зелёный |
| failed | красный |
| cancelled / expired | серый |
| processing | синий |
| pending | жёлтый |
| requires_* / manual_review | оранжевый |
| active / enabled | зелёный |
| disabled / revoked | красный |
| test | фиолетовый |
| live | красный |

### DataTable

TanStack Table:
- Серверная сортировка (query params)
- Серверная пагинация (offset, 20/50/100)
- Фильтры (сворачиваемая панель сверху)
- **Saved Filters** — сохранение/загрузка наборов фильтров (см. 6.19)
- Empty state: иконка + текст + CTA
- Loading: skeleton
- **Sticky headers** — при скролле заголовки таблицы остаются видимыми (`position: sticky`)
- Bulk selection (checkboxes)
- **Bulk actions toolbar** — появляется при выборе записей: "Выбрано N записей" + кнопки действий
- **Data density** — compact/comfortable/spacious (из настроек пользователя, переключается через dropdown в таблице)
- Мобильный: карточки вместо строк
- **Экспорт CSV** — кнопка на каждой таблице, экспортирует с текущими фильтрами

**Bulk actions по таблицам:**

| Таблица | Доступные bulk actions |
|---------|----------------------|
| Платежи | Export CSV, Bulk Refund (для succeeded) |
| Возвраты | Export CSV |
| Клиенты | Export CSV, Bulk Delete |
| Вебхуки | Export CSV, Bulk Retry (для failed) |
| Коннекторы | Bulk Enable/Disable |
| Routing Rules | Bulk Activate/Deactivate |
| API-ключи | Bulk Revoke |
| Аудит-лог | Export CSV |
| Уведомления | Mark as Read, Delete |

### Формы

- React Hook Form + Zod + серверные ошибки из API
- Submit с loading (disabled + spinner)
- Диалоги: Escape + overlay click
- Toast (Sonner) после действий
- Inline-ошибки под полями

### Подтверждение опасных действий

- Confirm dialog: "Это действие необратимо"
- Критичные (удаление мерчанта, отзыв ключа): ввести название для подтверждения
- Красная кнопка + loading

### Копирование

- Иконка рядом с ключами, ID, URL
- Tooltip "Скопировано!" 2 сек
- Monospace для всех ключей и ID

### JSON-viewer

- Collapsible дерево для metadata, webhook payload
- Подсветка синтаксиса
- Кнопка "Копировать JSON"
- Max-height с прокруткой

### Форматирование

- **Суммы:** минорные → основные: `12345` → `123,45 ₽`. Пробел-разделитель тысяч. Используем `Intl.NumberFormat` с locale из настроек
- **Многовалютность:** символ валюты из ISO 4217. Аналитика агрегирует в базовую валюту мерчанта (настройка в профиле). Конвертация на бэкенде
- **Даты:** относительные ("5 мин. назад") + абсолютные в tooltip. **Все даты отображаются в таймзоне пользователя** (из настроек). API всегда возвращает UTC, фронт конвертирует. Используем `date-fns` или `dayjs` с timezone support
- **Ключи/ID:** monospace, copy, truncate + tooltip

### Keyboard Shortcuts

Глобальные хоткеи:

| Хоткей | Действие |
|--------|----------|
| `⌘K` / `Ctrl+K` | Открыть Command Palette (глобальный поиск) |
| `⌘/` / `Ctrl+/` | Показать список хоткеев (справка) |
| `g p` | Перейти к Платежам |
| `g r` | Перейти к Возвратам |
| `g c` | Перейти к Клиентам |
| `g o` | Перейти к Обзору |
| `g s` | Перейти к Настройкам |
| `Escape` | Закрыть диалог / dropdown / Command Palette |

В таблицах:
| `↑` / `↓` | Навигация по строкам |
| `Enter` | Открыть выбранную запись |
| `Space` | Выбрать/снять выбор строки (checkbox) |
| `⌘A` | Выбрать все записи на странице |

Хоткеи отключаются когда фокус в input/textarea.

### Empty States

Каждая страница: иконка + заголовок + подсказка + CTA-кнопка.

### Error States

- Ошибка загрузки: иконка + текст + "Повторить"
- 401/403: редирект на логин
- 404: "Не найдено"
- 500: текст ошибки

---

## 8. Бэкенд-доработки

### Существующий API (готов)

- Organizations: create, show
- Merchants: create, show
- Business Profiles: create, show
- API Keys: create, revoke
- Connectors: CRUD
- Routing Rules: CRUD
- Payments: create, show, confirm, capture, cancel
- Refunds: create, show
- Customers: CRUD
- Payment Methods: CRUD
- Webhooks: receiver

### Нужно добавить

#### Auth & User
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/auth/user` | Текущий пользователь + роль + доступные организации + таймзона + preferences |
| `POST /api/v1/auth/login` | Sanctum SPA auth |
| `POST /api/v1/auth/logout` | Завершение сессии |
| `PATCH /api/v1/auth/user/preferences` | Обновить таймзону, data density, base currency, locale |

#### Мультитенантность
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/organizations` | Список организаций |
| `GET /api/v1/organizations/{orgKey}/merchants` | Мерчанты организации (для каскадного picker'а) |
| `GET /api/v1/merchants/{key}/profiles` | Список профилей мерчанта |
| `PATCH /api/v1/profiles/{key}` | Обновление профиля |

#### Списки с фильтрацией
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/payments` + фильтры | status, connector, date, amount, currency, capture_method, customer_id |
| `GET /api/v1/refunds` + фильтры | status, date, payment_id |
| `GET /api/v1/merchants/{key}/api-keys` | Список API-ключей мерчанта |
| `GET /api/v1/webhook-events` | Лог вебхуков с пагинацией и фильтрами |

#### Действия
| Эндпоинт | Зачем |
|-----------|-------|
| `POST /api/v1/webhook-events/{id}/retry` | Ручной retry вебхука |
| `POST /api/v1/webhook-events/bulk-retry` | Bulk retry неудавшихся |
| `POST /api/v1/payments/bulk-refund` | Bulk refund для списка payment_ids |
| `POST /api/v1/test-payments` | Создать + подтвердить в одном запросе |
| `GET /api/v1/payments/export?format=csv` | Экспорт с текущими фильтрами |
| `GET /api/v1/refunds/export?format=csv` | Экспорт возвратов |
| `GET /api/v1/customers/export?format=csv` | Экспорт клиентов |

#### Аналитика
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/analytics/overview` | Агрегированные метрики (считать на бэке) |
| `GET /api/v1/analytics/charts` | Данные для графиков (по дням, по коннекторам) |
| `GET /api/v1/analytics/funnel` | Воронка конверсии: created → confirmed → succeeded |
| `GET /api/v1/analytics/payment-methods` | Распределение по методам оплаты (для donut chart) |
| `GET /api/v1/analytics/failure-reasons` | Топ причин отказов |

#### Connector Health
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/connectors/{key}/health` | Текущий статус: uptime %, latency avg, error rate |
| `GET /api/v1/connectors/{key}/health/history` | История метрик за период (24h/7d/30d) |

#### Поиск
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/search?q=...&types=payment,customer,...` | Глобальный поиск для Command Palette |

#### Уведомления
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/notifications` | Список уведомлений с пагинацией |
| `GET /api/v1/notifications/unread-count` | Количество непрочитанных (для badge) |
| `POST /api/v1/notifications/mark-read` | Пометить как прочитанные (bulk) |
| `DELETE /api/v1/notifications/{id}` | Удалить уведомление |

#### Логи и аудит
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/audit-log` | Лог действий (spatie activity_log) |
| `GET /api/v1/audit-log/export?format=csv` | Экспорт аудит-лога |
| `GET /api/v1/event-logs` | Объединённый лог: webhooks + status changes |

#### Saved Filters
| Эндпоинт | Зачем |
|-----------|-------|
| `GET /api/v1/user/saved-filters` | Получить сохранённые фильтры пользователя |
| `POST /api/v1/user/saved-filters` | Сохранить набор фильтров |
| `DELETE /api/v1/user/saved-filters/{id}` | Удалить сохранённый фильтр |

### Бэкенд — отложено (фазы 3-4)

| Что | Зачем |
|-----|-------|
| Disputes: модель + миграции + CRUD API | Раздел "Споры" |
| Users/Roles: модель + RBAC + приглашения API | Раздел "Пользователи" |
| Notifications: модель + генерация + доставка (email, in-app, WebSocket) | Notification Center + Настройки |
| Connector Health: сбор метрик (latency, errors) из PaymentAttempts | Health Dashboard |
| Search: полнотекстовый поиск (Scout + Meilisearch или Elasticsearch) | Command Palette |
| User Preferences: модель + миграция (timezone, locale, density, base_currency) | Настройки |
| Saved Filters: модель + миграция | Сохранённые фильтры |
| Export Jobs: очередь для больших экспортов (>10k записей) | CSV Export |

---

## 9. Фазы реализации

### Фаза 1 — Каркас + Core

1. Инициализация SPA: Vite, роутинг, API-клиент, auth (Sanctum)
2. Layout: header (контекстный переключатель + Test/Live + ⌘K поиск), sidebar с breadcrumbs
3. Глобальный поиск (Command Palette ⌘K)
4. Keyboard shortcuts (глобальные + таблицы)
5. Таймзоны (настройка + отображение всех дат в таймзоне пользователя)
6. Обзор — метрики, графики, воронка конверсии, последние платежи
7. Платежи — список с фильтрами + детальная + capture/cancel/refund + timeline
8. Возвраты — список с фильтрами
9. DataTable (sticky headers, пагинация, сортировка, экспорт CSV, empty/loading/error states)

### Фаза 2 — Операции + Конфигурация

10. Клиенты — список + детальная + способы оплаты
11. Коннекторы — карточки + визард подключения + редактирование + health индикатор в карточке
12. Маршрутизация — список + визуальный редактор (priority, rule-based, volume split)
13. API-ключи — список + создание (show-once modal) + отзыв
14. Вебхуки — список + expandable row + retry + bulk retry
15. Notification Center (колокольчик в хедере + dropdown panel)
16. Bulk actions на всех таблицах (refund, retry, export, enable/disable)
17. Data density (compact/comfortable/spacious)
18. Экспорт CSV на всех таблицах

### Фаза 3 — Управление + Продвинутые фичи

19. Организации / Мерчанты / Профили — CRUD-страницы
20. Тестовый платёж — форма + пресеты (success, decline, 3DS)
21. Онбординг — визард (5 шагов) + чеклист-виджет на Overview
22. Логи событий — timeline с фильтрами
23. Saved Filters — сохранение/загрузка наборов фильтров + preset'ы
24. Connector Health Dashboard — latency, error rate, uptime графики
25. Страница уведомлений (`/notifications`)
26. Многовалютность в аналитике — базовая валюта + конвертация

### Фаза 4 — Enterprise

27. Споры (Disputes) — список + детальная + evidence upload + timeline
28. Пользователи и роли — RBAC (admin/operator/viewer) + приглашения
29. Аудит-лог — лог действий + expandable diff + экспорт
30. Расширенная аналитика — доп. графики, экспорт отчётов
31. Настройки уведомлений — per event type, каналы (email, in-app)
32. Региональные настройки — locale, формат даты/чисел

---

## 10. Нефункциональные требования

- **Desktop-first:** корректное отображение от 1024px. Мобильная (>375px) — sidebar в sheet, таблицы в карточки
- **Производительность:** серверная пагинация/фильтрация, TanStack Query кэш + дедупликация, lazy loading страниц (code splitting по роутам)
- **Доступность:** keyboard navigation, aria-labels, focus-visible, screen reader
- **Состояния:** loading (skeleton), empty state, error state — на каждой странице и каждом блоке данных
- **Темизация:** light/dark/system, все компоненты корректны в обеих темах
- **Безопасность:** XSS (React default), CSRF (Sanctum), секреты не в localStorage
- **i18n-ready:** тексты через файлы локализации, готовность к английскому языку
- **Таймзоны:** API возвращает UTC, фронт конвертирует в таймзону пользователя. `date-fns-tz` или `dayjs/timezone`
- **Многовалютность:** `Intl.NumberFormat`, символы валют из ISO 4217, агрегация в base currency на бэкенде
- **Data density:** 3 режима отображения таблиц (compact/comfortable/spacious), сохраняется в preferences

---

## 11. Тестирование фронтенда

### Unit-тесты (Vitest)

- Утилиты: `formatMoney`, `formatDate`, `convertTimezone`, `parseJsonApi`
- Zustand stores: context switcher state, auth state, preferences
- Hooks: `useCurrentContext`, `usePermissions`, `useKeyboardShortcut`
- Компоненты: StatusBadge, MoneyFormat, CopyButton, DataTable (рендеринг, сортировка, пагинация)

### Integration-тесты (Vitest + Testing Library)

- Формы: валидация (Zod), submit, серверные ошибки
- Command Palette: поиск, навигация, клавиатура
- Context Switcher: каскадный выбор, инвалидация кэша
- DataTable: фильтры, сортировка, bulk selection

### E2E-тесты (Playwright)

- **Auth flow:** логин → 2FA → dashboard
- **Платёжный flow:** список → фильтры → детальная → capture → refund
- **Настройка:** создание коннектора (визард) → создание правила маршрутизации → тестовый платёж
- **Мультитенантность:** переключение org → merchant → profile, проверка фильтрации данных
- **Test/Live переключение:** confirm dialog, фильтрация данных
- **Онбординг:** полный визард от создания org до тестового платежа
- **Responsive:** тесты на мобильном viewport (sidebar → sheet, таблицы → карточки)

### Storybook (опционально)

Каталог UI-компонентов:
- Все компоненты из `components/ui/` с вариантами (sizes, states, themes)
- StatusBadge со всеми статусами
- DataTable с моковыми данными
- Формы со всеми состояниями (empty, filled, error, loading)
- Command Palette
- Context Switcher
