# DK Bank Task 0 — Results

**Run date:** 2026-05-15
**Run by:** Claude (Probes 1, 3, 5) + Sam (Probe 2, 4 phone scans)
**Environment:** UAT (https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg)

> **Runbook:** `docs/superpowers/notes/2026-05-15-dk-task-0-runbook.md`
> **Probe helper:** `scripts/dk-probe.php`

---

## Probe 1 — Signing end-to-end ✅ PASSED

- Token fetch (Step A): ✓ `response_code: "0000"`, access_token returned
- Sign key fetch (Step B): ✓ PEM received (1704 bytes), persisted to `storage/app/dk_pg.pem` at mode 0600
- QR generate (Step C): ✓ `response_code: "0000"`, image returned (16900 bytes base64)

End-to-end signing chain verified against real DK UAT. `canonicalJson` + RS256 JWT + all required headers from Tasks 5–9 are empirically correct. **No signature bugs.**

## Probe 2 — DK→DK status check ⛔ BLOCKED ON DK (UAT app build needed)

Empirical finding: Sam's DK Bank mobile app rejected the UAT QR with **`<ERR: 2052>` "Invalid QR code. Please try again."** This matches the warning in the standalone QR guide:

> "UAT QRs are only payable from the DK Bank UAT mobile app. The production mobile app rejects UAT QRs with `<ERR: 2052>` and vice versa."

Sam's phone has the production DK app. To run Probe 2 we need DK's integration team to provide the UAT DK Bank app build (TestFlight invite for iOS, signed APK / Diawi link for Android, or whatever distribution channel they use).

**Plan impact:** Tasks 16's DK→DK polling code remains unit-tested via mocks (already covered). Production smoke will run Probe 2 against the production DK app before we flip `DK_BANK_ENABLED=true` on prod.

## Probe 3 — Fabricated RRN ✅ PASSED

`response_code: "3001"`, `response_message: "Missing"`, `response_description: "Missing record / record not found <Please check again on the next business day.>"`

The `verifyByRrn` "RRN not found" failure-path UI surfaces a real DK response shape.

## Probe 4 — 🚨 Cross-bank RRN ⏸️ PARTIAL (cross-bank scan WORKS, payment submit BLOCKED)

**Positive finding (huge):** BoB mobile app successfully scanned and **decoded** the DK-generated UAT QR. Cross-bank QR scanning is empirically confirmed — DK's QR is on Bhutan's RMA common standard. The decoded form showed:

- Beneficiary account: `9400XXXXXXXX9480` (likely BoB's internal routing/proxy number for our DK account `110158212197`)
- Beneficiary name: `Tshering Zangmo` (the DK UAT beneficiary's name)
- Reference: `DKQR-PROBE2-001` (preserved exactly as we set it — confirms `remarks` round-trips cross-bank)
- Amount: `1.0` (preserved)

**Blocker:** Tapping the **Submit** button in BoB does nothing — the button looks active but doesn't react. No error message shown. Likely causes (any of):

- BoB's production app refuses to actually transfer real money to a UAT test beneficiary (sanity check / whitelist)
- BoB has a min-amount validation that rejects Nu. 1.00 silently
- BoB itself has a bug with cross-bank UAT-routed payments
- Account verification / daily transaction limit issue on Sam's BoB account

**Net empirical Probe 4 outcome:** scan ✅, payment ❌, RRN never generated → can't call `/v1/intra-transaction/status` against a real cross-bank RRN. The blocking question — *does DK's status API actually return 0000 for a real cross-bank RRN?* — remains unanswered.

**Plan impact:** RRN-path tasks (17, 19's verifyRrn, 20's RRN tests, 21's RRN UI) **stay in the plan** based on DK's email assurance. Production smoke will validate the actual cross-bank verification end-to-end before we flip the killswitch on. If production smoke fails, we cut the RRN UI in a follow-up PR and the manual form remains the non-DK fallback.

## Probe 5 — Token caching ✅ PASSED

- Cold token fetch: **140 ms** (full HTTP round-trip to DK)
- Cached lookup: **1.66 ms** (~85x faster)

## Probe 6 — Date sensitivity ⛔ BLOCKED (needs successful Probe 2)

Cannot run until Probe 2 succeeds (needs a real payment whose date we can re-query). Will run during production smoke.

---

## Empirically confirmed (we can rely on these in code)

1. **Signing chain is correct.** `canonicalJson()` produces what DK's server expects. RS256 JWT verification passes. All required headers parse correctly. Tasks 5-9 are empirically validated.
2. **3001 is the documented "not found" path.** `verifyByRrn`'s failure-path UI is reachable.
3. **Token caching works.** Tasks 8 + 9 produce the speedup we designed for.
4. **Cross-bank QR scanning works.** DK's QR is RMA common-standard; any Bhutanese bank app can decode it. Sam confirmed with BoB.
5. **Only one UAT API key works.** Both `/v1/auth/token` and `/v1/sign/key` and signed data endpoints all use the same `595987da-...` key. The `98cf3639-...` key in the PDF returns HTTP 401 — PDF is outdated or rotated. Our existing single-key `DkBankClient` is correct as-built.

## Cannot empirically verify in UAT — production smoke deferral

1. **DK→DK round-trip payment + status check.** Needs UAT DK app build from DK Bank.
2. **Cross-bank payment landing in DK beneficiary account.** Needs either BoB's bug fixed OR running in production (which means real money). Sam's BoB submit doesn't fire.
3. **`txn_ts` exact format + `amount` exact type.** Both fields appear in `response_data[0]`; Task 16 handles them defensively (`(float)` cast + Carbon parse) so the format quirk won't break the code, but documenting actuals would let us tighten parsing.

---

## Open questions for DK Bank's integration team

1. **UAT DK app build.** Where do we get it (TestFlight, APK, Diawi link, internal portal)?
2. **Cross-bank UAT payments.** Are payments from non-DK banks (BoB, BNB, etc.) routed to UAT DK beneficiaries actually supported end-to-end? BoB's app scans your UAT QR fine but doesn't submit. Is this a known UAT limitation, or should it work?
3. **`X-gravitee-api-key` keys.** The PDF page 4 lists `98cf3639-df33-4587-9d36-dae9d2bb974c` for token+sign-key endpoints. That key returns HTTP 401 today against UAT. Only `595987da-fa2d-484f-82e3-b3a330d5c768` works, and works for ALL endpoints. Has the dual-key scheme been deprecated, or has `98cf3639-...` been rotated out? Will production use one key or two?

---

## Findings to fold back into the plan

- ✅ **No DkBankClient refactor needed** — single api key is fine.
- ✅ **RRN-path tasks stay in plan** — based on DK's email + empirical cross-bank scan working. Production smoke is the final validation.
- ✅ **Task 16's two-candidate-date logic stays** — we can't disprove the strict-date theory without Probe 6 succeeding, so the defensive code stays.
- ✅ **Tasks 16-31 can resume against mocks now** — every coded path has been validated either empirically (1, 3, 5) or by mock + DK statement (2, 4 deferred to production smoke).
