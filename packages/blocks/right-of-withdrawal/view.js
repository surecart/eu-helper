/**
 * Right of Withdrawal — front-end behaviour, WordPress Interactivity API store.
 *
 * No build step: this is a native ES module. WordPress core resolves the bare
 * `@wordpress/interactivity` specifier via its import map, so it ships as-is.
 *
 * The markup (form, modal/inline panels, orders, requests, confirmation) is
 * server-rendered in render.php with data-wp-* directives. This module only
 * supplies the reactive state, the event actions, and the accessibility
 * behaviour (focus trap, Escape, focus restore, body scroll lock) that the
 * declarative directives can't express on their own.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

// The trigger that opened the current panel, per block root, so focus can be
// restored on close. Keyed by the interactive root element.
const lastTrigger = new WeakMap();
// Whether each block root's panel was open on the previous watch run, so the
// focus/scroll-lock side effects fire only on open/close transitions.
const wasOpen = new WeakMap();
// The panel shown on the previous watch run, so focus can also move on
// panel-to-panel transitions within an already-open modal (form -> review -> …).
const lastPanel = new WeakMap();

// `sc-button` is a SureCart web component that delegates focus to its internal
// button; it must be in this list or the focus trap computes the wrong
// first/last boundary and Tab escapes the dialog.
const FOCUSABLE =
	'a[href], button:not([disabled]), sc-button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Map a REST error payload to a user-facing status string.
 *
 * @param {object} body Parsed JSON error body.
 * @param {object} i18n Localised strings from the Interactivity store.
 * @return {string}
 */
function restErrorMessage(body, i18n) {
	const code = body && body.code ? body.code : '';
	const map = {
		sceu_not_eligible: i18n.notEligible,
		rest_cookie_invalid_nonce: i18n.invalidNonce,
		sceu_module_disabled: i18n.moduleDisabled,
		sceu_too_many: i18n.tooMany,
		sceu_no_selection: i18n.noSelection,
	};
	if (map[code]) {
		return map[code];
	}
	if (body && body.message) {
		return body.message;
	}
	return i18n.error;
}

// Build the current selection from context: per-item quantities for itemised
// orders, plus whole-order ids for orders with no line-item detail.
// Resolve the line item a control belongs to by its tagged id, rather than
// trusting getContext().item inside a nested data-wp-each (whose event handlers
// can bind to the wrong iteration). Line item ids are globally unique.
function findItem(ctx, el) {
	const node = el && el.closest ? el.closest('[data-sceu-item]') : el;
	const id = node && node.dataset ? node.dataset.sceuItem : '';
	if (!id) {
		return null;
	}
	const orders = ctx.orders || [];
	for (let i = 0; i < orders.length; i++) {
		const lineItems = orders[i].lineItems || [];
		for (let j = 0; j < lineItems.length; j++) {
			if (lineItems[j].id === id) {
				return lineItems[j];
			}
		}
	}
	return null;
}

// Read the selection straight from the rendered DOM (the exact values the
// customer sees). This is the source of truth for review + submit: in a nested
// data-wp-each, the per-row item proxies the inputs bind to diverge from the
// form-level context.orders, so reading context here records the wrong data.
function readSelection(root) {
	const orders = [];
	if (!root) {
		return orders;
	}
	root.querySelectorAll('.sceu-orders__item').forEach(function (orderEl) {
		const orderId = orderEl.getAttribute('data-sceu-order') || '';
		const cb = orderEl.querySelector('.sceu-orders__cb');
		const checked = cb ? cb.checked : false;
		const labelEl = orderEl.querySelector('.sceu-orders__label');
		const metaEl = orderEl.querySelector('.sceu-orders__meta');
		const label = labelEl ? labelEl.textContent.trim() : '';
		const meta = metaEl ? metaEl.textContent.trim() : '';
		const rows = Array.prototype.slice.call(
			orderEl.querySelectorAll('.sceu-item')
		);

		// Whole-order orders have no item rows: a checked box selects them.
		if (rows.length === 0) {
			if (checked) {
				orders.push({ id: orderId, label, meta, whole: true, items: [] });
			}
			return;
		}

		const items = [];
		rows.forEach(function (r) {
			const input = r.querySelector('.sceu-item__input');
			const nameEl = r.querySelector('.sceu-item__name');
			const id = input ? input.getAttribute('data-sceu-item') : '';
			const qty = input ? parseInt(input.value, 10) || 0 : 0;
			if (id && qty > 0) {
				items.push({
					id,
					name: nameEl ? nameEl.textContent.trim() : '',
					qty,
				});
			}
		});
		if (items.length) {
			orders.push({ id: orderId, label, meta, whole: false, items });
		}
	});
	return orders;
}

// The block root for an action. Prefer the event's target (reliable in both
// click and form-submit handlers); fall back to getElement(), which does not
// resolve consistently inside a form-submit handler.
function blockRoot(event) {
	if (event && event.target && event.target.closest) {
		const r = event.target.closest('.sceu-row');
		if (r) {
			return r;
		}
	}
	const el = getElement() && getElement().ref;
	return el && el.closest ? el.closest('.sceu-row') : null;
}

// Move focus into the visible panel. Prefer the modal title (it carries the
// dialog name via aria-labelledby, so screen readers announce the new step);
// otherwise focus the first focusable control in the visible panel.
function focusPanel(root) {
	const title = root.querySelector(
		'.sceu-modal:not([hidden]) .sceu-modal__title'
	);
	if (title) {
		title.focus();
		return;
	}
	const scope = root.querySelector(
		'.sceu-modal:not([hidden]), .sceu-row__panel:not([hidden])'
	);
	if (scope) {
		const target = scope.querySelector(FOCUSABLE);
		if (target) {
			target.focus();
		}
	}
}

const { state, actions } = store('surecart-eu-helper', {
	state: {
		get isOpen() {
			return getContext().panel !== 'none';
		},
		get isForm() {
			return getContext().panel === 'form';
		},
		get isRequests() {
			return getContext().panel === 'requests';
		},
		get isReview() {
			return getContext().panel === 'review';
		},
		get isConfirmation() {
			return getContext().panel === 'confirmation';
		},
		get heading() {
			const ctx = getContext();
			if ('review' === ctx.panel) {
				return ctx.reviewTitle;
			}
			return 'requests' === ctx.panel ? ctx.requestsTitle : ctx.modalTitle;
		},
		get showItems() {
			const ctx = getContext();
			return (
				!!ctx.order.selected &&
				!ctx.order.wholeOrder &&
				(ctx.order.lineItems || []).length > 0
			);
		},
		get incDisabled() {
			const item = getContext().item;
			return (item.qty || 0) >= (item.max || 0);
		},
		get decDisabled() {
			return (getContext().item.qty || 0) <= 0;
		},
		get itemQtyLabel() {
			return (state.i18n.qtyLabel || '%s').replace('%s', getContext().item.name);
		},
		get incLabel() {
			return (state.i18n.incLabel || '%s').replace('%s', getContext().item.name);
		},
		get decLabel() {
			return (state.i18n.decLabel || '%s').replace('%s', getContext().item.name);
		},
		get itemAnnounce() {
			const item = getContext().item;
			return (state.i18n.qtyAnnounce || '%1$s %2$s %3$s')
				.replace('%1$s', item.name)
				.replace('%2$s', String(item.qty || 0))
				.replace('%3$s', String(item.max || 0));
		},
		get hasConfirmed() {
			const ctx = getContext();
			return (ctx.confirmedDetails || []).length > 0;
		},
	},

	actions: {
		openForm() {
			getContext().panel = 'form';
		},
		openRequests() {
			getContext().panel = 'requests';
		},
		close() {
			const ctx = getContext();
			ctx.panel = 'none';
			ctx.status = '';
		},
		onOverlayClick(event) {
			// Only a click on the overlay itself (not the dialog) closes it.
			if (event.target === getElement().ref) {
				actions.close();
			}
		},
		onDialogKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				actions.close();
				return;
			}
			if (event.key !== 'Tab') {
				return;
			}
			const dialog = getElement().ref;
			const items = Array.prototype.slice
				.call(dialog.querySelectorAll(FOCUSABLE))
				.filter(function (el) {
					return el.offsetParent !== null;
				});
			if (!items.length) {
				return;
			}
			const first = items[0];
			const last = items[items.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		},
		toggleOrder(event) {
			const order = getContext().order;
			order.selected = event.target.checked;
			// Selecting an order REVEALS its items at quantity 0 — the customer
			// then adds exactly what they want to withdraw. (We do not pre-fill
			// to full quantity, which made it look like everything was already
			// selected.) Deselecting clears any quantities entered.
			if (!order.selected && !order.wholeOrder) {
				(order.lineItems || []).forEach(function (li) {
					li.qty = 0;
				});
			}
		},
		setItemQty(event) {
			const item = findItem(getContext(), event.target);
			if (!item) {
				return;
			}
			let v = parseInt(event.target.value, 10);
			if (isNaN(v)) {
				v = 0;
			}
			item.qty = Math.max(0, Math.min(item.max || 0, v));
		},
		incItem(event) {
			const item = findItem(getContext(), event.target);
			if (item && (item.qty || 0) < (item.max || 0)) {
				item.qty = (item.qty || 0) + 1;
			}
		},
		decItem(event) {
			const item = findItem(getContext(), event.target);
			if (item && (item.qty || 0) > 0) {
				item.qty = (item.qty || 0) - 1;
			}
		},
		setName(event) {
			getContext().name = event.target.value;
		},
		setReason(event) {
			getContext().reason = event.target.value;
		},
		review(event) {
			event.preventDefault();
			const ctx = getContext();
			const sel = readSelection(blockRoot(event));
			if (!sel.length) {
				ctx.status = state.i18n.selectOne;
				return;
			}
			// Render the review as a single text string (one order per line),
			// shown via data-wp-text. A data-wp-each repeater does not render a
			// client-populated list here, but plain data-wp-text bindings (name,
			// email, status) update reliably — so we use one of those.
			ctx.reviewSummary = sel
				.map(function (o) {
					const itemsText = o.whole
						? state.i18n.entireOrder
						: o.items
							.map(function (it) {
								return it.qty > 1 ? it.qty + '× ' + it.name : it.name;
							})
							.join(', ');
					return o.label + ' — ' + itemsText;
				})
				.join('\n');
			ctx.status = '';
			ctx.panel = 'review';
		},
		back() {
			const ctx = getContext();
			ctx.status = '';
			ctx.panel = 'form';
		},
		*submit(event) {
			event.preventDefault();
			const ctx = getContext();
			const sel = readSelection(blockRoot(event));
			const items = [];
			const wholeOrderIds = [];
			sel.forEach(function (o) {
				if (o.whole) {
					wholeOrderIds.push(o.id);
					return;
				}
				o.items.forEach(function (it) {
					items.push({
						order_id: o.id,
						line_item_id: it.id,
						quantity: it.qty,
					});
				});
			});
			if (!items.length && !wholeOrderIds.length) {
				ctx.status = state.i18n.selectOne;
				return;
			}
			ctx.submitting = true;
			ctx.submitLabel = state.i18n.sending;
			ctx.status = '';
			try {
				const res = yield fetch(state.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.nonce,
					},
					body: JSON.stringify({
						items,
						order_ids: wholeOrderIds,
						name: ctx.name,
						email: ctx.email,
						reason: ctx.reason,
					}),
				});
				if (res.ok) {
					// Reflect the new request in the UI without a page reload:
					// append its row, decrement the remaining quantities (dropping
					// emptied items/orders), and flip the visibility flags.
					let okBody = {};
					try {
						okBody = yield res.json();
					} catch (parseError) {
						okBody = {};
					}
					const r = okBody && okBody.request ? okBody.request : null;
					if (r) {
						// Itemised summary shown on the confirmation screen.
						ctx.confirmedDetails = (r.details || []).slice();
						// Prepend so the newest request appears at the top, matching
						// the server-rendered list's newest-first order.
						ctx.requests = [
							{
								id: r.id,
								statusLabel: r.statusLabel,
								ordersText:
									state.i18n.ordersLabel +
									': ' +
									(r.orders || []).join(', '),
								detailsText: (r.details || []).join('; '),
								dateText: r.date
									? state.i18n.submittedLabel + ': ' + r.date
									: '',
								isReceived: r.status === 'received',
								isResolved: r.status === 'resolved',
								isRejected: r.status === 'rejected',
							},
						].concat(ctx.requests);
						ctx.hasRequests = true;
					}

					// Per-line-item quantities just submitted.
					const taken = {};
					items.forEach(function (i) {
						taken[i.line_item_id] =
							(taken[i.line_item_id] || 0) + i.quantity;
					});
					const wholeTaken = {};
					wholeOrderIds.forEach(function (id) {
						wholeTaken[id] = true;
					});

					const nextOrders = [];
					(ctx.orders || []).forEach(function (o) {
						if (o.wholeOrder) {
							if (!wholeTaken[o.id]) {
								nextOrders.push(
									Object.assign({}, o, { selected: false })
								);
							}
							return;
						}
						const remaining = (o.lineItems || [])
							.map(function (li) {
								const max = Math.max(
									0,
									(li.max || 0) - (taken[li.id] || 0)
								);
								return Object.assign({}, li, {
									max,
									qty: 0,
									availText: state.i18n.availableTemplate.replace(
										'%d',
										String(max)
									),
								});
							})
							.filter(function (li) {
								return li.max > 0;
							});
						if (remaining.length) {
							nextOrders.push(
								Object.assign({}, o, {
									selected: false,
									lineItems: remaining,
								})
							);
						}
					});

					ctx.orders = nextOrders;
					ctx.hasOrders = nextOrders.length > 0;
					ctx.reviewSummary = '';
					ctx.panel = 'confirmation';
				} else {
					let body = {};
					try {
						body = yield res.json();
					} catch (parseError) {
						body = {};
					}
					ctx.status = restErrorMessage(body, state.i18n);
				}
			} catch (e) {
				ctx.status = state.i18n.error;
			} finally {
				ctx.submitting = false;
				ctx.submitLabel = state.i18n.submit;
			}
		},
	},

	callbacks: {
		// Runs on mount and whenever context.panel changes. Handles the focus and
		// scroll-lock side effects that accompany opening/closing a panel.
		onPanelChange() {
			const root = getElement().ref;
			const ctx = getContext();
			const open = ctx.panel !== 'none';
			const was = wasOpen.get(root) || false;
			const prevPanel = lastPanel.get(root);

			if (ctx.display === 'modal') {
				document.body.classList.toggle('sceu-modal-open', open);
			}

			if (open && !was) {
				// Opening: remember the trigger so focus can return to it on close.
				lastTrigger.set(root, document.activeElement);
				focusPanel(root);
			} else if (open && was && ctx.panel !== prevPanel) {
				// Switched step within an open modal (form -> review -> confirmation):
				// move focus to the new step so keyboard/SR users aren't stranded on a
				// now-hidden control.
				focusPanel(root);
			} else if (!open && was) {
				const trigger = lastTrigger.get(root);
				if (trigger && document.body.contains(trigger)) {
					trigger.focus();
				}
			}

			wasOpen.set(root, open);
			lastPanel.set(root, ctx.panel);
		},
	},
});
