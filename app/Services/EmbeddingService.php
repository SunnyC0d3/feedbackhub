<?php

namespace App\Services;

use OpenAI;

class EmbeddingService
{
    private $client;
    private string $model = 'text-embedding-3-small';

    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.api_key'));
    }

    public function generateEmbedding(string $text): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->client->embeddings()->create([
                'model' => $this->model,
                'input' => $text,
            ]);

            $embedding = $response->embeddings[0]->embedding;
            $duration = microtime(true) - $startTime;

            LogService::apiCall('openai', 'embeddings', $duration, [
                'model' => $this->model,
                'input_length' => strlen($text),
                'embedding_dimensions' => count($embedding),
            ]);

            return $embedding;
        } catch (\Exception $e) {
            LogService::apiError('openai', 'embeddings', $e, [
                'input_length' => strlen($text),
            ]);

            throw $e;
        }
    }

    public function generateEmbeddings(array $texts): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->client->embeddings()->create([
                'model' => $this->model,
                'input' => $texts,
            ]);

            $embeddings = [];
            foreach ($response->embeddings as $item) {
                $embeddings[] = $item->embedding;
            }

            $duration = microtime(true) - $startTime;

            LogService::apiCall('openai', 'embeddings_batch', $duration, [
                'model' => $this->model,
                'texts_count' => count($texts),
            ]);

            return $embeddings;
        } catch (\Exception $e) {
            LogService::apiError('openai', 'embeddings_batch', $e);
            throw $e;
        }
    }
}
