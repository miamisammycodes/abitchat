<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SafeExternalUrlTest extends TestCase
{
    private function fails(string $url): bool
    {
        $validator = Validator::make(
            ['url' => $url],
            ['url' => [new SafeExternalUrl]]
        );

        return $validator->fails();
    }

    public function test_loopback_ipv4_rejected(): void
    {
        $this->assertTrue($this->fails('http://127.0.0.1/admin'));
    }

    public function test_aws_metadata_ip_rejected(): void
    {
        $this->assertTrue($this->fails('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_rfc1918_private_ip_rejected(): void
    {
        $this->assertTrue($this->fails('http://10.0.0.1/'));
    }

    public function test_public_url_passes(): void
    {
        $this->assertFalse($this->fails('https://example.com/page'));
    }

    public function test_hostname_resolving_to_private_ip_rejected(): void
    {
        // localhost typically resolves to 127.0.0.1 (or ::1) on every system.
        $this->assertTrue($this->fails('http://localhost/admin'));
    }

    public function test_ipv4_mapped_ipv6_loopback_rejected(): void
    {
        $this->assertTrue($this->fails('http://[::ffff:127.0.0.1]/admin'));
    }

    public function test_ipv4_mapped_ipv6_aws_metadata_rejected(): void
    {
        $this->assertTrue($this->fails('http://[::ffff:169.254.169.254]/latest/meta-data/'));
    }

    public function test_ipv4_mapped_ipv6_rfc1918_rejected(): void
    {
        $this->assertTrue($this->fails('http://[::ffff:10.0.0.1]/'));
    }

    public function test_zero_address_rejected(): void
    {
        $this->assertTrue($this->fails('http://0.0.0.0/'));
    }

    public function test_unspecified_ipv6_rejected(): void
    {
        $this->assertTrue($this->fails('http://[::]/'));
    }

    public function test_ipv4_mapped_ipv6_hex_segment_form_rejected(): void
    {
        // ::ffff:7f00:1 is the hex-segment form of ::ffff:127.0.0.1
        $this->assertTrue($this->fails('http://[::ffff:7f00:1]/'));
    }

    public function test_ipv4_mapped_ipv6_hex_aws_metadata_rejected(): void
    {
        // ::ffff:a9fe:a9fe is the hex-segment form of ::ffff:169.254.169.254
        $this->assertTrue($this->fails('http://[::ffff:a9fe:a9fe]/latest/meta-data/'));
    }

    public function test_ipv4_mapped_ipv6_uncompressed_dotted_rejected(): void
    {
        $this->assertTrue($this->fails('http://[0:0:0:0:0:ffff:127.0.0.1]/'));
    }

    public function test_ipv4_mapped_ipv6_uncompressed_hex_rejected(): void
    {
        $this->assertTrue($this->fails('http://[0:0:0:0:0:ffff:7f00:1]/'));
    }

    public function test_is_safe_ip_matches_the_shared_adversarial_fixture(): void
    {
        $cases = json_decode(
            file_get_contents(base_path('tests/fixtures/ssrf-ip-cases.json')),
            true
        );

        foreach ($cases as $ip => $expectedPrivate) {
            $this->assertSame(
                ! $expectedPrivate,
                SafeExternalUrl::isSafeIp($ip),
                "isSafeIp({$ip}) should be ".($expectedPrivate ? 'false' : 'true')
            );
        }
    }

    public function test_resolve_public_ips_returns_validated_set_for_literal_public_ip(): void
    {
        $this->assertSame(['1.1.1.1'], SafeExternalUrl::resolvePublicIps('1.1.1.1'));
    }

    public function test_resolve_public_ips_fails_closed_for_literal_private_ip(): void
    {
        $this->assertSame([], SafeExternalUrl::resolvePublicIps('127.0.0.1'));
        $this->assertSame([], SafeExternalUrl::resolvePublicIps('169.254.169.254'));
    }

    public function test_resolve_public_ips_fails_closed_for_unresolvable_host(): void
    {
        $this->assertSame([], SafeExternalUrl::resolvePublicIps('no-such-host.invalid'));
    }
}
