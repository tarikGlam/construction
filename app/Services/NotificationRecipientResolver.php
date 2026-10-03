<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public const EVENT_PERMISSIONS = [
        'sale_created'      => 'sales-view',
        'purchase_created'  => 'purchases-view',
        'low_stock'         => 'product-qty-alert',
        'payment_received'  => 'sales-view',
        'quotation_created' => 'quotes-view',
        'expiry_alert'      => 'product-expiry-report',
        'stock_transfer'    => 'transfers-view',
    ];

    public const APPLICABLE_RECIPIENTS = [
        'sale_created'      => ['admin', 'customer'],
        'purchase_created'  => ['admin', 'supplier'],
        'low_stock'         => ['admin'],
        'payment_received'  => ['admin', 'customer'],
        'quotation_created' => ['admin', 'customer'],
        'expiry_alert'      => ['admin'],
        'stock_transfer'    => ['admin'],
    ];

    /**
     * Resolve all eligible staff (administrators & authorized warehouse users).
     *
     * @return Collection<User>
     */
    public function resolveStaffRecipients(NotificationEventData $data): Collection
    {
        $warehouseId = $data->warehouseId;
        $fromWh = $data->fromWarehouseId;
        $toWh = $data->toWarehouseId;

        $targetWarehouses = array_filter([(int) $warehouseId, (int) $fromWh, (int) $toWh]);

        // Base active query excluding portal customers (role 5) and deleted users
        $users = User::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', false);
            })
            ->where('role_id', '!=', 5)
            ->get();

        $requiredPermission = self::EVENT_PERMISSIONS[$data->event] ?? 'all_notification';

        return $users->filter(function (User $user) use ($targetWarehouses, $requiredPermission) {
            // 1. Global Administrators (Roles 1 & 2) have unrestricted global visibility
            if ((int) $user->role_id <= 2) {
                return true;
            }

            // 2. Warehouse-Operational Users (Role > 2 and != 5)
            // Must have a valid warehouse assignment matching the event's warehouse scope
            if (empty($targetWarehouses)) {
                return false;
            }

            if (!in_array((int) $user->warehouse_id, $targetWarehouses, true)) {
                return false;
            }

            // 3. Operational users must hold the event operational permission or all_notification
            try {
                return $user->hasPermissionTo($requiredPermission) || $user->hasPermissionTo('all_notification');
            } catch (\Throwable $e) {
                return false;
            }
        })->values();
    }

    /**
     * Check if a recipient category is applicable for an event.
     */
    public function isRecipientApplicable(string $event, string $category): bool
    {
        $allowed = self::APPLICABLE_RECIPIENTS[$event] ?? ['admin'];
        return in_array($category, $allowed, true);
    }

    /**
     * Check if a channel is applicable for a recipient category.
     * Suppliers do not have linked user accounts in schema and cannot receive in-app notifications.
     */
    public function isChannelApplicable(string $category, string $channel): bool
    {
        if ($category === 'supplier' && $channel === 'in_app') {
            return false;
        }

        return true;
    }

    /**
     * Resolve the contact destination for a recipient category and channel.
     *
     * @return array{destination: ?string, user: ?User, normalized_hash: string}
     */
    public function resolveDestination(
        string $category,
        string $channel,
        NotificationEventData $data,
        ?User $staffUser = null
    ): array {
        $destination = null;
        $user = null;

        if ($category === 'admin' && $staffUser) {
            $user = $staffUser;
            $destination = match ($channel) {
                'mail' => $staffUser->email,
                'whatsapp', 'sms' => $staffUser->phone,
                'in_app' => (string) $staffUser->id,
                default => null,
            };
        } elseif ($category === 'customer' && $data->customer) {
            $customer = $data->customer;
            $destination = match ($channel) {
                'mail' => $customer->email,
                'whatsapp' => $customer->wa_number ?: $customer->phone_number,
                'sms' => $customer->phone_number ?: $customer->wa_number,
                'in_app' => $customer->user_id ? (string) $customer->user_id : null,
                default => null,
            };

            if ($channel === 'in_app' && $customer->user_id) {
                $user = User::where('is_active', true)
                    ->where(fn($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', false))
                    ->where('role_id', 5)
                    ->find($customer->user_id);

                // A portal notification must never be delivered merely because a
                // customer stores a stale or reassigned user id.
                if (!$user || (int) $customer->user_id !== (int) $user->id) {
                    $user = null;
                    $destination = null;
                }
            }
        } elseif ($category === 'supplier' && $data->supplier) {
            $supplier = $data->supplier;
            $destination = match ($channel) {
                'mail' => $supplier->email,
                'whatsapp' => $supplier->wa_number ?: $supplier->phone_number,
                'sms' => $supplier->phone_number ?: $supplier->wa_number,
                'in_app' => null, // Suppliers do not have user accounts in schema
                default => null,
            };
        }

        $normalized = $this->normalizeDestination($destination, $channel);
        // Missing destinations must be idempotent as well.  Random hashes turn a
        // harmless missing contact into unbounded skipped delivery records.
        $hash = hash('sha256', $normalized !== ''
            ? $normalized
            : 'missing:' . $category . ':' . $channel . ':' . $data->subjectType . ':' . $data->subjectId);

        return [
            'destination'     => $normalized !== '' ? $normalized : null,
            'user'            => $user,
            'normalized_hash' => $hash,
        ];
    }

    private function normalizeDestination(?string $destination, string $channel): string
    {
        $destination = trim((string) $destination);
        if ($destination === '') {
            return '';
        }

        if ($channel === 'mail') {
            return strtolower($destination);
        }

        if (in_array($channel, ['sms', 'whatsapp'], true)) {
            $phone = preg_replace('/[\s\-\(\)\.]/', '', $destination);
            // Preserve an explicit international number; do not invent a country
            // prefix for ambiguous local numbers.
            return str_starts_with($phone, '+') ? '+' . ltrim($phone, '+') : $phone;
        }

        return $destination;
    }
}
