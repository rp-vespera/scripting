# Fix Inflated LMC Balance — NLIO00947

*Date: 2026-07-01 · Prepared by: Kervin Fugata · Org: NLIO (ad_org_id = 162012)*

> Script: `scripts/pending/2026_07_01_fix_lmcline_bal_inflated_amt_totalpayout_nlio00947.php`

---

## Why we need this script

Five LMC budget lines for interment order **NLIO00947** are showing as over-budget
in the checker dashboard. The system reports each line as paid at **twice its budget**,
which blocks normal operations — the re-draft guard treats over-budget lines as already
fully covered, and any future balance check incorrectly reads these lines as exhausted.

| Budget Line | Budget | Shows Paid | Over by |
|---|---|---|---|
| MERIENDA PACKAGE | ₱800 | ₱1,600 | ₱800 |
| INCENTIVE FOR EMCEE | ₱1,000 | ₱2,000 | ₱1,000 |
| PHOTOGRAPHER AND VIDEOGRAPHER | ₱1,500 | ₱3,000 | ₱1,500 |
| SINGER | ₱1,000 | ₱2,000 | ₱1,000 |
| VIDEO LIVESTREAMING | ₱5,000 | ₱10,000 | ₱5,000 |

**No supplier was paid twice.** The actual disbursements are correct — each supplier
received the right amount exactly once. The problem is confined to a balance tracking
counter in the database.

---

## What caused it

### How the two systems track balance

Java SAERP uses **encumbrance accounting**: when the Maker saves a payout draft (DR),
the budget line is immediately committed by incrementing `wip_l_lmcline_bal.amt_totalpayout`.
This prevents other payouts from exceeding the budget ceiling before the DR is even processed.
When the DR is processed to PR, Java records the actual disbursement
(`wip_t_lmc_bgtline.l_qty_payout` / `l_amt_payout`) but does not touch `amt_totalpayout`
again — it was already encumbered.

The online system previously incremented `amt_totalpayout` at **PR time** instead.
That decision was made to allow multiple suppliers to be drafted for the same IO in parallel
without blocking each other — but it created a mismatch with Java's encumbrance model.

### What happened for NLIO00947

The Maker drafted the payouts in Java SAERP offline. Java encumbered
`amt_totalpayout += ₱X` for each of the 5 lines at DR-save time — correct by design.

The online Checker then processed the same IO: created new online DRs and processed
them to PR. Because the online PR incremented `amt_totalpayout` again (not accounting
for Java's encumbrance already written to the same field), each line ended up doubled.

```
Java Maker saves DR (offline)     →  amt_totalpayout += ₱X   (Java encumbrance)
                                      l_qty_payout, l_amt_payout unchanged

Online Checker creates online DRs →  amt_totalpayout unchanged (online did not encumber at DR time)

Online Checker processes to PR    →  amt_totalpayout += ₱X   (online PR-time write)
                                      l_qty_payout  += 1
                                      l_amt_payout  += ₱X
                                      ──────────────────────────────────────
                                      amt_totalpayout = 2×₱X  ⚠
                                      l_qty_payout    =   1   ✓
                                      l_amt_payout    =   ₱X  ✓
```

Neither system had a bug. The mismatch was a **design gap**: the online PR increment
did not account for the Java encumbrance already written to the same field.

### What has been fixed going forward

The online system has been aligned with Java: `amt_totalpayout` is now encumbered at
**DR-save time** in `draftPayoutsForIo`, and `processPayoutToPr` no longer increments it.
The balance filter (`amt_totalpayout >= budget`) then correctly blocks re-drafting any
line already encumbered by Java offline — preventing this double-count from recurring.

---

## What the fix does

The script corrects `wip_l_lmcline_bal.amt_totalpayout` for the five affected lines
by subtracting the Java draft-time increment — setting each back to the amount the
single online PR actually paid:

| wip_l_lmcline_bal_id | Line | From | To | PR |
|---|---|---|---|---|
| 38038 | MERIENDA PACKAGE | ₱1,600 | ₱800 | NLMC0008198 |
| 38044 | INCENTIVE FOR EMCEE | ₱2,000 | ₱1,000 | NLMC0008197 |
| 38046 | PHOTOGRAPHER AND VIDEOGRAPHER | ₱3,000 | ₱1,500 | NLMC0008195 |
| 38047 | SINGER | ₱2,000 | ₱1,000 | NLMC0008194 |
| 38048 | VIDEO LIVESTREAMING | ₱10,000 | ₱5,000 | NLMC0008196 |

No other tables are changed. GL entries, `fin_l_debt`, and `wip_t_lmc_bgtline`
running totals were verified correct and require no correction. All 5 lines are in
**scope 25958** (NLIO00947 INTERMENT ORDER); the script guards each row against a
wrong-scope write.

---

## Technical notes

**Verification queries (run after the script commits)**

```sql
-- Confirm corrected balances
SELECT
    b.wip_l_lmcline_bal_id,
    bl.description,
    b.amt_totallmcbudget,
    b.amt_totalpayout,
    b.amt_totallmcbudget - b.amt_totalpayout AS remaining,
    b.updated,
    b.date_updated
FROM wip_l_lmcline_bal b
JOIN wip_t_lmc_bgtline bl ON bl.wip_l_lmcline_bal_id = b.wip_l_lmcline_bal_id
JOIN wip_i_project_scope_stage st
    ON st.wip_i_project_scope_stage_id = bl.wip_i_project_scope_stage_id
WHERE st.wip_i_project_scope_id = 25958
  AND b.wip_l_lmcline_bal_id IN (38038, 38044, 38046, 38047, 38048)
ORDER BY b.wip_l_lmcline_bal_id;
```

Expected: `amt_totalpayout = amt_totallmcbudget` and `remaining = 0` for all 5 rows.

**Rollback**

```sql
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 1600.00  WHERE wip_l_lmcline_bal_id = 38038;
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 2000.00  WHERE wip_l_lmcline_bal_id = 38044;
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 3000.00  WHERE wip_l_lmcline_bal_id = 38046;
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 2000.00  WHERE wip_l_lmcline_bal_id = 38047;
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 10000.00 WHERE wip_l_lmcline_bal_id = 38048;
```

**Dry-run result (2026-07-01)**

Ran read-only against the replica: all 5 pre-flight checks passed (scope 25958, budget
matches, exactly 2× current, linked PR verified in PR status with matching amount).
"DRY-RUN complete — no changes written." Would correct 5 rows, ₱18,600 → ₱9,300.

**Open item — ERP master DB**

This correction targets the SAERP working DB (`mysql_secondary`). If the same inflated
balances exist on the live ERP master, the equivalent correction must be applied there
by the senior — this script covers the app-facing replica/working DB only.
