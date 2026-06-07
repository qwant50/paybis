#!/bin/sh

if [ ! -d /var/log/supervisor ]; then
  mkdir /var/log/supervisor && chmod 777 /var/log/supervisor
fi

if [ -z "$APP_ENV" ]; then
  source .env
  source .env.local
fi

# composer install in init.sh, not in Dockerfile.local, because:
# - ./app folder from host is mounted to /app in container
# - all existing content in /app in container is rewritten by content of ./app host folder
echo "Install composer packages."
composer install -n

echo 'Clear all caches everywhere.'
bin/console cache:pool:clear cache.global_clearer

echo 'Apply migrations to database.'
bin/console doctrine:migrations:migrate -n

echo 'Apply migrations to test database.'
bin/console doctrine:migrations:migrate -n -e test

# Set umask so any file created by this script or sub-processes
# is world-readable/writable
umask 000
# Force everything in the app folder to be world-writable
# so your host user can always edit them.
chmod -R 777 /app /var/log

echo 'Application container has been started.'

exec "$@"
