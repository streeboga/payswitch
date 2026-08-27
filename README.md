# Payswitch

> Открытый проект [EQ Platform](https://eq.team) — [страница проекта](https://eq.team/open-source/oss-payswitch/)

Payment processing платформа с дашбордом — Laravel 13 API + React 19 SPA.

## Стек

| Слой | Технологии |
|------|-----------|
| Backend | PHP 8.3+, Laravel 13, Pest PHP |
| Frontend | React 19, TypeScript 5.9, Vite 8, Tailwind 4 |
| Auth | Laravel Sanctum (SPA cookies) + 2FA (google2fa) |
| State | Zustand, TanStack Router, React Query, ky |
| UI | Radix primitives, Lucide icons, Recharts |
| БД | PostgreSQL, database queue & cache |
| API | JSON:API v1.1, Spatie Query Builder, Scramble docs |

## Возможности

- **Платежи** — приём, подтверждение, capture, отмена, возвраты
- **Мерчанты и организации** — мультитенантность, RBAC
- **Коннекторы** — подключение платёжных провайдеров, health-check
- **Роутинг** — правила маршрутизации платежей
- **Аналитика** — overview, графики, воронка, методы оплаты, причины отказов
- **Диспуты** — управление спорами, подача доказательств
- **Вебхуки** — приём и повторная отправка событий
- **Аудит** — лог активности с экспортом
- **API-ключи** — создание и управление для мерчантов
- **2FA** — двухфакторная аутентификация через Google Authenticator

## Быстрый старт

### Требования

- PHP 8.3+
- Composer 2
- Node.js 22+
- PostgreSQL 15+

### Установка

```bash
git clone <repo-url> payswitch
cd payswitch

# Скопировать и настроить окружение
cp .env.example .env
# Отредактировать .env — задать DB_*, APP_URL, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS

# Полная установка (composer install, key:generate, migrate, npm install, build)
composer setup
```

### Разработка

```bash
# Запуск dev-сервера (PHP 8000 + Vite 3000 + queue + logs)
composer dev
```

Дашборд: http://localhost:3000
API: http://localhost:8000/api/v1

### Тесты

```bash
# Все тесты (lint + Pest)
composer test

# Только Pest
./vendor/bin/pest

# Конкретный тест
./vendor/bin/pest --filter=TestName

# Фронтенд тесты
cd dashboard && npm run test

# Полная CI-проверка (lint + format + types + tests)
composer ci:check

# Мутационное тестирование
composer mutate
```

### Линтинг

```bash
# PHP (Pint)
composer lint          # исправить
composer lint:check    # проверить

# Frontend
cd dashboard
npm run lint           # ESLint fix
npm run format         # Prettier fix
npm run types:check    # TypeScript
```

## API

### Аутентификация

| Тип | Описание |
|-----|----------|
| Sanctum SPA | Cookie-аутентификация для дашборда |
| Secret API Key | Заголовок для Merchant API |
| Admin API Key | Заголовок для Admin API |

### Основные эндпоинты

```
# Merchant API (auth: secret_api_key)
POST   /api/v1/payments
GET    /api/v1/payments/{id}
POST   /api/v1/payments/{id}/confirm
POST   /api/v1/payments/{id}/capture
POST   /api/v1/payments/{id}/cancel
POST   /api/v1/refunds
GET    /api/v1/refunds/{id}

# Dashboard API (auth: sanctum)
GET    /api/v1/dashboard/{merchant}/analytics/overview
GET    /api/v1/dashboard/{merchant}/payments
GET    /api/v1/dashboard/{merchant}/connectors
GET    /api/v1/dashboard/{merchant}/routing-rules

# Webhooks (no auth)
POST   /api/v1/webhooks/{merchantKey}/{mcaKey}

# Health
GET    /api/v1/health
```

Документация API: Scramble (доступна в dev-режиме).

## Структура проекта

```
├── app/
│   ├── Concerns/              # Трейты (HasTwoFactorAuthentication)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/          # Login, 2FA, User
│   │   │   └── Api/V1/       # JSON:API контроллеры
│   │   └── Requests/          # Form Requests
│   ├── Models/
│   ├── Services/              # Бизнес-логика
│   └── Repositories/          # Eloquent-репозитории
├── dashboard/                 # React 19 SPA
│   ├── src/
│   │   ├── api/               # HTTP-клиент, эндпоинты
│   │   ├── pages/             # Страницы
│   │   ├── stores/            # Zustand stores
│   │   └── components/        # UI-компоненты
├── packages/                  # Локальные пакеты
│   ├── streeboga/payment-data/
│   ├── streeboga/payment-connectors/
│   └── scramble/
├── routes/
│   ├── web.php                # Auth-роуты (login, logout, 2FA)
│   └── api.php                # API v1
└── tests/
    └── Feature/               # Pest Feature-тесты
```

## Деплой

### Подготовка сервера

```bash
# Системные пакеты
sudo apt update && sudo apt install -y \
  php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-gd php8.3-redis \
  nginx postgresql-15 redis-server supervisor

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 22
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

### Настройка PostgreSQL

```bash
sudo -u postgres psql
CREATE DATABASE payswitch;
CREATE USER payswitch WITH ENCRYPTED PASSWORD 'your_password';
GRANT ALL PRIVILEGES ON DATABASE payswitch TO payswitch;
\q
```

### Деплой приложения

```bash
cd /var/www/payswitch

# Зависимости
composer install --no-dev --optimize-autoloader
cd dashboard && npm ci && npm run build && cd ..

# Окружение
cp .env.example .env
# Настроить .env:
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://your-domain.com
#   FRONTEND_URL=https://your-domain.com
#   SANCTUM_STATEFUL_DOMAINS=your-domain.com
#   DB_CONNECTION=pgsql
#   DB_HOST=127.0.0.1
#   DB_DATABASE=payswitch
#   DB_USERNAME=payswitch
#   DB_PASSWORD=your_password
#   QUEUE_CONNECTION=database
#   SESSION_DOMAIN=your-domain.com

php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/payswitch/public;
    index index.php;

    ssl_certificate     /etc/ssl/certs/your-domain.pem;
    ssl_certificate_key /etc/ssl/private/your-domain.key;

    # SPA — отдаёт index.html для всех фронтенд-роутов
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Статика дашборда (Vite build)
    location /build/ {
        alias /var/www/payswitch/dashboard/dist/;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### Supervisor (Queue Worker)

```ini
; /etc/supervisor/conf.d/payswitch-worker.conf
[program:payswitch-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/payswitch/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/payswitch/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start payswitch-worker:*
```

### Cron (Scheduler)

```bash
# crontab -e -u www-data
* * * * * cd /var/www/payswitch && php artisan schedule:run >> /dev/null 2>&1
```

### Права доступа

```bash
sudo chown -R www-data:www-data /var/www/payswitch
sudo chmod -R 755 /var/www/payswitch
sudo chmod -R 775 /var/www/payswitch/storage
sudo chmod -R 775 /var/www/payswitch/bootstrap/cache
```

### Обновление (повторный деплой)

```bash
cd /var/www/payswitch
git pull origin main

composer install --no-dev --optimize-autoloader
cd dashboard && npm ci && npm run build && cd ..

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

sudo supervisorctl restart payswitch-worker:*
```

### Переменные окружения для production

| Переменная | Значение |
|-----------|----------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain.com` |
| `FRONTEND_URL` | `https://your-domain.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `your-domain.com` |
| `DB_CONNECTION` | `pgsql` |
| `QUEUE_CONNECTION` | `database` |
| `SESSION_DRIVER` | `database` |
| `SESSION_DOMAIN` | `your-domain.com` |
| `SESSION_SECURE_COOKIE` | `true` |
| `PAYSWITCH_ENVIRONMENT` | `production` |

## CI/CD

GitHub Actions запускает на push/PR в `main`:

- **Lint** — Pint (PHP) + ESLint + Prettier (TS/React)
- **Tests** — Pest PHP на матрице PHP 8.3 / 8.4 / 8.5

## Лицензия

Proprietary. All rights reserved.
