# MCP Integration Design

Connect wp-theme-guard abilities to AI agents (Claude Code, Cursor) via the WordPress MCP Adapter's HTTP transport.

## Goal

Enable manual testing of an end-to-end AI workflow: an AI agent discovers site design constraints, generates styles, and validates them — all through MCP tools backed by our WordPress abilities.

## Architecture

```
AI Agent (Claude Code / Cursor)
  ↓ MCP client (JSON-RPC over stdio)
@automattic/mcp-wordpress-remote (proxy)
  ↓ HTTP + app password auth
WordPress REST API (localhost:8890)
  ↓ /wp-json/mcp/mcp-adapter-default-server
MCP Adapter (converts MCP tool calls → ability executions)
  ↓
wp-theme-guard abilities (validate-styles, validate-blocks, get-constraints)
```

## Installation

`composer require wordpress/mcp-adapter` as a dependency of wp-theme-guard. The adapter initializes itself on load — our plugin just needs to require `vendor/autoload.php` and call `McpAdapter::instance()`.

WordPress trunk already bundles the Abilities API, so no separate plugin needed for that.

## Auth

HTTP transport requires WordPress application passwords. A setup script (`bin/setup-mcp.sh`) generates one and writes credentials to `.env` (gitignored). The `@automattic/mcp-wordpress-remote` proxy reads `WP_API_USERNAME` and `WP_API_PASSWORD` from env vars.

## Client Configuration

### Claude Code (`.mcp.json` in project root)

```json
{
  "mcpServers": {
    "wp-theme-guard": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "http://localhost:8890/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "<from .env>",
        "WP_API_PASSWORD": "<from .env>"
      }
    }
  }
}
```

### Cursor (`.cursor/mcp.json`)

Same structure as Claude Code.

## Exposed MCP Tools

The default MCP server exposes three meta-tools. Abilities require `mcp.public = true` in their `meta` registration to be discoverable.

| MCP Tool | Description |
|---|---|
| `mcp-adapter-discover-abilities` | List all public abilities |
| `mcp-adapter-get-ability-info` | Get schema details for a specific ability |
| `mcp-adapter-execute-ability` | Execute an ability by name with parameters |

Abilities are called via `mcp-adapter-execute-ability` with `ability_name` (e.g. `wp-theme-guard/validate-styles`) and `parameters`.

## Verification Plan

### Phase 1: Plumbing

1. Start wp-env, verify MCP adapter loaded
2. Generate app password
3. Configure Claude Code MCP server
4. Call `mcp-adapter-discover-abilities` — should list our 3 abilities
5. Call each ability tool with sample inputs

### Phase 2: Realistic Scenario

Ask Claude: "Generate styles for a hero section that comply with my theme's design system."

Expected agent behavior:
1. Calls `get-constraints` to learn the theme's palette, font sizes, spacing
2. Generates styles using preset references
3. Calls `validate-styles` to confirm validity
4. If errors, adjusts and re-validates

## Files Changed

- `composer.json` — add `wordpress/mcp-adapter` dependency
- `wp-theme-guard.php` — load autoloader, init adapter
- `bin/setup-mcp.sh` — app password generation script
- `.mcp.json` — Claude Code MCP server config (gitignored or committed as template)
- `.cursor/mcp.json` — Cursor MCP server config
- `.gitignore` — add `.env`
- `.wp-env.json` — no changes needed

## Non-Goals

- Production deployment of MCP (this is local dev/testing only)
- Custom MCP server configuration (default server is sufficient)
- STDIO transport (HTTP is more realistic; STDIO available as fallback)
