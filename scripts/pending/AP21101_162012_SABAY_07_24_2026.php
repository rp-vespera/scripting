<?php // scripts/pending/AP21101_162012_SABAY_07_24_2026.php
// ============================================================================
// SABAY, ROSARIO B. (bp 15996) — AP 21101, RP Tan A (org 162012).
// SCRIPTED NATIVE-STYLE GENERAL JOURNAL + AGING REMOVAL, all three books, one pass.
//
// *** POSTS TO THE GENERAL LEDGER (acct_gl) — Type 2, requires accounting sign-off. ***
//
// Replaces the manual JV NJV-ADJ0000028 (which must be REVERSED in the module first).
// A manual/native JV corrects the ledger but ALSO auto-creates a +40 aging row it never
// settles; a SCRIPTED GJ does NOT touch aging, so this script drives aging to 0 itself.
//
// Writes, exactly like the approved Group-B pattern:
//   doc_i_stub   -> allocate one NJV #### (PR stub 268) + NJV####DR (DR stub 267), bump currentno
//   acct_doc     -> visible Description (native wording; ticket tag lives in `updated`)
//   doc_t_reference_number -> documentno_pr / _dr
//   acct_gl      -> CR 21101 (SABAY subacct) + DR 42001 Other Income  (balanced)
//   acct_balance -> cache mirror of both legs  (+ a correcting row if cache had drift)
//   fin_l_debt_history -> settlement row so SABAY AGING = 0
//
// SELF-MEASURING: brings journal/cache/aging each to 0 by whatever delta exists.
// If journal is already 0 (JV not yet reversed) the GL leg is SKIPPED (no double-post).
// Guarded (100.00 cap), idempotent (stamp), single transaction, reversible.
// AUDIT: created=NULL ; updated='#IMS-SABAY-21101'.  Rollback: ..._ROLLBACK.php
// ============================================================================
return function ($cmd) {
    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $UPD='#IMS-SABAY-21101'; $SUBMOD=4;                 // GJL — General Journal
    $ACCT=21101; $OFFSET=42001;                         // DR Other Income (write-off of neg payable)
    $ORG=162012; $SABAY=15996; $PR_STUB=268; $DR_STUB=267;
    $TOL=0.01; $CAP=100.00; $RUN=date('Y-m-d H:i:s'); $DATE_GL=date('Y-m-d');
    $L=str_repeat('=',96);
    $say=fn($s)=>print($s.PHP_EOL); $m=fn($x)=>number_format((float)$x,2,'.',',');
    $fmt=fn($p,$n,$s,$len)=>$p.str_pad((string)$n,$len,'0',STR_PAD_LEFT).$s;
    $sub="(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode=$ORG) AS mother ON child.lft>=mother.lft AND child.ryt<=mother.ryt)";

    $sgl =fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(g.credit-g.debit),0),2) v FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub AND s.s_bpartner_id=$SABAY")->v;
    $sbal=fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(b.credit-b.debit),0),2) v FROM acct_balance b JOIN gl_subacct s ON s.gl_subacct_id=b.gl_subacct_id WHERE b.gl_acct_id=$ACCT AND b.ad_org_id IN $sub AND s.s_bpartner_id=$SABAY")->v;
    $sage=fn()=>(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(CASE WHEN d.direction='O' THEN h.amount ELSE -h.amount END),0),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.s_bpartner_id=$SABAY AND d.status='PR' AND h.status='PR'")->v;

    $say($L); $say(' SABAY (bp '.$SABAY.') — scripted GJ + aging removal on AP 21101. Run '.$RUN);
    $say(' *** POSTS TO GENERAL LEDGER — Type 2, needs sign-off. Reverse NJV-ADJ0000028 first. ***');
    $say($L);

    // resolve SABAY's subaccount on 21101 (from existing history)
    $saRow=$db->selectOne("SELECT g.gl_subacct_id sa FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub AND s.s_bpartner_id=$SABAY ORDER BY g.acct_gl_id DESC LIMIT 1");
    if(!$saRow){ $saRow=$db->selectOne("SELECT gl_subacct_id sa FROM gl_subacct WHERE s_bpartner_id=$SABAY ORDER BY gl_subacct_id DESC LIMIT 1"); }
    if(!$saRow) throw new \RuntimeException('SABAY subaccount not found on 21101 — ABORT.');
    $SA=(int)$saRow->sa;

    $j0=$sgl(); $b0=$sbal(); $a0=$sage();
    $say(sprintf(' BEFORE:  journal=%-10s cache=%-10s aging=%s   (subacct %d)', $m($j0),$m($b0),$m($a0),$SA));

    // idempotency
    if((int)$db->selectOne("SELECT COUNT(*) n FROM acct_gl WHERE updated='$UPD' AND doc_i_submod_id=$SUBMOD AND ad_org_id IN $sub")->n>0){
        $say(''); $say(' NO-OP — already stamped '.$UPD.'.'); $say($L); return;
    }

    // deltas needed to reach 0 in each book
    $glDiff  = round(0 - $j0, 2);   // CR to AP (positive) to lift a negative payable to 0
    $ageDiff = round(0 - $a0, 2);   // aging settlement/adjust
    foreach(['journal'=>$glDiff,'aging'=>$ageDiff] as $k=>$v)
        if(abs($v)>$CAP) throw new \RuntimeException("SABAY $k adj ".$m($v)." > cap ".$m($CAP)." — ABORT for review.");

    $db->beginTransaction();
    try {
        $glPosted=false;
        if (abs($glDiff) > $TOL) {
            // ---- native-style General Journal (only if the ledger still needs correcting) ----
            $prStub=$db->selectOne("SELECT currentno,prefix,suffix,length FROM doc_i_stub WHERE doc_i_stub_id=$PR_STUB");
            $drStub=$db->selectOne("SELECT currentno,prefix,suffix,length FROM doc_i_stub WHERE doc_i_stub_id=$DR_STUB");
            if(!$prStub||!$drStub) throw new \RuntimeException('GJL doc_i_stub not found — ABORT.');
            $docPr=$fmt($prStub->prefix,(int)$prStub->currentno,$prStub->suffix,(int)$prStub->length);
            $docDr=$fmt($drStub->prefix,(int)$drStub->currentno,$drStub->suffix,(int)$drStub->length);
            $explain='To write off the negative accounts payable balance of Sabay, Rosario B. at RP A 162012, '
                    .'arising from a 2023 bank payment (NBNP0003109) that exceeded the recorded payable by '.$m($glDiff).'.';

            $acctDocId=(int)$db->table('acct_doc')->insertGetId([
                'reference'=>'Approved pre-entry','date_reference'=>$DATE_GL,'explanation'=>$explain,
                'ad_org_id'=>$ORG,'created'=>null,'date_created'=>$RUN,'updated'=>$UPD,'date_updated'=>$RUN,
                'is_active'=>1,'date_gl'=>$DATE_GL,
            ]);
            $refId=(int)$db->table('doc_t_reference_number')->insertGetId([
                'doc_i_submod_id'=>$SUBMOD,'documentno_dr'=>$docDr,'date_draft'=>$DATE_GL,
                'documentno_pr'=>$docPr,'date_process'=>$DATE_GL,'ad_org_id'=>$ORG,
                'created'=>null,'date_created'=>$RUN,'updated'=>$UPD,'date_updated'=>$RUN,'is_active'=>1,
            ]);
            $cr=$glDiff>0?$glDiff:0.0; $dr=$glDiff<0?-$glDiff:0.0;
            // GL leg 1 — 21101 (SABAY subacct)
            $db->insert("INSERT INTO acct_gl (ad_org_id,gl_acct_id,documentno,date_gl,date_trans,debit,credit,created,date_created,updated,date_updated,gl_subacct_id,acct_doc_id,doc_i_submod_id,doc_t_reference_number_id)
                         VALUES (?,$ACCT,?,DATE(?),?,?,?,NULL,?,?,?,?,?,$SUBMOD,?)",
                        [$ORG,$docPr,$DATE_GL,$RUN,$dr,$cr,$RUN,$UPD,$RUN,$SA,$acctDocId,$refId]);
            // GL leg 2 — 42001 offset (opposite side), no subacct
            $db->insert("INSERT INTO acct_gl (ad_org_id,gl_acct_id,documentno,date_gl,date_trans,debit,credit,created,date_created,updated,date_updated,gl_subacct_id,acct_doc_id,doc_i_submod_id,doc_t_reference_number_id)
                         VALUES (?,$OFFSET,?,DATE(?),?,?,?,NULL,?,?,?,NULL,?,$SUBMOD,?)",
                        [$ORG,$docPr,$DATE_GL,$RUN,$cr,$dr,$RUN,$UPD,$RUN,$acctDocId,$refId]);
            // cache mirror of both legs
            $db->insert("INSERT INTO acct_balance (gl_acct_id,date_gl,ad_org_id,created,date_created,updated,date_updated,is_active,debit,credit,gl_subacct_id,doc_i_submod_id)
                         VALUES ($ACCT,DATE(?),?,NULL,?,?,?,1,?,?,?,$SUBMOD)",[$DATE_GL,$ORG,$RUN,$UPD,$RUN,$dr,$cr,$SA]);
            $db->insert("INSERT INTO acct_balance (gl_acct_id,date_gl,ad_org_id,created,date_created,updated,date_updated,is_active,debit,credit,gl_subacct_id,doc_i_submod_id)
                         VALUES ($OFFSET,DATE(?),?,NULL,?,?,?,1,?,?,NULL,$SUBMOD)",[$DATE_GL,$ORG,$RUN,$UPD,$RUN,$cr,$dr]);
            // bump stubs
            $db->update("UPDATE doc_i_stub SET currentno=".((int)$prStub->currentno+1).",date_updated='$RUN',updated='$UPD' WHERE doc_i_stub_id=$PR_STUB");
            $db->update("UPDATE doc_i_stub SET currentno=".((int)$drStub->currentno+1).",date_updated='$RUN',updated='$UPD' WHERE doc_i_stub_id=$DR_STUB");
            $glPosted=true;
            $say(''); $say('  GL : '.$docPr.'  CR '.$ACCT.' / DR '.$OFFSET.' = '.$m($glDiff).'  (acct_doc '.$acctDocId.')');
        } else {
            $say(''); $say('  GL : SKIP (journal already 0 — reverse NJV-ADJ0000028 first if you want the scripted JV).');
        }

        // ---- CACHE: square any residual drift to journal (0) ----
        $bMid=$sbal(); $cDiff=round(0 - $bMid,2); $cIns=0;
        if (abs($cDiff) > $TOL) {
            if (abs($cDiff) > $CAP) throw new \RuntimeException('SABAY cache residual '.$m($cDiff).' > cap — ABORT.');
            $ccr=$cDiff>0?$cDiff:0.0; $cdr=$cDiff<0?-$cDiff:0.0;
            $db->insert("INSERT INTO acct_balance (gl_acct_id,date_gl,ad_org_id,created,date_created,updated,date_updated,is_active,debit,credit,gl_subacct_id,doc_i_submod_id)
                         VALUES ($ACCT,DATE(?),?,NULL,?,?,?,1,?,?,?,NULL)",[$DATE_GL,$ORG,$RUN,$UPD,$RUN,$cdr,$ccr,$SA]);
            $cIns=1;
        }

        // ---- AGING: settlement row so SABAY aging = 0 ----
        $aIns=0;
        if (abs($ageDiff) > $TOL) {
            $anc=$db->selectOne("SELECT fin_l_debt_id id, documentno docno, doc_i_submod_id sm, ad_org_id org, doc_t_reference_number_id ref FROM fin_l_debt WHERE gl_acct_id=$ACCT AND ad_org_id IN $sub AND s_bpartner_id=$SABAY AND status='PR' AND direction='O' ORDER BY fin_l_debt_id DESC LIMIT 1");
            if(!$anc) throw new \RuntimeException('SABAY has no direction=O debt to anchor the aging settlement — ABORT (run the probe).');
            $db->insert("INSERT INTO fin_l_debt_history (fin_l_debt_id,date_gl,amount,documentno,created,date_created,updated,date_updated,is_active,is_creation,is_settlement,status,ad_org_id,doc_i_submod_id,doc_t_reference_number_id)
                         VALUES (?,DATE(?),?,?,NULL,?,?,?,1,?,?,'PR',?,?,?)",
                        [(int)$anc->id,$DATE_GL,$ageDiff,$anc->docno,$RUN,$UPD,$RUN,$ageDiff>0?1:0,$ageDiff<0?1:0,$anc->org,$anc->sm,$anc->ref]);
            $aIns=1;
        }
        $say('  CACHE resid rows: '.$cIns.' ('.$m($cDiff).')   AGING rows: '.$aIns.' ('.$m($ageDiff).')');

        // ---- post-check: SABAY 0/0/0, GL balanced ----
        $j1=$sgl(); $b1=$sbal(); $a1=$sage();
        $say(''); $say(sprintf(' AFTER :  journal=%-10s cache=%-10s aging=%s', $m($j1),$m($b1),$m($a1)));
        if (abs($j1)>$TOL) throw new \RuntimeException('post-check: SABAY journal '.$m($j1).' != 0 — ABORT.');
        if (abs($b1)>$TOL) throw new \RuntimeException('post-check: SABAY cache '.$m($b1).' != 0 — ABORT.');
        if (abs($a1)>$TOL) throw new \RuntimeException('post-check: SABAY aging '.$m($a1).' != 0 — ABORT.');
        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(''); $say($L);
    $say(' SUCCESS — SABAY journal = cache = aging = 0.00 (scripted GJ '.($glPosted?'posted':'skipped').').');
    $say(' Then run AP21101_162012_reconcile_full — whole-account variance ties to 0.00.');
    $say(' Rollback: AP21101_162012_sabay_jv_07_24_2026_ROLLBACK.php');
    $say($L);
    if (isset($cmd)) $cmd->info('SABAY scripted GJ + aging removal complete (0/0/0).');
};
