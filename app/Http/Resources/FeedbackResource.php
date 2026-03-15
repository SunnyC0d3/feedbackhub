<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'project_id'  => $this->project_id,
            'user_id'     => $this->user_id,
            'tenant_id'   => $this->tenant_id,
            'project'     => new ProjectResource($this->whenLoaded('project')),
            'author'      => new UserResource($this->whenLoaded('user')),
            'created_at'  => $this->created_at->toISOString(),
            'updated_at'  => $this->updated_at->toISOString(),
        ];
    }
}
