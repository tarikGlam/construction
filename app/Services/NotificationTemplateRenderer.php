<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use App\Models\Warehouse;

class NotificationTemplateRenderer
{
    public const ALLOWLIST = [
        'sale_created' => ['customer', 'reference', 'amount', 'product', 'qty', 'date'],
        'purchase_created' => ['supplier', 'reference', 'amount', 'product', 'qty', 'date'],
        'low_stock' => ['product', 'qty', 'date'],
        'payment_received' => ['customer', 'reference', 'amount', 'date'],
        'quotation_created' => ['customer', 'reference', 'amount', 'product', 'qty', 'date'],
        'expiry_alert' => ['product', 'qty', 'date'],
        'stock_transfer' => ['reference', 'from_warehouse', 'to_warehouse', 'product', 'qty', 'amount', 'date'],
    ];

    /**
     * Validate template against allowlist of placeholders for the specific event.
     *
     * @return array List of invalid placeholder tags found (e.g. ['[custmr]'])
     */
    public function validateTemplate(string $event, ?string $template): array
    {
        if (!$template || trim($template) === '') {
            return [];
        }

        $allowed = self::ALLOWLIST[$event] ?? ['reference', 'date'];
        preg_match_all('/(?:\[|\{)([a-zA-Z0-9_]+)(?:\]|\})/', $template, $matches);

        $foundTags = $matches[1] ?? [];
        $invalid = [];

        foreach ($foundTags as $tag) {
            if (!in_array($tag, $allowed, true)) {
                $invalid[] = '[' . $tag . ']';
            }
        }

        return array_values(array_unique($invalid));
    }

    /**
     * Get translatable default email subject.
     */
    public function renderSubject(string $event, NotificationEventData $data): string
    {
        $ref = $data->reference ?: 'Update';

        return match ($event) {
            'sale_created'      => __('db.Sale Confirmation - :reference', ['reference' => $ref]),
            'purchase_created'  => __('db.Purchase Order Created - :reference', ['reference' => $ref]),
            'low_stock'         => __('db.Low Stock Alert - :product', ['product' => $data->extra['product_name'] ?? $ref]),
            'payment_received'  => __('db.Payment Receipt - :reference', ['reference' => $ref]),
            'quotation_created' => __('db.Quotation Ready - :reference', ['reference' => $ref]),
            'expiry_alert'      => __('db.Product Expiry Alert Notice'),
            'stock_transfer'    => __('db.Stock Transfer Dispatched - :reference', ['reference' => $ref]),
            default             => ucwords(str_replace('_', ' ', $event)) . ' - ' . $ref,
        };
    }

    /**
     * Render message body for a specific channel with safe formatting and placeholder substitution.
     */
    public function renderBody(?string $template, string $channel, NotificationEventData $data): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = $this->getDefaultTemplate($data->event, $channel);
        }

        $isHtml = ($channel === 'mail');

        // Resolve dictionary values
        $customerName = $data->customer?->name ?: ($data->extra['customer_name'] ?? '');
        $supplierName = $data->supplier?->name ?: ($data->extra['supplier_name'] ?? '');
        $reference    = $data->reference ?: '';
        $amount       = $data->amount !== null ? number_format($data->amount, 2) : '';
        $qty          = $data->totalQty !== null ? (string) (float) $data->totalQty : '';
        $date         = $data->date ?: date('Y-m-d');
        $fromWh       = $this->resolveWarehouseName($data->fromWarehouseId);
        $toWh         = $this->resolveWarehouseName($data->toWarehouseId);
        $productStr   = $this->formatProductSummary($data->products, $isHtml);

        $values = [
            '[customer]'       => $this->sanitizeValue($customerName, $isHtml),
            '[supplier]'       => $this->sanitizeValue($supplierName, $isHtml),
            '[reference]'      => $this->sanitizeValue($reference, $isHtml),
            '[amount]'         => $amount,
            '[product]'        => $productStr,
            '[qty]'            => $qty,
            '[from_warehouse]' => $this->sanitizeValue($fromWh, $isHtml),
            '[to_warehouse]'   => $this->sanitizeValue($toWh, $isHtml),
            '[date]'           => $date,
        ];

        $rendered = str_replace(array_keys($values), array_values($values), $template);

        // Strip any remaining unsupported/misspelled placeholders
        $rendered = preg_replace('/\[[a-zA-Z0-9_]+\]/', '', $rendered);

        // Strip HTML tags for plain-text channels
        if (!$isHtml) {
            $rendered = preg_replace('/<br\s*\/?>/i', ' ', $rendered);
            $rendered = preg_replace('/<\/(p|div)>/i', ' ', $rendered);
            $rendered = strip_tags($rendered);
            $rendered = html_entity_decode($rendered, ENT_QUOTES, 'UTF-8');
            $rendered = preg_replace('/[ \t]+/', ' ', $rendered);
        }

        return trim($rendered);
    }

    private function sanitizeValue(string $val, bool $isHtml): string
    {
        return $isHtml ? htmlspecialchars($val, ENT_QUOTES, 'UTF-8') : $val;
    }

    private function resolveWarehouseName(?int $warehouseId): string
    {
        if (!$warehouseId) {
            return '';
        }

        $wh = Warehouse::withoutGlobalScope('authorized_warehouse')->find($warehouseId);
        return $wh?->name ?? '';
    }

    private function formatProductSummary(array $products, bool $isHtml): string
    {
        if (empty($products)) {
            return '';
        }

        $items = [];
        $limit = 3;
        $count = count($products);

        foreach (array_slice($products, 0, $limit) as $p) {
            $name = $p['name'] ?? 'Item';
            $qty = isset($p['qty']) ? ' (Qty: ' . (float) $p['qty'] . ')' : '';
            $items[] = $this->sanitizeValue($name, $isHtml) . $qty;
        }

        $res = implode(', ', $items);
        if ($count > $limit) {
            $remaining = $count - $limit;
            $res .= " (+{$remaining} more)";
        }

        return $res;
    }

    private function getDefaultTemplate(string $event, string $channel): string
    {
        return match ($event) {
            'sale_created' => match ($channel) {
                'mail' => 'Dear [customer],<br><br>Thank you for your order. Your order reference <strong>[reference]</strong> for [amount] has been created successfully.<br><br>Best Regards,',
                'sms'  => 'Order [reference] confirmed. Total: [amount]. Thank you!',
                default => 'Hello [customer], your order [reference] of [amount] has been created successfully!',
            },
            'purchase_created' => match ($channel) {
                'mail' => 'Hello [supplier],<br><br>Purchase order <strong>[reference]</strong> has been created with total [amount].',
                'sms'  => 'PO [reference] created. Total: [amount].',
                default => 'Purchase Order [reference] created.',
            },
            'low_stock' => match ($channel) {
                'mail' => 'Alert: The following item is running low on stock: <strong>[product]</strong>.',
                default => 'Low Stock Alert: [product]',
            },
            'payment_received' => match ($channel) {
                'mail' => 'Dear [customer],<br><br>We received your payment of [amount] for reference [reference]. Thank you!',
                'sms'  => 'Payment received for [reference]. Amount: [amount].',
                default => 'Hi [customer], payment of [amount] received for [reference].',
            },
            'quotation_created' => match ($channel) {
                'mail' => 'Dear [customer],<br><br>Quotation <strong>[reference]</strong> is ready for review with total [amount].',
                default => 'Hello [customer], quotation [reference] has been prepared.',
            },
            'expiry_alert' => match ($channel) {
                'mail' => 'Alert: Product <strong>[product]</strong> has items expiring soon.',
                default => 'Expiry Alert: [product] expiring soon.',
            },
            'stock_transfer' => match ($channel) {
                'mail' => 'Stock Transfer <strong>[reference]</strong> dispatched from [from_warehouse] to [to_warehouse]. Items: [product].',
                default => 'Transfer [reference] dispatched from [from_warehouse] to [to_warehouse].',
            },
            default => 'Event notification: [reference]',
        };
    }
}
