<?php // scripts/pending/NLIO00469_162012_06_24_2026.php
/**
 * NLIO00469 (project 8405, org 162012) — duplicate WIP closure return cleanup.
 *
 * Per IMS Soft Artifact Agile #17064 (Stanlie Aller, 08/25/2025 -> dave tandog):
 *   Double-save of NWPCLR-NVT0000063 on 2024-11-09 caused -421.95 variance on acct 12502.
 *   Recommendation: delete the duplicate transaction (twin NWPCLR-NVT0000061 stays).
 *
 * Touches 9 rows across 7 tables (FK-safe order) + 2 acct_balance summary updates.
 * Companion docs: NLIO00469_162012_06_24_2026.docx, _DataDictionary.docx, _rollback.php
 */

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID   = 8405;
    $ORG       = 162012;
    $GL_ACCT   = 12502;
    $SUB       = 29987;
    $DOC       = 'NWPCLR-NVT0000063';
    $EXPECTED_VAR = 421.95;       // gl_amt before fix; variance = -421.95
    $TOL       = 0.01;

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00469_162012 — delete duplicate WIP closure return ($DOC)");
    $say(" Per IMS#17064. Project $PROJ_ID / Org $ORG / Acct $GL_ACCT / Subacct $SUB");
    $say(" Expected variance to close: -" . $money($EXPECTED_VAR));
    $say($line);

    // ============================================================
    // IDEMPOTENCY CHECK — has the fix already been applied?
    // ============================================================
    $dupCount = (int) $db->selectOne("SELECT COUNT(*) AS c FROM acct_gl WHERE documentno = ?", [$DOC])->c;
    $bal506   = $db->selectOne("SELECT debit, credit FROM acct_balance WHERE acct_balance_id = 733506");
    $bal383   = $db->selectOne("SELECT debit, credit FROM acct_balance WHERE acct_balance_id = 733383");
    $fixedDup = ($dupCount === 0);
    $fixedBal = $bal506 && abs((float)$bal506->debit - 421.95)  < $TOL
              && $bal383 && abs((float)$bal383->credit - 42170.47) < $TOL;
    if ($fixedDup && $fixedBal) {
        $glNow = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $GL_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" NO-OP — fix already applied on a previous run. Current state:");
        $say("   acct_gl rows for $DOC      : 0   (duplicate already deleted)");
        $say("   acct_balance(733506) debit : " . $money((float)$bal506->debit)  . "   (was 843.90, now 421.95)");
        $say("   acct_balance(733383) credit: " . $money((float)$bal383->credit) . "   (was 42,592.42, now 42,170.47)");
        $say("   project variance           : " . $money($glNow) . "   (was 421.95, now 0.00)");
        $say($line);
        $say(" Nothing to do. Script run recorded as success.");
        $say($line);
        return;
    }

    // ============================================================
    // PRE-CHECK 1: current variance is still -421.95
    // ============================================================
    $glBefore = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
         FROM acct_balance bal
         JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ_ID, $GL_ACCT, $ORG]
    )->gl;
    $say("");
    $say(" PRE-CHECK 1: current gl balance");
    $say("   gl_amt = " . $money($glBefore) . "  (expected " . $money($EXPECTED_VAR) . ")");
    if (abs($glBefore - $EXPECTED_VAR) > $TOL) {
        throw new \RuntimeException("Pre-check failed: gl_amt is $glBefore, expected $EXPECTED_VAR. If the fix was already applied, the idempotency check should have caught it — the data may be in an inconsistent partial-fix state. Inspect manually before retrying.");
    }

    // ============================================================
    // PRE-CHECK 2: show every row that will be deleted/updated
    // ============================================================
    $say("");
    $say(" PRE-CHECK 2: rows that will be DELETED");

    $sayRow = function ($label, $row) use ($say) {
        if ($row === null) { $say("   [MISSING] $label"); return; }
        $vals = [];
        foreach ((array)$row as $k => $v) {
            if ($v !== null && $v !== '') $vals[] = "$k=$v";
        }
        $say("   $label: " . implode(', ', $vals));
    };

    $r_contra = $db->selectOne("SELECT * FROM wip_t_project_closure_contra WHERE wip_t_project_closure_contra_id = 1590");
    $sayRow("wip_t_project_closure_contra(1590)", $r_contra);

    $r_signee1 = $db->selectOne("SELECT * FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id = 39030");
    $sayRow("wip_t_project_closure_signee(39030)", $r_signee1);

    $r_signee2 = $db->selectOne("SELECT * FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id = 39111");
    $sayRow("wip_t_project_closure_signee(39111)", $r_signee2);

    $r_gl1 = $db->selectOne("SELECT acct_gl_id, ad_org_id, gl_acct_id, documentno, date_gl, debit, credit FROM acct_gl WHERE acct_gl_id = 1961538");
    $sayRow("acct_gl(1961538)", $r_gl1);

    $r_gl2 = $db->selectOne("SELECT acct_gl_id, ad_org_id, gl_acct_id, gl_subacct_id, documentno, date_gl, debit, credit FROM acct_gl WHERE acct_gl_id = 1961539");
    $sayRow("acct_gl(1961539)", $r_gl2);

    $r_closure = $db->selectOne("SELECT * FROM wip_t_project_closure WHERE wip_t_project_closure_id = 20920");
    $sayRow("wip_t_project_closure(20920)", $r_closure);

    $r_loc = $db->selectOne("SELECT nvt_l_stockcard_locatorqty_id, doc_i_submod_id, documentno, qtymovement, qtybalance, date_gl FROM nvt_l_stockcard_locatorqty WHERE nvt_l_stockcard_locatorqty_id = 872620");
    $sayRow("nvt_l_stockcard_locatorqty(872620)", $r_loc);

    $r_mac = $db->selectOne("SELECT nvt_l_stockcard_mac_id, doc_i_submod_id, documentno, qty, amt, cumamt, cost FROM nvt_l_stockcard_mac WHERE nvt_l_stockcard_mac_id = 551000");
    $sayRow("nvt_l_stockcard_mac(551000)", $r_mac);

    $r_doc = $db->selectOne("SELECT * FROM acct_doc WHERE acct_doc_id = 103704833");
    $sayRow("acct_doc(103704833)", $r_doc);

    foreach ([$r_contra, $r_signee1, $r_signee2, $r_gl1, $r_gl2, $r_closure, $r_loc, $r_mac, $r_doc] as $i => $row) {
        if ($row === null) throw new \RuntimeException("Pre-check failed: expected row #$i not found");
    }

    $say("");
    $say(" PRE-CHECK 3: acct_balance rows that will be UPDATED");
    $r_bal1 = $db->selectOne("SELECT acct_balance_id, gl_acct_id, ad_org_id, doc_i_submod_id, date_gl, debit, credit FROM acct_balance WHERE acct_balance_id = 733506");
    $sayRow("acct_balance(733506) BEFORE", $r_bal1);
    if (abs((float)$r_bal1->debit - 843.90) > $TOL) throw new \RuntimeException("acct_balance.733506 debit drifted: expected 843.90, got " . $r_bal1->debit);

    $r_bal2 = $db->selectOne("SELECT acct_balance_id, gl_acct_id, ad_org_id, doc_i_submod_id, date_gl, debit, credit FROM acct_balance WHERE acct_balance_id = 733383");
    $sayRow("acct_balance(733383) BEFORE", $r_bal2);
    if (abs((float)$r_bal2->credit - 42592.42) > $TOL) throw new \RuntimeException("acct_balance.733383 credit drifted: expected 42592.42, got " . $r_bal2->credit);

    // ============================================================
    // APPLY — wrapped in transaction
    // ============================================================
    $say("");
    $say(" APPLYING (transaction)");
    $db->beginTransaction();
    try {
        $deletes = [
            ['wip_t_project_closure_contra',  "wip_t_project_closure_contra_id = 1590 AND wip_t_project_closure_id = 20920", 1],
            ['wip_t_project_closure_signee',  "wip_t_project_closure_signee_id IN (39030, 39111) AND wip_t_project_closure_id = 20920", 2],
            ['acct_gl',                       "acct_gl_id IN (1961538, 1961539) AND documentno = '$DOC'", 2],
            ['wip_t_project_closure',         "wip_t_project_closure_id = 20920 AND documentno = '$DOC'", 1],
            ['nvt_l_stockcard_locatorqty',    "nvt_l_stockcard_locatorqty_id = 872620 AND documentno = '$DOC'", 1],
            ['nvt_l_stockcard_mac',           "nvt_l_stockcard_mac_id = 551000 AND documentno = '$DOC'", 1],
            ['acct_doc',                      "acct_doc_id = 103704833", 1],
        ];
        foreach ($deletes as [$t, $w, $expected]) {
            $aff = $db->delete("DELETE FROM `$t` WHERE $w");
            $say("   DELETE $t WHERE $w  -> affected=$aff (expected $expected)");
            if ($aff !== $expected) {
                throw new \RuntimeException("DELETE on $t affected $aff (expected $expected)");
            }
        }

        // UPDATEs
        $aff1 = $db->update("UPDATE acct_balance SET debit = 421.95 WHERE acct_balance_id = 733506 AND debit = 843.90");
        $say("   UPDATE acct_balance(733506) debit 843.90 -> 421.95   affected=$aff1");
        if ($aff1 !== 1) throw new \RuntimeException("UPDATE acct_balance.733506 affected $aff1 (expected 1)");

        $aff2 = $db->update("UPDATE acct_balance SET credit = 42170.47 WHERE acct_balance_id = 733383 AND credit = 42592.42");
        $say("   UPDATE acct_balance(733383) credit 42592.42 -> 42170.47   affected=$aff2");
        if ($aff2 !== 1) throw new \RuntimeException("UPDATE acct_balance.733383 affected $aff2 (expected 1)");

        // Post-check: variance now zero
        $glAfter = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal
             JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $GL_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK: gl_amt = " . $money($glAfter) . " (expected 0.00)");
        if (abs($glAfter) > $TOL) {
            throw new \RuntimeException("Post-check failed: gl_amt is $glAfter, expected 0.00");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // ============================================================
    // VERIFY AFTER COMMIT — show updated acct_balance rows
    // ============================================================
    $say("");
    $say(" AFTER COMMIT — updated acct_balance rows:");
    $r_bal1a = $db->selectOne("SELECT acct_balance_id, debit, credit FROM acct_balance WHERE acct_balance_id = 733506");
    $sayRow("acct_balance(733506) AFTER", $r_bal1a);
    $r_bal2a = $db->selectOne("SELECT acct_balance_id, debit, credit FROM acct_balance WHERE acct_balance_id = 733383");
    $sayRow("acct_balance(733383) AFTER", $r_bal2a);

    $say("");
    $say($line);
    $say(" SUCCESS — variance closed to 0.00. Duplicate $DOC removed across 7 tables.");
    $say(" Audit query:");
    $say("   SELECT COUNT(*) FROM acct_gl WHERE documentno = '$DOC';  -- expected 0");
    $say(" Manual rollback (outside repo): scripts/pending/NLIO00469_162012_06_24_2026_rollback.php");
    $say($line);
};
