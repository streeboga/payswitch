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

`amount_received` — сумма, названная провайдером, в минорных единицах, а не
копия `amount`. Читает её `App\Support\ProviderAmount::minor()`: верхний уровень
(`Amount`, `amount`, `OutSum`), `object.amount.value` (YooKassa) и
`data.object.amount` (Stripe), в единицах коннектора.

**Успех без суммы — не оплата** (06.10.2026). Правило одно на уведомление, `sync`
и синхронный ответ `purchase` (`ProviderAmount::paidStatus()`): сумма названа и
равна выставленной — `succeeded`; не названа — `processing` с `error_code`
`amount_unconfirmed` и событием `payment_status_changed`; названа другая —
`requires_merchant_action` / `amount_mismatch`. Из `processing` платёж выводит
уведомление с суммой, `POST /payments/{id}/sync` или опрос по расписанию (ниже). Успех без суммы по `expired`/`failed` уходит в
`requires_merchant_action`. Обратный вызов RBS суммы не несёт: такой платёж ждёт
опроса (Сбер и Альфа сейчас не подключаются — `PAYSWITCH_CONNECTABLE`). Холд
(`requires_capture`) не сверяется: денег он не взял. Тестовый коннектор сумму в
ответе называет; сторонний тестовый драйвер без `data.amount` даст `processing`.

**Опрос провайдера по расписанию** (06.10.2026) — `PollProviderStatusJob` раз в
минуту, `ProviderPollingService`. Платёж в `processing` или
`requires_customer_action` с транзакцией у провайдера спрашивается тем же
`PaymentService::sync()`, что и ручной `sync`: переходы и события обычные.
Интервал — `SCHEDULE`: опросы на 1, 3, 8, 23, 83 минуте от входа в статус, дальше
раз в 6 часов; состояние — `poll_attempts` и `next_poll_at`, обнуляется при смене
статуса (хук `updating` модели). После девятого опроса без итога (≈ 25 ч)
`processing` уходит в `requires_merchant_action` с `error_code`
`provider_unconfirmed` и событием `payment_status_changed`
(`requires_customer_action` раньше истекает по своему сроку; без срока — `expired`).
За прогон — до 50 платежей и 50 возвратов и 45 секунд; один платёж опрашивает один
процесс (`Cache::lock`). Тестовые коннекторы не опрашиваются: симулятор отвечает
«оплачено» на любой вопрос. Pending-возврат с id провайдера идёт тем же расписанием
через `RefundReconciliationService` (умеет только YooKassa); через сутки остаётся
`pending` с `Log::error` — конечного статуса и события нет намеренно: деньги могли
уйти. `sync` читает `data.status` ответа коннектора: у RBS его ставит драйвер из
`orderStatus`; **у T-Bank (`Status`) и Точки (вложенный `Data.Operation`) не
ставит никто — их `sync` и опрос ничего не меняют**, Robokassa статус не отдаёт.

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

**Захват и отмена — тоже по `Idempotency-Key`** (06.10.2026), но ключ уникален в
пределах платежа и лежит в `payment_actions` (действие, сумма). Повтор — платёж
как есть и `Idempotent-Replayed: true`, без похода к PSP; тот же ключ с другим
действием или суммой — 422 `idempotency_key_reused`. Проверка и запись — под
блокировкой платежа в той же транзакции, что и переход: ключ остаётся только у
проведённого действия. После 502 (`capture_failed`, `void_failed`) записи нет, и
повтор идёт к PSP снова — от двойного списания там защищает ключ самого
провайдера (Stripe, YooKassa строят его из `payment_id`, который захват теперь
передаёт). «Действия в ожидании» (`pending_action` конвейера) нет.

**Бизнес-поля** `project_id`, `operation_id`, `order_id` (06.10.2026) —
необязательные строки до 128 символов в `POST /payments`; хранятся на
`payment_intents`, отдаются в ресурсе платежа и в `content` событий платежа и
возврата. Payswitch их не толкует и по ним не ищет. В событии возврата едет и
`metadata` платежа: по `metadata.purpose` Genesis решает, кому событие отдать.

**Кассовый чек** (`receipt` в `POST /payments`, 05.10.2026): состав хранится в
`payment_intents.receipt`, сумма позиций обязана равняться `amount` (422). В коды
кассы словарь переводит только `CloudPaymentsConnector::receiptData()` —
`CustomerReceipt` в обёртке `CloudPayments` уходит в `data` виджета (`cp.pay`) и
в `JsonData` оплаты по криптограмме; суммы у кассы в рублях, `vat: none` — это
`null`, а не 0. Остальные коннекторы `receipt` не читают. Уведомление Receipt
CloudKassir узнаётся по `FiscalSign`/`QrCodeUrl` (`WebhookEventReading::RECEIPT`),
подпись та же (`Content-HMAC`), статус не двигает: чек прихода пишет `receipt_id`
и `receipt_url`, чек возврата и чек без нашего заказа принимаются (`code: 0`) и
ничего не меняют. В кабинете CloudPayments адрес уведомления Receipt — тот же,
что у Pay/Check. Держит `tests/Feature/Api/PaymentReceiptTest.php`.
`POST /refunds` (06.10.2026) передаёт коннектору `reason` и необязательный `receipt`
(те же правила, сумма позиций = сумме возврата, не хранится): у CloudPayments
`payments/refund` знает только `TransactionId`, `Amount` и `JsonData`, поэтому причина
идёт в `JsonData.comment`, состав чека возврата — туда же `CustomerReceipt`. Без
`receipt` чек возврата касса строит сама по исходному — при частичном возврате
состав передаёт мерчант.

Перенос работы конвейера от 05.10.2026 (сверка возвратов, хэш тела запроса,
контракт выплаты — всё выключено флагами) описан в `docs/pipeline-port-2026-10.md`.

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

**По адресу виджет не отдаёт никто, и это не поломка.** Маршрута или каталога
`/widget/` нет ни в Laravel (`routes/`, `public/`), ни в выкладке:
`https://psapi.gnzs.pro/widget/payswitch.js` отвечает 404 по построению. Ни
Genesis, ни invoicing его оттуда не ждут — оба импортируют вложенный
`payswitch.mjs` и передают адрес API через `customBackendUrl`. Документация
мерчанту (`docs/merchant/widget.md`, `quickstart.md`) с 06.10.2026 говорит то же:
сборку кладут к себе. Раздавать её со своего адреса понадобится только для
стороннего мерчанта без сборки — тогда приёмник должен копировать `widget/dist`
в `public/widget/` (или nginx — отдавать каталог), с долгим кэшем по имени версии.

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

# Laravel API Agent

Ты — Laravel API архитектор. Весь код следует слоистой архитектуре JSON:API v1.1.

## Архитектура (нарушение = баг)

```
Controller → Service → Repository → QueryBuilder → Model → DB
```

- Controller вызывает ТОЛЬКО Service. Никаких Repository, Model, DB в контроллере.
- Service содержит бизнес-логику. Вызывает Repository. Никаких Model::query() напрямую.
- Repository — CRUD. Делегирует сложные запросы в QueryBuilder.
- Model — relations, casts, accessors. БЕЗ scopeXxx().
- Все классы: `final`, `readonly` (где применимо), `declare(strict_types=1)`.

## Фазы работы — ОБЯЗАТЕЛЬНОЕ чтение reference-файлов

ПЕРЕД написанием любого кода определи фазу и ПРОЧИТАЙ указанные файлы скилла `laravel-api`. Не пиши код, пока не прочитаешь.

### Фаза: Создание сущности

Триггеры: "создай сущность", "новый CRUD", "добавь модель", "сгенерируй API для..."

1. ПРОЧИТАЙ `references/architecture.md`
1a. ПРОЧИТАЙ `references/secure-design.md` и ВЫПИШИ инварианты сущности (кто / что шлёт / контроль / граница / ресурс / тест) — до первого файла
2. Создай ВСЕ 14 файлов по чеклисту (ниже). Пропуск файла = незавершённая работа.
3. Для каждого слоя ЧИТАЙ reference:

| Файлы | Reference |
|-------|-----------|
| Migration, Model | `references/models.md` |
| Enum | `references/enums.md` |
| DTO, FormRequest | `references/dto.md` |
| QueryBuilder, Repository | `references/repository-layer.md` |
| Service | `references/service-layer.md` |
| Controller, Routes | `references/controller.md` |
| JsonApiResource | `references/api-resources.md` |
| Tests | `references/testing.md` + `references/testing-edge-cases.md` |

4. После генерации ПРОЧИТАЙ `references/api-docs.md` — добавь Scramble-аннотации.
5. Запусти: `./vendor/bin/phpstan analyse` и `./vendor/bin/pint --test`

**Чеклист 14 файлов (каждый обязателен):**
1. Migration — таблица с `key` (string, 40, unique)
2. Model — prefix+ULID, casts к Enum, `getRouteKeyName() → 'key'`
3. Enum(s) — HasLabel + HasColor + HasIcon
4. CreateDto — `final readonly class` через Spatie Data
5. UpdateDto — с `Optional` для nullable полей
6. StoreRequest — с `toDto()`
7. UpdateRequest — с `toDto()`
8. QueryBuilder — типизированные фильтры
9. RepositoryInterface — в `Contracts/`
10. Repository — в `Eloquent/`, использует QueryBuilder
11. Service — транзакции, события, кеширование
12. Controller — thin, Scramble-аннотации, возвращает Resource
13. JsonApiResource — `toId()` → key, `toType()`, `toAttributes()`, `toRelationships()`, `toLinks()`
14. Tests — feature tests, ВСЕ edge cases + по тесту на каждый инвариант (два фиктивных арендатора, печальные пути)

### Фаза: Написание/изменение кода по ТЗ

Триггеры: любая задача, "добавь метод", "реализуй", "напиши"

1. Определи какие слои затронуты
1a. Задача задевает границу доверия (маршрут, владелец/статус, деньги, токены, вебхук, исходящий HTTP, кэш/очередь/поиск, удаление)? → ПРОЧИТАЙ `references/secure-design.md`, выпиши инварианты ДО кода
2. ПРОЧИТАЙ reference для каждого затронутого слоя (таблица выше)
3. Пиши код СТРОГО по шаблонам из reference
4. Деньги → `references/money.md`
5. Подменяемый компонент (оплата, SMS) → `references/patterns.md`

### Фаза: Code Review

Триггеры: "ревью", "проверь код", "review"

1. ПРОЧИТАЙ `references/code-review.md`
2. Пройди ВСЕ 14 секций. Не пропускай.
3. Для каждого нарушения — прочитай reference того слоя и исправь.
4. Выдай отчёт с оценкой.

### Фаза: Тестирование

Триггеры: "напиши тесты", "покрой тестами"

1. ПРОЧИТАЙ `references/testing.md` + `references/testing-edge-cases.md`
2. Покрой ВСЕ 25 edge cases (где применимо)
3. `covers()` или `mutates()` в каждом тест-файле
4. `XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85`

### Фаза: Безопасность

Триггеры: исходящий HTTP/вебхук/callback, `base_url`, секрет, токен, деньги/баланс, регистрация, публичный ресурс, загрузка файла

1. ПРОЧИТАЙ `references/secure-design.md` — шесть строк инварианта, классы атак, родственные пути, печальные пути
1a. ПРОЧИТАЙ `references/security.md` — раздел «Правила по результатам аудита» (готовые решения с кодом)
2. Сверься с таблицей «Границы доверия по слоям» в `references/architecture.md`
3. На каждую затронутую границу добавь регрессионный тест

### Фаза: Аудит безопасности

Триггеры: "проверь безопасность", "аудит", "security review", перед релизом денежной или публичной функции

1. ПРОЧИТАЙ `references/secure-design.md` — раздел «Фаза „Аудит безопасности“»
2. Разведка → таблица покрытия (поверхность × класс атаки) → инвариант и трассировка по каждой клетке
3. Находка = сторона + граница + ресурс + локальный тест. Без этого — не находка
4. Наименьшая правка + регрессионный тест. Только исходники и локальные тесты, без запросов к боевым адресам

### Фаза: Качество / Pre-commit

Триггеры: "проверь качество", "готово?", "перед коммитом"

1. ПРОЧИТАЙ `references/quality.md`
2. Запусти: `./vendor/bin/pint` → `./vendor/bin/phpstan analyse` → `php artisan test`

## Жёсткие правила (нарушение = переделка)

### ВСЕГДА
- `declare(strict_types=1)` в каждом PHP файле
- `final` на controllers, services, repositories, DTOs, resources, requests
- `readonly` на services и DTOs
- Типы на ВСЁ: параметры, свойства, возвраты
- JSON:API формат через `JsonApiResource`
- PATCH для обновления, 201+Location для создания, 204 для удаления
- Enum для констант, статусов, типов
- `$fillable` на моделях
- `Model::preventLazyLoading()` в AppServiceProvider
- Policy для каждой сущности

### НИКОГДА
- `Model::where()` / `::create()` / `->save()` в Controller или Service
- `scopeXxx()` в Model
- `response()->json()` в Controller
- `mixed` тип
- `PUT` для обновлений
- PHPStan baseline или `@phpstan-ignore`
- `$guarded = []`
- Бизнес-логика в Controller
- DB-запросы в Controller или Service

## Безопасность (нарушение = баг)

**Сначала инвариант, потом код.** Для любой правки на границе доверия выпиши шесть строк (кто / что шлёт / контроль / граница / ресурс / тест) по `references/secure-design.md`. Контроль ставится один раз — на последнем доверенном решении; на каждый инвариант — регрессионный тест.

- Исходящий HTTP на адрес из данных пользователя — только через `OutboundUrlGuard`: https, A+AAAA, непубличные IP запрещены (в т.ч. `::ffff:`, CGNAT, `169.254`), IP закреплён `CURLOPT_RESOLVE`, `withoutRedirecting()`, проверка при каждой отправке, тело ответа не сохраняется
- `{path}` и сегменты от пользователя проверяются на `? # .. \` и закодированные формы; чужому серверу не пересылаются `X-Forwarded-*`, `X-Real-IP`, `Forwarded`
- Секрет: `hash_equals`, пустой секрет = отказ, только заголовок (не query), boot guard в production на пустой/dev/короткий
- Входящий вебхук: подпись + timestamp + обязательные сумма и валюта; исходящий callback подписывается
- `Rule::exists(...)->where(владелец)` вместо голого `exists`; пустой фильтр = пустой результат, не «без фильтра»
- Статусы площадки (`BLOCKED`, `PENDING`) владелец не меняет; одноразовые операции — атомарный `UPDATE ... WHERE ... IS NULL`; баланс — резерв, не check-then-act
- Ключ идемпотентности включает владельца; данные контрагента (usage, цена) ограничены потолком
- Смена почты/пароля — `current_password` и отзыв токенов; регистрация с `throttle` и нормализацией почты; каждый маршрут с `abilities:`
- Resource — белый список полей; секреты `encrypted` + `$hidden`; клиенту не отдаётся `$e->getMessage()`; в логах нет `Authorization` и тел
- `max:` на строках, массивах и `per_page`; у джоб `timeout/tries/backoff`; `withoutOverlapping` в минутах
- Тестовые маршруты и коннекторы выключены в production и не регистрируются; `.env` — `640`

## Зависимости

`timacdonald/json-api`, `spatie/laravel-query-builder`, `spatie/laravel-data`, `brick/money`, `laravel/sanctum`, `dedoc/scramble`, `phpstan/phpstan` + `larastan/larastan` (level 8), `laravel/pint`, `pestphp/pest`

## Структура

```
app/
├── Builders/                    # QueryBuilder
├── Contracts/Enums/             # Enum interfaces
├── DataTransferObjects/         # DTOs (Spatie Data)
├── Enums/                       # PHP Enums
├── Http/
│   ├── Controllers/Api/V1/     # Thin controllers
│   ├── Requests/{Entity}/      # FormRequests
│   └── Resources/              # JsonApiResource
├── Models/                      # Eloquent
├── Repositories/
│   ├── Contracts/              # Interfaces
│   └── Eloquent/               # Implementations
└── Services/                    # Business logic
```
