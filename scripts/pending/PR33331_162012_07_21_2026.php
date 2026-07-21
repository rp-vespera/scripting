<?php 
// scripts/pending/PR33331_162012_07_21_2026.php
// IMS-SCRIPT #13714 — Commission Payable (21136) Group-A extension: Paragas, Elena (bp 1267).
//
// PR33331 (debt 217160, +36,000) is a phantom open debt in the AGING only. The ledger already
// cleared it via NLPT0000113 (Lot Payment Transfer, submod 168; approved_forpmt 157692). Same
// defect class as Group A (a GL-side clearing that never cascaded to fin_l_debt_history) — so the
// fix is the missing aging SETTLEMENT, posted directly against PR33331 and referencing the real
// clearing document NLPT0000113.
//
// AUDIT: created=NULL ; updated='#IMS-13714'. Aging-only; GL + acct_balance untouched.
// History-only (matches Group A). Idempotent + reversible.
// Rollback: GroupA_Paragas_PR33331_162012_ROLLBACK.php

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $BP=1267; $DEBT=217160; $ACCT=21136; $ORG=162012; $TOL=0.01;
    $EXPECT_EXCESS=36000.00;
    $UPD='#IMS-13714'; $SUBMOD=168; $DOC='NLPT0000113';
    $RUN=date('Y-m-d H:i:s'); $DATE_GL=date('Y-m-d');
    $say = fn ($s) => print($s . PHP_EOL);
    $m = fn ($x) => number_format((float)$x, 2, '.', ',');
    $sub = "(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT * FROM ad_org WHERE orgcode=$ORG) AS mo ON child.lft>=mo.lft AND child.ryt<=mo.ryt)";
    $L = str_repeat('=', 92);

    $aging = fn () => (float)$db->selectOne("SELECT ROUND(IFNULL(SUM(CASE WHEN d.direction='O' THEN h.amount ELSE -h.amount END),0),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.s_bpartner_id=$BP AND d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.status='PR' AND h.status='PR'")->v;
    $ledger = fn () => (float)$db->selectOne("SELECT ROUND(IFNULL(SUM(g.credit-g.debit),0),2) v FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE s.s_bpartner_id=$BP AND g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub")->v;
    $debtNet = fn () => (float)$db->selectOne("SELECT ROUND(IFNULL(SUM(amount),0),2) v FROM fin_l_debt_history WHERE fin_l_debt_id=$DEBT AND status='PR'")->v;

    $say($L); $say(' IMS-SCRIPT #13714 — Paragas (bp 1267) PR33331 aging settlement (Group-A extension)');
    $say(' Run: '.$RUN.'   submod LPT('.$SUBMOD.')   doc='.$DOC.'   created=NULL  updated='.$UPD); $say($L);

    // IDEMPOTENCY
    $already = (int)$db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history WHERE fin_l_debt_id=$DEBT AND updated='$UPD' AND documentno='$DOC' AND doc_i_submod_id=$SUBMOD")->n;
    if ($already>0){ $say(''); $say(" NO-OP — settlement already present (updated='$UPD')."); $say($L); return; }

    // PRE-CHECK
    $d = $db->selectOne("SELECT documentno, direction, status FROM fin_l_debt WHERE fin_l_debt_id=$DEBT AND s_bpartner_id=$BP AND gl_acct_id=$ACCT");
    if (!$d || $d->documentno!=='PR33331' || $d->direction!=='O' || $d->status!=='PR') throw new \RuntimeException("debt $DEBT is not the expected PR33331/O/PR — ABORT.");
    $agB=$aging(); $glB=$ledger(); $dB=$debtNet();
    $excess=round($agB-$glB,2);
    $say(sprintf(' PRE:  aging=%s  ledger=%s  excess=%s  PR33331 history-net=%s', $m($agB),$m($glB),$m($excess),$m($dB)));
    if (abs($excess-$EXPECT_EXCESS)>$TOL) throw new \RuntimeException("excess ".$m($excess)." != expected ".$m($EXPECT_EXCESS)." — drifted, ABORT.");
    if (abs($dB-$EXPECT_EXCESS)>$TOL) throw new \RuntimeException("PR33331 history-net ".$m($dB)." != ".$m($EXPECT_EXCESS)." — ABORT.");

    $ref = $db->selectOne("SELECT doc_t_reference_number_id id FROM doc_t_reference_number WHERE documentno_pr='$DOC' OR documentno_dr LIKE '$DOC%' LIMIT 1");
    $refId = $ref->id ?? null;

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $adj = -$excess; // -36,000 settlement against PR33331
        $ok = $db->insert("INSERT INTO fin_l_debt_history
            (fin_l_debt_id, date_gl, amount, documentno, created, date_created, updated, date_updated, is_active, is_creation, is_settlement, status, ad_org_id, doc_i_submod_id, doc_t_reference_number_id)
            VALUES (?, DATE(?), ?, ?, NULL, ?, ?, ?, 1, 0, 1, 'PR', ?, ?, ?)",
            [$DEBT, $DATE_GL, $adj, $DOC, $RUN, $UPD, $RUN, $ORG, $SUBMOD, $refId]);
        $say(sprintf('    PR33331 settle=%s doc=%s submod=LPT(%s) ref=%s (%s)', $m($adj), $DOC, $SUBMOD, $refId ?? 'NULL', $ok?'ok':'FAIL'));
        if (!$ok) throw new \RuntimeException('insert failed');

        $agA=$aging(); $glA=$ledger(); $dA=$debtNet();
        $say(''); $say(' POST-CHECK:');
        $say(sprintf('   aging=%s  ledger=%s  variance=%s   PR33331 history-net=%s', $m($agA),$m($glA),$m($agA-$glA),$m($dA)));
        if (abs($agA-$glA)>$TOL) throw new \RuntimeException("Paragas did not tie (variance ".$m($agA-$glA).") — ABORT.");
        if (abs($dA)>$TOL) throw new \RuntimeException("PR33331 not fully settled (".$m($dA).") — ABORT.");
        if (abs($glA-$glB)>$TOL) throw new \RuntimeException("ledger moved — ABORT.");
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(''); $say($L);
    $say(' SUCCESS — PR33331 settled in aging (-'.$m($EXPECT_EXCESS).'); Paragas aging now ties to ledger (11,960.00).');
    $say($L);
};
