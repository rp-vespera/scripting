<?php // scripts/pending/WIP38118_162011_06_25_2026.php
// Project 12520 (RP AREA 6 SIDEWALK 141.12LN.M. BOQ 3) org 162011
// Remove duplicate closure WPCL-ACPR0959 (2025-12-12, â‚±11,241.81).
//
// Context: this project has 3 closures + 1 return:
//   2025-11-28  WPCL-ACPR0948 (legit)   â€” CANCELLED by the return below
//   2025-12-08  WPCL-ACPR0956 (dup)     â€” will become effective legit closure
//   2025-12-11  WPCLRACCPR0171 (return) â€” wrongly returned the LEGITIMATE 0948
//   2025-12-12  WPCL-ACPR0959 (dup)     â€” TO BE DELETED
//
// Same MKR/CKR (bp 23078 / bp 1355) on the 3 forward closures. The return was posted by
// different signees (bp 25271 / bp 21800) â€” likely a senior who tried to clean up but
// targeted the wrong closure.
//
// Fix shape: delete WPCL-ACPR0959 and its cascade. Re-link the 2 consumption rows
// (WPC0003137, WPC0003138) that 0959 had claimed to WPCL-ACPR0956 (id 25939), the
// closure that will remain on the books as the effective legit closure for this project.
//
// Cascade (FK-safe order):
//   1. wip_t_project_consumption â€” re-link FK (26117 â†’ 25939) for the 2 PR rows
//   2. wip_t_project_closure_signee   (2 rows)        DELETE
//   3. wip_t_project_closure_acctpair (1 row)         DELETE
//   4. acct_balance MemLot (846960, SHARED with 0958) UPDATE -= 11,241.81
//   5. acct_balance WIP    (846986, exclusive)        DELETE
//   6. acct_gl             (2 rows)                   DELETE
//   7. wip_t_project_closure (1 row)                  DELETE
//   8. acct_doc            (1 row)                    DELETE

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ=12520; $ORG=162011; $WIP_ACCT=12502;
    $AMT = 11241.81; $TOL = 0.01;

    $D = [
        'docno'=>'WPCL-ACPR0959', 'closure_id'=>26117, 'acct_doc_id'=>103806870,
        'signee_ids'=>[49219, 49224], 'acctpair_id'=>21026,
        'acct_gl_ids'=>[2301432, 2301433],
        'ml_bal_id'=>846960,
        'wip_bal_id'=>846986,
        'relink_to_closure_id'=>25939,  // WPCL-ACPR0956 becomes the effective legit
    ];

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12520 (SIDEWALK BOQ3) â€” delete duplicate WPCL-ACPR0959");
    $say($line);

    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}")->c;
    if ($exists === 0) { $say(""); $say(" NO-OP â€” closure 26117 already removed."); $say($line); return; }

    // Verify 0956 (relink target) still exists
    $relinkOk = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['relink_to_closure_id']}")->c;
    if ($relinkOk !== 1) throw new \RuntimeException("Re-link target closure {$D['relink_to_closure_id']} (WPCL-ACPR0956) not found");

    $say(""); $say(" PRE-CHECK:");
    foreach ([
        ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
        ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")", 2],
        ['acctpair', "SELECT COUNT(*) c FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$D['acctpair_id']}", 1],
        ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")", 2],
        ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}", 1],
        ['wip_bal',  "SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$D['wip_bal_id']} AND credit = $AMT", 1],
        ['consumption', "SELECT COUNT(*) c FROM wip_t_project_consumption WHERE wip_t_project_closure_id = {$D['closure_id']}", 2],
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
        $a = $db->update("UPDATE wip_t_project_consumption SET wip_t_project_closure_id = ? WHERE wip_t_project_closure_id = ?", [$D['relink_to_closure_id'], $D['closure_id']]);
        $say("   relink consumption (26117 â†’ 25939): $a"); if ($a !== 2) throw new \RuntimeException('relink');

        $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$D['signee_ids']).")");
        $say("   signees: $a"); if ($a !== 2) throw new \RuntimeException('signees');
        $a = $db->delete("DELETE FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_acctpair_id = {$D['acctpair_id']}");
        $say("   acctpair: $a"); if ($a !== 1) throw new \RuntimeException('acctpair');
        $a = $db->update("UPDATE acct_balance SET debit = debit - ? WHERE acct_balance_id = ?", [$AMT, $D['ml_bal_id']]);
        $say("   ml_bal UPDATE -= " . $money($AMT) . ": $a"); if ($a !== 1) throw new \RuntimeException('ml_bal');
        $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id = {$D['wip_bal_id']}");
        $say("   wip_bal: $a"); if ($a !== 1) throw new \RuntimeException('wip_bal');
        $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$D['acct_gl_ids']).")");
        $say("   acct_gl: $a"); if ($a !== 2) throw new \RuntimeException('acct_gl');
        $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$D['closure_id']}");
        $say("   closure: $a"); if ($a !== 1) throw new \RuntimeException('closure');
        $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$D['acct_doc_id']}");
        $say("   acct_doc: $a"); if ($a !== 1) throw new \RuntimeException('acct_doc');

        $wipBal = (float)$db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $wipGl = (float)$db->selectOne("SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance = " . $money($wipBal));
        $say("   acct_gl      = " . $money($wipGl));
        if (abs($wipBal) > $TOL || abs($wipGl) > $TOL) throw new \RuntimeException("post-check fail");

        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate removed, consumption re-linked, variance closed.");
    $say($line);
};

