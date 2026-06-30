/**
 * Bootstrap data localized by Admin\SettingsPage into `window.sceuSettingsApp`:
 * the module + field schema, REST paths, and brand/email placeholders. Read once
 * here so the rest of the app imports a parsed object instead of poking the
 * global from several places. Frozen because it's a shared read-only singleton.
 *
 * Shape (sent by Admin\SettingsPage::app_bootstrap()):
 *
 * @typedef {Object} SceuBoot
 * @property {string}        restPath                 Settings REST path (POST to save).
 * @property {string}        productSearchPath        Admin product-search REST path.
 * @property {string}        refreshExclusionsPath    REST path (POST) to rebuild the exclusion cache.
 * @property {Array<Object>} modules                  Module + field schema.
 * @property {Array<Object>} collections              SureCart collections ({id,name,products_count}).
 * @property {string}        merchantEmailPlaceholder Pre-filled merchant notification email.
 * @property {boolean}       removeData               Whether "remove all data on uninstall" is on.
 */

/** @type {SceuBoot} */
export const boot = Object.freeze(window.sceuSettingsApp || {});

export const MODULES = Array.isArray(boot.modules) ? boot.modules : [];
