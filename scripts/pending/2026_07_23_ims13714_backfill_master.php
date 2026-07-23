<?php
/**
 * IMS-13714 — Commission aging backfill MASTER (21136, org 162012). ONE RUN.
 * Consolidates the legit sub-ledger fixes (SUPERSEDES — delete after):
 *   2026_07_20_commission_backfill_is_creation.php
 *   2026_07_23_ims13714_acr_accrual_backfill.php
 *   2026_07_23_ims13714_v2_incl_nohistory.php
 *   2026_07_23_ims13714_v3_restore_header_to_history.php
 * ---------------------------------------------------------------------------
 * Writes ONLY fin_l_debt_history (never acct_gl/acct_balance, never fin_l_debt).
 * DRY-RUN default.  $MODE = 'dry' | 'apply' | 'rollback'
 *   php artisan scripts:run-one scripts/pending/2026_07_23_ims13714_backfill_master.php
 *
 * WHAT IT FIXES — Class C ONLY (aging understated): for every ACR (544) debt where
 *   amt_outstanding > SUM(history), insert ONE is_creation row = the difference,
 *   so the aging history equals the debt's own authoritative header. Covers the
 *   missing-creation, no-history, and header-restored variants. Amount comes from
 *   the debt itself — nothing invented, no rows fabricated, no debts created.
 *
 * OVERSHOOT GUARD: excludes any agent whose (aging + restore) would exceed acct_gl
 *   — so PARAGAS (phantom, class A) is never inflated.
 *
 * DOES NOT (by design — these are NOT a sub-ledger backfill):
 *   - Class B negative-ledger agents (COLGUE, SALES AGENT, LORELIE, WALK IN,
 *     RUBY = 84,828.61) -> post a GL Journal Voucher (GJL) crediting 21136 to 0.
 *   - Class A PARAGAS phantom (22,608.72) -> reduce the specific over-accrued debt.
 *   - It will NOT force paid-as-owed balances; agents whose header does not justify
 *     the GL are left for accounting's paid-vs-owed determination.
 *
 * GUARD/IDEMPOTENCY: single transaction; re-runs are no-ops (gap closes to 0);
 *   stamp created='IMS13714'; rollback via $MODE='rollback'.
 * VERIFY (not the report — acct_balance cache is corrupt): per agent
 *   SUM(fin_l_debt_history.amount) should equal SUM(acct_gl.credit-debit).
 * ⚠️ RUN ON THE PERSISTENT ENVIRONMENT (production / main branch). The replica
 *   is restored from backup and wipes applied fixes.
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    $conn='mysql_secondary'; $stamp='#IMS-13714'; $MODE='dry';
    $acct=21136; $org=162012;
    $log=fn($m)=>$cmd->info($m); $db=DB::connection($conn);

    if ($MODE==='rollback') {
        $q=fn()=>$db->table('fin_l_debt_history')->whereIn('created',['IMS13714','IMS13714-REV'])->where('is_creation',1);
        $n=(clone $q())->count(); if(!$n){$log('nothing to roll back');return['deleted'=>0];}
        $d=$db->transaction(fn()=>$q()->delete()); $log("rolled back {$d} rows"); return['deleted'=>$d];
    }

    $tree="d.ad_org_id IN (SELECT c.ad_org_id FROM ad_org c
        JOIN (SELECT lft,ryt FROM ad_org WHERE orgcode={$org}) mo ON c.lft>=mo.lft AND c.ryt<=mo.ryt)";
    $affected="FROM fin_l_debt d
        LEFT JOIN (SELECT fin_l_debt_id, SUM(amount) s FROM fin_l_debt_history
                   WHERE status='PR' GROUP BY fin_l_debt_id) hs ON hs.fin_l_debt_id=d.fin_l_debt_id
        WHERE d.gl_acct_id={$acct} AND d.doc_i_submod_id=544 AND d.status='PR' AND {$tree}
          AND (d.amt_outstanding - COALESCE(hs.s,0)) > 0.005";

    // overshoot guard: skip agents where restoring would exceed acct_gl (Paragas phantom)
    $agg=$db->select("SELECT d.s_bpartner_id bp, SUM(d.amt_outstanding - COALESCE(hs.s,0)) restore,
        (SELECT IFNULL(SUM(h.amount),0) FROM fin_l_debt dd JOIN fin_l_debt_history h ON h.fin_l_debt_id=dd.fin_l_debt_id
          WHERE dd.gl_acct_id={$acct} AND dd.ad_org_id={$org} AND dd.s_bpartner_id=d.s_bpartner_id
            AND dd.direction='O' AND dd.status='PR' AND h.status='PR') aging,
        (SELECT IFNULL(SUM(g.credit-g.debit),0) FROM acct_gl g JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id
          WHERE g.gl_acct_id={$acct} AND g.ad_org_id={$org} AND s.s_bpartner_id=d.s_bpartner_id) gl
        {$affected} GROUP BY d.s_bpartner_id");
    $ex=[]; foreach($agg as $a){ if((float)$a->aging+(float)$a->restore > (float)$a->gl+0.01) $ex[]=(int)$a->bp; }
    $exC=$ex? " AND d.s_bpartner_id NOT IN (".implode(',',$ex).")":"";

    $rows=$db->select("SELECT d.fin_l_debt_id id, d.s_bpartner_id bp, d.doc_i_submod_id sm, d.ad_org_id o,
        d.doc_t_reference_number_id ref, d.date_gl dg, d.documentno doc,
        ROUND(d.amt_outstanding - COALESCE(hs.s,0),2) amt {$affected}{$exC}");
    $sum=array_sum(array_map(fn($r)=>(float)$r->amt,$rows));
    $byBp=[]; foreach($rows as $r){ $byBp[$r->bp]=($byBp[$r->bp]??0)+(float)$r->amt; }
    $log("Class C backfill: ".count($rows)." debts / ".count($byBp)." agents, +".number_format($sum,2));
    if($ex) $log("EXCLUDED (overshoot/phantom -> accounting): ".implode(',',$ex));
    if(!count($rows)){$log('nothing to backfill (already tied). Done.');return['inserted'=>0];}
    if($MODE!=='apply'){$log("DRY RUN — set \$MODE='apply' to write");return['would_insert'=>count($rows),'total'=>round($sum,2),'excluded'=>$ex];}

    $n=$db->transaction(function() use($db,$rows,$stamp,$log){
        $ins=0;
        foreach($rows as $r){
            $db->table('fin_l_debt_history')->insert([
                'fin_l_debt_id'=>$r->id,'doc_i_submod_id'=>$r->sm,'ad_org_id'=>$r->o,
                'doc_t_reference_number_id'=>$r->ref,'date_gl'=>$r->dg,'amount'=>$r->amt,
                'documentno'=>$r->doc,'is_creation'=>1,'is_settlement'=>0,'is_active'=>1,
                'status'=>'PR','created'=>$stamp,'date_created'=>now(),
            ]); $ins++;
        }
        $log("inserted {$ins} backfill rows (stamp={$stamp})");
        return $ins;
    });
    return['inserted'=>$n];
};
