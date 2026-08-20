<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'account_number' => [
                'nullable', 'string', 'max:255',
                Rule::unique('accounts')->where(fn ($query) => $query->where('tenant_id', Tenant::current()?->id)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['chequing', 'savings', 'credit', 'investment', 'cash', 'other', 'debit'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'opening_balance' => ['sometimes', 'decimal:0,4'],
            'opening_balance_date' => ['nullable', 'date'],
        ];
    }
}
