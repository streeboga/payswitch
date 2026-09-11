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
`canTransition()` / `assertTransition()`. В таблице 11 ключей: в
`requires_merchant_action` не ведёт ни один переход — статус объявлен, но
недостижим.

## Подпись вебхуков от PSP

`WebhookReceiverService.php:49` проверяет подпись **до** обработки и на отказ
отдаёт 401:

```php
if (! $connector->verifyWebhookSignature($request->getContent(), $headers)) {
    return ['status' => 'invalid_signature', 'code' => 401];
}
```

`processWebhook()` вызывается только на `:67`. Код проходит наружу как есть
(`WebhookReceiverController.php:34`); докблок этого контроллера на `:25` всё ещё
обещает «Always returns 200» — он устарел.

Что именно проверяет каждый драйвер:

| Коннектор | Файл | Что на самом деле |
|---|---|---|
| Stripe | `StripeConnector.php:82` | Настоящий HMAC-SHA256 по `t.payload`, окно повтора 300 с, `hash_equals` |
| CloudPayments | `CloudPaymentsConnector.php:103` | HMAC-SHA256 base64 по сырому телу против `content-hmac` |
| TBank | `TBankConnector.php:190` | **Не HMAC.** Токен: SHA-256 от склеенных отсортированных полей с паролем |
| Robokassa | `RobokassaConnector.php:125` | **MD5**, не HMAC: `md5("{$outSum}:{$invId}:{$password2}{$shp}")` |
| **Rbs** | `RbsConnector.php:205` | **Не проверяет.** `return true` — «callbacks are unreliable, polling» |
| **Tochka** | `TochkaConnector.php:180` | **Не проверяет.** `return true` — «For MVP» |
| **YooKassa** | `YooKassaConnector.php:94` | **Не проверяет** осознанно: у них IP-allowlist, подписи нет |

**Дыр три, а не одна.** YooKassa — единственная, где отказ от подписи
обоснован: провайдер её не выдаёт, защита строится на списке адресов. Rbs
(а значит `sberbank` и `alfabank`) и Tochka принимают любой POST на свой
адрес вебхука. Пока это закрыто только секретностью URL и TLS.

Отдельно: CloudPayments на отсутствующем заголовке возвращает
`! app()->environment('production')` (`:107`) — вне прода пропускает.

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

Панель ходит другим путём: `ResolveMerchantContext.php:17` читает заголовок
`X-Merchant-Key` и проверяет `$user->hasAccessToMerchant()` на `:34`.

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
  `"@payswitch/js": "file:../widget"`), а CI перед сборкой перетирает свежей
  сборкой: `rm -rf node_modules/@payswitch/js; cp -r ../widget node_modules/@payswitch/js`
  (`deploy.yml:32`);
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
слушателей в `app/Listeners` сам (`bootstrap/app.php:22` → `withEvents()`,
`$shouldDiscoverEvents = true`), а `AppServiceProvider::boot()` регистрировал те
же три класса ещё и явно через `Event::listen`. `LogPaymentAudit`,
`SendWebhookNotification` и `DepositToWallet` отрабатывали по два раза, в
`webhook_events` ложились две строки, **мерчанту уходило по два вебхука**.

В проде хуже: `deploy.yml:41` делает `php artisan event:cache` и замораживает
задвоение в кэше.

Правка лежит **в рабочем дереве и не закоммичена**: явные `Event::listen`
убраны, на их месте комментарий в `AppServiceProvider.php:50`, в
`tests/Feature/Listeners/PaymentListenersTest.php` добавлена регрессия на
`WebhookEvent::count() === 1`.

## Выкладка

`.github/workflows/deploy.yml` — пуш в `main`, `appleboy/ssh-action`, хост
`equity.su`, пользователь `deploy`, каталог `/var/www/psapi.gnzs.pro`. Тянет
`git pull`, ставит зависимости, собирает виджет и панель с
`VITE_BACKEND_URL=https://psapi.gnzs.pro`, мигрирует, делает `config:cache`,
`route:cache`, `view:cache`, `event:cache` и перезапускает
`payswitch-worker:*`.

**Workflow падает, выкатывают руками.** Команды из него годятся как справка,
запускать надо по ssh.

**Два разных боевых пути.** CI кладёт в `/var/www/psapi.gnzs.pro`, а README
описывает `/var/www/payswitch` с доменом `your-domain.com` и без сборки виджета
(`README.md:201` и далее). Правильный — путь из CI; README отстал.

**php-fpm работает от `www-data`** (`README.md:175`, supervisor `user=www-data`
на `:285`, `chown -R www-data:www-data` на `:308`). Артизан руками:

```bash
env HOME=/tmp sudo -u www-data php artisan <команда>
```

Без `HOME=/tmp` composer и артизан спотыкаются о недоступный домашний каталог,
без `sudo -u www-data` кэш получает владельца root и php-fpm его не читает.

**`config:clear`, а не `optimize:clear`.** `CACHE_STORE=database`
(`.env.example:44`), `SESSION_DRIVER=database` (`:30`) — `optimize:clear`
вычищает ту же таблицу и разлогинивает всю панель. В репозитории этого
предупреждения нигде нет, только `:cache`-варианты в workflow.

**Зелёные тесты не значат рабочий Postgres.** Набор гоняется на sqlite в
памяти с `RefreshDatabase`. Всё, что расходится между диалектами — блокировки,
частичные индексы, типы, сортировка — на sqlite зелёное. Перед тем как верить
прогону, повторить на Postgres. (В invoicing-service от этого отказались и
гоняют тесты прямо на Postgres — там без него не проверить advisory-локи.)

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
