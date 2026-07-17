<?php // scripts/pending/CP21136_162012_07_17_2026.php
// IMS-SCRIPT #13714 — Commission Payable (21136) Group-A aging reconciliation, RP Tan A (org 162012)
// *** WITH A REAL ADJUSTMENT DOCUMENT + FULL AUDIT STAMPS *** (no NULL doc refs, no NULL updated).
//
// Creates ONE real adjustment reference in doc_t_reference_number (submodule GJL-CP = 413 — the
// document type used for the manual commission reversals), documentno NADJCP0013714, then posts
// the 3 Group-A settlement rows into fin_l_debt_history LINKED to that reference. Every row carries
// real doc_i_submod_id + doc_t_reference_number_id AND full audit stamps:
//     created / updated      = 'SCRIPT-WEB'
//     date_created / date_updated = the run timestamp (when the script was executed)
//
//   3341 CHIU, VALENTIN S.   -20,720.00
//   7925 EMP-SALAZAR, RHEA   -12,800.00
//   1052 PADRONES, ANGEL REX  -2,640.00      GROUP A TOTAL = -36,160.00
//
// Guards PER AGENT (each excess must equal its known value) — NOT on the volatile account total
// (the WEB Commission tool churns other agents live). GL / acct_balance NOT touched. Groups B/C
// NOT touched. Idempotent (keyed on the unique documentno NADJCP0013714).
// Rollback: CP21136_162012_07_17_2026_ROLLBACK.php

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $AUDIT   = 'SCRIPT-WEB';             // created / updated audit user
    $DOCNO   = 'NADJCP0013714';          // unique adjustment doc no. — also the rollback/idempotency key
    $SUBMOD  = 413;                      // GJL-CP  (General Journal - Commission Payable)
    $RUN     = date('Y-m-d H:i:s');      // when the script was run → date_created / date_updated
    $DATE_GL = date('Y-m-d');
    $ORG     = 162012;
    $ACCT    = 21136;
    $TOL     = 0.01;
    // Group A — agent => expected excess (aging - ledger). Stable/frozen; used as the per-agent guard.
    $TARGETS = [3341 => 20720.00, 7925 => 12800.00, 1052 => 2640.00];
    $EXPECTED_TOTAL = 36160.00;

    $line  = str_repeat('=', 92);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $sub = "(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT * FROM ad_org WHERE orgcode=$ORG) AS mother ON child.lft>=mother.lft AND child.ryt<=mother.ryt)";

    $agedOf   = fn (int $bp) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(his.amount),0),2) v FROM fin_l_debt debt JOIN fin_l_debt_history his ON his.fin_l_debt_id=debt.fin_l_debt_id WHERE debt.ad_org_id IN $sub AND debt.status='PR' AND his.status='PR' AND his.date_gl<=DATE('$DATE_GL') AND debt.gl_acct_id=$ACCT AND debt.direction='O' AND debt.s_bpartner_id=$bp")->v;
    $ledgerOf = fn (int $bp) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(bal.debit-bal.credit)*-1,0),2) v FROM acct_balance bal JOIN gl_subacct s2 ON s2.gl_subacct_id=bal.gl_subacct_id WHERE bal.ad_org_id IN $sub AND bal.date_gl<=DATE('$DATE_GL') AND bal.gl_acct_id=$ACCT AND s2.s_bpartner_id=$bp")->v;

    $say($line);
    $say(' IMS-SCRIPT #13714 — Commission Payable (21136) Group-A reconciliation (real document + audit stamps)');
    $say(' Run: ' . $RUN . '   Doc#: ' . $DOCNO . '   Submodule: GJL-CP(' . $SUBMOD . ')   Audit: ' . $AUDIT);
    $say($line);

    // IDEMPOTENCY — keyed on the unique documentno
    $already = (int) $db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history WHERE documentno='$DOCNO'")->n;
    if ($already > 0) { $say(''); $say(" NO-OP — $already row(s) already carry documentno '$DOCNO'. Nothing to do."); $say($line); return; }

    $say(''); $say(' PRE-CHECK (per agent — account total intentionally ignored, it is volatile):');
    foreach ($TARGETS as $bp => $expected) {
        $ex = round($agedOf($bp) - $ledgerOf($bp), 2);
        $say(sprintf('   bp=%-6s aging=%-11s ledger=%-11s excess=%-9s expected=%s', $bp, $money($agedOf($bp)), $money($ledgerOf($bp)), $money($ex), $money($expected)));
        if (abs($ex - $expected) > $TOL) {
            throw new \RuntimeException("bp $bp excess " . $money($ex) . " != expected " . $money($expected) . " (this Group-A agent drifted) — ABORT, review before running.");
        }
    }

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        // 1) Real adjustment reference document (nothing posts to the GL) — with full audit stamps.
        $refId = (int) $db->table('doc_t_reference_number')->insertGetId([
            'doc_i_submod_id' => $SUBMOD,
            'documentno_dr'   => $DOCNO,
            'date_draft'      => $DATE_GL,
            'documentno_pr'   => $DOCNO,
            'date_process'    => $DATE_GL,
            'ad_org_id'       => $ORG,
            'created'         => $AUDIT,
            'date_created'    => $RUN,
            'updated'         => $AUDIT,
            'date_updated'    => $RUN,
            'is_active'       => 1,
        ]);
        $say('   Created adjustment document: ref_id=' . $refId . '  documentno=' . $DOCNO . '  (GJL-CP)  audit=' . $AUDIT . ' @ ' . $RUN);
        $say('');

        // 2) Settlement rows, each LINKED to the document — with full audit stamps.
        $grand = 0.0;
        foreach ($TARGETS as $bp => $expected) {
            $name   = $db->selectOne("SELECT name1 FROM s_bpartner WHERE s_bpartner_id=$bp")->name1 ?? "($bp)";
            $excess = round($agedOf($bp) - $ledgerOf($bp), 2);
            if (abs($excess - $expected) > $TOL) throw new \RuntimeException("bp $bp $name drifted mid-run — ABORT.");

            $anchor = $db->selectOne("SELECT debt.fin_l_debt_id id FROM fin_l_debt debt WHERE debt.ad_org_id IN $sub AND debt.status='PR' AND debt.gl_acct_id=$ACCT AND debt.direction='O' AND debt.s_bpartner_id=$bp ORDER BY debt.fin_l_debt_id DESC LIMIT 1");
            if (!$anchor) throw new \RuntimeException("bp $bp $name — no anchor debt row, ABORT.");
            $adj = -$excess;
            $grand += $excess;

            $ok = $db->insert(
                "INSERT INTO fin_l_debt_history
                   (fin_l_debt_id, date_gl, amount, documentno, created, date_created, updated, date_updated, is_active, is_creation, is_settlement, status, ad_org_id, doc_i_submod_id, doc_t_reference_number_id)
                 VALUES (?, DATE(?), ?, ?, ?, ?, ?, ?, 1, 0, 1, 'PR', ?, ?, ?)",
                [(int) $anchor->id, $DATE_GL, $adj, $DOCNO, $AUDIT, $RUN, $AUDIT, $RUN, $ORG, $SUBMOD, $refId]
            );
            $say(sprintf('    bp=%-6s %-22s settle=%-11s submod=%s ref=%s (%s)', $bp, $name, $money($adj), $SUBMOD, $refId, $ok ? 'ok' : 'FAIL'));
            if (!$ok) throw new \RuntimeException("insert failed for bp $bp");
        }

        if (abs($grand - $EXPECTED_TOTAL) > $TOL) throw new \RuntimeException("Group A total " . $money($grand) . " != expected " . $money($EXPECTED_TOTAL) . ", ABORT.");

        // POST-CHECK (per agent — robust to web-tool churn on other agents): each target now ties.
        $say(''); $say(' POST-CHECK (each Group-A agent should now tie: aging == ledger):');
        foreach ($TARGETS as $bp => $expected) {
            $res = round($agedOf($bp) - $ledgerOf($bp), 2);
            $say(sprintf('   bp=%-6s aging-ledger = %s', $bp, $money($res)));
            if (abs($res) > $TOL) throw new \RuntimeException("bp $bp did not tie after fix (residual " . $money($res) . ") — ABORT.");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — Group A (-' . $money($EXPECTED_TOTAL) . ') reconciled via real document ' . $DOCNO . ' (GJL-CP).');
    $say(' Rows carry doc_i_submod_id=' . $SUBMOD . ', a real doc_t_reference_number, and audit created/updated=' . $AUDIT . ' @ ' . $RUN . '.');
    $say(' GL / acct_balance untouched. Rollback: CP21136_162012_07_17_2026_ROLLBACK.php');
    $say($line);
};
