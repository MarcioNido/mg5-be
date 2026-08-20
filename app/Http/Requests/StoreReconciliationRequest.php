<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'statement_date' => ['required', 'date'],
            'entered_bank_balance' => ['required', 'decimal:0,4'],
        ];
    }
}
