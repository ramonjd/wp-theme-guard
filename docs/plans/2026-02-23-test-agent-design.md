# Test Agent Admin Page — Design

## Goal

A standalone WordPress admin page that lets users chat with an AI agent to generate and validate theme styles using wp-theme-guard abilities. Serves as a testing harness for the core validation tools before Big Sky integration.

## Architecture

Server-side agentic loop. The browser sends a prompt, PHP calls the Anthropic Messages API with tool definitions, executes tools via `wp_execute_ability()` directly (no MCP — we're inside WordPress), loops until the agent is done or hits the round cap, then returns the full conversation trace.

### Separation from Core

The agent page is a **test harness**, not part of the core product. The core product is the abilities + validators.

- Agent code lives in `includes/agent/` and `assets/`, isolated from core classes.
- Agent code only loads when `WP_THEME_GUARD_API_KEY` is defined and in admin context.
- Zero overhead on sites that don't define the constant — no menu, no REST routes, no assets.
- To ship the core tools without the agent: remove `includes/agent/` and `assets/`.

```php
// wp-theme-guard.php — conditional load
if ( defined( 'WP_THEME_GUARD_API_KEY' ) && is_admin() ) {
    require_once __DIR__ . '/includes/agent/class-agent-page.php';
    require_once __DIR__ . '/includes/agent/class-agent-rest.php';
    require_once __DIR__ . '/includes/agent/class-anthropic-client.php';
}
```

## Components

### 1. Admin Page

- Menu: **Tools > Theme Guard Agent**
- Capability: `edit_theme_options`
- Shows admin notice with setup instructions if API key constant is missing.

### 2. API Key

- Stored as `WP_THEME_GUARD_API_KEY` constant in `wp-config.php`.
- Never sent to the browser. All Anthropic API calls happen server-side.

### 3. REST Endpoint

**`POST /wp-theme-guard/v1/agent/chat`**
- Input: `{ "message": "string", "conversation": [...] }`
- Sends conversation + system prompt + tool definitions to Anthropic Messages API.
- Executes tool calls via `wp_execute_ability()`.
- Loops up to 5 rounds of tool use.
- Returns: full conversation trace including tool calls and results.

### 4. Tool Definitions

Three tools passed to the Anthropic API, mapping to our abilities:

| Tool name | Ability | Description |
|---|---|---|
| `get_constraints` | `wp-theme-guard/get-constraints` | Get the site's design rules |
| `validate_styles` | `wp-theme-guard/validate-styles` | Validate a styles object |
| `validate_blocks` | `wp-theme-guard/validate-blocks` | Validate block markup |

### 5. System Prompt

Instructs the agent to:
1. Call `get_constraints` first to understand the theme.
2. Generate styles matching theme.json structure.
3. Validate with `validate_styles`.
4. Self-correct on errors/warnings, preferring presets when available.

Derived from existing `CLAUDE.md` content.

### 6. Save as Global Styles

- Button appears after the agent produces a styles object.
- Uses `wp.apiFetch` to save via the core `/wp/v2/global-styles/{id}` REST endpoint — no custom save endpoint needed.
- Deep-merges agent styles with existing global styles to preserve non-overlapping values.
- WordPress creates a revision automatically.
- Link to revisions page so users can restore previous state.

## Frontend UX

### Layout

WordPress-native styling. Minimal custom CSS.

- **Top bar:** page title + "View Revisions" link (to `wp_global_styles` revision history).
- **Main area:** scrollable conversation display.
- **Bottom:** text input + Send button.

### Conversation Display

- User messages: right-aligned.
- Agent messages: left-aligned, rendered as text.
- Tool calls: collapsible sections showing tool name, input, and result.
- Validation errors/warnings: highlighted within tool results.

### States

- **Empty:** welcome text with example prompts.
- **Loading:** spinner with round counter ("Thinking... round 2/5").
- **Error:** clear message (missing API key, API failure, max rounds hit).
- **Result:** styles displayed, "Save as Global Styles" button enabled.

## Data Flow

```
User prompt → JS POST /agent/chat
  → PHP: build Anthropic request (system prompt + tools + conversation)
  → PHP: call Anthropic Messages API
  → Claude responds with tool_use → PHP: wp_execute_ability() → append result → loop
  → Max 5 rounds or Claude sends text response
  → Return conversation trace to JS
  → JS renders messages, tool calls, results

"Save as Global Styles" click
  → JS: wp.apiFetch GET /wp/v2/global-styles/{id} (current styles)
  → JS: deep-merge agent styles with existing
  → JS: wp.apiFetch POST /wp/v2/global-styles/{id} (merged styles)
  → WordPress creates revision automatically
  → JS: fetch latest revision, show confirmation + revisions link
```

## File Structure

```
includes/
  class-abilities.php            # Core — unchanged
  class-style-validator.php      # Core — unchanged
  class-block-validator.php      # Core — unchanged
  class-schema-provider.php      # Core — unchanged
  agent/                         # Test harness
    class-agent-page.php         # Admin page registration + HTML
    class-agent-rest.php         # Chat REST endpoint (Anthropic proxy)
    class-anthropic-client.php   # Anthropic API client + tool loop
assets/
  agent-page.js                  # Conversation UI
  agent-page.css                 # Minimal styling
```

## Error Handling

- **Missing API key:** admin notice with `wp-config.php` instructions, chat input disabled.
- **Anthropic API errors:** displayed in conversation as error message.
- **Max rounds exceeded:** show partial results + "max iterations reached" note.
- **Save failure:** WordPress error displayed.

## Testing

- Unit tests for `class-anthropic-client.php` with mocked HTTP responses.
- Unit tests for `class-agent-rest.php` with mocked ability execution.
- Manual testing via the admin page.
