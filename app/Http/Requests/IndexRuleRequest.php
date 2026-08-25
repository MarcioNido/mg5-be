<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'account_id' => ['sometimes', 'integer', Rule::exists('accounts', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')
            )],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')
            )],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
