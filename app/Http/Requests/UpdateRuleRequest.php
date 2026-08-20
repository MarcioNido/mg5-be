<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRuleRequest extends FormRequest
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
            'content' => 'required|string',
            'account.account_number' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'account_number')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
            'category.id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
        ];
    }
}
