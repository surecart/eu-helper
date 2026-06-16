=== SureCart EU Helper ===
Contributors: wpcrafter
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
Initial release: modular EU compliance helper with the Right of Withdrawal module.
