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

### Setup

wp-env must be running first (`npx wp-env start`).

```bash
# Verify MCP adapter is loaded and write .mcp.json
./bin/setup-mcp.sh
```

This uses STDIO transport via WP-CLI — no app passwords or npm proxies needed.

### Connect Claude Code

Restart Claude Code after running `setup-mcp.sh`. The `wp-theme-guard` MCP server connects automatically via `.mcp.json`.

### Connect Cursor

The `.cursor/mcp.json` is already configured. Both configs use the same STDIO transport:

```json
{
    "mcpServers": {
        "wp-theme-guard": {
            "command": "npx",
            "args": ["wp-env", "run", "cli", "--", "wp", "mcp-adapter", "serve",
                     "--server=mcp-adapter-default-server", "--user=admin"]
        }
    }
}
```

### Available MCP Tools

The MCP adapter exposes abilities through three meta-tools:

| Tool | Description |
|---|---|
| `mcp-adapter-discover-abilities` | List all available abilities |
| `mcp-adapter-get-ability-info` | Get schema details for a specific ability |
| `mcp-adapter-execute-ability` | Execute an ability with parameters |

Call abilities via `mcp-adapter-execute-ability` with `ability_name` and `parameters`.
