# Payswitch — платёжный шлюз

Боевой `/var/www/psapi.gnzs.pro`. Наружу — `https://psapi.gnzs.pro` (API) и
`https://payswitch.gnzs.pro` (панель, статика из `psapi.gnzs.pro/dashboard/dist`).

## Это не payments-service

В старом аудите владельца фигурирует `payments-service`. **Это другой
репозиторий и другой код**, и он нигде не развёрнут. Там модели `Payment`,
`GatewayConfig`, `MerchantConfig`, `WebhookLog` — плоская обёртка над одним
шлюзом.

Payswitch — самостоятельный маршрутизирующий шлюз со своей доменной моделью:

| Модель | Зачем |
|---|---|
| `PaymentIntent` | Намерение оплаты: сумма, валюта, статус, выбранный коннектор |
| `PaymentAttempt` | Попытка по намерению — их может быть несколько |
| `MerchantConnectorAccount` | Подключение мерчанта к конкретному PSP, с кредами |
| `RoutingRule` | Правило выбора коннектора |
| `WebhookEvent` | Исходящее уведомление мерчанту, с доставкой и повторами |

Плюс `ApiKey`, `MerchantAccount`, `BusinessProfile`, `Organization`, `Customer`,
`PaymentMethod`, `Refund`, `PaymentAuditLog`. Всё в
`packages/streeboga/payment-data/src/Models/`.

**Выводы аудита про payments-service к этому репозиторию не относятся.**

## Коннекторы

`ConnectorInterface` — `packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php:11`,
13 методов: `createPaymentSession`, `verifyWebhookSignature`, `testConnection`
и прочее.

**Классов-драйверов восемь**, из них семь настоящих PSP плюс `TestConnector`:
`CloudPaymentsConnector`, `RbsConnector`, `RobokassaConnector`, `StripeConnector`,
`TBankConnector`, `TochkaConnector`, `YooKassaConnector`, `TestConnector`.

Имён в реестре десять — два раза по два имени делят класс
(`config/payswitch.php:29`):

```php
'sberbank' => RbsConnector::class,
'alfabank' => RbsConnector::class,
'test'     => TestConnector::class,
'test_sbp' => TestConnector::class,
```

Так что «девять коннекторов» и «восемь драйверов» — про разное. `ConnectorName`
(`app/Enums/ConnectorName.php:13`) держит все десять имён.

Креды подключения лежат в `MerchantConnectorAccount.connector_account_details`
под `encrypted:array` (`MerchantConnectorAccount.php:41`) — Laravel Crypt,
`AES-256-CBC` (`config/app.php:100`) на `APP_KEY`. Расшифровываются только в
`ConnectorFactory::resolve()` (`ConnectorFactory.php:63`).

## Состояния платежа

12 статусов, `packages/streeboga/payment-data/src/Enums/PaymentStatus.php:13`:
`RequiresPaymentMethod`, `RequiresConfirmation`, `RequiresCustomerAction`,
`RequiresMerchantAction`, `Processing`, `RequiresCapture`, `Succeeded`,
`Failed`, `Cancelled`, `Expired`, `PartiallyCaptured`,
`PartiallyCapturedAndCapturable`.

Переходы — статическая таблица в
`packages/streeboga/payment-data/src/StateMachine/PaymentStateMachine.php:13`,
`canTransition()` / `assertTransition()`. `succeeded`, `failed`, `cancelled`,
`expired`, `partially_captured` — конечные.

**Провайдеру можно больше.** `canConfirmByProvider()` (`:46`) пускает
подтверждение списания из `expired`/`failed` в `succeeded` или
`requires_capture`, а из `cancelled` — в `requires_merchant_action`. Списание у
PSP — факт, а не наше решение: 3DS или СБП дольше 15 минут срока платежа, и
отбросить такой Pay значит молча потерять деньги плательщика. Зовётся только из
приёмника вебхуков.

`requires_merchant_action` — «деньги пришли не те»: сумма или валюта в Pay не
совпали с намерением (`error_code` `amount_mismatch`/`currency_mismatch`), или
оплатили отменённый платёж. Разбирает человек.

`amount_received` — сумма из уведомления провайдера в минорных единицах, а не
копия `amount`. Сверяется только верхний уровень (`Amount`, `amount`,
`OutSum`): у Stripe и YooKassa сумма глубже, для них это пока копия.

## Подпись вебхуков от PSP

`WebhookReceiverService.php:65` проверяет подпись **до** обработки и на отказ
отдаёт 401; `processWebhook()` — на `:83`. Всё, что брошено при обработке,
включая `TypeError`, ловится как `\Throwable` и отдаётся провайдеру его кодом
отказа.

Что именно проверяет каждый драйвер (`packages/streeboga/payment-connectors/src/Drivers/`):

| Коннектор | Строка | Что на самом деле |
|---|---|---|
| Stripe | `StripeConnector.php:84` | HMAC-SHA256 по `t.payload`, окно повтора 300 с, `hash_equals` |
| CloudPayments | `CloudPaymentsConnector.php:112` | HMAC-SHA256 base64 по сырому телу против `content-hmac`. Без заголовка — только при `PAYSWITCH_ALLOW_UNSIGNED_WEBHOOKS=true` (на проде не задан) |
| TBank | `TBankConnector.php:204` | **Не HMAC.** Токен: SHA-256 от склеенных отсортированных полей с паролем |
| Robokassa | `RobokassaConnector.php:136` | **MD5**, не HMAC: `md5("{$outSum}:{$invId}:{$password2}{$shp}")` |
| Rbs | `RbsConnector.php:227` | HMAC `checksum` по `callback_secret` |
| Tochka | `TochkaConnector.php:207` | JWT RS256, `alg` зашит |
| **YooKassa** | `YooKassaConnector.php:137` | Подписи нет у провайдера — IP-allowlist по `$request->ip()` |
| **Test** | `TestConnector.php:98` | `return true` |

**Коннектор из URL двигает только свои платежи.** Платёж, у которого записан
другой `connector`, — `unacceptable`; возврат ищется только у мерчанта из URL.
Иначе неподписанный `test` мерчанта проводил бы его CloudPayments-платежи.

**CloudPayments: что за уведомление, решает драйвер**
(`webhookEventType`). Check — `Completed` или `Authorized` без `AuthCode`: код
13, если платёж уже нельзя оплатить, 20 для `expired`, 12 при несовпадении
суммы или валюты. Fail — по `ReasonCode`. `OperationType=Refund` заводит
`Refund` в `succeeded`, если его у мерчанта нет, и шлёт `refund_succeeded`.
Pay и Fail пишут исход и `TransactionId` в попытку — без этого возврат через API
не находил попытку.

TBank получает телом `OK`, Robokassa — `OK{InvId}`; при отказе — JSON, чтобы
провайдер повторил.

## Симулятор `test-psp`

`routes/api.php:162`, без авторизации. Проводит **только** платежи тестового
коннектора (`TestPspController.php:123`), переход — через state machine под
блокировкой. Платёж боевого коннектора — 404. До этого любой, зная `pay_…`
(он в `client_secret` чекаута), переводил в `succeeded` любой платёж.

## Маршрутизация

`RoutingService::resolve()` перебирает правила по приоритету:

1. явно указанный коннектор (`:33`);
2. `evaluatePriorityRule` (`:120`);
3. автовыбор по способу оплаты (`:68`);
4. первый активный (`:82`).

`evaluatePriorityRule` берёт `$config['connectors']` — упорядоченный список имён
— и возвращает **первое имя, которое разрешается в невыключенное подключение
этого мерчанта** (`findActiveConnectorByMerchantAndName`). Не нашлось ни одного
— `null`, и `resolve()` падает в следующее правило. Работает.

## Изоляция арендаторов

Держится **только на `api_keys.merchant_account_id`**.
`app/Http/Middleware/ResolveApiKey.php:54`:

```php
$request->attributes->set('merchant_id', $apiKeyModel->merchant_account_id);
```

По этому `merchant_id` режутся платежи (`PaymentIntentQueryBuilder:27`),
возвраты (`RefundRepository:52`), коннекторы, клиенты, бизнес-профили, правила
маршрутизации, сами ключи. `publishable_key` лежит на `merchant_accounts`, то
есть один на мерчанта.

**У `business_profile` своего ключа нет.** В миграции `api_keys`
(`..._000004_create_api_keys_table.php:11`) есть только `merchant_account_id`.
У профиля есть собственный публичный идентификатор — `business_profiles.key`
с префиксом `pro_` (`..._000003:13`), но по нему никто не аутентифицируется.
Профиль — это разметка, а не граница. **Разделить проекты профилями нечем.**

**Тип ключа — из `api_keys.type`** (`ResolveApiKey.php`): `publishable`
остаётся publishable, `admin` из таблицы работает как secret мерчанта.
Глобальный admin API — только ключ из `PAYSWITCH_ADMIN_API_KEY`; в production
ключ короче 32 символов или равный тестовому значению не принимается. На проде
он был дословно `admin_test_key_for_development` из `.env.example` —
ротирован 15.09.2026.

**`key_prefix` не уникален.** У ULID-ключей одной миллисекунды первые 20
символов совпадают; ключ выбирается среди кандидатов по `hash_equals` хэша, а
отзыв и срок проверяются только после совпадения. Отсюда плавал
`TenantIsolationTest`.

**Идемпотентность — заголовок `Idempotency-Key`** на `POST /api/v1/payments` и
`POST /api/v1/refunds`, уникален в пределах мерчанта (индекс
`(merchant_account_id, idempotency_key)`). Повтор — 200 и
`Idempotent-Replayed: true`, к PSP не ходит; тот же ключ с другими параметрами —
422 `idempotency_key_reused`. Возврат с неизвестным исходом у PSP остаётся
`pending` и отдаёт 502 `refund_pending` с ключом в `errors[0].meta.refund_id` —
повторять с тем же ключом. Genesis и invoicing заголовок шлют.

Панель ходит другим путём: `ResolveMerchantContext.php:17` читает заголовок
`X-Merchant-Key` и проверяет `$user->hasAccessToMerchant()`. Маршруты панели
вне `resolve.merchant` (организации, список мерчантов, профили по мерчанту)
отдают только то, где у пользователя есть роль; роли меняет только admin
организации роли; ключ подписи профиля видит только admin мерчанта.

### Genesis

В этом репозитории про Genesis нет ни строчки — связка живёт на её стороне.
Там **проект Genesis соответствует отдельному `merchant_account` payswitch**:
драйвер раньше брал ключ мерчанта из `.env` и аргумент `$appId` игнорировал, так
что три проекта видели один список коннекторов, платежи всех ложились в
`merchant_account_id=1`, а `publishable_key` был один на всех
(`genesis-new`, `15f5c75`).

Ключи мерчанта лежат в `app_modules.settings['payswitch']` модуля `payments` на
стороне Genesis (`genesis-new/app/Models/AppModule.php:31`). Наружу они не
отдаются и настройками из панели не затираются: иначе секретный ключ уезжает в
панель, а потерянная привязка даёт новый мерчант и невидимые платежи.

## Списки платежей по ключу сервиса

`routes/api.php:221` — группа `['auth.api_key', 'auth.secret_api_key',
'throttle:payswitch-api']`:

| Маршрут | Контроллер |
|---|---|
| `GET /api/v1/payments` (`:222`) | `PaymentController::index`, `:54` |
| `GET /api/v1/refunds` (`:228`) | `RefundController::index`, `:45` |

Оба берут `merchant_id` **только** из атрибута запроса, который поставил
`ResolveApiKey`. `merchant_id` из query или тела игнорируется намеренно —
докблок `PaymentController.php:33`. `Gate` не зовётся: во всём дереве
`app/Http/Controllers/Api/` нет ни одного `Gate::` или `authorize()`, и
`PaymentListRequest`/`RefundListRequest` метода `authorize()` не определяют.
Единственная проверка — `AuthenticateSecretApiKey:15`, отсекающая ключи не того
типа с 403.

Дашбордный близнец устроен иначе и закрыт сессией браузера:
`DashboardPaymentController.php:51` зовёт `Gate::authorize('payment.viewAny',
[$merchantId])`, а группа `routes/api.php:84` висит на
`['auth:sanctum', 'resolve.merchant', 'throttle:300,1']`. Sanctum в
cookie-режиме: `bootstrap/app.php:46` добавляет
`EnsureFrontendRequestsAreStateful`, `config/sanctum.php:40` — guard `web`,
токенного запасного пути нет.

## Виджет оплаты

`widget/` — пакет `@payswitch/js` на Preact, Vite в режиме библиотеки (ESM + UMD).

```bash
cd widget && npm install && npm run build
cd widget && npm run test
```

**В реестр не опубликован**: `widget/package.json:4` — `"private": true`, ни
`publishConfig`, ни `files`, ни `prepublish*`. `widget/dist/` в `.gitignore`, то
есть и в репозитории сборки нет.

Потребители вкладывают сборку к себе:

- панель держит его путевой зависимостью (`dashboard/package.json:62` —
  `"@payswitch/js": "file:../widget"`), сборку делает приёмник `ci-deploy payswitch`
  (сначала `widget`, потом `dashboard`);
- invoicing-service кладёт `payswitch.mjs` прямо в исходники чекаута
  (`resources/js/checkout/vendor/`).

**Документация мерчанту при этом врёт.** `docs/merchant/quickstart.md:66` и
`docs/merchant/widget.md:10` предлагают `npm install @payswitch/js`, а
`widget.md:20` — CDN `https://cdn.payswitch.example.com/...`. Ни то, ни другое
не существует.

## Грабли

**`page[number]` не работал нигде.** Номер страницы приходит по JSON:API как
`page[number]`, а Laravel по умолчанию читает скалярный `?page=`. Вторая
страница **молча отдавала первую** — во всех списках сразу. Сначала это чинили
по месту, в одном контроллере (`1c7ca15`, `EventLogController.php:40`), потом
один раз глобально — резолвер в `RepositoryServiceProvider.php:64` (`48846c8`).
Локальный хак в `EventLogController` с тех пор мёртвый и его стоит снять.

**Слушатели `PaymentStatusChanged` срабатывали дважды.** Laravel обнаруживает
слушателей в `app/Listeners` сам (`bootstrap/app.php:22` → `withEvents()`), а
`AppServiceProvider::boot()` регистрировал их ещё и явно. Мерчанту уходило по два
вебхука — на проде 24 платежа до 11.09. Починено `7970658`, явных
`Event::listen` нет; регрессия в `tests/Feature/Listeners/PaymentListenersTest.php`.

**Уведомление создаётся после коммита статуса — и может не создаться.**
`WebhookEvent` делает синхронный слушатель. Упал любой слушатель — статус уже
`succeeded`, события нет, повтор PSP не проходит. Так на проде 11.09 потеряны
уведомления платежей 117 и 118: удалённый `DepositToWallet` остался в старой
автозагрузке. Догоняет `ReconcileWebhookEventsJob` (`routes/console.php:14`,
каждые 5 минут): платежи в уведомляемом статусе старше 2 минут и моложе 7 дней
без события с этим `content->status`.

**Вебхук мерчанту не выбрасывается молча.** Нет адреса — `last_error` и
повтор по расписанию; кончились 16 попыток (~18 часов) — `Log::error` и
`failed_jobs`. Адрес — из профиля платежа, `business_profile_id` события
заполнен. Ключ подписи профиля хранится зашифрованным на `APP_KEY` — **`APP_KEY`
не менять**. В теле `created`/`updated` — время события, а не последней
попытки; `event_id` ещё и заголовком `x-webhook-event-id`.

**Истечение — 15 минут от создания платежа.** `CleanExpiredPaymentsJob` переводит
`requires_customer_action` в `expired` под блокировкой и сообщает мерчанту.
Поздний Pay всё равно проводится (см. «Состояния платежа»).

**`like` на sqlite регистронезависим, на Postgres — нет.** Поиск в панели был
регистрозависимым на проде при зелёных тестах. Только `whereLike`.

## Выкладка

Push в `main` → Woodpecker (`.woodpecker/deploy.yaml`) → приёмник `ci-deploy payswitch` на 85.198.101.184; вручную — `ci-deploy payswitch <sha>` от `deploy`.

README описывает `/var/www/payswitch` и `your-domain.com` (`README.md:201` и
далее) — отстал.

**php-fpm работает от `www-data`** (`README.md:175`, supervisor `user=www-data`
на `:285`, `chown -R www-data:www-data` на `:308`). Артизан руками:

```bash
env HOME=/tmp sudo -u www-data php artisan <команда>
```

Без `HOME=/tmp` composer и артизан спотыкаются о недоступный домашний каталог,
без `sudo -u www-data` кэш получает владельца root и php-fpm его не читает.

**`config:clear`, а не `optimize:clear`.** `CACHE_STORE=database`
(`.env.example:44`), `SESSION_DRIVER=database` (`:30`) — `optimize:clear`
вычищает ту же таблицу и разлогинивает всю панель.

**Зелёные тесты не значат рабочий Postgres.** Набор гоняется на sqlite в
памяти с `RefreshDatabase`; блокировки, типы и `like` там другие. До 15.09 на
Postgres не запускались 46 тестов панели — фикстуры писали дату в integer.
Перед выкладкой прогнать и там:

```bash
createdb payswitch_pg
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=payswitch_pg DB_USERNAME=$USER DB_PASSWORD= ./vendor/bin/pest
```

## Команды

```bash
composer setup            # зависимости, ключ, миграции, сборка
composer dev              # сервер + очередь + логи + vite
composer test             # линт + тесты
./vendor/bin/pest --filter=TestName
composer lint             # Pint
composer ci:check         # линт + формат + типы + тесты

cd dashboard && npm run build
cd dashboard && npm run lint / types:check / test / e2e
```

## Стек и раскладка

- PHP 8.4, Laravel 13, Pest. Node 22, React 19 (React Compiler), TypeScript 5.9,
  Vite 8, Tailwind 4, shadcn/ui.
- Sanctum SPA-cookie + свой 2FA на google2fa. Ни Inertia, ни Fortify.
- Zustand, TanStack Router, React Query + ky, react-hook-form + Zod, Recharts.
- Larastan/PHPStan strict, Deptrac, Pint (пресет laravel).
- Очередь — драйвер `database`.

Пакеты в `packages/`:

| Пакет | Что |
|---|---|
| `streeboga/payment-data` | Домен: модели, енумы, state machine, миграции, контракты |
| `streeboga/payment-connectors` | Драйверы PSP, фабрика по конфигу, 4 типа интеграции (redirect, form_redirect, widget, qr_inline) |
| `scramble` | Генератор документации API с поддержкой JSON:API v1.1 |

Приложение: 27 сервисов в `app/Services/`, 16 репозиториев, 10 query-билдеров в
`app/Builders/`, DTO на `spatie/laravel-data`, 20 политик, middleware
`AuthenticateAdminApiKey` / `AuthenticateSecretApiKey` / `AuthenticateClientSecret` /
`ResolveApiKey` / `ResolveMerchantContext` / `ForceJsonApiContentType`.

Панель — отдельное Vite-приложение в `dashboard/` (порт 3000, проксирует `/api`
и `/sanctum` на 8000), около 30 страниц, 80 компонентов, i18n ru/en.
Подробности — `dashboard/CLAUDE.md`.

Маршруты: `routes/web.php` — вход, выход, 2FA; `routes/api.php` — дашбордное
API под Sanctum, админское под admin-ключом, публичное под publishable key +
client_secret, мерчантское под секретным ключом, приёмник вебхуков, healthcheck.
