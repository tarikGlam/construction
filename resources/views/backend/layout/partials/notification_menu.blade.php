@php
    $alertProductCount = (int) ($alert_product ?? 0);
    $dsoAlertProductCount = (int) ($dso_alert_product_no ?? 0);
    $expireAlertProductCount = (int) ($expire_alert_products ?? 0);
    $pendingEcommerceOrdersCount = (int) ($pending_ecommerce_orders ?? 0);

    $allUnreadNotifications = Auth::user()
        ? Auth::user()
            ->unreadNotifications
            ->filter(function ($n) {
                $remDate = $n->data['reminder_date'] ?? null;
                return !$remDate || $remDate <= date('Y-m-d');
            })
        : collect();

    $userUnreadCount = $allUnreadNotifications->count();

    $total_notifications =
        $alertProductCount +
        $dsoAlertProductCount +
        $expireAlertProductCount +
        $pendingEcommerceOrdersCount +
        $userUnreadCount;
@endphp

<li class="nav-item" id="notification-icon">
    <a rel="nofollow" data-toggle="tooltip" title="{{ __('Notifications') }}"
        class="nav-link dropdown-item">
        <i class="ti ti-bell"></i>

        @if ($total_notifications > 0)
            <span class="badge badge-danger notification-number">{{ $total_notifications }}</span>
        @endif
    </a>

    <style>
        .custom-notifications-list {
            width: 550px;
            max-width: 95vw;
            padding: 0;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            border: 1px solid #f0f0f0;
            background: #fff;
            right: 0 !important;
            left: auto !important;
        }
        .custom-notifications-list li {
            line-height: normal !important;
        }
        .custom-notifications-list .dropdown-header {
            font-weight: 700;
            font-size: 15px;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f0;
            color: #2c3e50;
            background: #ffffff;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .custom-notifications-list .dropdown-header .badge {
            font-size: 11px;
            padding: 5px 8px;
            border-radius: 20px;
        }
        .custom-notifications-list .notifications {
            border-bottom: 1px solid #f9f9f9;
            padding: 0;
            margin: 0;
            list-style: none;
        }
        .custom-notifications-list .notifications:last-child {
            border-bottom: none;
        }
        .custom-notifications-list .notifications a {
            display: flex;
            align-items: flex-start;
            padding: 16px 20px;
            text-decoration: none;
            color: #555;
            transition: all 0.2s ease;
        }
        .custom-notifications-list .notifications a:hover {
            background: #f8faff;
        }
        .notify-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 15px;
        }
        .notify-icon-box i {
            font-size: 20px;
        }
        .notify-text {
            flex-grow: 1;
        }
        .notify-text h6 {
            margin: 0 0 4px 0;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
        }
        .notify-text span {
            font-size: 13px;
            color: #7f8c8d;
            line-height: 1.4;
            display: block;
        }
        /* Variations */
        .bg-qty { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
        .bg-dso { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .bg-expire { background: rgba(211, 84, 0, 0.1); color: #d35400; }
        .bg-order { background: rgba(52, 152, 219, 0.1); color: #3498db; }
        .bg-reminder { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }
    </style>

    <ul class="right-sidebar custom-notifications-list">
        <li class="dropdown-header">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div>
                    <span>{{ __('Notifications') }}</span>
                    @if($total_notifications > 0)
                        <span class="badge badge-danger ml-2">{{ $total_notifications }} New</span>
                    @endif
                </div>
                <button type="button" class="btn btn-link text-muted p-0" onclick="$(this).closest('.nav-item').removeClass('show'); $(this).closest('.right-sidebar').removeClass('open show');" style="font-size: 16px; text-decoration: none;">
                    <i class="ti ti-close"></i>
                </button>
            </div>
        </li>

        {{-- No notifications --}}
        @if ($total_notifications == 0)
            <li class="notifications text-center">
                <span class="text-muted d-block py-4">
                    {{ __('db.no_notifications_available') }}
                </span>
            </li>
        @else
            {{-- Quantity Alert --}}
            @if ($alertProductCount > 0)
                <li class="notifications">
                    <a href="{{ route('report.qtyAlert') }}">
                        <div class="notify-icon-box bg-qty">
                            <i class="ti ti-alert"></i>
                        </div>

                        <div class="notify-text">
                            <h6>{{ __('db.quantity_alert') }}</h6>
                            <span>
                                <strong>{{ $alertProductCount }}</strong>
                                {{ __('db.product_exceeds_alert_quantity') }}
                            </span>
                        </div>
                    </a>
                </li>
            @endif

            {{-- Daily Sale Objective Alert --}}
            @if ($dsoAlertProductCount > 0)
                <li class="notifications">
                    <a href="{{ route('report.dailySaleObjective') }}">
                        <div class="notify-icon-box bg-dso">
                            <i class="ti ti-target"></i>
                        </div>

                        <div class="notify-text">
                            <h6>{{ __('db.daily_sale_objective') }}</h6>
                            <span>
                                {{ __('db.products_below_objective', [
                                    'count' => $dsoAlertProductCount,
                                ]) }}
                            </span>
                        </div>
                    </a>
                </li>
            @endif

            {{-- Product Expiry Alert --}}
            @if ($expireAlertProductCount > 0)
                <li class="notifications">
                    <a href="{{ route('report.productExpiry') }}">
                        <div class="notify-icon-box bg-expire">
                            <i class="ti ti-time"></i>
                        </div>

                        <div class="notify-text">
                            <h6>{{ __('db.product_expiry') }}</h6>
                            <span>
                                {{ __('db.products_expiring_within_days', [
                                    'count' => $expireAlertProductCount,
                                    'days' => $general_setting->expiry_alert_days ?? 30,
                                ]) }}
                            </span>
                        </div>
                    </a>
                </li>
            @endif

            {{-- Pending Ecommerce Orders --}}
            @if ($pendingEcommerceOrdersCount > 0)
                <li class="notifications">
                    <a href="{{ route('sales.index') }}">
                        <div class="notify-icon-box bg-order">
                            <i class="ti ti-shopping-cart"></i>
                        </div>

                        <div class="notify-text">
                            <h6>{{ __('db.new_online_orders') }}</h6>
                            <span>
                                {{ __('db.pending_ecommerce_orders_count', [
                                    'count' => $pendingEcommerceOrdersCount,
                                ]) }}
                            </span>
                        </div>
                    </a>
                </li>
            @endif

            {{-- In-App & Reminder Notifications --}}
            @foreach ($allUnreadNotifications as $notification)
                @php
                    $documentName = $notification->data['document_name'] ?? null;
                    $message = $notification->data['message'] ?? '';
                    $event = $notification->data['event'] ?? null;

                    // Resolve target link safely - NEVER return a 404 URL
                    $hasRealDocument = $documentName && file_exists(public_path('documents/notification/' . $documentName));
                    $targetUrl = 'javascript:void(0);';
                    $targetBlank = false;

                    if ($hasRealDocument) {
                        $targetUrl = url('documents/notification/' . $documentName);
                        $targetBlank = true;
                    } elseif ($event === 'sale_created' || $event === 'payment_received' || ($documentName && (str_starts_with($documentName, 'sr-') || str_starts_with($documentName, 'posr-') || str_starts_with($documentName, 'SL-')))) {
                        $targetUrl = route('sales.index');
                    } elseif ($event === 'purchase_created' || ($documentName && (str_starts_with($documentName, 'pr-') || str_starts_with($documentName, 'po-') || str_starts_with($documentName, 'PO-')))) {
                        $targetUrl = route('purchases.index');
                    } elseif ($event === 'quotation_created' || ($documentName && (str_starts_with($documentName, 'qr-') || str_starts_with($documentName, 'QUO-')))) {
                        $targetUrl = route('quotations.index');
                    } elseif ($event === 'stock_transfer' || ($documentName && (str_starts_with($documentName, 'tr-') || str_starts_with($documentName, 'TRN-')))) {
                        $targetUrl = route('transfers.index');
                    } elseif ($event === 'low_stock') {
                        $targetUrl = route('report.qtyAlert');
                    } elseif ($event === 'expiry_alert') {
                        $targetUrl = route('report.productExpiry');
                    }

                    // Determine icon & title
                    $iconClass = 'ti ti-bell';
                    $bgClass = 'bg-reminder';
                    $title = __('db.reminder');

                    if ($event === 'sale_created') {
                        $iconClass = 'ti ti-shopping-cart';
                        $bgClass = 'bg-order';
                        $title = __('db.Sale Created');
                    } elseif ($event === 'payment_received') {
                        $iconClass = 'ti ti-credit-card';
                        $bgClass = 'bg-reminder';
                        $title = __('db.Payment Received');
                    } elseif ($event === 'purchase_created') {
                        $iconClass = 'ti ti-truck-delivery';
                        $bgClass = 'bg-order';
                        $title = __('db.Purchase Created');
                    } elseif ($event === 'quotation_created') {
                        $iconClass = 'ti ti-file-text';
                        $bgClass = 'bg-order';
                        $title = __('db.Quotation Created');
                    } elseif ($event === 'stock_transfer') {
                        $iconClass = 'ti ti-arrows-left-right';
                        $bgClass = 'bg-order';
                        $title = __('db.Stock Transfer');
                    } elseif ($event === 'low_stock') {
                        $iconClass = 'ti ti-alert-triangle';
                        $bgClass = 'bg-qty';
                        $title = __('db.Low Stock');
                    } elseif ($event === 'expiry_alert') {
                        $iconClass = 'ti ti-clock';
                        $bgClass = 'bg-expire';
                        $title = __('db.Expiry Alert');
                    }
                @endphp

                <li class="notifications">
                    <a href="{{ $targetUrl }}" @if ($targetBlank) target="_blank" rel="noopener noreferrer" @endif>
                        <div class="notify-icon-box {{ $bgClass }}">
                            <i class="{{ $iconClass }}"></i>
                        </div>

                        <div class="notify-text">
                            <h6>{{ $title }}</h6>
                            <span>{{ $message }}</span>
                        </div>
                    </a>
                </li>
            @endforeach
        @endif
    </ul>
</li>
