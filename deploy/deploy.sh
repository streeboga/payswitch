#!/usr/bin/env bash
# Выкладка на 85.198.101.184 идёт только через приёмник: push в main → Woodpecker → ci-deploy.
# Прежний скрипт шёл от root в обход приёмника и ломал правило владельцев: код deploy:www-data,
# storage и bootstrap/cache www-data:www-data g+rwXs (deploy.sh invoicing делал chown -R www-data).
echo "выкладка через приёмник ci-deploy / Woodpecker: push в main → Woodpecker → приёмник; вручную — ci-deploy payswitch <sha> от deploy" >&2
exit 1
