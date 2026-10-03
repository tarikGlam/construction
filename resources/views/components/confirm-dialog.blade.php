{{-- Universal SalePro Confirmation Modal Component --}}
<div class="modal fade" id="salepro-confirm-modal" tabindex="-1" role="dialog" aria-labelledby="salepro-confirm-title" aria-modal="true" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 440px;">
        <div class="modal-content shadow-lg border-0" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <div class="d-flex align-items-center">
                    <div id="salepro-confirm-icon-wrapper" class="mr-3" style="width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                        <span id="salepro-confirm-icon">⚠</span>
                    </div>
                    <h5 class="modal-title font-weight-bold" id="salepro-confirm-title" style="font-size: 18px; color: #222; margin: 0;">
                        {{ __('db.Confirmation') ?? 'Confirmation' }}
                    </h5>
                </div>
                <button type="button" class="close text-muted" data-dismiss="modal" aria-label="Close" style="font-size: 24px; padding: 0; margin-top: -12px;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-4 py-3 text-muted" id="salepro-confirm-body" style="font-size: 15px; line-height: 1.5; color: #555;">
                {{ __('db.Are you sure want to delete?') ?? 'Are you sure you want to proceed?' }}
            </div>
            <div class="modal-footer border-0 pt-2 pb-4 px-4 d-flex justify-content-end" style="gap: 10px;">
                <button type="button" class="btn btn-secondary px-3 py-2 font-weight-bold" id="salepro-confirm-cancel-btn" data-dismiss="modal" style="border-radius: 6px;">
                    {{ __('db.Cancel') ?? 'Cancel' }}
                </button>
                <button type="button" class="btn btn-danger px-4 py-2 font-weight-bold" id="salepro-confirm-ok-btn" style="border-radius: 6px;">
                    {{ __('db.Confirm') ?? 'Confirm' }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    if (window.SaleProConfirm) {
        return; // Idempotent check
    }

    var activeResolver = null;
    var lastActiveElement = null;

    var I18N = {
        title: @json(__('db.Confirmation') ?? 'Confirmation'),
        deleteTitle: @json(__('db.Delete Confirmation') ?? 'Delete Confirmation'),
        deleteMessage: @json(__('db.Are you sure want to delete?') ?? 'Are you sure want to delete?'),
        confirm: @json(__('db.Confirm') ?? 'Confirm'),
        delete: @json(__('db.delete') ?? 'Delete'),
        cancel: @json(__('db.Cancel') ?? 'Cancel')
    };

    var TYPE_STYLES = {
        danger: {
            bg: '#f8d7da',
            color: '#dc3545',
            icon: '✕',
            btnClass: 'btn btn-danger'
        },
        warning: {
            bg: '#fff3cd',
            color: '#856404',
            icon: '⚠',
            btnClass: 'btn btn-warning'
        },
        info: {
            bg: '#d1ecf1',
            color: '#0c5460',
            icon: 'ℹ',
            btnClass: 'btn btn-info'
        },
        primary: {
            bg: '#cce5ff',
            color: '#004085',
            icon: '?',
            btnClass: 'btn btn-primary'
        }
    };

    var SaleProConfirm = {
        open: function(options) {
            options = options || {};
            var title = options.title || I18N.title;
            var message = options.message || I18N.deleteMessage;
            var type = options.type || 'danger';
            var confirmText = options.confirmText || (type === 'danger' ? I18N.delete : I18N.confirm);
            var cancelText = options.cancelText || I18N.cancel;

            lastActiveElement = document.activeElement;

            return new Promise(function(resolve) {
                activeResolver = resolve;

                var modalEl = $('#salepro-confirm-modal');
                if (!modalEl.length) {
                    resolve(false);
                    return;
                }

                // Update content
                $('#salepro-confirm-title').text(title);
                $('#salepro-confirm-body').text(message);

                var okBtn = $('#salepro-confirm-ok-btn');
                okBtn.text(confirmText);

                var cancelBtn = $('#salepro-confirm-cancel-btn');
                cancelBtn.text(cancelText);

                // Styling
                var style = TYPE_STYLES[type] || TYPE_STYLES.danger;
                var iconWrap = $('#salepro-confirm-icon-wrapper');
                iconWrap.css({
                    'background-color': style.bg,
                    'color': style.color
                });
                $('#salepro-confirm-icon').text(style.icon);

                okBtn.attr('class', style.btnClass + ' px-4 py-2 font-weight-bold');

                modalEl.modal('show');

                modalEl.one('shown.bs.modal', function() {
                    cancelBtn.trigger('focus');
                });
            });
        },

        ask: function(message, title, type) {
            return this.open({
                message: message,
                title: title,
                type: type || 'warning'
            });
        },

        confirmDelete: function(message, title) {
            return this.open({
                message: message || I18N.deleteMessage,
                title: title || I18N.deleteTitle,
                type: 'danger',
                confirmText: I18N.delete
            });
        }
    };

    window.SaleProConfirm = SaleProConfirm;
    window.saleproConfirm = function(message, optionsOrCallback) {
        if (typeof optionsOrCallback === 'function') {
            return SaleProConfirm.open({ message: message }).then(function(confirmed) {
                if (confirmed) optionsOrCallback();
            });
        }
        var opts = typeof optionsOrCallback === 'object' ? optionsOrCallback : {};
        opts.message = message || opts.message;
        return SaleProConfirm.open(opts);
    };

    // Global Modal Button Handlers
    $(document).on('click', '#salepro-confirm-ok-btn', function() {
        if (activeResolver) {
            var res = activeResolver;
            activeResolver = null;
            $('#salepro-confirm-modal').modal('hide');
            res(true);
        }
    });

    $(document).on('hidden.bs.modal', '#salepro-confirm-modal', function() {
        if (activeResolver) {
            var res = activeResolver;
            activeResolver = null;
            res(false);
        }
        if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
            try { lastActiveElement.focus(); } catch (e) {}
        }
    });

    // Declarative HTML Interception for [data-confirm-message] / [data-confirm]
    $(document).on('click', '[data-confirm-message], [data-confirm]', function(e) {
        var el = $(this);
        if (el.data('salepro-confirmed')) {
            el.removeData('salepro-confirmed');
            return; // Allow through on confirmed rerun
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        var message = el.attr('data-confirm-message') || el.attr('data-confirm');
        var title = el.attr('data-confirm-title') || (el.attr('data-confirm-type') === 'danger' ? I18N.deleteTitle : I18N.title);
        var type = el.attr('data-confirm-type') || 'danger';
        var confirmText = el.attr('data-confirm-btn') || (type === 'danger' ? I18N.delete : I18N.confirm);

        SaleProConfirm.open({
            title: title,
            message: message,
            type: type,
            confirmText: confirmText
        }).then(function(confirmed) {
            if (!confirmed) return;

            var form = el.closest('form');
            if (form.length && (el.is(':submit') || el.attr('type') === 'submit' || el.is('button'))) {
                el.data('salepro-confirmed', true);
                if (typeof form[0].requestSubmit === 'function') {
                    form[0].requestSubmit(el[0]);
                } else {
                    form.trigger('submit');
                }
            } else if (el.is('a') && el.attr('href') && el.attr('href') !== '#' && !el.attr('href').startsWith('javascript:')) {
                window.location.href = el.attr('href');
            } else {
                el.data('salepro-confirmed', true);
                el.trigger('click');
            }
        });
    });

    // Declarative Form Interception for form[data-confirm-message]
    $(document).on('submit', 'form[data-confirm-message], form[data-confirm]', function(e) {
        var form = $(this);
        if (form.data('salepro-confirmed')) {
            form.removeData('salepro-confirmed');
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        var message = form.attr('data-confirm-message') || form.attr('data-confirm');
        var title = form.attr('data-confirm-title') || I18N.title;
        var type = form.attr('data-confirm-type') || 'danger';

        SaleProConfirm.open({
            title: title,
            message: message,
            type: type
        }).then(function(confirmed) {
            if (!confirmed) return;

            form.data('salepro-confirmed', true);
            if (typeof form[0].requestSubmit === 'function') {
                form[0].requestSubmit();
            } else {
                form.trigger('submit');
            }
        });
    });
})();
</script>
