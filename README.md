# WP Theme Guard

Validates AI-generated content against your site's design system and block rules via the WordPress Abilities API.

Requires WordPress 7.0+ (trunk) with the Abilities API.

## Setup

Prerequisites: Docker, Node.js.

```bash
# Start the wp-env environment (WordPress trunk on port 8890)
npx wp-env start

# Install PHP test dependencies inside the test container
npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi composer install
```

## Running tests

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit
```

## Registered abilities

| Ability | Category | Description |
|---|---|---|
| `wp-theme-guard/validate-styles` | validation | Validates style values against theme.json |
| `wp-theme-guard/validate-blocks` | validation | Validates block markup against the block registry |
| `wp-theme-guard/get-constraints` | data-retrieval | Exports the site's design rules as a structured snapshot |

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

The `.mcp.json` in the project root configures the MCP server. After running `setup-mcp.sh`, add the credentials from `.env` to your environment or `.mcp.json`:

```json
"env": {
    "WP_API_URL": "http://localhost:8890/index.php?rest_route=/mcp/mcp-adapter-default-server",
    "WP_API_USERNAME": "admin",
    "WP_API_PASSWORD": "<from .env>",
    "OAUTH_ENABLED": "false"
}
```

Restart Claude Code to pick up the MCP server.

### Connect Cursor

Same as Claude Code — config is at `.cursor/mcp.json`.

### Available MCP Tools

The MCP adapter exposes abilities through three meta-tools:

| Tool | Description |
|---|---|
| `mcp-adapter-discover-abilities` | List all available abilities |
| `mcp-adapter-get-ability-info` | Get schema details for a specific ability |
| `mcp-adapter-execute-ability` | Execute an ability with parameters |

Call abilities via `mcp-adapter-execute-ability` with `ability_name` and `parameters`.
