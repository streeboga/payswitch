#!/usr/bin/env bash
# Выкладка на 85.198.101.184, /var/www/psapi.gnzs.pro. Запускать от root.
# Копировать файлы по одному нельзя: развёрнутое дерево должно совпадать
# с коммитом, иначе непонятно, что именно работает.
set -euo pipefail

DIR=${DIR:-/var/www/psapi.gnzs.pro}
cd "$DIR"

# git от root: часть объектов в .git принадлежит root, от deploy fetch падает.
git fetch origin
git merge --ff-only origin/main
git log --oneline -1

sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction -q

# config:clear, а не optimize:clear: последний чистит CACHE_STORE=database.
sudo -u www-data env HOME=/tmp php artisan migrate --force
sudo -u www-data env HOME=/tmp php artisan config:clear
sudo -u www-data env HOME=/tmp php artisan queue:restart

# Планировщик: файл крона живёт в репозитории, а не только на сервере.
install -m 0644 -o root -g root deploy/cron.d/psapi-gnzs-pro /etc/cron.d/psapi-gnzs-pro

curl -s -o /dev/null -w 'up: %{http_code}\n' https://psapi.gnzs.pro/up
