#!/bin/sh

# Path to the runtime config.js file
CONFIG_FILE=/app/config.js

# Replace placeholders in config.js with environment variables
echo "Generating runtime configuration in $CONFIG_FILE"
cat <<EOF > $CONFIG_FILE
window.config = {
    VITE_API_BASE_URL: "${VITE_API_BASE_URL:-http://localhost:8000}",
    VITE_WS_URL: "${VITE_WS_URL:-ws://localhost:9000/ws}",
    VITE_PROJECTION_BASE_URL: "${VITE_PROJECTION_BASE_URL:-http://localhost:9000}"
};
EOF

# Start Nginx
nginx -g "daemon off;"
