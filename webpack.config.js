/**
 * Build config.
 *
 * Starts from @wordpress/scripts' default config — which auto-discovers every
 * `block.json` under the source dir (`--webpack-src-dir=assets/src`) and compiles
 * each block into `build/` — and adds the one non-block entry we have: the React
 * admin settings app (`assets/src/admin/settings/index.js` → `build/admin/settings.js`
 * + `settings.asset.php`). New blocks still need no change here; only additional
 * standalone apps would.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const base = Array.isArray( defaultConfig ) ? defaultConfig[ 0 ] : defaultConfig;

module.exports = {
	...base,
	entry: async ( ...args ) => {
		const discovered =
			typeof base.entry === 'function'
				? await base.entry( ...args )
				: { ...base.entry };
		return {
			...discovered,
			'admin/settings': path.resolve(
				__dirname,
				'assets/src/admin/settings/index.js'
			),
		};
	},
};
