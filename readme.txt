=== SureCart EU Helper ===
Contributors: wpcrafter
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular EU merchant-compliance helper for SureCart. Module 1: Right of Withdrawal.

== Description ==

SureCart EU Helper is a **modular** plugin: enable only the EU-compliance
features you need, and more modules can be added over time. It reads the
logged-in SureCart customer through SureCart's own PHP models, which
authenticate with the token SureCart already stores — this plugin never handles
your API key directly.

= Module 1: Right of Withdrawal =

Adds a **Right of Withdrawal** block to the customer area. It appears **only**
for eligible customers:

* a logged-in, resolvable SureCart customer,
* with a billing country in the EU,
* who has at least one order inside your look-back window,
* and (optionally) who is a consumer rather than a VAT-registered business.

Everyone else sees nothing. When eligible, the customer sees a short heading, a
one-line explanation, and a button. Clicking it opens a pre-filled form (their
name, email, and a multi-select of recent orders — each showing its contents so
it's recognisable, not just an order number) in an **accessible modal** or
**inline**, your choice. The buttons use your **SureCart store's primary
colour**. On submit:

* the request is recorded in an on-site log (optional),
* the customer receives a timestamped confirmation email,
* you (the merchant) receive a notification email with a **deep link to each
  order** in your SureCart admin, so you can process the cancellation/refund.

This is a **request + notification** workflow — no money moves automatically.

= Settings =

Enabling the module shows a friendly reminder that compliance with
right-of-withdrawal law is the merchant's responsibility (the plugin is a tool,
not legal advice). Under **EU Helper → Settings**:

* **Look-back window (days)** — default 14; raise it (e.g. 16–17) when shipped
  goods start the clock on delivery.
* **Apply to** — all customers, or only customers without a VAT number.
* **Merchant notification email** — defaults to your SureCart store email.
* **Form display** — modal (accessible dialog) or inline.

Every request is recorded in an on-site log (IP, time, customer, selected
orders), viewable under **EU Helper → Withdrawal Log** with CSV export. There you
can mark each request Resolved / Declined / Pending and run a best-effort
**Sync statuses** against SureCart. The log also powers duplicate-request
prevention and the customer's on-dashboard request status.

= Translating the text =

All visible strings ship as translatable defaults (text domain
`surecart-eu-helper`), so WPML, Polylang, or Loco Translate can localise the
heading, explanation, button, form, and emails per country. You can also
override the heading, explanation, button label, form title, and confirmation
message per block instance in the editor.

= Troubleshooting =

Add the shortcode `[sceu_debug]` to any page and view it **while logged in as
the customer you're testing**. It prints the resolved customer (country, VAT,
recent-order count) and the exact eligibility decision. Lookups are cached ~60s
(filter `sceu_cache_ttl`).

= Extending with your own modules =

Modules are a registry. Add one without touching the core:

`
add_action( 'sceu_register_modules', function ( $registry ) {
    $registry->register( new My_EU_Module() );
} );
`

Your class implements `SureCartEuHelper\Modules\ModuleInterface`
(`id()`, `label()`, `description()`, `settings_fields()`, `boot()`). Its settings
fields render on the settings page automatically, and `boot()` runs only when
the module is enabled.

== Changelog ==

= 1.4.0 =
* Redesigned block: heading + supporting text on the left, action buttons right-aligned on the same tight row (stacks on mobile).
* "View my requests" is now a proper button (no underline), and the request status pill shows inline. Fixed the modal close button (square, centered icon, even padding).
* New block settings: Color scheme (Auto / Light / Dark — Auto matches the theme) and Container (Card or Borderless).

= 1.3.0 =
* Withdrawn orders no longer reappear: an order the customer has already requested is removed from the withdrawal list, and the server rejects duplicate requests for the same order.
* Customers can now see their submitted requests and statuses on the dashboard via a "View my requests" view; when no orders remain to withdraw, the block shows the request status instead of the withdraw button.
* The request log is now always on (it powers duplicate prevention and the dashboard status) — the on/off toggle was removed.
* Admin log: each request can be marked Resolved / Declined / Pending, and a new "Sync statuses" button best-effort-checks SureCart for refunded/cancelled orders. Added an "Emails sent" indicator (customer + merchant) for delivery diagnostics.
* New block setting: choose the heading style (H2–H6 or normal text) so it no longer inherits an oversized theme heading.

= 1.2.0 =
* Fix: the order links in the merchant email and the request log now open the order directly (`admin.php?page=sc-orders&action=edit&id=…`) instead of the orders list.
* The block now appears under SureCart's **Customer Dashboard** block category (where it belongs), not **Widgets**.
* New outline-style block icon that matches the WordPress inserter icon set.

= 1.1.1 =
* Fix: the block stopped displaying after 1.1.0 because recent orders were fetched with `paginate()`, which proved unreliable when chained after relation expansions. Reverted to the proven `get()` call so the notice renders again.

= 1.1.0 =
* Performance: the block's front-end CSS/JS now load **only** on pages where the block actually renders for an eligible customer (enqueued at render time) — nothing loads on other pages, or for non-eligible visitors on the customer dashboard.
* The order multi-select now shows each order's **contents** (product names, with quantities, truncated to one line) so buyers recognise it — resolved via SureCart's documented `checkout.line_items` / `line_item.price` / `price.product` expansion, with tolerant collection handling.
* Buttons now use the SureCart store's primary colour via SureCart's own `--sc-color-primary-500` / `--sc-color-primary-text` CSS variables, with a `sceu_primary_color` override filter and theme-primary fallback.
* Recent orders are fetched with `paginate()` (SureCart's `get()` caps at 10) so customers with many recent orders aren't truncated; page size filterable via `sceu_orders_per_page`.
* Settings page shows a friendly merchant-responsibility/liability disclaimer (the plugin is a tool, not legal advice).
* Security: CSV export hardened against spreadsheet formula injection; per-user submission cool-down (filter `sceu_submission_cooldown`) to prevent email-spam abuse.

= 1.0.0 =
* Initial release.
* Modular core: a module registry (`sceu_register_modules`), a single settings
  page, and a shared SureCart customer gateway (transient-cached, fail-safe).
* Module — Right of Withdrawal: customer-area block shown only to eligible EU
  consumers with recent orders; accessible modal/inline pre-filled form with a
  recent-orders multi-select that shows each order's contents (product names,
  truncated to one line) so it's recognisable; buttons use the SureCart store's
  primary colour; server-side re-validation of eligibility and order ownership;
  customer confirmation email + merchant notification with per-order SureCart
  admin deep links; optional on-site request log with admin viewer and CSV
  export; merchant-responsibility disclaimer on the settings page.
* `[sceu_debug]` diagnostic shortcode.

== Upgrade Notice ==

= 1.4.0 =
Polished block layout with a tight action row, fixed buttons/modal, and new Color scheme + Container settings.

= 1.3.0 =
Prevents duplicate withdrawals, adds customer-facing request status on the dashboard, merchant status controls + sync, and a heading-style setting.

= 1.2.0 =
Order links now open the order directly; block moved to the Customer Dashboard category with a matching outline icon.

= 1.1.1 =
Fixes the block not displaying in 1.1.0. Update immediately.

= 1.1.0 =
Shows order contents in the form, matches your SureCart brand colour, loads assets only where the block renders, and adds security hardening. Recommended.

= 1.0.0 =
Initial release: modular EU compliance helper with the Right of Withdrawal module.
