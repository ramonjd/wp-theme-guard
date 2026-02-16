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
