<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use ReflectionClass;
use Tests\TestCase;

class DkBankClientCanonicalJsonTest extends TestCase
{
    private function invokeCanonicalJson(array $body): string
    {
        $client = $this->app->make(DkBankClient::class);
        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('canonicalJson');
        $method->setAccessible(true);

        return $method->invoke($client, $body);
    }

    public function test_sorts_keys_alphabetically(): void
    {
        $output = $this->invokeCanonicalJson(['z' => 1, 'a' => 2, 'm' => 3]);
        $this->assertSame('{"a":2,"m":3,"z":1}', $output);
    }

    public function test_sorts_keys_recursively_in_nested_arrays(): void
    {
        $output = $this->invokeCanonicalJson([
            'outer_z' => ['inner_z' => 1, 'inner_a' => 2],
            'outer_a' => 3,
        ]);
        $this->assertSame('{"outer_a":3,"outer_z":{"inner_a":2,"inner_z":1}}', $output);
    }

    public function test_escapes_non_ascii_characters(): void
    {
        // Em-dash (U+2014) must be escaped to — to match Python's
        // json.dumps(ensure_ascii=True) on DK's server. Without escape,
        // the signature would mismatch.
        $output = $this->invokeCanonicalJson(['remarks' => 'Payment — order']);
        $this->assertStringContainsString('\\u2014', $output);
        $this->assertStringNotContainsString('—', $output);
    }

    public function test_leaves_forward_slashes_unescaped(): void
    {
        $output = $this->invokeCanonicalJson(['url' => 'https://example.com/path']);
        $this->assertStringContainsString('https://example.com/path', $output);
        $this->assertStringNotContainsString('https:\/\/', $output);
    }

    public function test_no_whitespace_between_tokens(): void
    {
        $output = $this->invokeCanonicalJson(['a' => 1, 'b' => 2]);
        $this->assertStringNotContainsString(' ', $output);
        $this->assertStringNotContainsString("\n", $output);
    }
}
