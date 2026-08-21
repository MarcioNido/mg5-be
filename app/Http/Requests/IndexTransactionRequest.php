<?php

namespace App\Http\Requests;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('uncategorized'))
            && in_array(strtolower($this->input('uncategorized')), ['true', 'false'], true)) {
            $this->merge([
                'uncategorized' => strtolower($this->input('uncategorized')) === 'true',
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'account_id' => [
                'sometimes',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'origin' => ['sometimes', Rule::enum(TransactionOrigin::class)],
            'category_id' => [
                'sometimes',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'uncategorized' => ['sometimes', 'boolean'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => [
                'sometimes',
                'date',
                Rule::when($this->filled('date_from'), 'after_or_equal:date_from'),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('category_id') && $this->boolean('uncategorized')) {
                $validator->errors()->add(
                    'uncategorized',
                    'The uncategorized filter cannot be combined with a category.'
                );
            }
        }];
    }
}
