<?php
/**
 * IMS-13714 v2 — ACR commission accrual backfill, incl. NO-HISTORY debts
 * (org 162012, acct 21136). SUPERSEDES 2026_07_23_ims13714_acr_accrual_backfill.php.
 * ---------------------------------------------------------------------------
 * ⚠️ IN scripts/pending/ -> AUTO-RUNS ON DEPLOY. Writes ONLY fin_l_debt_history
 *    (never acct_gl/acct_balance). DRY-RUN by default; set $MODE='apply' to write.
 *      php artisan scripts:run-one scripts/pending/2026_07_23_ims13714_v2_incl_nohistory.php
 *
 * WHY v2: v1 required EXISTS(is_settlement), so it skipped ACR debts that have
 *   NO history rows at all (e.g. TURCOLAS 24358 — 8 debts, amt_outstanding=365,
 *   GL=365, zero history). Those are the SAME missing-creation defect with no
 *   settlement yet. v2 replaces the "has settlement" test with an INVARIANT +
 *   "outstanding>0" gate that catches both variants and is self-limiting.
 *
 * DETECTION (safe, self-limiting): ACR (544) debt on 21136/162012, status PR, with
 *   - NO is_creation row, AND
 *   - amt_outstanding > 0 (genuinely owed), AND
 *   - inserting amt_debt EXACTLY restores SUM(history)=amt_outstanding.
 *   This includes no-history debts (out = 0 + amt_debt) AND partially-settled
 *   missing-creation debts, and EXCLUDES fully-settled/unreversed-cancellation
 *   debts (out=0 -> gate fails) — those need a reversal, not an accrual.
 *
 * OVERSHOOT GUARD: excludes any agent whose aging+backfill would exceed acct_gl
 *   (e.g. Paragas 1267). NOT for class A (phantom) or the 2b reversal residuals.
 *
 * GUARD/IDEMPOTENCY: transaction; re-runs are no-ops (debt then has a creation
 *   row); stamped created='IMS13714' (same as v1 — one rollback covers both);
 *   rollback via $MODE='rollback'.
 * VERIFY (not the report — acct_balance cache is corrupt): per agent
 *   SUM(fin_l_debt_history.amount) should equal SUM(acct_gl.credit-debit).
 *
 * RETIRE v1: after this runs, move 2026_07_23_ims13714_acr_accrual_backfill.php
 *   to done/ (or delete) so only one ACR backfill script remains in pending/.
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    $conn='mysql_secondary'; $stamp='#IMS-13714'; $MODE='apply';   // dry | apply | rollback
    $acct=21136; $org=162012;
    $log=fn($m)=>$cmd->info($m); $db=DB::connection($conn);

    if ($MODE==='rollback') {
        $q=fn()=>$db->table('fin_l_debt_history')->where('created',$stamp)->where('is_creation',1);
        $n=(clone $q())->count(); if(!$n){$log('nothing to roll back');return['deleted'=>0];}
        $d=$db->transaction(fn()=>$q()->delete()); $log("rolled back {$d} rows"); return['deleted'=>$d];
    }

    $tree="d.ad_org_id IN (SELECT c.ad_org_id FROM ad_org c
        JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode={$org}) mo ON c.lft>=mo.lft AND c.ryt<=mo.ryt)";
    // v2 detection — includes no-history debts, invariant-gated, outstanding>0
    $affected="FROM fin_l_debt d
        LEFT JOIN (SELECT fin_l_debt_id, SUM(amount) s FROM fin_l_debt_history
                   WHERE status='PR' GROUP BY fin_l_debt_id) hs ON hs.fin_l_debt_id=d.fin_l_debt_id
        WHERE d.gl_acct_id={$acct} AND d.doc_i_submod_id=544 AND d.status='PR' AND {$tree}
          AND NOT EXISTS (SELECT 1 FROM fin_l_debt_history h WHERE h.fin_l_debt_id=d.fin_l_debt_id AND h.is_creation=1)
          AND d.amt_outstanding > 0.005
          AND ABS(d.amt_outstanding - (COALESCE(hs.s,0) + d.amt_debt)) <= 0.005";

    // overshoot guard: exclude agents whose aging+backfill would exceed acct_gl
    $agg=$db->select("SELECT d.s_bpartner_id bp, SUM(d.amt_debt) bf,
        (SELECT IFNULL(SUM(h.amount),0) FROM fin_l_debt dd JOIN fin_l_debt_history h ON h.fin_l_debt_id=dd.fin_l_debt_id
          WHERE dd.gl_acct_id={$acct} AND dd.ad_org_id={$org} AND dd.s_bpartner_id=d.s_bpartner_id
            AND dd.direction='O' AND dd.status='PR' AND h.status='PR') aging,
        (SELECT IFNULL(SUM(g.credit-g.debit),0) FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id
          WHERE g.gl_acct_id={$acct} AND g.ad_org_id={$org} AND s.s_bpartner_id=d.s_bpartner_id) gl
        {$affected} GROUP BY d.s_bpartner_id");
    $ex=[]; foreach($agg as $a){ if((float)$a->aging+(float)$a->bf > (float)$a->gl+0.01) $ex[]=(int)$a->bp; }
    $exC=$ex? " AND d.s_bpartner_id NOT IN (".implode(',',$ex).")":"";

    $rows=$db->select("SELECT d.fin_l_debt_id id, d.s_bpartner_id bp, d.amt_debt amt {$affected}{$exC}");
    $sum=array_sum(array_map(fn($r)=>(float)$r->amt,$rows));
    $byBp=[]; foreach($rows as $r){ $byBp[$r->bp]=($byBp[$r->bp]??0)+(float)$r->amt; }
    $log("v2 backfill set: ".count($rows)." debts / ".count($byBp)." agents, ".number_format($sum,2));
    foreach($byBp as $bp=>$a) $log("   agent {$bp}: +".number_format($a,2));
    if($ex) $log("EXCLUDED overshoot agents (accounting): ".implode(',',$ex));
    if(!count($rows)){$log('nothing to backfill');return['inserted'=>0];}
    if($MODE!=='apply'){$log("DRY RUN — set \$MODE='apply' to write");return['would_insert'=>count($rows),'total'=>round($sum,2),'excluded'=>$ex];}

    $n=$db->transaction(function() use($db,$affected,$exC,$stamp,$log){
        $ins=$db->affectingStatement("INSERT INTO fin_l_debt_history
            (fin_l_debt_id, doc_i_submod_id, ad_org_id, doc_t_reference_number_id,
             date_gl, amount, documentno, is_creation, is_settlement, is_active, status, created, date_created)
            SELECT d.fin_l_debt_id, 544, d.ad_org_id, d.doc_t_reference_number_id,
                   d.date_gl, d.amt_debt, d.documentno, 1, 0, 1, 'PR', ?, NOW()
            {$affected}{$exC}",[$stamp]);
        $log("inserted {$ins} creation rows (stamp={$stamp})");
        return $ins;
    });
    return['inserted'=>$n];
};
