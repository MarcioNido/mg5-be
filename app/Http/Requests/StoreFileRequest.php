<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFileRequest extends FormRequest
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
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('tenant_id', Tenant::current()?->id)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.extensions' => 'The file must have a .csv or .txt extension.',
            'file.max' => 'The file must not be larger than 10 MB.',
        ];
    }
}
