<?php // scripts/pending/WIP37263_162011_06_25_2026_rollback.php
// Rollback for PROJ12109 fix â€” re-inserts the 8 deleted rows + restores the
// shared MemLot acct_balance by adding 83,228.82 back.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ=12109; $ORG=162011; $WIP_ACCT=12502; $AMT=83228.82; $TOL=0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 12109 â€” ROLLBACK (restore duplicate WPCL-ACPR0966)");
    $say($line);

    $exists = (int) $db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id = 25654")->c;
    if ($exists === 1) { $say(""); $say(" NO-OP â€” closure 25654 still present."); $say($line); return; }

    $RESTORE = [
        ['table' => 'acct_doc', 'data' => [
            'acct_doc_id'  => 103811416,
            'explanation'  => 'RP AREA 5 PERIMETER FENCE 2.74LM 1ST BATCH-25 SPANS',
            'date_created' => '2025-12-29 16:44:06',
            'is_active'    => 1,
        ]],
        ['table' => 'wip_t_project_closure', 'data' => [
            'wip_t_project_closure_id' => 25654,
            'documentno'        => 'WPCL-ACPR0966',
            'docstatus'         => 'PR',
            'amt_closure'       => 83228.82,
            'date_closure'      => '2025-12-29',
            'date_gl'           => '2025-12-29',
            'ad_org_id'         => 162011,
            'wip_i_project_id'  => 12109,
            'doc_i_submod_id'   => 293,
            'acct_doc_id'       => 103811416,
            'doc_t_reference_number_id' => 2737056,
            'date_created'      => '2025-10-22 08:31:38',
            'date_updated'      => '2025-12-29 16:44:06',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 49574,
            'wip_t_project_closure_id' => 25654,
            's_bpartner_id'      => 19593,
            'usercode'           => 1719985786088,
            'role'               => 'MKR',
            'bpar_i_person_id'   => 23078,
            'date_created'       => '2025-12-29 16:25:24',
            'date_updated'       => '2025-12-29 16:42:37',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 49576,
            'wip_t_project_closure_id' => 25654,
            's_bpartner_id'      => 1716,
            'usercode'           => 1450330627149,
            'role'               => 'CKR',
            'bpar_i_person_id'   => 1355,
            'date_created'       => '2025-12-29 16:44:06',
            'date_updated'       => '2025-12-29 16:44:06',
        ]],
        ['table' => 'wip_t_project_closure_acctpair', 'data' => [
            'wip_t_project_closure_acctpair_id' => 20603,
            'wip_t_project_closure_id' => 25654,
            'gl_acct_id'         => 11310,
            'gl_subacct_id'      => 25766,
            'date_created'       => '2025-10-22 08:31:38',
            'is_active'          => 1,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2314379,
            'ad_org_id'     => 162011,
            'gl_acct_id'    => 11310,
            'documentno'    => 'WPCL-ACPR0966',
            'date_gl'       => '2025-12-29',
            'debit'         => 83228.82,
            'credit'        => 0.00,
            'gl_subacct_id' => 25766,
            'acct_doc_id'   => 103811416,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2314380,
            'ad_org_id'     => 162011,
            'gl_acct_id'    => 12502,
            'documentno'    => 'WPCL-ACPR0966',
            'date_gl'       => '2025-12-29',
            'debit'         => 0.00,
            'credit'        => 83228.82,
            'gl_subacct_id' => 37263,
            'acct_doc_id'   => 103811416,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_balance', 'data' => [
            'acct_balance_id' => 851534,
            'gl_acct_id'      => 12502,
            'gl_subacct_id'   => 37263,
            'ad_org_id'       => 162011,
            'date_gl'         => '2025-12-29',
            'debit'           => 0.00,
            'credit'          => 83228.82,
            'doc_i_submod_id' => 293,
        ]],
    ];

    $say(""); $say(" RESTORE (transaction):");
    $db->beginTransaction();
    try {
        foreach ($RESTORE as $r) {
            $cols = array_keys($r['data']);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $db->insert("INSERT INTO {$r['table']} (" . implode(',', $cols) . ") VALUES ($ph)", array_values($r['data']));
            $say("   INSERT {$r['table']} id=" . ($r['data'][$cols[0]] ?? '?'));
        }
        $a = $db->update("UPDATE acct_balance SET debit = debit + ? WHERE acct_balance_id = ?", [$AMT, 851515]);
        $say("   ml_bal 851515 debit += " . $money($AMT) . ": $a");
        if ($a !== 1) throw new \RuntimeException("ml_bal restore");

        $wipBal = (float) $db->selectOne("SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?", [$PROJ, $WIP_ACCT, $ORG])->s;
        $say(""); $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected -83,228.82)");
        if (abs($wipBal + $AMT) > $TOL) throw new \RuntimeException("WIP net $wipBal");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS â€” duplicate restored, variance back to -83,228.82.");
    $say($line);
};

