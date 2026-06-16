<?php
/**
 * Global helper functions (no namespace) so they are safe to call from block
 * render.php, which executes in the global namespace.
 *
 * @package SureCartEuHelper
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sceu_order_admin_url' ) ) {
	/**
	 * Build a deep link to a single order inside the SureCart admin.
	 *
	 * An individual SureCart order opens via the `edit` action with the order
	 * id as a query arg (e.g. admin.php?page=sc-orders&action=edit&id=<id>).
	 * Filterable in case the route changes.
	 *
	 * @param string $order_id SureCart order id.
	 * @return string Absolute admin URL.
	 */
	function sceu_order_admin_url( $order_id ) {
		$default = add_query_arg(
			array(
				'page'   => 'sc-orders',
				'action' => 'edit',
				'id'     => (string) $order_id,
			),
			admin_url( 'admin.php' )
		);

		/**
		 * Filter the admin deep link for a SureCart order.
		 *
		 * @param string $url      Default admin order URL.
		 * @param string $order_id The order id.
		 */
		return (string) esc_url( apply_filters( 'sceu_order_admin_url', $default, (string) $order_id ) );
	}
}
