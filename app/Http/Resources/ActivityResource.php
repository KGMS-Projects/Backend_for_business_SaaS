<?php

namespace App\Http\Resources;

use App\Models\RequestActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestActivity */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'comment' => $this->comment,
            'meta' => $this->meta,
            'actor' => UserResource::make($this->whenLoaded('actor')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
