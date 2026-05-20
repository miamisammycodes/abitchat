<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use Firebase\JWT\JWT;
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
        // Octane-safe approach: never touch JWT::$timestamp (a process-wide static
        // that would create a race condition on shared Octane workers). Instead:
        // 1. Manually split the JWT and verify the HS256 signature ourselves.
        // 2. Decode the payload via base64url decode (no library timing check).
        // 3. Validate exp/nbf/iat claims using Carbon::now()->timestamp so that
        //    Laravel's travelTo() / $this->travel() test helpers work correctly.
        //
        // firebase/php-jwt v7.0.5 note: JWT::decode() checks timing claims
        // internally and would require setting JWT::$timestamp to suppress that
        // check. This approach bypasses JWT::decode() for timing entirely.
        // JWT::$timestamp is NEVER mutated — Octane safety is genuine, not just
        // a narrowed mutation window.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidSessionTokenException('Malformed token');
        }

        [$headb64, $bodyb64, $sigb64] = $parts;

        try {
            $headerRaw = JWT::urlsafeB64Decode($headb64);
            $header = json_decode($headerRaw);

            if (! $header || ! isset($header->alg)) {
                throw new InvalidSessionTokenException('Malformed token header');
            }

            if ($header->alg !== self::ALGORITHM) {
                throw new InvalidSessionTokenException('Unexpected algorithm: '.$header->alg);
            }

            // Verify HMAC-SHA256 signature: hash_hmac('SHA256', "$head.$body", $key, raw_output=true)
            $sig = JWT::urlsafeB64Decode($sigb64);
            $expected = hash_hmac('SHA256', "{$headb64}.{$bodyb64}", $this->secret, true);

            if (! hash_equals($expected, $sig)) {
                throw new InvalidSessionTokenException('Signature verification failed');
            }

            $payloadRaw = JWT::urlsafeB64Decode($bodyb64);
            $payload = json_decode($payloadRaw);

            if (! $payload) {
                throw new InvalidSessionTokenException('Malformed token payload');
            }
        } catch (InvalidSessionTokenException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('[Widget] Unexpected JWT decode failure', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new InvalidSessionTokenException('Malformed token', 0, $e);
        }

        // Carbon-based timing validation — test-travel compatible, no static mutation.
        $now = Carbon::now()->timestamp;

        if (isset($payload->exp) && $now >= $payload->exp) {
            throw new InvalidSessionTokenException('Token has expired');
        }

        if (isset($payload->nbf) && $now < $payload->nbf) {
            throw new InvalidSessionTokenException('Token not yet valid');
        }

        if (isset($payload->iat) && ! isset($payload->nbf) && $now < $payload->iat) {
            throw new InvalidSessionTokenException('Token not yet valid (iat in future)');
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
        // O(1) indexed lookup: api_key_hash column has a unique index on `tenants`.
        // `where('api_key_hash', ...)` is not a raw tenant_id query — DEC-05/NoRawTenantIdWhere
        // bans `where('tenant_id', ...)` only. We are resolving a tenant FROM the hash,
        // not filtering a tenant-scoped query, so forTenant() is not applicable here.
        $tenant = Tenant::where('api_key_hash', $expectedSub)
            ->where('status', 'active')
            ->first();

        if ($tenant === null) {
            throw new InvalidSessionTokenException('Tenant not found or api_key rotated');
        }

        return $tenant;
    }
}
