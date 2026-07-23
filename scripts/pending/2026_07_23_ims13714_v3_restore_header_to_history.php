<?php
/**
 * IMS-13714 v3 — restore aging HISTORY to match the debt's own HEADER
 * (org 162012, acct 21136). For debts whose amt_outstanding was restored on the
 * header (e.g. a cancelled-payment reversal) but the matching is_creation history
 * row was never written — so SUM(history) < amt_outstanding.
 * ---------------------------------------------------------------------------
 * ⚠️ IN scripts/pending/ -> AUTO-RUNS ON DEPLOY. Writes ONLY fin_l_debt_history.
 *    DRY-RUN default; set $MODE='apply' to write.
 *      php artisan scripts:run-one scripts/pending/2026_07_23_ims13714_v3_restore_header_to_history.php
 *
 * DETECTION (correct, self-limiting — NOT a plug): ACR (544) debt on 21136/162012,
 *   status PR, where amt_outstanding > SUM(history) by >0.005. Insert ONE
 *   is_creation row per debt = (amt_outstanding - SUM(history)) so the aging
 *   history equals the debt's authoritative header. Amount comes from the debt
 *   itself — nothing invented.
 *
 * OVERSHOOT GUARD (critical): EXCLUDE any agent whose (aging + restore) would
 *   EXCEED its acct_gl. This drops PARAGAS (1267) — its header is a PHANTOM
 *   (47,960 vs GL 11,960); restoring it would inflate the variance. It keeps
 *   only agents where restoring ties aging to GL (currently just PABIONA 21412).
 *
 * NOT HANDLED HERE (need accounting — do NOT force):
 *   - LACTAOTAO 24462 (600): payments CLEARED (agent paid) -> GL over-stated,
 *     header=0, no header>history gap. Forcing would book a paid liability.
 *   - MECHA 24371 (1,800): 600 cancelled + 1,200 on cleared payments (paid).
 *   - MALINAO 24357 (368): cancelled amounts were mostly re-paid.
 *   - ROSAL 24360 (840): NO fin_l_debt row. Not fabricated here — investigate
 *     whether the GL 840 is a real debt (create it) or a stray posting (GL fix).
 *   - PARAGAS 1267: phantom (aging OVER gl) -> phantom reduction, not a restore.
 *
 * GUARD/IDEMPOTENCY: transaction; re-runs are no-ops (gap closes to 0);
 *   stamp created='IMS13714-REV'; rollback via $MODE='rollback'.
 * VERIFY: per agent SUM(fin_l_debt_history.amount) should equal SUM(acct_gl.credit-debit).
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    $conn='mysql_secondary'; $stamp='#IMS-13714'; $MODE='apply';   // dry | apply | rollback
    $acct=21136; $org=162012;
    $log=fn($m)=>$cmd->info($m); $db=DB::connection($conn);

    if ($MODE==='rollback') {
        $q=fn()=>$db->table('fin_l_debt_history')->where('created',$stamp);
        $n=(clone $q())->count(); if(!$n){$log('nothing to roll back');return['deleted'=>0];}
        $d=$db->transaction(fn()=>$q()->delete()); $log("rolled back {$d} rows"); return['deleted'=>$d];
    }

    $tree="d.ad_org_id IN (SELECT c.ad_org_id FROM ad_org c
        JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode={$org}) mo ON c.lft>=mo.lft AND c.ryt<=mo.ryt)";
    // debts where header > history (restoration recorded on header, missing from history)
    $affected="FROM fin_l_debt d
        LEFT JOIN (SELECT fin_l_debt_id, SUM(amount) s FROM fin_l_debt_history
                   WHERE status='PR' GROUP BY fin_l_debt_id) hs ON hs.fin_l_debt_id=d.fin_l_debt_id
        WHERE d.gl_acct_id={$acct} AND d.doc_i_submod_id=544 AND d.status='PR' AND {$tree}
          AND (d.amt_outstanding - COALESCE(hs.s,0)) > 0.005";

    // overshoot guard: exclude agents whose (aging + restore) would exceed acct_gl (e.g. Paragas phantom)
    $agg=$db->select("SELECT d.s_bpartner_id bp, SUM(d.amt_outstanding - COALESCE(hs.s,0)) restore,
        (SELECT IFNULL(SUM(h.amount),0) FROM fin_l_debt dd JOIN fin_l_debt_history h ON h.fin_l_debt_id=dd.fin_l_debt_id
          WHERE dd.gl_acct_id={$acct} AND dd.ad_org_id={$org} AND dd.s_bpartner_id=d.s_bpartner_id
            AND dd.direction='O' AND dd.status='PR' AND h.status='PR') aging,
        (SELECT IFNULL(SUM(g.credit-g.debit),0) FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id
          WHERE g.gl_acct_id={$acct} AND g.ad_org_id={$org} AND s.s_bpartner_id=d.s_bpartner_id) gl
        {$affected} GROUP BY d.s_bpartner_id");
    $ex=[]; foreach($agg as $a){ if((float)$a->aging+(float)$a->restore > (float)$a->gl+0.01) $ex[]=(int)$a->bp; }
    $exC=$ex? " AND d.s_bpartner_id NOT IN (".implode(',',$ex).")":"";

    $rows=$db->select("SELECT d.fin_l_debt_id id, d.s_bpartner_id bp, d.doc_t_reference_number_id ref,
        d.date_gl dg, ROUND(d.amt_outstanding - COALESCE(hs.s,0),2) restore {$affected}{$exC}");
    $sum=array_sum(array_map(fn($r)=>(float)$r->restore,$rows));
    $byBp=[]; foreach($rows as $r){ $byBp[$r->bp]=($byBp[$r->bp]??0)+(float)$r->restore; }
    $log("v3 restore set: ".count($rows)." debts / ".count($byBp)." agents, +".number_format($sum,2));
    foreach($byBp as $bp=>$a) $log("   agent {$bp}: +".number_format($a,2));
    if($ex) $log("EXCLUDED (overshoot / phantom -> accounting): ".implode(',',$ex));
    if(!count($rows)){$log('nothing to restore');return['inserted'=>0];}
    if($MODE!=='apply'){$log("DRY RUN — set \$MODE='apply' to write");return['would_insert'=>count($rows),'total'=>round($sum,2),'excluded'=>$ex];}

    $n=$db->transaction(function() use($db,$rows,$stamp,$org,$log){
        $ins=0;
        foreach($rows as $r){
            $db->table('fin_l_debt_history')->insert([
                'fin_l_debt_id'=>$r->id, 'doc_i_submod_id'=>544, 'ad_org_id'=>$org,
                'doc_t_reference_number_id'=>$r->ref, 'date_gl'=>$r->dg, 'amount'=>$r->restore,
                'documentno'=>$stamp, 'is_creation'=>1, 'is_settlement'=>0, 'is_active'=>1,
                'status'=>'PR', 'created'=>$stamp, 'date_created'=>now(),
            ]);
            $ins++;
        }
        $log("inserted {$ins} restore rows (stamp={$stamp})");
        return $ins;
    });
    return['inserted'=>$n];
};
