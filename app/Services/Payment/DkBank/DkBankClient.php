<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DkBankClient
{
    private const TOKEN_CACHE_KEY = 'dk_bank:access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 1500;  // 5 min headroom below DK's 1800s

    private ?string $cachedPrivateKey = null;

    public function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_TTL_SECONDS, function () {
            $response = Http::asForm()
                ->timeout((int) config('services.dk_bank.http_timeout'))
                ->withHeaders(['X-gravitee-api-key' => config('services.dk_bank.api_key')])
                ->post(config('services.dk_bank.base_url').'/v1/auth/token', [
                    'username' => config('services.dk_bank.username'),
                    'password' => config('services.dk_bank.password'),
                    'client_id' => config('services.dk_bank.client_id'),
                    'client_secret' => config('services.dk_bank.client_secret'),
                    'grant_type' => 'password',
                    'scopes' => 'keys:read',
                    'source_app' => config('services.dk_bank.source_app'),
                    'request_id' => $this->generateRequestId(),
                ]);

            $body = $response->json();
            if (($body['response_code'] ?? null) !== '0000') {
                throw new \RuntimeException('DK Bank token fetch failed: '.json_encode($body));
            }

            return $body['response_data']['access_token'];
        });
    }

    public function generateRequestId(): string
    {
        return str_replace('-', '', (string) Str::uuid());
    }

    private function canonicalJson(array $body): string
    {
        return json_encode($this->sortKeysRecursive($body), JSON_UNESCAPED_SLASHES);
    }

    private function signBody(array $body, string $timestamp, string $nonce): string
    {
        $canonical = $this->canonicalJson($body);
        $payload = [
            'data' => base64_encode($canonical),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ];

        return JWT::encode($payload, $this->getPrivateKey(), 'RS256');
    }

    private function getPrivateKey(): string
    {
        if ($this->cachedPrivateKey === null) {
            $this->cachedPrivateKey = file_get_contents(config('services.dk_bank.private_key_path'));
        }

        return $this->cachedPrivateKey;
    }

    /**
     * Recursively sort array keys alphabetically. Matches Python's
     * `json.dumps(sort_keys=True)` behavior, which DK's server uses to
     * re-canonicalize the body and verify the signature.
     */
    private function sortKeysRecursive(array $body): array
    {
        ksort($body);
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = $this->sortKeysRecursive($value);
            }
        }

        return $body;
    }
}
