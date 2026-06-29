/**
 * Register/unregister EU Helper blocks from their block.json metadata.
 *
 * Mirrors SureCart's own register-block helper so our blocks are authored the
 * same way: each block module exports its block.json `metadata` plus a JS-only
 * `settings` object (icon, edit, save), and the raw metadata object is handed
 * straight to registerBlockType(). That keeps block.json the single source of
 * truth for name/title/category/attributes — the JS never restates them.
 */
import {
	getBlockType,
	registerBlockType,
	unregisterBlockType,
} from '@wordpress/blocks';

/**
 * Register a list of block modules ({ metadata, settings }).
 *
 * @param {Array<Object>} blocks Block modules to register.
 */
export const registerBlocks = (blocks = []) =>
	(blocks || []).forEach(registerBlock);

/**
 * Unregister a list of block modules.
 *
 * @param {Array<Object>} blocks Block modules to unregister.
 */
export const unregisterBlocks = (blocks = []) =>
	(blocks || [])
		.filter((block) => block?.metadata?.name)
		.map((block) => block.metadata.name)
		.forEach((name) => getBlockType(name) && unregisterBlockType(name));

/**
 * Register a single block module.
 *
 * @param {Object} block            The block module.
 * @param {Object} block.metadata   Parsed block.json.
 * @param {Object} block.settings   JS-only settings (icon, edit, save).
 */
const registerBlock = (block) => {
	if (!block) {
		return;
	}

	const { metadata, settings } = block;

	registerBlockType(
		{
			...metadata,
			textdomain: 'surecart-eu-helper',
		},
		{ ...settings }
	);
};
