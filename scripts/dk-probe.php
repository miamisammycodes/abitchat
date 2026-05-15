<?php

/**
 * DK Bank Task 0 — UAT verification probes.
 *
 * Usage:
 *   php scripts/dk-probe.php 1 [--reference DKQR-...] [--save]
 *   php scripts/dk-probe.php 2 <reference_no>
 *   php scripts/dk-probe.php 3
 *   php scripts/dk-probe.php 4 <rrn>
 *   php scripts/dk-probe.php 5
 *   php scripts/dk-probe.php 6 <reference_no> +N
 *
 * One-off helper. Delete after Task 0 results are documented.
 *
 * Two-API-key handling: DK's UAT spec page 4 specifies DIFFERENT
 * X-gravitee-api-key values for /v1/auth/token + /v1/sign/key vs. every
 * other endpoint. We honor that by reading DK_BANK_API_KEY_AUTH for the
 * first two endpoints and DK_BANK_API_KEY for everything else. If only
 * DK_BANK_API_KEY is set we use it for both.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Firebase\JWT\JWT;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = $argv;
array_shift($args);
$probe = (int) ($args[0] ?? 0);

if ($probe < 1 || $probe > 6) {
    echo "Usage: php scripts/dk-probe.php <1-6> [...args]\n";
    exit(1);
}

$config = [
    'base_url' => env('DK_BANK_BASE_URL') ?: config('services.dk_bank.base_url'),
    'api_key' => env('DK_BANK_API_KEY') ?: config('services.dk_bank.api_key'),
    'api_key_auth' => env('DK_BANK_API_KEY_AUTH') ?: env('DK_BANK_API_KEY') ?: config('services.dk_bank.api_key'),
    'username' => env('DK_BANK_USERNAME') ?: config('services.dk_bank.username'),
    'password' => env('DK_BANK_PASSWORD') ?: config('services.dk_bank.password'),
    'client_id' => env('DK_BANK_CLIENT_ID') ?: config('services.dk_bank.client_id'),
    'client_secret' => env('DK_BANK_CLIENT_SECRET') ?: config('services.dk_bank.client_secret'),
    'source_app' => env('DK_BANK_SOURCE_APP') ?: config('services.dk_bank.source_app'),
    'beneficiary' => env('DK_BANK_BENEFICIARY_ACCOUNT') ?: config('services.dk_bank.beneficiary_account'),
    'mcc_code' => env('DK_BANK_MCC_CODE') ?: config('services.dk_bank.mcc_code') ?: '5817',
    'pem_path' => storage_path('app/dk_pg.pem'),
];

foreach (['base_url', 'username', 'password', 'client_id', 'client_secret', 'source_app', 'beneficiary'] as $required) {
    if (empty($config[$required])) {
        echo '✗ Missing config: DK_BANK_'.strtoupper($required)."\n";
        echo "  Check your .env per the runbook prerequisites section.\n";
        exit(1);
    }
}

$http = new Guzzle(['timeout' => 30, 'verify' => false, 'http_errors' => false]);

function requestId(): string
{
    return str_replace('-', '', (string) Str::uuid());
}

function canonicalJson(array $body): string
{
    $sort = function (array $b) use (&$sort): array {
        ksort($b);
        foreach ($b as $k => $v) {
            if (is_array($v)) {
                $b[$k] = $sort($v);
            }
        }

        return $b;
    };

    return json_encode($sort($body), JSON_UNESCAPED_SLASHES);
}

function fetchToken(Guzzle $http, array $config): string
{
    echo "  → POST {$config['base_url']}/v1/auth/token\n";

    $response = $http->post($config['base_url'].'/v1/auth/token', [
        'headers' => ['X-gravitee-api-key' => $config['api_key_auth']],
        'form_params' => [
            'username' => $config['username'],
            'password' => $config['password'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'password',
            'scopes' => 'keys:read',
            'source_app' => $config['source_app'],
            'request_id' => requestId(),
        ],
    ]);

    $body = json_decode((string) $response->getBody(), true);

    if (($body['response_code'] ?? null) !== '0000') {
        echo "  ✗ Token fetch failed:\n";
        echo '    '.json_encode($body, JSON_PRETTY_PRINT)."\n";
        exit(1);
    }

    echo '  ✓ response_code 0000, access_token starts with '.substr($body['response_data']['access_token'], 0, 10)."...\n";

    return $body['response_data']['access_token'];
}

function fetchOrLoadKey(Guzzle $http, array $config, string $token): string
{
    if (file_exists($config['pem_path']) && filesize($config['pem_path']) > 0) {
        echo "  ✓ Using existing PEM at {$config['pem_path']}\n";

        return file_get_contents($config['pem_path']);
    }

    echo "  → POST {$config['base_url']}/v1/sign/key\n";

    $response = $http->post($config['base_url'].'/v1/sign/key', [
        'headers' => [
            'X-gravitee-api-key' => $config['api_key_auth'],
            'Authorization' => 'bearer '.$token,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'request_id' => requestId(),
            'source_app' => $config['source_app'],
        ]),
    ]);

    $body = (string) $response->getBody();

    if (! str_contains($body, 'BEGIN RSA PRIVATE KEY') && ! str_contains($body, 'BEGIN PRIVATE KEY')) {
        echo "  ✗ Key fetch response is not a PEM:\n";
        echo '    '.substr($body, 0, 300)."\n";
        exit(1);
    }

    file_put_contents($config['pem_path'], $body);
    chmod($config['pem_path'], 0600);
    echo "  ✓ PEM written to {$config['pem_path']} (mode 0600), ".filesize($config['pem_path'])." bytes\n";

    return $body;
}

function signedPost(Guzzle $http, array $config, string $endpoint, array $body, string $token, string $privateKey): array
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $nonce = requestId();
    $canonical = canonicalJson($body);

    $payload = [
        'data' => base64_encode($canonical),
        'timestamp' => $timestamp,
        'nonce' => $nonce,
    ];
    $signature = JWT::encode($payload, $privateKey, 'RS256');

    echo "  → POST {$config['base_url']}{$endpoint}\n";
    echo '    request body: '.substr(json_encode($body), 0, 200)."\n";

    $response = $http->post($config['base_url'].$endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-gravitee-api-key' => $config['api_key'],
            'Authorization' => 'bearer '.$token,
            'DK-Timestamp' => $timestamp,
            'DK-Nonce' => $nonce,
            'DK-Signature' => 'DKSignature '.$signature,
            'source_app' => $config['source_app'],
        ],
        'body' => json_encode($body),
    ]);

    return json_decode((string) $response->getBody(), true) ?? [];
}

// ============ Probes ============

function probe1(Guzzle $http, array $config, array $args): void
{
    $reference = null;
    $save = false;
    for ($i = 1; $i < count($args); $i++) {
        if ($args[$i] === '--reference' && isset($args[$i + 1])) {
            $reference = $args[++$i];
        } elseif ($args[$i] === '--save') {
            $save = true;
        }
    }
    $reference ??= 'DKQR-PROBE1-'.strtoupper(Str::random(6));

    echo "[Probe 1] Step A: fetching access token...\n";
    $token = fetchToken($http, $config);

    echo "\n[Probe 1] Step B: fetching RSA private key...\n";
    $key = fetchOrLoadKey($http, $config, $token);

    echo "\n[Probe 1] Step C: generating QR with reference {$reference}...\n";
    $response = signedPost($http, $config, '/v1/generate_qr', [
        'request_id' => requestId(),
        'currency' => 'BTN',
        'bene_account_number' => $config['beneficiary'],
        'amount' => 1.0,
        'mcc_code' => $config['mcc_code'],
        'remarks' => 'Probe 1',
        'reference_no' => $reference,
    ], $token, $key);

    if (($response['response_code'] ?? null) !== '0000') {
        echo "  ✗ FAILED:\n";
        echo '    '.json_encode($response, JSON_PRETTY_PRINT)."\n";
        exit(1);
    }

    $imageLen = strlen($response['response_data']['image'] ?? '');
    echo "  ✓ response_code 0000, image is {$imageLen} bytes base64\n";

    if ($save) {
        if (! is_dir(__DIR__.'/../tmp')) {
            mkdir(__DIR__.'/../tmp', 0755, true);
        }
        $pngPath = __DIR__."/../tmp/dk-qr-{$reference}.png";
        file_put_contents($pngPath, base64_decode($response['response_data']['image']));
        echo "  ✓ QR PNG saved to {$pngPath}\n";
        echo "    Open this file on your monitor and scan from your phone.\n";
    }

    echo "\n[Probe 1] PASSED — signing works end-to-end against UAT.\n";
    echo "         Reference used: {$reference}\n";
}

function probe2or6(Guzzle $http, array $config, array $args, int $probeNum): void
{
    if (! isset($args[1])) {
        echo "Usage: php scripts/dk-probe.php {$probeNum} <reference_no> ".($probeNum === 6 ? '+N' : '')."\n";
        exit(1);
    }

    $reference = $args[1];
    $dateOffset = 0;
    if ($probeNum === 6 && isset($args[2]) && preg_match('/^([+-]?\d+)$/', $args[2], $m)) {
        $dateOffset = (int) $m[1];
    }

    $token = fetchToken($http, $config);
    $key = fetchOrLoadKey($http, $config, $token);

    $date = (new DateTime('now', new DateTimeZone('UTC')))->modify("{$dateOffset} day")->format('Y-m-d');

    echo "\n[Probe {$probeNum}] Calling /v1/intra-transaction/status with reference {$reference}, date {$date}...\n";
    $response = signedPost($http, $config, '/v1/intra-transaction/status', [
        'request_id' => requestId(),
        'reference_no' => $reference,
        'transaction_date' => $date,
        'bene_account_number' => $config['beneficiary'],
    ], $token, $key);

    echo "\n  Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

    $code = $response['response_code'] ?? null;
    $status = $response['response_data'][0]['status'] ?? null;

    if ($code === '0000' && $status === '0') {
        echo "[Probe {$probeNum}] ✓ PASSED — credit confirmed.\n";
    } elseif ($code === '3001') {
        echo "[Probe {$probeNum}] response 3001 — not found.\n";
        echo "  If this is Probe 2, the DK→DK payment hasn't landed yet (or wasn't initiated).\n";
        echo "  If this is Probe 6, DK's transaction_date param is STRICT — keep two-candidate logic in Task 16.\n";
    } else {
        echo "[Probe {$probeNum}] Unexpected response — see above.\n";
    }
}

function probe3(Guzzle $http, array $config): void
{
    $token = fetchToken($http, $config);
    $key = fetchOrLoadKey($http, $config, $token);

    $fakeRrn = 'FAKERRN'.random_int(100000000, 999999999);

    echo "\n[Probe 3] Calling /v1/intra-transaction/status with fabricated RRN {$fakeRrn}...\n";
    $response = signedPost($http, $config, '/v1/intra-transaction/status', [
        'request_id' => requestId(),
        'reference_no' => $fakeRrn,
        'transaction_date' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d'),
        'bene_account_number' => $config['beneficiary'],
    ], $token, $key);

    echo "\n  Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    if (($response['response_code'] ?? null) === '3001') {
        echo "\n[Probe 3] ✓ PASSED — fabricated RRN returns 3001 as documented.\n";
    } else {
        echo "\n[Probe 3] Unexpected — record this in the results file.\n";
    }
}

function probe4(Guzzle $http, array $config, array $args): void
{
    if (! isset($args[1])) {
        echo "Usage: php scripts/dk-probe.php 4 <real-bank-RRN>\n";
        echo "\nFirst pay Nu. 1.00 from a non-DK Bhutanese bank app by scanning a DK QR\n";
        echo "(generate one with: php scripts/dk-probe.php 1 --reference DKQR-PROBE4-001 --save)\n";
        echo "Then grab the RRN/JRNL/TxnRef from your bank's receipt and pass it here.\n";
        exit(1);
    }

    $rrn = $args[1];
    echo "[Probe 4] 🚨 BLOCKING probe — outcome decides whether RRN-path tasks ship.\n\n";

    $token = fetchToken($http, $config);
    $key = fetchOrLoadKey($http, $config, $token);

    $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

    echo "\n[Probe 4] Calling /v1/intra-transaction/status with cross-bank RRN {$rrn}, date {$date}...\n";
    $response = signedPost($http, $config, '/v1/intra-transaction/status', [
        'request_id' => requestId(),
        'reference_no' => $rrn,
        'transaction_date' => $date,
        'bene_account_number' => $config['beneficiary'],
    ], $token, $key);

    echo "\n  Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

    $code = $response['response_code'] ?? null;
    $status = $response['response_data'][0]['status'] ?? null;

    if ($code === '0000' && $status === '0') {
        echo "[Probe 4] ✓ PASSED — cross-bank verification works. RRN-path tasks STAY in the plan.\n";
        echo "         Tasks 17, 19's verifyRrn, 20's RRN tests, 21's RRN UI are GREEN-LIT.\n";
    } elseif ($code === '3001') {
        echo "[Probe 4] ✗ FAILED — DK returned 3001 (not found) for a real cross-bank RRN.\n";
        echo "         This contradicts DK's email. Cross-bank auto-verification is NOT supported.\n";
        echo "         IMPACT: Cut Tasks 17, 19's verifyRrn, 20's RRN tests, 21's RRN UI.\n";
        echo "         Ship DK→DK only. Non-DK falls back to existing manual transaction-number form.\n";
        echo "         → Update results file + ping the controller.\n";
    } else {
        echo "[Probe 4] Unexpected response. Pause + escalate to DK before deciding.\n";
    }
}

function probe5(Guzzle $http, array $config): void
{
    echo "[Probe 5] Flushing token cache...\n";
    Cache::forget('dk_bank:access_token');

    echo "[Probe 5] First QR call (cold cache)...\n";
    $t1Start = microtime(true);
    fetchToken($http, $config);
    Cache::put('dk_bank:access_token', 'observed', 1500);
    $t1 = (microtime(true) - $t1Start) * 1000;

    echo '  duration: '.number_format($t1, 0)."ms\n\n";

    echo "[Probe 5] Second token call (should hit cache, not network)...\n";
    $t2Start = microtime(true);
    $cached = Cache::get('dk_bank:access_token');
    $t2 = (microtime(true) - $t2Start) * 1000;

    echo '  duration: '.number_format($t2, 2)."ms\n";
    echo "  Cache::get('dk_bank:access_token') = ".substr((string) $cached, 0, 16)."...\n\n";

    if ($t2 < ($t1 / 10)) {
        echo "[Probe 5] ✓ PASSED — cache hit is >10x faster than cold fetch.\n";
    } else {
        echo "[Probe 5] ✗ Caching may not be working — second call should be sub-ms.\n";
    }
}

// Dispatch
try {
    match ($probe) {
        1 => probe1($http, $config, $args),
        2 => probe2or6($http, $config, $args, 2),
        3 => probe3($http, $config),
        4 => probe4($http, $config, $args),
        5 => probe5($http, $config),
        6 => probe2or6($http, $config, $args, 6),
    };
} catch (RequestException $e) {
    echo "\n✗ HTTP error: ".$e->getMessage()."\n";
    if ($e->getResponse()) {
        echo '  Response body: '.(string) $e->getResponse()->getBody()."\n";
    }
    exit(1);
} catch (Throwable $e) {
    echo "\n✗ Error: ".$e->getMessage()."\n";
    echo '  File: '.$e->getFile().':'.$e->getLine()."\n";
    exit(1);
}
