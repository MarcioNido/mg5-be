<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        $isImportLinked = (bool) ($this->resource->getAttribute('has_import_rows')
            || $this->resource->getAttribute('has_imported_movements'));

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'currency' => $this->account->currency,
            ]),
            'transaction_date' => Carbon::parse($this->transaction_date)->toDateString(),
            'amount' => $this->amount,
            'description' => $this->description,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'origin' => $this->origin->value,
            'posted_at' => $this->posted_at?->toISOString(),
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'splits' => TransactionSplitResource::collection($this->whenLoaded('splits')),
            'is_import_linked' => $isImportLinked,
            'bank_fields_editable' => ! $isImportLinked,
            'deletable' => ! $isImportLinked,
        ];
    }
}
