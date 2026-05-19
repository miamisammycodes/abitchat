# Phase 14: Data Encryption at Rest - Context

**Gathered:** 2026-05-20
**Status:** Ready for planning

<domain>
## Phase Boundary

Encrypt sensitive fields at rest (REQ-sec-08) so a database compromise yields ciphertext — not plaintext API keys, tokens, or PII — with transparent application-level read/write and a reversible deploy migration. Scope fixed by ROADMAP.md success criteria:

1. API keys stored in DB encrypted at rest; raw column = ciphertext
2. Widget session-related tokens + designated PII fields encrypted before persist
3. App reads/writes transparently — existing functionality unchanged (caveat: see D-05 message-body consequence)
4. Migration encrypts all existing rows on deploy; rollback path documented

</domain>

<decisions>
## Implementation Decisions

### Phase 14 ↔ Phase 15 Coupling (api_key) — SHARED CONTRACT
- **D-01:** Phase 14 and Phase 15 both touch `tenants.api_key`. They are planned in parallel under a **shared contract**, NOT independently.
- **D-02:** **Phase 14 DEPENDS ON Phase 15's `api_key_hash` blind-index.** Phase 14 MUST NOT add its own hash/lookup column. The deterministic indexed `api_key_hash` (`sha256(api_key . APP_KEY)`) introduced by Phase 15 (CONS-22-f) is the blind-index that makes the encrypted `api_key` column still queryable.
- **D-03:** All current `api_key` *equality* lookups MUST be migrated to query `api_key_hash` instead of the (soon-encrypted) `api_key` column. Known call sites: `app/Http/Middleware/ValidateWidgetDomain.php:42`, `app/Http/Middleware/CheckUsageLimits.php:77`, and the `SessionTokenService` body-api_key comparison (`RequireWidgetSessionToken.php:59`). The planner MUST enumerate the full set, not assume these three are exhaustive.
- **D-04:** **Execution order: Phase 15 before Phase 14** (or, at minimum, Phase 15's `api_key_hash` column + lookup-migration tasks land before Phase 14's `api_key` encryption task). Both planners receive this contract in their prompt. Phase 14 plan must state this dependency explicitly and not encrypt `api_key` until the blind-index + migrated lookups exist.

### Field Scope (what gets encrypted)
- **D-05:** In scope for at-rest encryption this phase:
  - `tenants.api_key` (+ any persisted widget session-related token/secret fields) — criterion #1/#2
  - **Lead PII:** `leads.email`, `leads.phone`, `leads.name` — criterion #2 "designated PII"
  - **Conversation / message bodies** — chat transcript content. **User explicitly chose to encrypt these after being shown the consequences** (see Known Consequence below). NOT excluded.
  - **Other tenant/integration secrets** — the planner MUST enumerate exact columns from the live schema (payment/integration tokens, DK Bank credentials, SMTP, etc.) and encrypt persisted secrets.
- **D-06 (KNOWN CONSEQUENCE — message bodies):** Encrypting `conversations`/`messages` content with a non-deterministic cast **breaks**: conversation search/filtering, SQL-level conversation analytics, lead-scoring/quality tooling that reads message text, admin conversation-review UX (decrypt-per-row, no SQL search), and forces `messages.content_hash` to be neutralized (it is `MD5(plaintext)` — a plaintext-inference leak if the DB is compromised). This is an **accepted, deliberate** decision. The planner MUST emit a downstream-impact note and the Phase 21 (Analytics & Notifications) plan MUST account for encrypted transcripts.
  - **CORRECTION (verified empirically during planning, supersedes the original D-06 draft):** RAG retrieval is **NOT** affected. DEC-14's keyword `LIKE` fallback runs on `knowledge_chunks.content` (`RetrievalService.php:108-113`), **not** `messages.content`. Encrypting message bodies leaves RAG retrieval (pgvector cosine *and* keyword fallback) fully working. The original CONTEXT draft incorrectly listed RAG fallback as a breakage; this corrected scope is authoritative and is the basis for `14-02-PLAN.md`.

### Encryption Mechanism & Key Management
- **D-07:** Use **Laravel native encryption** (the `encrypted` / `encrypted:array` cast or a custom caster as appropriate per field), keyed by **APP_KEY**, with **`APP_PREVIOUS_KEYS`** supported for rotation (decrypt-with-old → re-encrypt-with-new). Consistent with the Phase 15 APP_KEY-as-pepper decision and DEC-12 JWT signing — one key lifecycle, least machinery.
- **D-08:** `api_key` is the one field that must remain *queryable*; it stays encrypted-at-rest for storage while lookups go via Phase 15's deterministic `api_key_hash` (D-02/D-03). Non-deterministic `encrypted` cast is acceptable for all other in-scope fields (they are not looked up by value equality — verify per field).

### Claude's Discretion / Planner-owned
- Migration & backfill mechanics (in-place vs add-encrypted-column+backfill+swap), rollback procedure documentation, per-field cast vs custom caster choice, deterministic-vs-non-deterministic decision per field (only `api_key` is constrained), and exact column enumeration for "other secrets" are left to the researcher/planner. Hard constraints: PHPStan/Larastan baseline stays zero (DEC-09); tenant queries use `forTenant`/`BelongsToTenant` (DEC-05); reversible migration with documented rollback (criterion #4).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` § "Phase 14: Data Encryption at Rest" — goal + 4 success criteria
- `.planning/REQUIREMENTS.md` — REQ-sec-08
- `.planning/intel/requirements.md` / `.planning/intel/constraints.md` — SEC cluster context

### Cross-phase contract (CRITICAL — read before planning)
- `.planning/phases/15-widget-session-token-hardening/15-CONTEXT.md` — Phase 15 decisions; D-06/D-07/D-08 there define the `api_key_hash` blind-index this phase depends on (CONS-22-f). Phase 14 MUST consume, not duplicate, that column.

### Locked decisions
- `.planning/PROJECT.md` § DEC-09 (PHPStan baseline = zero), § DEC-05 (BelongsToTenant / NoRawTenantIdWhere), § DEC-12 (APP_KEY signs widget JWTs), § DEC-14 (pgvector + keyword LIKE fallback — the tier D-06 impacts)

### Codebase map
- `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/CONCERNS.md`, `.planning/codebase/STRUCTURE.md` — data layer, models, RAG retrieval tiers

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- No existing `encrypted` casts anywhere in the codebase (greenfield encryption layer — confirmed via grep). Laravel's built-in `encrypted` cast is the baseline mechanism.
- `app/Models/Tenant.php` — `api_key` in `$fillable` + `$casts`; boot hook auto-generates `Str::random(64)` when empty (`Tenant.php:65-66`). Encryption caster + the Phase 15 `api_key_hash` maintenance hook must both fire on that assignment path.
- `app/Models/Lead.php` — `$fillable` includes `name`, `email`, `phone` (the PII set in D-05).

### Established Patterns
- DEC-05: all tenant queries via `forTenant`/`BelongsToTenant`; Larastan-enforced — no raw `where('tenant_id', …)` introduced by the migration or new lookups.
- api_key equality lookups currently at `ValidateWidgetDomain.php:42`, `CheckUsageLimits.php:77`, `RequireWidgetSessionToken.php:59`, `SessionTokenService.php:37/91` — all must move to the `api_key_hash` blind-index (D-03).

### Integration Points
- `tenants.api_key` ← encryption caster + Phase 15 `api_key_hash` (shared column, two phases — coordinate via D-01..D-04).
- `leads.{email,phone,name}` ← encryption casters.
- `conversations`/`messages` content columns ← encryption casters (D-06 consequence).

</code_context>

<specifics>
## Specific Ideas

- Same deployment reality as Phase 15: Laravel Forge single server + Cloudflare DNS-only; **no production deployed yet**, so the criterion-#4 "encrypt all existing rows" migration runs against dev/seed data only — but must still be correct, idempotent, and reversible for dev/test/CI.

</specifics>

<deferred>
## Deferred Ideas

- **Dedicated data-encryption key** (separate from APP_KEY / the widget pepper) for independent at-rest key rotation — considered, not chosen (APP_KEY + APP_PREVIOUS_KEYS selected for one key lifecycle). Revisit if/when key-isolation compliance requirements appear.
- **Searchable-encryption redesign for transcripts** (blind-indexed/tokenized search over encrypted message bodies to restore keyword fallback + analytics) — out of scope for Phase 14; flagged for Phase 21 (Analytics) to address the D-06 consequence, or a dedicated future phase.

</deferred>

---

*Phase: 14-data-encryption-at-rest*
*Context gathered: 2026-05-20*
