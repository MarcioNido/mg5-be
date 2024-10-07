<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
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
            "account.account_number" => [
                "required",
                "string",
                "max:255",
                Rule::exists("accounts", "account_number"),
            ],
            "transaction_date" => ["required", "date"],
            "amount" => ["required", "numeric"],
            "description" => ["required", "string", "max:255"],
            "category.id" => [
                "required",
                "integer",
                Rule::exists("categories", "id"),
            ],
            "rule_content" => ["nullable", "string", "max:255"],
        ];
    }
}
