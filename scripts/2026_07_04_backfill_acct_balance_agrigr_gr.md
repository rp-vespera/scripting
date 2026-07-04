# AGRIGR `acct_balance` Repair — GR postings missing from the GL rollup

|                |                                                                             |
| -------------- | --------------------------------------------------------------------------- |
| **Script**     | `scripts/pending/2026_07_04_backfill_acct_balance_agrigr_gr.php`            |
| **Date**       | 2026-07-04                                                                  |
| **Target DB**  | `mysql_secondary`                                                           |
| **Type**       | Data repair                                                                 |
| **Writes**     | `acct_balance` **only**                                                     |
| **Reads**      | `acct_gl`, `acct_balance` (never modified)                                  |
| **Commits**    | Yes (self-committing)                                                       |
| **Idempotent** | **Yes** — SETs balance = `net(acct_gl)`; re-run applies 0 change            |
| **Marker**     | `updated='SCRIPT-WEB'` on every write (inserts also `created='SCRIPT-WEB'`) |

---

## In one sentence

AGRIGR's goods-receipt posting wrote its journal entries to `acct_gl` but never to `acct_balance`, so SAERP's ledger balance reports drifted; this script re-derives `acct_balance` from `acct_gl` for every AGRIGR-GR key so the rollup matches the journal.

---

## The problem

SAERP maintains two GL structures that must agree:

- **`acct_gl`** — the individual journal lines (transaction detail).
- **`acct_balance`** — the netted rollup per `(date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id)`. **This is what the ledger reports read** (e.g. *Inventory Integrity per Product Category (Stockcard Mac)*).

The rule in SAERP (and in AGRIGR's own `InvoiceController::postToAcctBalance`) is: **every `acct_gl` insert must be paired with an `acct_balance` update.**

AGRIGR's GR posting in `PoStatusController` broke that rule — it inserted `acct_gl` but made **no** `acct_balance` write. Because the GR debits go to a suspense account and the later invoice (IGR) *does* post `acct_balance` on the opposite side, the rollup was only ever credited, never debited:

| Account                      | GR side (missing from `acct_balance`) | IGR side (already in `acct_balance`) |
| ---------------------------- | ------------------------------------- | ------------------------------------ |
| **11313** CMI-Suspense       | **debit**                             | credit                               |
| **21138** GRNI               | **credit**                            | debit                                |
| **92005** Input-Tax Suspense | **debit**                             | credit                               |

Result: **CMI-Suspense (11313) drained deeply negative** (a suspense that is only debited-then-credited can never be negative), and the stock-card no longer reconciled with the GL — the ~₱1.9M gap seen on the Inventory Integrity report.

*(Account `11309` CMI-Inventory is posted only by the IGR, which already writes `acct_balance`, so it is correct and is **not** touched.)*

---

## What the script does

For every `acct_balance` key that contains an AGRIGR GR-side row (`created='SYSTEM'`, isolated by account + side per the table above), it enforces the correct invariant:

> **`acct_balance(key) = net of acct_gl(key)`**

- Key present  → **UPDATE** its debit/credit to the recomputed one-sided net, `updated='SCRIPT-WEB'`.
- Key missing  → **INSERT** the net, `created='SCRIPT-WEB'`, `updated='SCRIPT-WEB'`.

Because it **SETs** the balance to the recomputed net (rather than adding a delta), the script is **idempotent and order-independent** — run it any number of times, before or after the `PoStatusController` code fix is deployed, and it always lands on the same correct value (a re-run reports 0 change).

It reports `current → +change → projected` per `(org, account)` and commits. It does **not** touch `acct_gl`, the stock-card, `nvt_t_gr/grline`, `fin_l_debt`, or account `11309`.

---

## Scope & safety — AGRIGR data only

- Only keys that contain an AGRIGR (`created='SYSTEM'`) GR-side row are affected (`HAVING has_gr > 0`).
- **Verified: zero native SAERP rows are touched.** `native rows on an AGRIGR-GR key = 0` — every native `acct_balance` row (thousands on these accounts) is left untouched.
- Every row the script writes carries **`updated='SCRIPT-WEB'`** (inserts also `created='SCRIPT-WEB'`), so its footprint is fully traceable and reversible.
- The corrections are **double-entry balanced per org** (they net to zero), because they re-derive complete GR journals.

---

## Verify (after commit)

```sql
-- 1. Each affected account should now be sane (11313 positive, 21138 negative liability):
SELECT ad_org_id, gl_acct_id, ROUND(SUM(debit)-SUM(credit),2) AS net
FROM acct_balance
WHERE gl_acct_id IN (11313,21138,92005) AND ad_org_id IN (162011,162012)
GROUP BY ad_org_id, gl_acct_id
ORDER BY ad_org_id, gl_acct_id;

-- 2. See exactly what the script wrote:
SELECT gl_acct_id, ad_org_id, COUNT(*) rows_touched, ROUND(SUM(debit)-SUM(credit),2) net
FROM acct_balance
WHERE updated='SCRIPT-WEB' OR created='SCRIPT-WEB'
GROUP BY gl_acct_id, ad_org_id;
```

Confirm on the Inventory Integrity report that CMI-Suspense (11313) is no longer negative and the stock-card vs GL gap has closed by the amounts above.

---

## Rollback

Because the script is idempotent (it re-derives the correct value), the post-run state *is* the correct one — rolling back re-introduces the drift and is rarely wanted. If a run targeted the **wrong database** and must be undone:

1. **Preferred:** restore `acct_balance` from a DB backup taken before the run.
2. The rows the script wrote are tagged `updated='SCRIPT-WEB'` / `created='SCRIPT-WEB'`, so they can be located precisely for manual reversal. (Inserted rows can be deleted; updated rows have no stored pre-image, so a backup is the reliable source.)
