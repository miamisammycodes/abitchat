import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { isPrivateIp } from './private-cidr.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const cases = JSON.parse(readFileSync(join(here, '../../tests/fixtures/ssrf-ip-cases.json'), 'utf8'));

test('isPrivateIp matches the shared adversarial fixture', () => {
  for (const [ip, expected] of Object.entries(cases)) {
    assert.equal(isPrivateIp(ip), expected, `isPrivateIp(${ip}) should be ${expected}`);
  }
});
