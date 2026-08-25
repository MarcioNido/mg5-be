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
    public function rules(): array
    {
        return [
            'match_text' => ['sometimes', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', Tenant::current()?->getKey())
                        ->whereNull('deleted_at')
                ),
            ],
            'category_id' => [
                'sometimes',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', Tenant::current()?->getKey())
                        ->whereNull('deleted_at')
                ),
            ],
            'content' => ['prohibited'],
            'account' => ['prohibited'],
            'category' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('match_text'))) {
            $this->merge(['match_text' => trim($this->input('match_text'))]);
        }
    }
}
