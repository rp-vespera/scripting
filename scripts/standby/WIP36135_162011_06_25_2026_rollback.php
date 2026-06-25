<?php // scripts/pending/WIP36135_162011_06_25_2026_rollback.php
// Rollback for WIP36135 (project 11455) — re-inserts 16 deleted rows from hardcoded
// pre-delete values captured 2026-06-25.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $PROJ=11455; $ORG=162011; $WIP_ACCT=12502; $AMT=40031.00; $TOL=0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11455 — ROLLBACK (restore 2 duplicates)");
    $say($line);

    $exists = (int)$db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25374, 25356)")->c;
    if ($exists === 2) { $say(""); $say(" NO-OP — closures still present."); $say($line); return; }

    $RESTORE = [
        // ---- WPCL-AST0080 (Feb 2) ----
        ['acct_doc', ['acct_doc_id'=>103820224, 'explanation'=>'RP INTERMENT BACKDROP', 'date_created'=>'2026-02-02 13:35:39', 'is_active'=>1]],
        ['wip_t_project_closure', ['wip_t_project_closure_id'=>25374, 'documentno'=>'WPCL-AST0080', 'docstatus'=>'PR', 'amt_closure'=>40031.00, 'date_closure'=>'2026-02-02', 'date_gl'=>'2026-02-02', 'ad_org_id'=>162011, 'wip_i_project_id'=>11455, 'doc_i_submod_id'=>292, 'acct_doc_id'=>103820224]],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>47587, 'wip_t_project_closure_id'=>25374, 's_bpartner_id'=>19593, 'role'=>'MKR', 'bpar_i_person_id'=>23078]],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>50140, 'wip_t_project_closure_id'=>25374, 's_bpartner_id'=>1716, 'role'=>'CKR', 'bpar_i_person_id'=>1355]],
        ['wip_t_project_closure_asset', ['wip_t_project_closure_asset_id'=>1656, 'wip_t_project_closure_id'=>25374, 'is_active'=>1]],
        ['acct_gl', ['acct_gl_id'=>2343207, 'ad_org_id'=>162011, 'gl_acct_id'=>12115, 'documentno'=>'WPCL-AST0080', 'date_gl'=>'2026-02-02', 'debit'=>40031.00, 'credit'=>0.00, 'gl_subacct_id'=>36112, 'acct_doc_id'=>103820224, 'doc_i_submod_id'=>292]],
        ['acct_gl', ['acct_gl_id'=>2343208, 'ad_org_id'=>162011, 'gl_acct_id'=>12502, 'documentno'=>'WPCL-AST0080', 'date_gl'=>'2026-02-02', 'debit'=>0.00, 'credit'=>40031.00, 'gl_subacct_id'=>36135, 'acct_doc_id'=>103820224, 'doc_i_submod_id'=>292]],
        ['acct_balance', ['acct_balance_id'=>861954, 'gl_acct_id'=>12115, 'gl_subacct_id'=>36112, 'ad_org_id'=>162011, 'date_gl'=>'2026-02-02', 'debit'=>40031.00, 'credit'=>0.00, 'doc_i_submod_id'=>292]],
        ['acct_balance', ['acct_balance_id'=>861958, 'gl_acct_id'=>12502, 'gl_subacct_id'=>36135, 'ad_org_id'=>162011, 'date_gl'=>'2026-02-02', 'debit'=>0.00, 'credit'=>40031.00, 'doc_i_submod_id'=>292]],

        // ---- WPCL-AST0081 (Mar 17) ----
        ['acct_doc', ['acct_doc_id'=>103830548, 'explanation'=>'RP INTERMENT BACKDROP', 'date_created'=>'2026-03-17 15:25:09', 'is_active'=>1]],
        ['wip_t_project_closure', ['wip_t_project_closure_id'=>25356, 'documentno'=>'WPCL-AST0081', 'docstatus'=>'PR', 'amt_closure'=>40031.00, 'date_closure'=>'2026-03-17', 'date_gl'=>'2026-03-17', 'ad_org_id'=>162011, 'wip_i_project_id'=>11455, 'doc_i_submod_id'=>292, 'acct_doc_id'=>103830548]],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>47560, 'wip_t_project_closure_id'=>25356, 's_bpartner_id'=>19593, 'role'=>'MKR', 'bpar_i_person_id'=>23078]],
        ['wip_t_project_closure_signee', ['wip_t_project_closure_signee_id'=>50712, 'wip_t_project_closure_id'=>25356, 's_bpartner_id'=>1716, 'role'=>'CKR', 'bpar_i_person_id'=>1355]],
        ['wip_t_project_closure_asset', ['wip_t_project_closure_asset_id'=>1649, 'wip_t_project_closure_id'=>25356, 'is_active'=>1]],
        ['acct_gl', ['acct_gl_id'=>2372728, 'ad_org_id'=>162011, 'gl_acct_id'=>12115, 'documentno'=>'WPCL-AST0081', 'date_gl'=>'2026-03-17', 'debit'=>40031.00, 'credit'=>0.00, 'gl_subacct_id'=>36112, 'acct_doc_id'=>103830548, 'doc_i_submod_id'=>292]],
        ['acct_gl', ['acct_gl_id'=>2372729, 'ad_org_id'=>162011, 'gl_acct_id'=>12502, 'documentno'=>'WPCL-AST0081', 'date_gl'=>'2026-03-17', 'debit'=>0.00, 'credit'=>40031.00, 'gl_subacct_id'=>36135, 'acct_doc_id'=>103830548, 'doc_i_submod_id'=>292]],
        ['acct_balance', ['acct_balance_id'=>873543, 'gl_acct_id'=>12115, 'gl_subacct_id'=>36112, 'ad_org_id'=>162011, 'date_gl'=>'2026-03-17', 'debit'=>40031.00, 'credit'=>0.00, 'doc_i_submod_id'=>292]],
        ['acct_balance', ['acct_balance_id'=>873545, 'gl_acct_id'=>12502, 'gl_subacct_id'=>36135, 'ad_org_id'=>162011, 'date_gl'=>'2026-03-17', 'debit'=>0.00, 'credit'=>40031.00, 'doc_i_submod_id'=>292]],
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
        $wipBal = (float)$db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected -80,062.00)");
        if (abs($wipBal + 80062.00) > $TOL) throw new \RuntimeException("WIP net $wipBal");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS — 2 duplicates restored, variance back to -80,062.00.");
    $say($line);
};
