<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
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
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'decimal:0,4'],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['pending', 'posted'])],
            'splits' => ['sometimes', 'array'],
            'splits.*.category_id' => ['required', 'integer'],
            'splits.*.amount' => ['required', 'decimal:0,4'],
            'splits.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
