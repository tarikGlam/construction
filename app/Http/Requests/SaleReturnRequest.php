<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class SaleReturnRequest extends FormRequest
{
    private const LINE_ARRAY_FIELDS = [
        'product_sale_id', 'product_id', 'product_code', 'product_variant_id',
        'variant_id', 'product_batch_id', 'batch_no', 'actual_qty', 'qty',
        'imei_number', 'product_price', 'sale_unit', 'net_unit_price',
        'discount', 'tax_rate', 'tax', 'subtotal',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selected_items' => ['required', 'array', 'min:1'],
            'selected_items.*' => ['required', 'integer', 'min:1', 'distinct'],
            'qty' => ['required', 'array', 'min:1'],
            'qty.*' => ['bail', 'required', 'numeric', 'gt:0'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'selected_items.required' => __('db.sale_return_line_required'),
            'selected_items.array' => __('db.sale_return_line_required'),
            'selected_items.min' => __('db.sale_return_line_required'),
            'qty.required' => __('db.sale_return_quantity_positive'),
            'qty.array' => __('db.sale_return_quantity_positive'),
            'qty.min' => __('db.sale_return_quantity_positive'),
            'qty.*.required' => __('db.sale_return_quantity_positive'),
            'qty.*.numeric' => __('db.sale_return_quantity_positive'),
            'qty.*.gt' => __('db.sale_return_quantity_positive'),
            'product_id.required' => __('db.sale_return_line_required'),
            'product_id.min' => __('db.sale_return_line_required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $selected = $this->input('selected_items');
        $productSaleIds = $this->input('product_sale_id');

        if (!is_array($selected) || !is_array($productSaleIds)) {
            return;
        }

        $selectedLookup = array_fill_keys(array_map('strval', $selected), true);
        $selectedIndexes = [];
        foreach ($productSaleIds as $index => $productSaleId) {
            if (isset($selectedLookup[(string) $productSaleId])) {
                $selectedIndexes[$index] = true;
            }
        }

        $filtered = [];
        foreach (self::LINE_ARRAY_FIELDS as $field) {
            $values = $this->input($field);
            if (is_array($values)) {
                $filtered[$field] = array_intersect_key($values, $selectedIndexes);
            }
        }
        $this->merge($filtered);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $quantities = $this->input('qty', []);
            $products = $this->input('product_id', []);
            $selected = $this->input('selected_items', []);
            $productSaleIds = $this->input('product_sale_id', []);

            if (is_array($selected) && is_array($productSaleIds)) {
                $selectedIds = array_map('strval', $selected);
                $submittedIds = array_map('strval', $productSaleIds);
                if (count($selectedIds) !== count($submittedIds)
                    || array_diff($selectedIds, $submittedIds)
                    || array_diff($submittedIds, $selectedIds)) {
                    $validator->errors()->add('selected_items', __('db.sale_return_line_mismatch'));
                }
            }

            if (is_array($quantities) && is_array($products) && count($quantities) !== count($products)) {
                $validator->errors()->add('qty', __('db.sale_return_line_mismatch'));
            }
            foreach (['product_sale_id', 'imei_number', 'variant_id', 'product_variant_id', 'product_batch_id'] as $field) {
                if ($this->has($field) && (!is_array($this->input($field)) || !is_array($products) || array_keys($this->input($field)) !== array_keys($products))) {
                    $validator->errors()->add($field, __('db.sale_return_line_mismatch'));
                }
            }
        });
    }
}
