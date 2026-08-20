<?php

namespace App\Http\Requests;

use App\Enums\ImportStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'account_id' => [
                'sometimes',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', Tenant::current()?->id)),
            ],
            'status' => ['sometimes', Rule::enum(ImportStatus::class)],
        ];
    }
}
