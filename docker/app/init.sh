#!/bin/sh

if [ ! -d /var/log/supervisor ]; then
  mkdir /var/log/supervisor && chmod 777 /var/log/supervisor
fi

if [ -z "$APP_ENV" ]; then
  source .env
  source .env.local
fi

echo 'Clear all caches everywhere.'
bin/console cache:pool:clear cache.global_clearer

echo 'Apply migrations to database.'
bin/console doctrine:migrations:migrate -n

echo 'Application container has been started.'

# Set umask so any file created by this script or sub-processes
# is world-readable/writable
umask 000
# Force everything in the app folder to be world-writable
# so your host user can always edit them.
chmod -R 777 /app /var/log

exec "$@"
