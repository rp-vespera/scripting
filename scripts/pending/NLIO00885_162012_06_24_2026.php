<?php // scripts/pending/NLIO00885_162012_06_24_2026.php
// NLIO00885 (project 13285, org 162012) — backfill missing GL post for payout NLMC0007618.
// Defect: payout NLMC0007618 (apcr 1,578.02 on 2026-03-04) exists in wip_t_lmc_payout with
// docstatus = PR, but its journal entries were never created. All other 12 payouts on this
// project posted correctly. Same defect pattern as NLIO00946 (missing GL post), narrower scope.
// Fix: post the missing double-entry as 1 acct_doc + 2 acct_gl + 2 acct_balance rows.
// Mirrors the exact pair used by the project's other apcr payouts (e.g. NLMC0007649).

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 13285;
    $ORG     = 162012;
    $WIP_ACCT = 12502;   $WIP_SUB = 40577;   // WIP for project 13285
    $COS_ACCT = 52028;   $COS_SUB = 40510;   // Salary & Wages Suspense / BPARTNER 24156
    $SUBMOD   = 445;                           // ILMC (Invoice LMC) — used by all other payouts' GL posts
    $DATE_GL  = '2026-03-04';                  // original payout date
    $AMOUNT   = 1578.02;
    $TOL      = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00885_162012 — backfill missing GL post for payout NLMC0007618");
    $say(" Project $PROJ_ID / Org $ORG / Pair (12502 WIP / 52028 Salary&Wages Suspense) / Date $DATE_GL");
    $say(" Expected variance to close: +1,578.02 → 0.00");
    $say($line);

    // ============================================================
    // IDEMPOTENCY CHECK
    // ============================================================
    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM acct_balance
         WHERE created LIKE 'SCRIPT-WEB-%'
           AND ad_org_id = ? AND gl_acct_id = ? AND gl_subacct_id = ?
           AND doc_i_submod_id = ? AND date_gl = ?",
        [$ORG, $WIP_ACCT, $WIP_SUB, $SUBMOD, $DATE_GL]
    )->c;
    if ($existing > 0) {
        $glNow = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" NO-OP — fix already applied. Current gl_amt for project = " . $money($glNow));
        $say($line);
        return;
    }

    // ============================================================
    // PRE-CHECKS — source payout still exists in wip_t_lmc_payout AND has no GL entries
    // ============================================================
    $payout = $db->selectOne(
        "SELECT wip_t_lmc_payout_id, docstatus, amt_total_acctpair_credit_payout, date_gl
         FROM wip_t_lmc_payout
         WHERE wip_t_lmc_payout_id = 54802 AND documentno = 'NLMC0007618'
           AND ad_org_id = ? AND wip_i_project_scope_id IN
                (SELECT wip_i_project_scope_id FROM wip_i_project_scope WHERE wip_i_project_id = ?)",
        [$ORG, $PROJ_ID]
    );
    if (!$payout) throw new \RuntimeException("Source payout NLMC0007618 (id 54802) not found");
    if ($payout->docstatus !== 'PR') throw new \RuntimeException("Source payout docstatus is {$payout->docstatus}, expected PR");
    if (abs((float)$payout->amt_total_acctpair_credit_payout - $AMOUNT) > $TOL) {
        throw new \RuntimeException("Source payout amt drifted: expected $AMOUNT, got " . $payout->amt_total_acctpair_credit_payout);
    }
    $say("");
    $say(" PRE-CHECK source payout: id=" . $payout->wip_t_lmc_payout_id . " docstatus=PR amt=" . $money((float)$payout->amt_total_acctpair_credit_payout));

    // Verify variance is still 1578.02 (gl side missing the amount)
    $glBefBal = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
         FROM acct_balance bal
         JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ_ID, $WIP_ACCT, $ORG]
    )->gl;
    $say("   acct_balance net (12502 for this project) = " . $money($glBefBal) . " (expected 15,853.85 — operational total minus 1,578.02)");

    // ============================================================
    // APPLY — 5 INSERTs in FK-safe order
    // ============================================================
    $ts = gmdate('ymdHis');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $batch = 'SCRIPT-WEB-' . $ts . $alphabet[random_int(0, strlen($alphabet) - 1)];

    $say("");
    $say(" APPLYING (transaction)  batch_id = $batch");

    $db->beginTransaction();
    try {
        // 1. acct_doc header
        $db->insert(
            "INSERT INTO acct_doc (explanation, date_created, is_active)
             VALUES (?, UTC_TIMESTAMP(), 1)",
            ['NLIO00885 - STANDARD LAWN INT. W/ MASS - backfill missing GL post for NLMC0007618']
        );
        $docId = (int) $db->selectOne("SELECT LAST_INSERT_ID() AS id")->id;
        $say("   INSERT acct_doc → new acct_doc_id = $docId");

        // 2. acct_gl — DR 12502 WIP (project subacct)
        $db->insert(
            "INSERT INTO acct_gl
               (ad_org_id, gl_acct_id, gl_subacct_id, documentno,
                date_gl, date_trans, debit, credit,
                acct_doc_id, doc_i_submod_id,
                created, date_created, updated, date_updated)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 0.00, ?, ?,
                     ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
            [$ORG, $WIP_ACCT, $WIP_SUB, $batch, $DATE_GL, $AMOUNT, $docId, $SUBMOD, $batch, $batch]
        );
        $glDrId = (int) $db->selectOne("SELECT LAST_INSERT_ID() AS id")->id;
        $say("   INSERT acct_gl    → DR 12502 / 40577 = " . $money($AMOUNT) . "  (acct_gl_id $glDrId)");

        // 3. acct_gl — CR 52028 Salary&Wages Suspense (BPARTNER subacct)
        $db->insert(
            "INSERT INTO acct_gl
               (ad_org_id, gl_acct_id, gl_subacct_id, documentno,
                date_gl, date_trans, debit, credit,
                acct_doc_id, doc_i_submod_id,
                created, date_created, updated, date_updated)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), 0.00, ?, ?, ?,
                     ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
            [$ORG, $COS_ACCT, $COS_SUB, $batch, $DATE_GL, $AMOUNT, $docId, $SUBMOD, $batch, $batch]
        );
        $glCrId = (int) $db->selectOne("SELECT LAST_INSERT_ID() AS id")->id;
        $say("   INSERT acct_gl    → CR 52028 / 40510 = " . $money($AMOUNT) . "  (acct_gl_id $glCrId)");

        // 4. acct_balance — DR 12502 WIP
        $db->insert(
            "INSERT INTO acct_balance
               (gl_acct_id, gl_subacct_id, ad_org_id, doc_i_submod_id,
                debit, credit, date_gl, is_active,
                created, date_created, updated, date_updated)
             VALUES (?, ?, ?, ?, ?, 0.00, ?, 1,
                     ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
            [$WIP_ACCT, $WIP_SUB, $ORG, $SUBMOD, $AMOUNT, $DATE_GL, $batch, $batch]
        );
        $balDrId = (int) $db->selectOne("SELECT LAST_INSERT_ID() AS id")->id;
        $say("   INSERT acct_balance → DR 12502 / 40577 = " . $money($AMOUNT) . "  (acct_balance_id $balDrId)");

        // 5. acct_balance — CR 52028
        $db->insert(
            "INSERT INTO acct_balance
               (gl_acct_id, gl_subacct_id, ad_org_id, doc_i_submod_id,
                debit, credit, date_gl, is_active,
                created, date_created, updated, date_updated)
             VALUES (?, ?, ?, ?, 0.00, ?, ?, 1,
                     ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
            [$COS_ACCT, $COS_SUB, $ORG, $SUBMOD, $AMOUNT, $DATE_GL, $batch, $batch]
        );
        $balCrId = (int) $db->selectOne("SELECT LAST_INSERT_ID() AS id")->id;
        $say("   INSERT acct_balance → CR 52028 / 40510 = " . $money($AMOUNT) . "  (acct_balance_id $balCrId)");

        // POST-CHECKS — variance closed in BOTH books
        $glAftBal = (float) $db->selectOne(
            "SELECT
               IFNULL((SELECT SUM(consume.amt_total_consume) FROM wip_t_project_consumption consume
                       JOIN wip_i_project_scope_stage stage ON stage.wip_i_project_scope_stage_id = consume.wip_i_project_scope_stage_id
                       JOIN wip_i_project_scope scope ON scope.wip_i_project_scope_id = stage.wip_i_project_scope_id
                       WHERE scope.wip_i_project_id = ? AND consume.docstatus = 'PR' AND consume.ad_org_id = ?), 0) +
               IFNULL((SELECT SUM(IFNULL(pay.amt_total_payout,0)+IFNULL(pay.amt_total_acctpair_credit_payout,0))
                       FROM wip_t_lmc_payout pay
                       JOIN wip_i_project_scope scope ON scope.wip_i_project_scope_id = pay.wip_i_project_scope_id
                       WHERE scope.wip_i_project_id = ? AND pay.docstatus = 'PR' AND pay.ad_org_id = ?), 0) -
               IFNULL((SELECT SUM(bal.debit - bal.credit)
                       FROM acct_balance bal
                       JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
                       WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?), 0) AS variance",
            [$PROJ_ID, $ORG, $PROJ_ID, $ORG, $PROJ_ID, $WIP_ACCT, $ORG]
        )->variance;
        $say("");
        $say(" POST-CHECK: scanner variance = " . $money($glAftBal) . "  (expected 0.00)");
        if (abs($glAftBal) > $TOL) throw new \RuntimeException("Post-check failed: variance is $glAftBal");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — variance closed to 0.00 in BOTH books. Missing payout GL post backfilled.");
    $say(" Audit queries:");
    $say("   SELECT * FROM acct_gl      WHERE created = '$batch';");
    $say("   SELECT * FROM acct_balance WHERE created = '$batch';");
    $say("   SELECT * FROM acct_doc     WHERE acct_doc_id = $docId;");
    $say($line);
};
