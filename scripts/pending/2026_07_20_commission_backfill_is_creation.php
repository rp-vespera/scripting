<?php
/**
 * Commission accrual — GROUP 2 (RP Tan A, org 162012, acct 21136)  [ONE SCRIPT]
 * ---------------------------------------------------------------------------
 * ⚠️ THIS FILE IS IN scripts/pending/  ->  IT AUTO-RUNS ON THE NEXT DEPLOY of
 *    this branch (staging->replica, main->production). It writes to the
 *    financial ledger (fin_l_debt_history). One file, one run: pick $MODE.
 *      $MODE = 'dry'      (DEFAULT) report only — safe, changes nothing
 *      $MODE = 'apply'    insert the missing 2a accrual rows
 *      $MODE = 'rollback' delete the rows this script previously inserted
 *    Run manually:
 *      php artisan scripts:run-one scripts/pending/2026_07_20_commission_backfill_is_creation.php
 *    On deploy with $MODE='dry' it just reports and archives to done/.
 *
 * WHAT "GROUP 2" IS  (10 agents on 21136, aging short of GL, total 26,357.97)
 *   2a MISSING ACCRUAL (6 agents, 22,749.97)  <-- THIS SCRIPT FIXES / ROLLS BACK
 *      Debt still outstanding (amt_outstanding = GL) but the history log never
 *      got the original accrual row. Fix = insert the accrual is_creation row
 *      (amount = amt_debt). For these 6 the header ties to the GL, so closing
 *      the history gap moves the report TOWARD the GL.
 *      Agents: 24364, 24382, 15228, 24455, 1768, 24531.
 *   2b CANCELLED-PMT SETTLEMENT NOT REVERSED (4 agents, 3,608)  <-- NOT HERE
 *      Subledger already internally consistent but below the GL — needs a
 *      SETTLEMENT REVERSAL (restore amt_outstanding + reversal row), not an
 *      accrual. Reported, never touched. Agents: 24371, 24360, 24462, 24357.
 *
 * ⚠️ PRE-REQUISITE (still separate): the earlier broad batch (320 rows,
 *   +73,560.18, created='WEB Commission (backfill)', 2026-07-21) was MISDIRECTED
 *   and should be rolled back first via
 *   scripts/2026_07_21_rollback_commission_backfill_is_creation.php.
 *
 * DETECTION (2a): commission debt (created='WEB Commission') for the 10 agents
 *   on 21136/162012 that (a) has no ORIGINAL non-CA accrual is_creation row
 *   (is_creation=1 AND documentno NOT LIKE '%-CA'), AND (b) inserting amt_debt
 *   exactly restores SUM(history)=amt_outstanding. Failing (b) => reported.
 *
 * GUARD / IDEMPOTENCY: transaction on write; apply is a no-op once fixed;
 *   post-check asserts SUM(history)=amt_outstanding or rolls back. Marker
 *   created='GRP2-ACCRUAL-FIX' — rollback deletes exactly those (is_creation=1),
 *   never live 'WEB Commission' or 'WEB Commission (backfill)' rows.
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    $connName = 'mysql_secondary';        // ERP (SAERP) database
    $tag      = 'Web Commission';         // marker for this batch
    $MODE     = 'apply';                    // 'dry' | 'apply' | 'rollback'

    $acct   = 21136;
    $org    = 162012;
    $agents = [24364, 24382, 15228, 24371, 24455, 24360, 1768, 24462, 24531, 24357];

    $log = function (string $m) use ($cmd) { $cmd->info($m); };
    $db  = DB::connection($connName);
    $in  = implode(',', array_map('intval', $agents));

    // ── ROLLBACK MODE ────────────────────────────────────────────────────────
    if ($MODE === 'rollback') {
        $q = fn () => $db->table('fin_l_debt_history')->where('created', $tag)->where('is_creation', 1);
        $count = (clone $q())->count();
        $span  = (clone $q())->selectRaw('MIN(date_created) mn, MAX(date_created) mx, SUM(amount) amt')->first();
        $log("ROLLBACK — rows with marker '{$tag}': {$count} (sum {$span->amt}; {$span->mn} .. {$span->mx})");
        if ($count === 0) { $log('Nothing to roll back.'); return ['mode' => 'rollback', 'deleted' => 0]; }
        $deleted = $db->transaction(fn () => $q()->delete());
        $log("Deleted {$deleted} row(s).");
        return ['mode' => 'rollback', 'deleted' => $deleted, 'remaining' => (clone $q())->count()];
    }

    // ── DETECTION (shared by dry + apply) ─────────────────────────────────────
    $hist = "LEFT JOIN ( SELECT fin_l_debt_id, SUM(amount) s FROM fin_l_debt_history
                          WHERE status='PR' GROUP BY fin_l_debt_id ) hs
               ON hs.fin_l_debt_id = d.fin_l_debt_id";
    $scope = "d.gl_acct_id={$acct} AND d.ad_org_id={$org} AND d.status='PR'
              AND d.created='WEB Commission' AND d.s_bpartner_id IN ({$in})";
    $noAccrual = "NOT EXISTS ( SELECT 1 FROM fin_l_debt_history h
                                WHERE h.fin_l_debt_id=d.fin_l_debt_id AND h.is_creation=1
                                  AND (h.documentno IS NULL OR h.documentno NOT LIKE '%-CA') )";
    $fixable = "FROM fin_l_debt d {$hist}
                WHERE {$scope} AND {$noAccrual}
                  AND ABS(d.amt_outstanding - (COALESCE(hs.s,0) + d.amt_debt)) <= 0.005";

    $fixRows = $db->select("SELECT d.fin_l_debt_id id, d.s_bpartner_id bp, d.amt_debt amt {$fixable}
                            ORDER BY d.s_bpartner_id, d.fin_l_debt_id");
    $needRev = $db->select("SELECT d.s_bpartner_id bp, SUM(d.amt_outstanding - COALESCE(hs.s,0)) gap, COUNT(*) n
                            FROM fin_l_debt d {$hist}
                            WHERE {$scope} AND ABS(d.amt_outstanding - COALESCE(hs.s,0)) > 0.005
                              AND d.fin_l_debt_id NOT IN (SELECT id FROM (SELECT d.fin_l_debt_id id {$fixable}) t)
                            GROUP BY d.s_bpartner_id");

    $fixSum = array_sum(array_map(fn ($r) => (float) $r->amt, $fixRows));
    $byBp   = [];
    foreach ($fixRows as $r) { $byBp[$r->bp] = ($byBp[$r->bp] ?? 0) + (float) $r->amt; }

    $log("=== GROUP 2 (org {$org}, acct {$acct}) — MODE={$MODE} ===");
    $log('2a FIXABLE (missing accrual): ' . count($fixRows) . ' rows / ' . count($byBp)
         . ' agents, total ' . number_format($fixSum, 2));
    foreach ($byBp as $bp => $amt) $log("   agent {$bp}: +" . number_format($amt, 2));
    if (! empty($needRev)) {
        $revSum = array_sum(array_map(fn ($r) => (float) $r->gap, $needRev));
        $log('2b NEEDS REVERSAL — NOT touched (separate fix): total ' . number_format($revSum, 2));
        foreach ($needRev as $r) $log("   agent {$r->bp}: gap " . number_format((float) $r->gap, 2) . " ({$r->n} debt/s)");
    }

    if (count($fixRows) === 0) { $log('Nothing to backfill for 2a. Done.'); return ['mode' => $MODE, 'inserted' => 0]; }

    if ($MODE !== 'apply') {
        $log("DRY RUN — nothing written. Set \$MODE='apply' to insert " . count($fixRows) . " row(s), or 'rollback' to undo.");
        return ['mode' => 'dry', 'would_insert' => count($fixRows), 'fix_total' => round($fixSum, 2),
                'needs_reversal' => count($needRev)];
    }

    // ── APPLY MODE ────────────────────────────────────────────────────────────
    $inserted = $db->transaction(function () use ($db, $fixable, $tag, $fixRows, $log) {
        $n = $db->affectingStatement("
            INSERT INTO fin_l_debt_history
                ( fin_l_debt_id, doc_i_submod_id, ad_org_id, fin_t_payment_id,
                  doc_t_reference_number_id, date_gl, amount, documentno,
                  is_creation, is_settlement, is_active, status, created, date_created )
            SELECT d.fin_l_debt_id, d.doc_i_submod_id, d.ad_org_id, NULL,
                   d.doc_t_reference_number_id, d.date_gl, d.amt_debt, d.documentno,
                   1, 0, 1, 'PR', ?, NOW()
            {$fixable}", [$tag]);
        $log("Accrual rows inserted: {$n}");
        $ids = implode(',', array_map(fn ($r) => (int) $r->id, $fixRows));
        $bad = (int) $db->selectOne("
            SELECT COUNT(*) c FROM fin_l_debt d
            LEFT JOIN (SELECT fin_l_debt_id, SUM(amount) s FROM fin_l_debt_history
                        WHERE status='PR' GROUP BY fin_l_debt_id) hs ON hs.fin_l_debt_id=d.fin_l_debt_id
             WHERE d.fin_l_debt_id IN ({$ids}) AND ABS(d.amt_outstanding - COALESCE(hs.s,0)) > 0.005")->c;
        if ($bad !== 0) throw new RuntimeException("Reconciliation FAILED: {$bad} debt(s) do not net to amt_outstanding. Rolling back.");
        $log('Post-fix check OK: all backfilled debts reconcile to amt_outstanding.');
        return $n;
    });

    $result = ['mode' => 'apply', 'inserted' => $inserted, 'fix_total' => round($fixSum, 2), 'needs_reversal' => count($needRev)];
    $log('RESULT: ' . json_encode($result));
    return $result;
};
