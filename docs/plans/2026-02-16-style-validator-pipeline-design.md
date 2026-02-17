# Style Validator Pipeline Design

## Problem

The current `validate-styles` ability only checks color, typography, and spacing values against theme presets. It doesn't validate whether:

1. Style property names are valid at all
2. Style values are safe/well-formed CSS
3. Properties are enabled by the theme's settings
4. Properties are supported by a specific block's `supports` declaration

## Approach: Layered validation pipeline

Restructure the style validator as a sequential pipeline of independent checks. Each layer produces errors or warnings. A flat list of `(path, value)` pairs flows through all layers.

## Input schema

```json
{
  "styles": { "color": { "background": "#ff0000" } },
  "context": "block",
  "blockName": "core/paragraph"
}
```

- `styles` (required): Theme.json styles structure.
- `context` (optional, default `"global"`): One of `block`, `element`, `global`.
- `blockName` (optional): When provided, layer 5 (block supports) runs.

## Output schema

Unchanged: `{ valid, errors, warnings }`. Each error/warning gains a `layer` field:

```json
{
  "property": "color.text",
  "value": "#ff0000",
  "message": "Text color is disabled by the theme.",
  "layer": "theme-settings"
}
```

Layers: `property-validity`, `value-sanitization`, `theme-settings`, `block-supports`, `preset-suggestion`.

## Pipeline layers

### Layer 1: Normalize

Walk the input `styles` object and build a flat list of `(path, value)` pairs.

```
{ "color": { "background": "#ff0000" }, "spacing": { "padding": { "top": "10px" } } }
→ [ ("color.background", "#ff0000"), ("spacing.padding.top", "10px") ]
```

### Layer 2: Property validity

Check each path against `WP_Theme_JSON::VALID_STYLES`. Unknown properties produce errors and are excluded from subsequent layers.

### Layer 3: Value sanitization

For each valid property:
1. Map the style path to its CSS property name using an inverted `WP_Theme_JSON::PROPERTIES_METADATA` lookup.
2. Run `safecss_filter_attr("$css_property: $value")` — the same check core uses in `is_safe_css_declaration()`.
3. Empty result means the value is unsafe/invalid → error.

Preset references (`var(--wp--preset--...)`) pass through `safecss_filter_attr` and are always valid.

### Layer 4: Theme settings gate

For each valid property, look up whether the corresponding theme.json setting is enabled.

The mapping from style paths to settings paths mostly follows a pattern (`typography.X` → `settings.typography.X`), with known exceptions maintained in a static map:

| Style path | Settings path |
|---|---|
| `color.gradient` | `color.customGradient` |
| `color.background` | `color.background` |
| `color.text` | `color.text` |
| `typography.fontSize` | `typography.customFontSize` |
| `spacing.*` (custom values) | `spacing.customSpacingSize` |
| `spacing.margin` | `spacing.margin` |
| `spacing.padding` | `spacing.padding` |
| `spacing.blockGap` | `spacing.blockGap` |

If the setting is explicitly `false`, the property produces an error. Preset references are still allowed when the property itself is enabled but custom values are not (e.g., `customFontSize: false` blocks `16px` but allows `var(--wp--preset--font-size--medium)`).

`appearanceTools: true` expands to its opt-ins per `WP_Theme_JSON::APPEARANCE_TOOLS_OPT_INS`.

### Layer 5: Block supports gate (optional)

Only runs when `blockName` is provided. For each valid property:

1. Get the block type from `WP_Block_Type_Registry`.
2. Call `block_has_support($block_type, $feature_path, false)`.
3. Handle experimental prefixes (`__experimentalBorder` → `border`, `__experimentalFontFamily` → `fontFamily`, etc.).
4. Not supported → error.

### Layer 6: Preset suggestions

Existing logic carried forward. For values that pass all gates, check if they match or are close to theme presets. Uses `WP_Theme_JSON::PRESETS_METADATA` to find applicable presets for each property. Matches produce warnings with `var(--wp--preset--...)` suggestions.

## Leaning on core

- `WP_Theme_JSON::VALID_STYLES` — property validity schema
- `WP_Theme_JSON::PROPERTIES_METADATA` — CSS property ↔ style path mapping (public constant)
- `safecss_filter_attr()` — CSS value sanitization (same as core's `is_safe_css_declaration()`)
- `WP_Theme_JSON::PRESETS_METADATA` — preset-to-settings mapping
- `WP_Theme_JSON::APPEARANCE_TOOLS_OPT_INS` — appearance tools expansion
- `WP_Theme_JSON_Resolver::get_merged_data()->get_settings()` — merged theme settings
- `block_has_support()` — block feature support check
- `WP_Block_Type_Registry` — block type lookup

The plugin maintains only the style-path-to-settings-path exception map and the style-path-to-block-support-path map (for experimental prefix handling).

## File changes

- `includes/class-style-validator.php` — rewrite with pipeline architecture
- `includes/class-abilities.php` — add `blockName` to `validate-styles` input schema
- `tests/Test_Style_Validator.php` — expand from 3 tests to ~15-20

No new files needed.

## Test plan

**Property validity:** valid paths pass, invalid paths error, nested sub-properties validated.

**Value sanitization:** safe CSS passes, `expression()`/`javascript:` error, preset references pass, empty values error.

**Theme settings gate:** enabled settings pass, disabled settings error, `appearanceTools` expansion, custom-value toggles respect preset references.

**Block supports gate:** supported properties pass, unsupported error, experimental prefixes handled.

**Preset suggestions:** close colors warn with suggestion, exact preset matches warn, carried forward from existing tests.

## Decisions

- Theme settings violations are **errors** (not warnings) — the style won't be applied by WordPress, so telling the AI it's invalid is accurate.
- `validate-blocks` stays structural for now. Block-level style validation (piping each block's styles through `validate-styles`) is a follow-up.
- Value validation uses `safecss_filter_attr()` — same gate as the style engine's `filter_declaration()`. Conservative but consistent with core.
