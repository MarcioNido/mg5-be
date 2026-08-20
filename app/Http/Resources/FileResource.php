<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'filename' => $this->filename,
            'source_name' => $this->source_name,
            'source_type' => $this->source_type,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
            'error_message' => $this->error_message,
            'rows' => $this->whenLoaded('rows'),
            'created_at' => $this->created_at,
        ];
    }
}
