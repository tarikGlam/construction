<?php

namespace App\Http\Requests;

class UpdateSaleReturnRequest extends SaleReturnRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'product_sale_id' => ['required', 'array', 'min:1'],
            'product_sale_id.*' => ['required', 'integer', 'min:1', 'distinct'],
            'imei_number' => ['sometimes', 'array'],
            'imei_number.*' => ['nullable', 'string'],
        ]);
    }
}
