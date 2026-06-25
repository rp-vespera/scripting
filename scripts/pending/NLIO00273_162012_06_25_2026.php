<?php // scripts/pending/NLIO00273_162012_06_25_2026.php
// NLIO00273 (project 4795, org 162012) — back-correct the original ₱551 LMC payout
// posting to its actually-paid ₱511 amount. Closes the residual -₱40 WIP variance.
//
// Root cause (per IMS#15851):
//   Payout NLMC0003326 was issued for ₱551 on 2022-12-21, journaled to GL the same
//   day as acct_doc 103555946 / NILMC0000147 (DR WIP 24165 / CR AP 24140 — both books).
//   It was then cancelled (NLMC0003326-CA -₱551) and reissued (NLMC0003400 +₱511) on
//   2023-01-26. The cancel+reissue chain reached wip_t_lmc_payout but the GL journal
//   posts never fired (likely the same Hibernate/accreditation bugs we hit in 2026).
//   The +₱551 GL entry has been frozen for 3.5 years — when the project closed in
//   Nov 2023 for the corrected ops total (₱1,794.07), the GL had ₱40 extra debit.
//
// Per "no new rows" rule: instead of posting a counter-entry, UPDATE the original
// payout's 4 ledger rows in-place from 551 → 511. This matches what SAERP should
// have done when NLMC0003326 was cancelled and replaced by NLMC0003400.
//
// Rows touched (all 4 share acct_doc_id=103555946):
//   acct_gl 1364286       DR WIP 12502/24165   551 → 511   (drains 40 from WIP)
//   acct_gl 1364287       CR AP  21101/24140   551 → 511   (reduces AP owed by 40)
//   acct_balance 552562   DR WIP 12502/24165   551 → 511
//   acct_balance 552634   CR AP  21101/24140   551 → 511
//
// Effect:
//   WIP project net:   +40 → 0   (variance closed)
//   AP Rosario net:    -40       (the ₱40 we never actually owed her is removed)
//   Cost of Service:   unchanged
//   Both books remain in sync — double-entry preserved.
//
// Audit tag in `updated` column: SCRIPT-WEB-{ts}{letter}. Use this for rollback.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 4795;
    $ORG     = 162012;
    $WIP_ACCT = 12502;  $WIP_SUB = 24165;
    $AP_ACCT  = 21101;  $AP_SUB  = 24140;
    $OLD = 551.00;
    $NEW = 511.00;
    $TOL = 0.01;

    $TARGETS = [
        // [table,                key_col,                target_id, side]
        ['acct_gl',      'acct_gl_id',      1364286, 'debit'],
        ['acct_gl',      'acct_gl_id',      1364287, 'credit'],
        ['acct_balance', 'acct_balance_id', 552562,  'debit'],
        ['acct_balance', 'acct_balance_id', 552634,  'credit'],
    ];

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00273_162012 — back-correct +551 LMC payout to +511 (closes -40 variance)");
    $say(" Project $PROJ_ID / Org $ORG / acct_doc 103555946 (NILMC0000147, 2022-12-29)");
    $say($line);

    // ============================================================
    // IDEMPOTENCY CHECK
    // ============================================================
    $alreadyTagged = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM acct_gl
         WHERE acct_gl_id IN (1364286, 1364287)
           AND updated LIKE 'SCRIPT-WEB-%'"
    )->c;
    if ($alreadyTagged > 0) {
        $say("");
        $say(" NO-OP — rows already tagged with SCRIPT-WEB. Run rollback first if you want to re-apply.");
        $say($line);
        return;
    }

    // ============================================================
    // PRE-CHECKS — verify each target row is in expected pre-state
    // ============================================================
    $say("");
    $say(" PRE-CHECK — verifying each of the 4 target rows has the old +551:");
    foreach ($TARGETS as [$tbl, $keyCol, $id, $side]) {
        $row = $db->selectOne("SELECT $side AS v, updated FROM $tbl WHERE $keyCol = ?", [$id]);
        if (!$row)                            throw new \RuntimeException("$tbl id=$id NOT FOUND");
        if (abs((float)$row->v - $OLD) > $TOL) throw new \RuntimeException("$tbl id=$id $side=$row->v, expected $OLD");
        if (!is_null($row->updated))          throw new \RuntimeException("$tbl id=$id already has updated='$row->updated' — abort");
        $say("   $tbl id=$id  $side=" . $money((float)$row->v) . "  ✓");
    }

    // Pre-fix variance check
    $glBefBal = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
         FROM acct_balance bal JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ_ID, $WIP_ACCT, $ORG]
    )->gl;
    $glBefGl = (float) $db->selectOne(
        "SELECT IFNULL(SUM(gl.debit - gl.credit), 0) AS gl
         FROM acct_gl gl JOIN gl_subacct sub ON sub.gl_subacct_id = gl.gl_subacct_id
         WHERE sub.wip_i_project_id = ? AND gl.gl_acct_id = ? AND gl.ad_org_id = ?",
        [$PROJ_ID, $WIP_ACCT, $ORG]
    )->gl;
    $say("");
    $say(" Variance BEFORE — WIP nets must both be +40.00 (the residual to drain):");
    $say("   acct_balance net = " . $money($glBefBal));
    $say("   acct_gl      net = " . $money($glBefGl));
    if (abs($glBefBal - 40.0) > $TOL) throw new \RuntimeException("Pre-check acct_balance net is $glBefBal, expected 40");
    if (abs($glBefGl  - 40.0) > $TOL) throw new \RuntimeException("Pre-check acct_gl net is $glBefGl, expected 40");

    // ============================================================
    // APPLY — 4 UPDATEs in a transaction
    // ============================================================
    $ts       = gmdate('ymdHis');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $batch    = 'SCRIPT-WEB-' . $ts . $alphabet[random_int(0, strlen($alphabet) - 1)];

    $say("");
    $say(" APPLYING (transaction)  batch_id = $batch");
    $db->beginTransaction();
    try {
        foreach ($TARGETS as [$tbl, $keyCol, $id, $side]) {
            $db->update(
                "UPDATE $tbl SET $side = ?, updated = ?, date_updated = UTC_TIMESTAMP() WHERE $keyCol = ?",
                [$NEW, $batch, $id]
            );
            $say("   UPDATE $tbl id=$id  $side: " . $money($OLD) . " → " . $money($NEW) . "  tag=$batch");
        }

        // POST-CHECKS — both books must net to 0 on WIP for this project
        $glAftBal = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $glAftGl = (float) $db->selectOne(
            "SELECT IFNULL(SUM(gl.debit - gl.credit), 0) AS gl
             FROM acct_gl gl JOIN gl_subacct sub ON sub.gl_subacct_id = gl.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND gl.gl_acct_id = ? AND gl.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance net = " . $money($glAftBal));
        $say("   acct_gl      net = " . $money($glAftGl));
        if (abs($glAftBal) > $TOL) throw new \RuntimeException("Post-check acct_balance is $glAftBal");
        if (abs($glAftGl)  > $TOL) throw new \RuntimeException("Post-check acct_gl is $glAftGl");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — variance closed to 0.00 in BOTH books.");
    $say(" Audit queries:");
    $say("   SELECT * FROM acct_gl      WHERE updated = '$batch';");
    $say("   SELECT * FROM acct_balance WHERE updated = '$batch';");
    $say($line);
};
