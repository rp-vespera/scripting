# Fix Inflated LMC Balance — NLIO00946 & NLIO00947

*Date: 2026-07-01 · Prepared by: Kervin Fugata · Org: NLIO (ad_org_id = 162012)*

> Script: `scripts/pending/2026_07_01_fix_lmcline_bal_inflated_amt_totalpayout_nlio00946_00947.php`

---

## Why we need this script

Five LMC budget lines **each**, for interment orders **NLIO00946** and **NLIO00947**,
are showing as over-budget in the checker dashboard. Every line is reported as paid at
**twice its budget**, which blocks normal operations — the re-draft guard treats
over-budget lines as already fully covered, and any future balance check reads these
lines as exhausted.

**NLIO00946** (scope 25956)

| Budget Line | Budget | Shows Paid | Over by |
|---|---|---|---|
| MERIENDA PACKAGE | ₱800 | ₱1,600 | ₱800 |
| INCENTIVE FOR EMCEE | ₱1,000 | ₱2,000 | ₱1,000 |
| PHOTOGRAPHER AND VIDEOGRAPHER | ₱1,500 | ₱3,000 | ₱1,500 |
| SINGER | ₱1,000 | ₱2,000 | ₱1,000 |
| VIDEO LIVESTREAMING | ₱5,000 | ₱10,000 | ₱5,000 |

**NLIO00947** (scope 25958)

| Budget Line | Budget | Shows Paid | Over by |
|---|---|---|---|
| MERIENDA PACKAGE | ₱800 | ₱1,600 | ₱800 |
| INCENTIVE FOR EMCEE | ₱1,000 | ₱2,000 | ₱1,000 |
| PHOTOGRAPHER AND VIDEOGRAPHER | ₱1,500 | ₱3,000 | ₱1,500 |
| SINGER | ₱1,000 | ₱2,000 | ₱1,000 |
| VIDEO LIVESTREAMING | ₱5,000 | ₱10,000 | ₱5,000 |

Each order: **₱18,600 recorded vs. ₱9,300 actually disbursed.** **No supplier was paid
twice** — actual disbursements are correct, each paid exactly once. The problem is
confined to the `amt_totalpayout` balance counter.

---

## What caused it

Java SAERP uses **encumbrance accounting**: when the Maker saves a payout draft (DR),
it immediately increments `wip_l_lmcline_bal.amt_totalpayout` (to reserve the budget).
When the DR is processed to PR, Java records the actual disbursement
(`wip_t_lmc_bgtline.l_qty_payout` / `l_amt_payout`) but does **not** touch
`amt_totalpayout` again.

The online system previously incremented `amt_totalpayout` at **PR time** instead
(to let multiple suppliers be drafted for the same IO in parallel). For both IOs, the
Maker drafted in Java offline (encumbering `amt_totalpayout`), then the online Checker
processed the same lines to PR and incremented `amt_totalpayout` **again** — doubling it.

```
Java Maker saves DR (offline)   →  amt_totalpayout += ₱X   (Java encumbrance)
Online Checker processes to PR  →  amt_totalpayout += ₱X   (online PR-time write)
                                   l_qty_payout = 1, l_amt_payout = ₱X   (correct)
                                   ── amt_totalpayout = 2×₱X  ⚠
```

Neither system had a bug — it was a **design gap**: the online PR increment did not
account for the Java encumbrance already written to the same field.

**Fixed going forward:** the online flow now encumbers `amt_totalpayout` at **DR-save**
(aligned with Java) and no longer increments it at PR, so the balance filter correctly
blocks re-drafting a line already encumbered offline. This script repairs the historical
data left behind for these two orders.

---

## What the fix does

Sets `wip_l_lmcline_bal.amt_totalpayout` for the ten affected lines back to the amount
the single online PR actually paid (= `amt_totallmcbudget`):

| IO | wip_l_lmcline_bal_id | Line | From | To | PR |
|---|---|---|---|---|---|
| NLIO00946 | 38024 | MERIENDA PACKAGE | ₱1,600 | ₱800 | NLMC0008192 |
| NLIO00946 | 38030 | INCENTIVE FOR EMCEE | ₱2,000 | ₱1,000 | NLMC0008190 |
| NLIO00946 | 38032 | PHOTOGRAPHER AND VIDEOGRAPHER | ₱3,000 | ₱1,500 | NLMC0008193 |
| NLIO00946 | 38033 | SINGER | ₱2,000 | ₱1,000 | NLMC0008191 |
| NLIO00946 | 38034 | VIDEO LIVESTREAMING | ₱10,000 | ₱5,000 | NLMC0008189 |
| NLIO00947 | 38038 | MERIENDA PACKAGE | ₱1,600 | ₱800 | NLMC0008198 |
| NLIO00947 | 38044 | INCENTIVE FOR EMCEE | ₱2,000 | ₱1,000 | NLMC0008197 |
| NLIO00947 | 38046 | PHOTOGRAPHER AND VIDEOGRAPHER | ₱3,000 | ₱1,500 | NLMC0008195 |
| NLIO00947 | 38047 | SINGER | ₱2,000 | ₱1,000 | NLMC0008194 |
| NLIO00947 | 38048 | VIDEO LIVESTREAMING | ₱10,000 | ₱5,000 | NLMC0008196 |

No other tables are changed. GL entries, `fin_l_debt`, and `wip_t_lmc_bgtline` running
totals were verified correct. Each row is guarded against its **own scope** (946 = 25956,
947 = 25958) so a line can never be written to the wrong IO.

---

## Technical notes

**Verification (run after commit)**

```sql
SELECT b.wip_l_lmcline_bal_id, bl.description, b.amt_totallmcbudget, b.amt_totalpayout,
       b.amt_totallmcbudget - b.amt_totalpayout AS remaining, b.updated
FROM wip_l_lmcline_bal b
JOIN wip_t_lmc_bgtline bl ON bl.wip_l_lmcline_bal_id = b.wip_l_lmcline_bal_id
WHERE b.wip_l_lmcline_bal_id IN (38024,38030,38032,38033,38034, 38038,38044,38046,38047,38048)
ORDER BY b.wip_l_lmcline_bal_id;
```

Expected: `amt_totalpayout = amt_totallmcbudget` and `remaining = 0` for all 10 rows.

**Rollback (restore inflated values)**

```sql
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 1600.00  WHERE wip_l_lmcline_bal_id IN (38024, 38038);
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 2000.00  WHERE wip_l_lmcline_bal_id IN (38030, 38033, 38044, 38047);
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 3000.00  WHERE wip_l_lmcline_bal_id IN (38032, 38046);
UPDATE wip_l_lmcline_bal SET amt_totalpayout = 10000.00 WHERE wip_l_lmcline_bal_id IN (38034, 38048);
```

**Dry-run result (2026-07-01)**

Ran read-only against the replica: all 10 pre-flight checks passed (correct scope,
budget match, exactly 2×, linked PR verified in PR status with matching amount).
"DRY-RUN complete — no changes written." Would correct 10 rows, ₱37,200 → ₱18,600.

**Open item — ERP master DB**

This targets the SAERP working DB (`mysql_secondary`). If the same inflated balances
exist on the live ERP master, the equivalent correction must be applied there by the
senior — this script covers the app-facing replica/working DB only.

**Note:** the standalone per-IO files (946 and 947 scripts + docs) are superseded by
this combined pair. Scan for other affected IOs (NLIO ≥ 948) returned **none**.
