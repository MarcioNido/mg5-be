<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => [
                'sometimes',
                'string',
                'regex:/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/',
                'date_format:Y-m',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! preg_match('/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/', $value)) {
                        return;
                    }

                    $currentMonth = Carbon::now(config('app.business_timezone'))->format('Y-m');

                    if ($value > $currentMonth) {
                        $fail('The month must not be after the current business month.');
                    }
                },
            ],
        ];
    }
}
