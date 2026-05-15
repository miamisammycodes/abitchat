<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank;

final class DkBankClient
{
    private function canonicalJson(array $body): string
    {
        return json_encode($this->sortKeysRecursive($body), JSON_UNESCAPED_SLASHES);
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
