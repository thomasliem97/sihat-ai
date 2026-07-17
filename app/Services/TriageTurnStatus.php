<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TriageTurnStatus
{
    public const STALE_AFTER_SECONDS = 300;

    /**
     * @return array{status: string, message?: string, started_at?: int}|null
     */
    public static function get(int $sessionId): ?array
    {
        $value = Cache::get(self::key($sessionId));

        if (! is_array($value)) {
            return null;
        }

        if (($value['status'] ?? null) === 'processing') {
            $startedAt = (int) ($value['started_at'] ?? 0);

            if ($startedAt > 0 && (now()->getTimestamp() - $startedAt) >= self::STALE_AFTER_SECONDS) {
                self::markFailed($sessionId, 'Voice processing timed out. Please try again.');

                $value = Cache::get(self::key($sessionId));

                if (! is_array($value)) {
                    return null;
                }
            }
        }

        return self::normalize($value);
    }

    public static function markProcessing(int $sessionId): void
    {
        Cache::put(self::key($sessionId), [
            'status' => 'processing',
            'started_at' => now()->getTimestamp(),
        ], now()->addMinutes(15));
    }

    public static function markFailed(int $sessionId, string $message): void
    {
        Cache::put(self::key($sessionId), [
            'status' => 'failed',
            'message' => $message,
        ], now()->addMinutes(5));
    }

    public static function clear(int $sessionId): void
    {
        Cache::forget(self::key($sessionId));
    }

    private static function key(int $sessionId): string
    {
        return "triage.pending.{$sessionId}";
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array{status: string, message?: string, started_at?: int}|null
     */
    private static function normalize(array $value): ?array
    {
        $status = $value['status'] ?? null;

        if (! is_string($status) || $status === '') {
            return null;
        }

        $normalized = ['status' => $status];

        if (isset($value['message']) && is_string($value['message'])) {
            $normalized['message'] = $value['message'];
        }

        if (isset($value['started_at']) && is_numeric($value['started_at'])) {
            $normalized['started_at'] = (int) $value['started_at'];
        }

        return $normalized;
    }
}
