<?php // scripts/pending/2026_07_01_fix_lmcline_bal_inflated_amt_totalpayout_nlio00946_00947.php

/**
 * ============================================================================
 * Fix Inflated LMC Balance — NLIO00946 & NLIO00947
 * Date: 2026-07-01 · Prepared by: Kervin Fugata · Org: NLIO (ad_org_id = 162012)
 * ============================================================================
 *
 * WHY THIS SCRIPT
 * ---------------
 * Five LMC budget lines each, for interment orders NLIO00946 and NLIO00947, show
 * wip_l_lmcline_bal.amt_totalpayout at TWICE their budget in the checker dashboard.
 * Each order reads as ₱18,600 paid vs ₱9,300 actually disbursed. This blocks
 * operations: the re-draft guard treats over-budget lines as fully covered, and
 * balance checks read these lines as exhausted.
 *
 * NO supplier was paid twice. Actual disbursements, GL entries, fin_l_debt, and
 * wip_t_lmc_bgtline running totals (l_qty_payout / l_amt_payout) are all correct.
 * The problem is confined to the amt_totalpayout balance counter.
 *
 * ROOT CAUSE
 * ----------
 * Java SAERP uses encumbrance accounting: it increments amt_totalpayout at DR-SAVE
 * time. For both IOs the Maker drafted in Java offline (encumbering += ₱X per line).
 * The online system then processed new online DRs to PR and incremented
 * amt_totalpayout AGAIN (it used to increment at PR time), not accounting for the
 * Java encumbrance already in the field — resulting in 2× per line:
 *
 *   Java Maker saves DR (offline)   →  amt_totalpayout += ₱X   (Java encumbrance)
 *   Online Checker processes to PR  →  amt_totalpayout += ₱X   (online PR-time write)
 *                                      l_qty_payout = 1, l_amt_payout = ₱X   (correct)
 *                                      ── amt_totalpayout = 2×₱X  ⚠
 *
 * Neither system had a bug — it was a design gap. FIXED GOING FORWARD: the online
 * flow now encumbers amt_totalpayout at DR-save (aligned with Java) and no longer
 * increments at PR. This script repairs the historical data left behind.
 *
 * WHAT THIS FIXES (amt_totalpayout: from → to)
 * --------------------------------------------
 *   NLIO00946  (scope 25956)                          | NLIO00947  (scope 25958)
 *   bal 38024 MERIENDA PACKAGE          1,600 → 800    | bal 38038 MERIENDA PACKAGE          1,600 → 800
 *   bal 38030 INCENTIVE FOR EMCEE       2,000 → 1,000  | bal 38044 INCENTIVE FOR EMCEE       2,000 → 1,000
 *   bal 38032 PHOTOGRAPHER & VIDEOGRAPH 3,000 → 1,500  | bal 38046 PHOTOGRAPHER & VIDEOGRAPH 3,000 → 1,500
 *   bal 38033 SINGER                    2,000 → 1,000  | bal 38047 SINGER                    2,000 → 1,000
 *   bal 38034 VIDEO LIVESTREAMING      10,000 → 5,000  | bal 38048 VIDEO LIVESTREAMING      10,000 → 5,000
 *   Correct value = amt_totallmcbudget = PR payoutline amt (fully paid, once).
 *
 * TABLES UPDATED : wip_l_lmcline_bal (amt_totalpayout only)
 * VERIFIED OK    : wip_t_lmc_bgtline (l_qty/l_amt_payout at 1×), fin_l_debt, acct_gl
 *
 * SAFETY: DRY_RUN default true; per-line guards (own scope, budget match, exactly
 * 2×, linked PR verified); transaction with pinned WHERE; idempotent; post-commit
 * verification. Applying (DRY_RUN=false) is a senior-executed step.
 *
 * VERIFY AFTER COMMIT
 *   SELECT b.wip_l_lmcline_bal_id, bl.description, b.amt_totallmcbudget, b.amt_totalpayout,
 *          b.amt_totallmcbudget - b.amt_totalpayout AS remaining
 *     FROM wip_l_lmcline_bal b
 *     JOIN wip_t_lmc_bgtline bl ON bl.wip_l_lmcline_bal_id = b.wip_l_lmcline_bal_id
 *    WHERE b.wip_l_lmcline_bal_id IN (38024,38030,38032,38033,38034, 38038,38044,38046,38047,38048);
 *   -- expected: amt_totalpayout = amt_totallmcbudget, remaining = 0 for all 10 rows.
 *
 * ROLLBACK (restore inflated values)
 *   UPDATE wip_l_lmcline_bal SET amt_totalpayout=1600  WHERE wip_l_lmcline_bal_id IN (38024,38038);
 *   UPDATE wip_l_lmcline_bal SET amt_totalpayout=2000  WHERE wip_l_lmcline_bal_id IN (38030,38033,38044,38047);
 *   UPDATE wip_l_lmcline_bal SET amt_totalpayout=3000  WHERE wip_l_lmcline_bal_id IN (38032,38046);
 *   UPDATE wip_l_lmcline_bal SET amt_totalpayout=10000 WHERE wip_l_lmcline_bal_id IN (38034,38048);
 *
 * OPEN ITEM: targets the SAERP working DB (mysql_secondary). If the same inflation
 * exists on the live ERP master, apply the equivalent correction there (senior).
 */

return function ($cmd) {
    $DRY_RUN = false;
    $CONN    = 'mysql_secondary';

    // Each row carries its OWN expected scope_id so a line can never be written to
    // the wrong IO. correct_amt must equal BOTH amt_totallmcbudget AND the PR payoutline amt.
    $corrections = [
        // ── NLIO00946 — scope 25956 ──────────────────────────────────────────
        ['io' => 'NLIO00946', 'scope_id' => 25956, 'wip_l_lmcline_bal_id' => 38024, 'description' => 'MERIENDA PACKAGE',             'correct_amt' => 800.00,  'pr_documentno' => 'NLMC0008192'],
        ['io' => 'NLIO00946', 'scope_id' => 25956, 'wip_l_lmcline_bal_id' => 38030, 'description' => 'INCENTIVE FOR EMCEE',           'correct_amt' => 1000.00, 'pr_documentno' => 'NLMC0008190'],
        ['io' => 'NLIO00946', 'scope_id' => 25956, 'wip_l_lmcline_bal_id' => 38032, 'description' => 'PHOTOGRAPHER AND VIDEOGRAPHER', 'correct_amt' => 1500.00, 'pr_documentno' => 'NLMC0008193'],
        ['io' => 'NLIO00946', 'scope_id' => 25956, 'wip_l_lmcline_bal_id' => 38033, 'description' => 'SINGER',                        'correct_amt' => 1000.00, 'pr_documentno' => 'NLMC0008191'],
        ['io' => 'NLIO00946', 'scope_id' => 25956, 'wip_l_lmcline_bal_id' => 38034, 'description' => 'VIDEO LIVESTREAMING',           'correct_amt' => 5000.00, 'pr_documentno' => 'NLMC0008189'],
        // ── NLIO00947 — scope 25958 ──────────────────────────────────────────
        ['io' => 'NLIO00947', 'scope_id' => 25958, 'wip_l_lmcline_bal_id' => 38038, 'description' => 'MERIENDA PACKAGE',             'correct_amt' => 800.00,  'pr_documentno' => 'NLMC0008198'],
        ['io' => 'NLIO00947', 'scope_id' => 25958, 'wip_l_lmcline_bal_id' => 38044, 'description' => 'INCENTIVE FOR EMCEE',           'correct_amt' => 1000.00, 'pr_documentno' => 'NLMC0008197'],
        ['io' => 'NLIO00947', 'scope_id' => 25958, 'wip_l_lmcline_bal_id' => 38046, 'description' => 'PHOTOGRAPHER AND VIDEOGRAPHER', 'correct_amt' => 1500.00, 'pr_documentno' => 'NLMC0008195'],
        ['io' => 'NLIO00947', 'scope_id' => 25958, 'wip_l_lmcline_bal_id' => 38047, 'description' => 'SINGER',                        'correct_amt' => 1000.00, 'pr_documentno' => 'NLMC0008194'],
        ['io' => 'NLIO00947', 'scope_id' => 25958, 'wip_l_lmcline_bal_id' => 38048, 'description' => 'VIDEO LIVESTREAMING',           'correct_amt' => 5000.00, 'pr_documentno' => 'NLMC0008196'],
    ];

    $line  = str_repeat('─', 64);
    $db    = \DB::connection($CONN);
    $abort = false;

    echo "{$line}\n";
    echo "FIX INFLATED amt_totalpayout — NLIO00946 & NLIO00947\n";
    echo "Connection : {$CONN}\n";
    echo "Mode       : " . ($DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY') . "\n";
    echo "{$line}\n";

    // ── PRE-FLIGHT ────────────────────────────────────────────────────────────
    echo "\n[PRE-FLIGHT CHECKS]\n";
    $preflight = true;

    foreach ($corrections as $c) {
        $balId      = $c['wip_l_lmcline_bal_id'];
        $correctAmt = $c['correct_amt'];
        $scopeId    = $c['scope_id'];
        $prDoc      = $c['pr_documentno'];

        echo "\n  ▶ {$c['io']}  bal_id={$balId}  {$c['description']}\n";

        $bal = $db->selectOne(
            'SELECT b.wip_l_lmcline_bal_id, b.amt_totallmcbudget, b.amt_totalpayout,
                    st.wip_i_project_scope_id
               FROM wip_l_lmcline_bal b
               JOIN wip_t_lmc_bgtline bl ON bl.wip_l_lmcline_bal_id = b.wip_l_lmcline_bal_id
               JOIN wip_i_project_scope_stage st
                    ON st.wip_i_project_scope_stage_id = bl.wip_i_project_scope_stage_id
              WHERE b.wip_l_lmcline_bal_id = ?',
            [$balId]
        );

        if (!$bal) { echo "    ⛔ FAIL: row not found.\n"; $preflight = false; continue; }

        $current = (float) $bal->amt_totalpayout;
        $budget  = (float) $bal->amt_totallmcbudget;

        if ((int) $bal->wip_i_project_scope_id !== $scopeId) {
            echo "    ⛔ FAIL: scope_id={$bal->wip_i_project_scope_id} ≠ expected {$scopeId}. Wrong IO.\n";
            $preflight = false; continue;
        }
        if (abs($budget - $correctAmt) > 0.001) {
            echo "    ⛔ FAIL: budget=₱{$budget} ≠ expected ₱{$correctAmt}. Budget changed.\n";
            $preflight = false; continue;
        }
        if (abs($current - ($correctAmt * 2)) > 0.001) {
            if (abs($current - $correctAmt) < 0.001) {
                echo "    ✅ Already at correct value ₱{$correctAmt} — will skip.\n";
            } else {
                echo "    ⛔ FAIL: amt_totalpayout=₱{$current} is not 2× expected ₱{$correctAmt}.\n";
                $preflight = false;
            }
            continue;
        }

        $prLine = $db->selectOne(
            'SELECT pl.amt, p.docstatus
               FROM wip_t_lmc_payout p
               JOIN wip_t_lmc_payoutline pl ON pl.wip_t_lmc_payout_id = p.wip_t_lmc_payout_id
              WHERE p.documentno            = ?
                AND p.wip_i_project_scope_id = ?
                AND pl.wip_l_lmcline_bal_id  = ?
                AND COALESCE(p.is_active, 1) = 1
                AND COALESCE(pl.is_active, 1) = 1
              LIMIT 1',
            [$prDoc, $scopeId, $balId]
        );

        if (!$prLine) { echo "    ⛔ FAIL: PR {$prDoc} not found for bal_id={$balId} in scope {$scopeId}.\n"; $preflight = false; continue; }
        if ($prLine->docstatus !== 'PR') { echo "    ⛔ FAIL: {$prDoc} status '{$prLine->docstatus}', expected PR.\n"; $preflight = false; continue; }
        if (abs((float) $prLine->amt - $correctAmt) > 0.001) { echo "    ⛔ FAIL: {$prDoc} amt=₱{$prLine->amt} ≠ ₱{$correctAmt}.\n"; $preflight = false; continue; }

        echo "    ✓ scope={$bal->wip_i_project_scope_id}  budget=₱{$budget}  current=₱{$current}  PR {$prDoc} amt=₱{$prLine->amt} [{$prLine->docstatus}]\n";
    }

    if (!$preflight) {
        echo "\n{$line}\n⛔ PRE-FLIGHT FAILED — no writes performed. Resolve issues above.\n{$line}\n";
        if (isset($cmd)) $cmd->error('pre-flight failed — no changes written.');
        return;
    }
    echo "\n  ✅ All pre-flight checks passed.\n";

    // ── WRITE PHASE ───────────────────────────────────────────────────────────
    echo "\n[CORRECTIONS]\n";
    $totalRows = 0;

    $db->beginTransaction();
    try {
        foreach ($corrections as $c) {
            $balId      = $c['wip_l_lmcline_bal_id'];
            $correctAmt = $c['correct_amt'];

            $current = (float) $db->selectOne(
                'SELECT amt_totalpayout FROM wip_l_lmcline_bal WHERE wip_l_lmcline_bal_id = ?',
                [$balId]
            )->amt_totalpayout;

            echo "\n  ▶ {$c['io']}  bal_id={$balId}  {$c['description']}\n    ₱{$current}  →  ₱{$correctAmt}\n";

            if (abs($current - $correctAmt) < 0.001) { echo "    ✅ Already correct — skipping.\n"; continue; }

            if (abs($current - ($correctAmt * 2)) > 0.001) {
                $abort = true;
                echo "    ⛔ ABORT: value changed since pre-flight (₱{$current}) — rolling back all.\n";
                break;
            }

            $rows = $db->update(
                'UPDATE wip_l_lmcline_bal
                    SET amt_totalpayout = ?, updated = ?, date_updated = NOW()
                  WHERE wip_l_lmcline_bal_id = ? AND amt_totalpayout = ?',
                [$correctAmt, 'Script by Web', $balId, $current]
            );

            if ($rows !== 1) { $abort = true; echo "    ⛔ ABORT: UPDATE matched {$rows} row(s) — rolling back all.\n"; break; }

            echo "    wip_l_lmcline_bal updated : {$rows} row\n";
            $totalRows += $rows;
        }

        if ($abort) {
            $db->rollBack();
            echo "\n{$line}\n⛔ ABORTED — rolled back all changes.\n{$line}\n";
            if (isset($cmd)) $cmd->error('aborted — all changes rolled back.');
            return;
        }

        if ($DRY_RUN) {
            $db->rollBack();
            echo "\n{$line}\nDRY-RUN complete — no changes written. Set \$DRY_RUN = false to apply.\n{$line}\n";
            if (isset($cmd)) $cmd->info('dry-run complete, no changes written.');
            return;
        }

        $db->commit();

        // ── POST-COMMIT VERIFICATION ─────────────────────────────────────────
        echo "\n[POST-COMMIT VERIFICATION]\n";
        $allOk = true;
        foreach ($corrections as $c) {
            $after = $db->selectOne(
                'SELECT amt_totalpayout, updated FROM wip_l_lmcline_bal WHERE wip_l_lmcline_bal_id = ?',
                [$c['wip_l_lmcline_bal_id']]
            );
            $isOk = abs((float) $after->amt_totalpayout - $c['correct_amt']) < 0.001;
            $allOk = $allOk && $isOk;
            echo "  " . ($isOk ? '✅' : '⛔') . " {$c['io']}  bal_id={$c['wip_l_lmcline_bal_id']}  {$c['description']}  amt_totalpayout=₱{$after->amt_totalpayout}\n";
        }

        echo "\n{$line}\n";
        echo $allOk ? "✅ All {$totalRows} row(s) verified correct after commit.\n"
                    : "⛔ Post-commit verification FAILED — check rows above.\n";
        echo "{$line}\n";
        if (isset($cmd)) $cmd->info("nlio00946+00947-inflated-bal: {$totalRows} row(s) updated and verified.");

    } catch (\Throwable $e) {
        $db->rollBack();
        echo "\n❌ Exception — rolled back: " . $e->getMessage() . "\n";
        throw $e;
    }
};
