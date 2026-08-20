<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

class ImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $normalized = $this->normalized_payload ?? [];
        $amount = $this->decimalAmount($normalized['amount'] ?? null);

        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'status' => $this->status->value,
            'transaction_id' => $this->transaction_id,
            'error_message' => $this->error_message,
            'transaction_date' => $this->when(
                array_key_exists('transaction_date', $normalized),
                $normalized['transaction_date'] ?? null
            ),
            'description' => $this->when(
                array_key_exists('description', $normalized),
                $normalized['description'] ?? null
            ),
            'amount' => $this->when($amount !== null, $amount),
        ];
    }

    private function decimalAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        try {
            return Money::decimal(Money::units($amount));
        } catch (Throwable) {
            return null;
        }
    }
}
