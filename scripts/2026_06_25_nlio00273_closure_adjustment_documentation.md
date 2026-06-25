# NLIO00273 Closure Adjustment — Back-correct stranded ₱40 WIP variance

*Date: 2026-06-25 · Author: JC Mejia · Affected application: NLIO org (ad_org_id = 162012)*

> **Single run-once script for one finding.**
> The script is idempotent (skips if the rows are already tagged with `SCRIPT-WEB-…`),
> wrapped in a single transaction (any failure rolls back the whole apply), and includes
> pre/post checks that abort cleanly on data drift.
>
> Script: `scripts/pending/NLIO00273_162012_06_25_2026.php`

---

## PART 1 — Plain-Language Summary (for management)

### The situation

NLIO00273 (project 4795, "COMMUNITY VAULT INTERMENT W/ OUT MASS") has carried a **−₱40
hanging variance** on its WIP account (12502, subacct 24165) since the project closed
in November 2023. The FRS Project Integrity scanner has been flagging this every scan.

The ₱40 traces back to a cancel-and-reissue chain on a single LMC payout from 2023
that the operational tables tracked correctly but the GL journal never mirrored:

| Date | Document | Operational amount | Status |
|---|---|---:|---|
| 2022-12-21 | NLMC0003326 (original payout) | +₱551.00 | PR |
| 2023-01-26 | NLMC0003326-CA (cancellation) | −₱551.00 | PR |
| 2023-01-26 | NLMC0003400 (reissue) | +₱511.00 | PR |
| | **Operational net (what was actually paid)** | **₱511.00** | |

The GL kept the original ₱551 and never received the −₱551/+₱511 correction pair.
Difference: ₱40. When the project closed in Nov 2023 against the operational total
of ₱1,794.07, the GL had ₱1,834.07 sitting there — leaving exactly ₱40 stranded.

Cross-referenced with IMS ticket #15851.

### What we correct

Back-correct the four GL rows of the original ₱551 payout posting (acct_doc 103555946,
docno NILMC0000147) so they reflect the actually-paid amount of ₱511. This is what
SAERP should have done in 2023 when NLMC0003326 was cancelled and replaced by NLMC0003400.

| Row | Table | Side | Before | After |
|---|---|---|---:|---:|
| 1364286 | acct_gl | debit | 551.00 | **511.00** |
| 1364287 | acct_gl | credit | 551.00 | **511.00** |
| 552562 | acct_balance | debit | 551.00 | **511.00** |
| 552634 | acct_balance | credit | 551.00 | **511.00** |

### Effect

- **WIP project net**: +40 → **0** (variance closed)
- **A/P to SABAY, ROSARIO B.**: −40 (correctly reflects ₱511 owed, not ₱551)
- **Closure NWPCL-ACPR00867 (₱1,794.07)**: untouched — it now matches the corrected GL total
- **Double-entry preserved** in both books, no new rows added

### Safety measures

- **Idempotency**: each target row is checked for an existing `updated='SCRIPT-WEB-…'`
  tag before any write. A second run with the rows already updated exits early
  with "NO-OP — rows already tagged."
- **Pre-checks**: every target row is verified to be at the expected pre-state value
  (debit/credit = 551.00 and `updated IS NULL`) before any UPDATE runs. Any drift
  aborts the script and leaves the row untouched.
- **Transactional**: all four UPDATEs run in a single transaction. If any check or
  UPDATE fails, the whole apply rolls back.
- **Post-check**: after all four updates, the WIP net for project 4795 is recomputed
  in both `acct_balance` and `acct_gl` to confirm 0.00. If either is non-zero, the
  transaction rolls back.
- **Audit tag**: each updated row gets `updated='SCRIPT-WEB-{YYMMDDHHMMSS}{LETTER}'`
  for traceability and to enable the rollback script to find them.

### Where this runs

The script uses the `mysql_secondary` connection. Validated on `saerp_rp_replica`
prior to live deployment.

---

## PART 2 — Technical Detail (for IT / execution)

### Root cause

The 2023 cancel-and-reissue workflow on NLMC0003326 → NLMC0003326-CA → NLMC0003400
reached `wip_t_lmc_payout` (operational table) but never fired the GL post for either
the cancellation or the reissue. The most likely culprit, based on what we observed
in 2026, is the Hibernate lazy-init bug in
`ph.softartifact.scm.client.wip.cservice.TWipLmcPayoutLiquidationCService.java:67` —
this bug masks underlying validation errors (e.g. expired bpartner accreditation)
as a Hibernate `failed to lazily initialize a collection of role:
WipIProjectCategory.wipIProjectCategoryAccts` exception, and the surrounding
exception handler swallows the transaction without journaling.

The result is a silent operational-vs-GL desync: the t-table tracks the correction,
the L-tables do not.

When the project closed in Nov 2023 for the operational total (₱1,794.07), the closure
correctly drained ₱1,794.07 from WIP, leaving ₱40 of "phantom" debit on the original
+₱551 row that nothing claimed.

### Rows touched (all four share acct_doc_id = 103555946)

```
acct_gl 1364286       DR WIP 12502 / 24165   551 → 511   (drains 40 from WIP)
acct_gl 1364287       CR AP  21101 / 24140   551 → 511   (reduces AP owed by 40)
acct_balance 552562   DR WIP 12502 / 24165   551 → 511
acct_balance 552634   CR AP  21101 / 24140   551 → 511
```

### Why this approach (UPDATE, not INSERT)

The "no new rows" rule applies here: instead of posting a counter-entry to neutralise
the stranded ₱40, we directly correct the existing posting to reflect what was actually
paid. Net economic effect is the same as a counter-entry, but the audit trail is
cleaner — one historical row corrected vs two new rows plus the original wrong row.

### Dry run output (replica, 2026-06-25)

```
==========================================================================================
 NLIO00273_162012 — back-correct +551 LMC payout to +511 (closes -40 variance)
 Project 4795 / Org 162012 / acct_doc 103555946 (NILMC0000147, 2022-12-29)
==========================================================================================

 PRE-CHECK — verifying each of the 4 target rows has the old +551:
   acct_gl id=1364286       debit=551.00   ✓
   acct_gl id=1364287       credit=551.00  ✓
   acct_balance id=552562   debit=551.00   ✓
   acct_balance id=552634   credit=551.00  ✓

 Variance BEFORE — WIP nets must both be +40.00 (the residual to drain):
   acct_balance net = 40.00
   acct_gl      net = 40.00

 APPLYING (transaction)  batch_id = SCRIPT-WEB-260625XXXXXXX
   UPDATE acct_gl id=1364286       debit: 551.00 → 511.00   tag=SCRIPT-WEB-…
   UPDATE acct_gl id=1364287       credit: 551.00 → 511.00  tag=SCRIPT-WEB-…
   UPDATE acct_balance id=552562   debit: 551.00 → 511.00   tag=SCRIPT-WEB-…
   UPDATE acct_balance id=552634   credit: 551.00 → 511.00  tag=SCRIPT-WEB-…

 POST-CHECK (both must be 0.00):
   acct_balance net = 0.00
   acct_gl      net = 0.00

==========================================================================================
 SUCCESS — variance closed to 0.00 in BOTH books.
==========================================================================================
```

### Verification queries (run after the script commits)

```sql
-- Confirm the 4 GL rows are at 511.00 and tagged
SELECT acct_gl_id, documentno, gl_acct_id, gl_subacct_id, debit, credit, updated
FROM   acct_gl
WHERE  acct_gl_id IN (1364286, 1364287);

SELECT acct_balance_id, gl_acct_id, gl_subacct_id, date_gl, debit, credit, updated
FROM   acct_balance
WHERE  acct_balance_id IN (552562, 552634);

-- Confirm the variance is 0 in both books
SELECT
  ROUND(SUM(bal.debit - bal.credit), 2) AS acct_balance_net,
  (SELECT ROUND(SUM(ag.debit - ag.credit), 2) FROM acct_gl ag
     JOIN gl_subacct sub ON sub.gl_subacct_id = ag.gl_subacct_id
     WHERE sub.wip_i_project_id = 4795 AND ag.gl_acct_id = 12502 AND ag.ad_org_id = 162012
  ) AS acct_gl_net
FROM   acct_balance bal
JOIN   gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
WHERE  sub.wip_i_project_id = 4795 AND bal.gl_acct_id = 12502 AND bal.ad_org_id = 162012;

-- Both columns must be 0.00 after apply
```

### Rollback

A companion rollback script reverts the 4 UPDATEs (511 → 551) and clears the
`updated` tag back to NULL, restoring byte-identical pre-fix state. Rollback is
held in the standby/bundle folder and is not committed to `scripts/pending/` —
it must be deliberately moved into pending when a revert is required, then
removed from pending after the rollback runs.

### Locked rules honoured

- `no_new_db_tables` — no schema changes
- `no_new_rows` — only in-place UPDATEs; zero INSERTs, zero DELETEs
- `no_delete_on_live` — not applicable
- `rollback_must_match_original` — rollback restores byte-identical pre-fix state
- `data_accuracy` — ₱40 = 551 − 511, verified by direct probe of the 3 operational records
- `frs_scope_only` — accounting-side rows only
- `replica_reproduction_sufficient` — fix proven on `saerp_rp_replica`

### Open item — SAERP-side EXB / LMC modules

The underlying defect (cancel/reissue not journaling) is a SAERP code-level issue,
not just a data correction. Every legacy bpartner with a pre-2023 cancel+reissue chain
on an LMC payout is at risk of carrying the same kind of stranded variance. The
script in this PR closes one finding (NLIO00273 / ₱40); it does not prevent future
occurrences. The structural fix belongs in the SAERP SCM/BPM team — see IMS#15851
for the original bug report, and IMS#16215 for the related EXB-side rounding defect.
