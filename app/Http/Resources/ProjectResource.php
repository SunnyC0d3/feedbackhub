<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'division_id'    => $this->division_id,
            'tenant_id'      => $this->tenant_id,
            'feedback_count' => $this->feedbacks_count ?? $this->whenLoaded('feedback', fn () => $this->feedback->count()),
            'division'       => new DivisionResource($this->whenLoaded('division')),
            'created_at'     => $this->created_at->toISOString(),
            'updated_at'     => $this->updated_at->toISOString(),
        ];
    }
}
