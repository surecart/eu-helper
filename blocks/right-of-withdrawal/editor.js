/**
 * Right of Withdrawal — block editor registration.
 *
 * No build step: written against the global `wp` packages. The block is
 * server-rendered (dynamic), so save() returns null; edit() shows a static
 * preview plus the text-override controls in the inspector. All visible
 * defaults are translatable so a translation plugin can localize per country.
 */
(function (wp) {
  "use strict";

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var __ = wp.i18n.__;
  var registerBlockType = wp.blocks.registerBlockType;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var components = wp.components;
  var PanelBody = components.PanelBody;
  var TextControl = components.TextControl;
  var TextareaControl = components.TextareaControl;
  var SelectControl = components.SelectControl;

  var DEFAULTS = {
    heading: __("Right of Withdrawal", "surecart-eu-helper"),
    intro: __(
      "You have the right to withdraw from your purchase within 14 days of receiving your order.",
      "surecart-eu-helper",
    ),
    buttonLabel: __("Withdraw from contract", "surecart-eu-helper"),
    modalTitle: __("Request a withdrawal", "surecart-eu-helper"),
    confirmButtonLabel: __("Confirm withdrawal", "surecart-eu-helper"),
    confirmationMessage: __(
      "Thank you. Your withdrawal request has been received and a confirmation has been emailed to you.",
      "surecart-eu-helper",
    ),
  };

  // Outline-style shield icon to match the WordPress inserter icon set
  // (line drawing, currentColor), rather than a solid Dashicon.
  var icon = el(
    "svg",
    {
      viewBox: "0 0 24 24",
      width: 24,
      height: 24,
      xmlns: "http://www.w3.org/2000/svg",
    },
    el("path", {
      fill: "none",
      stroke: "currentColor",
      strokeWidth: 1.6,
      strokeLinejoin: "round",
      strokeLinecap: "round",
      d: "M12 3.25l6.75 2.4v5.05c0 4.3-2.88 7.42-6.75 8.8-3.87-1.38-6.75-4.5-6.75-8.8V5.65L12 3.25z",
    }),
  );

  registerBlockType("surecart-eu-helper/right-of-withdrawal", {
    icon: icon,
    category: "surecart-customer-dashboard",
    edit: function (props) {
      var a = props.attributes;
      var set = props.setAttributes;
      var scheme = a.colorScheme || "auto";
      var container = a.container === "none" ? "plain" : "card";
      var blockProps = useBlockProps({
        className:
          "sceu-row sceu-row--editor sceu-row--scheme-" +
          scheme +
          " sceu-row--" +
          container,
      });

      function field(key, label, isTextarea) {
        var Control = isTextarea ? TextareaControl : TextControl;
        return el(Control, {
          key: key,
          label: label,
          value: a[key] || "",
          placeholder: DEFAULTS[key],
          onChange: function (v) {
            var patch = {};
            patch[key] = v;
            set(patch);
          },
        });
      }

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          { key: "inspector" },
          el(
            PanelBody,
            {
              title: __("Withdrawal text", "surecart-eu-helper"),
              initialOpen: true,
            },
            field("heading", __("Heading", "surecart-eu-helper")),
            el(SelectControl, {
              key: "headingLevel",
              label: __("Heading style", "surecart-eu-helper"),
              value: a.headingLevel || "h3",
              options: [
                {
                  value: "h2",
                  label: __("Heading 2 (large)", "surecart-eu-helper"),
                },
                { value: "h3", label: __("Heading 3", "surecart-eu-helper") },
                { value: "h4", label: __("Heading 4", "surecart-eu-helper") },
                { value: "h5", label: __("Heading 5", "surecart-eu-helper") },
                { value: "h6", label: __("Heading 6", "surecart-eu-helper") },
                { value: "p", label: __("Normal text", "surecart-eu-helper") },
              ],
              onChange: function (v) {
                set({ headingLevel: v });
              },
            }),
            field("intro", __("Explanation", "surecart-eu-helper"), true),
            field("buttonLabel", __("Button label", "surecart-eu-helper")),
            field("modalTitle", __("Form title", "surecart-eu-helper")),
            field(
              "confirmButtonLabel",
              __("Confirm button label", "surecart-eu-helper"),
            ),
            field(
              "confirmationMessage",
              __("Confirmation message", "surecart-eu-helper"),
              true,
            ),
          ),
          el(
            PanelBody,
            {
              title: __("Appearance", "surecart-eu-helper"),
              initialOpen: false,
            },
            el(SelectControl, {
              key: "colorScheme",
              label: __("Color scheme", "surecart-eu-helper"),
              value: a.colorScheme || "auto",
              options: [
                {
                  value: "auto",
                  label: __("Auto (match theme)", "surecart-eu-helper"),
                },
                { value: "light", label: __("Light", "surecart-eu-helper") },
                { value: "dark", label: __("Dark", "surecart-eu-helper") },
              ],
              onChange: function (v) {
                set({ colorScheme: v });
              },
            }),
            el(SelectControl, {
              key: "container",
              label: __("Container", "surecart-eu-helper"),
              value: a.container || "card",
              options: [
                {
                  value: "card",
                  label: __("Card (bordered)", "surecart-eu-helper"),
                },
                {
                  value: "none",
                  label: __("Borderless", "surecart-eu-helper"),
                },
              ],
              onChange: function (v) {
                set({ container: v });
              },
            }),
          ),
        ),
        el(
          "div",
          blockProps,
          el(
            "div",
            { className: "sceu-row__notice" },
            el(
              "div",
              { className: "sceu-row__text" },
              el(
                a.headingLevel || "h3",
                { className: "sceu-row__heading" },
                a.heading || DEFAULTS.heading,
              ),
              el(
                "p",
                { className: "sceu-row__intro" },
                a.intro || DEFAULTS.intro,
              ),
            ),
            el(
              "div",
              { className: "sceu-row__actions" },
              el(
                "button",
                {
                  type: "button",
                  className:
                    "sceu-row__trigger sceu-btn sceu-btn--primary wp-element-button",
                  disabled: true,
                },
                a.buttonLabel || DEFAULTS.buttonLabel,
              ),
            ),
          ),
          el(
            "p",
            { className: "sceu-row__editor-note" },
            __(
              "This block only appears for eligible EU consumers with recent orders. Visitors who do not qualify will not see it.",
              "surecart-eu-helper",
            ),
          ),
        ),
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
