# ADR 0001 — Build blocks with @wordpress/scripts (standard convention)

**Status:** Accepted (POC on `poc/blocks-build-step`)
**Date:** 2026-06-27
**Scope:** the `blocks/` feature — editor scripts, view modules, and block styles

---

## Context

The plugin shipped build-free: block editor scripts were hand-written ES5 against the global
`wp.*` object, and `editor.asset.php` dependency manifests were maintained by hand. That JS was
the least maintainable code in the repo. We want JSX and a real toolchain for blocks, without
losing the "drop-in and run" property for end users.

An earlier cut scoped the build to *just* the editor scripts and emitted them in place
(`blocks/<name>/editor.js`). That worked but was brittle: every new block meant editing the
webpack `entry` map and enumerating new files in `.gitignore`, and generated files sat mixed in
with source. Rejected in favour of the standard convention below.

SureCart's own approach — whole blocks in a source tree, one build that auto-discovers every
`block.json`, output to a wholesale-gitignored folder, registration by scanning that folder — is
simpler to live with and is what `@wordpress/scripts` is designed around. We adopt it.

## Decision

Use the standard `@wordpress/scripts` block convention:

- Block sources live in `assets/src/blocks/<name>/` (`block.json`, `index.js` editor entry,
  `editor.scss`, `style.scss`, `view.js` Interactivity module, `render.php`); shared editor code
  in `assets/src/blocks/shared/`.
- `npm run build` = `wp-scripts build --webpack-src-dir=assets/src`. It auto-discovers every
  `block.json` and compiles each block into `build/blocks/<name>/`, mirroring the source tree.
  No custom webpack config (the repo's `webpack.config.js` only re-exports the default and is
  deletable).
- `Module::register_block()` registers blocks by scanning `build/blocks/*/` for `block.json`.
- `build/` is generated and gitignored wholesale. Release/QA workflows run `npm ci && npm run
  build`, then stage into `.release/` (renamed from `build/`, which now holds plugin output) and
  zip — so the shipped plugin still drops in and runs with no toolchain.

**Adding a block needs no config, ignore, or PHP changes — just a new source folder.**

## Consequences

- **Pro:** JSX editors; auto-generated `*.asset.php`; zero per-block wiring; generated output
  isolated in `build/` and never committed; mirrors SureCart so the mental model transfers.
- **Con:** `view.js` and block CSS now go through the build too (the convention's cost). Keep
  `view.js` authored as a raw Interactivity API module and styles as plain SCSS so the change
  stays a representation change, not a rewrite. Working from a clone now requires
  `npm ci && npm run build`.
- **Neutral:** end users unaffected — the release zip ships pre-built `build/`.

## Build output contract (create-block standard)

For `assets/src/blocks/right-of-withdrawal/` →

| source | built (`build/blocks/right-of-withdrawal/`) | block.json field |
| --- | --- | --- |
| `index.js` (imports `editor.scss`) | `index.js` + `index.asset.php` | `editorScript: file:./index.js` |
| `editor.scss` | `index.css` | `editorStyle: file:./index.css` |
| `style.scss` (auto-detected) | `style-index.css` | `style: file:./style-index.css` |
| `view.js` | `view.js` (+ `view.asset.php`) | `viewScriptModule: file:./view.js` |
| `render.php`, `block.json` | copied verbatim | — |

`withdrawal-form` has no own styles (`editorStyle`/`style` stay the shared `sceu-withdrawal-form`
handle) and no view module — only `index.js` + `render.php` + `block.json`.

## Verification

- JSX compiles cleanly (esbuild, WordPress JSX semantics).
- The new `build/blocks/*` folder-scan registration was confirmed live in the editor at
  sc.local: both blocks register, the editor renders (dynamic heading level, attribute
  reactivity, form preview), styles resolve to `index.css` / `style-index.css`, and no
  block-related console errors appear.
- **Not verified in this environment:** the real `wp-scripts build` (the dependency tree was too
  large to install in the sandbox). Filenames follow the documented create-block contract above;
  run `npm run build` once and confirm `build/blocks/*/` matches — if your wp-scripts version
  emits different style filenames, adjust the two `editorStyle`/`style` refs in
  `right-of-withdrawal/block.json` accordingly.

## Rollback

Reversible: restore the original `blocks/<name>/` sources and the two `register_block_type()`
calls, delete `assets/src/blocks`, `package.json`, `webpack.config.js`, and `build/`. No data,
schema, or API surface is touched.
