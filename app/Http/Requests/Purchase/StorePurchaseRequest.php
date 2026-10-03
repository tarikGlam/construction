<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'project_id' => 'nullable|exists:projects,id',
            'site_id' => 'nullable|exists:construction_sites,id',
            'currency_id' => 'required|exists:currencies,id,is_active,1',
            'exchange_rate' => ['required', new \App\Rules\ValidTransactionExchangeRate()],
            'product_code' => 'required|array',
            'product_code.*' => 'required|string',
            'qty' => 'required|array',
            'qty.*' => 'required|numeric|min:0.01',
            'document' => 'nullable|file|mimes:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
        ];
    }
    

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $defaultCurrencyId = (int) (\App\Models\GeneralSetting::latest()->value('currency') ?? 0);
            $currencyId = (int) $this->input('currency_id');
            $exchangeRate = (float) $this->input('exchange_rate');

            if ($currencyId === $defaultCurrencyId && abs($exchangeRate - 1.0) > 0.00000001) {
                $validator->errors()->add('exchange_rate', 'The default currency exchange rate must be 1.');
            }
        });
    }

    public function messages()
    {
        return [
            'warehouse_id.required' => 'Select a warehouse',
            'currency_id.required' => 'Currency field is required.',
            'exchange_rate.required' => 'The exchange rate is required.',
            'exchange_rate.numeric' => 'The exchange rate must be a valid number.',
            'product_code.required' => 'Please insert a product.',
            'qty.required' => 'At least one quantity is required.',
            'qty.array' => 'The quantities must be in an array format.',
            'qty.*.required' => 'Each quantity must be provided.',
            'qty.*.numeric' => 'Each quantity must be a valid number.',
            'qty.*.min' => 'Each quantity must be at least 0.01.',
        ];
    }
}
