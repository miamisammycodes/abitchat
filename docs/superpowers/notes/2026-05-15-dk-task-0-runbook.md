# DK Bank Task 0 — UAT Verification Runbook

**Goal:** Empirically verify the DK Bank API behaves how the spec assumes, BEFORE we write any more code that depends on cross-bank verification.

**Outcome:** A populated `docs/superpowers/notes/2026-05-15-dk-task-0-results.md` file. Probe 4's outcome decides whether Tasks 17, 19's verifyRrn, 20's RRN tests, 21's RRN UI ship — or get cut for a DK→DK-only ship.

---

## Prerequisites

### 1. UAT credentials (from `zala.bt Payment Gateway` PDF, page 27)

Set these in `.env` BEFORE running anything:

```ini
DK_BANK_ENABLED=true
DK_BANK_BASE_URL=https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg

# UAT credentials — page 27 of the PDF
DK_BANK_USERNAME=PG_AVS
DK_BANK_PASSWORD=p@PG1234
DK_BANK_CLIENT_ID=PG_AVS_123
DK_BANK_CLIENT_SECRET=PG-Requestor-TestSecret123
DK_BANK_SOURCE_APP=SRC_AVS_0201
DK_BANK_BENEFICIARY_ACCOUNT=110158212197
DK_BANK_MCC_CODE=5817

# Per PDF page 4, UAT uses TWO different X-gravitee-api-key values:
# - For /v1/auth/token and /v1/sign/key:
DK_BANK_API_KEY_AUTH=98cf3639-df33-4587-9d36-dae9d2bb974c
# - For every other signed endpoint:
DK_BANK_API_KEY=595987da-fa2d-484f-82e3-b3a330d5c768
```

**⚠️ Discovery for DK to confirm:** Two different keys in UAT. Ask DK:
> Are the two `X-gravitee-api-key` values (`98cf...` for token+sign-key, `595987...` for everything else) the design? Or do we get a single key in production? If permanent, our integration needs to track both.

### 2. Phone setup

For Probes 2, 4, 5, 6 you need:

- **A phone with the UAT DK Bank mobile app installed.** Ask DK's integration team how to obtain the UAT build (TestFlight, APK, Diawi, etc.)
- **For Probe 4: access to a second Bhutanese bank app** (BoB, BNB, DPNB, BDBL, or T-Bank — any one). You'll send Nu. 1.00 from this account to our DK UAT beneficiary account via the scanned QR.

### 3. Storage directory for the private key

```bash
mkdir -p storage/app
```

The probe helper script (below) will write `storage/app/dk_pg.pem` automatically on first run.

---

## The probe helper script

Save this as `scripts/dk-probe.php` (already created for you by the writing-plans step — see file at that path). Run probes like:

```bash
php scripts/dk-probe.php 1                    # Probe 1: signing works end-to-end
php scripts/dk-probe.php 2 DKQR-TEST-001      # Probe 2: status check by our reference_no
php scripts/dk-probe.php 3                    # Probe 3: fabricated RRN returns 3001
php scripts/dk-probe.php 4 <real-bank-RRN>    # Probe 4: cross-bank verify with real RRN
php scripts/dk-probe.php 5                    # Probe 5: token caching observed
php scripts/dk-probe.php 6 DKQR-TEST-001 +1   # Probe 6: same reference, next-day date
```

For Probes 2, 4, 6 the script accepts a reference number argument so you can re-run after paying. For Probe 6, the `+1` argument means "use tomorrow's date".

---

## The 6 probes (in order)

### Probe 1 — Canonical JSON signing + RSA key fetch + QR generation

**Setup:** none.

**Run:**

```bash
php scripts/dk-probe.php 1
```

**Expected output:** something like:

```
[Probe 1] Step A: fetching access token...
  ✓ response_code 0000, access_token starts with eyJ...

[Probe 1] Step B: fetching RSA private key...
  ✓ PEM written to storage/app/dk_pg.pem (mode 0600)

[Probe 1] Step C: generating QR with reference DKQR-PROBE-1-<random>...
  ✓ response_code 0000, image is 17234 bytes base64

[Probe 1] PASSED — signing works end-to-end against UAT.
```

**Record in results file:**
- ✓ PASSED / ✗ FAILED
- If failed: the exact response body from the failing step

**Failure interpretation:**
- 4002 on Step A: credentials wrong → re-check `.env`
- 5001 on Step C: signature mismatch → `canonicalJson()` has a bug; the unit tests passed but real DK rejected it. Pause; surface the actual signed body for diagnosis.

---

### Probe 2 — §10 status with DK→DK reference_no

This is a two-phase probe — generate QR, then physically pay, then check status.

**Phase 1: generate the QR**

```bash
php scripts/dk-probe.php 1
```

This already runs Probe 1, which generates a QR with reference `DKQR-PROBE-1-<random>`. Note the reference number it printed. Save the base64 image (the script will dump it to `tmp/dk-qr-<reference>.png` if you pass `--save`).

Actually, easier: re-run Probe 1 with a stable reference like `DKQR-PROBE2-001`:

```bash
php scripts/dk-probe.php 1 --reference DKQR-PROBE2-001 --save
```

This writes `tmp/dk-qr-DKQR-PROBE2-001.png` — open that file on screen.

**Phase 2: physically pay**

1. Open the UAT DK Bank mobile app on your phone
2. Scan the QR shown on your monitor (or photograph it)
3. Confirm the amount + beneficiary look right
4. Complete payment with your UAT credentials
5. Note the time of payment

**Phase 3: status check**

```bash
php scripts/dk-probe.php 2 DKQR-PROBE2-001
```

**Expected output:**

```
[Probe 2] Calling /v1/intra-transaction/status with reference DKQR-PROBE2-001...
  response_code: 0000
  response_data[0]:
    status: "0"
    status_desc: "Successfully completed"
    amount: "1000.00"
    credit_account: "110158212197"
    txn_ts: "2026-05-15 14:32:01"
    srn: "..."
    debit_account: "..."

[Probe 2] PASSED. DK→DK status check works with our reference_no.
```

**Record in results file:**
- ✓ PASSED / ✗ FAILED
- The exact response_data shape (this is what Tasks 16 + 17 parse)
- Whether `amount` is a string `"1000.00"` or number `1000.00` (this affects the `(float)` cast logic in Task 16)
- The exact format of `txn_ts` (this affects the Carbon parsing in Task 16)

---

### Probe 3 — §10 with fabricated RRN returns 3001

**Setup:** none.

**Run:**

```bash
php scripts/dk-probe.php 3
```

**Expected output:**

```
[Probe 3] Calling /v1/intra-transaction/status with fabricated RRN FAKERRN999...
  response_code: 3001
  response_message: Missing
  response_description: Missing record / record not found...

[Probe 3] PASSED. Failure path returns 3001 as documented.
```

**Record in results file:** just ✓ / ✗.

---

### Probe 4 — 🚨 BLOCKING: §10 with REAL cross-bank RRN

**Phase 1: generate a fresh QR**

```bash
php scripts/dk-probe.php 1 --reference DKQR-PROBE4-001 --save
```

**Phase 2: pay from a NON-DK Bhutanese bank app**

1. Open your BoB / BNB / DPNB / BDBL / T-Bank app on your phone
2. Scan the QR (open `tmp/dk-qr-DKQR-PROBE4-001.png` on your monitor)
3. Pay Nu. 1.00 (or whatever min amount your bank allows for small QR payments)
4. **Grab the RRN / Journal No / Reference No from the success screen + SMS.** Write it down — this is the key.

If your bank app refuses to scan the DK-issued QR at all, **that's also a finding** — it means the QR isn't on the RMA common standard despite DK's claim. Document it.

**Phase 3: status check with the bank's RRN**

```bash
php scripts/dk-probe.php 4 <RRN-from-your-bank>
```

**Expected outcome (if DK's email was correct):**

```
[Probe 4] Calling /v1/intra-transaction/status with cross-bank RRN <RRN>...
  response_code: 0000
  response_data[0]:
    status: "0"
    status_desc: "Successfully completed"
    amount: "1.00"
    credit_account: "110158212197"
    ...

[Probe 4] PASSED — cross-bank verification works. RRN-path tasks stay in the plan.
```

**Other possible outcomes:**

| Outcome | Meaning | Plan impact |
|---|---|---|
| `response_code: 0000` + `status: 0` | Cross-bank verification works. RRN path stays. | None — proceed to Task 16. |
| `response_code: 3001` "Missing record" | Cross-bank verification is NOT supported. DK's email was wrong/outdated. | **BLOCKING — rip out Tasks 17 (verifyByRrn), 19's verify-rrn action, 20's RRN tests, 21's RRN paste UI.** Ship DK→DK only. |
| QR doesn't scan from non-DK app | The QR isn't cross-bank readable despite DK's claim. | Same as above — DK→DK only ship. |
| Some other code (e.g. 2004) | Unknown failure mode. | **Pause + escalate to DK.** Do not proceed until DK explains. |

**Record in results file:**
- ✓ PASSED / ✗ FAILED + full response body
- Which payer bank you used
- Time elapsed between payment + status check (was the credit instant or delayed?)

---

### Probe 5 — Token caching observed

**Setup:** flush Redis to ensure a fresh start:

```bash
php artisan tinker --execute='\Illuminate\Support\Facades\Cache::forget("dk_bank:access_token"); echo "cleared";'
```

**Run:**

```bash
php scripts/dk-probe.php 5
```

The script makes two `/v1/generate_qr` calls back-to-back and reports how many `/v1/auth/token` calls were observed.

**Expected output:**

```
[Probe 5] Flushing token cache...
[Probe 5] First QR call... took 432ms (includes token fetch + RSA key fetch + QR generate)
[Probe 5] Second QR call... took 218ms (should only do QR generate, no token re-fetch)
[Probe 5] Cache::get('dk_bank:access_token') = "eyJ..." (cached)
[Probe 5] PASSED. Token cache works.
```

If the second call's duration is similar to the first, the cache isn't working. The script can also use `Http::recorded()` to count calls if you enable Laravel's HTTP log.

**Record in results file:** ✓ / ✗ + the timing delta.

---

### Probe 6 — Response date sensitivity

**Setup:** must run AFTER Probe 2 succeeds (you need a payment that exists at today's date).

**Run:**

```bash
php scripts/dk-probe.php 6 DKQR-PROBE2-001 +1
```

This calls `/v1/intra-transaction/status` with the SAME reference as Probe 2 but `transaction_date` set to tomorrow.

**Possible outcomes:**

| Outcome | Meaning | Task 16 impact |
|---|---|---|
| `response_code: 0000` + status 0 | DK's `transaction_date` is a hint, not strict. | **Simplify Task 16 to a single date call** before committing. |
| `response_code: 3001` | DK's `transaction_date` strictly binds. | Keep Task 16's two-candidate-date logic as planned. |

**Record in results file:** which outcome you saw.

---

## Results file template

Create `docs/superpowers/notes/2026-05-15-dk-task-0-results.md` with the section below filled in:

```markdown
# DK Bank Task 0 — Results

**Run date:** YYYY-MM-DD
**Run by:** <name>
**Environment:** UAT (https://internal-gateway.sit.digitalkidu.bt:8082/api/dkpg)

## Probe 1 — Signing end-to-end
- Status: ✓ PASSED / ✗ FAILED
- Notes:

## Probe 2 — DK→DK status check
- Status: ✓ / ✗
- response_data[0] shape:
  ```json
  { ... paste actual response here ... }
  ```
- amount field type: string / number
- txn_ts format:
- Notes:

## Probe 3 — Fabricated RRN
- Status: ✓ / ✗
- Notes:

## Probe 4 — 🚨 Cross-bank RRN (BLOCKING)
- Status: ✓ PASSED / ✗ FAILED
- Payer bank used:
- RRN value:
- response shape:
  ```json
  { ... }
  ```
- **Decision: RRN path stays / RRN path cut**
- Notes:

## Probe 5 — Token caching
- Status: ✓ / ✗
- First-call duration: __ ms
- Second-call duration: __ ms
- Notes:

## Probe 6 — Date sensitivity
- Status: 0000 (lenient) / 3001 (strict)
- **Decision: Task 16 stays two-candidate / Task 16 simplifies to single-candidate**
- Notes:

## Open questions for DK Bank

1. UAT uses two different X-gravitee-api-key values (98cf... for token+sign-key, 595987... for everything else). Is this also true in production, or do we get a single key?
2. <add anything else surprising>
```

---

## When you're done

Ping me with the results file populated and I'll resume Tasks 16-31, adapting based on:
- Probe 4 result → RRN path stays / cuts
- Probe 6 result → Task 16 stays / simplifies
- Two-API-key finding → small refactor to DkBankClient before Task 18
