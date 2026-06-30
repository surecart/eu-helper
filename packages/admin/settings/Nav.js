/**
 * Left settings navigation: one tab per module plus the fixed "uninstall" tab.
 * Tabs are anchors so they remain linkable/focusable; selection is handled in JS.
 */
import { __ } from '@wordpress/i18n';

export default function SettingsNav({ modules, active, onSelect }) {
	const renderTab = (id, label, icon) => (
		<a
			key={id}
			href={`#${id}`}
			className={`sceu-nav__item${active === id ? ' is-active' : ''}`}
			aria-current={active === id ? 'true' : undefined}
			onClick={(e) => {
				e.preventDefault();
				onSelect(id);
			}}
		>
			{icon && (
				<span
					className={`dashicons dashicons-${icon}`}
					aria-hidden="true"
				/>
			)}
			<span className="sceu-nav__label">{label}</span>
		</a>
	);

	return (
		<nav
			className="sceu-app__nav"
			aria-label={__('EU Helper modules', 'surecart-eu-helper')}
		>
			{modules.map((m) => renderTab(m.id, m.label, m.icon))}
			{renderTab(
				'uninstall',
				__('Uninstall', 'surecart-eu-helper'),
				'trash'
			)}
		</nav>
	);
}
