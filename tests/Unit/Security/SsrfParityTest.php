<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SsrfParityTest extends TestCase
{
    public function test_js_is_private_ip_matches_php_safe_external_url_for_every_fixture_ip(): void
    {
        if (! shell_exec('command -v node')) {
            $this->markTestSkipped('node not available');
        }

        $fixture = base_path('tests/fixtures/ssrf-ip-cases.json');
        $module = base_path('resources/node/private-cidr.mjs');
        $cases = json_decode((string) file_get_contents($fixture), true);

        $script = sprintf(
            'import { isPrivateIp } from %s; '.
            'import { readFileSync } from "node:fs"; '.
            'const c = JSON.parse(readFileSync(%s, "utf8")); '.
            'const out = {}; for (const ip of Object.keys(c)) out[ip] = isPrivateIp(ip); '.
            'process.stdout.write(JSON.stringify(out));',
            json_encode($module),
            json_encode($fixture),
        );

        $result = Process::run(['node', '--input-type=module', '-e', $script]);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $jsVerdicts = json_decode($result->output(), true);

        foreach ($cases as $ip => $_) {
            $this->assertSame(
                ! SafeExternalUrl::isSafeIp($ip),
                $jsVerdicts[$ip],
                "JS and PHP disagree on {$ip}",
            );
        }
    }
}
