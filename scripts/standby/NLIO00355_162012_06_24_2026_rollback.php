<?php // scripts/pending/NLIO00355_162012_06_24_2026_rollback.php
// Rollback: restore the 16 deleted rows byte-identical. Pre-fix state, nothing changed.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 5654;
    $ORG     = 162012;
    $WIP_ACCT = 12502;
    $TOL     = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00355_162012 — ROLLBACK (restore duplicate closure NWPCL-NVT0000411)");
    $say($line);

    // PRE-CHECK: fix must have been applied (closure 25071 must not exist)
    $still = (int) $db->selectOne("SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = 25071")->c;
    if ($still !== 0) {
        throw new \RuntimeException("Rollback pre-check failed: closure 25071 still exists (fix not applied?)");
    }
    $say("   Confirmed: closure 25071 not present — fix has been applied.");

    $say("");
    $say(" APPLYING ROLLBACK (transaction)");
    $db->beginTransaction();
    try {
        // 1. acct_doc header (parent — restore first)
        $db->insert("INSERT INTO acct_doc (acct_doc_id, explanation, date_created, is_active)
                     VALUES (103783708, 'NLIO00355 - CONCRETE VAULT', '2025-09-18 15:58:56', 1)");
        $say("   INSERT acct_doc(103783708)");

        // 2. wip_t_project_closure (411 itself, parent of signee + inventory + contra)
        $db->insert("INSERT INTO wip_t_project_closure
                       (wip_t_project_closure_id, documentno, docstatus, amt_closure,
                        date_closure, date_gl, date_created,
                        ad_org_id, doc_i_submod_id, wip_i_project_id, acct_doc_id)
                     VALUES (25071, 'NWPCL-NVT0000411', 'PR', 1932.96,
                             '2025-09-18', '2025-09-18', '2025-09-18 11:10:32',
                             162012, 397, 5654, 103783708)");
        $say("   INSERT wip_t_project_closure(25071)");

        // 3. wip_t_project_closure_signee (children of 25071)
        $db->insert("INSERT INTO wip_t_project_closure_signee
                       (wip_t_project_closure_signee_id, wip_t_project_closure_id, s_bpartner_id,
                        usercode, role, date_created, date_updated, bpar_i_person_id)
                     VALUES (47105, 25071, 19593, '1719985786088', 'MKR',
                             '2025-09-18 11:11:17', '2025-09-18 11:11:17', 23078)");
        $db->insert("INSERT INTO wip_t_project_closure_signee
                       (wip_t_project_closure_signee_id, wip_t_project_closure_id, s_bpartner_id,
                        usercode, role, date_created, date_updated, bpar_i_person_id)
                     VALUES (47120, 25071, 1716, '1450330627149', 'CKR',
                             '2025-09-18 15:47:06', '2025-09-18 15:47:06', 1355)");
        $say("   INSERT wip_t_project_closure_signee(47105, 47120)");

        // 4. wip_t_project_closure_inventory (children of 25071)
        $db->insert("INSERT INTO wip_t_project_closure_inventory
                       (wip_t_project_closure_inventory_id, wip_t_project_closure_id, nvt_i_sku_id,
                        nvt_i_locator_id, qty, cost, amt)
                     VALUES (1347, 25071, 3906, 29842, 1.00, 966.48, 966.48)");
        $db->insert("INSERT INTO wip_t_project_closure_inventory
                       (wip_t_project_closure_inventory_id, wip_t_project_closure_id, nvt_i_sku_id,
                        nvt_i_locator_id, qty, cost, amt)
                     VALUES (1348, 25071, 2432, 29842, 1.00, 966.48, 966.48)");
        $say("   INSERT wip_t_project_closure_inventory(1347, 1348)");

        // 5. wip_t_project_closure (the draft 124DR, child of contra-link parent 25071)
        $db->insert("INSERT INTO wip_t_project_closure
                       (wip_t_project_closure_id, documentno, docstatus, amt_closure, date_closure, date_created,
                        ad_org_id, doc_i_submod_id, wip_i_project_id, doc_t_reference_number_id)
                     VALUES (27138, 'NWPCLR-NVT0000124DR', 'DR', -1932.96, '2026-06-16',
                             '2026-06-16 11:05:09', 162012, 398, 5654, 2864084)");
        $say("   INSERT wip_t_project_closure(27138)  — the draft");

        // 6. wip_t_project_closure_signee for the draft 27138 (must exist before contra, child of 27138)
        $db->insert("INSERT INTO wip_t_project_closure_signee
                       (wip_t_project_closure_signee_id, wip_t_project_closure_id, s_bpartner_id,
                        usercode, role, date_created, date_updated, bpar_i_person_id)
                     VALUES (50780, 27138, 21786, '1746406246454', 'MKR',
                             '2026-06-16 11:03:20', '2026-06-16 11:05:09', 25271)");
        $say("   INSERT wip_t_project_closure_signee(50780)  — draft maker");

        // 7. wip_t_project_closure_contra (links 27138 → 25071)
        $db->insert("INSERT INTO wip_t_project_closure_contra
                       (wip_t_project_closure_contra_id, wip_t_project_closure_id, wip_t_project_closure_id_returned)
                     VALUES (1867, 27138, 25071)");
        $say("   INSERT wip_t_project_closure_contra(1867)");

        // 7. acct_gl rows
        $db->insert("INSERT INTO acct_gl
                       (acct_gl_id, ad_org_id, gl_acct_id, documentno, date_gl, debit, credit,
                        acct_doc_id, doc_i_submod_id)
                     VALUES (2231641, 162012, 11309, 'NWPCL-NVT0000411', '2025-09-18',
                             1932.96, 0.00, 103783708, 397)");
        $db->insert("INSERT INTO acct_gl
                       (acct_gl_id, ad_org_id, gl_acct_id, documentno, date_gl, debit, credit,
                        acct_doc_id, doc_i_submod_id)
                     VALUES (2231642, 162012, 12502, 'NWPCL-NVT0000411', '2025-09-18',
                             0.00, 1932.96, 103783708, 397)");
        $say("   INSERT acct_gl(2231641, 2231642)");

        // 8. nvt_l_stockcard_locatorqty
        $db->insert("INSERT INTO nvt_l_stockcard_locatorqty
                       (nvt_l_stockcard_locatorqty_id, doc_i_submod_id, nvt_i_sku_id, nvt_i_locator_id,
                        documentno, qtymovement, date_gl, ad_org_id, qtybalance,
                        date_movement, date_created, doc_t_reference_number_id)
                     VALUES (925468, 397, 3906, 29842, 'NWPCL-NVT0000411', 0.00, '2025-09-18', 162012, 0.00,
                             '2025-09-18 15:58:57', '2025-09-18 15:58:57', 1533812)");
        $db->insert("INSERT INTO nvt_l_stockcard_locatorqty
                       (nvt_l_stockcard_locatorqty_id, doc_i_submod_id, nvt_i_sku_id, nvt_i_locator_id,
                        documentno, qtymovement, date_gl, ad_org_id, qtybalance,
                        date_movement, date_created, doc_t_reference_number_id)
                     VALUES (925469, 397, 2432, 29842, 'NWPCL-NVT0000411', 0.00, '2025-09-18', 162012, 0.00,
                             '2025-09-18 15:58:57', '2025-09-18 15:58:57', 1533812)");
        $say("   INSERT nvt_l_stockcard_locatorqty(925468, 925469)");

        // 9. nvt_l_stockcard_mac
        $db->insert("INSERT INTO nvt_l_stockcard_mac
                       (nvt_l_stockcard_mac_id, doc_i_submod_id, ad_org_id, nvt_i_sku_id, documentno,
                        qty, cumqty, amt, cumamt, cost, date_trans, date_gl, transaction, doc_t_reference_number_id)
                     VALUES (591509, 397, 162012, 3906, 'NWPCL-NVT0000411',
                             0.00, -74.00, 966.48, -274437.40, 966.48,
                             '2025-09-18 15:58:57', '2025-09-18', 'OTHERS', 1533812)");
        $db->insert("INSERT INTO nvt_l_stockcard_mac
                       (nvt_l_stockcard_mac_id, doc_i_submod_id, ad_org_id, nvt_i_sku_id, documentno,
                        qty, cumqty, amt, cumamt, cost, date_trans, date_gl, transaction, doc_t_reference_number_id)
                     VALUES (591510, 397, 162012, 2432, 'NWPCL-NVT0000411',
                             0.00, 0.00, 966.48, 966.48, 966.48,
                             '2025-09-18 15:58:57', '2025-09-18', 'OTHERS', 1533812)");
        $say("   INSERT nvt_l_stockcard_mac(591509, 591510)");

        // 10. acct_balance summary rows
        $db->insert("INSERT INTO acct_balance
                       (acct_balance_id, gl_acct_id, ad_org_id, doc_i_submod_id, date_gl, debit, credit, is_active)
                     VALUES (822043, 11309, 162012, 397, '2025-09-18', 1932.96, 0.00, 1)");
        $db->insert("INSERT INTO acct_balance
                       (acct_balance_id, gl_acct_id, gl_subacct_id, ad_org_id, doc_i_submod_id, date_gl, debit, credit, is_active)
                     VALUES (822063, 12502, 25822, 162012, 397, '2025-09-18', 0.00, 1932.96, 1)");
        $say("   INSERT acct_balance(822043, 822063)");

        // POST-CHECK: variance back to -1932.96
        $glAfter = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK: gl_amt = " . $money($glAfter) . "  (expected -1932.96)");
        if (abs($glAfter - (-1932.96)) > $TOL) throw new \RuntimeException("Rollback post-check failed: gl_amt is $glAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — pre-fix state fully restored. NLIO00355 variance back to +1,932.96.");
    $say($line);
};
