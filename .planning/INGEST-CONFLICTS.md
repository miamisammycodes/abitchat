## Conflict Detection Report

Mode: new
Docs ingested: 33 (0 ADR, 6 SPEC, 1 PRD, 26 DOC)
Precedence: ADR > SPEC > PRD > DOC
Cycle detection: 0 doc-to-doc cycles detected
UNKNOWN-confidence-low docs: 0

---

### BLOCKERS (0)

None.

No LOCKED-vs-LOCKED ADR contradictions (0 ADRs in ingest set).
No UNKNOWN-confidence-low documents.
No doc-to-doc reference cycles.
No ingest decisions contradict existing locked CONTEXT.md (MODE: new — no existing context to merge against).

---

### WARNINGS (0)

None.

No competing acceptance criteria across PRDs (single PRD source: prd.md).
No requirement scope overlaps with divergent acceptance variants.

---

### INFO (7)

[INFO] Auto-resolved: PRD > DOC on pricing currency
  Found: prd.md specifies plan pricing in USD — Starter $29/month, Business $79/month, Enterprise $199/month
  Expected: ROADMAP.md shows plan pricing in BTN (Ngultrum) — Starter Nu.5,000/month, Business Nu.7,000/month, Enterprise "Contact Us"
  source found: prd.md (PRD, precedence tier 3)
  source expected: ROADMAP.md (DOC, precedence tier 4)
  Rationale: PRD wins over DOC per default precedence (PRD > DOC). ROADMAP pricing reflects operational market adaptation for Bhutan; PRD defines the canonical product specification. Both values preserved in context.md under TOPIC: Pricing and Plans.
  Impact: requirements.md uses PRD pricing as authoritative. ROADMAP BTN pricing noted as operational reality in context.md.

[INFO] Auto-resolved: PRD > DOC on Business plan token quota
  Found: prd.md specifies Business plan: 500K tokens/month, 50 KB items
  Expected: ROADMAP.md reflects Business plan with different KB quota (100 items per M5.4 update note)
  source found: prd.md (PRD, precedence tier 3)
  source expected: ROADMAP.md (DOC, precedence tier 4)
  Rationale: PRD wins. REQ-kb-08 uses PRD values (10/50/unlimited). ROADMAP discrepancy noted in REQ-kb-08 notes field.
  Impact: If shipped quota enforcement uses 100 items for Business, a correction PR or PRD amendment is needed before routing new work on quota enforcement.

[INFO] Auto-resolved: PRD > DOC on vector store technology
  Found: prd.md specifies SQLite-vec as the vector store
  Expected: ROADMAP.md (M5.4 update) and architecture-deepening SPEC confirm Postgres + pgvector is the shipped vector store
  source found: prd.md (PRD, precedence tier 3) — specifies SQLite-vec
  source expected: ROADMAP.md (DOC, precedence tier 4) + docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (SPEC, precedence tier 2) — confirm pgvector
  Note: In this instance the SPEC (tier 2) and DOC (tier 4) both contradict the PRD (tier 3) on a shipped implementation fact. The SPEC is higher-precedence than the PRD. DEC-14 records pgvector as the architectural decision. The PRD reference to SQLite-vec is stale.
  Rationale: SPEC > PRD on same scope. pgvector is the correct constraint for downstream planning. PRD's SQLite-vec should be treated as superseded.
  Impact: Any future work touching the vector store layer should target Postgres + pgvector, not SQLite-vec. PRD amendment recommended.

[INFO] Auto-resolved: PRD > DOC on Laravel version requirement
  Found: prd.md specifies Laravel 12+
  Expected: CLAUDE.md and codebase confirm Laravel 13+
  source found: prd.md (PRD, precedence tier 3) — specifies Laravel 12+
  source expected: CLAUDE.md (DOC, precedence tier 4) — states Laravel 13+
  Rationale: PRD wins over DOC in strict precedence. However this is a shipped implementation fact — the codebase runs Laravel 13+. PRD's version floor (12+) is inclusive of 13+ so there is no true contradiction; PRD simply understates the floor.
  Impact: No constraint conflict. Downstream consumers should target Laravel 13+ minimum. PRD annotation recommended.

[INFO] Auto-resolved: PRD > DOC on development LLM model
  Found: prd.md references Gemma2 2B as the development LLM
  Expected: CLAUDE.md and implementation plans confirm gemma3:4b as the shipped development model
  source found: prd.md (PRD, precedence tier 3)
  source expected: CLAUDE.md (DOC, precedence tier 4)
  Rationale: PRD wins on specification. However CLAUDE.md defines the operational coding standard for this project. gemma3:4b is the authoritative dev model for active use. PRD model reference is stale.
  Impact: CLAUDE.md value (gemma3:4b) is the correct model for new feature development. context.md records the correct model.

[INFO] Auto-resolved: PRD > DOC on payment processor priority
  Found: prd.md specifies Stripe as P0 payment processor (Stripe Cashier, subscription-first)
  Expected: ROADMAP.md and implementation DOCs confirm shipped reality is manual payment + DK Bank QR (PR #24) as primary active flows; Stripe Cashier is in codebase but full Stripe checkout is PARTIAL
  source found: prd.md (PRD, precedence tier 3)
  source expected: ROADMAP.md + implementation plan DOCs (DOC, precedence tier 4)
  Rationale: PRD wins on specification intent (Stripe is the designed P0). Shipped state is noted in requirements.md status fields and in context.md under TOPIC: Shipped PRs. Both are preserved; status fields mark Stripe requirements as PARTIAL.
  Impact: REQ-aba-01 marked PARTIAL. REQ-cbs-06 added as SHIPPED for DK Bank QR. Future billing work should evaluate whether to complete Stripe integration or formally amend PRD to reflect DK Bank as primary.

[INFO] Auto-resolved: SPEC > PRD on bot_custom_instructions max length
  Found: prd.md implicitly permits bot_custom_instructions up to 2000 characters (original validation rule)
  Expected: docs/superpowers/specs/2026-05-11-prompt-injection-design.md explicitly reduces max to 1000 characters
  source found: prd.md (PRD, precedence tier 3)
  source expected: docs/superpowers/specs/2026-05-11-prompt-injection-design.md (SPEC, precedence tier 2)
  Rationale: SPEC wins over PRD per default precedence (SPEC > PRD). The reduction to 1000 chars is a security constraint from the prompt injection defense spec. CONS-02 records this as authoritative.
  Impact: Any feature work touching bot_custom_instructions validation MUST use 1000 char maximum. PRD annotation recommended.
