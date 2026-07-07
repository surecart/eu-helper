/**
 * Build config.
 *
 * @wordpress/scripts ships an ARRAY of two configs: `[ scriptConfig, moduleConfig ]`.
 * The script config auto-discovers every `block.json` under the source dir
 * (`--webpack-src-dir=packages`) and compiles classic entries (block `editorScript`,
 * plus our standalone admin app). The **module** config (`experiments.outputModule`)
 * compiles block `viewScriptModule` entries — our Interactivity `view.js`. Both must
 * be kept: collapsing the array to just the script config silently stops front-end
 * view modules from building, so interactive blocks render but their clicks no-op.
 *
 * So we map over both configs and add the one non-block entry we have — the React
 * admin settings app (`packages/admin/settings/index.js` → `build/admin/settings.js`
 * + `settings.asset.php`) — to the script config only, passing the module config
 * through untouched. New blocks still need no change here.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

const configs = Array.isArray(defaultConfig) ? defaultConfig : [defaultConfig];

module.exports = configs.map((config) => {
	// Leave the script-module config (which builds Interactivity view.js) as-is.
	if (config.experiments?.outputModule) {
		return config;
	}
	return {
		...config,
		entry: async (...args) => {
			const discovered =
				typeof config.entry === 'function'
					? await config.entry(...args)
					: { ...config.entry };
			return {
				...discovered,
				'admin/settings': path.resolve(
					__dirname,
					'packages/admin/settings/index.js'
				),
			};
		},
	};
});
