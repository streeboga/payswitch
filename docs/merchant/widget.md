# Виджет SDK

Embeddable JavaScript SDK для приёма платежей на вашем сайте.

## Установка

### npm

```bash
npm install @payswitch/js
```

```javascript
import { loadPayswitch } from '@payswitch/js';
```

### CDN (UMD)

```html
<script src="https://cdn.payswitch.example.com/js/v1/payswitch.umd.js"></script>
<script>
  // Доступно через глобальную переменную Payswitch
  const payswitch = await Payswitch.loadPayswitch('pk_xxx');
</script>
```

## API

### loadPayswitch

Инициализирует SDK и возвращает экземпляр `PayswitchInstance`.

```typescript
loadPayswitch(
  publishableKey: string,
  options?: LoadOptions
): Promise<PayswitchInstance>
```

#### Параметры

| Параметр | Тип | Описание |
|----------|-----|----------|
| `publishableKey` | `string` | Ваш publishable key (`pk_...`) |
| `options.customBackendUrl` | `string` | URL вашего API (если отличается от дефолтного) |
| `options.env` | `'sandbox' \| 'production'` | Окружение. По умолчанию: `'production'` |

#### Пример

```javascript
const payswitch = await loadPayswitch('pk_live_abc123', {
  customBackendUrl: 'https://api.payswitch.example.com',
  env: 'production'
});
```

### instance.widgets

Создаёт коллекцию виджетов, привязанную к конкретному платежу через `clientSecret`.

```typescript
instance.widgets(options: WidgetsOptions): WidgetCollection
```

#### Параметры

| Параметр | Тип | Обязательное | Описание |
|----------|-----|:------------:|----------|
| `clientSecret` | `string` | да | `client_secret` из ответа при создании платежа |
| `locale` | `string` | нет | Локаль (`'ru'`, `'en'`). По умолчанию определяется автоматически |
| `translations` | `object` | нет | Пользовательские переводы для переопределения стандартных строк |

#### Пример

```javascript
const widgets = payswitch.widgets({
  clientSecret: 'pay_abc123_secret_xyz789',
  locale: 'ru',
  translations: {
    payButton: 'Оплатить заказ',
    cardNumber: 'Номер карты'
  }
});
```

### collection.create

Создаёт виджет указанного типа.

```typescript
collection.create(type: 'payment'): PaymentWidget
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `type` | `'payment'` | Тип виджета. На данный момент поддерживается `'payment'` |

### widget.mount

Монтирует виджет в DOM-элемент.

```typescript
widget.mount(target: string | HTMLElement): void
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `target` | `string \| HTMLElement` | CSS-селектор или ссылка на DOM-элемент |

#### Пример

```javascript
const paymentWidget = widgets.create('payment');

// По селектору
paymentWidget.mount('#payment-widget');

// По ссылке на элемент
const container = document.getElementById('payment-widget');
paymentWidget.mount(container);
```

### widget.unmount

Отмонтирует виджет из DOM, сохраняя его состояние. Виджет можно повторно примонтировать вызовом `mount()`.

```typescript
widget.unmount(): void
```

### widget.destroy

Полностью уничтожает виджет и освобождает все ресурсы. После вызова `destroy()` виджет нельзя использовать повторно.

```typescript
widget.destroy(): void
```

### widget.on

Подписывается на события виджета.

```typescript
widget.on(event: string, handler: Function): void
```

#### События

| Событие | Описание | Данные в handler |
|---------|----------|-----------------|
| `'ready'` | Виджет загружен и готов к взаимодействию | — |
| `'change'` | Пользователь изменил данные в форме | `{ complete: boolean }` |
| `'redirect'` | Начинается перенаправление на внешнюю страницу | `{ url: string }` |
| `'error'` | Произошла ошибка | `{ message: string, code?: string }` |

#### Пример

```javascript
paymentWidget.on('ready', () => {
  console.log('Виджет готов');
  document.getElementById('pay-button').disabled = false;
});

paymentWidget.on('change', (event) => {
  if (event.complete) {
    document.getElementById('pay-button').disabled = false;
  }
});

paymentWidget.on('error', (error) => {
  console.error('Ошибка виджета:', error.message);
});
```

### instance.confirmPayment

Подтверждает платёж. Обрабатывает все необходимые действия (3DS, редирект и т.д.).

```typescript
instance.confirmPayment(options: ConfirmOptions): Promise<ConfirmPaymentResult>
```

#### Параметры

| Параметр | Тип | Обязательное | Описание |
|----------|-----|:------------:|----------|
| `widgets` | `WidgetCollection` | да | Коллекция виджетов |
| `confirmParams.return_url` | `string` | да | URL для перенаправления после оплаты |
| `redirect` | `'always' \| 'if_required'` | нет | Стратегия редиректа. По умолчанию: `'if_required'` |

- `'always'` — всегда перенаправлять на `return_url` после оплаты
- `'if_required'` — перенаправлять только если платёжный метод этого требует

#### Типы результата

| Тип | Описание |
|-----|----------|
| `succeeded` | Платёж успешно завершён |
| `requires_customer_action` | Требуется действие пользователя (3DS и т.п.) — происходит редирект |
| `requires_form_redirect` | Требуется отправка формы на внешний URL (автоматическая) |
| `requires_qr` | Требуется показ QR-кода для оплаты (СБП и т.п.) |
| `requires_external_widget` | Требуется загрузка внешнего виджета провайдера |
| `error` | Ошибка подтверждения платежа |

#### Пример

```javascript
const result = await payswitch.confirmPayment({
  widgets,
  confirmParams: {
    return_url: 'https://example.com/payment/result'
  },
  redirect: 'if_required'
});

switch (result.type) {
  case 'succeeded':
    showSuccess('Платёж прошёл успешно!');
    break;
  case 'error':
    showError(result.error.message);
    break;
  // Остальные типы обрабатываются SDK автоматически
}
```

## Полный пример

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Оплата</title>
</head>
<body>
  <div id="payment-widget"></div>
  <button id="pay-button" disabled>Оплатить</button>
  <div id="error-message"></div>

  <script type="module">
    import { loadPayswitch } from '@payswitch/js';

    const payswitch = await loadPayswitch('pk_live_abc123', {
      customBackendUrl: 'https://api.payswitch.example.com'
    });

    // clientSecret получен от вашего бэкенда
    const clientSecret = 'pay_abc123_secret_xyz789';

    const widgets = payswitch.widgets({
      clientSecret,
      locale: 'ru'
    });

    const paymentWidget = widgets.create('payment');
    paymentWidget.mount('#payment-widget');

    paymentWidget.on('ready', () => {
      document.getElementById('pay-button').disabled = false;
    });

    paymentWidget.on('error', (err) => {
      document.getElementById('error-message').textContent = err.message;
    });

    document.getElementById('pay-button').addEventListener('click', async () => {
      const button = document.getElementById('pay-button');
      button.disabled = true;

      const result = await payswitch.confirmPayment({
        widgets,
        confirmParams: {
          return_url: window.location.origin + '/payment/result'
        },
        redirect: 'if_required'
      });

      if (result.error) {
        document.getElementById('error-message').textContent = result.error.message;
        button.disabled = false;
      }
    });
  </script>
</body>
</html>
```
