<?php // scripts/pending/WIP35755_162011_06_25_2026_rollback.php
// Project 11229 â€” ROLLBACK of the 2-duplicate-closure deletion.
//
// IMPORTANT â€” replica rollback path:
//   The apply script DELETEs rows (14 deletes + 2 updates). Rolling back DELETEs
//   requires re-INSERTing the original data, which the apply itself doesn't capture.
//
//   PRIMARY rollback path: trigger a replica sync from live. Live still has all the
//   original rows; the next sync will restore them automatically.
//
//   FALLBACK rollback path: this script re-INSERTs hardcoded original row contents
//   captured from forensic probes 2026-06-25 (see Integrity Report/.tools/_explain_11229.php
//   and _cascade_11229.php). Suitable for live deployment scenarios where we want
//   to undo our own apply without waiting for sync.
//
//   For LIVE deployment of the apply: before running the apply, capture the rows to
//   be deleted into a backup table or JSON file. This script is a documentation copy
//   that handles the most-common rollback case but should not be the only safety net.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ = 11229;
    $ORG  = 162011;
    $WIP_ACCT = 12502;
    $AMT = 125662.31;
    $TOL = 0.01;

    $line  = str_repeat('=', 95);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11229 â€” ROLLBACK (restore 2 duplicate closures + variance -251,324.62)");
    $say($line);

    // Detect current state â€” if rows are still there, nothing to roll back
    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25177, 26860)"
    )->c;
    if ($existing === 2) {
        $say("");
        $say(" NO-OP â€” both duplicate closures still present. Apply was never run, or sync already restored.");
        $say($line);
        return;
    }
    if ($existing !== 0) {
        throw new \RuntimeException("Partial state: expected 0 or 2 duplicate closures, found $existing");
    }

    // Hardcoded original values from forensic probes (2026-06-25)
    $RESTORE = [
        // ---- WPCL-ACPR0974 (Feb 2, 2026) ----
        ['table' => 'acct_doc', 'data' => [
            'acct_doc_id'  => 103820222,
            'explanation'  => 'RP AREA 5&6-24" U CULVERT 464.94 L.M & CATCH BASIN 12 SETS',
            'date_created' => '2026-02-02 13:34:33',
            'is_active'    => 1,
        ]],
        ['table' => 'wip_t_project_closure', 'data' => [
            'wip_t_project_closure_id' => 25177,
            'documentno'   => 'WPCL-ACPR0974',
            'docstatus'    => 'PR',
            'amt_closure'  => 125662.31,
            'date_gl'      => '2026-02-02',
            'acct_doc_id'  => 103820222,
            'ad_org_id'    => 162011,
            'wip_i_project_id' => 11229,
            'doc_i_submod_id'  => 293,
            'date_created' => '2025-09-22 09:50:18',
            'date_updated' => '2026-02-02 13:34:33',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 47260,
            'wip_t_project_closure_id' => 25177,
            'role'              => 'MKR',
            's_bpartner_id'     => 19593,
            'bpar_i_person_id'  => 23078,
            'date_created'      => '2026-02-02 13:34:30',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 50138,
            'wip_t_project_closure_id' => 25177,
            'role'              => 'CKR',
            's_bpartner_id'     => 1716,
            'bpar_i_person_id'  => 1355,
            'date_created'      => '2026-02-02 13:34:33',
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2343203,
            'documentno'    => 'WPCL-ACPR0974',
            'date_gl'       => '2026-02-02',
            'gl_acct_id'    => 11310,
            'gl_subacct_id' => 25766,
            'ad_org_id'     => 162011,
            'debit'         => 125662.31,
            'credit'        => 0.00,
            'acct_doc_id'   => 103820222,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2343204,
            'documentno'    => 'WPCL-ACPR0974',
            'date_gl'       => '2026-02-02',
            'gl_acct_id'    => 12502,
            'gl_subacct_id' => 35755,
            'ad_org_id'     => 162011,
            'debit'         => 0.00,
            'credit'        => 125662.31,
            'acct_doc_id'   => 103820222,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_balance', 'data' => [
            'acct_balance_id' => 861957,
            'gl_acct_id'      => 12502,
            'gl_subacct_id'   => 35755,
            'ad_org_id'       => 162011,
            'date_gl'         => '2026-02-02',
            'debit'           => 0.00,
            'credit'          => 125662.31,
            'doc_i_submod_id' => 293,
        ]],

        // ---- WPCL-ACPR0979 (Mar 9, 2026) ----
        ['table' => 'acct_doc', 'data' => [
            'acct_doc_id'  => 103828748,
            'explanation'  => 'RP AREA 5&6-24" U CULVERT 464.94 L.M & CATCH BASIN 12 SETS',
            'date_created' => '2026-03-09 14:16:55',
            'is_active'    => 1,
        ]],
        ['table' => 'wip_t_project_closure', 'data' => [
            'wip_t_project_closure_id' => 26860,
            'documentno'       => 'WPCL-ACPR0979',
            'docstatus'        => 'PR',
            'amt_closure'      => 125662.31,
            'date_gl'          => '2026-03-09',
            'acct_doc_id'      => 103828748,
            'ad_org_id'        => 162011,
            'wip_i_project_id' => 11229,
            'doc_i_submod_id'  => 293,
            'date_created'     => '2026-02-21 10:09:50',
            'date_updated'     => '2026-03-09 14:16:55',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 50352,
            'wip_t_project_closure_id' => 26860,
            'role'              => 'MKR',
            's_bpartner_id'     => 19593,
            'bpar_i_person_id'  => 23078,
            'date_created'      => '2026-03-09 14:16:50',
        ]],
        ['table' => 'wip_t_project_closure_signee', 'data' => [
            'wip_t_project_closure_signee_id' => 50632,
            'wip_t_project_closure_id' => 26860,
            'role'              => 'CKR',
            's_bpartner_id'     => 1716,
            'bpar_i_person_id'  => 1355,
            'date_created'      => '2026-03-09 14:16:55',
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2367820,
            'documentno'    => 'WPCL-ACPR0979',
            'date_gl'       => '2026-03-09',
            'gl_acct_id'    => 11310,
            'gl_subacct_id' => 25766,
            'ad_org_id'     => 162011,
            'debit'         => 125662.31,
            'credit'        => 0.00,
            'acct_doc_id'   => 103828748,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_gl', 'data' => [
            'acct_gl_id'    => 2367821,
            'documentno'    => 'WPCL-ACPR0979',
            'date_gl'       => '2026-03-09',
            'gl_acct_id'    => 12502,
            'gl_subacct_id' => 35755,
            'ad_org_id'     => 162011,
            'debit'         => 0.00,
            'credit'        => 125662.31,
            'acct_doc_id'   => 103828748,
            'doc_i_submod_id' => 293,
        ]],
        ['table' => 'acct_balance', 'data' => [
            'acct_balance_id' => 871423,
            'gl_acct_id'      => 12502,
            'gl_subacct_id'   => 35755,
            'ad_org_id'       => 162011,
            'date_gl'         => '2026-03-09',
            'debit'           => 0.00,
            'credit'          => 125662.31,
            'doc_i_submod_id' => 293,
        ]],
    ];

    // Memorial Lot balances to restore (UPDATE +125,662.31 each)
    $ML_BAL_IDS = [861945, 871414];

    $say("");
    $say(" RESTORE (transaction): re-insert " . count($RESTORE) . " rows + restore 2 ML balances");
    $db->beginTransaction();
    try {
        // Note: wip_t_project_closure_acctpair rows are NOT in the hardcoded set
        // because the original probe didn't capture their columns. To get a true
        // byte-identical rollback, run a replica sync from live instead.

        foreach ($RESTORE as $r) {
            $cols = array_keys($r['data']);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colList = implode(',', $cols);
            $db->insert("INSERT INTO {$r['table']} ($colList) VALUES ($placeholders)", array_values($r['data']));
            $say("   INSERT {$r['table']} id=" . ($r['data'][$cols[0]] ?? '?'));
        }

        foreach ($ML_BAL_IDS as $id) {
            $a = $db->update("UPDATE acct_balance SET debit = debit + ? WHERE acct_balance_id = ?", [$AMT, $id]);
            $say("   UPDATE acct_balance ML(" . $id . ") debit += " . $money($AMT) . ": affected=$a");
            if ($a !== 1) throw new \RuntimeException("ML balance update affected $a");
        }

        // POST-CHECK
        $wipBalNet = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
             JOIN gl_subacct sub USING (gl_subacct_id)
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $wipGlNet = (float) $db->selectOne(
            "SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag
             JOIN gl_subacct sub USING (gl_subacct_id)
             WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $say("");
        $say(" POST-CHECK (both must be -251,324.62):");
        $say("   acct_balance WIP net = " . $money($wipBalNet));
        $say("   acct_gl      WIP net = " . $money($wipGlNet));
        if (abs($wipBalNet + 251324.62) > $TOL) throw new \RuntimeException("WIP balance net is $wipBalNet");
        if (abs($wipGlNet + 251324.62) > $TOL)  throw new \RuntimeException("WIP gl net is $wipGlNet");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" âœ“ SUCCESS â€” pre-fix state mostly restored. Variance back to -251,324.62.");
    $say(" NOTE: wip_t_project_closure_acctpair rows are NOT restored by this script.");
    $say("       For a fully byte-identical restoration, trigger a replica sync from live.");
    $say($line);
};

