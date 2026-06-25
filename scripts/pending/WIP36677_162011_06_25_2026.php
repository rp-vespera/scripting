<?php // scripts/pending/WIP36677_162011_06_25_2026.php
// Project 11703 (RP BATCHING PLANT DETACHABLE) org 162011 â€” remove duplicate closure
// WPCL-AST0073 (2025-10-27, â‚±31,615.71). The legitimate WPCL-AST0071 (2025-10-14)
// stays untouched. Same operator (MKR bp 23078 / CKR bp 1355) drafted both.
//
// Cascade per duplicate (FK-safe order):
//   1. wip_t_project_closure_signee   (2 rows MKR+CKR)         DELETE
//   2. wip_t_project_closure_asset    (1 row â€” asset linkage)  DELETE
//   3. acct_balance                   (2 rows â€” both exclusive) DELETE
//   4. acct_gl                        (2 rows DR Asset / CR WIP) DELETE
//   5. wip_t_project_closure          (1 row)                  DELETE
//   6. acct_doc                       (1 row)                  DELETE
//
// Total: 9 DELETEs in a single transaction.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ = 11703;
    $ORG  = 162011;
    $WIP_ACCT = 12502;
    $AMT = 31615.71;
    $TOL = 0.01;

    $D = [
        'docno'        => 'WPCL-AST0073',
        'closure_id'   => 25735,
        'acct_doc_id'  => 103793325,
        'signee_ids'   => [48199, 48206],
        'acct_gl_ids'  => [2260263, 2260264],
        'asset_bal_id' => 832979,
        'wip_bal_id'   => 832988,
    ];

    $line = str_repeat('=', 90);
    $say  = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11703 (RP BATCHING PLANT DETACHABLE) â€” delete duplicate WPCL-AST0073");
    $say($line);

    // Idempotency
    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}")->c;
    if ($exists === 0) {
        $say(""); $say(" NO-OP â€” duplicate already removed."); $say($line);
        return;
    }

    // Pre-check
    $say(""); $say(" PRE-CHECK:");
    foreach ([
        ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
        ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")", 2],
        ['cl_asset', "SELECT COUNT(*) c FROM wip_t_project_closure_asset WHERE wip_t_project_closure_id = {$D['closure_id']}", 1],
        ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")", 2],
        ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}", 1],
        ['asset_bal',"SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$D['asset_bal_id']} AND debit = $AMT", 1],
        ['wip_bal',  "SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$D['wip_bal_id']} AND credit = $AMT", 1],
    ] as [$lbl, $q, $expect]) {
        $got = (int) $db->selectOne($q)->c;
        if ($got !== $expect) throw new \RuntimeException("$lbl: got=$got expected=$expect");
        $say("   $lbl: $got âœ“");
    }

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
         JOIN gl_subacct sub USING (gl_subacct_id)
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE = " . $money($wipNet));
    if (abs($wipNet + $AMT) > $TOL) throw new \RuntimeException("WIP net is $wipNet");

    // Apply
    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")");
        $say("   signees deleted: $a");
        if ($a !== 2) throw new \RuntimeException("signees affected $a");

        $a = $db->delete("DELETE FROM wip_t_project_closure_asset WHERE wip_t_project_closure_id = {$D['closure_id']}");
        $say("   closure_asset deleted: $a");
        if ($a !== 1) throw new \RuntimeException("closure_asset affected $a");

        $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id IN ({$D['asset_bal_id']}, {$D['wip_bal_id']})");
        $say("   acct_balance deleted: $a");
        if ($a !== 2) throw new \RuntimeException("acct_balance affected $a");

        $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")");
        $say("   acct_gl deleted: $a");
        if ($a !== 2) throw new \RuntimeException("acct_gl affected $a");

        $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}");
        $say("   closure deleted: $a");
        if ($a !== 1) throw new \RuntimeException("closure affected $a");

        $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}");
        $say("   acct_doc deleted: $a");
        if ($a !== 1) throw new \RuntimeException("acct_doc affected $a");

        $wipBal = (float) $db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $wipGl  = (float) $db->selectOne("SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say("");
        $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance WIP net = " . $money($wipBal));
        $say("   acct_gl      WIP net = " . $money($wipGl));
        if (abs($wipBal) > $TOL) throw new \RuntimeException("balance net $wipBal");
        if (abs($wipGl)  > $TOL) throw new \RuntimeException("gl net $wipGl");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate closure removed, variance closed.");
    $say($line);
};

