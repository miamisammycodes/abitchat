import net from 'node:net';
import http from 'node:http';
import dns from 'node:dns/promises';
import { isPrivateIp } from './private-cidr.mjs';

const BIND = '127.0.0.1';

/**
 * Resolve a hostname and return its validated public IPs, or null (fail closed)
 * if it is unresolvable or ANY address is private/reserved. Literal IPs are
 * validated directly. The caller connects to the returned IP — so the IP
 * validated is the IP connected to (no rebinding TOCTOU).
 */
async function resolvePublicIps(hostname) {
  if (net.isIP(hostname)) {
    return isPrivateIp(hostname) ? null : [hostname];
  }
  let addrs;
  try {
    addrs = await dns.lookup(hostname, { all: true });
  } catch {
    return null;
  }
  if (!addrs.length) return null;
  const ips = addrs.map((a) => a.address);
  if (ips.some(isPrivateIp)) return null;
  return ips;
}

function reject(socket) {
  socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
  socket.end();
}

export function startProxy(port = Number(process.env.CRAWLER_EGRESS_PROXY_PORT) || 8118) {
  const server = http.createServer(async (req, res) => {
    // Plain-HTTP forward: Chromium sends an absolute-form request URI.
    let target;
    try {
      target = new URL(req.url);
    } catch {
      res.writeHead(400).end();
      return;
    }
    const ips = await resolvePublicIps(target.hostname);
    if (!ips) {
      res.writeHead(403).end();
      return;
    }
    const upstream = http.request(
      {
        host: ips[0], // pin to validated IP
        port: target.port || 80,
        path: target.pathname + target.search,
        method: req.method,
        headers: { ...req.headers, host: target.host },
      },
      (up) => { res.writeHead(up.statusCode || 502, up.headers); up.pipe(res); },
    );
    upstream.on('error', () => res.writeHead(502).end());
    req.pipe(upstream);
  });

  server.on('connect', async (req, clientSocket, head) => {
    const [hostname, portStr] = req.url.split(':');
    const port = Number(portStr) || 443;

    const ips = await resolvePublicIps(hostname);
    if (!ips) {
      reject(clientSocket);
      return;
    }

    const serverSocket = net.connect(port, ips[0], () => {
      clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      serverSocket.write(head);
      serverSocket.pipe(clientSocket);
      clientSocket.pipe(serverSocket);
    });
    serverSocket.on('error', () => clientSocket.end());
    clientSocket.on('error', () => serverSocket.end());
  });

  return new Promise((resolve) => {
    server.listen(port, BIND, () => {
      // eslint-disable-next-line no-console
      console.log(`[egress-proxy] listening ${BIND}:${server.address().port}`);
      resolve(server);
    });
  });
}

// Run standalone when invoked directly (node resources/node/egress-proxy.mjs [port]).
if (import.meta.url === `file://${process.argv[1]}`) {
  startProxy(Number(process.argv[2]) || undefined);
}
