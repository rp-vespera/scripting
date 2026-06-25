<?php // scripts/pending/NLIO00469_162012_06_24_2026_rollback.php
/**
 * Rollback for NLIO00469_162012_06_24_2026.php
 * Restores byte-identical pre-fix state (9 rows re-inserted in FK-safe order; 2 acct_balance rows reverted).
 * After running: variance returns to -421.95 exactly as before.
 */

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 8405;
    $ORG     = 162012;
    $GL_ACCT = 12502;
    $DOC     = 'NWPCLR-NVT0000063';
    $TOL     = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00469_162012 — ROLLBACK (restore duplicate $DOC and revert acct_balance)");
    $say($line);

    // PRE-CHECK: confirm fix was applied (variance currently 0, rows absent)
    $glNow = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
         FROM acct_balance bal
         JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ_ID, $GL_ACCT, $ORG]
    )->gl;
    $say("");
    $say(" PRE-CHECK: current gl_amt = " . $money($glNow) . " (expected 0.00 — fix applied)");
    if (abs($glNow) > $TOL) {
        throw new \RuntimeException("Rollback pre-check failed: variance is $glNow, expected 0.00 (fix not applied?)");
    }

    $countDoc = (int) $db->selectOne("SELECT COUNT(*) AS c FROM acct_gl WHERE documentno = ?", [$DOC])->c;
    if ($countDoc !== 0) {
        throw new \RuntimeException("Rollback pre-check failed: acct_gl still has $countDoc row(s) with documentno=$DOC (fix not applied yet?)");
    }
    $say("   acct_gl rows with documentno=$DOC: 0  (confirmed — fix has been applied)");

    // APPLY rollback inside transaction
    $say("");
    $say(" APPLYING ROLLBACK (transaction)");
    $db->beginTransaction();
    try {
        // 1. acct_doc (parent — restore first)
        $db->insert("INSERT INTO acct_doc (acct_doc_id, explanation, date_created, is_active)
                     VALUES (?, ?, ?, ?)",
            [103704833, 'NLIO00469 - CONCRETE VAULT', '2024-11-09 11:57:04', 1]);
        $say("   INSERT acct_doc(103704833)");

        // 2. wip_t_project_closure 20920 (parent of contra + signee)
        $db->insert("INSERT INTO wip_t_project_closure
                       (wip_t_project_closure_id, documentno, docstatus, amt_closure,
                        date_closure, date_gl, date_created, ad_org_id, doc_i_submod_id,
                        wip_i_project_id, doc_t_reference_number_id, acct_doc_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [20920, $DOC, 'PR', -421.95,
             '2024-11-09', '2024-11-09', '2024-11-09 11:52:15', $ORG, 398,
             $PROJ_ID, 1378480, 103704833]);
        $say("   INSERT wip_t_project_closure(20920)");

        // 3. wip_t_project_closure_contra 1590
        $db->insert("INSERT INTO wip_t_project_closure_contra
                       (wip_t_project_closure_contra_id, wip_t_project_closure_id, wip_t_project_closure_id_returned)
                     VALUES (?,?,?)",
            [1590, 20920, 16592]);
        $say("   INSERT wip_t_project_closure_contra(1590)");

        // 4. wip_t_project_closure_signee 39030 + 39111
        $db->insert("INSERT INTO wip_t_project_closure_signee
                       (wip_t_project_closure_signee_id, wip_t_project_closure_id, s_bpartner_id,
                        usercode, role, date_created, date_updated, bpar_i_person_id)
                     VALUES (?,?,?,?,?,?,?,?)",
            [39030, 20920, 18961, '1708912821464', 'MKR', '2024-11-08 20:35:23', '2024-11-08 20:35:23', 22446]);
        $say("   INSERT wip_t_project_closure_signee(39030)");
        $db->insert("INSERT INTO wip_t_project_closure_signee
                       (wip_t_project_closure_signee_id, wip_t_project_closure_id, s_bpartner_id,
                        usercode, role, date_created, date_updated, bpar_i_person_id)
                     VALUES (?,?,?,?,?,?,?,?)",
            [39111, 20920, 18315, '1699078631689', 'CKR', '2024-11-09 11:52:15', '2024-11-09 11:52:15', 21800]);
        $say("   INSERT wip_t_project_closure_signee(39111)");

        // 5. acct_gl 1961538 + 1961539
        $db->insert("INSERT INTO acct_gl
                       (acct_gl_id, ad_org_id, gl_acct_id, documentno, date_gl, date_trans,
                        debit, credit, acct_doc_id, doc_i_submod_id, doc_t_reference_number_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [1961538, $ORG, 11309, $DOC, '2024-11-09', '2024-11-09 11:57:04',
             0.00, 421.95, 103704833, 398, 1378480]);
        $say("   INSERT acct_gl(1961538)");
        $db->insert("INSERT INTO acct_gl
                       (acct_gl_id, ad_org_id, gl_acct_id, documentno, date_gl, date_trans,
                        debit, credit, gl_subacct_id, acct_doc_id, doc_i_submod_id, doc_t_reference_number_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [1961539, $ORG, $GL_ACCT, $DOC, '2024-11-09', '2024-11-09 11:57:04',
             421.95, 0.00, 29987, 103704833, 398, 1378480]);
        $say("   INSERT acct_gl(1961539)");

        // 6. nvt_l_stockcard_locatorqty 872620
        $db->insert("INSERT INTO nvt_l_stockcard_locatorqty
                       (nvt_l_stockcard_locatorqty_id, doc_i_submod_id, nvt_i_sku_id, nvt_i_locator_id,
                        documentno, qtymovement, date_gl, ad_org_id, qtybalance,
                        date_movement, date_created, doc_t_reference_number_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [872620, 398, 3906, 3, $DOC, 0.00, '2024-11-09', $ORG, -60.00,
             '2024-11-09 11:57:04', '2024-11-09 11:57:04', 1378480]);
        $say("   INSERT nvt_l_stockcard_locatorqty(872620)");

        // 7. nvt_l_stockcard_mac 551000
        $db->insert("INSERT INTO nvt_l_stockcard_mac
                       (nvt_l_stockcard_mac_id, doc_i_submod_id, ad_org_id, nvt_i_sku_id, documentno,
                        qty, cumqty, amt, cumamt, cost, date_trans, date_gl, transaction, doc_t_reference_number_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [551000, 397, $ORG, 3906, $DOC,
             0.00, -60.00, -421.95, -234379.19, -421.95,
             '2024-11-09 11:57:04', '2024-11-09', 'OTHERS', 1378480]);
        $say("   INSERT nvt_l_stockcard_mac(551000)");

        // 8. Revert acct_balance summary rows
        $a1 = $db->update("UPDATE acct_balance SET debit = 843.90 WHERE acct_balance_id = 733506 AND debit = 421.95");
        $say("   UPDATE acct_balance(733506) debit 421.95 -> 843.90   affected=$a1");
        if ($a1 !== 1) throw new \RuntimeException("Rollback UPDATE acct_balance.733506 affected $a1");

        $a2 = $db->update("UPDATE acct_balance SET credit = 42592.42 WHERE acct_balance_id = 733383 AND credit = 42170.47");
        $say("   UPDATE acct_balance(733383) credit 42170.47 -> 42592.42   affected=$a2");
        if ($a2 !== 1) throw new \RuntimeException("Rollback UPDATE acct_balance.733383 affected $a2");

        // Post-check: variance back to -421.95 (gl_amt = 421.95)
        $glAfter = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $GL_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK: gl_amt = " . $money($glAfter) . " (expected 421.95)");
        if (abs($glAfter - 421.95) > $TOL) {
            throw new \RuntimeException("Rollback post-check failed: gl_amt is $glAfter, expected 421.95");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — pre-fix state fully restored. NLIO00469 variance back to -421.95.");
    $say($line);
};
