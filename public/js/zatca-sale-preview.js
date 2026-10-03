(function (root) {
    'use strict';
    // Small controller shared with offline tests; the server always verifies again.
    function controller(io) {
        let reviewed = null, sequence = 0, expires = 0, busy = false;
        function invalidate() { reviewed = null; expires = 0; io.token(''); }
        return {
            async review() {
                const current = ++sequence;
                invalidate(); busy = true;
                const before = io.fingerprint();
                io.message('Checking ZATCA totals…');
                try {
                    const result = await io.request();
                    if (current !== sequence) { return; }
                    if (before !== io.fingerprint()) { throw new Error('The sale changed. Review ZATCA totals again.'); }
                    const totals = result.totals || {};
                    if (typeof result.token !== 'string' || !result.token || !Number.isInteger(result.expires_at)
                        || result.expires_at <= io.now() || !['subtotal', 'discount', 'vat', 'payable'].every(k => /^\d+\.\d{2}$/.test(totals[k]))
                        || (totals.charges !== undefined && !/^\d+\.\d{2}$/.test(totals.charges))) {
                        throw new Error('Invalid preview response. Please review again.');
                    }
                    reviewed = before; expires = result.expires_at;
                    io.token(result.token);
                    io.message('Reviewed — Subtotal: ' + totals.subtotal + ' SAR; Discount: ' + totals.discount
                        + ' SAR; Charges including VAT: ' + (totals.charges || '0.00')
                        + ' SAR; Final VAT: ' + totals.vat + ' SAR; Payable: ' + totals.payable + ' SAR.');
                } catch (error) {
                    if (current === sequence) { invalidate(); io.message(error.message || 'Preview failed. Please try again.'); }
                } finally { if (current === sequence) { busy = false; } }
            },
            changed() { ++sequence; busy = false; invalidate(); io.message('Sale changed. Review ZATCA totals again before saving this fiscal sale.'); },
            canSubmit(online = true) {
                if (!online && !io.offlineSafe()) {
                    invalidate();
                    io.message('Connect to the server before saving this Phase 2 sale. Only drafts can be queued offline; offline receipts cannot carry verified ZATCA fiscal evidence.');
                    return false;
                }
                if (!io.required()) { return true; }
                if (busy || reviewed !== io.fingerprint() || expires <= io.now()) {
                    invalidate(); io.message('Review the current ZATCA totals before saving.'); return false;
                }
                return true;
            }
        };
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = controller; }
    if (!root.document) { return; }
    const instances = new WeakMap();
    root.ZatcaSalePreview = {
        canSubmit(form, online = true) { return !instances.has(form) || instances.get(form).canSubmit(online); },
        allowsOfflineSync(serialized) {
            if (typeof serialized !== 'string') { return false; }
            const status = new URLSearchParams(serialized).get('sale_status');
            return status === '3';
        }
    };
    function mount() {
        root.document.querySelectorAll('[data-zatca-sale-preview]').forEach(panel => {
            const form = panel.closest('form');
            if (!form || instances.has(form)) { return; }
            let checkoutKey = form.querySelector('[name="idempotency_key"]');
            if (!checkoutKey) {
                checkoutKey = root.document.createElement('input');
                checkoutKey.type = 'hidden'; checkoutKey.name = 'idempotency_key';
                form.appendChild(checkoutKey);
            }
            if (!checkoutKey.value) {
                checkoutKey.value = root.crypto?.randomUUID?.()
                    || ('zatca-' + Date.now() + '-' + Math.random().toString(36).slice(2));
            }
            const token = panel.querySelector('[name="zatca_sale_preview"]');
            const messages = form.querySelectorAll('[data-zatca-result]');
            const value = name => form.querySelector('[name="' + name + '"]')?.value || '';
            const payload = () => {
                const body = new FormData(form);
                // Add Sale enables this control just before AJAX submission. Its
                // enabled/disabled state must not by itself invalidate the review.
                if (!body.has('paid_amount') && !body.has('paid_amount[]')) {
                    form.querySelectorAll('[name="paid_amount"], [name="paid_amount[]"]').forEach(field => body.append(field.name, field.value));
                }
                return body;
            };
            const entries = () => Array.from(payload().entries())
                .filter(([key, val]) => key !== 'zatca_sale_preview'
                    // Add Sale and POS append these payment-plan metadata fields
                    // only when submitting. Fiscal amounts remain in the form
                    // fingerprint and the server verifies the pricing plan.
                    && key !== 'enable_installment' && !key.startsWith('installment_plan[')
                    // Browsers create a new empty File (with a fresh timestamp)
                    // for an unselected upload field every time FormData is read.
                    && (typeof val === 'string' || val.name !== '' || val.size !== 0))
                .sort(([a], [b]) => a.localeCompare(b));
            const instance = controller({
                fingerprint: () => JSON.stringify(entries().map(([key, val]) => [key, typeof val === 'string' ? val : [val.name, val.size, val.lastModified]])),
                token: val => { token.value = val; },
                message: text => { messages.forEach(message => { message.textContent = text; }); },
                now: () => Math.floor(Date.now() / 1000),
                offlineSafe: () => value('sale_status') === '3',
                required: () => ['1', '5'].includes(value('sale_status')),
                request: async () => {
                    const abort = new AbortController();
                    const timer = setTimeout(() => abort.abort(), 30000);
                    try {
                        const response = await fetch(panel.dataset.zatcaSalePreview, {
                            method: 'POST', body: payload(), credentials: 'same-origin',
                            headers: { Accept: 'application/json' }, signal: abort.signal
                        });
                        const result = await response.json();
                        if (!response.ok) { throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Unable to review ZATCA totals.'); }
                        return result;
                    } finally { clearTimeout(timer); }
                }
            });
            instances.set(form, instance);
            form.querySelectorAll('[data-zatca-review]').forEach(button => {
                button.addEventListener('click', () => instance.review());
            });
            ['input', 'change'].forEach(event => form.addEventListener(event, e => {
                if (e.target !== token) { instance.changed(); }
            }));
        });
    }
    if (root.document.readyState === 'loading') { root.document.addEventListener('DOMContentLoaded', mount); }
    else { mount(); }
})(typeof window !== 'undefined' ? window : globalThis);
