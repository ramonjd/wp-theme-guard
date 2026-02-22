# MCP Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire up the WordPress MCP Adapter so AI agents (Claude Code, Cursor) can discover and call wp-theme-guard abilities via HTTP transport.

**Architecture:** `composer require wordpress/mcp-adapter` as a library dependency. Our plugin loads the adapter on init. The adapter auto-creates a default MCP server exposing all abilities at `/wp-json/mcp/mcp-adapter-default-server`. AI clients connect through `@automattic/mcp-wordpress-remote` proxy using application password auth.

**Tech Stack:** PHP 8.1, WordPress trunk (7.0-alpha), wp-env, Composer, `@automattic/mcp-wordpress-remote` (npm)

---

### Task 1: Add MCP Adapter as Composer dependency

Install the adapter and wire it into our plugin bootstrap.

**Files:**
- Modify: `composer.json`
- Modify: `wp-theme-guard.php`

**Step 1: Update composer.json**

Replace `composer.json` with:

```json
{
	"name": "wordpress/wp-theme-guard",
	"description": "Validates AI-generated content against your site's design system and block rules via the Abilities API.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"wordpress/mcp-adapter": "^0.4"
	},
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"yoast/phpunit-polyfills": "^2.0"
	}
}
```

**Step 2: Run composer update**

Run: `composer update --no-dev -W`
Expected: `wordpress/mcp-adapter` v0.4.1 installed to `vendor/`.

Then install dev deps too for tests:
Run: `composer install`

**Step 3: Update plugin bootstrap to load adapter**

In `wp-theme-guard.php`, add the autoloader require and adapter init. After the `define` lines and before `wp_theme_guard_init`, add:

```php
// Load Composer autoloader for MCP Adapter.
if ( file_exists( WP_THEME_GUARD_PATH . 'vendor/autoload.php' ) ) {
	require_once WP_THEME_GUARD_PATH . 'vendor/autoload.php';
}
```

Then inside `wp_theme_guard_init()`, after the class requires, add:

```php
	// Initialize MCP Adapter if available.
	if ( class_exists( \WP\MCP\Plugin::class ) ) {
		\WP\MCP\Plugin::instance();
	}
```

**Step 4: Reinstall deps in test container and verify tests still pass**

Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi composer install`
Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`
Expected: 32 tests, 65 assertions — all PASS.

**Step 5: Verify MCP adapter loaded in wp-env**

Run: `npx wp-env run cli -- wp eval "echo class_exists('\WP\MCP\Plugin') ? 'MCP Adapter loaded' : 'NOT loaded';" --user=admin`
Expected: `MCP Adapter loaded`

Run: `npx wp-env run cli -- wp mcp-adapter list --user=admin`
Expected: Lists the default MCP server.

**Step 6: Commit**

```bash
git add composer.json composer.lock wp-theme-guard.php
git commit -m "feat: add MCP Adapter as composer dependency"
```

---

### Task 2: App password setup script

Create a script that generates a WordPress application password and writes it to `.env`.

**Files:**
- Create: `bin/setup-mcp.sh`
- Modify: `.gitignore`

**Step 1: Add .env to .gitignore**

Append `.env` to `.gitignore`.

**Step 2: Create setup script**

Create `bin/setup-mcp.sh`:

```bash
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
```

**Step 3: Make it executable**

Run: `chmod +x bin/setup-mcp.sh`

**Step 4: Test it**

Run: `./bin/setup-mcp.sh`
Expected: `.env` file created with credentials.

Run: `cat .env`
Expected: Contains WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD.

**Step 5: Commit**

```bash
git add bin/setup-mcp.sh .gitignore
git commit -m "feat: add MCP app password setup script"
```

---

### Task 3: MCP client configuration files

Create config files for Claude Code and Cursor that use the HTTP transport proxy.

**Files:**
- Create: `.mcp.json`
- Create: `.cursor/mcp.json`

**Step 1: Create Claude Code config**

Create `.mcp.json` in project root. This file uses env vars from `.env` — Claude Code reads `.env` files automatically, but we also hardcode the URL since it's always localhost for this project:

```json
{
	"mcpServers": {
		"wp-theme-guard": {
			"command": "npx",
			"args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
			"env": {
				"WP_API_URL": "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server",
				"OAUTH_ENABLED": "false",
				"LOG_LEVEL": "1"
			}
		}
	}
}
```

Note: `WP_API_USERNAME` and `WP_API_PASSWORD` are read from `.env` by the proxy automatically, or can be set in the env block after running `bin/setup-mcp.sh`.

**Step 2: Create Cursor config**

Create `.cursor/mcp.json`:

```json
{
	"mcpServers": {
		"wp-theme-guard": {
			"command": "npx",
			"args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
			"env": {
				"WP_API_URL": "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server",
				"OAUTH_ENABLED": "false",
				"LOG_LEVEL": "1"
			}
		}
	}
}
```

**Step 3: Commit**

```bash
git add .mcp.json .cursor/mcp.json
git commit -m "feat: add MCP client configs for Claude Code and Cursor"
```

---

### Task 4: Verify plumbing end-to-end

Manual verification — no code changes. Run these from the project directory after `bin/setup-mcp.sh`.

**Step 1: Verify REST endpoint responds**

Source the .env file and test with curl:

```bash
source .env
curl -s -u "$WP_API_USERNAME:$WP_API_PASSWORD" \
  "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | python3 -m json.tool
```

Expected: JSON response listing MCP tools including our 3 abilities.

**Step 2: Test calling validate-styles via MCP**

```bash
source .env
curl -s -u "$WP_API_USERNAME:$WP_API_PASSWORD" \
  "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wp-theme-guard-validate-styles","arguments":{"styles":{"color":{"background":"#ff0000"}}}}}' | python3 -m json.tool
```

Expected: JSON response with validation result (valid/errors/warnings).

**Step 3: Test from Claude Code**

Restart Claude Code (or run `/mcp` to reload MCP servers). The `wp-theme-guard` server should appear. Ask Claude to call `mcp-adapter-discover-abilities` to list available abilities.

**Step 4: Test each ability tool**

From Claude Code, call:
- `wp-theme-guard-get-constraints` with `{}` → should return styles/blocks/layout
- `wp-theme-guard-validate-styles` with `{"styles":{"color":{"text":"var(--wp--preset--color--black)"}}}` → should return valid: true
- `wp-theme-guard-validate-blocks` with `{"content":"<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->"}` → should return valid: true

**Step 5: Commit (only if fixes were needed)**

---

### Task 5: Update README

Add MCP setup instructions to README.md.

**Files:**
- Modify: `README.md`

**Step 1: Add MCP section**

Append to README.md after the "Registered abilities" section:

```markdown
## MCP Integration (AI Agent Testing)

Connect AI agents (Claude Code, Cursor) to wp-theme-guard abilities via MCP.

### Prerequisites

wp-env must be running (`npx wp-env start`).

### Setup

```bash
# Install dependencies (includes MCP Adapter)
composer install

# Generate application password for MCP auth
./bin/setup-mcp.sh
```

### Connect Claude Code

The `.mcp.json` in the project root configures the MCP server automatically. After running `setup-mcp.sh`, add the credentials to `.mcp.json`:

```json
"env": {
    "WP_API_URL": "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server",
    "WP_API_USERNAME": "admin",
    "WP_API_PASSWORD": "<from .env>",
    "OAUTH_ENABLED": "false"
}
```

Restart Claude Code to pick up the MCP server.

### Connect Cursor

Same as Claude Code — config is at `.cursor/mcp.json`.

### Available MCP Tools

| Tool | Description |
|---|---|
| `wp-theme-guard-validate-styles` | Validate styles against theme.json |
| `wp-theme-guard-validate-blocks` | Validate block markup |
| `wp-theme-guard-get-constraints` | Export site design constraints |
```

**Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add MCP integration setup instructions"
```
