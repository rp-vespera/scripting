<?php // scripts/pending/WIP36677_162011_06_25_2026_rollback.php
// Rollback for PROJ11703 fix â€” re-inserts deleted rows from hardcoded pre-delete values
// captured 2026-06-25 via forensic probe. Restores the original +31,615.71 variance.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ = 11703; $ORG = 162011; $WIP_ACCT = 12502; $AMT = 31615.71; $TOL = 0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11703 â€” ROLLBACK (restore duplicate WPCL-AST0073)");
    $say($line);

    // NO-OP guard
    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = 25735")->c;
    if ($exists === 1) {
        $say(""); $say(" NO-OP â€” closure 25735 still present (apply never ran)."); $say($line);
        return;
    }

    $RESTORE = [
        ['table' => 'acct_doc', 'data' => [
            'acct_doc_id'  => 103793325,
            'explanation'  => 'RP BATCHING PLANT DETACHABLE',
            'date_created' => '2025-10-27 11:15:54',
            'is_active'    => 1,
        ]],
        ['table' => 'wip_t_project_closure', 'data' => [
            'wip_t_project_closure_id' => 25735,
            'documentno'        => 'WPCL-AST0073',
            'docstatus'         => 'PR',
            'amt_closure'       => 31615.71,
            'date_closure'      => '2025-10-27',
            'date_gl'           => '2025-10-27',
            'ad_org_id'         => 162011,
            'wip_i_project_id'  => 11703,
            'doc_i_submod_id'   => 292,
            'acct_doc_id'       => 103793325,
            'doc_t_reference_number_id' => 2740871,
            'date_created'      => '2025-10-27 10:47:29',
            'date_updated'      => '2025-10-27 11:15:54',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 48199,
            'wip_t_project_closure_id' => 25735,
            's_bpartner_id'      => 19593,
            'usercode'           => 1719985786088,
            'role'               => 'MKR',
            'bpar_i_person_id'   => 23078,
            'date_created'       => '2025-10-27 10:35:45',
            'date_updated'       => '2025-10-27 10:35:45',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 48206,
            'wip_t_project_closure_id' => 25735,
            's_bpartner_id'      => 1716,
            'usercode'           => 1450330627149,
            'role'               => 'CKR',
            'bpar_i_person_id'   => 1355,
            'date_created'       => '2025-10-27 11:15:55',
            'date_updated'       => '2025-10-27 11:15:55',
        ]],
        ['table' => 'wip_t_project_closure_asset', 'data' => [
            'wip_t_project_closure_asset_id' => 1683,
            'wip_t_project_closure_id' => 25735,
            'a_asset_id'         => 2868,
            'ast_i_asset_id'     => 3191,
            'ast_l_asset_history_id' => 12338,
            'date_created'       => '2025-10-27 10:47:29',
            'is_active'          => 1,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2260263,
            'ad_org_id'     => 162011,
            'gl_acct_id'    => 12105,
            'documentno'    => 'WPCL-AST0073',
            'date_gl'       => '2025-10-27',
            'date_trans'    => '2025-10-27 11:28:07',
            'debit'         => 31615.71,
            'credit'        => 0.00,
            'gl_subacct_id' => 36676,
            'acct_doc_id'   => 103793325,
            'doc_i_submod_id' => 292,
            'doc_t_reference_number_id' => 2740871,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2260264,
            'ad_org_id'     => 162011,
            'gl_acct_id'    => 12502,
            'documentno'    => 'WPCL-AST0073',
            'date_gl'       => '2025-10-27',
            'date_trans'    => '2025-10-27 11:28:07',
            'debit'         => 0.00,
            'credit'        => 31615.71,
            'gl_subacct_id' => 36677,
            'acct_doc_id'   => 103793325,
            'doc_i_submod_id' => 292,
            'doc_t_reference_number_id' => 2740871,
        ]],
        ['table' => 'acct_balance', 'data' => [
            'acct_balance_id' => 832979,
            'gl_acct_id'      => 12105,
            'gl_subacct_id'   => 36676,
            'ad_org_id'       => 162011,
            'date_gl'         => '2025-10-27',
            'debit'           => 31615.71,
            'credit'          => 0.00,
            'doc_i_submod_id' => 292,
        ]],
        ['table' => 'acct_balance', 'data' => [
            'acct_balance_id' => 832988,
            'gl_acct_id'      => 12502,
            'gl_subacct_id'   => 36677,
            'ad_org_id'       => 162011,
            'date_gl'         => '2025-10-27',
            'debit'           => 0.00,
            'credit'          => 31615.71,
            'doc_i_submod_id' => 292,
        ]],
    ];

    $say(""); $say(" RESTORE (transaction):");
    $db->beginTransaction();
    try {
        foreach ($RESTORE as $r) {
            $cols = array_keys($r['data']);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $db->insert("INSERT INTO {$r['table']} (" . implode(',', $cols) . ") VALUES ($placeholders)", array_values($r['data']));
            $say("   INSERT {$r['table']} id=" . ($r['data'][$cols[0]] ?? '?'));
        }

        $wipBal = (float) $db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say("");
        $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected -31,615.71)");
        if (abs($wipBal + $AMT) > $TOL) throw new \RuntimeException("WIP net $wipBal");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate WPCL-AST0073 restored. Variance back to -31,615.71.");
    $say($line);
};

