#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Setting up MCP for wp-theme-guard..."

# Verify wp-env is running.
if ! npx wp-env run cli -- wp option get siteurl > /dev/null 2>&1; then
    echo "Error: wp-env is not running. Start it with: npx wp-env start"
    exit 1
fi

# Verify MCP adapter is loaded.
if ! npx wp-env run cli -- wp mcp-adapter list --user=admin > /dev/null 2>&1; then
    echo "Error: MCP adapter not loaded. Run: npx wp-env run cli --env-cwd=wp-content/plugins/nairobi composer install"
    exit 1
fi

# Write .mcp.json for Claude Code.
cat > "$PROJECT_DIR/.mcp.json" << 'EOF'
{
	"mcpServers": {
		"wp-theme-guard": {
			"command": "npx",
			"args": [
				"wp-env",
				"run",
				"cli",
				"--",
				"wp",
				"mcp-adapter",
				"serve",
				"--server=mcp-adapter-default-server",
				"--user=admin"
			]
		}
	}
}
EOF

echo "MCP config written to .mcp.json"
echo ""
echo "Transport: STDIO (via wp-env + WP-CLI)"
echo "No app passwords required."
echo ""
echo "Restart Claude Code to pick up the MCP server."
echo "For Cursor, copy .mcp.json to .cursor/mcp.json"
