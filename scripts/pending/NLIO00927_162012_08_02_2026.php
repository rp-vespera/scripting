<?php // NLIO00927_162012_08_02_2026.php
// ============================================================================
// LMC payout header double-books the account-pair credit — NLIO00927
// Org 162012 · mysql_secondary · 2026-08-02
//
//   NLMC0008378 is a pure account-pair credit payout: no labour payout lines,
//   one account-pair line of 5,098.82. Its header carries that same 5,098.82 in
//   amt_total_payout and amt_total_payout_net, which by convention hold the
//   labour total only.
//
//   Project Stage Variance reads the header as
//     amt_total_payout_net + amt_total_acctpair_credit_payout_net
//   so scope 25905 INTERMENT ORDER prints 7,698.82 + 5,098.82 = 12,797.64
//   against a 7,872.81 budget, giving (4,924.83) instead of 173.99.
//
//   The report is correct: 29,798 Java-written payouts follow the convention.
//   127 headers written by 'WEB Accounting' on 2026-07-27 and 07-28 do not.
//   This is one of them; the other 126 follow in a separate script.
//
//   Sets the two labour totals to the sum of this document's own payout lines,
//   which is 0.00. The account-pair columns, every payout line, every balance
//   and the ledger are untouched.
//
// WRITES     wip_t_lmc_payout — 1 UPDATE, 2 columns
// ROLLBACK   NLIO00927_162012_08_02_2026_rollback.php
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $POSTED   = date('Y-m-d');               // stamped with the day it actually runs
    $IMS      = null;
    $TAG      = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;
    $STAMP    = $POSTED . ' 00:00:00';

    $ORG      = 162012;
    $PAYOUT   = 55810;                       // wip_t_lmc_payout
    $DOCNO    = 'NLMC0008378';
    $SCOPE    = 25905;                       // INTERMENT ORDER
    $STAGE    = 34108;
    $ACCTPAIR = 5098.82;
    $WRITER   = 'WEB Accounting';

    $EXP_LABOUR       = 0.00;                // this document's own payout lines
    $EXP_SCOPE_BEFORE = 12797.64;            // what the report prints today
    $EXP_SCOPE_AFTER  = 7698.82;
    $EXP_BUDGET       = 7872.81;

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 92);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    // what the Project Stage Variance scope-details subreport computes
    $reported = fn(int $scope) => (float) $db->selectOne(
        'SELECT ROUND(SUM(IFNULL(amt_total_payout_net,0)) + SUM(IFNULL(amt_total_acctpair_credit_payout_net,0)),2) v
           FROM wip_t_lmc_payout WHERE wip_i_project_scope_id = ? AND docstatus = ?', [$scope, 'PR'])->v;

    $labour = fn(int $id) => (float) $db->selectOne(
        'SELECT ROUND(IFNULL(SUM(IFNULL(amt,0) - IFNULL(l_amt_returned,0)),0),2) v
           FROM wip_t_lmc_payoutline WHERE wip_t_lmc_payout_id = ?', [$id])->v;

    $say($L);
    $say(' NLMC0008378 — LMC payout header double-books the account-pair credit');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · org ' . $ORG . ' · tag ' . $TAG . ' · COMMIT');
    $say($L);

    // ---------------- GATE 1 · the header is exactly as audited ----------------
    $h = $db->selectOne('SELECT documentno, docstatus, ad_org_id, wip_i_project_scope_id sc,
                                amt_total_payout, amt_total_payout_net,
                                amt_total_payout_tax tax, amt_total_payout_wtax wtax,
                                amt_total_acctpair_credit_payout ap_gross,
                                amt_total_acctpair_credit_payout_net ap_net,
                                COALESCE(created,\'\') created, COALESCE(updated,\'\') updated
                           FROM wip_t_lmc_payout WHERE wip_t_lmc_payout_id = ?', [$PAYOUT]);
    if (!$h) throw new \RuntimeException("GATE 1 FAILED: payout $PAYOUT not found. ABORT.");
    if ($h->documentno !== $DOCNO || $h->docstatus !== 'PR' || (int) $h->ad_org_id !== $ORG
        || (int) $h->sc !== $SCOPE)
        throw new \RuntimeException("GATE 1 FAILED: payout $PAYOUT is {$h->documentno} / {$h->docstatus}"
            . " / org {$h->ad_org_id} / scope {$h->sc} — not the audited document. ABORT.");
    if (abs((float) $h->amt_total_payout - $ACCTPAIR) > 0.001
        || abs((float) $h->amt_total_payout_net - $ACCTPAIR) > 0.001)
        throw new \RuntimeException('GATE 1 FAILED: header totals are ' . $m($h->amt_total_payout) . ' / '
            . $m($h->amt_total_payout_net) . ', expected ' . $m($ACCTPAIR) . ' on both. ABORT.');
    if (abs((float) $h->ap_net - $ACCTPAIR) > 0.001)
        throw new \RuntimeException('GATE 1 FAILED: account-pair net is ' . $m($h->ap_net)
            . ', expected ' . $m($ACCTPAIR) . '. ABORT.');
    if (abs((float) $h->tax) > 0.001 || abs((float) $h->wtax) > 0.001)
        throw new \RuntimeException('GATE 1 FAILED: tax ' . $m($h->tax) . ' / wtax ' . $m($h->wtax)
            . ' are non-zero; the labour totals are not a clean copy of the account-pair figure. ABORT.');
    if ($h->created !== $WRITER)
        throw new \RuntimeException("GATE 1 FAILED: created='{$h->created}', expected '$WRITER'. "
            . 'Only the backend-written headers carry this defect. ABORT.');

    // ---------------- GATE 2 · no labour lines back the header total ----------------
    $lab = $labour($PAYOUT);
    if (abs($lab - $EXP_LABOUR) > 0.01)
        throw new \RuntimeException('GATE 2 FAILED: payout lines total ' . $m($lab) . ', expected '
            . $m($EXP_LABOUR) . '. The header total may be legitimate. ABORT.');

    // ---------------- GATE 3 · the account-pair line is intact ----------------
    $apLine = (float) $db->selectOne(
        'SELECT ROUND(IFNULL(SUM(IFNULL(amt_acctpair_credit_payout,0)
                                - IFNULL(l_amt_acctpair_credit_payout_ret,0)),0),2) v
           FROM wip_t_lmc_payoutline_acctpair_credit WHERE wip_t_payout_id = ?', [$PAYOUT])->v;
    if (abs($apLine - $ACCTPAIR) > 0.01)
        throw new \RuntimeException('GATE 3 FAILED: account-pair lines total ' . $m($apLine)
            . ', expected ' . $m($ACCTPAIR) . '. ABORT.');

    // ---------------- GATE 4 · the report reads what we audited ----------------
    $before = $reported($SCOPE);
    if (abs($before - $EXP_SCOPE_BEFORE) > 0.01)
        throw new \RuntimeException('GATE 4 FAILED: scope ' . $SCOPE . ' reports ' . $m($before)
            . ', expected ' . $m($EXP_SCOPE_BEFORE) . '. ABORT.');

    $say('        payout ' . $PAYOUT . ' · ' . $DOCNO . ' · PR · scope ' . $SCOPE . ' · created ' . $WRITER);
    $say('        header labour totals  ' . $m($h->amt_total_payout) . ' gross · '
         . $m($h->amt_total_payout_net) . ' net');
    $say('        its own payout lines  ' . $m($lab));
    $say('        account-pair lines    ' . $m($apLine) . '  (unchanged)');
    $say(' GATES  0 replica · 1 header · 2 no labour lines · 3 account-pair intact · 4 report ..... PASS');
    $say('');
    $say('   PLAN — 1 UPDATE on wip_t_lmc_payout ' . $PAYOUT);
    $say('   amt_total_payout      ' . $m($h->amt_total_payout) . '  ->  ' . $m($EXP_LABOUR));
    $say('   amt_total_payout_net  ' . $m($h->amt_total_payout_net) . '  ->  ' . $m($EXP_LABOUR));
    $say('');
    $say('   Project Stage Variance, scope ' . $SCOPE . ' INTERMENT ORDER');
    $say('   LMC Consume   ' . $m($before) . '  ->  ' . $m($EXP_SCOPE_AFTER));
    $say('   LMC Variance  ' . $m($EXP_BUDGET - $before) . '  ->  ' . $m($EXP_BUDGET - $EXP_SCOPE_AFTER));

    // ---------------- APPLY ----------------
    $glBefore = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                  FROM acct_gl WHERE ad_org_id = ?', [$ORG]);

    $db->beginTransaction();
    try {
        $n = $db->update('UPDATE wip_t_lmc_payout
                             SET amt_total_payout = ?, amt_total_payout_net = ?,
                                 updated = ?, date_updated = ?
                           WHERE wip_t_lmc_payout_id = ?',
                         [$EXP_LABOUR, $EXP_LABOUR, $TAG, $STAMP, $PAYOUT]);
        if ($n !== 1)
            throw new \RuntimeException("UPDATE touched $n rows, expected 1. ABORT.");

        // ---------------- POST-CHECKS ----------------
        $a = $db->selectOne('SELECT amt_total_payout, amt_total_payout_net,
                                    amt_total_acctpair_credit_payout ap_gross,
                                    amt_total_acctpair_credit_payout_net ap_net, updated
                               FROM wip_t_lmc_payout WHERE wip_t_lmc_payout_id = ?', [$PAYOUT]);
        if (abs((float) $a->amt_total_payout) > 0.001 || abs((float) $a->amt_total_payout_net) > 0.001)
            throw new \RuntimeException('POST-CHECK FAILED: labour totals are ' . $m($a->amt_total_payout)
                . ' / ' . $m($a->amt_total_payout_net) . ', expected 0.00. ABORT.');
        if (abs((float) $a->ap_net - $ACCTPAIR) > 0.001 || abs((float) $a->ap_gross - $ACCTPAIR) > 0.001)
            throw new \RuntimeException('POST-CHECK FAILED: account-pair columns moved. ABORT.');
        if ($a->updated !== $TAG)
            throw new \RuntimeException("POST-CHECK FAILED: updated='{$a->updated}'. ABORT.");

        if (abs($labour($PAYOUT) - $EXP_LABOUR) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: payout lines moved. ABORT.');
        $apAfter = (float) $db->selectOne(
            'SELECT ROUND(IFNULL(SUM(IFNULL(amt_acctpair_credit_payout,0)
                                    - IFNULL(l_amt_acctpair_credit_payout_ret,0)),0),2) v
               FROM wip_t_lmc_payoutline_acctpair_credit WHERE wip_t_payout_id = ?', [$PAYOUT])->v;
        if (abs($apAfter - $ACCTPAIR) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: account-pair lines moved. ABORT.');

        $glAfter = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                     FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
        if ((int) $glAfter->n !== (int) $glBefore->n
            || abs((float) $glAfter->v - (float) $glBefore->v) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl moved. It must never be written. ABORT.');

        $after = $reported($SCOPE);
        if (abs($after - $EXP_SCOPE_AFTER) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: scope ' . $SCOPE . ' reports ' . $m($after)
                . ', expected ' . $m($EXP_SCOPE_AFTER) . '. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK  header 0.00 / 0.00 · account-pair ' . $m($apAfter) . ' unchanged'
             . ' · payout lines unchanged · acct_gl unchanged');
        $say('             LMC Consume   ' . $m($before) . '  ->  ' . $m($after));
        $say('             LMC Variance  ' . $m($EXP_BUDGET - $before) . '  ->  '
             . $m($EXP_BUDGET - $after));
        $say(' COMPLETE — reprint Project Stage Variance for NLIO00927');
        $say($L);
        if (isset($cmd)) $cmd->info('NLMC0008378 fixed: scope reports ' . $m($after));
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
