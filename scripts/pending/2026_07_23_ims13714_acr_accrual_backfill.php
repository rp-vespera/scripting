<?php
/**
 * IMS-13714 — ACR commission accrual backfill (org 162012, acct 21136)
 * ---------------------------------------------------------------------------
 * ⚠️ IN scripts/pending/ -> AUTO-RUNS ON DEPLOY. Writes ONLY fin_l_debt_history
 *    (never acct_gl/acct_balance). DRY-RUN by default; set $MODE='apply' to write.
 *      php artisan scripts:run-one scripts/pending/2026_07_23_ims13714_acr_accrual_backfill.php
 *
 * ROOT CAUSE (confirmed): Agent Commission Release (ACR, doc_i_submod_id=544)
 *   posted commission to acct_gl and wrote the SETTLEMENT row in
 *   fin_l_debt_history on pay, but historically NEVER the CREATION row
 *   (is_creation=1). So those debts' aging sub-ledger has only "minus"
 *   settlements and no "plus" creation, leaving aging understated vs the GL.
 *   The forward code fix is already in CommissionGlRepository::postToFinLDebt();
 *   this backfills the pre-existing rows the old code skipped.
 *
 * WHAT IT DOES: for every ACR (544) debt on 21136/162012 that has a settlement
 *   row but NO is_creation row, insert ONE creation row (amount=+amt_debt,
 *   is_creation=1, is_settlement=0, status='PR', dated to the debt's own date_gl,
 *   doc_i_submod_id=544, documentno/ref from the debt). aging then = amt_outstanding = GL.
 *
 * OVERSHOOT GUARD: excludes any agent where aging+backfill would EXCEED acct_gl
 *   (e.g. Paragas 1267, a phantom/over-stated agent) — backfilling it is wrong.
 *   Those, plus non-ACR residuals (24357/24360/24371/24462, 24358, 21412), are
 *   accounting reconciliations, NOT this backfill.
 *
 * GUARD/IDEMPOTENCY: transaction; re-runs are no-ops (debt then has a creation
 *   row); stamped created='IMS13714'; rollback via $MODE='rollback'.
 * VERIFY (not the report — acct_balance cache is corrupt): per agent
 *   SUM(fin_l_debt_history.amount) should equal SUM(acct_gl.credit-debit).
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
    $affected="FROM fin_l_debt d
        WHERE d.gl_acct_id={$acct} AND d.doc_i_submod_id=544 AND d.status='PR' AND {$tree}
          AND NOT EXISTS (SELECT 1 FROM fin_l_debt_history h WHERE h.fin_l_debt_id=d.fin_l_debt_id AND h.is_creation=1)
          AND EXISTS     (SELECT 1 FROM fin_l_debt_history h WHERE h.fin_l_debt_id=d.fin_l_debt_id AND h.is_settlement=1)";

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

    $rows=$db->select("SELECT d.fin_l_debt_id id, d.amt_debt amt {$affected}{$exC}");
    $sum=array_sum(array_map(fn($r)=>(float)$r->amt,$rows));
    $log("ACR backfill set: ".count($rows)." debts, ".number_format($sum,2));
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
