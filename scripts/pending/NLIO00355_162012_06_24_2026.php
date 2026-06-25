<?php // scripts/pending/NLIO00355_162012_06_24_2026.php
// NLIO00355 (project 5654, org 162012) — delete duplicate WIP closure NWPCL-NVT0000411.
// Defect: original closure NWPCL-NVT0000143 (2023-11-09, 1,932.96) drained WIP correctly.
// Then on 2025-09-18, the SAME closure was re-posted as NWPCL-NVT0000411 for the same
// 1,932.96 — over-draining WIP to -1,932.96. Accounting team already knew (they drafted
// reversal NWPCLR-NVT0000124DR on 2026-06-16 targeting 411) but the draft was never processed.
// Fix: delete duplicate closure 411 + its full cascade + the orphan draft.
// 16 rows across 9 tables. FK-safe order (deepest children first).

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 5654;
    $ORG     = 162012;
    $WIP_ACCT = 12502;   $WIP_SUB = 25822;
    $INV_ACCT = 11309;
    $SUBMOD  = 397;
    $TOL     = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00355_162012 — delete duplicate WIP closure NWPCL-NVT0000411");
    $say(" Project $PROJ_ID / Org $ORG / Acct $WIP_ACCT / Subacct $WIP_SUB");
    $say(" Expected: gl_amt -1932.96 → 0.00 (drop the duplicate)");
    $say($line);

    // ============================================================
    // IDEMPOTENCY CHECK
    // ============================================================
    $stillExists = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM wip_t_project_closure
         WHERE wip_t_project_closure_id = 25071 AND documentno = 'NWPCL-NVT0000411'"
    )->c;
    if ($stillExists === 0) {
        $glNow = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" NO-OP — fix already applied (closure 25071 already deleted). Current gl_amt = " . $money($glNow));
        $say($line);
        return;
    }

    // ============================================================
    // PRE-CHECKS — every row we expect to delete is present
    // ============================================================
    $checks = [
        ['wip_t_project_closure_contra',  "wip_t_project_closure_contra_id = 1867 AND wip_t_project_closure_id_returned = 25071", 1],
        ['wip_t_project_closure',         "wip_t_project_closure_id = 27138 AND documentno = 'NWPCLR-NVT0000124DR' AND docstatus = 'DR'", 1],
        ['wip_t_project_closure_signee',  "wip_t_project_closure_signee_id IN (47105, 47120) AND wip_t_project_closure_id = 25071", 2],
        ['wip_t_project_closure_signee',  "wip_t_project_closure_signee_id = 50780 AND wip_t_project_closure_id = 27138", 1],
        ['wip_t_project_closure_inventory', "wip_t_project_closure_inventory_id IN (1347, 1348) AND wip_t_project_closure_id = 25071", 2],
        ['acct_gl',                       "acct_gl_id IN (2231641, 2231642) AND documentno = 'NWPCL-NVT0000411'", 2],
        ['wip_t_project_closure',         "wip_t_project_closure_id = 25071 AND documentno = 'NWPCL-NVT0000411'", 1],
        ['nvt_l_stockcard_locatorqty',   "nvt_l_stockcard_locatorqty_id IN (925468, 925469) AND documentno = 'NWPCL-NVT0000411'", 2],
        ['nvt_l_stockcard_mac',          "nvt_l_stockcard_mac_id IN (591509, 591510) AND documentno = 'NWPCL-NVT0000411'", 2],
        ['acct_doc',                      "acct_doc_id = 103783708", 1],
        ['acct_balance',                  "acct_balance_id IN (822043, 822063)", 2],
    ];
    $say("");
    $say(" PRE-CHECKS:");
    foreach ($checks as [$t, $w, $expected]) {
        $c = (int) $db->selectOne("SELECT COUNT(*) AS c FROM `$t` WHERE $w")->c;
        $say("   $t  →  $c rows  (expected $expected)");
        if ($c !== $expected) throw new \RuntimeException("Pre-check failed on $t: got $c, expected $expected");
    }

    // ============================================================
    // APPLY DELETES (FK-safe order: deepest children first)
    // ============================================================
    $say("");
    $say(" APPLYING (transaction)");
    $db->beginTransaction();
    try {
        $deletes = [
            // 1. contra link (child of both 27138 and 25071)
            ['wip_t_project_closure_contra',  "wip_t_project_closure_contra_id = 1867", 1],
            // 2. signee row tied to the draft 27138 (FK child of 27138 — must go before 27138)
            ['wip_t_project_closure_signee',  "wip_t_project_closure_signee_id = 50780 AND wip_t_project_closure_id = 27138", 1],
            // 3. draft reversal 124DR (no remaining references)
            ['wip_t_project_closure',         "wip_t_project_closure_id = 27138 AND documentno = 'NWPCLR-NVT0000124DR'", 1],
            // 3. children of closure 411 (must clear before deleting 411 itself)
            ['wip_t_project_closure_signee',  "wip_t_project_closure_signee_id IN (47105, 47120)", 2],
            ['wip_t_project_closure_inventory', "wip_t_project_closure_inventory_id IN (1347, 1348)", 2],
            // 4. acct_gl rows (children of acct_doc 103783708)
            ['acct_gl',                       "acct_gl_id IN (2231641, 2231642) AND documentno = 'NWPCL-NVT0000411'", 2],
            // 5. closure 411 itself (child of acct_doc 103783708)
            ['wip_t_project_closure',         "wip_t_project_closure_id = 25071 AND documentno = 'NWPCL-NVT0000411'", 1],
            // 6. standalone stockcard rows
            ['nvt_l_stockcard_locatorqty',   "nvt_l_stockcard_locatorqty_id IN (925468, 925469) AND documentno = 'NWPCL-NVT0000411'", 2],
            ['nvt_l_stockcard_mac',          "nvt_l_stockcard_mac_id IN (591509, 591510) AND documentno = 'NWPCL-NVT0000411'", 2],
            // 7. acct_balance summary rows
            ['acct_balance',                  "acct_balance_id IN (822043, 822063)", 2],
            // 8. acct_doc header (last)
            ['acct_doc',                      "acct_doc_id = 103783708", 1],
        ];

        foreach ($deletes as [$t, $w, $expected]) {
            $aff = $db->delete("DELETE FROM `$t` WHERE $w");
            $say("   DELETE $t WHERE $w  →  affected=$aff (expected $expected)");
            if ($aff !== $expected) throw new \RuntimeException("DELETE $t affected $aff, expected $expected");
        }

        // POST-CHECK: variance on 12502 returns to 0 (FRS scanner side)
        $glAfter = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK: acct_balance gl_amt = " . $money($glAfter) . "  (expected 0.00)");
        if (abs($glAfter) > $TOL) throw new \RuntimeException("Post-check failed: gl_amt is $glAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — duplicate closure NWPCL-NVT0000411 removed. NLIO00355 variance closed to 0.00.");
    $say(" Audit queries:");
    $say("   SELECT COUNT(*) FROM acct_gl WHERE documentno = 'NWPCL-NVT0000411';  -- expected 0");
    $say("   SELECT COUNT(*) FROM acct_gl WHERE documentno = 'NWPCL-NVT0000143';  -- expected 2 (legit closure still there)");
    $say(" Separate issue (not fixed here): closure NWPCL-NVT0000413 has acct_gl entries without acct_balance.");
    $say($line);
};
