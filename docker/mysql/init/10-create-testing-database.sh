#!/usr/bin/env bash
# Runs once on first container start. Creates the database used by phpunit.
mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\`;
EOSQL
