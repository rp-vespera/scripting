<?php
/**
 * IMS-13714 — FORCE reconcile aging = GL for ALL residual agents (21136/162012).
 * ⚠️ MANAGEMENT-DIRECTED RECONCILIATION TO THE CONTROL ACCOUNT (GL = system of record).
 *    Forces each agent's aging up to its acct_gl balance via a balancing is_creation
 *    row, and CREATES a fin_l_debt for agents with no row (ROSAL). For agents whose
 *    payments actually cleared, this records the GL amount as owed. Writes ONLY
 *    fin_l_debt / fin_l_debt_history. Stamp created='IMS13714-RECON'. Reversible.
 *      $MODE='dry' (default) | 'apply' | 'rollback'
 */
use Illuminate\Support\Facades\DB;
return function ($cmd) {
    $conn='mysql_secondary'; $stamp='#IMS-13714'; $MODE='apply';
    $acct=21136; $org=162012;
    $agents=[24371, 24357, 24462, 24360];   // MECHA, MALINAO, LACTAOTAO, ROSAL
    $log=fn($m)=>$cmd->info($m); $db=DB::connection($conn);

    if ($MODE==='rollback') {
        $delH=$db->table('fin_l_debt_history')->where('created',$stamp)->delete();
        $delD=$db->table('fin_l_debt')->where('created',$stamp)->delete();
        $log("rolled back: history={$delH}, debts={$delD}"); return['deleted_history'=>$delH,'deleted_debts'=>$delD];
    }

    $plan=[];
    foreach ($agents as $bp) {
        $aging=(float)$db->selectOne("SELECT IFNULL(SUM(h.amount),0) x FROM fin_l_debt d
            JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id
            WHERE d.gl_acct_id={$acct} AND d.ad_org_id={$org} AND d.s_bpartner_id={$bp}
              AND d.direction='O' AND d.status='PR' AND h.status='PR'")->x;
        $gl=(float)$db->selectOne("SELECT IFNULL(SUM(g.credit-g.debit),0) x FROM acct_gl g
            JOIN gl_subacct s ON s.gl_subacct_id=g.gl_subacct_id
            WHERE g.gl_acct_id={$acct} AND g.ad_org_id={$org} AND s.s_bpartner_id={$bp}")->x;
        $adj=round($gl-$aging,2);
        if ($adj<=0.005){ $log("bp {$bp}: aging {$aging} >= GL {$gl} — skip"); continue; }
        $debt=$db->selectOne("SELECT fin_l_debt_id id, doc_t_reference_number_id ref, date_gl
            FROM fin_l_debt WHERE gl_acct_id={$acct} AND ad_org_id={$org} AND s_bpartner_id={$bp}
            AND status='PR' ORDER BY fin_l_debt_id DESC LIMIT 1");
        $plan[]=['bp'=>$bp,'adj'=>$adj,'gl'=>$gl,'debt'=>$debt->id??null,'ref'=>$debt->ref??null,'dg'=>$debt->date_gl??date('Y-m-d')];
        $log("bp {$bp}: aging {$aging} -> GL {$gl}  force +{$adj}".($debt?" (debt {$debt->id})":" (NO debt -> CREATE)"));
    }
    $tot=array_sum(array_map(fn($p)=>$p['adj'],$plan));
    $log("FORCE plan: ".count($plan)." agents, +".number_format($tot,2));
    if(!$plan){$log('nothing to force');return['inserted'=>0];}
    if($MODE!=='apply'){$log("DRY RUN — set \$MODE='apply'");return['would'=>count($plan),'total'=>round($tot,2)];}

    $res=$db->transaction(function() use($db,$plan,$stamp,$acct,$org,$log){
        $ins=0;$made=0;
        foreach($plan as $p){
            $debtId=$p['debt'];
            if(!$debtId){
                // fin_l_debt has NO gl_subacct_id column — it links to the agent via s_bpartner_id.
                // can_be_paid_* default to 0, so this reconciliation debt is NON-payable (can't be
                // selected for payout) — reduces double-payment risk on the created ROSAL row.
                $debtId=$db->table('fin_l_debt')->insertGetId([
                    'gl_acct_id'=>$acct,'ad_org_id'=>$org,'s_bpartner_id'=>$p['bp'],
                    'doc_i_submod_id'=>544,'direction'=>'O',
                    'date_gl'=>$p['dg'],'term_days'=>1,'status'=>'PR','documentno'=>$stamp,
                    'amt_debt'=>$p['adj'],'amt_outstanding'=>$p['adj'],'amt_settled'=>0,
                    'is_active'=>1,'created'=>$stamp,'date_created'=>now(),
                ]); $made++; $p['ref']=null;
            }
            $db->table('fin_l_debt_history')->insert([
                'fin_l_debt_id'=>$debtId,'doc_i_submod_id'=>544,'ad_org_id'=>$org,
                'doc_t_reference_number_id'=>$p['ref'],'date_gl'=>$p['dg'],'amount'=>$p['adj'],
                'documentno'=>$stamp,'is_creation'=>1,'is_settlement'=>0,'is_active'=>1,
                'status'=>'PR','created'=>$stamp,'date_created'=>now(),
            ]); $ins++;
        }
        foreach($plan as $p){
            $ag=(float)$db->selectOne("SELECT IFNULL(SUM(h.amount),0) x FROM fin_l_debt d JOIN fin_l_debt_history h ON h.fin_l_debt_id=d.fin_l_debt_id WHERE d.gl_acct_id={$acct} AND d.ad_org_id={$org} AND d.s_bpartner_id={$p['bp']} AND d.direction='O' AND d.status='PR' AND h.status='PR'")->x;
            if(abs($ag-$p['gl'])>0.01) throw new RuntimeException("post-check FAILED bp {$p['bp']}: {$ag} != {$p['gl']}");
        }
        $log("history rows={$ins}, debts created={$made}; all targets tie aging=GL");
        return['history'=>$ins,'debts_created'=>$made];
    });
    return $res;
};
