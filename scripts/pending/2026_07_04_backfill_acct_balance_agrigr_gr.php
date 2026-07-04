<?php // scripts/pending/2026_07_04_backfill_acct_balance_agrigr_gr.php
/**
 * ============================================================================
 * ONE-TIME BACKFILL — post the acct_balance entries that AGRIGR's GR posting
 * (PoStatusController) wrote to acct_gl but never to acct_balance.
 * Date: 2026-07-04 · Target: mysql_secondary (SAS SAERPRP)
 * ============================================================================
 *
 * WHY: SAERP ledger reports (Inventory Integrity per Product Category, etc.)
 * read balances from acct_balance. The GR posted acct_gl only, so the GR-side
 * debits/credits never reached the rollup — draining CMI-Suspense (11313)
 * deeply negative and desyncing the stock-card from GL.
 *
 * WHAT IT TOUCHES — only AGRIGR GR-side rows (created='SYSTEM'), identified by
 * account + side (GR and IGR post OPPOSITE sides; the IGR side is already in
 * acct_balance via InvoiceController::postToAcctBalance):
 *     11313 DEBIT   (GR; IGR credits it)
 *     21138 CREDIT  (GR; IGR debits it)
 *     92005 DEBIT   (GR; IGR credits it)
 * 11309 is IGR-only and already balanced — NOT touched.
 *
 * It replays each missing GR contribution into acct_balance using the SAME
 * net-upsert logic as postToAcctBalance (key: date_gl, doc_i_submod_id,
 * gl_acct_id, ad_org_id, gl_subacct_id).
 *
 * ⚠ RUN ONCE. NOT idempotent — a second run would double-post. Once this file
 * moves to scripts/done/ the pipeline will not run it again on this branch;
 * do not move it back to pending/ after a successful run. This script COMMITS.
 *
 * Verify against the known reconciliation (org 162012):
 *   11313 current -830,866.20  ->  projected ~ +254,840  (sane uninvoiced-GR suspense)
 */

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    set_time_limit(0);

    echo "*** COMMIT MODE — writing acct_balance ***\n";
    $t0 = microtime(true);

    // 1. Aggregate the missing GR-side contributions per acct_balance key.
    $keys = $db->select("
      SELECT date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id,
             ROUND(SUM(debit),2)  AS dr,
             ROUND(SUM(credit),2) AS cr,
             COUNT(*)             AS n
      FROM acct_gl
      WHERE created='SYSTEM' AND (
            (gl_acct_id = 11313 AND debit  > 0) OR
            (gl_acct_id = 21138 AND credit > 0) OR
            (gl_acct_id = 92005 AND debit  > 0))
      GROUP BY date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id");
    echo "GR-side acct_balance keys to backfill: " . count($keys) . "\n";

    // 2. Current balances (before) for context.
    $before = [];
    foreach ($db->select("
        SELECT ad_org_id, gl_acct_id, ROUND(SUM(debit)-SUM(credit),2) net
        FROM acct_balance WHERE gl_acct_id IN (11313,21138,92005)
        GROUP BY ad_org_id, gl_acct_id") as $r) {
        $before[$r->ad_org_id.'|'.$r->gl_acct_id] = (float) $r->net;
    }

    $added = [];           // (org|acct) => net (dr-cr) added
    $inserted = 0; $updated = 0;

    $db->beginTransaction();
    try {
        foreach ($keys as $r) {
            $dr = (float) $r->dr; $cr = (float) $r->cr;
            $ak = $r->ad_org_id.'|'.$r->gl_acct_id;
            $added[$ak] = ($added[$ak] ?? 0) + ($dr - $cr);

            // net-upsert mirrors postToAcctBalance
            $ex = $db->selectOne(
                "SELECT acct_balance_id, debit, credit FROM acct_balance
                 WHERE date_gl = ? AND gl_acct_id = ? AND ad_org_id = ?
                   AND doc_i_submod_id <=> ? AND gl_subacct_id <=> ? LIMIT 1",
                [$r->date_gl, $r->gl_acct_id, $r->ad_org_id, $r->doc_i_submod_id, $r->gl_subacct_id]
            );

            if (! $ex) {
                $db->insert(
                    "INSERT INTO acct_balance
                     (date_gl,doc_i_submod_id,ad_org_id,gl_acct_id,gl_subacct_id,debit,credit,is_active,created,date_created)
                     VALUES (?,?,?,?,?,?,?,1,'SYSTEM',NOW())",
                    [$r->date_gl, $r->doc_i_submod_id, $r->ad_org_id, $r->gl_acct_id, $r->gl_subacct_id, $dr, $cr]
                );
                $inserted++;
            } else {
                $net = ((float) $ex->debit) - ((float) $ex->credit) + $dr - $cr;
                $nd = $net < 0 ? 0.0 : $net;
                $nc = $net < 0 ? -$net : 0.0;
                $db->update(
                    "UPDATE acct_balance SET debit=?, credit=?, updated='SYSTEM', date_updated=NOW()
                     WHERE acct_balance_id=?",
                    [$nd, $nc, $ex->acct_balance_id]
                );
                $updated++;
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        echo "ERROR (rolled back): " . $e->getMessage() . "\n";
        throw $e;
    }

    // 3. Report: before -> after per (org|acct).
    $nm = [11313 => 'CMI-Suspense', 21138 => 'GRNI', 92005 => 'InputTax'];
    echo "\n  org|acct        (name)          before        + added   =  projected\n";
    echo "  " . str_repeat('-', 72) . "\n";
    ksort($added);
    foreach ($added as $ak => $add) {
        [$org, $acct] = explode('|', $ak);
        $b = $before[$ak] ?? 0.0;
        printf("  %-15s %-12s %14s %14s   %14s\n",
            $ak, $nm[$acct] ?? '', number_format($b, 2), number_format($add, 2), number_format($b + $add, 2));
    }

    echo "\nCOMMITTED. keys applied=" . count($keys) . " (inserted=$inserted, updated=$updated)\n";
    printf("elapsed %.1fs\n", microtime(true) - $t0);
    if (isset($cmd)) $cmd->info("acct_balance backfill: {$inserted} inserted, {$updated} updated.");
};
