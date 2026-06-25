<?php // scripts/pending/WIP37263_162011_06_25_2026.php
// Project 12109 (RP AREA 5 PERIMETER FENCE 2.74LM 1ST BATCH-25 SPANS) org 162011
// Remove duplicate closure WPCL-ACPR0966 (2025-12-29, â‚±83,228.82). Legitimate
// WPCL-ACPR0951 (2025-12-02) stays untouched. Same operator (MKR bp 23078 / CKR bp 1355).
//
// Cascade per duplicate (FK-safe order, ACPR/MemLot doctype):
//   1. wip_t_project_closure_signee   (2 rows MKR+CKR)         DELETE
//   2. wip_t_project_closure_acctpair (1 row)                  DELETE
//   3. acct_balance (MemLot, shared)  UPDATE debit -= 83,228.82
//   4. acct_balance (WIP, exclusive)  DELETE
//   5. acct_gl                        (2 rows)                  DELETE
//   6. wip_t_project_closure          (1 row)                   DELETE
//   7. acct_doc                       (1 row)                   DELETE

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ=12109; $ORG=162011; $WIP_ACCT=12502;
    $AMT = 83228.82; $TOL = 0.01;

    $D = [
        'docno'        => 'WPCL-ACPR0966',
        'closure_id'   => 25654,
        'acct_doc_id'  => 103811416,
        'signee_ids'   => [49574, 49576],
        'acctpair_id'  => 20603,
        'acct_gl_ids'  => [2314379, 2314380],
        'ml_bal_id'    => 851515,
        'wip_bal_id'   => 851534,
    ];

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12109 (PERIMETER FENCE) â€” delete duplicate WPCL-ACPR0966");
    $say($line);

    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}")->c;
    if ($exists === 0) { $say(""); $say(" NO-OP â€” duplicate already removed."); $say($line); return; }

    $say(""); $say(" PRE-CHECK:");
    foreach ([
        ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
        ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")", 2],
        ['acctpair', "SELECT COUNT(*) c FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$D['acctpair_id']}", 1],
        ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")", 2],
        ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}", 1],
        ['wip_bal',  "SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$D['wip_bal_id']} AND credit = $AMT", 1],
    ] as [$lbl, $q, $expect]) {
        $got = (int) $db->selectOne($q)->c;
        if ($got !== $expect) throw new \RuntimeException("$lbl: got=$got expected=$expect");
        $say("   $lbl: $got âœ“");
    }
    $ml = $db->selectOne("SELECT debit FROM acct_balance WHERE acct_balance_id = {$D['ml_bal_id']}");
    if (!$ml || (float)$ml->debit < $AMT - $TOL) throw new \RuntimeException("ml_bal insufficient");
    $say("   ml_bal: " . $money((float)$ml->debit) . " âœ“");

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE: " . $money($wipNet));
    if (abs($wipNet + $AMT) > $TOL) throw new \RuntimeException("WIP net $wipNet");

    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")");
        $say("   signees deleted: $a"); if ($a !== 2) throw new \RuntimeException('signees');
        $a = $db->delete("DELETE FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$D['acctpair_id']}");
        $say("   acctpair deleted: $a"); if ($a !== 1) throw new \RuntimeException('acctpair');
        $a = $db->update("UPDATE acct_balance SET debit = debit - ? WHERE acct_balance_id = ?", [$AMT, $D['ml_bal_id']]);
        $say("   ml_bal updated: $a"); if ($a !== 1) throw new \RuntimeException('ml_bal');
        $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id = {$D['wip_bal_id']}");
        $say("   wip_bal deleted: $a"); if ($a !== 1) throw new \RuntimeException('wip_bal');
        $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")");
        $say("   acct_gl deleted: $a"); if ($a !== 2) throw new \RuntimeException('acct_gl');
        $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}");
        $say("   closure deleted: $a"); if ($a !== 1) throw new \RuntimeException('closure');
        $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}");
        $say("   acct_doc deleted: $a"); if ($a !== 1) throw new \RuntimeException('acct_doc');

        $wipBal = (float) $db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $wipGl = (float) $db->selectOne("SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance = " . $money($wipBal));
        $say("   acct_gl      = " . $money($wipGl));
        if (abs($wipBal) > $TOL || abs($wipGl) > $TOL) throw new \RuntimeException("post-check fail");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate removed, variance closed.");
    $say($line);
};

