=== SureCart EU Helper ===
Contributors: wpcrafter
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.6.0
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

= 1.6.0 =
This is a major feature release building on the initial Right of Withdrawal module — partial withdrawals, a public self-serve form, product/collection exclusions, a SureCart-style settings app, and a series of compliance and correctness fixes.

**Partial withdrawal**

* Customers can withdraw specific items — and specific quantities — from an order, not only whole orders. Selecting an order reveals its line items, each with a quantity control and a product thumbnail. Orders without retrievable line-item detail fall back to a whole-order withdrawal (a store can opt to withhold those via the `sceu_offer_order_without_line_items` filter).
* Per-item remaining tracking: once items/quantities have been requested, only the not-yet-requested quantities remain selectable, and an order keeps appearing until nothing is left to withdraw.
* Itemised throughout — the review step, the customer confirmation email, the merchant notification, the on-dashboard request history, and the admin Withdrawal Log all show the exact items and quantities (including "1 ×", as § 356a BGB requires). The log's "Withdrawing" column shows each item as "2 of 3 × Product" with a Partial / Full order badge, and the CSV export gains a matching column.
* The quantity picker is a single accessible segmented control: steppers carry product-specific labels, disable at the available minimum/maximum, and announce each change to screen readers.

**Public withdrawal form (block + shortcode)**

* A new front-end form lets any customer start a withdrawal without logging in. Add it with the **Withdrawal Request Form** block or the `[sceu_withdrawal_form]` shortcode. The customer enters their email + order number; on a match they see the order's items and can withdraw specific items/quantities (exclusions still apply). If no order matches, they get a free-text box describing what they'd like to withdraw, which is sent to you.
* The form is unauthenticated, so it's defended with a logged-out nonce, a per-IP rate limit, and a honeypot; order lookups run server-side with your existing SureCart token, and which field was wrong is never revealed. An optional **Spam protection** setting (off by default) adds **reCAPTCHA v3**, reusing the keys under SureCart → Settings → Spam Protection & Security.
* Inline, accessible per-field errors (required / invalid email / required order number), with distinct messages for expired sessions and rate-limiting, and the email is prefilled for logged-in visitors (still editable). A leading "#" pasted from an invoice (e.g. "#TEST-0010") is stripped before matching. Verified guest requests are linked to their SureCart customer and show the real customer name.

**Excluded products & collections**

* Under **EU Helper → Settings** you can exclude whole **product collections** and/or search and add **individual products**; excluded items never appear in the withdrawal form, while the rest of an order stays withdrawable. Enforced server-side, never trusting the browser. The customer-facing form makes no extra SureCart API calls — the excluded set is precomputed and cached, refreshed in the background and via a manual "Refresh excluded product list" button.

**Settings & admin experience**

* The settings screen is a SureCart-style app: header bar, left module navigation, content cards, the store brand colour, and SureCart's design tokens, with full dark-theme support. The Withdrawal Requests screen uses the same shell — styled Sync/Export buttons, a clean full-width table, on-brand status banners, and Screen Options + bulk actions.
* Withdrawal emails show the quantity for every item, attribute the footer to your store, and drop the internal reference line.

**Compliance & correctness fixes**

* **Availability is protected end-to-end.** Reactivating a request — resetting it to *pending* or reversing a *declined* one — re-checks the order's remaining quantities first and is refused (with an explanatory notice) if the same units are already covered by another request, so the same physical item can't be refunded twice. Enforced per order, so it also covers guest requests.
* **Unverified guest submissions are held out of the actionable queue.** A public-form submission whose email and order number never matched a real order is flagged **"Identity not verified"** and offers only *Verify & accept* or *Mark declined* — never a one-click resolve — and carries no order detail, so it can't block a genuine re-request.
* **Partial refunds are detected during sync.** Sync reads authoritative charge totals and classifies each order as not / partially / fully refunded; a partial or mixed refund leaves the status untouched and flags the row **"Refund detected — review"**, since a refunded amount can't be attributed automatically to one of several requests on the same order.
* **More accurate consumer-vs-business detection.** An order with an *invalid* EU VAT number is treated as a consumer order (matching how SureCart taxes it), and a valid VAT captured on a recent checkout counts as a business signal even when the customer record hasn't caught up — so the "consumers only" audience rule shows and hides the button correctly.
* **GDPR tooling.** Alongside permanent deletion (which re-enables re-requesting of the affected orders), you can **anonymize** a request — stripping name, email, IP, and reason while keeping the transactional record — as a bulk or per-row action.

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
