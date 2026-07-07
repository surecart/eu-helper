/**
 * Withdrawal Request Form — block registration entry.
 *
 * Declarative, SureCart-style: block.json is the single source of truth for
 * name/title/category/icon/attributes; this entry only adds the JS-only
 * settings (edit, save) and hands the metadata to registerBlocks(). Styles come
 * from the shared `sceu-withdrawal-form` handle referenced in block.json.
 */
import edit from './edit';
import metadata from './block.json';
import { registerBlocks } from '../register-block';

const { name } = metadata;

export const settings = {
	edit,
	save: () => null,
};

export { metadata, name };

registerBlocks([{ metadata, settings }]);
