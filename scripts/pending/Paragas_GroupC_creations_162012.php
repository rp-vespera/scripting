<?php // scripts/hold/Paragas_GroupC_creations_162012.php
// IMS-SCRIPT #13714 — Paragas (bp 1267) GROUP C creation backfill — STEP 1 of 2.
// *** AGING-ONLY. Restores the 82 is_creation fin_l_debt_history rows the WEB commission engine
//     (ACR, submod 544) failed to write for Paragas. Does NOT touch acct_gl / acct_balance / module.
//
// Per missing-creation debt: insert one row is_creation=1, is_settlement=0, amount=+amt_debt,
//   date_gl = debt's original date_gl, documentno/org/ref = the debt's own. created=NULL, updated=#IMS-13714.
// Invariant (validated 82/82): history_net + amt_debt == amt_outstanding, so each debt ties to its header.
//
// RESULT: Paragas aging 34,568.72 -> 47,960.00 (variance becomes +36,000 = the pure PR33331 phantom,
//   which STEP 2 [PR33331_162012_07_21_2026.php] then settles to 0).
// Idempotent + reversible. Rollback: Paragas_GroupC_creations_162012_ROLLBACK.php
return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $UPD='#IMS-13714'; $SUBMOD=544; $ACCT=21136; $ORG=162012; $BP=1267; $TOL=0.01;
    $RUN=date('Y-m-d H:i:s');
    $EXPECT_DEBTS=82; $EXPECT_TOTAL=13391.28; $EXPECT_AGING_AFTER=47960.00; $EXPECT_PHANTOM=36000.00;
    $line=str_repeat('=',92); $say=fn($s)=>print($s.PHP_EOL); $m=fn($x)=>number_format((float)$x,2,'.',',');
    $sub="(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode=$ORG) mo ON child.lft>=mo.lft AND child.ryt<=mo.ryt)";
    $agBp = fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(h.amount),0),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.direction='O' AND d.s_bpartner_id=$BP AND h.status='PR'")->v;
    $glBp = fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(g.credit-g.debit),0),2) v FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub AND s.s_bpartner_id=$BP")->v;

    $say($line); $say(' IMS-SCRIPT #13714 — Paragas (1267) Group C creation backfill (STEP 1/2, aging-only)');
    $say(' Run: '.$RUN.'   submod 544 (ACR)   created=NULL  updated='.$UPD); $say($line);

    // IDEMPOTENCY
    $done=(int)$db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history h JOIN fin_l_debt d ON d.fin_l_debt_id=h.fin_l_debt_id
        WHERE h.updated='$UPD' AND h.is_creation=1 AND h.doc_i_submod_id=$SUBMOD AND d.gl_acct_id=$ACCT AND d.s_bpartner_id=$BP AND d.ad_org_id IN $sub")->n;
    if($done>0){ $say(''); $say(" NO-OP — $done creation row(s) already stamped $UPD for Paragas."); $say($line); return; }

    $rows=$db->select("
        SELECT d.fin_l_debt_id id, d.documentno docno, d.date_gl date_gl, d.ad_org_id org,
               d.doc_t_reference_number_id refid, ROUND(d.amt_debt,2) amt_debt, ROUND(d.amt_outstanding,2) outv,
               ROUND(COALESCE((SELECT SUM(x.amount) FROM fin_l_debt_history x WHERE x.fin_l_debt_id=d.fin_l_debt_id),0),2) hist_net
        FROM fin_l_debt d
        WHERE d.gl_acct_id=$ACCT AND d.doc_i_submod_id=$SUBMOD AND d.s_bpartner_id=$BP AND d.ad_org_id IN $sub AND d.amt_debt>0
          AND NOT EXISTS (SELECT 1 FROM fin_l_debt_history c WHERE c.fin_l_debt_id=d.fin_l_debt_id AND c.is_creation=1)");

    $total=0.0; foreach($rows as $r){ $total+=(float)$r->amt_debt;
        if(abs(($r->hist_net + $r->amt_debt) - $r->outv) > $TOL) throw new \RuntimeException("debt {$r->id}: +amt_debt would not tie to outstanding — ABORT.");
    }
    $agB=$agBp(); $glB=$glBp();
    $say(sprintf(' PRE: debts=%d total=%s (expect %d / %s)   aging=%s journal=%s var=%s',
        count($rows),$m($total),$EXPECT_DEBTS,$m($EXPECT_TOTAL),$m($agB),$m($glB),$m($agB-$glB)));
    if(count($rows)!==$EXPECT_DEBTS) throw new \RuntimeException('debt count drifted ('.count($rows).') — ABORT & re-validate.');
    if(abs($total-$EXPECT_TOTAL)>$TOL) throw new \RuntimeException('total drifted ('.$m($total).') — ABORT & re-validate.');

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $ins=0;$grand=0.0;
        foreach($rows as $r){
            $ok=$db->insert("INSERT INTO fin_l_debt_history
                (fin_l_debt_id, date_gl, amount, documentno, created, date_created, updated, date_updated,
                 is_active, is_creation, is_settlement, status, ad_org_id, doc_i_submod_id, doc_t_reference_number_id)
                VALUES (?, DATE(?), ?, ?, NULL, ?, ?, ?, 1, 1, 0, 'PR', ?, $SUBMOD, ?)",
                [$r->id, $r->date_gl, $r->amt_debt, $r->docno, $RUN, $UPD, $RUN, $r->org, $r->refid]);
            if(!$ok) throw new \RuntimeException("insert failed for debt {$r->id}");
            $ins++; $grand+=(float)$r->amt_debt;
            $net=(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(amount),0),2) v FROM fin_l_debt_history WHERE fin_l_debt_id={$r->id}")->v;
            if(abs($net-$r->outv)>$TOL) throw new \RuntimeException("debt {$r->id} post-net ".$m($net)." != outstanding ".$m($r->outv)." — ABORT.");
        }
        if($ins!==$EXPECT_DEBTS || abs($grand-$EXPECT_TOTAL)>$TOL) throw new \RuntimeException("inserted $ins / ".$m($grand)." != expected — ABORT.");
        $agA=$agBp(); $glA=$glBp();
        $say(sprintf('   inserted %d creations (+%s).  aging %s -> %s  journal=%s',$ins,$m($grand),$m($agB),$m($agA),$m($glA)));
        if(abs($agA-$EXPECT_AGING_AFTER)>$TOL) throw new \RuntimeException("aging landed ".$m($agA)." != ".$m($EXPECT_AGING_AFTER)." — ABORT.");
        if(abs(($agA-$glA)-$EXPECT_PHANTOM)>$TOL) throw new \RuntimeException("residual ".$m($agA-$glA)." != phantom ".$m($EXPECT_PHANTOM)." — ABORT.");
        $db->commit();
    } catch(\Throwable $e){ $db->rollBack(); throw $e; }

    $say(''); $say($line);
    $say(' SUCCESS — Paragas creations restored (+'.$m($EXPECT_TOTAL).'). Aging now 47,960; remaining +36,000 = PR33331 phantom.');
    $say(' NEXT: run STEP 2  scripts/pending/PR33331_162012_07_21_2026.php  (settles -36,000 -> aging 11,960 = journal).');
    $say(' Rollback: Paragas_GroupC_creations_162012_ROLLBACK.php');
    $say($line);
};
