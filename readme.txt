=== SureCart EU Helper ===
Contributors: wpcrafter
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.5.8
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
override the heading, explanation, button label, form title, confirm button
label, and confirmation message per block instance in the editor.

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

= 1.5.8 =
* **Dark mode fixes.** With SureCart's dark theme active, the Right of Withdrawal UI now renders correctly throughout. The notice heading and description stay readable instead of showing low-contrast dark text; the "Request a withdrawal" modal follows the dark theme instead of opening as a bright white panel; and the form fields, labels, order list, and the public withdrawal form are all readable — field labels and inputs no longer pick up the theme's light-mode colours (dark text on dark, or stray white input boxes). Stores using the light theme — or the block's explicit Light/Dark colour scheme — are unaffected.

= 1.5.7 =
* **Admin UI polish.** The Withdrawal Requests screen now uses the same SureCart-style shell as the Settings page — header bar, store brand colour, styled Sync/Export buttons, clean full-width table with natural column widths, and on-brand status banners — instead of the plain WordPress admin look. Rows are tighter: "Delete permanently" is now a hover action under the request's date rather than a column. The settings tabs also remember which module you were on after saving, and "Settings" moves to the bottom of the EU Helper menu (below the module pages).

= 1.5.6 =
* **Withdrawal email polish.** The confirmation/notification emails now show the **quantity** for every withdrawn item (the customer email previously hid "1 ×"). The footer is attributed to your store ("sent automatically by {Store}") instead of the plugin name, and the internal "Request reference" line — an opaque ID that wasn't shown anywhere in the admin — has been removed from both emails. Orders are identified by their order number, as before.

= 1.5.5 =
* **Settings redesigned as a SureCart-style app.** The EU Helper settings now have a SureCart-style header bar and a left module navigation (one entry per module — Right of Withdrawal today, Peppol and others to come), with content cards, the store brand colour, and SureCart's design tokens — so it feels like a native part of SureCart. The Withdrawal Log remains its own submenu and only appears when the Right of Withdrawal module is enabled.
* The settings UI now loads even when a module is disabled (the enable toggle only governs front-end behaviour); active nav items match SureCart (colour change, not a filled box); sub-sections use a titled, divided layout; and the WordPress admin footer is hidden to match SureCart.

= 1.3.0 =
* **Public withdrawal form (block + shortcode).** A new front-end form lets any customer start a withdrawal without logging in — handy when shoppers expect a self-serve form. Add it with the **Withdrawal Request Form** block, or the `[sceu_withdrawal_form]` shortcode on non-block sites. The customer enters their email + order number; if they match a real order, they see its items and can withdraw specific items/quantities (exclusions still apply). If no order matches, they get a free-text box to describe what they'd like to withdraw from, which is sent to you to handle.
* Security: the form is unauthenticated, so it's defended with a logged-out nonce, a per-IP rate limit, and a honeypot. The order's contents are only shown when the submitted email matches the order, and which field was wrong is never revealed. Order lookups run server-side with your existing SureCart token — credentials are never exposed to the browser.

= 1.2.0 =
* **Excluded products & collections.** Some goods are excluded from the statutory right of withdrawal (e.g. perishable, made-to-order, sealed hygiene, or digital items). Under **EU Helper → Settings** you can now exclude whole **product collections** (the scalable way to exclude many products at once) and/or search and add **individual products**. Excluded items never appear in the withdrawal form; the rest of an order stays withdrawable, and an order whose items are all excluded disappears. Enforced on the server too, never trusting the browser.
* Performance: the customer-facing form does **no** extra SureCart API calls for exclusions — the excluded set is precomputed and cached. Collections are resolved to their member products in the background (on save, on a schedule, and via a manual "Refresh excluded product list" button), never during a customer page load.
* This remains a merchant-configured policy tool: some legal exclusions are conditional (e.g. sealed goods only once unsealed), which the plugin cannot detect — you decide which products/collections are never offered for withdrawal.

= 1.1.0 =
* **Partial withdrawal.** Customers can now withdraw specific items — and specific quantities — from an order, instead of only whole orders. Selecting an order reveals its line items, each with a quantity stepper and a product thumbnail so items are easy to tell apart. Orders without retrievable line-item detail continue to work as a whole-order withdrawal.
* **Per-item remaining tracking.** Once items/quantities have been requested, only the not-yet-requested quantities remain selectable; an order keeps appearing until nothing is left to withdraw.
* **Itemised throughout.** The review step, the customer confirmation email, the merchant notification, the on-dashboard request history, and the admin Withdrawal Log all show the exact items and quantities. The log's "Withdrawing" column shows each item as "2 of 3 × Product" with a Partial / Full order badge, and the CSV export gains a matching column.
* **Accessibility.** Quantity steppers carry product-specific labels, disable at the available minimum/maximum, and announce each change to screen readers.
* **Durable log + GDPR delete.** The log is append-only for normal use (status changes manage workflow); an admin-only "Delete permanently" action is available for GDPR erasure / test cleanup, which also re-enables re-requesting of the affected order(s).
* **Customer request list.** "Your withdrawal requests" is consistently newest-first, and finished requests age off the dashboard after a grace period (default 30 days, filter `sceu_request_history_days`); pending requests stay until handled.
* **Clearer "Emails sent" log column.** Each email now reads "Customer: Sent" / "Customer: Not sent" with an explanatory tooltip, so a staging site with no working mail setup is not mistaken for a failure of the request itself.
* **Resend notifications.** Each log entry has a per-recipient "Resend" / "Try again" link for the customer and merchant emails. The re-sent email is rebuilt from the stored request, so its "Received at" timestamp still reflects when the withdrawal was originally requested; the log updates to show the new delivery result.

= 1.0.4 =
* Publish a downloadable release ZIP via GitHub Actions on each GitHub Release.

= 1.0.3 =
* Show declined requests on customer dashboard.

= 1.0.2 =
* Make the withdrawal 2-step.
* Show the reason in the table and csv export.

= 1.0.1 =
* Right of Withdrawal: the withdrawal form is now a two-step process to meet the
  German "Widerrufsbutton" requirement (§ 356a BGB). Step 1 collects the
  declaration (name, orders, optional reason); step 2 shows a review of the
  declaration and a separate confirmation button before anything is submitted.
* Added a merchant-configurable "Confirm button label" block attribute
  (default "Confirm withdrawal").

= 1.0.0 =
Initial release: modular EU compliance helper with the Right of Withdrawal module.
