import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import net from 'node:net';
import { startProxy } from './egress-proxy.mjs';

let proxy;
let port;

before(async () => {
  proxy = await startProxy(0); // ephemeral port
  port = proxy.address().port;
});

after(() => proxy.close());

function connectThrough(target) {
  return new Promise((resolve, reject) => {
    const sock = net.connect(port, '127.0.0.1', () => {
      sock.write(`CONNECT ${target} HTTP/1.1\r\nHost: ${target}\r\n\r\n`);
    });
    let buf = '';
    sock.on('data', (d) => {
      buf += d.toString();
      if (buf.includes('\r\n\r\n')) { sock.end(); resolve(buf.split('\r\n')[0]); }
    });
    sock.on('error', reject);
    sock.setTimeout(3000, () => { sock.destroy(); reject(new Error('timeout')); });
  });
}

test('refuses CONNECT to a private literal IP', async () => {
  const statusLine = await connectThrough('127.0.0.1:443');
  assert.match(statusLine, /403/);
});

test('refuses CONNECT to the cloud metadata IP', async () => {
  const statusLine = await connectThrough('169.254.169.254:80');
  assert.match(statusLine, /403/);
});

test('refuses CONNECT to a host that resolves to a private IP', async () => {
  // localhost resolves to 127.0.0.1 → must be rejected by resolve-and-validate.
  const statusLine = await connectThrough('localhost:443');
  assert.match(statusLine, /403/);
});
