<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'query'          => $this->resource['query'],
            'feedback_found' => $this->resource['feedback_found'],
            'summary'        => $this->resource['summary'],
            'feedback'       => FeedbackResource::collection($this->resource['feedback']),
            'usage'          => [
                'tokens_used' => $this->resource['tokens_used'],
                'cost_usd'    => $this->resource['cost_usd'],
            ],
        ];
    }
}
