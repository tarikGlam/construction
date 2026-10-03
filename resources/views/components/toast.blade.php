@php
    $normalizeMessage = function ($msg) {
        if ($msg === null) {
            return null;
        }
        if (is_string($msg) || is_numeric($msg) || is_bool($msg)) {
            return (string) $msg;
        }
        if (is_array($msg)) {
            $preferred = ['message', 'success', 'error', 'not_permitted', 'warning', 'info', 'text', 'detail', 'value', 'label'];
            foreach ($preferred as $pk) {
                if (isset($msg[$pk]) && (is_string($msg[$pk]) || is_numeric($msg[$pk]))) {
                    return (string) $msg[$pk];
                }
            }
            foreach ($msg as $k => $v) {
                if (is_string($v) && trim($v) !== '') {
                    return $v;
                }
                if (is_string($k) && !is_numeric($k) && strlen($k) > 1) {
                    return $k;
                }
            }
            return json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_object($msg)) {
            if (method_exists($msg, '__toString')) {
                return (string) $msg;
            }
            return json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $msg;
    };

    $flashes = [];

    // 1. Structured toast_notify
    if (session()->has('toast_notify')) {
        $tn = session('toast_notify');
        if (is_array($tn)) {
            $flashes[] = [
                'message' => $normalizeMessage($tn['message'] ?? ''),
                'type'    => $tn['type'] ?? 'success',
                'title'   => $tn['title'] ?? null,
                'action'  => $tn['action'] ?? null,
            ];
        } elseif (is_string($tn)) {
            $flashes[] = ['message' => $tn, 'type' => 'success', 'title' => null, 'action' => null];
        }
    }

    // 2. Standard session keys
    if (session()->has('message')) {
        $flashes[] = ['message' => $normalizeMessage(session('message')), 'type' => 'success', 'title' => null, 'action' => null];
    }
    if (session()->has('success')) {
        $flashes[] = ['message' => $normalizeMessage(session('success')), 'type' => 'success', 'title' => null, 'action' => null];
    }
    if (session()->has('import_message')) {
        $flashes[] = ['message' => $normalizeMessage(session('import_message')), 'type' => 'success', 'title' => null, 'action' => null];
    }
    if (session()->has('not_permitted')) {
        $flashes[] = ['message' => $normalizeMessage(session('not_permitted')), 'type' => 'error', 'title' => null, 'action' => null];
    }
    if (session()->has('error')) {
        $flashes[] = ['message' => $normalizeMessage(session('error')), 'type' => 'error', 'title' => null, 'action' => null];
    }
    if (session()->has('warning')) {
        $flashes[] = ['message' => $normalizeMessage(session('warning')), 'type' => 'warning', 'title' => null, 'action' => null];
    }
    if (session()->has('info')) {
        $flashes[] = ['message' => $normalizeMessage(session('info')), 'type' => 'info', 'title' => null, 'action' => null];
    }
    if (session()->has('customMessage')) {
        $cType = session('type', 'info');
        $cType = ($cType === 'danger') ? 'error' : $cType;
        $flashes[] = ['message' => $normalizeMessage(session('customMessage')), 'type' => $cType, 'title' => null, 'action' => null];
    }
    if ($errors->any()) {
        $flashes[] = [
            'message' => $normalizeMessage($errors->first()),
            'type' => 'error',
            'title' => __('db.Validation Error'),
            'action' => null,
        ];
    }
@endphp

{{-- Global Toast Container --}}
<div id="salepro-toast-container"
     class="salepro-toast-container"
     role="region"
     aria-label="{{ __('db.Notifications') ?? 'Notifications' }}"
     aria-live="polite"
     aria-atomic="true">
</div>

<style>
.salepro-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 420px;
    width: calc(100% - 40px);
    pointer-events: none;
}
[dir="rtl"] .salepro-toast-container {
    right: auto;
    left: 20px;
}
.salepro-toast-item {
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    padding: 12px 16px;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    background: #fff;
    border-left: 5px solid #28a745;
    color: #333;
    font-size: 14px;
    line-height: 1.4;
    position: relative;
    overflow: hidden;
    transition: transform 0.25s ease, opacity 0.25s ease;
    opacity: 0;
    transform: translateY(-10px);
}
[dir="rtl"] .salepro-toast-item {
    border-left: none;
    border-right: 5px solid #28a745;
}
.salepro-toast-item.salepro-toast-show {
    opacity: 1;
    transform: translateY(0);
}
.salepro-toast-item.salepro-toast-hide {
    opacity: 0;
    transform: translateY(-10px);
}
.salepro-toast-item.salepro-toast-success {
    border-color: #28a745;
    background: #ffffff;
}
.salepro-toast-item.salepro-toast-success .salepro-toast-icon {
    color: #28a745;
}
.salepro-toast-item.salepro-toast-error {
    border-color: #dc3545;
    background: #ffffff;
}
.salepro-toast-item.salepro-toast-error .salepro-toast-icon {
    color: #dc3545;
}
.salepro-toast-item.salepro-toast-warning {
    border-color: #ffc107;
    background: #ffffff;
}
.salepro-toast-item.salepro-toast-warning .salepro-toast-icon {
    color: #d39e00;
}
.salepro-toast-item.salepro-toast-info {
    border-color: #17a2b8;
    background: #ffffff;
}
.salepro-toast-item.salepro-toast-info .salepro-toast-icon {
    color: #17a2b8;
}
.salepro-toast-icon {
    margin-right: 12px;
    font-size: 18px;
    flex-shrink: 0;
    margin-top: 1px;
}
[dir="rtl"] .salepro-toast-icon {
    margin-right: 0;
    margin-left: 12px;
}
.salepro-toast-body {
    flex-grow: 1;
    word-break: break-word;
}
.salepro-toast-title {
    font-weight: 700;
    margin-bottom: 2px;
    color: #111;
}
.salepro-toast-close {
    background: none;
    border: none;
    padding: 0 0 0 10px;
    margin-left: auto;
    font-size: 18px;
    line-height: 1;
    color: #888;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s ease;
}
.salepro-toast-close:hover {
    opacity: 1;
    color: #000;
}
.salepro-toast-action {
    display: inline-block;
    margin-top: 6px;
    font-weight: 600;
    color: #007bff;
    text-decoration: underline;
}
.salepro-toast-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: rgba(0, 0, 0, 0.15);
    width: 100%;
    transform-origin: left;
    animation: salepro-toast-shrink linear forwards;
}
[dir="rtl"] .salepro-toast-progress {
    left: auto;
    right: 0;
    transform-origin: right;
}
@keyframes salepro-toast-shrink {
    from { width: 100%; }
    to { width: 0%; }
}
</style>

<script>
(function() {
    if (window.SaleProToast) {
        return; // Idempotent check
    }

    var ICONS = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };

    function normalizeToastMessage(payload) {
        if (payload === null || typeof payload === 'undefined') {
            return '';
        }

        if (typeof payload === 'string' || typeof payload === 'number' || typeof payload === 'boolean') {
            return String(payload);
        }

        if (Array.isArray(payload)) {
            return payload
                .map(normalizeToastMessage)
                .filter(function(item) { return item !== ''; })
                .join('<br>');
        }

        if (typeof payload === 'object') {
            var preferredKeys = ['message', 'success', 'error', 'not_permitted', 'warning', 'info', 'text', 'detail', 'value', 'label'];
            for (var i = 0; i < preferredKeys.length; i++) {
                var key = preferredKeys[i];
                if (Object.prototype.hasOwnProperty.call(payload, key)) {
                    var value = normalizeToastMessage(payload[key]);
                    if (value !== '') {
                        return value;
                    }
                }
            }

            if (payload.responseJSON) {
                var responseMessage = normalizeToastMessage(payload.responseJSON);
                if (responseMessage !== '') {
                    return responseMessage;
                }
            }

            if (payload.data && typeof payload.data === 'object') {
                var dataMessage = normalizeToastMessage(payload.data);
                if (dataMessage !== '') {
                    return dataMessage;
                }
            }

            if (payload.errors && typeof payload.errors === 'object') {
                var validationMessages = [];
                Object.keys(payload.errors).forEach(function(field) {
                    var fieldMessage = normalizeToastMessage(payload.errors[field]);
                    if (fieldMessage !== '') {
                        validationMessages.push(fieldMessage);
                    }
                });
                if (validationMessages.length) {
                    return validationMessages.join('<br>');
                }
            }

            var objKeys = Object.keys(payload);
            for (var k = 0; k < objKeys.length; k++) {
                var subKey = objKeys[k];
                if (typeof payload[subKey] === 'string' && payload[subKey].trim() !== '') {
                    return payload[subKey];
                }
                if (typeof subKey === 'string' && subKey.length > 1 && isNaN(subKey)) {
                    return subKey.trim();
                }
            }

            try {
                var serialized = JSON.stringify(payload);
                if (serialized && serialized !== '{}') {
                    return serialized;
                }
            } catch (e) {
                // Fall through to the generic message below.
            }

            return @json(__('db.operation_completed'));
        }

        return String(payload);
    }

    var SaleProToast = {
        normalizeMessage: normalizeToastMessage,

        show: function(message, type, options) {
            type = type || 'success';
            if (type === 'danger') type = 'error';
            options = options || {};
            message = normalizeToastMessage(message);

            if (!message) {
                return null;
            }

            var container = document.getElementById('salepro-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'salepro-toast-container';
                container.className = 'salepro-toast-container';
                container.setAttribute('role', 'region');
                container.setAttribute('aria-live', 'polite');
                document.body.appendChild(container);
            }

            // Success/info messages may disappear automatically. Errors require
            // user attention and remain visible until explicitly dismissed so a
            // user has time to read and act on the explanation.
            var defaultDuration = type === 'error' ? 0 : (type === 'warning' ? 10000 : 4500);
            var duration = typeof options.duration === 'number' ? options.duration : defaultDuration;
            var isHtml = !!options.html;

            var toast = document.createElement('div');
            toast.className = 'salepro-toast-item salepro-toast-' + type;
            toast.setAttribute('role', 'status');

            // Icon
            var iconSpan = document.createElement('span');
            iconSpan.className = 'salepro-toast-icon';
            iconSpan.textContent = ICONS[type] || ICONS.info;
            toast.appendChild(iconSpan);

            // Body
            var bodyDiv = document.createElement('div');
            bodyDiv.className = 'salepro-toast-body';

            if (options.title) {
                var titleDiv = document.createElement('div');
                titleDiv.className = 'salepro-toast-title';
                titleDiv.textContent = options.title;
                bodyDiv.appendChild(titleDiv);
            }

            var textDiv = document.createElement('div');
            if (isHtml) {
                textDiv.innerHTML = message;
            } else {
                textDiv.textContent = message;
            }
            bodyDiv.appendChild(textDiv);

            if (options.action && options.action.url && options.action.label) {
                var actionA = document.createElement('a');
                actionA.className = 'salepro-toast-action';
                actionA.href = options.action.url;
                actionA.textContent = options.action.label;
                bodyDiv.appendChild(actionA);
            }

            toast.appendChild(bodyDiv);

            // Close button
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'salepro-toast-close';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.innerHTML = '&times;';
            closeBtn.onclick = function() {
                dismissToast(toast);
            };
            toast.appendChild(closeBtn);

            // Progress bar
            if (duration > 0) {
                var progress = document.createElement('div');
                progress.className = 'salepro-toast-progress';
                progress.style.animationDuration = duration + 'ms';
                toast.appendChild(progress);
            }

            container.appendChild(toast);

            // Animate in
            requestAnimationFrame(function() {
                toast.classList.add('salepro-toast-show');
            });

            // Dismiss timer
            var timerId = null;
            if (duration > 0) {
                var remaining = duration;
                var startTime = Date.now();

                var startTimer = function() {
                    timerId = setTimeout(function() {
                        dismissToast(toast);
                    }, remaining);
                };

                startTimer();

                toast.onmouseenter = function() {
                    if (timerId) {
                        clearTimeout(timerId);
                        remaining -= Date.now() - startTime;
                        var progressEl = toast.querySelector('.salepro-toast-progress');
                        if (progressEl) {
                            progressEl.style.animationPlayState = 'paused';
                        }
                    }
                };

                toast.onmouseleave = function() {
                    if (remaining > 0) {
                        startTime = Date.now();
                        startTimer();
                        var progressEl = toast.querySelector('.salepro-toast-progress');
                        if (progressEl) {
                            progressEl.style.animationPlayState = 'running';
                        }
                    }
                };
            }

            function dismissToast(el) {
                if (!el || el.classList.contains('salepro-toast-hide')) return;
                el.classList.remove('salepro-toast-show');
                el.classList.add('salepro-toast-hide');
                setTimeout(function() {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 300);
            }

            return toast;
        },

        success: function(message, options) {
            return this.show(message, 'success', options);
        },

        error: function(message, options) {
            return this.show(message, 'error', options);
        },

        warning: function(message, options) {
            return this.show(message, 'warning', options);
        },

        info: function(message, options) {
            return this.show(message, 'info', options);
        }
    };

    window.SaleProToast = SaleProToast;
    window.saleproToast = function(msg, type, options) {
        return SaleProToast.show(msg, type, options);
    };
    window.saleProToast = function(msg, type, options) {
        return SaleProToast.show(msg, type, options);
    };

    // Thin POS adapter for backward compatibility with POS calls
    window.posToast = function(msg, bgOrType, color) {
        var type = 'info';
        if (typeof bgOrType === 'string') {
            if (bgOrType === 'success' || bgOrType === 'error' || bgOrType === 'warning' || bgOrType === 'info') {
                type = bgOrType;
            } else if (bgOrType.indexOf('#d1e7dd') !== -1 || bgOrType.indexOf('success') !== -1) {
                type = 'success';
            } else if (bgOrType.indexOf('#f8d7da') !== -1 || bgOrType.indexOf('danger') !== -1) {
                type = 'error';
            } else if (bgOrType.indexOf('#fff3cd') !== -1 || bgOrType.indexOf('warning') !== -1) {
                type = 'warning';
            }
        }
        return SaleProToast.show(msg, type);
    };

    // Render queued server flash messages
    document.addEventListener('DOMContentLoaded', function() {
        var serverFlashes = {!! json_encode($flashes, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' !!};
        if (Array.isArray(serverFlashes)) {
            serverFlashes.forEach(function(flash) {
                if (flash && flash.message) {
                    SaleProToast.show(flash.message, flash.type, {
                        title: flash.title,
                        action: flash.action,
                        html: true
                    });
                }
            });
        }
    });
})();
</script>
