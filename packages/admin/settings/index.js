import { createRoot } from '@wordpress/element';
import SettingsApp from './App';

const root = document.getElementById('sceu-settings-root');
if (root) {
	createRoot(root).render(<SettingsApp />);
}
