# DK Bank Task 0 — Results

**Run date:** _fill in_
**Run by:** _fill in_
**Environment:** UAT (https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg)

> **Runbook:** `docs/superpowers/notes/2026-05-15-dk-task-0-runbook.md`
> **Probe helper:** `scripts/dk-probe.php`

---

## Probe 1 — Signing end-to-end

- Status: ⬜ PASSED / ⬜ FAILED
- Token fetch (Step A): ⬜ 0000 / ⬜ error code: ___
- Sign key fetch (Step B): ⬜ PEM received / ⬜ error
- QR generate (Step C): ⬜ 0000 + image returned / ⬜ error
- Notes:

## Probe 2 — DK→DK status check

- Status: ⬜ PASSED / ⬜ FAILED
- Reference number used: _e.g. DKQR-PROBE2-001_
- response_data[0] shape (paste actual JSON):
  ```json
  {
  }
  ```
- amount field type: ⬜ string / ⬜ number
- txn_ts format: _e.g. "2026-05-15 14:32:01"_
- Notes:

## Probe 3 — Fabricated RRN

- Status: ⬜ PASSED (3001 returned) / ⬜ FAILED (different code)
- Notes:

## Probe 4 — 🚨 Cross-bank RRN (BLOCKING)

- Status: ⬜ PASSED / ⬜ FAILED
- Payer bank used: _e.g. BoB / BNB / DPNB / BDBL / T-Bank_
- RRN value: _from your bank's receipt or SMS_
- Time between payment + status check: __ seconds/minutes
- Did the non-DK app even scan the DK-issued QR? ⬜ Yes / ⬜ No (separate finding)
- Response shape:
  ```json
  {
  }
  ```
- **Decision: ⬜ RRN path stays / ⬜ RRN path CUT (DK→DK-only ship)**
- Notes:

## Probe 5 — Token caching

- Status: ⬜ PASSED / ⬜ FAILED
- First-call duration: __ ms
- Second-call duration: __ ms
- Notes:

## Probe 6 — Date sensitivity

- Outcome: ⬜ 0000 (lenient — date doesn't strictly bind) / ⬜ 3001 (strict — date must match)
- **Decision: ⬜ Task 16 simplifies to single-candidate / ⬜ Task 16 keeps two-candidate logic**
- Notes:

---

## Open questions for DK Bank's integration team

1. **Two API keys in UAT** — Page 4 of the spec shows different `X-gravitee-api-key` values for `/v1/auth/token` + `/v1/sign/key` (`98cf3639-df33-4587-9d36-dae9d2bb974c`) vs every other endpoint (`595987da-fa2d-484f-82e3-b3a330d5c768`). Is this dual-key setup permanent or just a UAT artifact? Will we get one key or two in production?
2.
3.

## Findings to feed back into the plan

After all probes complete, before resuming Task 16, note any plan revisions needed:

- [ ] e.g. _If Probe 4 failed: rip out Tasks 17, 19's verifyRrn action, 20's RRN tests, 21's RRN paste UI_
- [ ] e.g. _If Probe 6 lenient: simplify Task 16's two-candidate-date logic to single-call_
- [ ] e.g. _If amount is returned as number not string: simplify Task 16's `(float)` cast_
- [ ] _Two-API-key finding (above): add `DK_BANK_API_KEY_AUTH` config + adjust DkBankClient to use different key for token+sign-key vs other signed endpoints_
