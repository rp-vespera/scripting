<?php // scripts/pending/NLIO00885_162012_06_24_2026_rollback.php
// Rollback: deletes the 5 SCRIPT-WEB rows (2 acct_gl + 2 acct_balance + 1 acct_doc) in FK-safe order.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 13285;
    $ORG     = 162012;
    $WIP_ACCT = 12502;   $WIP_SUB = 40577;
    $SUBMOD   = 445;
    $DATE_GL  = '2026-03-04';
    $TOL      = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00885_162012 — ROLLBACK (remove SCRIPT-WEB row, restore +1,578.02 variance)");
    $say($line);

    // Find the batch_id from the WIP-side acct_balance row
    $bal = $db->selectOne(
        "SELECT acct_balance_id, created FROM acct_balance
         WHERE created LIKE 'SCRIPT-WEB-%'
           AND ad_org_id = ? AND gl_acct_id = ? AND gl_subacct_id = ?
           AND doc_i_submod_id = ? AND date_gl = ?",
        [$ORG, $WIP_ACCT, $WIP_SUB, $SUBMOD, $DATE_GL]
    );
    if (!$bal) throw new \RuntimeException("Rollback pre-check failed: no SCRIPT-WEB row on WIP side — fix not applied?");
    $batch = $bal->created;
    $say("");
    $say(" Found batch_id = $batch");

    $glRow = $db->selectOne("SELECT acct_doc_id FROM acct_gl WHERE created = ? LIMIT 1", [$batch]);
    if (!$glRow) throw new \RuntimeException("No acct_gl rows tagged $batch");
    $docId = (int) $glRow->acct_doc_id;
    $say(" Linked acct_doc_id = $docId");

    $say("");
    $say(" APPLYING ROLLBACK (transaction)");
    $db->beginTransaction();
    try {
        $a = $db->delete("DELETE FROM acct_balance WHERE created = ?", [$batch]);
        $say("   DELETE acct_balance WHERE created='$batch'  →  affected=$a (expected 2)");
        if ($a !== 2) throw new \RuntimeException("acct_balance delete affected $a");

        $b = $db->delete("DELETE FROM acct_gl WHERE created = ?", [$batch]);
        $say("   DELETE acct_gl      WHERE created='$batch'  →  affected=$b (expected 2)");
        if ($b !== 2) throw new \RuntimeException("acct_gl delete affected $b");

        $c = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = ?", [$docId]);
        $say("   DELETE acct_doc     WHERE acct_doc_id=$docId →  affected=$c (expected 1)");
        if ($c !== 1) throw new \RuntimeException("acct_doc delete affected $c");

        // POST-CHECK: variance back to +1,578.02
        $varAfter = (float) $db->selectOne(
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
        $say(" POST-CHECK: scanner variance = " . $money($varAfter) . "  (expected +1,578.02)");
        if (abs($varAfter - 1578.02) > $TOL) throw new \RuntimeException("Rollback variance is $varAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — pre-fix state restored. NLIO00885 variance back to +1,578.02.");
    $say($line);
};
