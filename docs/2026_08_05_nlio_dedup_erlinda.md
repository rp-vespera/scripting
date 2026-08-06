# Script Doc — NLIO Dedup: align 13403 to Erlinda + close duplicate 13392

**Script:** [`scripts/pending/2026_08_05_fix_nlio_dedup_erlinda_13403_close_13392.php`](../scripts/pending/2026_08_05_fix_nlio_dedup_erlinda_13403_close_13392.php)
**Date:** 2026-08-05 · **Connection:** `mysql_secondary` (SAERP) · **Author:** Kervin Fugata
**Type:** one-off data correction · identity-only · **zero money movement**

---

## 1. Case scenario (why this script exists)

Two WIP projects existed for a single interment, and the money was paid against the wrong one:

| | **13403** (keep) | **13392** (retire) |
|---|---|---|
| Doc no | `NWIP0001398` | `NWIP1700046` |
| Created by | Java ERP desktop | Online Interment Application |
| Status | **COMMENCED** | **BUDGETING** |
| Linked order (before) | `1865` = **NLIO00934-CA** (cancelled — Superlativo) | `1858` = **NLIO00931** (live — Erlinda) |
| Occupant | FSUPT. JIMMY G. SUPERLATIVO SR. | ERLINDA / RONIE / CHRISTOPHER MAGBANUA |
| Standard | 630 (W/O MASS) | 629 (W/ MASS) |
| Payouts | **21 PR** — 7 debts settled ₱9,818.74, outstanding 0 | **6 DR** (draft only, nothing disbursed) |

**What happened:** project 13403 was commenced for Superlativo, whose order was later **cancelled**
(`NLIO00934-CA`) — but ₱518.74 of materials had already been consumed and paid on it. Erlinda's
real project 13392 was stuck in **BUDGETING**, so it could not carry actual payouts. The team
repurposed the already-commenced 13403 for Erlinda (renamed it, processed all her payouts there).
That fixed *where the money sits* but left 13403's deep identity — its **order link, occupant,
standard, and subaccount pointer** — still pointing at the cancelled Superlativo record.

**What this script does:** finishes the alignment as an **identity-only** correction and closes the
duplicate. It moves **no money** and touches **no ledger** — the paid payouts/liquidations are
immutable and simply belong to Erlinda once the project points at her order.

> Full investigation & proof: core-system `docs/case-nlio-duplicate-erlinda-superlativo.md` (+ PDF).

---

## 2. What the script function does (operation by operation)

The file returns a closure `function ($cmd)`; the runner wraps it, but because writes target
`mysql_secondary` the closure opens its **own** transaction on that connection. All seven
operations run in **one atomic transaction**, each **guarded on its current value** (idempotent).

| Op | Table | Change | Guard / self-heal |
|---|---|---|---|
| **A1** | `mp_t_interment_order_project` (row 1269) | order `1865 → 1858` | only if currently 1865; occupancy follows to Magbanua automatically |
| **A2** | `mp_t_interment_order_project` (row 1265) | `is_active 1 → 0` | releases 13392's grip on the live order |
| **A6** | `wip_i_project` 13403 | `gl_subacct_id 40907 → 41009` | restores the project's **owned** subaccount (matches locator x + all 14 GL postings); a stray 40907 came from the manual rename |
| **SH** | `wip_i_project` 13403 | `std 629 → 630` | self-heals a v1-test leftover; **no-op on pristine live** (std must match the built scopes) |
| **B1** | `wip_t_lmc_payout` 55506–55511 | `docstatus DR → VO` | voids 6 draft payouts (never processed) |
| **B2** | `wip_i_project` 13392 | `BUDGETING\|HALTED → CLOSED` | MD directive: **CLOSE** the duplicate (accepts a prior HALTED too) |
| **B3** | `wip_l_project_status_change` | INSERT `CLOSED` audit row for 13392 | only if none exists; `s_bpartner_id` looked up for person 1967 |

**Standard is intentionally left at 630** — the scopes/BOM/LMC were built from 630, and 629 vs 630
differ only by a ₱158 vigil-candle price. The project **name** already reads "W/ MASS".

After the writes, an **in-transaction VERIFY block** asserts the aligned end-state (see §4). If any
assertion fails, the whole transaction rolls back and the script throws — the deploy pipeline then
quarantines the file to `scripts/failed/` and records the error on the `/scripts` dashboard.

---

## 3. Integrity — accounting & inventory (why it's safe)

The script writes to **only 4 tables**: `mp_t_interment_order_project`, `wip_i_project` (header),
`wip_t_lmc_payout` (drafts), `wip_l_project_status_change`. It writes **nothing** to:

- **Accounting:** `acct_gl`, `acct_balance`, `fin_l_debt`, `fin_l_debt_history`, `fin_s_advances`, `fin_t_payment`
- **Inventory:** `wip_l_bomline_bal`, `wip_t_bomline`, `nvt_i_locator`, any stockcard table

Verified facts:
- **13403's GL** (14 rows under subaccount 41009) and its **settled debts** (350482/350483,
  outstanding 0.00) are untouched. A6 only fixes the *header pointer* to match where postings
  already are — it re-posts nothing.
- **13392 has 0 consumed materials, 0 GL rows, 0 debts** → closing it strands/reverses nothing;
  voiding *draft* payouts has no GL/AP effect.
- Where the script touches a pointer (A6, SH) it **restores** consistency the manual rename broke
  (header now matches the owned subaccount 41009 and the built standard 630).

Net: accounting and inventory come out **untouched or more consistent — never degraded**.

---

## 4. Verification (built into the script)

The closure asserts all of these before committing (and they're also runnable standalone from
core-system `docs/sql/verify_fix_applied.sql`):

| Check | Expected |
|---|---|
| A1 order link (bridge 1269) | 1858 (NLIO00931) |
| A2 bridge 1265 | is_active 0 |
| A6 13403 gl_subacct_id | 41009 |
| STD 13403 standard | 630 |
| B1 drafts 55506–55511 | VO ×6 |
| B2 13392 status | CLOSED |
| B3 13392 audit row | present |
| OCC order 1858 occupants | contains MAGBANUA |
| MONEY debts 350482/350483 | outstanding 0.00 |
| NEG cancelled order 1865 | 0 active project links |

---

## 5. How to run

**Local dry-run** (no writes — rolls back):
```bash
# set $DRY_RUN = true in the script first
php artisan scripts:run-one scripts/pending/2026_08_05_fix_nlio_dedup_erlinda_13403_close_13392.php
```

**Pipeline (governed):**
- Commit on `staging` → runs on **staging** on deploy.
- Commit on `main` → runs on **production** on deploy.
- Gated by the `/scripts` review/approval dashboard; success moves the file to `done/`, failure to
  `failed/` (changes rolled back). Every run is recorded in `script_runs`.

---

## 6. Rollback

The script is idempotent and re-runnable. To revert after a run, the inverse is the core-system
[`docs/sql/rollback_nlio_duplicate_erlinda_v2.sql`](../../core-system/docs/sql/rollback_nlio_duplicate_erlinda_v2.sql)
sequence (order 1858→1865, is_active 0→1, subacct 41009→40907, VO→DR, CLOSED→BUDGETING, delete the
CLOSED audit row). Note the pre-fix baseline: order 1865, std 630, 13392 BUDGETING, drafts DR,
header gl_subacct 40907.

---

## 7. Key identifiers (quick reference)

- Projects: **13403** keep (COMMENCED), **13392** retire (BUDGETING→CLOSED)
- Bridge rows: **1269** (13403↔order), **1265** (13392↔order)
- Orders: **1858** `NLIO00931` live (Erlinda), **1865** `NLIO00934-CA` cancelled (Superlativo)
- Subaccount: **41009** (owned/correct), 40907 (stray)
- Standard: **630** (built), 629 (W/ MASS label)
- Draft payouts voided: **55506–55511**
- Settled debts (untouched): **350482, 350483**
- Status processor: person **1967**
