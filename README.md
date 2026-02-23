# WP Theme Guard

WordPress plugin that validates AI-generated styles and block markup against the active theme's design system. Exposes validation abilities via the WordPress Abilities API so any AI agent — whether connected through MCP, REST, or a custom client — can generate theme-compatible output.

Requires WordPress 7.0+ (trunk) with the Abilities API.

## What it does

- **Style validation** — checks color, typography, spacing, border, and other properties against the theme.json schema, theme settings (custom values allowed?), and block supports. Suggests preset alternatives when a custom value is close to an existing preset.
- **Custom CSS support** — the `css` property accepts raw CSS strings (with `&` nesting syntax) for styling that goes beyond declarative theme.json properties.
- **Block validation** — checks block markup against the block registry and nesting rules.
- **Design constraints export** — returns the site's color palette, font sizes, spacing presets, block registry, layout settings, and a structure guide that teaches agents how to target global, block, and element styles.

## Abilities

| Ability | Description |
|---|---|
| `wp-theme-guard/validate-styles` | Validate style objects against theme.json. Accepts `styles`, optional `blockName` for block support checks. |
| `wp-theme-guard/validate-blocks` | Validate block markup against the block registry and nesting rules. |
| `wp-theme-guard/get-constraints` | Export the site's design rules: structure guide, style presets, block registry, and layout. |

## Integrating with AI agents

The typical agent workflow:

1. **Learn the design system** — call `get-constraints` to discover available presets, style properties, the targeting hierarchy (global / block / element), and the `css` escape hatch.
2. **Check existing styles** — fetch current user global styles so changes can be merged rather than replacing what's already saved.
3. **Generate styles** — produce a theme.json `styles` object. Use preset references (`var(--wp--preset--color--primary)`) when possible; use the `css` property for anything beyond declarative properties.
4. **Validate** — call `validate-styles` for each target separately (one call per block or global scope). Fix errors and re-validate.
5. **Save** — write the validated styles to the global styles CPT via the `/wp/v2/global-styles/{id}` REST endpoint.

### Example: validate styles via the Abilities API

```php
$ability = wp_get_ability( 'wp-theme-guard/validate-styles' );
$result  = $ability->execute( array(
    'styles'    => array(
        'color' => array( 'text' => 'var(--wp--preset--color--primary)' ),
        'css'   => '& .inner { display: grid; gap: 1rem; }',
    ),
    'blockName' => 'core/group',
) );
// $result = [ 'valid' => true, 'errors' => [], 'warnings' => [] ]
```

### Example: validate styles via MCP

```json
{
    "ability_name": "wp-theme-guard/validate-styles",
    "parameters": {
        "styles": { "color": { "text": "var(--wp--preset--color--primary)" } },
        "blockName": "core/group"
    }
}
```

### Example: get design constraints via MCP

```json
{
    "ability_name": "wp-theme-guard/get-constraints",
    "parameters": { "include": ["structure", "styles"] }
}
```

The response includes a `structure` section with a complete example of the styles tree, a targeting hierarchy reference, block name aliases (e.g. "button" → `core/button`), and documentation for the `css` property.

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

## MCP integration

Connect AI agents (Claude Code, Cursor) to wp-theme-guard abilities via MCP.

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

### MCP tools

The MCP adapter exposes abilities through three meta-tools:

| Tool | Description |
|---|---|
| `mcp-adapter-discover-abilities` | List all available abilities |
| `mcp-adapter-get-ability-info` | Get schema details for a specific ability |
| `mcp-adapter-execute-ability` | Execute an ability with parameters |

## Test agent (admin page)

A built-in chat interface for testing wp-theme-guard abilities with an AI agent. Lives under **Tools > Theme Guard Agent** in wp-admin.

### Setup

1. Add your Anthropic API key to wp-env:

```bash
npx wp-env run cli -- wp config set WP_THEME_GUARD_API_KEY 'sk-ant-your-key-here' --type=constant
```

2. Visit `http://localhost:8890/wp-admin/tools.php?page=wp-theme-guard-agent`

The agent calls `get_constraints` and `get_current_styles` to learn your theme and existing customizations, generates styles, validates them with `validate_styles`, and self-corrects on errors. Valid styles are accumulated across conversation turns and can be saved as global styles with one click. The agent can also call `reset_styles` to hard-reset the global styles CPT to the base empty state.

### Architecture

- Agent code is isolated in `includes/agent/` and `assets/` — separate from core validation abilities.
- The admin page always loads (so the setup notice is visible), but the REST endpoint and Anthropic client only load when `WP_THEME_GUARD_API_KEY` is defined.
- All Anthropic API calls happen server-side. The API key is never sent to the browser.
- Saving uses the core `/wp/v2/global-styles/{id}` REST endpoint via `wp.apiFetch` with deep merge. WordPress creates a revision automatically.
- Resets use a dedicated `/wp-theme-guard/v1/agent/reset-styles` endpoint that writes the base theme.json directly to the CPT.
