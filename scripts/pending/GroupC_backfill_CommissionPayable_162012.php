<?php // scripts/hold/GroupC_backfill_CommissionPayable_162012.php
// IMS-SCRIPT #13714 — Commission Payable (21136) GROUP C backfill, RP Tan A (org 162012)
// *** AGING-ONLY. Restores the is_creation fin_l_debt_history rows the WEB commission engine
//     (ACR, submod 544 / CommissionGlRepository) failed to write. Does NOT touch acct_gl,
//     acct_balance, fin_l_debt, or the module. GL is already correct; writing GL would double-count.
//
// For every submod-544 debt on 21136/162012 that has NO is_creation row, insert exactly one:
//     is_creation=1, is_settlement=0, amount = +amt_debt, date_gl = debt's original date_gl,
//     documentno/org/ref = the debt's own. created=NULL, updated='#IMS-13714'.
// Result per debt: fin_l_debt_history net -> amt_outstanding (ties aging to the debt header).
//
// SCOPE VALIDATED 2026-07-23: 254 debts / 23 agents / +64,732.45. Per-debt tie 254/254.
// 21 agents tie to the journal; Paragas keeps +36,000 (PR33331 phantom = separate fix);
// Padrones(24377) keeps -11,100 (separate anomaly, NOT this defect) — both expected, not fixed here.
//
// Idempotent (skips debts already carrying a creation) + rollback keyed on updated + is_creation.
// Rollback: GroupC_backfill_CommissionPayable_162012_ROLLBACK.php
return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $UPD='#IMS-13714'; $SUBMOD=544; $ACCT=21136; $ORG=162012; $TOL=0.01;
    $RUN=date('Y-m-d H:i:s');
    $EXPECT_DEBTS=254; $EXPECT_TOTAL=64732.45;     // abort if the live scope drifted from validation
    $line=str_repeat('=',96); $say=fn($s)=>print($s.PHP_EOL); $m=fn($x)=>number_format((float)$x,2,'.',',');
    $sub="(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode=$ORG) mo ON child.lft>=mo.lft AND child.ryt<=mo.ryt)";

    $say($line); $say(' IMS-SCRIPT #13714 — Commission Payable (21136) GROUP C is_creation backfill (aging-only)');
    $say(' Run: '.$RUN.'   submod 544 (ACR)   created=NULL  updated='.$UPD); $say($line);

    // IDEMPOTENCY
    $done=(int)$db->selectOne("SELECT COUNT(*) n FROM fin_l_debt_history h JOIN fin_l_debt d ON d.fin_l_debt_id=h.fin_l_debt_id
        WHERE h.updated='$UPD' AND h.is_creation=1 AND h.doc_i_submod_id=$SUBMOD AND d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub")->n;
    if($done>0){ $say(''); $say(" NO-OP — $done creation row(s) already carry updated='$UPD'. Nothing to do."); $say($line); return; }

    // affected debts (submod 544, NO creation row; paid or unpaid)
    $rows=$db->select("
        SELECT d.fin_l_debt_id id, d.s_bpartner_id bp, d.documentno docno, d.date_gl date_gl,
               d.ad_org_id org, d.doc_t_reference_number_id refid,
               ROUND(d.amt_debt,2) amt_debt, ROUND(d.amt_outstanding,2) outv,
               ROUND(COALESCE((SELECT SUM(x.amount) FROM fin_l_debt_history x WHERE x.fin_l_debt_id=d.fin_l_debt_id),0),2) hist_net
        FROM fin_l_debt d
        WHERE d.gl_acct_id=$ACCT AND d.doc_i_submod_id=$SUBMOD AND d.ad_org_id IN $sub
          AND NOT EXISTS (SELECT 1 FROM fin_l_debt_history c WHERE c.fin_l_debt_id=d.fin_l_debt_id AND c.is_creation=1)
          AND d.amt_debt > 0");

    $total=0.0; foreach($rows as $r) $total+=(float)$r->amt_debt;
    $say(sprintf(' PRE-CHECK: %d debts, total amt_debt=%s (expect %d / %s)', count($rows), $m($total), $EXPECT_DEBTS, $m($EXPECT_TOTAL)));
    if(count($rows)!==$EXPECT_DEBTS) throw new \RuntimeException('debt count drifted ('.count($rows).' != '.$EXPECT_DEBTS.') — data changed, ABORT & re-validate.');
    if(abs($total-$EXPECT_TOTAL)>$TOL) throw new \RuntimeException('total drifted ('.$m($total).' != '.$m($EXPECT_TOTAL).') — ABORT & re-validate.');
    // per-debt: adding +amt_debt must land on amt_outstanding
    foreach($rows as $r){ if(abs(($r->hist_net + $r->amt_debt) - $r->outv) > $TOL)
        throw new \RuntimeException("debt {$r->id}: +amt_debt would not tie to outstanding — ABORT."); }

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $ins=0; $grand=0.0;
        foreach($rows as $r){
            $ok=$db->insert("INSERT INTO fin_l_debt_history
                (fin_l_debt_id, date_gl, amount, documentno, created, date_created, updated, date_updated,
                 is_active, is_creation, is_settlement, status, ad_org_id, doc_i_submod_id, doc_t_reference_number_id)
                VALUES (?, DATE(?), ?, ?, NULL, ?, ?, ?, 1, 1, 0, 'PR', ?, $SUBMOD, ?)",
                [$r->id, $r->date_gl, $r->amt_debt, $r->docno, $RUN, $UPD, $RUN, $r->org, $r->refid]);
            if(!$ok) throw new \RuntimeException("insert failed for debt {$r->id}");
            $ins++; $grand+=(float)$r->amt_debt;
            // per-debt post-check
            $net=(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(amount),0),2) v FROM fin_l_debt_history WHERE fin_l_debt_id={$r->id}")->v;
            if(abs($net-$r->outv)>$TOL) throw new \RuntimeException("debt {$r->id} post-net ".$m($net)." != outstanding ".$m($r->outv)." — ABORT.");
        }
        if($ins!==$EXPECT_DEBTS || abs($grand-$EXPECT_TOTAL)>$TOL) throw new \RuntimeException("inserted $ins / ".$m($grand)." != expected — ABORT.");
        $say(sprintf('   inserted %d creation rows, total aging added = %s', $ins, $m($grand)));
        $db->commit();
    } catch(\Throwable $e){ $db->rollBack(); throw $e; }

    // informational per-agent residual vs journal (NOT a guard — Paragas/Padrones expected non-zero)
    $say(''); $say(' POST (informational) — per-agent aging vs journal after backfill:');
    $bps=array_values(array_unique(array_map(fn($r)=>(int)$r->bp,$rows)));
    foreach($bps as $bp){
        $ag=(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(h.amount),0),2) v FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id=$ACCT AND d.ad_org_id IN $sub AND d.direction='O' AND d.s_bpartner_id=$bp AND h.status='PR'")->v;
        $gl=(float)$db->selectOne("SELECT ROUND(IFNULL(SUM(g.credit-g.debit),0),2) v FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id WHERE g.gl_acct_id=$ACCT AND g.ad_org_id IN $sub AND s.s_bpartner_id=$bp")->v;
        $r=round($ag-$gl,2); if(abs($r)>$TOL){ $nm=$db->selectOne("SELECT name1 FROM s_bpartner WHERE s_bpartner_id=$bp")->name1 ?? $bp;
            $say(sprintf('   RESIDUAL %-26s aging=%s journal=%s resid=%s',substr($nm,0,26),$m($ag),$m($gl),$m($r))); }
    }
    $say(''); $say($line);
    $say(' SUCCESS — Group C creations restored (+'.$m($EXPECT_TOTAL).'). Aging-only; GL/cache/module untouched.');
    $say(' Expected remaining residuals: Paragas +36,000 (PR33331 phantom, separate) ; Padrones 24377 -11,100 (separate anomaly).');
    $say(' Rollback: GroupC_backfill_CommissionPayable_162012_ROLLBACK.php');
    $say($line);
};
