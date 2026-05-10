#!/bin/sh
set -xe

touch /app/.env

echo "VITE_API_BASE_URL=$VITE_API_BASE_URL" >> /app/.env
echo "VITE_WS_URL=$VITE_WS_URL" >> /app/.env
echo "VITE_PROJECTION_BASE_URL=$VITE_PROJECTION_BASE_URL" >> /app/.env

exec "$@"
