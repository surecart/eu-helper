/**
 * Outline-style shield icon for the inserter — a line drawing using
 * currentColor to match the WordPress icon set, rather than a solid Dashicon.
 * Lives in JS (not block.json) because block.json `icon` only accepts a
 * Dashicon slug, not custom SVG.
 */
const icon = (
	<svg
		viewBox="0 0 24 24"
		width={24}
		height={24}
		xmlns="http://www.w3.org/2000/svg"
	>
		<path
			fill="none"
			stroke="currentColor"
			strokeWidth={1.6}
			strokeLinejoin="round"
			strokeLinecap="round"
			d="M12 3.25l6.75 2.4v5.05c0 4.3-2.88 7.42-6.75 8.8-3.87-1.38-6.75-4.5-6.75-8.8V5.65L12 3.25z"
		/>
	</svg>
);

export default icon;
