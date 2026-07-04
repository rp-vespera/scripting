<?php // scripts/pending/2026_07_04_backfill_acct_balance_agrigr_gr.php
/**
 * ============================================================================
 * REPAIR acct_balance for AGRIGR GR postings.  (IDEMPOTENT)
 * Date: 2026-07-04 · Target: mysql_secondary (SAS SAERPRP)
 * ============================================================================
 *
 * WHY: SAERP ledger reports (Inventory Integrity per Product Category, etc.)
 * read balances from acct_balance. AGRIGR's GR (PoStatusController) wrote
 * acct_gl only — the GR-side entries never reached the rollup, draining
 * CMI-Suspense (11313) deeply negative and desyncing the stock-card from GL.
 *
 * WHAT IT DOES: for every acct_balance key that has an AGRIGR GR-side row
 * (created='SYSTEM'), it enforces the correct invariant
 *
 *       acct_balance(key) = net of acct_gl(key)
 *
 * key = (date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id).
 * It SETS the balance (not add), so it is IDEMPOTENT and ORDER-INDEPENDENT:
 * run it any number of times, before or after the PoStatusController code fix
 * is deployed — it always lands on the same correct value (0 delta on re-run).
 *
 * Accounts in scope: 11313 (CMI Suspense), 21138 (GRNI), 92005 (Input Tax).
 * 11309 is IGR-only (already balanced) — NOT touched.
 *
 * TRACEABILITY: every row this script writes is marked updated='SCRIPT-WEB'
 * (inserts also created='SCRIPT-WEB'). Find/rollback via that marker.
 *
 * WHAT IT TOUCHES: acct_balance only. Reads acct_gl (never modified).
 * Verified: no native (non-SYSTEM) acct_balance row shares an AGRIGR-GR key,
 * so only AGRIGR-originated balance rows are affected. This script COMMITS.
 */

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    set_time_limit(0);
    echo "*** COMMIT (idempotent) — writing acct_balance [updated=SCRIPT-WEB] ***\n";
    $t0 = microtime(true);

    // 1. Correct net(acct_gl) per key, restricted to keys that have an AGRIGR
    //    GR-side row (created='SYSTEM' on the GR side of these accounts).
    $keys = $db->select("
      SELECT date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id,
             ROUND(SUM(debit),2)  AS dr,
             ROUND(SUM(credit),2) AS cr
      FROM acct_gl
      WHERE gl_acct_id IN (11313,21138,92005)
      GROUP BY date_gl, doc_i_submod_id, gl_acct_id, ad_org_id, gl_subacct_id
      HAVING SUM(CASE WHEN created='SYSTEM' AND (
                   (gl_acct_id = 11313 AND debit  > 0) OR
                   (gl_acct_id = 21138 AND credit > 0) OR
                   (gl_acct_id = 92005 AND debit  > 0)) THEN 1 ELSE 0 END) > 0");
    echo "acct_balance keys to repair: " . count($keys) . "\n";

    // 2. Current full-account balances (before) for the report.
    $before = [];
    foreach ($db->select("
        SELECT ad_org_id, gl_acct_id, ROUND(SUM(debit)-SUM(credit),2) net
        FROM acct_balance WHERE gl_acct_id IN (11313,21138,92005)
        GROUP BY ad_org_id, gl_acct_id") as $r) {
        $before[$r->ad_org_id.'|'.$r->gl_acct_id] = (float) $r->net;
    }

    $delta = [];          // (org|acct) => net change applied
    $inserted = 0; $updated = 0;

    $db->beginTransaction();
    try {
        foreach ($keys as $r) {
            $target = round((float) $r->dr - (float) $r->cr, 2);   // acct_balance(key) MUST equal net(acct_gl)

            $ex = $db->selectOne(
                "SELECT acct_balance_id, debit, credit FROM acct_balance
                 WHERE date_gl = ? AND gl_acct_id = ? AND ad_org_id = ?
                   AND doc_i_submod_id <=> ? AND gl_subacct_id <=> ? LIMIT 1",
                [$r->date_gl, $r->gl_acct_id, $r->ad_org_id, $r->doc_i_submod_id, $r->gl_subacct_id]
            );
            $cur = $ex ? round(((float) $ex->debit) - ((float) $ex->credit), 2) : 0.0;
            $ak  = $r->ad_org_id.'|'.$r->gl_acct_id;
            $delta[$ak] = round(($delta[$ak] ?? 0) + ($target - $cur), 2);

            $nd = $target < 0 ? 0.0 : $target;
            $nc = $target < 0 ? round(-$target, 2) : 0.0;

            if (! $ex) {
                $db->insert(
                    "INSERT INTO acct_balance
                     (date_gl,doc_i_submod_id,ad_org_id,gl_acct_id,gl_subacct_id,debit,credit,is_active,created,date_created,updated,date_updated)
                     VALUES (?,?,?,?,?,?,?,1,'SCRIPT-WEB',NOW(),'SCRIPT-WEB',NOW())",
                    [$r->date_gl, $r->doc_i_submod_id, $r->ad_org_id, $r->gl_acct_id, $r->gl_subacct_id, $nd, $nc]
                );
                $inserted++;
            } else {
                $db->update(
                    "UPDATE acct_balance SET debit=?, credit=?, updated='SCRIPT-WEB', date_updated=NOW()
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

    // 3. Report: current -> projected (= current + delta). Re-run => delta 0.
    $nm = [11313 => 'CMI-Suspense', 21138 => 'GRNI', 92005 => 'InputTax'];
    echo "\n  org|acct        (name)            current        + change   =  projected\n";
    echo "  " . str_repeat('-', 74) . "\n";
    ksort($delta);
    foreach ($delta as $ak => $d) {
        [$org, $acct] = explode('|', $ak);
        $b = $before[$ak] ?? 0.0;
        printf("  %-15s %-12s %14s %14s   %14s\n",
            $ak, $nm[$acct] ?? '', number_format($b, 2), number_format($d, 2), number_format($b + $d, 2));
    }

    echo "\nCOMMITTED (idempotent). keys=" . count($keys) . " inserted=$inserted updated=$updated\n";
    printf("elapsed %.1fs\n", microtime(true) - $t0);
    if (isset($cmd)) $cmd->info("acct_balance repair: {$inserted} inserted, {$updated} updated (updated=SCRIPT-WEB).");
};
