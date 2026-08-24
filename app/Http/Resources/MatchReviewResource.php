<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class MatchReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        $importedDate = $this->transaction->transaction_date;
        $candidates = $this->suggestions->sort(function ($left, $right) use ($importedDate): int {
            $confidence = strcmp($right->confidence, $left->confidence);
            if ($confidence !== 0) {
                return $confidence;
            }

            $leftDifference = Carbon::parse($left->pendingTransaction->transaction_date)->diffInDays($importedDate, true);
            $rightDifference = Carbon::parse($right->pendingTransaction->transaction_date)->diffInDays($importedDate, true);

            return $leftDifference <=> $rightDifference ?: $left->id <=> $right->id;
        })->values();

        return [
            'id' => $this->id,
            'account' => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'currency' => $this->account->currency,
            ],
            'import' => [
                'id' => $this->import->id,
                'original_filename' => $this->import->original_filename,
                'source_name' => $this->import->source_name,
                'created_at' => $this->import->created_at->toISOString(),
            ],
            'line_number' => $this->line_number,
            'imported_transaction' => new MatchTransactionResource($this->transaction),
            'candidates' => MatchCandidateResource::collection($candidates),
        ];
    }
}
