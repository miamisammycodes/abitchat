import net from 'node:net';

// Mirror of app/Rules/SafeExternalUrl::isPrivateIp. Kept in lockstep by
// tests/Unit/Security/SsrfParityTest.php — change both or the parity test fails.
const blocks = new net.BlockList();
blocks.addSubnet('0.0.0.0', 8, 'ipv4');
blocks.addSubnet('10.0.0.0', 8, 'ipv4');
blocks.addSubnet('100.64.0.0', 10, 'ipv4');
blocks.addSubnet('127.0.0.0', 8, 'ipv4');
blocks.addSubnet('169.254.0.0', 16, 'ipv4');
blocks.addSubnet('172.16.0.0', 12, 'ipv4');
blocks.addSubnet('192.0.0.0', 24, 'ipv4');
blocks.addSubnet('192.168.0.0', 16, 'ipv4');
blocks.addSubnet('198.18.0.0', 15, 'ipv4');
blocks.addSubnet('224.0.0.0', 4, 'ipv4');
blocks.addSubnet('240.0.0.0', 4, 'ipv4');
blocks.addAddress('::1', 'ipv6');
blocks.addSubnet('fc00::', 7, 'ipv6');
blocks.addSubnet('fe80::', 10, 'ipv6');
blocks.addSubnet('ff00::', 8, 'ipv6');

/** Expand an IPv6 string to its 16 bytes, or null if not IPv6. */
function v6Bytes(ip) {
  if (!net.isIPv6(ip)) return null;
  let [head, tail] = ip.split('::');
  const headGroups = head ? head.split(':') : [];
  const tailGroups = tail !== undefined ? (tail ? tail.split(':') : []) : null;

  // An embedded dotted IPv4 in the last group (e.g. ::ffff:1.2.3.4) → two hextets.
  const toHextets = (groups) => {
    const out = [];
    for (const g of groups) {
      if (g.includes('.')) {
        const o = g.split('.').map(Number);
        out.push(((o[0] << 8) | o[1]) >>> 0, ((o[2] << 8) | o[3]) >>> 0);
      } else {
        out.push(parseInt(g || '0', 16));
      }
    }
    return out;
  };

  let hextets;
  if (tailGroups === null) {
    hextets = toHextets(headGroups);
  } else {
    const h = toHextets(headGroups);
    const t = toHextets(tailGroups);
    const fill = 8 - h.length - t.length;
    hextets = [...h, ...Array(fill).fill(0), ...t];
  }

  const bytes = [];
  for (const x of hextets) {
    bytes.push((x >> 8) & 0xff, x & 0xff);
  }
  return bytes.length === 16 ? bytes : null;
}

/** Normalize IPv4-mapped (::ffff:/96) and NAT64 (64:ff9b::/96) to the embedded IPv4. */
function normalize(ip) {
  if (net.isIPv4(ip)) return ip;
  const b = v6Bytes(ip);
  if (b === null) return ip; // not normalizable here

  const isMapped = b.slice(0, 10).every((x) => x === 0) && b[10] === 0xff && b[11] === 0xff;
  const isNat64 = b[0] === 0x00 && b[1] === 0x64 && b[2] === 0xff && b[3] === 0x9b
    && b.slice(4, 12).every((x) => x === 0);
  if (isMapped || isNat64) {
    return `${b[12]}.${b[13]}.${b[14]}.${b[15]}`;
  }
  return ip;
}

export function isPrivateIp(ip) {
  if (ip === '0.0.0.0' || ip === '::') return true;

  const norm = normalize(ip);
  if (net.isIPv4(norm)) return blocks.check(norm, 'ipv4');
  if (net.isIPv6(norm)) return blocks.check(norm, 'ipv6');
  return true; // unparseable → fail closed
}
