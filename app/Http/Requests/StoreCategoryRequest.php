<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            "name" => ["required", "string", "max:255"],
            "type" => [
                "required",
                Rule::in([
                    "income",
                    "deductions",
                    "fixed expenses",
                    "variable expenses",
                    "financial transactions",
                ]),
            ],
            "parent.id" => ["nullable", "exists:categories,id"],
            //            "level" => ["required", "integer", "min:1", "max:3"],
        ];
    }
}
