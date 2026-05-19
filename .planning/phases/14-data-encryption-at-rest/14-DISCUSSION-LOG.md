# Phase 14: Data Encryption at Rest - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-20
**Phase:** 14-data-encryption-at-rest
**Areas discussed:** Phase 14↔15 api_key coupling, Field scope, Encryption key & rotation, Message-body consequence confirm

---

## Phase 14 ↔ 15 coupling on tenants.api_key

| Option | Description | Selected |
|--------|-------------|----------|
| Shared-contract parallel plan | Parallel-plan; Phase 14 depends on Phase 15 api_key_hash; lookups migrated; exec 15→14 | ✓ |
| Sequence 15 → 14 (no parallel) | Plan+execute 15 fully, then plan 14 | |
| Narrow Phase 14 scope | Drop api_key encryption from 14 (changes boundary vs criterion #1) | |

**User's choice:** Shared-contract parallel plan
**Notes:** Surfaced by codebase scout — api_key is plaintext, looked up by equality in ValidateWidgetDomain/CheckUsageLimits + hashed in SessionTokenService; Laravel `encrypted` cast is non-deterministic so those break. Phase 15's deterministic indexed api_key_hash is the enabling blind-index. Both planners get the contract.

---

## Field scope for encryption

| Option | Description | Selected |
|--------|-------------|----------|
| api_key + widget tokens | Core secrets | ✓ |
| Lead PII (email/phone/name) | Clear PII, low query impact | ✓ |
| Conversation/message bodies | HIGH impact — breaks RAG keyword fallback/search/analytics | ✓ |
| Other tenant secrets | Planner enumerates exact columns | ✓ |

**User's choice:** All four selected
**Notes:** Message-body inclusion confirmed in a dedicated follow-up after consequences were spelled out (see below).

---

## Encryption key source & rotation

| Option | Description | Selected |
|--------|-------------|----------|
| APP_KEY + Laravel previous-keys | Native encryption, APP_PREVIOUS_KEYS rotation | ✓ |
| Dedicated data-encryption key | Separate key, independent rotation | |
| You decide | Defer to planner | |

**User's choice:** APP_KEY + Laravel previous-keys
**Notes:** Consistent with Phase 15 APP_KEY-as-pepper + DEC-12 JWT signing — one key lifecycle.

---

## Message-body encryption consequence confirm

| Option | Description | Selected |
|--------|-------------|----------|
| Exclude message bodies | Keep RAG fallback/search/analytics working | |
| Encrypt bodies anyway | Accept break of DEC-14 keyword fallback, search, analytics, Phase 21 | ✓ |
| Encrypt + searchable derived index | Retrieval redesign; largest scope | |

**User's choice:** Encrypt bodies anyway
**Notes:** User made an informed decision after being shown concrete impact (DEC-14 keyword LIKE fallback, conversation search/analytics, Phase 21 quality metrics, admin review UX all break/degrade; pgvector cosine survives). Captured as accepted known consequence + downstream-impact note for planner and Phase 21. Not re-litigated.

## Claude's Discretion

- Migration/backfill mechanics, rollback documentation, per-field cast vs custom caster, deterministic-vs-non-deterministic per field (only api_key constrained), exact "other secrets" column enumeration — planner-owned. Hard constraints: PHPStan zero (DEC-09), forTenant/BelongsToTenant (DEC-05), reversible migration (criterion #4).

## Deferred Ideas

- Dedicated data-encryption key (separate from APP_KEY) for isolated at-rest key rotation — considered, not chosen.
- Searchable-encryption redesign for encrypted transcripts (restore keyword fallback/analytics) — deferred to Phase 21 or a future phase.
