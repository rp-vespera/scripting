<?php // scripts/pending/WIP36135_162011_06_25_2026.php
// Project 11455 (RP INTERMENT BACKDROP) org 162011 / WIP subacct 36135
// Remove 2 duplicate closures WPCL-AST0080 (2026-02-02) and WPCL-AST0081 (2026-03-17),
// each ₱40,031. Keep WPCL-AST0079 (2026-01-10) as the effective legit closure.
//
// Context: this project has 4 forward closures + 1 return on the 162011 side:
//   2025-11-28  WPCL-AST0076 — original, CANCELLED by return below
//   2025-12-26  WPCLRAST0013 — return, targets 0076 (per contra row 1832)
//   2026-01-10  WPCL-AST0079 — duplicate (becomes effective legit after the return)
//   2026-02-02  WPCL-AST0080 — DUPLICATE, TO BE DELETED
//   2026-03-17  WPCL-AST0081 — DUPLICATE, TO BE DELETED
//
// All forward closures by MKR bp 23078 / CKR bp 1355. The return was processed by
// different signees (consistent with the 12520 pattern of a senior partial cleanup).
//
// Cascade per duplicate (FK-safe order, WPCL-AST doctype):
//   1. wip_t_project_closure_signee   (2 rows)            DELETE
//   2. wip_t_project_closure_asset    (1 row)             DELETE
//   3. acct_balance (asset side 36112) (exclusive)        DELETE
//   4. acct_balance (WIP side 36135)   (exclusive)        DELETE
//   5. acct_gl                        (2 rows DR/CR)      DELETE
//   6. wip_t_project_closure          (1 row)             DELETE
//   7. acct_doc                       (1 row)             DELETE
//
// Total: 18 DELETEs across both duplicates.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ=11455; $ORG=162011; $WIP_ACCT=12502;
    $AMT = 40031.00; $TOL = 0.01;

    $DUPS = [
        [
            'docno'=>'WPCL-AST0080', 'closure_id'=>25374, 'acct_doc_id'=>103820224,
            'signee_ids'=>[47587, 50140], 'closure_asset_id'=>1656,
            'acct_gl_ids'=>[2343207, 2343208],
            'asset_bal_id'=>861954, 'wip_bal_id'=>861958,
        ],
        [
            'docno'=>'WPCL-AST0081', 'closure_id'=>25356, 'acct_doc_id'=>103830548,
            'signee_ids'=>[47560, 50712], 'closure_asset_id'=>1649,
            'acct_gl_ids'=>[2372728, 2372729],
            'asset_bal_id'=>873543, 'wip_bal_id'=>873545,
        ],
    ];

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11455 (INTERMENT BACKDROP) — delete 2 duplicates");
    $say($line);

    $exists = (int)$db->selectOne("SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25374, 25356)")->c;
    if ($exists === 0) { $say(""); $say(" NO-OP — both duplicates already removed."); $say($line); return; }
    if ($exists !== 2) throw new \RuntimeException("Partial state: expected 0 or 2, found $exists");

    $say(""); $say(" PRE-CHECK:");
    foreach ($DUPS as $i => $d) {
        $say("  #".($i+1)." {$d['docno']}:");
        foreach ([
            ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
            ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")", 2],
            ['closure_asset', "SELECT COUNT(*) c FROM wip_t_project_closure_asset WHERE wip_t_project_closure_asset_id = {$d['closure_asset_id']}", 1],
            ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")", 2],
            ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}", 1],
            ['asset_bal',"SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$d['asset_bal_id']} AND debit = $AMT", 1],
            ['wip_bal',  "SELECT COUNT(*) c FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']} AND credit = $AMT", 1],
            ['consumption_fk', "SELECT COUNT(*) c FROM wip_t_project_consumption WHERE wip_t_project_closure_id = {$d['closure_id']}", 0],
        ] as [$lbl, $q, $expect]) {
            $got = (int) $db->selectOne($q)->c;
            if ($got !== $expect) throw new \RuntimeException("$lbl {$d['docno']}: got=$got expected=$expect");
            $say("    $lbl: $got ✓");
        }
    }

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE: " . $money($wipNet));
    if (abs($wipNet + 80062.00) > $TOL) throw new \RuntimeException("WIP net $wipNet");

    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        foreach ($DUPS as $d) {
            $say("  -- {$d['docno']} --");
            $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")");
            $say("    signees: $a"); if ($a !== 2) throw new \RuntimeException('signees');
            $a = $db->delete("DELETE FROM wip_t_project_closure_asset WHERE wip_t_project_closure_asset_id = {$d['closure_asset_id']}");
            $say("    closure_asset: $a"); if ($a !== 1) throw new \RuntimeException('closure_asset');
            $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id IN ({$d['asset_bal_id']}, {$d['wip_bal_id']})");
            $say("    acct_balance: $a"); if ($a !== 2) throw new \RuntimeException('acct_balance');
            $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")");
            $say("    acct_gl: $a"); if ($a !== 2) throw new \RuntimeException('acct_gl');
            $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']}");
            $say("    closure: $a"); if ($a !== 1) throw new \RuntimeException('closure');
            $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}");
            $say("    acct_doc: $a"); if ($a !== 1) throw new \RuntimeException('acct_doc');
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
    $say(" SUCCESS — 2 duplicates removed, variance closed.");
    $say($line);
};
