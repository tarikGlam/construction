<?php

namespace App\Http\Requests;

class StoreSaleReturnRequest extends SaleReturnRequest
{
    public function authorize(): bool
    {
        // Hiding Add Return is not authorization for a direct POST.
        return $this->user() !== null
            && (\Spatie\Permission\Models\Role::find($this->user()->role_id)?->checkPermissionTo('returns-add') ?? false);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sale_id' => ['required', 'integer', 'min:1'],
            'zatca_request_key' => ['sometimes', 'uuid'],
            'zatca_charge_refunds' => ['sometimes', 'array:shipping_cost,service_charge'],
            'zatca_charge_refunds.*' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'zatca_rounding_consent' => ['sometimes', 'in:1'],
            'zatca_rounding_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'product_sale_id' => ['required', 'array', 'min:1'],
            'product_sale_id.*' => ['required', 'integer', 'min:1', 'distinct'],
            'imei_number' => ['sometimes', 'array'],
            'imei_number.*' => ['nullable', 'string'],
        ]);
    }
}
