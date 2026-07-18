<?php // scripts/pending/CP21136_162012_07_17_2026.php
// IMS-SCRIPT #13714 — Commission Payable (21136) Group-A aging reconciliation, RP Tan A (org 162012)
// *** LSPCA-STYLE CANCELLATION DOCUMENT + FULL AUDIT STAMPS *** (no NULL doc refs, no NULL updated).
//
// This posts the debt-side cancellation that the LSPCA (Lot Sales Payment Cancellation) run FAILED to
// post — the exact defect in MpTLotSalesCancellationService (GL reversed, fin_l_debt did not). So it is
// filed as an LSPCA cancellation, matching the ERP-native pattern:
//     submodule           = LSPCA (doc_i_submod_id 164, is_contra=1)  — same type used by the 243 real
//                           commission cancellations already on this account.
//     documentno          = <the anchor debt's own documentno> + '-CA'   (the ERP cancellation convention,
//                           e.g. PR-I004842 -> PR-I004842-CA), created per agent.
// Every row carries a real doc_i_submod_id + doc_t_reference_number_id AND full audit stamps:
//     created / updated          = 'SCRIPT-WEB'   (this value is what distinguishes our rows from the
//                                  genuine -CA cancellations — no real cancellation uses it)
//     date_created / date_updated = the run timestamp (when the script was executed)
//
//   3341 CHIU, VALENTIN S.   -20,720.00
//   7925 EMP-SALAZAR, RHEA   -12,800.00
//   1052 PADRONES, ANGEL REX  -2,640.00      GROUP A TOTAL = -36,160.00
//
// Guards PER AGENT (each excess must equal its known value) — NOT on the volatile account total
// (the WEB Commission tool churns other agents live). GL / acct_balance NOT touched. Groups B/C
// NOT touched. Idempotent (keyed on created='SCRIPT-WEB' + submodule LSPCA for these agents).
// Rollback: CP21136_162012_07_17_2026_ROLLBACK.php

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $AUDIT   = 'SCRIPT-WEB';             // created / updated audit user — also the rollback discriminator
    $SUBMOD  = 164;                      // LSPCA  (Lot Sales Payment Cancellation, is_contra=1)
    $CA      = '-CA';                    // ERP cancellation documentno suffix
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
    $bpList = implode(',', array_keys($TARGETS));

    $sub = "(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT * FROM ad_org WHERE orgcode=$ORG) AS mother ON child.lft>=mother.lft AND child.ryt<=mother.ryt)";

    $agedOf   = fn (int $bp) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(his.amount),0),2) v FROM fin_l_debt debt JOIN fin_l_debt_history his ON his.fin_l_debt_id=debt.fin_l_debt_id WHERE debt.ad_org_id IN $sub AND debt.status='PR' AND his.status='PR' AND his.date_gl<=DATE('$DATE_GL') AND debt.gl_acct_id=$ACCT AND debt.direction='O' AND debt.s_bpartner_id=$bp")->v;
    $ledgerOf = fn (int $bp) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(bal.debit-bal.credit)*-1,0),2) v FROM acct_balance bal JOIN gl_subacct s2 ON s2.gl_subacct_id=bal.gl_subacct_id WHERE bal.ad_org_id IN $sub AND bal.date_gl<=DATE('$DATE_GL') AND bal.gl_acct_id=$ACCT AND s2.s_bpartner_id=$bp")->v;

    $say($line);
    $say(' IMS-SCRIPT #13714 — Commission Payable (21136) Group-A reconciliation (LSPCA cancellation + audit stamps)');
    $say(' Run: ' . $RUN . '   Submodule: LSPCA(' . $SUBMOD . ')   Doc#: <anchor documentno>' . $CA . '   Audit: ' . $AUDIT);
    $say($line);

    // IDEMPOTENCY — have we already posted our LSPCA rows (created='SCRIPT-WEB', submod LSPCA) for these agents?
    $already = (int) $db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history his JOIN fin_l_debt debt ON debt.fin_l_debt_id=his.fin_l_debt_id WHERE his.created='$AUDIT' AND his.doc_i_submod_id=$SUBMOD AND debt.gl_acct_id=$ACCT AND debt.ad_org_id IN $sub AND debt.s_bpartner_id IN ($bpList)")->n;
    if ($already > 0) { $say(''); $say(" NO-OP — $already SCRIPT-WEB LSPCA row(s) already exist for these agents. Nothing to do."); $say($line); return; }

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
        $grand = 0.0;
        foreach ($TARGETS as $bp => $expected) {
            $name   = $db->selectOne("SELECT name1 FROM s_bpartner WHERE s_bpartner_id=$bp")->name1 ?? "($bp)";
            $excess = round($agedOf($bp) - $ledgerOf($bp), 2);
            if (abs($excess - $expected) > $TOL) throw new \RuntimeException("bp $bp $name drifted mid-run — ABORT.");

            // Anchor = the agent's latest outstanding commission debt; its documentno seeds the -CA number.
            // Prefer a real lot-sales payment (OR-LSP) debt so the -CA reads as a genuine payment
            // cancellation (PR-I...-CA); fall back to the newest O debt only if the agent has no OR-LSP.
            $anchor = $db->selectOne("SELECT debt.fin_l_debt_id id, debt.documentno docno FROM fin_l_debt debt LEFT JOIN doc_i_submod sm ON sm.doc_i_submod_id=debt.doc_i_submod_id WHERE debt.ad_org_id IN $sub AND debt.status='PR' AND debt.gl_acct_id=$ACCT AND debt.direction='O' AND debt.s_bpartner_id=$bp ORDER BY (sm.submodule_code='OR-LSP') DESC, debt.fin_l_debt_id DESC LIMIT 1");
            if (!$anchor) throw new \RuntimeException("bp $bp $name — no anchor debt row, ABORT.");
            $docno = $anchor->docno . $CA;                 // ERP cancellation convention: <original>-CA

            // Safety: never collide with (overwrite the meaning of) a genuine cancellation of this documentno.
            $clash = (int) $db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history WHERE documentno='$docno' AND (created IS NULL OR created<>'$AUDIT')")->n;
            if ($clash > 0) throw new \RuntimeException("bp $bp $name — a real cancellation '$docno' already exists — ABORT (choose a different anchor).");

            $adj = -$excess;
            $grand += $excess;

            // Real LSPCA cancellation reference (nothing posts to the GL) — with full audit stamps.
            $refId = (int) $db->table('doc_t_reference_number')->insertGetId([
                'doc_i_submod_id' => $SUBMOD,
                'documentno_dr'   => $docno,
                'date_draft'      => $DATE_GL,
                'documentno_pr'   => $docno,
                'date_process'    => $DATE_GL,
                'ad_org_id'       => $ORG,
                'created'         => $AUDIT,
                'date_created'    => $RUN,
                'updated'         => $AUDIT,
                'date_updated'    => $RUN,
                'is_active'       => 1,
            ]);

            // Settlement row, LINKED to the LSPCA reference — with full audit stamps.
            $ok = $db->insert(
                "INSERT INTO fin_l_debt_history
                   (fin_l_debt_id, date_gl, amount, documentno, created, date_created, updated, date_updated, is_active, is_creation, is_settlement, status, ad_org_id, doc_i_submod_id, doc_t_reference_number_id)
                 VALUES (?, DATE(?), ?, ?, ?, ?, ?, ?, 1, 0, 1, 'PR', ?, ?, ?)",
                [(int) $anchor->id, $DATE_GL, $adj, $docno, $AUDIT, $RUN, $AUDIT, $RUN, $ORG, $SUBMOD, $refId]
            );
            $say(sprintf('    bp=%-6s %-22s settle=%-11s doc=%-16s submod=LSPCA(%s) ref=%s (%s)', $bp, $name, $money($adj), $docno, $SUBMOD, $refId, $ok ? 'ok' : 'FAIL'));
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
    $say(' SUCCESS — Group A (-' . $money($EXPECTED_TOTAL) . ') reconciled via LSPCA cancellation documents (<anchor>' . $CA . ').');
    $say(' Rows carry doc_i_submod_id=' . $SUBMOD . ' (LSPCA), a real doc_t_reference_number, and audit created/updated=' . $AUDIT . ' @ ' . $RUN . '.');
    $say(' GL / acct_balance untouched. Rollback: CP21136_162012_07_17_2026_ROLLBACK.php');
    $say($line);
};
