#!/usr/bin/env bash
# Выкладка на 85.198.101.184, /var/www/psapi.gnzs.pro. Запускать от root.
# Копировать файлы по одному нельзя: развёрнутое дерево должно совпадать
# с коммитом, иначе непонятно, что именно работает.
set -euo pipefail

DIR=${DIR:-/var/www/psapi.gnzs.pro}
BACKUPS=${BACKUPS:-/var/backups/psapi}
cd "$DIR"

as_deploy() { sudo -u deploy env HOME=/home/deploy "$@"; }
artisan() { sudo -u www-data env HOME=/tmp php artisan "$@"; }

# git только от deploy. От root git оставлял в .git объекты root, после чего
# fetch от deploy падал, и приходилось снова идти от root — поломка сама себя
# закрепляла. Если fetch упал на правах, лечится один раз:
#   chown -R deploy:www-data .git
as_deploy git fetch origin
as_deploy git merge --ff-only origin/main
as_deploy git log --oneline -1

as_deploy composer install --no-dev --optimize-autoloader --no-interaction -q

# dist панели и виджета в .gitignore: без сборки git обновляет код, а
# payswitch.gnzs.pro продолжает отдавать старую панель.
(cd widget && as_deploy npm ci --no-audit --no-fund && as_deploy npm run build)
(
    cd dashboard
    as_deploy npm ci --no-audit --no-fund
    # file:../widget ставится симлинком на исходники; панели нужна сборка.
    rm -rf node_modules/@payswitch/js
    as_deploy cp -r ../widget node_modules/@payswitch/js
    as_deploy env VITE_BACKEND_URL=https://psapi.gnzs.pro npm run build
)

# Снимок базы до миграций: откатить миграцию на живых платежах иначе нечем.
install -d -m 0700 "$BACKUPS"
sudo -u postgres pg_dump -Fc payswitch > "$BACKUPS/payswitch-$(date +%Y%m%d-%H%M%S).dump"
ls -1t "$BACKUPS"/payswitch-*.dump | tail -n +11 | xargs -r rm --

artisan migrate --force

# config:clear, а не optimize:clear: последний чистит CACHE_STORE=database и
# разлогинивает панель. Кэши маршрутов, событий и шаблонов — отдельно: старый
# events.php пережил бы выкладку и держал удалённый слушатель.
artisan config:clear
artisan route:clear
artisan event:clear
artisan view:clear
artisan queue:restart

# Планировщик: файл крона живёт в репозитории, а не только на сервере.
install -m 0644 -o root -g root deploy/cron.d/psapi-gnzs-pro /etc/cron.d/psapi-gnzs-pro

curl -sf -o /dev/null -w 'up: %{http_code}\n' https://psapi.gnzs.pro/up
curl -sf -o /dev/null -w 'panel: %{http_code}\n' https://payswitch.gnzs.pro/
