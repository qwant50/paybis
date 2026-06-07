#!/usr/bin/env bash
set -e

# The official MySQL image already creates ${APP_DB} and the ${APP_DB_USER}
# account (via MYSQL_DATABASE / MYSQL_USER). Here we additionally provision the
# test database and grant the application user access to both schemas.
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<EOSQL
  CREATE DATABASE IF NOT EXISTS \`${APP_DB}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON \`${APP_DB}\`.* TO '${APP_DB_USER}'@'%';
  GRANT ALL PRIVILEGES ON \`${APP_DB}_test\`.* TO '${APP_DB_USER}'@'%';
  FLUSH PRIVILEGES;
EOSQL
