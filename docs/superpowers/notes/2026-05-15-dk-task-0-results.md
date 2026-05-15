# DK Bank Task 0 — Results

**Run date:** 2026-05-15
**Run by:** Claude (Probes 1, 3, 5) + Sam (Probes 2, 4, 6 — pending phone scan)
**Environment:** UAT (https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg)

> **Runbook:** `docs/superpowers/notes/2026-05-15-dk-task-0-runbook.md`
> **Probe helper:** `scripts/dk-probe.php`

---

## Probe 1 — Signing end-to-end ✅ PASSED

- Token fetch (Step A): ✓ `response_code: "0000"`, access_token returned
- Sign key fetch (Step B): ✓ PEM received (1704 bytes), persisted to `storage/app/dk_pg.pem` at mode 0600
- QR generate (Step C): ✓ `response_code: "0000"`, image returned (16900 bytes base64)
- Notes: End-to-end signing chain verified against real DK UAT. `canonicalJson` + RS256 JWT + all required headers from Tasks 5–9 are empirically correct. **No signature bugs.**

## Probe 2 — DK→DK status check ⏳ PENDING

- QR generated: `DKQR-PROBE2-001` (saved at `tmp/dk-qr-DKQR-PROBE2-001.png`)
- **NEXT STEPS** (manual, requires UAT DK mobile app):
  1. Open the UAT DK Bank mobile app on your phone
  2. Scan `tmp/dk-qr-DKQR-PROBE2-001.png` from your monitor
  3. Confirm + pay Nu. 1.00
  4. Run: `php scripts/dk-probe.php 2 DKQR-PROBE2-001`
  5. Record the `response_data[0]` shape below — this is what Tasks 16/17 will parse

- response_data[0] shape (paste actual JSON):
  ```json
  ```
- `amount` field type: ⬜ string `"1.00"` / ⬜ number `1.00`
- `txn_ts` format:
- Notes:

## Probe 3 — Fabricated RRN ✅ PASSED

- Status: `response_code: "3001"`, `response_message: "Missing"`, `response_description: "Missing record / record not found <Please check again on the next business day.>"`
- Verified: the failure path that `verifyByRrn` surfaces to users with "Reference number not found" is real and reachable.

## Probe 4 — 🚨 Cross-bank RRN (BLOCKING) ⏳ PENDING

- QR generated: `DKQR-PROBE4-001` (saved at `tmp/dk-qr-DKQR-PROBE4-001.png`)
- **NEXT STEPS** (manual, requires non-DK Bhutanese bank app + Nu. 1.00):
  1. Open your BoB / BNB / DPNB / BDBL / T-Bank app
  2. Scan `tmp/dk-qr-DKQR-PROBE4-001.png` from your monitor
  3. **If the app refuses to scan it**, that's a finding — note which bank
  4. Otherwise, pay Nu. 1.00 to the beneficiary `110158212197`
  5. Grab the RRN / Journal No / Txn Ref from your bank's receipt + SMS
  6. Run: `php scripts/dk-probe.php 4 <RRN-from-your-bank>`

- Outcome:
  - ⬜ `response_code: "0000"` + `status: "0"` → **RRN path STAYS in plan**
  - ⬜ `response_code: "3001"` (not found) → **RRN path CUT, ship DK→DK only**
  - ⬜ Other code → escalate to DK

- Payer bank used:
- RRN value:
- Response shape:
  ```json
  ```
- Notes:

## Probe 5 — Token caching ✅ PASSED

- Cold token fetch: **140 ms** (full HTTP round-trip to DK)
- Cached lookup: **1.66 ms** (~85x faster)
- Cache key `dk_bank:access_token` populated after first call, served from Redis on second call.

## Probe 6 — Date sensitivity ⏳ PENDING (requires Probe 2 to land first)

- **NEXT STEP**: after Probe 2 succeeds, run:
  ```
  php scripts/dk-probe.php 6 DKQR-PROBE2-001 +1
  ```
- Outcome: ⬜ `response_code: "0000"` (lenient) / ⬜ `response_code: "3001"` (strict)
- Decision: ⬜ Task 16 simplifies to single date / ⬜ Task 16 keeps two-candidate logic
- Notes:

---

## Findings to fold back into the plan

### ✅ Resolved: Two-API-key claim is wrong/outdated in UAT

The PDF (page 4) says `/v1/auth/token` + `/v1/sign/key` use api-key `98cf3639-df33-4587-9d36-dae9d2bb974c`, and other endpoints use `595987da-fa2d-484f-82e3-b3a330d5c768`. **Empirically, only the `595987da-...` key works today** — for ALL endpoints including the token endpoint. The `98cf3639-...` key returns HTTP 401.

**Plan impact:** Our existing `DkBankClient` (which uses a single `DK_BANK_API_KEY` config) is correct as-built. **No refactor needed for Task 18.** The runbook's earlier warning about needing a `DK_BANK_API_KEY_AUTH` config can be ignored unless DK confirms a future state where two keys come back into play.

### ⏳ Open: Probe 4 outcome decides RRN-path tasks

- If passes: Tasks 17, 19's verifyRrn, 20's RRN tests, 21's RRN UI proceed as planned.
- If fails: those tasks get cut. The plan ships DK→DK auto-verify + manual fallback (existing form, admin approval) for non-DK payers.

### ⏳ Open: Probe 6 outcome may simplify Task 16

- If lenient (date doesn't strictly bind): simplify Task 16 to single-candidate.
- If strict (date must match): keep two-candidate logic as planned.

### ⏳ Open: amount + txn_ts shape from Probe 2

Both fields appear in DK's response; the exact type (string vs number for `amount`) and exact format (ISO 8601 vs `Y-m-d H:i:s` for `txn_ts`) affect Task 16's parsing.

---

## Open questions for DK Bank's integration team

1. **API key rotation:** PDF page 4 lists `98cf3639-...` for token+sign-key fetch; UAT currently rejects it with HTTP 401 and only accepts `595987da-...` for ALL endpoints. Is the dual-key model deprecated, or has `98cf3639-...` been rotated out? Will production have one or two keys?
2. **(Reserved for Probe 4 follow-up if it fails)**
