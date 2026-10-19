# Omnipay-адаптер и Robokassa

Пакет: отдельный репозиторий `streeboga/payment-omnipay` (`/Users/k.mazurov/PhpstormProjects/payment-omnipay`),
подключён path-репозиторием `../../payment-omnipay`. Для деплоя нужен VCS-remote (не создан).

## Включение

`PAYSWITCH_ROBOKASSA_DRIVER=omnipay` — реестр `config/payswitch.php` отдаёт `Streeboga\PaymentOmnipay\Robokassa\RobokassaConnector`.
По умолчанию `legacy` (старый `RobokassaConnector`). Старые 8 драйверов не тронуты.

Реквизиты MCA (`connector_account_details`): `login`, `password1`, `password2`, `hash_algo` (md5|ripemd160|sha1|sha256|sha384|sha512),
`test_mode`, `fiscal` (bool), `sno`, `tax`, `payment_method`, `payment_object`.
Дефолты чека `service` / `full_payment` / `none` не подтверждены бухгалтером — УСН «доходы», аванс и пополнение решает владелец.

## Границы доверия

| Граница | Кто / что шлёт | Контроль | Ресурс | Тест |
|---|---|---|---|---|
| ResultURL | Робокасса: `OutSum, InvId, SignatureValue, Shp_*` | подпись `OutSum:InvId:Пароль#2[:Shp_*]`, `hash_equals`, пустой пароль = отказ, алгоритм из allowlist | платёж по `Shp_payment_id` | `OmnipayRobokassaTest`: плохая подпись, нет `Shp_currency`, чужой MCA → 401 |
| Сумма/валюта | тот же вебхук | `OutSum` и `Shp_currency` обязательны; строгий разбор, дробь ниже копейки отклоняется; расхождение → `requires_merchant_action` | интент | тест mismatch суммы и валюты |
| Повтор | Робокасса повторяет до `OK{InvId}` | машина состояний: повтор Succeeded — no-op, событие одно | интент | тест повтора |
| Возврат браузера | SuccessURL / query | доказательством оплаты не служит; статус только по серверу | виджет | `widget/src/ui/FormRedirect.test.tsx` |
| Деньги | внутри — копейки (int), наружу — десятичная строка (`Minor`), `AmountUnit::Rubles` | границы 0..1e14 | — | `MinorAndReceiptTest` |
| Чек | `Receipt` = `rawurlencode(compact JSON)` и в подписи, и в форме | валидация enum sno/tax/payment_method/payment_object; сумма строк = сумме платежа | `payment_receipts` | тест несходящихся строк |
| InvId | `payment.id` (`invoice_number`), иначе 7 hex-цифр sha256 | положительный int32 | — | тест подписи формы |

## Чек (фискализация)

Статус чека — отдельная таблица `payment_receipts` (`pending|registered|failed`, уникально `payment_intent_id+kind`).
Успех платежа чек не закрывает (PSW-12). `PaymentReceiptService::openIfFiscal` заводит `pending` при создании сессии;
`markRegistered`/`markFailed` — для будущего фискального сигнала.

## Тестирование

- Pest без сети: `tests/Feature/Connectors/OmnipayRobokassaTest.php` (payswitch), 35 тестов в пакете.
- Строки подписи сверены с MCP `calc_signature`/`verify_result_signature` (`tests/Fixtures/robokassa-mcp.json`); хэш не сверен — пароли MCP серверные.
- Набор параметров `create_test_payment` (`IsTest=1`) сверён с формой адаптера; платёж в браузере не оплачивался.
- Полный набор payswitch: 1302 passed.

## Ограничения

1. Нет API статуса чека: чек остаётся `pending` до фискального сигнала (его пока нет).
2. Не реализованы refund, status, hold, recurring, second receipt (RoboFiscal/Receipt/Attach).
3. `robokassa` не добавлена в `connectable`.
4. В phpstan приложения остаются 15 старых ошибок (не от этой работы).

## Подключить новую платёжку

1. В пакете: `Gateway` (Omnipay `AbstractGateway`) + `PurchaseRequest/Response` + `Notification` (подпись проверяется в конструкторе).
2. `XxxConnector extends OmnipayConnector`: `makeGateway`, `sessionOptions`, `requiredCredentials`, `capabilities()`.
3. Запись в `config/payswitch.php` (за флагом драйвера), тесты на фикстурах подписи, строка в таблице границ доверия выше.

## Включение и адреса для кабинета Робокассы

1. Payswitch `.env`: `PAYSWITCH_ROBOKASSA_DRIVER=omnipay`, `robokassa` в `PAYSWITCH_CONNECTABLE`, `ROBOKASSA_*` (логин, пароли #1/#2, `ROBOKASSA_HASH_ALGO`, `ROBOKASSA_TEST_MODE=true`). Значения вносит владелец.
2. Genesis `.env`: `robokassa` в `PAYSWITCH_CONNECTABLE`. Мерчант payswitch заводится при первом обращении проекта (ключи в `app_modules.settings['payswitch']`).
3. MCA: либо форма шлюзов в панели Genesis, либо `php artisan payswitch:robokassa-mca <merchant_key>` (берёт `ROBOKASSA_*`).
4. Кабинет Робокассы → Технические настройки магазина:

| Поле | Значение |
|---|---|
| Result URL (метод POST) | `https://psapi.gnzs.pro/api/v1/webhooks/{merchant_key}/{mca_key}` |
| Алгоритм подписи | тот же, что `ROBOKASSA_HASH_ALGO` |
| Success URL / Fail URL | страница возврата проекта (статика кабинета; платёж по ней не подтверждается) |

`merchant_key` и `mca_key` видны после шага 3 (`GET /api/v1/merchants/{merchant_key}/connectors`).

Путь платежа: Hub → Genesis (`PayswitchPaymentsService`) → payswitch `/payments` + confirm → адаптер отдаёт форму `form_redirect` → Робокасса → ResultURL в payswitch (подпись Паролем #2, `OK{InvId}`) → статус платежа → исходящий вебхук payswitch на `https://api.gnzs.pro/internal/webhooks/payswitch/{app_uuid}` (HMAC, проект в адресе) → Genesis зачисляет. Чек: отдельная строка `payment_receipts`, двигается только фискальным сигналом.
