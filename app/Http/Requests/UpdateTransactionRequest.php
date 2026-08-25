<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'account_id' => [
                'sometimes',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())->whereNull('deleted_at')
                ),
            ],
            'transaction_date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'decimal:0,4'],
            'description' => ['sometimes', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())->whereNull('deleted_at')
                ),
            ],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['pending', 'posted'])],
            'origin' => ['prohibited'],
            'splits' => ['sometimes', 'array'],
            'splits.*.category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('tenant_id', Tenant::current()?->getKey())
                ),
            ],
            'splits.*.amount' => ['required', 'decimal:0,4'],
            'splits.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $transaction = $this->route('transaction');
            if (! $transaction
                || $validator->errors()->hasAny(['account_id', 'transaction_date', 'amount', 'status'])) {
                return;
            }

            if (! $transaction->isLinkedToImport()) {
                return;
            }

            $changesBankData = ($this->has('account_id') && $this->integer('account_id') !== $transaction->account_id)
                || ($this->has('transaction_date') && Carbon::parse($this->input('transaction_date'))->toDateString() !== $transaction->transaction_date)
                || ($this->has('amount') && Money::units($this->input('amount')) !== Money::units($transaction->amount))
                || ($this->has('status') && $this->input('status') !== $transaction->status->value);

            if ($changesBankData) {
                $validator->errors()->add('transaction', 'Bank fields of an imported transaction are read-only.');
            }
        }];
    }
}
