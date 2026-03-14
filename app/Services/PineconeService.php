<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PineconeService
{
    private string $apiKey;
    private string $environment;
    private string $indexName;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.pinecone.api_key');
        $this->environment = config('services.pinecone.environment');
        $this->indexName = config('services.pinecone.index');

        $customHost = config('services.pinecone.host');

        if ($customHost) {
            $this->baseUrl = "https://{$customHost}";
        } else {
            if (str_contains($this->environment, 'gcp-starter')) {
                $this->baseUrl = "https://{$this->indexName}.svc.{$this->environment}.pinecone.io";
            } elseif (str_contains($this->environment, 'aws')) {
                $this->baseUrl = "https://{$this->indexName}.svc.pinecone.io";
            } else {
                $this->baseUrl = "https://{$this->indexName}-{$this->environment}.svc.pinecone.io";
            }
        }

        LogService::info('Pinecone service initialized', [
            'base_url' => $this->baseUrl,
            'environment' => $this->environment,
        ]);
    }

    public function upsert(array $vectors): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/vectors/upsert", [
                'vectors' => $vectors,
            ]);

            $duration = microtime(true) - $startTime;

            if (!$response->successful()) {
                throw new \Exception("Pinecone upsert failed: " . $response->body());
            }

            LogService::apiCall('pinecone', 'upsert', $duration, [
                'vectors_count' => count($vectors),
                'status' => $response->status(),
            ]);

            return $response->json();
        } catch (\Exception $e) {
            LogService::apiError('pinecone', 'upsert', $e, [
                'vectors_count' => count($vectors),
            ]);

            throw $e;
        }
    }

    public function query(array $vector, int $topK = 5, array $filter = []): array
    {
        $startTime = microtime(true);

        try {
            $payload = [
                'vector' => $vector,
                'topK' => $topK,
                'includeMetadata' => true,
            ];

            if (!empty($filter)) {
                $payload['filter'] = $filter;
            }

            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/query", $payload);

            $duration = microtime(true) - $startTime;

            if (!$response->successful()) {
                throw new \Exception("Pinecone query failed: " . $response->body());
            }

            LogService::apiCall('pinecone', 'query', $duration, [
                'topK' => $topK,
                'results_count' => count($response->json()['matches'] ?? []),
                'status' => $response->status(),
            ]);

            return $response->json();
        } catch (\Exception $e) {
            LogService::apiError('pinecone', 'query', $e);
            throw $e;
        }
    }

    public function delete(array $ids): array
    {
        try {
            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/vectors/delete", [
                'ids' => $ids,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Pinecone delete failed: " . $response->body());
            }

            LogService::info('Pinecone vectors deleted', [
                'ids_count' => count($ids),
            ]);

            return $response->json();
        } catch (\Exception $e) {
            LogService::apiError('pinecone', 'delete', $e);
            throw $e;
        }
    }

    public function describeIndexStats(): array
    {
        try {
            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey,
            ])->withBody('{}', 'application/json')
                ->post("{$this->baseUrl}/describe_index_stats");

            LogService::info('Pinecone stats response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $response->headers(),
            ]);

            if (!$response->successful()) {
                throw new \Exception("Pinecone stats failed (HTTP {$response->status()}): " . $response->body());
            }

            return $response->json();
        } catch (\Exception $e) {
            LogService::apiError('pinecone', 'describe_index_stats', $e, [
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
