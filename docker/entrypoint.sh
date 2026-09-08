#!/bin/sh
set -eu
cd /var/www/html

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

echo "[Fluxos Luiz] Limpando caches..."
php artisan optimize:clear >/dev/null 2>&1 || true

echo "[Fluxos Luiz] Criando/validando índices MongoDB..."
php artisan app:ensure-mongo-indexes

echo "[Fluxos Luiz] Criando/validando administrador inicial..."
php artisan app:bootstrap-admin

echo "[Fluxos Luiz] Aplicação disponível na porta 8000."
exec php artisan serve --host=0.0.0.0 --port=8000
