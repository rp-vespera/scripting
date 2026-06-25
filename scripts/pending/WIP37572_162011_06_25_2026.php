<?php // scripts/pending/WIP37572_162011_06_25_2026.php
// Project 12297 (RP AREA 5&6 ROAD 160LM BOQ 4) org 162011 â€” remove 2 duplicate closures
// WPCL-ACPR0975 (2026-02-02) and WPCL-ACPR0980 (2026-03-09), each â‚±64,505.12.
// Legitimate WPCL-ACPR0972 (2026-01-17) stays untouched.
// Same operator (MKR bp 23078 / CKR bp 1355) drafted all three.
//
// Cascade per duplicate (FK-safe order, ACPR/MemLot doctype):
//   1. wip_t_project_closure_signee   (2 rows)              DELETE
//   2. wip_t_project_closure_acctpair (1 row)               DELETE
//   3. acct_balance MemLot:
//        â€” WPCL-ACPR0975: ml_bal 861945 is EXCLUSIVE after 11229 fix â†’ DELETE
//        â€” WPCL-ACPR0980: ml_bal 871414 SHARED with 0976/0977/0978 â†’ UPDATE (-= 64,505.12)
//   4. acct_balance WIP                                     DELETE
//   5. acct_gl                       (2 rows)              DELETE
//   6. wip_t_project_closure         (1 row)               DELETE
//   7. acct_doc                      (1 row)               DELETE

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ=12297; $ORG=162011; $WIP_ACCT=12502;
    $AMT = 64505.12; $TOL = 0.01;

    $DUPS = [
        [
            'docno'=>'WPCL-ACPR0975', 'closure_id'=>25176, 'acct_doc_id'=>103820223,
            'signee_ids'=>[47259,50139], 'acctpair_id'=>20176,
            'acct_gl_ids'=>[2343205,2343206],
            'ml_bal_id'=>861945, 'ml_action'=>'DELETE',
            'wip_bal_id'=>861959,
        ],
        [
            'docno'=>'WPCL-ACPR0980', 'closure_id'=>26862, 'acct_doc_id'=>103828749,
            'signee_ids'=>[50354,50634], 'acctpair_id'=>21673,
            'acct_gl_ids'=>[2367822,2367823],
            'ml_bal_id'=>871414, 'ml_action'=>'UPDATE',
            'wip_bal_id'=>871425,
        ],
    ];

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12297 (RD 160LM BOQ4) â€” delete 2 duplicates (WPCL-ACPR0975 + WPCL-ACPR0980)");
    $say($line);

    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25176, 26862)")->c;
    if ($exists === 0) { $say(""); $say(" NO-OP â€” both duplicates already removed."); $say($line); return; }
    if ($exists !== 2) throw new \RuntimeException("Partial state: expected 0 or 2, found $exists");

    $say(""); $say(" PRE-CHECK:");
    foreach ($DUPS as $i => $d) {
        $say("  #".($i+1)." {$d['docno']} ({$d['ml_action']} ml_bal):");
        foreach ([
            ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
            ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")", 2],
            ['acctpair', "SELECT COUNT(*) c FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$d['acctpair_id']}", 1],
            ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")", 2],
            ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}", 1],
            ['wip_bal',  "SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']} AND credit = $AMT", 1],
        ] as [$lbl, $q, $expect]) {
            $got = (int) $db->selectOne($q)->c;
            if ($got !== $expect) throw new \RuntimeException("$lbl {$d['docno']}: got=$got expected=$expect");
            $say("    $lbl: $got âœ“");
        }
        $ml = $db->selectOne("SELECT debit FROM acct_balance WHERE acct_balance_id = {$d['ml_bal_id']}");
        if (!$ml || (float)$ml->debit < $AMT - $TOL) throw new \RuntimeException("ml_bal insufficient for {$d['docno']}");
        $say("    ml_bal: " . $money((float)$ml->debit) . " âœ“");
    }

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE: " . $money($wipNet));
    if (abs($wipNet + 129010.24) > $TOL) throw new \RuntimeException("WIP net is $wipNet");

    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        foreach ($DUPS as $d) {
            $say("  -- {$d['docno']} --");
            $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")");
            $say("    signees deleted: $a"); if ($a !== 2) throw new \RuntimeException('signees');
            $a = $db->delete("DELETE FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$d['acctpair_id']}");
            $say("    acctpair deleted: $a"); if ($a !== 1) throw new \RuntimeException('acctpair');

            if ($d['ml_action'] === 'DELETE') {
                $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id = {$d['ml_bal_id']}");
                $say("    ml_bal DELETED: $a"); if ($a !== 1) throw new \RuntimeException('ml delete');
            } else {
                $a = $db->update("UPDATE acct_balance SET debit = debit - ? WHERE acct_balance_id = ?", [$AMT, $d['ml_bal_id']]);
                $say("    ml_bal UPDATED -= " . $money($AMT) . ": $a"); if ($a !== 1) throw new \RuntimeException('ml update');
            }

            $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']}");
            $say("    wip_bal deleted: $a"); if ($a !== 1) throw new \RuntimeException('wip_bal');
            $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")");
            $say("    acct_gl deleted: $a"); if ($a !== 2) throw new \RuntimeException('acct_gl');
            $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']}");
            $say("    closure deleted: $a"); if ($a !== 1) throw new \RuntimeException('closure');
            $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}");
            $say("    acct_doc deleted: $a"); if ($a !== 1) throw new \RuntimeException('acct_doc');
        }

        $wipBal = (float)$db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $wipGl = (float)$db->selectOne("SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance = " . $money($wipBal));
        $say("   acct_gl      = " . $money($wipGl));
        if (abs($wipBal) > $TOL || abs($wipGl) > $TOL) throw new \RuntimeException("post-check fail");

        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” 2 duplicates removed, variance closed.");
    $say($line);
};

