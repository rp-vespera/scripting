<?php // scripts/pending/2026_08_05_fix_nlio_dedup_erlinda_13403_close_13392.php

/**
 * NLIO duplicate-project correction — Erlinda (NLIO00931) vs Superlativo (NLIO00934-CA).
 *
 * Two WIP projects existed for one interment. The COMMENCED project 13403 (which holds the
 * real, settled money) was wired to the CANCELLED Superlativo order 1865; the correct
 * beneficiary Erlinda's live order 1858 (NLIO00931) sat on the BUDGETING duplicate 13392.
 * The team repurposed 13403 for Erlinda (rename + processed payouts there). This script
 * finishes the alignment — identity only, ZERO money movement — and closes the duplicate.
 *
 * Operations (all on mysql_secondary, one atomic transaction):
 *   A1  bridge 1269  order 1865 -> 1858        (13403 now points at Erlinda's live order)
 *   A2  bridge 1265  is_active 1 -> 0          (release 13392's grip on order 1858)
 *   A6  wip_i_project 13403  gl_subacct 40907 -> 41009  (restore the true owned subaccount;
 *                                              matches locator x + all 14 GL postings)
 *   SH  wip_i_project 13403  std 629 -> 630    (self-heal a v1-test leftover; std must match
 *                                              the materialized scopes 25924/25925 -> std 630)
 *   B1  wip_t_lmc_payout 55506..55511  DR -> VO (void 6 draft payouts on 13392)
 *   B2  wip_i_project 13392  BUDGETING|HALTED -> CLOSED   (MD directive: CLOSE the duplicate)
 *   B3  wip_l_project_status_change  + CLOSED audit row for 13392 (what the ERP itself writes)
 *
 * Standard (std_id) is deliberately NOT flipped to 629 — the scopes/BOM/LMC are built from 630;
 * only the ₱158 vigil-candle price differs between 629/630. Name already reads "W/ MASS".
 *
 * Integrity: touches ONLY mp_t_interment_order_project, wip_i_project, wip_t_lmc_payout (drafts),
 * wip_l_project_status_change. It writes NOTHING to acct_gl, acct_balance, fin_l_debt,
 * fin_l_debt_history, fin_s_advances, fin_t_payment, wip_l_bomline_bal, or any stockcard table.
 * 13392 has 0 consumed materials, 0 GL rows, 0 debts — closing it strands/reverses nothing.
 *
 * Idempotent & self-healing: every step is guarded on its current value, so re-running is safe
 * and a partially-applied baseline still converges. Verifies in-transaction; any failed
 * assertion rolls the whole thing back and throws (deploy quarantines to failed/).
 */

return function ($cmd) {
    $DRY_RUN = false;                 // flip true for a local no-write rehearsal
    $CONN    = 'mysql_secondary';

    // ---- identifiers (all primary keys — globally unique, no org filter needed) ----
    $KEEP        = 13403;             // COMMENCED project -> becomes Erlinda's
    $DUP         = 13392;             // BUDGETING duplicate -> CLOSED
    $BRIDGE_KEEP = 1269;             // mp_t_interment_order_project row for 13403
    $BRIDGE_DUP  = 1265;             // ...row for 13392
    $IO_LIVE     = 1858;             // NLIO00931  (Erlinda, live)
    $IO_CANCEL   = 1865;             // NLIO00934-CA (Superlativo, cancelled)
    $SUBACCT     = 41009;            // 13403's OWNED WIP subaccount (correct)
    $SUBACCT_BAD = 40907;            // stray value left by the manual rename
    $STD_OK      = 630;              // W/O-MASS std the scopes were built from
    $STD_LEFT    = 629;              // v1-test leftover to heal
    $DRAFTS      = [55506, 55507, 55508, 55509, 55510, 55511];
    $PROC_PERSON = 1967;             // RP company person (status-change processor)
    $STAMP       = 'Script by Web';

    $line = str_repeat('─', 68);
    $db   = \DB::connection($CONN);

    echo "$line\n";
    echo "NLIO DEDUP — align 13403 to Erlinda (NLIO00931) + CLOSE duplicate 13392\n";
    echo "Connection : $CONN\n";
    echo "Mode       : " . ($DRY_RUN ? 'DRY-RUN (rolled back)' : 'APPLY') . "\n";
    echo "$line\n";

    $applied = 0;
    $stamp   = ['updated' => $STAMP, 'date_updated' => now()];

    $db->beginTransaction();
    try {
        // ---- A1: repoint 13403's order link to Erlinda's live order ----
        echo "\n▶ A1  bridge {$BRIDGE_KEEP}: order -> {$IO_LIVE} (NLIO00931)\n";
        $cur = (int) $db->table('mp_t_interment_order_project')
            ->where('mp_t_interment_order_project_id', $BRIDGE_KEEP)->value('mp_t_interment_order_id');
        if ($cur === $IO_LIVE) {
            echo "  ✅ already {$IO_LIVE} — skip.\n";
        } elseif ($cur === $IO_CANCEL) {
            $applied += $db->table('mp_t_interment_order_project')
                ->where('mp_t_interment_order_project_id', $BRIDGE_KEEP)
                ->where('mp_t_interment_order_id', $IO_CANCEL)
                ->update(['mp_t_interment_order_id' => $IO_LIVE] + $stamp);
            echo "  ✓ repointed {$IO_CANCEL} -> {$IO_LIVE}.\n";
        } else {
            throw new \RuntimeException("A1 unexpected current order {$cur} on bridge {$BRIDGE_KEEP}");
        }

        // ---- A2: release 13392's link to the live order ----
        echo "\n▶ A2  bridge {$BRIDGE_DUP}: is_active -> 0\n";
        $rows = $db->table('mp_t_interment_order_project')
            ->where('mp_t_interment_order_project_id', $BRIDGE_DUP)
            ->where('wip_i_project_id', $DUP)
            ->where('mp_t_interment_order_id', $IO_LIVE)
            ->where('is_active', 1)
            ->update(['is_active' => 0] + $stamp);
        echo $rows ? "  ✓ released.\n" : "  ✅ already released — skip.\n";
        $applied += $rows;

        // ---- A6: restore true WIP subaccount pointer on 13403 ----
        echo "\n▶ A6  project {$KEEP}: gl_subacct_id -> {$SUBACCT}\n";
        $sub = (int) $db->table('wip_i_project')->where('wip_i_project_id', $KEEP)->value('gl_subacct_id');
        if ($sub === $SUBACCT) {
            echo "  ✅ already {$SUBACCT} — skip.\n";
        } elseif ($sub === $SUBACCT_BAD) {
            $applied += $db->table('wip_i_project')->where('wip_i_project_id', $KEEP)
                ->where('gl_subacct_id', $SUBACCT_BAD)->update(['gl_subacct_id' => $SUBACCT] + $stamp);
            echo "  ✓ restored {$SUBACCT_BAD} -> {$SUBACCT}.\n";
        } else {
            echo "  ⚠ gl_subacct_id is {$sub} (neither {$SUBACCT} nor {$SUBACCT_BAD}) — left untouched, review.\n";
        }

        // ---- SH: self-heal standard leftover (629 -> 630); no-op on pristine live ----
        echo "\n▶ SH  project {$KEEP}: std {$STD_LEFT} -> {$STD_OK} (if leftover)\n";
        $healed = $db->table('wip_i_project')->where('wip_i_project_id', $KEEP)
            ->where('wip_i_project_std_id', $STD_LEFT)->update(['wip_i_project_std_id' => $STD_OK] + $stamp);
        echo $healed ? "  ✓ healed std -> {$STD_OK}.\n" : "  ✅ std already {$STD_OK} — skip.\n";
        $applied += $healed;

        // ---- B1: void the 6 draft payouts on 13392 ----
        echo "\n▶ B1  payouts " . implode(',', $DRAFTS) . ": DR -> VO\n";
        $voided = $db->table('wip_t_lmc_payout')->whereIn('wip_t_lmc_payout_id', $DRAFTS)
            ->where('docstatus', 'DR')->update(['docstatus' => 'VO', 'is_active' => 0] + $stamp);
        echo "  ✓ voided {$voided} draft(s)" . ($voided < count($DRAFTS) ? " (rest already VO).\n" : ".\n");
        $applied += $voided;

        // ---- B2: CLOSE the duplicate (accept BUDGETING or a prior HALTED) ----
        echo "\n▶ B2  project {$DUP}: status -> CLOSED\n";
        $status = $db->table('wip_i_project')->where('wip_i_project_id', $DUP)->value('project_status');
        if ($status === 'CLOSED') {
            echo "  ✅ already CLOSED — skip.\n";
        } elseif (in_array($status, ['BUDGETING', 'HALTED'], true)) {
            $applied += $db->table('wip_i_project')->where('wip_i_project_id', $DUP)
                ->whereIn('project_status', ['BUDGETING', 'HALTED'])
                ->update(['project_status' => 'CLOSED'] + $stamp);
            echo "  ✓ {$status} -> CLOSED.\n";
        } else {
            throw new \RuntimeException("B2 unexpected status '{$status}' on project {$DUP}");
        }

        // ---- B3: write the status-change audit row the ERP would write (idempotent) ----
        echo "\n▶ B3  wip_l_project_status_change: + CLOSED row for {$DUP}\n";
        $exists = $db->table('wip_l_project_status_change')
            ->where('wip_i_project_id', $DUP)->where('project_status', 'CLOSED')->exists();
        if ($exists) {
            echo "  ✅ CLOSED audit row already present — skip.\n";
        } else {
            $sbp = $db->table('bpar_i_person')->where('bpar_i_person_id', $PROC_PERSON)->value('s_bpartner_id');
            $db->table('wip_l_project_status_change')->insert([
                'wip_i_project_id'         => $DUP,
                's_bpartner_id_process'    => $sbp,
                'bpar_i_person_id_process' => $PROC_PERSON,
                'project_status'           => 'CLOSED',
                'date_process'             => now(),
                'date_created'             => now(),
                'is_active'                => 1,
            ]);
            echo "  ✓ inserted CLOSED audit row (s_bpartner={$sbp}).\n";
            $applied += 1;
        }

        // ---- VERIFY (in-transaction): assert the aligned end-state ----
        echo "\n$line\nVERIFY\n$line\n";
        $checks = [];
        $checks['A1 order=1858']      = (int) $db->table('mp_t_interment_order_project')->where('mp_t_interment_order_project_id', $BRIDGE_KEEP)->value('mp_t_interment_order_id') === $IO_LIVE;
        $checks['A2 bridge inactive'] = (int) $db->table('mp_t_interment_order_project')->where('mp_t_interment_order_project_id', $BRIDGE_DUP)->value('is_active') === 0;
        $checks['A6 subacct=41009']   = (int) $db->table('wip_i_project')->where('wip_i_project_id', $KEEP)->value('gl_subacct_id') === $SUBACCT;
        $checks['STD=630']            = (int) $db->table('wip_i_project')->where('wip_i_project_id', $KEEP)->value('wip_i_project_std_id') === $STD_OK;
        $checks['B1 6 drafts VO']     = $db->table('wip_t_lmc_payout')->whereIn('wip_t_lmc_payout_id', $DRAFTS)->where('docstatus', 'VO')->count() === count($DRAFTS);
        $checks['B2 13392 CLOSED']    = $db->table('wip_i_project')->where('wip_i_project_id', $DUP)->value('project_status') === 'CLOSED';
        $checks['B3 audit row']       = $db->table('wip_l_project_status_change')->where('wip_i_project_id', $DUP)->where('project_status', 'CLOSED')->exists();
        $checks['OCC Magbanua']       = $db->table('mp_t_interment_order_occupancy')->where('mp_t_interment_order_id', $IO_LIVE)->where('occupant_name', 'like', '%MAGBANUA%')->exists();
        $checks['MONEY untouched']    = (float) $db->table('fin_l_debt')->whereIn('fin_l_debt_id', [350482, 350483])->sum('amt_outstanding') === 0.0;
        $checks['1865 orphaned']      = $db->table('mp_t_interment_order_project')->where('mp_t_interment_order_id', $IO_CANCEL)->where('is_active', 1)->count() === 0;

        $failed = [];
        foreach ($checks as $label => $pass) {
            echo '  ' . ($pass ? '✅' : '❌') . " {$label}\n";
            if (! $pass) { $failed[] = $label; }
        }
        if ($failed) {
            throw new \RuntimeException('Verification failed: ' . implode(', ', $failed));
        }

        // ---- commit / rollback ----
        echo "\n$line\n";
        if ($DRY_RUN) {
            $db->rollBack();
            echo "DRY-RUN — rolled back. {$applied} row(s) would change.\n";
            $cmd->info("nlio-dedup: dry-run OK, {$applied} row(s) would change (no writes).");
        } else {
            $db->commit();
            echo "✅ COMMITTED — {$applied} row(s) changed. 13403 = Erlinda; 13392 CLOSED.\n";
            $cmd->info("nlio-dedup: applied — 13403 aligned to Erlinda (NLIO00931), 13392 CLOSED, {$applied} row(s).");
        }
        echo "$line\n";

    } catch (\Throwable $e) {
        $db->rollBack();
        echo "\n❌ Error (rolled back): " . $e->getMessage() . "\n";
        throw $e;   // non-zero exit -> deploy moves this file to scripts/failed/
    }
};
