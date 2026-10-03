<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The direct POST must enforce the same permission as Add Sale/POS.
        return $this->user() !== null
            && (\Spatie\Permission\Models\Role::find($this->user()->role_id)?->checkPermissionTo('sales-add') ?? false);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert products array to parallel format if present
        if ($this->has('products') && is_array($this->products)) {
            $products = $this->products;
            $productId = [];
            $productCode = [];
            $qty = [];
            $saleUnit = [];
            $netUnitPrice = [];
            $discount = [];
            $taxRate = [];
            $tax = [];
            $subtotal = [];
            $imeiNumber = [];
            $productBatchId = [];

            foreach ($products as $product) {
                $productId[] = $product['product_id'] ?? $product['id'] ?? null;
                $productCode[] = $product['code'] ?? '';
                $qty[] = $product['qty'] ?? '1';
                $saleUnit[] = $product['sale_unit'] ?? ($product['sale_unit_id'] ?? 'n/a');
                $netUnitPrice[] = $product['net_unit_price'] ?? $product['price'] ?? '0';
                $discount[] = $product['discount'] ?? '0';
                $taxRate[] = $product['tax_rate'] ?? '0';
                $tax[] = $product['tax'] ?? '0';
                $subtotal[] = $product['subtotal'] ?? null;
                $imeiNumber[] = $product['imei_number'] ?? '';
                $productBatchId[] = $product['product_batch_id'] ?? null;
            }

            $this->merge([
                'item' => count($products),
                'product_id' => $productId,
                'product_code' => $productCode,
                'qty' => $qty,
                'sale_unit' => $saleUnit,
                'net_unit_price' => $netUnitPrice,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax' => $tax,
                'subtotal' => $subtotal,
                'imei_number' => $imeiNumber,
                'product_batch_id' => $productBatchId,
            ]);
        }

    }

    public function rules(): array
    {
        $paymentStatusRule = $this->input('pos') ? 'nullable' : 'required';

        $decimalRule = function (bool $mustBePositive = false, bool $allowZero = true) {
            return function ($attribute, $value, $fail) use ($mustBePositive, $allowZero) {
                if ($value === null || $value === '') {
                    return;
                }
                if (is_float($value)) {
                    return $fail(__('db.Decimal values must be submitted as strings or integers, floats are not permitted.'));
                }
                if (!is_string($value) && !is_int($value)) {
                    return $fail(__('db.Decimal values must be submitted as strings or integers.'));
                }
                $str = trim((string)$value);
                if (preg_match('/[eE]/', $str)) {
                    return $fail(__('db.Exponent notation is not permitted.'));
                }
                if (!preg_match('/^\+?\d+(?:\.\d+)?$/', $str)) {
                    return $fail(__('db.Decimal value format is invalid.'));
                }
                $digitsOnly = str_replace(['+', '.'], '', $str);
                $isZero = (trim($digitsOnly, '0') === '');
                if ($mustBePositive && $isZero) {
                    return $fail(__('db.Value must be greater than zero.'));
                }
                if (!$allowZero && $isZero) {
                    return $fail(__('db.Value must not be zero.'));
                }
            };
        };

        $rules = [
            'reference_no'    => 'nullable|string|max:191|unique:sales,reference_no',
            'idempotency_key' => 'nullable|string|max:64|regex:/^[A-Za-z0-9_\-]+$/',
            'zatca_sale_preview' => 'nullable|string|max:4096',
            'customer_id'     => 'required|integer',
            'warehouse_id'    => 'required|integer',
            'currency_id'     => 'required',
            'item'            => 'required|min:1',
            'sale_status'     => 'required',
            'payment_status'  => $paymentStatusRule,
            'document'        => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
            'product_id'      => 'required|array|min:1',
            'product_id.*'    => 'required|integer',
            'qty'             => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    if (is_array($value) && is_array($this->input('product_id')) && count($value) !== count($this->input('product_id'))) {
                        $fail(__('db.The quantity count must match the product count.'));
                    }
                },
            ],
            'qty.*'           => [
                'required',
                $decimalRule(mustBePositive: true),
            ],
            'net_unit_price.*'   => ['nullable', $decimalRule()],
            'discount.*'         => ['nullable', $decimalRule()],
            'tax_rate.*'         => ['nullable', $decimalRule()],
            'tax.*'              => ['nullable', $decimalRule()],
            'subtotal.*'         => ['nullable', $decimalRule()],
            'paying_amount.*'    => ['nullable', $decimalRule()],
            'paid_amount.*'      => ['nullable', $decimalRule()],
            'grand_total'        => ['nullable', $decimalRule()],
            'paid_amount'        => ['nullable', function ($attribute, $value, $fail) use ($decimalRule) {
                if (!is_array($value)) {
                    $decimalRule()($attribute, $value, $fail);
                }
            }],
            'paying_amount'      => ['nullable', function ($attribute, $value, $fail) use ($decimalRule) {
                if (!is_array($value)) {
                    $decimalRule()($attribute, $value, $fail);
                }
            }],
            'order_discount'     => ['nullable', $decimalRule()],
            'order_discount_value' => ['nullable', $decimalRule()],
            'order_tax_rate'     => ['nullable', $decimalRule()],
            'order_tax'          => ['nullable', $decimalRule()],
            'shipping_cost'      => ['nullable', $decimalRule()],
            'service_charge'     => ['nullable', $decimalRule()],
            'coupon_discount'    => ['nullable', $decimalRule()],
            'coupon_id'          => ['nullable', 'integer', 'min:1'],
            'coupon_code'        => ['nullable', 'string', 'max:255'],
        ];

        // Optional parallel arrays must match product_id count only when present
        $optionalParallelArrays = [
            'product_code',
            'net_unit_price',
            'discount',
            'tax_rate',
            'tax',
            'subtotal',
            'sale_unit',
            'imei_number',
            'product_batch_id',
            'variant_id',
            'product_variant_id',
        ];

        foreach ($optionalParallelArrays as $optField) {
            $rules[$optField] = array_merge($rules[$optField] ?? ['nullable'], [
                'array',
                function ($attribute, $value, $fail) {
                    if (is_array($value) && is_array($this->input('product_id')) && count($value) !== count($this->input('product_id'))) {
                        $fail(__('db.The :attribute count must match the product count.', ['attribute' => $attribute]));
                    }
                },
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'reference_no.unique'       => __('db.The reference number must be unique.'),
            'idempotency_key.regex'     => __('db.Invalid idempotency key format.'),
            'customer_id.required'      => __('db.Please select a customer.'),
            'warehouse_id.required'     => __('db.Please select a warehouse.'),
            'item.required'             => __('db.Please add at least one item.'),
            'sale_status.required'      => __('db.Sale status is required.'),
            'payment_status.required'   => __('db.Payment status is required.'),
            'document.mimes'            => __('db.The document must be a file of type: jpg, jpeg, png, gif, pdf, csv, docx, xlsx, txt.'),
            'product_id.required'       => __('db.Please add at least one product.'),
            'product_id.array'          => __('db.Invalid product data.'),
            'product_id.min'            => __('db.Please add at least one product.'),
            'product_id.*.required'     => __('db.Product is required.'),
            'product_id.*.integer'      => __('db.Invalid product ID.'),
            'qty.required'              => __('db.Quantity is required.'),
            'qty.array'                 => __('db.Invalid quantity data.'),
            'qty.min'                   => __('db.Quantity must have at least one entry.'),
            'qty.*.required'            => __('db.Quantity is required.'),
        ];
    }
}
