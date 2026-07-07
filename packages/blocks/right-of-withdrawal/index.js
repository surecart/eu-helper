/**
 * Right of Withdrawal — block registration entry.
 *
 * Declarative, SureCart-style: block.json is the single source of truth for
 * name/title/category/attributes; this entry only adds the JS-only settings
 * (custom SVG icon, edit, save) and hands the metadata to registerBlocks().
 *
 * Imports both stylesheets so the build extracts them: `editor.scss` → index.css
 * (block.json `editorStyle`) and `style.scss` → style-index.css (block.json
 * `style`). @wordpress/scripts only emits a stylesheet that is actually imported
 * — it is NOT auto-detected by filename — so dropping the `style.scss` import
 * silently ships the block with no front-end CSS (e.g. the modal lays out inline).
 */
import './editor.scss';
import './style.scss';
import edit from './edit';
import icon from './icon';
import metadata from './block.json';
import { registerBlocks } from '../register-block';

const { name } = metadata;

export const settings = {
	icon,
	edit,
	save: () => null,
};

export { metadata, name };

registerBlocks([{ metadata, settings }]);
