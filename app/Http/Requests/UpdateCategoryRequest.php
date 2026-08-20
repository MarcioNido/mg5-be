<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => [
                'required',
                Rule::in([
                    'income',
                    'deductions',
                    'fixed expenses',
                    'variable expenses',
                    'financial transactions',
                ]),
            ],
            'parent.id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
            //            "level" => ["required", "integer", "min:1", "max:3"],
        ];
    }
}
