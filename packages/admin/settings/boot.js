/**
 * Bootstrap data localized by Admin\SettingsPage into `window.sceuSettingsApp`:
 * the module + field schema, REST paths, and brand/email placeholders. Read once
 * here so the rest of the app imports a parsed object instead of poking the
 * global from several places.
 */
export const boot = window.sceuSettingsApp || {};

export const MODULES = Array.isArray(boot.modules) ? boot.modules : [];
