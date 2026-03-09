<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    const TTL_SHORT = 300;
    const TTL_MEDIUM = 1800;
    const TTL_LONG = 3600;
    const TTL_DAY = 86400;

    public static function key(string $prefix, ...$parts): string
    {
        $tenantId = auth()->check() ? auth()->user()->tenant_id : 'guest';
        $userId = auth()->id() ?? 'guest';

        return sprintf(
            '%s:%s:%s:%s',
            $prefix,
            $tenantId,
            $userId,
            implode(':', $parts)
        );
    }

    public static function remember(string $key, int $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    public static function forget(string $pattern): void
    {
        $keys = self::getKeysByPattern($pattern);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    private static function getKeysByPattern(string $pattern): array
    {
        $redis = Cache::store('redis')->getRedis();
        return $redis->keys($pattern);
    }

    public static function clearTenantCache(): void
    {
        if (!auth()->check()) {
            return;
        }

        $tenantId = auth()->user()->tenant_id;
        self::forget("*:{$tenantId}:*");
    }

    public static function clearUserCache(): void
    {
        if (!auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $tenantId = auth()->user()->tenant_id;
        self::forget("*:{$tenantId}:{$userId}:*");
    }
}
