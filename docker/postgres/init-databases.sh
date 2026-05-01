#!/bin/bash
# =============================================================================
# PostgreSQL Initialization Script
# Creates one database per microservice (database-per-service pattern)
# This script runs automatically on first container start.
# Ref: https://www.postgresql.org/docs/16/manage-ag-createdb.html
# =============================================================================

set -e

echo ">>> Creating SaintAugustin databases..."

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_USER" <<-EOSQL
    CREATE DATABASE "$DB_AUTH_NAME" OWNER $POSTGRES_USER;

    -- Grant full privileges to the app user on each database
    GRANT ALL PRIVILEGES ON DATABASE "$DB_AUTH_NAME" TO $POSTGRES_USER;
EOSQL

echo ">>> Databases created: $DB_AUTH_NAME"
