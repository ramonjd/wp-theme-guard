# WP Theme Guard

WordPress plugin that validates AI-generated content against the site's design system via the Abilities API.

## MCP Tools

This project has an MCP server (`wp-theme-guard`) that connects to a running WordPress instance. Use these tools instead of reading source files when working with design validation:

- **`mcp-adapter-discover-abilities`** — List available abilities
- **`mcp-adapter-get-ability-info`** — Get input/output schema for an ability
- **`mcp-adapter-execute-ability`** — Execute an ability

### Abilities

| Ability name | Use for |
|---|---|
| `wp-theme-guard/validate-styles` | Validating style objects against the active theme's design system |
| `wp-theme-guard/validate-blocks` | Validating block markup against the block registry |
| `wp-theme-guard/get-constraints` | Fetching the site's color palette, font sizes, spacing presets, and block rules |

### Workflow

When asked to generate or validate styles/blocks:

1. Call `mcp-adapter-execute-ability` with `ability_name: "wp-theme-guard/get-constraints"` to learn the site's design constraints
2. Generate styles using any valid CSS values. Prefer theme preset references (e.g. `var(--wp--preset--color--black)`) for consistency with the design system, but custom values like `#ff6600`, `18px`, or `2rem` are also valid when the theme allows them
3. Call `mcp-adapter-execute-ability` with `ability_name: "wp-theme-guard/validate-styles"` to check validity
4. If errors are returned, fix them and re-validate

The validator checks that properties exist in the theme.json schema, values are well-formed, and the theme settings allow the feature (e.g. custom colors enabled). It does not force preset usage — it ensures the styles are compatible with the site's configuration.

### Style object structure

The `styles` parameter follows the WordPress theme.json styles format. Properties are nested objects with dot-path keys like `color.text`, `typography.fontSize`, `spacing.padding.top`.

```json
{
  "color": {
    "text": "var(--wp--preset--color--contrast)",
    "background": "var(--wp--preset--color--base)",
    "gradient": "var(--wp--preset--gradient--vivid-cyan-blue-to-vivid-purple)"
  },
  "typography": {
    "fontSize": "var(--wp--preset--font-size--large)",
    "fontFamily": "var(--wp--preset--font-family--manrope)",
    "fontWeight": "700",
    "lineHeight": "1.5",
    "letterSpacing": "0.02em",
    "textDecoration": "none",
    "textTransform": "uppercase"
  },
  "spacing": {
    "padding": {
      "top": "var(--wp--preset--spacing--50)",
      "right": "var(--wp--preset--spacing--50)",
      "bottom": "var(--wp--preset--spacing--50)",
      "left": "var(--wp--preset--spacing--50)"
    },
    "margin": {
      "top": "var(--wp--preset--spacing--30)",
      "bottom": "var(--wp--preset--spacing--30)"
    },
    "blockGap": "var(--wp--preset--spacing--40)"
  },
  "border": {
    "color": "var(--wp--preset--color--accent-3)",
    "width": "2px",
    "style": "solid",
    "radius": "8px"
  }
}
```

Preset references (`var(--wp--preset--<type>--<slug>)`) are preferred for design system consistency, but custom CSS values are valid too when the theme permits them. Call `get-constraints` first to discover available presets and which custom value features are enabled.

### Example calls

Validate styles:
```json
{"ability_name": "wp-theme-guard/validate-styles", "parameters": {"styles": {"color": {"text": "var(--wp--preset--color--black)"}}}}
```

With block context (also checks block supports):
```json
{"ability_name": "wp-theme-guard/validate-styles", "parameters": {"styles": {"color": {"text": "var(--wp--preset--color--black)"}}, "blockName": "core/paragraph"}}
```

Validate blocks:
```json
{"ability_name": "wp-theme-guard/validate-blocks", "parameters": {"content": "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->"}}
```

Get constraints (discover available presets):
```json
{"ability_name": "wp-theme-guard/get-constraints", "parameters": {"include": ["styles", "blocks", "layout"]}}
```

## Development

- **Tests:** `npx wp-env run tests-cli --env-cwd=wp-content/plugins/nairobi vendor/bin/phpunit`
- **wp-env:** WordPress trunk on port 8890
- **PHP:** 8.1+
