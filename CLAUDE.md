# Project guidance for Claude Code

**SureCart EU Helper** — a modular WordPress plugin that adds EU merchant-compliance
features to SureCart stores (Module 1: Right of Withdrawal; Module 2: E-Invoicing/Peppol).
PHP 7.4+, WordPress 6.6+, hard dependency on the SureCart plugin.

---

## How to work here (process)

1. **Research first, then change.** Before editing, read the surrounding code and follow
   the conventions already in the file — match its comment density, naming, escaping, and
   idiom. This codebase is internally consistent; new code should be indistinguishable from
   what's there. When a change spans modules, trace the call path before touching anything.
2. **Smallest change that's correct.** Prefer surgical edits over refactors. Don't introduce
   new patterns, libraries, or abstractions when an existing one fits — there is no build
   step and no Composer/npm dependency to lean on (see below).
3. **Preserve safe-degradation.** Every SureCart API touch and every public endpoint already
   fails closed. Keep it that way; never remove a guard, nonce, capability check, or
   `try/catch` to "simplify".
4. **State outcomes honestly.** If something is untested or skipped, say so.

---

## SureCart-first principle

This plugin is a guest in SureCart's house. It should feel native, never bolted-on.

- **Depend, don't reimplement.** Read customer/order/product data through SureCart's own PHP
  models (`\SureCart\Models\User|Customer|Order|Product|ProductCollection|Account`). The
  plugin never handles the API key — SureCart's stored token authenticates these calls.
- **Gate on the stable sentinel.** Detect SureCart with `sceu_surecart_is_active()`
  (checks `defined( 'SURECART_PLUGIN_FILE' )`), not on model class names — classes move
  between major versions, the constant doesn't. Boot on `plugins_loaded`.
- **Guard every model call.** Wrap SureCart model access in `try/catch ( \Throwable )` and
  treat null/false as "no match" so a SureCart change degrades gracefully instead of fatals.
  Use `class_exists()` where the class may be absent.
- **Mirror SureCart's UI, don't invent one.** Front-end form fields mirror SureCart's
  `sc-form-control` anatomy (`.sceu-form-control` → `__label` / `__required` / `__input`).
  Use the `<sc-button>` web component for actions. The admin settings page reuses SureCart's
  app shell (header bar, brand colour, left module nav, content cards) and design tokens
  (`--sceu-primary: #388051`, `--sceu-border`, `--sceu-radius`, …). Pull the live store brand
  colour via `Merchant\BrandColor`.
- **Follow SureCart's dark theme.** SureCart's store-wide dark mode (Settings →
  Design & Branding → "Dark") is signalled by `body.surecart-theme-dark` (light is
  `surecart-theme-light`) — a persistent store setting, **not** OS
  `prefers-color-scheme`, so don't gate dark styles on that media query. SureCart
  only darkens its own surfaces; plain-HTML blocks keep inheriting a light-mode
  text colour, so front-end CSS must supply its own readable colour under
  `body.surecart-theme-dark`. Note themes colour bare headings at specificity
  0,1,0 (e.g. Astra `.entry-content :where(h3)`), which beats a colour merely
  *inherited* from a container — give headings an explicit colour at ≥0,2,0
  (e.g. `.sceu-row .sceu-row__heading`). The same trap hits bare `label`
  (Astra colours it directly, so nested text inherits the wrong colour) and
  `input[type=…]` (themes force a solid background/colour, 0,1,1) — scope form
  controls under the block root (`.sceu-row` / `.sceu-wf`, 0,2,0) so the block's
  transparent, scheme-coloured fields win.

---

## Architecture

- **No build step.** Runs as-is when dropped in `wp-content/plugins/`. There is **no
  Composer, no npm, no bundler**. A hand-rolled PSR-4 autoloader in
  `surecart-eu-helper.php` maps `SureCartEuHelper\Foo\Bar` → `src/Foo/Bar.php` (with a
  traversal-safe class-name regex). Assets are plain CSS/JS, enqueued directly; blocks ship
  pre-built. Don't add a toolchain.
- **Singleton + module registry.** `Plugin::instance()->init()` registers modules
  (`ModuleRegistry`) and boots only the enabled ones. Each feature is a self-contained module
  under `src/Modules/<Name>/` implementing `ModuleInterface`
  (`id()`, `label()`, `description()`, `settings_fields()`, `disclaimer()`, `boot()`).
  Third parties add modules via the `sceu_register_modules` action.
- **Settings: one option, nested.** All config lives in a single `sceu_settings` option
  (`Settings::OPTION`), structured `['modules' => [...], '<module_id>' => [...]]`. Access only
  through the `Settings` static API (`is_module_enabled()`, `get()`, `for_module()`, `all()`).
  A module declares its fields via `settings_fields()` and the admin page renders them
  generically — don't hand-build settings forms.
- **Migrations.** Bump `SCEU_VERSION`; the one-time `sceu_upgrade` action (fired by
  `Plugin::maybe_upgrade()` on version change) is where modules self-heal their schema.
  Tables also self-heal at runtime (e.g. `LogTable::maybe_create()`).
- **Blocks** (`blocks/`): API v3, server-rendered (`render.php`, `"html": false`), in the
  `surecart-customer-dashboard` category. Eligibility is checked server-side; ineligible
  visitors get an empty string so no assets enqueue. Interactivity is the WordPress
  **Interactivity API** (`view.js`, store namespace `surecart-eu-helper`, `data-wp-*`
  directives). Shared runtime data via `wp_interactivity_state()`; per-instance state via
  `data-wp-context`.

---

## Naming conventions

- **PHP constants:** `SCEU_` prefix (`SCEU_VERSION`, `SCEU_DIR`, `SCEU_URL`, `SCEU_FILE`).
- **Options / transients / hooks / class-constant keys:** `sceu_` prefix
  (`sceu_settings`, `sceu_version`, `sceu_upgrade`, `sceu_register_modules`,
  transients like `sceu_orders_{hash}`). Filters: `sceu_cache_ttl`, `sceu_trust_proxy_headers`,
  `sceu_request_ip`, etc.
- **REST:** namespace `surecart-eu-helper/v1`.
- **Text domain:** `surecart-eu-helper` (always pass it to i18n functions).
- **Classes:** PascalCase, one per file, namespace mirrors directory. Global-namespace helper
  functions (callable from block `render.php`) live in `src/helpers.php`, prefixed `sceu_`.
- **CSS/JS:** BEM-like, `sceu-` prefixed — block `sceu-wf`, element `sceu-wf__submit`,
  modifier/state `sceu-wf__submit.is-loading` / `sceu-row--card`. Compounds:
  `sceu-form-control__*`, `sceu-modal__*`, `sceu-excl__*`, `sceu-badge--received`.

---

## Coding standards

- **WordPress Coding Standards by practice** (no phpcs.xml is shipped): tabs for indentation,
  snake_case functions/vars, PascalCase classes, **Yoda conditions**, full docblocks.
- **Escape on output, sanitize on input.** `esc_html()` / `esc_attr()` / `esc_url()` /
  `esc_html__()` at output; `sanitize_email()` / `sanitize_text_field( wp_unslash( … ) )` /
  `sanitize_textarea_field` at input. REST args declare `sanitize_callback`.
- **DB:** use `$wpdb` prepared statements and `wp_json_encode()`; annotate unavoidable direct
  queries with a scoped `// phpcs:ignore WordPress.DB.…` and a reason.
- **Security idioms (don't weaken):**
  - Authenticated REST: verify the `wp_rest` nonce (`X-WP-Nonce` header or `_wpnonce`).
  - Admin REST: `permission_callback` with real capability checks
    (`current_user_can( 'edit_sc_products' ) || current_user_can( 'manage_options' )`).
  - **Guest/public endpoints** (`permission_callback => __return_true`) are defended *in the
    handler*: logged-out nonce, per-IP rate limit (`sceu_rate_limit_ip()` keyed on
    un-forgeable `REMOTE_ADDR`), honeypot field, per-user submission cooldown, and
    **never reveal which field was wrong** (uniform `{ found: false }`).
  - Always re-validate eligibility/ownership server-side; never trust client claims.
- **Keep hot paths cheap.** Expensive SureCart enumeration (e.g. building product-exclusion
  lookups) runs in admin/background and is cached in transients — never on the front-end
  request path.

---

## Accessibility (required, not optional)

Recent work made this a first-class concern — hold the line:

- Inputs: `aria-required`, `aria-describedby` → error region, `aria-invalid` toggled on
  validation failure. In-flight controls set `aria-busy`.
- Modals: `role="dialog" aria-modal="true" aria-labelledby`, a real **focus trap** (the
  focusable selector must include `sc-button`), focus moved into each panel on
  open/step-change (prefer the titled heading with `tabindex="-1"`), and focus restored to
  the trigger on close. Escape closes.
- Listbox/combobox widgets follow the ARIA pattern (roles, `aria-expanded`,
  `aria-activedescendant`, arrow-key navigation).
- Status changes announce via `role="status" aria-live="polite"` regions; visually-hidden
  text via `.screen-reader-text` / `.sceu-sr-only`.
- Respect `prefers-reduced-motion` — gate or disable animations.

---

## Versioning & release

- Version lives in **three places that must stay in sync**: the plugin header `Version:`,
  the `SCEU_VERSION` constant (both in `surecart-eu-helper.php`), and `Stable tag:` in
  `readme.txt`.
- The `readme.txt` `== Changelog ==` entries are **merchant-facing prose** — a bold lead-in
  summarising the user-visible change, not a raw commit list.
- Releases: pushing a published GitHub release runs `.github/workflows/release.yml`, which
  stages the slug folder (honouring `.distignore`) and attaches `eu-helper.zip`.

---

## Commit policy

Commits and PRs are authored solely by the logged-in developer — keep `git config`
author untouched. **No Claude/Anthropic attribution of any kind**: no `Co-Authored-By`
trailer, no "Generated with Claude Code" line, no icon. Imperative subject, body explains
the *why*.
