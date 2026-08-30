<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkCategorizeTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        return [
            'transaction_ids' => ['required', 'array', 'between:1,200'],
            'transaction_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('transactions', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->whereNull('ignored_at')),
            ],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
