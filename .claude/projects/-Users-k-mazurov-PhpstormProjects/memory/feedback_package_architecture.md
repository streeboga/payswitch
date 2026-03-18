---
name: package_architecture
description: Project uses monorepo package architecture with vendor 'streeboga'. Domain logic split into composer packages under packages/ for SDK reuse. Contracts+data in one package, connectors separate.
type: project
---

Payswitch использует пакетную архитектуру с вендором `streeboga`. Доменная логика выносится в отдельные Composer-пакеты в `packages/` для переиспользования в SDK.

**Why:** Пакеты с контрактами, моделями и коннекторами должны подключаться к SDK отдельно от Laravel-приложения. Разделение на слои данных и контрактов.

**How to apply:**
- `packages/streeboga/payment-data` — контракты (интерфейсы, enums, DTO) + Eloquent модели, миграции, репозитории
- `packages/streeboga/payment-connectors` — OmniPay abstraction, Stripe/YooKassa коннекторы
- `app/` — Laravel-specific: controllers, middleware, routes, jobs, listeners, actions
- Вендор: `streeboga`
