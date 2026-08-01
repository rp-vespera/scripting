<?php // NLIO00929_162012_08_01_2026.php
// ============================================================================
// LMC payout header double-books the account-pair credit — NLIO00929
// Org 162012 · mysql_secondary · 2026-08-01
//
//   NLMC0008379 is a pure account-pair credit payout: no labour payout lines,
//   one account-pair line of 4,804.97. Its header carries that same 4,804.97 in
//   amt_total_payout and amt_total_payout_net, which by convention hold the
//   labour total only.
//
//   Project Stage Variance reads the header as
//     amt_total_payout_net + amt_total_acctpair_credit_payout_net
//   so scope 25911 INTERMENT ORDER prints 12,609.94 instead of 7,804.97.
//
//   One of 127 headers written by 'WEB Accounting' on 2026-07-27 and 07-28.
//   The other three payouts on this project are Java-written and correct.
//
//   Sets the two labour totals to the sum of this document's own payout lines,
//   which is 0.00. The account-pair columns, every payout line, every balance
//   and the ledger are untouched.
//
// WRITES     wip_t_lmc_payout — 1 UPDATE, 2 columns
// ROLLBACK   NLIO00929_162012_08_01_2026_rollback.php
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $APPROVED = true;                       // <-- set true only with approval on record

    $POSTED   = '2026-08-01';
    $IMS      = null;
    $TAG      = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;
    $STAMP    = $POSTED . ' 00:00:00';

    $ORG      = 162012;
    $WRITER   = 'WEB Accounting';

    // payout id => [documentno, scope, account-pair amount, report before, report after]
    $PAYOUTS = [
        55812 => ['NLMC0008379', 25911, 4804.97, 12609.94, 7804.97],
    ];

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

    $apLines = fn(int $id) => (float) $db->selectOne(
        'SELECT ROUND(IFNULL(SUM(IFNULL(amt_acctpair_credit_payout,0)
                                - IFNULL(l_amt_acctpair_credit_payout_ret,0)),0),2) v
           FROM wip_t_lmc_payoutline_acctpair_credit WHERE wip_t_payout_id = ?', [$id])->v;

    $say($L);
    $say(' NLMC0008379 — LMC payout header double-books the account-pair credit');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · org ' . $ORG . ' · tag ' . $TAG
         . ' · MODE: ' . ($APPROVED ? 'APPLY' : 'DRY-RUN'));
    $say($L);

    // ---------------- GATE 0 · replica only ----------------
    if ($APPROVED && stripos($schema, 'replica') === false)
        throw new \RuntimeException("GATE 0 FAILED: '$schema' is not a replica. ABORT.");

    $plan = [];
    foreach ($PAYOUTS as $id => [$docno, $scope, $ap, $expBefore, $expAfter]) {

        // ---------------- GATE 1 · the header is exactly as audited ----------------
        $h = $db->selectOne('SELECT documentno, docstatus, ad_org_id, wip_i_project_scope_id sc,
                                    amt_total_payout, amt_total_payout_net,
                                    amt_total_payout_tax tax, amt_total_payout_wtax wtax,
                                    amt_total_acctpair_credit_payout ap_gross,
                                    amt_total_acctpair_credit_payout_net ap_net,
                                    COALESCE(created,\'\') created
                               FROM wip_t_lmc_payout WHERE wip_t_lmc_payout_id = ?', [$id]);
        if (!$h) throw new \RuntimeException("GATE 1 FAILED: payout $id not found. ABORT.");
        if ($h->documentno !== $docno || $h->docstatus !== 'PR' || (int) $h->ad_org_id !== $ORG
            || (int) $h->sc !== $scope)
            throw new \RuntimeException("GATE 1 FAILED: payout $id is {$h->documentno} / {$h->docstatus}"
                . " / org {$h->ad_org_id} / scope {$h->sc} — not the audited document. ABORT.");
        if (abs((float) $h->amt_total_payout - $ap) > 0.001
            || abs((float) $h->amt_total_payout_net - $ap) > 0.001)
            throw new \RuntimeException("GATE 1 FAILED: $docno header totals are "
                . $m($h->amt_total_payout) . ' / ' . $m($h->amt_total_payout_net)
                . ', expected ' . $m($ap) . ' on both. ABORT.');
        if (abs((float) $h->ap_net - $ap) > 0.001)
            throw new \RuntimeException("GATE 1 FAILED: $docno account-pair net is " . $m($h->ap_net)
                . ', expected ' . $m($ap) . '. ABORT.');
        if (abs((float) $h->tax) > 0.001 || abs((float) $h->wtax) > 0.001)
            throw new \RuntimeException("GATE 1 FAILED: $docno tax " . $m($h->tax) . ' / wtax '
                . $m($h->wtax) . ' are non-zero. ABORT.');
        if ($h->created !== $WRITER)
            throw new \RuntimeException("GATE 1 FAILED: $docno created='{$h->created}', expected '$WRITER'. "
                . 'Only the backend-written headers carry this defect. ABORT.');

        // ---------------- GATE 2 · no labour lines back the header total ----------------
        $lab = $labour($id);
        if (abs($lab) > 0.01)
            throw new \RuntimeException("GATE 2 FAILED: $docno has payout lines totalling " . $m($lab)
                . '. The header total may be legitimate. ABORT.');

        // ---------------- GATE 3 · the account-pair line is intact ----------------
        $apl = $apLines($id);
        if (abs($apl - $ap) > 0.01)
            throw new \RuntimeException("GATE 3 FAILED: $docno account-pair lines total " . $m($apl)
                . ', expected ' . $m($ap) . '. ABORT.');

        // ---------------- GATE 4 · the report reads what we audited ----------------
        $before = $reported($scope);
        if (abs($before - $expBefore) > 0.01)
            throw new \RuntimeException("GATE 4 FAILED: scope $scope reports " . $m($before)
                . ', expected ' . $m($expBefore) . '. ABORT.');

        $plan[$id] = [$docno, $scope, $ap, $lab, $before, $expAfter];
        $say('        payout ' . $id . ' · ' . $docno . ' · PR · scope ' . $scope . ' · ' . $WRITER);
        $say('          header ' . $m($h->amt_total_payout) . ' gross / ' . $m($h->amt_total_payout_net)
             . ' net  ·  own payout lines ' . $m($lab) . '  ·  account-pair ' . $m($apl) . ' (unchanged)');
    }

    $say(' GATES  0 replica · 1 header · 2 no labour lines · 3 account-pair intact · 4 report ..... PASS');
    $say('');
    $say('   PLAN — ' . count($plan) . ' UPDATE(s) on wip_t_lmc_payout');
    foreach ($plan as $id => [$docno, $scope, $ap, $lab, $before, $expAfter]) {
        $say('   ' . $docno . '  amt_total_payout & _net  ' . $m($ap) . '  ->  ' . $m($lab));
        $say('   scope ' . $scope . ' LMC Consume  ' . $m($before) . '  ->  ' . $m($expAfter));
    }

    // ---------------- APPROVAL GUARD ----------------
    if (!$APPROVED) {
        $say('');
        $say(' DRY-RUN — zero database writes.');
        $say(' APPROVAL REQUIRED before this runs.');
        $say($L);
        if (isset($cmd)) $cmd->info('NLMC0008379 DRY-RUN: ' . count($plan) . ' update(s) planned, no writes.');
        return;
    }

    // ---------------- APPLY ----------------
    $glBefore = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                  FROM acct_gl WHERE ad_org_id = ?', [$ORG]);

    $db->beginTransaction();
    try {
        foreach ($plan as $id => [$docno, $scope, $ap, $lab, $before, $expAfter]) {
            $n = $db->update('UPDATE wip_t_lmc_payout
                                 SET amt_total_payout = ?, amt_total_payout_net = ?,
                                     updated = ?, date_updated = ?
                               WHERE wip_t_lmc_payout_id = ?', [$lab, $lab, $TAG, $STAMP, $id]);
            if ($n !== 1)
                throw new \RuntimeException("UPDATE on $docno touched $n rows, expected 1. ABORT.");

            $a = $db->selectOne('SELECT amt_total_payout, amt_total_payout_net,
                                        amt_total_acctpair_credit_payout ap_gross,
                                        amt_total_acctpair_credit_payout_net ap_net, updated
                                   FROM wip_t_lmc_payout WHERE wip_t_lmc_payout_id = ?', [$id]);
            if (abs((float) $a->amt_total_payout - $lab) > 0.001
                || abs((float) $a->amt_total_payout_net - $lab) > 0.001)
                throw new \RuntimeException("POST-CHECK FAILED: $docno labour totals not set. ABORT.");
            if (abs((float) $a->ap_net - $ap) > 0.001 || abs((float) $a->ap_gross - $ap) > 0.001)
                throw new \RuntimeException("POST-CHECK FAILED: $docno account-pair columns moved. ABORT.");
            if ($a->updated !== $TAG)
                throw new \RuntimeException("POST-CHECK FAILED: $docno updated='{$a->updated}'. ABORT.");
            if (abs($labour($id) - $lab) > 0.01 || abs($apLines($id) - $ap) > 0.01)
                throw new \RuntimeException("POST-CHECK FAILED: $docno payout lines moved. ABORT.");

            $after = $reported($scope);
            if (abs($after - $expAfter) > 0.01)
                throw new \RuntimeException("POST-CHECK FAILED: scope $scope reports " . $m($after)
                    . ', expected ' . $m($expAfter) . '. ABORT.');
            $plan[$id][] = $after;
        }

        $glAfter = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                     FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
        if ((int) $glAfter->n !== (int) $glBefore->n
            || abs((float) $glAfter->v - (float) $glBefore->v) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl moved. It must never be written. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK  account-pair columns unchanged · payout lines unchanged · acct_gl unchanged');
        foreach ($plan as $id => $p)
            $say('             ' . $p[0] . '  scope ' . $p[1] . ' LMC Consume  '
                 . $m($p[4]) . '  ->  ' . $m($p[6]));
        $say(' COMPLETE — reprint Project Stage Variance for NLIO00929');
        $say($L);
        if (isset($cmd)) $cmd->info('NLMC0008379 fixed: ' . count($plan) . ' header(s).');
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
