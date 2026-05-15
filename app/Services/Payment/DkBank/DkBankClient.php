<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

final class DkBankClient
{
    private ?string $cachedPrivateKey = null;

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
