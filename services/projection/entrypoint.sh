#!/bin/sh
set -xe

export NODE_ENV="$NODE_ENV"
export REDIS_HOST="$REDIS_HOST"
export REDIS_PORT="$REDIS_PORT"
export AUTH_SERVICE_URL="$AUTH_SERVICE_URL"

node /app/dist/main.js
