<?php // scripts/pending/WIP37572_162011_06_25_2026_rollback.php
// Rollback for PROJ12297 fix â€” re-inserts 16 deleted rows + reverts ml_bal UPDATE.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $PROJ=12297; $ORG=162011; $WIP_ACCT=12502; $AMT=64505.12; $TOL=0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12297 â€” ROLLBACK (restore 2 duplicates)");
    $say($line);

    $exists = (int)$db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25176, 26862)")->c;
    if ($exists === 2) { $say(""); $say(" NO-OP â€” closures still present."); $say($line); return; }

    $RESTORE = [
        // ---- WPCL-ACPR0975 (Feb 2) ----
        ['acct_doc', ['acct_doc_id'=>103820223, 'explanation'=>'RP AREA 5&6 ROAD 160LM BOQ 4', 'date_created'=>'2026-02-02 13:35:10', 'is_active'=>1]],
        ['wip_t_project_closure', ['wip_t_project_closure_id'=>25176, 'documentno'=>'WPCL-ACPR0975', 'docstatus'=>'PR', 'amt_closure'=>64505.12, 'date_closure'=>'2026-02-02', 'date_gl'=>'2026-02-02', 'ad_org_id'=>162011, 'wip_i_project_id'=>12297, 'doc_i_submod_id'=>293, 'acct_doc_id'=>103820223, 'date_created'=>'2025-09-22 09:49:54', 'date_updated'=>'2026-02-02 13:35:10']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>47259, 'wip_t_project_closure_id'=>25176, 's_bpartner_id'=>19593, 'role'=>'MKR', 'bpar_i_person_id'=>23078, 'date_created'=>'2026-02-02 13:35:00']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>50139, 'wip_t_project_closure_id'=>25176, 's_bpartner_id'=>1716, 'role'=>'CKR', 'bpar_i_person_id'=>1355, 'date_created'=>'2026-02-02 13:35:10']],
        ['wip_t_project_closure_acctpair', ['wip_t_project_closure_acctpair_id'=>20176, 'wip_t_project_closure_id'=>25176, 'gl_acct_id'=>11310, 'gl_subacct_id'=>25766, 'date_created'=>'2025-09-22 09:49:54', 'is_active'=>1]],
        ['acct_gl', ['acct_gl_id'=>2343205, 'ad_org_id'=>162011, 'gl_acct_id'=>11310, 'documentno'=>'WPCL-ACPR0975', 'date_gl'=>'2026-02-02', 'debit'=>64505.12, 'credit'=>0.00, 'gl_subacct_id'=>25766, 'acct_doc_id'=>103820223, 'doc_i_submod_id'=>293]],
        ['acct_gl', ['acct_gl_id'=>2343206, 'ad_org_id'=>162011, 'gl_acct_id'=>12502, 'documentno'=>'WPCL-ACPR0975', 'date_gl'=>'2026-02-02', 'debit'=>0.00, 'credit'=>64505.12, 'gl_subacct_id'=>37572, 'acct_doc_id'=>103820223, 'doc_i_submod_id'=>293]],
        ['acct_balance', ['acct_balance_id'=>861945, 'gl_acct_id'=>11310, 'gl_subacct_id'=>25766, 'ad_org_id'=>162011, 'date_gl'=>'2026-02-02', 'debit'=>64505.12, 'credit'=>0.00, 'doc_i_submod_id'=>293]],
        ['acct_balance', ['acct_balance_id'=>861959, 'gl_acct_id'=>12502, 'gl_subacct_id'=>37572, 'ad_org_id'=>162011, 'date_gl'=>'2026-02-02', 'debit'=>0.00, 'credit'=>64505.12, 'doc_i_submod_id'=>293]],

        // ---- WPCL-ACPR0980 (Mar 9) ----
        ['acct_doc', ['acct_doc_id'=>103828749, 'explanation'=>'RP AREA 5&6 ROAD 160LM BOQ 4', 'date_created'=>'2026-03-09 14:20:28', 'is_active'=>1]],
        ['wip_t_project_closure', ['wip_t_project_closure_id'=>26862, 'documentno'=>'WPCL-ACPR0980', 'docstatus'=>'PR', 'amt_closure'=>64505.12, 'date_closure'=>'2026-03-09', 'date_gl'=>'2026-03-09', 'ad_org_id'=>162011, 'wip_i_project_id'=>12297, 'doc_i_submod_id'=>293, 'acct_doc_id'=>103828749, 'date_created'=>'2026-02-21 10:20:52', 'date_updated'=>'2026-03-09 14:20:28']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>50354, 'wip_t_project_closure_id'=>26862, 's_bpartner_id'=>19593, 'role'=>'MKR', 'bpar_i_person_id'=>23078, 'date_created'=>'2026-03-09 14:20:20']],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>50634, 'wip_t_project_closure_id'=>26862, 's_bpartner_id'=>1716, 'role'=>'CKR', 'bpar_i_person_id'=>1355, 'date_created'=>'2026-03-09 14:20:28']],
        ['wip_t_project_closure_acctpair', ['wip_t_project_closure_acctpair_id'=>21673, 'wip_t_project_closure_id'=>26862, 'gl_acct_id'=>11310, 'gl_subacct_id'=>25766, 'date_created'=>'2026-02-21 10:20:52', 'is_active'=>1]],
        ['acct_gl', ['acct_gl_id'=>2367822, 'ad_org_id'=>162011, 'gl_acct_id'=>11310, 'documentno'=>'WPCL-ACPR0980', 'date_gl'=>'2026-03-09', 'debit'=>64505.12, 'credit'=>0.00, 'gl_subacct_id'=>25766, 'acct_doc_id'=>103828749, 'doc_i_submod_id'=>293]],
        ['acct_gl', ['acct_gl_id'=>2367823, 'ad_org_id'=>162011, 'gl_acct_id'=>12502, 'documentno'=>'WPCL-ACPR0980', 'date_gl'=>'2026-03-09', 'debit'=>0.00, 'credit'=>64505.12, 'gl_subacct_id'=>37572, 'acct_doc_id'=>103828749, 'doc_i_submod_id'=>293]],
        ['acct_balance', ['acct_balance_id'=>871425, 'gl_acct_id'=>12502, 'gl_subacct_id'=>37572, 'ad_org_id'=>162011, 'date_gl'=>'2026-03-09', 'debit'=>0.00, 'credit'=>64505.12, 'doc_i_submod_id'=>293]],
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
        $a = $db->update("UPDATE acct_balance SET debit = debit + ? WHERE acct_balance_id = ?", [$AMT, 871414]);
        $say("   ml_bal 871414 debit += " . $money($AMT) . ": $a");
        if ($a !== 1) throw new \RuntimeException("ml_bal restore");

        $wipBal = (float)$db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected -129,010.24)");
        if (abs($wipBal + 129010.24) > $TOL) throw new \RuntimeException("WIP net $wipBal");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” 2 duplicates restored, variance back to -129,010.24.");
    $say($line);
};

