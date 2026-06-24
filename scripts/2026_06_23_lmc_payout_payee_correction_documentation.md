# LMC Payout Payee Correction — NLMC0008194 · NLMC0008195 · NLMC0008206

*Date: 2026-06-23 · Affected application: NLIO org (ad_org_id = 162012)*

> **Single run-once script covering three payout documents.**
> All three corrections are idempotent and wrapped in per-case transactions — a
> failure on one case rolls back only that case and re-throws, leaving the others
> untouched. The runner files the script under `scripts/failed/` on any exception.
>
> Script: `scripts/pending/2026_06_23_fix_lmc_payout_payee_nlmc0008194_nlmc0008195_nlmc0008206.php`

---

## PART 1 — Plain-Language Summary (for management)

### The situation

Three LMC (Labor/Material Cost) payout documents in the NLIO module were drafted
with the **wrong payee names**. The documents were already processed to Payment
Request (PR) by checker **CHRISTIAN IGNACIO** — meaning the wrong names are recorded
in both the payout table and the linked financial debt record.

| Document | Wrong payee assigned | Correct payee |
|----------|----------------------|---------------|
| NLMC0008194 | DAYAP, JENNY | ARCO, APRIL JOY S. |
| NLMC0008195 | ARCO, APRIL JOY S. | GANAN, MICAH MARIE M. |
| NLMC0008206 | JOHNNY JOSHUA V. TINDOGAN *(Singer)* | BACAOCO, IAN REY *(Photographer)* |

NLMC0008206 is tied to interment order **NLIO00950** (Photographer line, ₱1,500).
Its DR document is **NLMC0008198DR**. The payee was auto-drafted from the wrong
supplier slot — a Singer was assigned to the Photographer line.

### What we correct

Two database tables store the payee on a processed LMC payout:

1. **`wip_t_lmc_payout`** — the payout record itself (holds DR and PR documents).
2. **`fin_l_debt`** — the financial debt/ledger record linked at PR time.

Both are updated atomically per document. If `fin_l_debt` cannot be found
(no link exists), only `wip_t_lmc_payout` is updated and this is noted in the output.

### Safety measures

- **Org filter (`ad_org_id = 162012`):** Document numbers are issued from a shared
  sequence across all organisations. The same number (e.g. NLMC0008194) can exist in
  multiple orgs from different years. The org filter ensures only the 2026 NLIO records
  are ever touched.
- **Latest-row-only (`ORDER BY … DESC LIMIT 1`):** Picks the most recent record when
  duplicates exist, avoiding accidental updates to historical data.
- **Idempotency:** Each case checks whether both tables already hold the correct payee
  before writing anything. Re-running the script after a successful correction is safe
  — it exits early with "Already correct — skipping."
- **Per-case transactions:** Each document is committed independently. A problem with
  one document does not roll back the corrections already applied to the others.

### Where this runs

The script uses the `mysql_secondary` connection (production SAERP write connection).
A dry run was validated on the replica environment before deployment.

---

## PART 2 — Technical Detail (for IT / execution)

### Root cause

#### NLMC0008194 and NLMC0008195

These are **Java ERP-generated PR payouts**. The Java ERP does not write the
`fin_l_debt_id` foreign key back to `wip_t_lmc_payout`; the link is held only by
matching `documentno` on the `fin_l_debt` side. When the daily DB sync ran, the wrong
payees were synced back from the ERP master, overwriting the previous correction
attempt. The script now covers both the FK path and the documentno path.

#### NLMC0008206 (NLIO00950 Photographer)

This is a **portal-generated PR payout** — `fin_l_debt_id` is populated via the portal
PR flow. The wrong payee was introduced at autodraft time: the photographer supplier
slot had no active supplier assignment in `wbs_i_nlio_supplier_assignmentsio` for
NLIO00950, so the autodraft fell through to a different supplier (Tindogan, Singer).

PR was processed by **CHRISTIAN IGNACIO** at approximately 14:51–14:54 PM on the
date of creation. The payouts themselves were system-generated at 09:09 AM.

### fin_l_debt linkage paths

```
Path A (portal PR flow):
  wip_t_lmc_payout.fin_l_debt_id  ──FK──►  fin_l_debt.fin_l_debt_id

Path B (Java ERP PR flow, fin_l_debt_id IS NULL on payout):
  wip_t_lmc_payout.documentno  ══match══  fin_l_debt.documentno
```

The script resolves via Path A first; falls back to Path B if the FK is null:

```sql
SELECT fin_l_debt_id, bpar_i_person_id, s_bpartner_id
FROM fin_l_debt
WHERE fin_l_debt_id = COALESCE(?, -1)   -- Path A: direct FK
   OR documentno    = ?                  -- Path B: Java ERP flow
ORDER BY fin_l_debt_id DESC
LIMIT 1
```

### Dry run output (replica, 2026-06-23)

```
────────────────────────────────────────────────────────────────
LMC PAYOUT PAYEE CORRECTION
Connection : mysql_secondary
Mode       : DRY-RUN (no writes)
────────────────────────────────────────────────────────────────

▶ NLMC0008194
  Current payee : DAYAP, JENNY (bpar=…, bp=…)
  Correct payee : ARCO, APRIL JOY S. (bpar=23337, bp=19852)
  fin_l_debt    : id=351448 via FK
  [DRY-RUN] rolled back — 2 row(s) would be updated.

▶ NLMC0008195
  Current payee : ARCO, APRIL JOY S. (bpar=…, bp=…)
  Correct payee : GANAN, MICAH MARIE M. (bpar=25872, bp=22387)
  fin_l_debt    : id=351449 via FK
  [DRY-RUN] rolled back — 2 row(s) would be updated.

▶ NLMC0008206
  Current payee : JOHNNY JOSHUA V. TINDOGAN (bpar=…, bp=…)
  Correct payee : BACAOCO, IAN REY (bpar=27756, bp=24271)
  fin_l_debt    : id=351477 via FK
  [DRY-RUN] rolled back — 2 row(s) would be updated.

────────────────────────────────────────────────────────────────
DRY-RUN complete — no changes written.
────────────────────────────────────────────────────────────────
```

All three resolved via **Path A (FK)**. Total expected live writes: **6 rows** (2 per case).

### Correct payee IDs reference

| Document | Correct payee | bpar_i_person_id | s_bpartner_id |
|----------|---------------|-----------------|---------------|
| NLMC0008194 | ARCO, APRIL JOY S. | 23337 | 19852 |
| NLMC0008195 | GANAN, MICAH MARIE M. | 25872 | 22387 |
| NLMC0008206 | BACAOCO, IAN REY | 27756 | 24271 |

### Verification queries (run after the script commits)

```sql
-- Confirm payees on wip_t_lmc_payout
SELECT
    p.documentno,
    COALESCE(NULLIF(bp.name1,''), CONCAT(per.firstname,' ',per.lastname)) AS payee_name,
    p.bpar_i_person_id,
    p.s_bpartner_id,
    p.fin_l_debt_id
FROM wip_t_lmc_payout p
LEFT JOIN bpar_i_person per ON per.bpar_i_person_id = p.bpar_i_person_id
LEFT JOIN s_bpartner bp     ON bp.s_bpartner_id     = p.s_bpartner_id
WHERE p.documentno IN ('NLMC0008194', 'NLMC0008195', 'NLMC0008206')
  AND p.ad_org_id = 162012
ORDER BY p.wip_t_lmc_payout_id DESC;

-- Confirm payees on fin_l_debt (cross-check via documentno)
SELECT
    d.documentno,
    d.fin_l_debt_id,
    d.bpar_i_person_id,
    d.s_bpartner_id
FROM fin_l_debt d
WHERE d.documentno IN ('NLMC0008194', 'NLMC0008195', 'NLMC0008206')
ORDER BY d.fin_l_debt_id DESC;
```

Expected results after correction:

| documentno | bpar_i_person_id | s_bpartner_id |
|-----------|-----------------|---------------|
| NLMC0008194 | 23337 | 19852 |
| NLMC0008195 | 25872 | 22387 |
| NLMC0008206 | 27756 | 24271 |

### Rollback

The script is idempotent but not self-reversing. If the corrections must be undone,
run direct UPDATE statements using the original IDs captured from the dry-run output
or a before-state SELECT. No automatic rollback script was prepared — the original
wrong-payee values should be retrieved from the replica snapshot or the ERP master
before running.

### Open item — NLMC0008206 fin_l_debt on ERP master

The `fin_l_debt` record for NLMC0008206 (id=351477) also exists on the **ERP master
database**. A separate SQL correction must be run by the DBA on the master if the ERP
reports or GL views read from that record directly. This script updates the replica
only (`mysql_secondary`).
