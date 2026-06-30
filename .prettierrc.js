/**
 * Prettier config for the JS/JSX under `packages/`.
 *
 * Inherits @wordpress/scripts' defaults (tabs, single quotes, 80 cols, es5
 * trailing commas, `arrowParens: always`) but turns OFF `parenSpacing` — the
 * WordPress-only rule that pads the inside of parens and brackets
 * (`( foo )`, `[ a, b ]`, `( ! x )`). We use standard, compact JS spacing
 * instead: `(foo)`, `[a, b]`, `(!x)`.
 *
 * Honoured by BOTH `npm run format` (Prettier resolves this file) and
 * `npm run lint:js` (the @wordpress/eslint-plugin `prettier/prettier` rule
 * merges this over its defaults), so the two never disagree.
 */
module.exports = {
	...require('@wordpress/prettier-config'),
	parenSpacing: false,
};
