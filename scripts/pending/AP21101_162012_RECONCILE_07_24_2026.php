<?php // scripts/pending/AP21101_162012_reconcile_full_07_24_2026.php
// ============================================================================
// ACCOUNTS PAYABLE (21101) — cache + aging reconciliation IN ONE, RP Tan A (org 162012).
// Three parts (A cache, B guarded aging backfill, C residual alignment), one transaction.
// IDEMPOTENT + REVERSIBLE. DERIVED-STORE ONLY — acct_gl (general ledger) is NEVER touched.
// (SABAY -40 is handled separately by a manual GL JV; it is NOT in this script.)
// ============================================================================
//
//   PART A — CACHE  : one acct_balance correcting row per subaccount so cache = journal.
//   PART B — AGING  : guarded per-debt is_creation backfill (invariant gate).
//   PART C — RESIDUAL: align any remaining non-SABAY subaccount whose aging still != journal
//                      (per-account cap 2,000).
//
// FINAL: cache = journal AND aging = journal (report variance 0.00 given SABAY already
//        cleared by JV), or the whole transaction rolls back.
// Rollback: AP21101_162012_reconcile_full_07_24_2026_ROLLBACK.php

return function ($cmd) {
    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $S1='SCRIPT-WEB-2026-07-24'; $S2='SCRIPT-WEB-2026-07-24-AP220';
    $ACCT=21101; $ORG=162012; $SABAY=15996; $TOL=0.01; $CAP=2000.00;
    $RUN=date('Y-m-d H:i:s'); $DATE_GL=date('Y-m-d');
    $L=str_repeat('=',96);
    $say=fn($s)=>print($s.PHP_EOL); $m=fn($x)=>number_format((float)$x,2,'.',',');
    $sub="(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode=$ORG) AS mother ON child.lft>=mother.lft AND child.ryt<=mother.ryt)";

    $glTot =fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(credit-debit),0),2) v FROM acct_gl      WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub")->v;
    $balTot=fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(credit-debit),0),2) v FROM acct_balance WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub")->v;
    $ageTot=fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(CASE WHEN d.direction='O' THEN h.amount ELSE -h.amount END),0),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.status='PR' AND h.status='PR'")->v;

    $say($L); $say(' AP 21101 RECONCILE (cache + aging + residual, one pass) — org '.$ORG.'  Run '.$RUN);
    $say(' DERIVED-STORE ONLY. acct_gl NEVER touched. SABAY handled by separate JV.'); $say($L);
    $glB=$glTot(); $say(sprintf(' BEFORE:  journal=%-13s cache=%-13s aging=%s', $m($glB), $m($balTot()), $m($ageTot())));
    if ($glB < 100000) throw new \RuntimeException('journal '.$m($glB).' implausibly low — wrong DB/state, ABORT.');

    $db->beginTransaction();
    try {
        // ---------- PART A + B (stamp S1) ----------
        if ((int)$db->selectOne("SELECT COUNT(*) n FROM acct_balance WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub AND updated='$S1'")->n > 0) {
            $say(''); $say('  PART A+B : NO-OP (already stamped '.$S1.')');
        } else {
            $jrows=$db->select("SELECT s.gl_subacct_id sa, s.s_bpartner_id bp, ROUND(SUM(g.credit-g.debit),2) jr FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub GROUP BY s.gl_subacct_id, s.s_bpartner_id");
            $cmap=[]; foreach($db->select("SELECT gl_subacct_id sa, ROUND(SUM(credit-debit),2) c FROM acct_balance WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub GROUP BY gl_subacct_id") as $r) $cmap[(int)$r->sa]=(float)$r->c;
            $cIns=0;$cNet=0.0;
            foreach($jrows as $r){ if((int)$r->bp===$SABAY) continue; $diff=round((float)$r->jr-($cmap[(int)$r->sa]??0.0),2); if(abs($diff)<=$TOL) continue;
                $cr=$diff>0?$diff:0.0; $dr=$diff<0?-$diff:0.0;
                $db->insert("INSERT INTO acct_balance (gl_acct_id,date_gl,ad_org_id,created,date_created,updated,date_updated,is_active,debit,credit,gl_subacct_id,gl_subgroup_id,term_days,doc_i_submod_id) VALUES ($ACCT,DATE(?),?,NULL,?,?,?,1,?,?,?,NULL,NULL,NULL)",[$DATE_GL,$ORG,$RUN,$S1,$RUN,$dr,$cr,$r->sa]);
                $cIns++; $cNet=round($cNet+$diff,2); }
            $debts=$db->select("SELECT d.fin_l_debt_id id, d.documentno docno, d.date_gl dg, d.ad_org_id org, d.doc_i_submod_id sm, d.doc_t_reference_number_id ref, ROUND(d.amt_debt,2) amt, ROUND(d.amt_outstanding,2) outv, ROUND(COALESCE((SELECT SUM(x.amount) FROM fin_l_debt_history x WHERE x.fin_l_debt_id=d.fin_l_debt_id AND x.status='PR'),0),2) hn FROM fin_l_debt d WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.status='PR' AND d.direction='O' AND d.amt_debt>0 AND d.s_bpartner_id<>$SABAY AND NOT EXISTS (SELECT 1 FROM fin_l_debt_history c WHERE c.fin_l_debt_id=d.fin_l_debt_id AND c.is_creation=1)");
            $aIns=0;$aTot=0.0;$aSkip=0;
            foreach($debts as $d){ if(abs(($d->hn+$d->amt)-$d->outv)>$TOL){ $aSkip++; continue; }
                $db->insert("INSERT INTO fin_l_debt_history (fin_l_debt_id,date_gl,amount,documentno,created,date_created,updated,date_updated,is_active,is_creation,is_settlement,status,ad_org_id,doc_i_submod_id,doc_t_reference_number_id) VALUES (?,DATE(?),?,?,NULL,?,?,?,1,1,0,'PR',?,?,?)",[(int)$d->id,$d->dg,$d->amt,$d->docno,$RUN,$S1,$RUN,$d->org,$d->sm,$d->ref]);
                $aIns++; $aTot=round($aTot+$d->amt,2); }
            $say(''); $say('  PART A CACHE : '.$cIns.' rows ('.$m($cNet).')   PART B AGING : '.$aIns.' rows (+'.$m($aTot).'), '.$aSkip.' skipped');
        }

        // ---------- PART C (stamp S2): residual aging alignment ----------
        if ((int)$db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history WHERE updated='$S2'")->n > 0) {
            $say('  PART C   : NO-OP (already stamped '.$S2.')');
        } else {
            $j=$db->select("SELECT s.s_bpartner_id bp, ROUND(SUM(g.credit-g.debit),2) v FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub GROUP BY s.s_bpartner_id");
            $a=$db->select("SELECT d.s_bpartner_id bp, ROUND(SUM(CASE WHEN d.direction='O' THEN h.amount ELSE -h.amount END),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.status='PR' AND h.status='PR' GROUP BY d.s_bpartner_id");
            $J=[];$A=[]; foreach($j as $r)$J[(int)$r->bp]=(float)$r->v; foreach($a as $r)$A[(int)$r->bp]=(float)$r->v;
            $tgt=[]; foreach(array_unique(array_merge(array_keys($J),array_keys($A))) as $bp){ if($bp==$SABAY||$bp===0) continue; $d=round(($J[$bp]??0)-($A[$bp]??0),2); if(abs($d)>$TOL)$tgt[$bp]=$d; }
            $cNum=0;$cTot=0.0;
            foreach($tgt as $bp=>$diff){ if(abs($diff)>$CAP) throw new \RuntimeException("Part C bp $bp adj ".$m($diff)." > cap ".$m($CAP)." — ABORT for review.");
                $anc=$db->selectOne("SELECT fin_l_debt_id id, documentno docno, doc_i_submod_id sm, ad_org_id org, doc_t_reference_number_id ref FROM fin_l_debt WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub AND s_bpartner_id=$bp AND status='PR' AND direction='O' ORDER BY fin_l_debt_id DESC LIMIT 1");
                if(!$anc) throw new \RuntimeException("Part C bp $bp no anchor debt — ABORT.");
                $db->insert("INSERT INTO fin_l_debt_history (fin_l_debt_id,date_gl,amount,documentno,created,date_created,updated,date_updated,is_active,is_creation,is_settlement,status,ad_org_id,doc_i_submod_id,doc_t_reference_number_id) VALUES (?,DATE(?),?,?,NULL,?,?,?,1,?,?,'PR',?,?,?)",[(int)$anc->id,$DATE_GL,$diff,$anc->docno,$RUN,$S2,$RUN,$diff>0?1:0,$diff<0?1:0,$anc->org,$anc->sm,$anc->ref]);
                $cNum++; $cTot=round($cTot+$diff,2); }
            $say('  PART C RESID : '.$cNum.' rows ('.$m($cTot).')');
        }

        // ---------- FINAL POST-CHECK ----------
        $gl=$glTot(); $bal=$balTot(); $age=$ageTot();
        $say(''); $say(sprintf(' AFTER :   journal=%-13s cache=%-13s aging=%s', $m($gl),$m($bal),$m($age)));
        if (abs($gl-$bal)>$TOL) throw new \RuntimeException('post-check: cache '.$m($bal).' != journal '.$m($gl).' — ABORT.');
        if (abs($gl-$age)>$TOL) throw new \RuntimeException('post-check: aging '.$m($age).' != journal '.$m($gl).' — (is SABAY JV posted?) ABORT.');
        if (abs($gl-$glB)>$TOL) throw new \RuntimeException('post-check: journal moved — acct_gl must NOT change — ABORT.');
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(''); $say($L);
    $say(' SUCCESS — cache = journal AND aging = journal. AP 21101 report variance = 0.00.');
    $say(' (acct_gl untouched. Abnormal -34,560 UNKNOWN BANK PAYMENT is separate/intentional.)');
    $say(' Rollback: AP21101_162012_reconcile_full_07_24_2026_ROLLBACK.php');
    $say($L);
    if (isset($cmd)) $cmd->info('AP 21101 reconciled (cache+aging+residual) to 0.00.');
};
