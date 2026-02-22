#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"

echo "Setting up MCP authentication..."

# Generate application password.
APP_PASSWORD=$(npx wp-env run cli -- wp user application-password create admin mcp-testing --porcelain --user=admin 2>/dev/null)

if [ -z "$APP_PASSWORD" ]; then
    echo "Error: Failed to generate application password."
    exit 1
fi

# Write .env file.
cat > "$ENV_FILE" << EOF
WP_API_URL=http://localhost:8890/wp-json/mcp/mcp-adapter-default-server
WP_API_USERNAME=admin
WP_API_PASSWORD=$APP_PASSWORD
EOF

echo "Credentials written to .env"
echo ""
echo "MCP server URL: http://localhost:8890/wp-json/mcp/mcp-adapter-default-server"
echo "Username: admin"
echo "Password: $APP_PASSWORD"
