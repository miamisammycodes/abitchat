# Crawler SSRF Hardening — Deploy & Ops Notes

**PR:** crawler SSRF hardening + render robustness
**Spec:** `docs/superpowers/specs/2026-06-01-crawler-ssrf-hardening-design.md`
**Plan:** `docs/superpowers/plans/2026-06-01-crawler-ssrf-hardening.md`

This PR closes the crawler's SSRF surface (redirect SSRF, DNS-rebinding TOCTOU,
denylist gaps), makes the Chromium render path rebinding-safe via a Node
validate-and-pin egress proxy, and fixes two adjacent robustness bugs
(empty-chunk heal-loop, unbounded render cost).

> **`CRAWLER_JS_RENDERING` stays OFF.** This PR only makes it *safe* to flip
> later. No behavior change ships to merchants until the flag is enabled, and
> the fail-closed interlock keeps rendering off until the egress proxy is wired.

---

## 1. What ships green at merge (no ops action required)

The pure-PHP HTTP-fetch SSRF fix (`GuardedHttpClient` + hardened
`SafeExternalUrl` + all five crawler fetchers routed through the chokepoint) is
live the moment this merges. It requires **no** new infrastructure: redirects
are followed in a manual per-hop loop that re-resolves and pins each hop to a
validated public IP via `CURLOPT_RESOLVE`. The robots/sitemap/body/header
fetchers all fail closed on a private or unresolvable target.

No env changes are required for this layer. It protects the crawler as it runs
today (rendering off).

---

## 2. Enabling headless rendering later (the SSRF-safe path)

The render path (`CRAWLER_JS_RENDERING=true`) drives Chromium, which does its
own DNS, follows its own redirects, and runs page JS that can reach internal
endpoints outside PHP's visibility. The in-app control that closes this is the
**Node validate-and-pin egress proxy** (`resources/node/egress-proxy.mjs`)
combined with Chromium's `--proxy-bypass-list=<-loopback>`. The proxy resolves
every target, rejects any host whose resolved IP is private/reserved, and
connects to the pinned validated IP — defeating DNS rebinding. `<-loopback>` is
mandatory: without it Chromium implicitly bypasses the proxy for `127/8` and
`169.254.169.254`.

To enable rendering in any environment:

1. **Run the egress proxy** as a supervised process bound to localhost:
   ```bash
   node resources/node/egress-proxy.mjs <port>
   ```
   It binds `127.0.0.1:<port>` and applies the same validation to every
   connection — the localhost bind + ephemeral port is what keeps it private.
   In dev it already runs as part of `composer dev` (the `proxy` process in the
   `concurrently` block, on port `8118`). In prod, run it under the process
   manager (supervisor / systemd) alongside `queue:work`.

2. **Point the app at the proxy:**
   ```
   CRAWLER_EGRESS_PROXY=127.0.0.1:<port>
   ```

3. **Only then** set:
   ```
   CRAWLER_JS_RENDERING=true
   ```
   The interlock in `PageRenderer::enabled()` keeps rendering OFF until
   `CRAWLER_EGRESS_PROXY` is configured, so setting the flag without a running,
   configured proxy is a no-op (the page is not even treated as a heal
   candidate — no churn loop).

4. **CI gate:** `node --test resources/node/*.test.mjs` must pass (covers the
   private-IP classifier, the SSRF IP parity fixture, and proxy
   reject-at-connect). Use the `*.test.mjs` glob, not the bare directory — on
   Node 22 the directory form tries to load `resources/node` as a module.

### Tunable knobs (all optional, sane defaults)

| Env | Default | Purpose |
|-----|---------|---------|
| `CRAWLER_JS_RENDERING` | `false` | Master flag for headless render-on-fallback. |
| `CRAWLER_EGRESS_PROXY` | `127.0.0.1:8118` | `host:port` of the validate-and-pin proxy. Required to enable rendering. |
| `CRAWLER_RENDER_BUDGET` | `25` | Max headless renders per crawl session (`0` = unlimited). Caps wall-clock on SPA-heavy sites. |
| `BROWSERSHOT_NODE_BINARY` | unset | Override Node binary path for Browsershot. |
| `BROWSERSHOT_NPM_BINARY` | unset | Override npm binary path for Browsershot. |
| `BROWSERSHOT_CHROME_PATH` | unset | Override Chromium binary path for Browsershot. |

---

## 3. Recommended defense-in-depth: OS egress firewall (NOT a hard prerequisite)

The validate-and-pin proxy + `<-loopback>` is the **rebinding-complete in-app
control**. It is sufficient on its own to make `CRAWLER_JS_RENDERING=true` safe
to flip.

As **additional** defense-in-depth, an OS-level egress firewall scoped to a
dedicated Chromium uid / network namespace is recommended. It should **drop**
egress to the private/reserved ranges:

```
127.0.0.0/8        (loopback)
10.0.0.0/8         (RFC1918)
172.16.0.0/12      (RFC1918)
192.168.0.0/16     (RFC1918)
169.254.0.0/16     (link-local, incl. cloud metadata 169.254.169.254)
100.64.0.0/10      (CGNAT)
::1                (IPv6 loopback)
fc00::/7           (IPv6 unique-local)
fe80::/10          (IPv6 link-local)
```

**Scope it to the Chromium child only.** The worker process itself legitimately
needs private-IP egress to reach the database, Redis, and other internal
services — a host-wide drop of these ranges would break the app. The firewall
must target the dedicated Chromium uid/netns, not the worker.

This firewall is **recommended additional defense, not a code deliverable and
not a hard prerequisite** for the flag now that the proxy closes rebinding.
Provisioning it is out of scope for this PR (ops/deploy concern).

---

## 4. Deploy steps summary

For the PR body / runbook:

1. Merge — the pure-PHP HTTP-fetch SSRF fix is live immediately, no env change.
2. (When enabling rendering, later) Run the egress proxy as a supervised
   localhost process: `node resources/node/egress-proxy.mjs <port>`.
3. (When enabling rendering, later) Set `CRAWLER_EGRESS_PROXY=127.0.0.1:<port>`.
4. (When enabling rendering, later) Set `CRAWLER_JS_RENDERING=true` — the
   interlock keeps render off until the proxy is configured.
5. Ensure `node --test resources/node/*.test.mjs` passes in CI.
6. (Recommended) Provision the OS egress firewall scoped to the Chromium
   uid/netns per section 3.
