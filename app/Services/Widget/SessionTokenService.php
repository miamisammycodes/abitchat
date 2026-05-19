<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SessionTokenService
{
    private const ALGORITHM = 'HS256';

    public function __construct(private readonly string $secret) {}

    /**
     * @return array{token: string, expires_at: int}
     */
    public function mint(Tenant $tenant, string $origin, string $ip): array
    {
        $ttl = (int) config('widget.session_ttl', 1800);
        $now = Carbon::now()->timestamp;
        $expiresAt = $now + $ttl;

        $payload = [
            'iss' => config('app.url'),
            // Hashing api_key (not tenant_id) is load-bearing: api_key rotation
            // invalidates all outstanding tokens on next verify. See spec.
            'sub' => hash('sha256', $tenant->api_key.$this->secret),
            'aud' => $origin,
            'ip' => $ip,
            'iat' => $now,
            'exp' => $expiresAt,
        ];

        return [
            'token' => JWT::encode($payload, $this->secret, self::ALGORITHM),
            'expires_at' => $expiresAt,
        ];
    }

    public function verify(string $token, string $origin, string $ip): Tenant
    {
        // Set JWT::$timestamp to Carbon's test-aware now so that Laravel's
        // travel helpers affect expiry checks in tests (and prod uses real time).
        JWT::$timestamp = Carbon::now()->timestamp;

        try {
            $payload = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (ExpiredException|BeforeValidException|SignatureInvalidException $e) {
            throw new InvalidSessionTokenException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            Log::warning('[Widget] Unexpected JWT decode failure', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new InvalidSessionTokenException('Malformed token', 0, $e);
        } finally {
            JWT::$timestamp = null;
        }

        if (($payload->aud ?? null) !== $origin) {
            throw new InvalidSessionTokenException('Origin mismatch');
        }

        if (($payload->iss ?? null) !== config('app.url')) {
            throw new InvalidSessionTokenException('Issuer mismatch');
        }

        if (($payload->ip ?? null) !== $ip) {
            throw new InvalidSessionTokenException('IP mismatch');
        }

        $expectedSub = $payload->sub ?? '';
        // Pre-filter to active tenants only. This is still O(active_tenants) per
        // verify — acceptable for small/medium scale but should become a
        // SHA2(CONCAT(api_key, ?), 256)-based indexed lookup or a dedicated
        // api_key_hash column before the tenant table exceeds ~1k rows.
        // TODO(perf): replace with indexed lookup when scale demands it.
        // In-PHP comparison — works for SQLite (tests) and MySQL; avoids
        // raw SHA2(CONCAT(...), 256) which is MySQL-only.
        $tenant = Tenant::where('status', 'active')->get()->first(
            fn ($t) => hash('sha256', $t->api_key.$this->secret) === $expectedSub
        );

        if ($tenant === null) {
            throw new InvalidSessionTokenException('Tenant not found or api_key rotated');
        }

        return $tenant;
    }
}
