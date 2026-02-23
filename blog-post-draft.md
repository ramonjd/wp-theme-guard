# Teaching AI Agents Your Theme's Design Rules

AI agents can generate CSS. What they can't do — at least not yet — is understand *your* theme. They don't know which colors are in your palette, which spacing scale you use, or which blocks support which style properties. Without that context, agent-generated styles break the design system the moment they're applied.

WP Theme Guard is a WordPress plugin that solves this by exposing the site's design constraints as abilities — structured tools that any AI agent can call to learn the rules, generate compatible styles, and validate the result before saving.

## The problem

When you ask an AI to "make the headings blue," it has to guess. It might produce `color: #0000ff` when your theme's blue is `var(--wp--preset--color--primary)`. It might set `font-size: 24px` when your theme uses a spacing scale. It might apply a border radius to a block that doesn't support borders.

These aren't hallucinations — they're reasonable guesses made without information. The agent doesn't have access to your theme.json, your block registry, or your design tokens. It's styling blind.

## The approach: abilities as design guardrails

WP Theme Guard registers three abilities through the WordPress Abilities API:

**`get-constraints`** returns everything an agent needs to understand the design system: the color palette, font sizes, spacing presets, block registry, layout settings, and a structure guide that explains how to target styles at the global, block, and element levels. It also documents the `css` escape hatch for styling that goes beyond declarative theme.json properties.

**`validate-styles`** checks a style object against the theme.json schema, theme settings (are custom colors allowed? custom font sizes?), and block supports (does this block accept typography? borders?). When a custom value is close to an existing preset, it suggests the preset alternative. The `css` property — raw CSS with `&` nesting syntax — is accepted without preset constraints since it's an author-level escape hatch.

**`validate-blocks`** checks block markup against the block registry and nesting rules. It catches unregistered blocks, invalid nesting, and structural issues before they reach the editor.

These abilities are available through any transport: MCP, REST, or direct PHP. An agent connected via Claude Code's MCP integration calls the same underlying validation as one making REST API calls from a custom client.

## The agent workflow

A well-behaved style agent follows a predictable pattern:

1. **Learn** — Call `get-constraints` to discover the design system. This returns presets, the targeting hierarchy (global → block → element → block/element), and the complete style property reference.

2. **Read** — Fetch the current user global styles so changes can be merged rather than replacing what's already saved. The agent needs to know the starting point.

3. **Generate** — Produce a theme.json `styles` object. Use preset references (`var(--wp--preset--color--primary)`) when the design system has what's needed. Use the `css` property for anything beyond declarative properties — grid layouts, custom selectors, scroll behavior.

4. **Validate** — Call `validate-styles` for each target separately. One call per block or global scope. The validator returns errors (must fix) and warnings (suggested improvements, like using a preset instead of a raw value).

5. **Save** — Write the validated styles to the global styles CPT. WordPress creates a revision automatically.

The key insight is that validation happens per-fragment. The agent validates `core/button` styles in one call and `core/heading` styles in another. Each call checks against that specific block's supports and the theme's settings. The fragments accumulate into a complete styles tree.

## What the structure guide teaches

The `get-constraints` response includes a structure guide — a reference document designed for agents, not humans. It contains:

- **A complete example** showing how global, block, element, and block-element styles compose into a single tree.
- **A targeting hierarchy** explaining the four levels of specificity and when to use each.
- **Common aliases** mapping casual terms ("buttons," "container," "text") to their canonical block names (`core/buttons`, `core/group`, `core/paragraph`).
- **The `css` property reference** with nesting syntax, placement rules, and examples at each level.

This structure guide is the part that makes agents effective. Without it, an agent might know the color palette but not how to target a link element inside a group block. The guide makes the style tree navigable.

## The css escape hatch

Not everything fits into declarative theme.json properties. Grid layouts, scroll behavior, pseudo-elements, complex selectors — these need raw CSS. The `css` property accepts a CSS string at any level of the style tree:

```json
{
  "styles": {
    "css": ".wp-site-blocks { scroll-margin-top: 100px; }",
    "blocks": {
      "core/group": {
        "css": "& .custom-layout { display: grid; gap: 1rem; }"
      }
    }
  }
}
```

The validator accepts `css` without preset constraints. It's the author's responsibility — human or AI — to write valid CSS. The `&` syntax references the current selector, following the same nesting pattern that WordPress core processes.

## Ideas for expansion

**Semantic validation.** The current validator checks structural correctness — does this property exist, does this block support it, is the value well-formed. A semantic layer could check whether styles *make sense*: sufficient contrast ratios, readable font sizes, consistent spacing rhythms. This moves from "is it valid?" to "is it good?"

**Style generation from intent.** Today, agents generate style objects and validate them. A higher-level ability could accept natural language intent ("make the site feel more spacious") and return a validated style object directly, using the constraints as guardrails during generation rather than as a post-check.

**Cross-block consistency.** When an agent styles `core/button` and `core/heading` independently, there's no check that the results are visually coherent. A consistency ability could analyze a set of style fragments together and flag mismatches — different color treatments, inconsistent spacing, clashing typography.

**Template and pattern awareness.** Styles interact with templates. A group block styled with tight padding might look fine in isolation but break when used inside a cover block with its own padding. Expanding validation to consider template context would catch these composition issues.

**Revision diffing.** The global styles CPT creates revisions on every save. An ability that diffs two revisions and explains what changed — in design terms, not JSON terms — would help agents understand the history and avoid undoing intentional choices.

## Try it

WP Theme Guard requires WordPress 7.0+ (trunk) with the Abilities API. Setup is straightforward: start a wp-env environment, connect your agent via MCP or REST, and start generating theme-compatible styles.

The plugin is designed to be infrastructure, not a product. It provides the validation layer so that any AI agent — whether it's a chat interface, a code assistant, or a headless automation — can work within the design system instead of against it.

The full source, setup instructions, and integration examples are in the repository README.
