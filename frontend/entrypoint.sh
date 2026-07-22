#!/bin/sh

# Path to the runtime config.js file
CONFIG_FILE=/app/config.js

# Replace placeholders in config.js with environment variables
echo "Generating runtime configuration in $CONFIG_FILE"
cat <<EOF > $CONFIG_FILE
window.config = {
    API_BASE_URL: "${API_BASE_URL:-http://localhost:8000}",
    PROJECTION_WS_URL: "${PROJECTION_WS_URL:-ws://localhost:9000/ws}",
    PROJECTION_URL: "${PROJECTION_URL:-http://localhost:9000}"
};
EOF

# Start Nginx
nginx -g "daemon off;"
