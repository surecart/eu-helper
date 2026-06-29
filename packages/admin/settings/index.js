import { createRoot } from '@wordpress/element';
import SettingsApp from './app';

const root = document.getElementById('sceu-settings-root');
if (root) {
	createRoot(root).render(<SettingsApp />);
}
