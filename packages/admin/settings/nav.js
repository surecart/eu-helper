/**
 * Left settings navigation: one tab per module plus the fixed "uninstall" tab.
 * Tabs are anchors so they remain linkable/focusable; selection is handled in JS.
 */
import { __ } from '@wordpress/i18n';

export default function SettingsNav({ modules, active, onSelect }) {
	const renderTab = (id, label) => (
		<a
			key={id}
			href={`#${id}`}
			className={`sceu-nav__item${active === id ? ' is-active' : ''}`}
			onClick={(e) => {
				e.preventDefault();
				onSelect(id);
			}}
		>
			{label}
		</a>
	);

	return (
		<nav
			className="sceu-app__nav"
			aria-label={__('EU Helper modules', 'surecart-eu-helper')}
		>
			{modules.map((m) => renderTab(m.id, m.label))}
			{renderTab('uninstall', __('Uninstall', 'surecart-eu-helper'))}
		</nav>
	);
}
