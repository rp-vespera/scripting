<?php // scripts/pending/WIP38118_162011_06_25_2026_rollback.php
// Rollback for PROJ12520 fix â€” re-inserts the 8 deleted rows, reverts the ml_bal UPDATE,
// and re-links the 2 consumption rows back from closure 25939 to 26117.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $PROJ=12520; $ORG=162011; $WIP_ACCT=12502; $AMT=11241.81; $TOL=0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12520 â€” ROLLBACK (restore WPCL-ACPR0959)");
    $say($line);

    $exists = (int)$db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = 26117")->c;
    if ($exists === 1) { $say(""); $say(" NO-OP â€” closure 26117 still present."); $say($line); return; }

    $RESTORE = [
        ['acct_doc', ['acct_doc_id'=>103806870, 'explanation'=>'RP AREA 6 SIDEWALK 141.12LN.M. BOQ 3', 'date_created'=>'2025-12-12 14:33:25', 'is_active'=>1]],
        ['wip_t_project_closure', ['wip_t_project_closure_id'=>26117, 'documentno'=>'WPCL-ACPR0959', 'docstatus'=>'PR', 'amt_closure'=>11241.81, 'date_closure'=>'2025-12-12', 'date_gl'=>'2025-12-12', 'ad_org_id'=>162011, 'wip_i_project_id'=>12520, 'doc_i_submod_id'=>293, 'acct_doc_id'=>103806870, 'doc_t_reference_number_id'=>2765149, 'date_created'=>'2025-11-28 11:39:47', 'date_updated'=>'2025-12-12 14:33:25']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>49219, 'wip_t_project_closure_id'=>26117, 's_bpartner_id'=>19593, 'role'=>'MKR', 'bpar_i_person_id'=>23078, 'date_created'=>'2025-12-12 14:33:20']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>49224, 'wip_t_project_closure_id'=>26117, 's_bpartner_id'=>1716, 'role'=>'CKR', 'bpar_i_person_id'=>1355, 'date_created'=>'2025-12-12 14:33:25']],
        ['wip_t_project_closure_acctpair', ['wip_t_project_closure_acctpair_id'=>21026, 'wip_t_project_closure_id'=>26117, 'gl_acct_id'=>11310, 'gl_subacct_id'=>25766, 'date_created'=>'2025-11-28 11:39:47', 'is_active'=>1]],
        ['acct_gl', ['acct_gl_id'=>2301432, 'ad_org_id'=>162011, 'gl_acct_id'=>11310, 'documentno'=>'WPCL-ACPR0959', 'date_gl'=>'2025-12-12', 'debit'=>11241.81, 'credit'=>0.00, 'gl_subacct_id'=>25766, 'acct_doc_id'=>103806870, 'doc_i_submod_id'=>293]],
        ['acct_gl', ['acct_gl_id'=>2301433, 'ad_org_id'=>162011, 'gl_acct_id'=>12502, 'documentno'=>'WPCL-ACPR0959', 'date_gl'=>'2025-12-12', 'debit'=>0.00, 'credit'=>11241.81, 'gl_subacct_id'=>38118, 'acct_doc_id'=>103806870, 'doc_i_submod_id'=>293]],
        ['acct_balance', ['acct_balance_id'=>846986, 'gl_acct_id'=>12502, 'gl_subacct_id'=>38118, 'ad_org_id'=>162011, 'date_gl'=>'2025-12-12', 'debit'=>0.00, 'credit'=>11241.81, 'doc_i_submod_id'=>293]],
    ];

    $say(""); $say(" RESTORE (transaction):");
    $db->beginTransaction();
    try {
        foreach ($RESTORE as [$tbl, $data]) {
            $cols = array_keys($data);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $db->insert("INSERT INTO $tbl (" . implode(',', $cols) . ") VALUES ($ph)", array_values($data));
            $say("   INSERT $tbl id=" . ($data[$cols[0]] ?? '?'));
        }
        $a = $db->update("UPDATE acct_balance SET debit = debit + ? WHERE acct_balance_id = ?", [$AMT, 846960]);
        $say("   ml_bal 846960 debit += " . $money($AMT) . ": $a");
        if ($a !== 1) throw new \RuntimeException("ml_bal restore");

        // Re-link consumption back to closure 26117
        $a = $db->update("UPDATE wip_t_project_consumption SET wip_t_project_closure_id = 26117 WHERE wip_t_project_closure_id = 25939 AND wip_t_project_consumption_id IN (63129, 63132)");
        $say("   re-link consumption (25939 â†’ 26117): $a");
        if ($a !== 2) throw new \RuntimeException("consumption relink restore");

        $wipBal = (float)$db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected -11,241.81)");
        if (abs($wipBal + $AMT) > $TOL) throw new \RuntimeException("WIP net $wipBal");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate restored, variance back to -11,241.81.");
    $say($line);
};

