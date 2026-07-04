# AGRIGR Stock-Card `qtybalance` Repair — AGRIGR-touched (sku, locator) groups

|                |                                                                            |
| -------------- | -------------------------------------------------------------------------- |
| **Script**     | `scripts/pending/2026_07_04_fix_agrigr_qtybalance_agrigr_groups.php`       |
| **Date**       | 2026-07-04                                                                 |
| **Target DB**  | `mysql_secondary`                                                          |
| **Type**       | One-time data repair                                                       |
| **Writes**     | `nvt_l_stockcard_locatorqty.qtybalance` **only**                           |
| **Backup**     | `bak_scloc_agrigr_groups` (created each run)                               |
| **Commits**    | Yes (self-committing)                                                      |
| **Idempotent** | **Yes** — recomputes from movements; a clean re-run finds "nothing to fix" |
| **Marker**     | `updated='SCRIPT-WEB'` (`date_updated=NOW()`) on every corrected row       |

---

## In one sentence

The per-locator running stock balance (`qtybalance`) drifted out of step with the actual movements for every item/locator AGRIGR posted to; this script recomputes the running balance from the movements and corrects only the wrong rows, after snapshotting them for rollback.

---

## The problem

`nvt_l_stockcard_locatorqty` is the per-`(sku, locator)` stock card. Each row carries:

- `qtymovement` — the quantity change for that transaction (+receipt / −issue).
- `qtybalance` — the **running on-hand balance** after that row, i.e. it must equal the cumulative `SUM(qtymovement)` over the active rows of that `(sku, locator)` group in canonical order `(date_gl ASC, id ASC)`.

For groups AGRIGR posted to (`created='SYSTEM'` rows present), the stored `qtybalance` no longer matches that running sum. Once one row's balance is wrong, every later row in the same group inherits the error — so the *latest* row (which downstream logic reads as "current on-hand") ends up wrong. This is the same defect class already corrected for documents **P00315–P00348**, here applied to **all** AGRIGR-touched groups.

A wrong latest `qtybalance` misreports on-hand stock and can block or distort consumption/receiving that depends on locator balance.

---

## What the script does

1. **Loads** every **active** row (`COALESCE(is_active,1)=1`) belonging to any `(sku, locator)` group that has at least one `created='SYSTEM'` row, ordered canonically `(sku, locator, date_gl ASC, id ASC)`.
2. **Recomputes** the running balance per group with **BCMath** (6-dp accumulate, 2-dp compare) — `run += qtymovement`.
3. **Collects** only the rows where the stored `qtybalance` ≠ the recomputed running balance, printing `from → to` and whether each row was `SYSTEM` or `native`.
4. **Snapshots** every affected group into `bak_scloc_agrigr_groups` (dropped + recreated each run) — this is DDL and auto-commits **before** any correction.
5. **Updates** each wrong row's `qtybalance` (and stamps `updated='SCRIPT-WEB'`, `date_updated=NOW()`) inside an explicit transaction on `mysql_secondary`; any error rolls the batch back. Prints `updated: N` and `>> COMMITTED.`.

No schema or logic change; `qtymovement`, other columns, and other tables are untouched.

---

## Scope & safety

- **Restricted to AGRIGR-touched groups** — only `(sku, locator)` groups that contain a `created='SYSTEM'` row are considered.
- Within those groups it corrects **whichever rows are wrong**, which may include `native` rows: because the balance is a per-group running sequence, one bad AGRIGR row shifts every subsequent row's balance regardless of who created it. Recomputing the whole group's sequence is the only correct repair, and the `[SYSTEM]` / `[native]` tag in the output shows exactly which rows moved.
- **Backup before write:** `bak_scloc_agrigr_groups` holds a full copy of every affected group for one-statement rollback.
- **Traceable:** every corrected row is stamped `updated='SCRIPT-WEB'` (`date_updated=NOW()`), so the script's footprint is queryable.
- **Idempotent:** balances are derived from `qtymovement`, so re-running after a successful commit recomputes the same values and reports 0 fixes. Safe to re-run to confirm.
- Read-only preflight prints the server hostname and `@@read_only` so an accidental run against the wrong node is obvious.

---

## Verify (after commit)

```sql
-- No group should have any row whose stored qtybalance disagrees with the
-- running SUM(qtymovement) in canonical order. Expected: empty result.
SELECT x.nvt_l_stockcard_locatorqty_id, x.nvt_i_sku_id, x.nvt_i_locator_id,
       x.qtybalance AS stored, x.running
FROM (
  SELECT s.*,
         SUM(s.qtymovement) OVER (
           PARTITION BY s.nvt_i_sku_id, s.nvt_i_locator_id
           ORDER BY s.date_gl, s.nvt_l_stockcard_locatorqty_id
         ) AS running
  FROM nvt_l_stockcard_locatorqty s
  WHERE COALESCE(s.is_active,1)=1
    AND (s.nvt_i_sku_id, s.nvt_i_locator_id) IN (
        SELECT DISTINCT nvt_i_sku_id, nvt_i_locator_id
        FROM nvt_l_stockcard_locatorqty WHERE created='SYSTEM')
) x
WHERE ROUND(x.qtybalance,2) <> ROUND(x.running,2);

-- See exactly what the script corrected:
SELECT nvt_i_sku_id, nvt_i_locator_id, COUNT(*) rows_corrected
FROM nvt_l_stockcard_locatorqty
WHERE updated='SCRIPT-WEB'
GROUP BY nvt_i_sku_id, nvt_i_locator_id;
```

---

## Rollback

Every affected group was snapshotted, so restoration is one statement:

```sql
UPDATE nvt_l_stockcard_locatorqty t
  JOIN bak_scloc_agrigr_groups b USING (nvt_l_stockcard_locatorqty_id)
   SET t.qtybalance = b.qtybalance;
```

*(The backup table is recreated on each run, so it reflects the most recent run's pre-image. Drop it once the fix is confirmed good.)*
