<?php
/**
 * IMS#16747 — Cancel LSP PR-I0031024 — PRE-CANCEL (1 of 2)
 * Lot 5529 · Preownership record 6643
 *
 * Full procedure: scripts/IMS16747_cancel_lsp_documentation.md
 *
 * Runs the doc's Step 0 (capture), the precondition check, and Steps 1-3 in
 * sequence inside ONE transaction on the ERP connection:
 *   1. lift the "owned" lock (detach + retire ownership; kept for history)
 *   2. lower the running total below the fully-paid threshold + un-own
 *   3. return the lot to AVAILABLE
 *
 * It does NOT cancel the payment. After this succeeds you must run the in-app
 * "Cancel LSP" for PR-I0031024 (doc Step 4), then run the companion
 * post-cancel script (variance fix + verify, Steps 5-6).
 *
 * Output: every step is echo'd (so the runner's ob_start captures it into
 * script_runs.output and the /scripts dashboard shows it) and the closure
 * returns a structured summary array.
 *
 * The runner's auto-transaction wraps the default (sqlite) connection only, so
 * we manage our own transaction on the ERP connection and throw to roll back
 * on any failed guard. Idempotent: re-running just re-asserts the same state.
 */

use Illuminate\Support\Facades\DB;

return function ($cmd) {
    // ── Config (the one record this fix targets) ────────────────────────────
    $connName    = 'mysql_secondary';   // ERP database
    $documentno  = 'PR-I0031024';
    $expectPreId = 6643;                 // expected mp_l_preownership_id
    $expectLotId = 5529;                 // expected mp_i_lot_id
    $floorTotal  = 11606.00;             // running total to set (must be < fully-paid threshold)
    $tag         = 'IMS#16747';

    $log = function (string $m) { echo $m, "\n"; };
    $db  = DB::connection($connName);

    // ── Resolve the record from the document number (expect exactly one) ─────
    $line = $db->selectOne(
        'SELECT l.mp_l_preownership_id, l.mp_i_lot_id
           FROM mp_t_lot_sales s
           JOIN mp_t_lot_sales_line l ON l.mp_t_lot_sales_id = s.mp_t_lot_sales_id
          WHERE s.documentno = ?',
        [$documentno]
    );

    if (! $line) {
        throw new RuntimeException("No lot-sales line found for documentno {$documentno}. Aborting.");
    }
    $preId = (int) $line->mp_l_preownership_id;
    $lotId = (int) $line->mp_i_lot_id;

    // Safety: the resolved ids must match the documented target.
    if ($preId !== $expectPreId || $lotId !== $expectLotId) {
        throw new RuntimeException(
            "Resolved preownership={$preId}, lot={$lotId} do not match expected "
            . "{$expectPreId}/{$expectLotId}. Wrong environment or data — aborting."
        );
    }

    // ── Step 0 — capture before-state (recorded in script output) ───────────
    $pre = $db->selectOne(
        'SELECT p.amtcontract_sales, p.total_sales_discount, p.amt_waived,
                (p.amtcontract_sales - COALESCE(p.total_sales_discount,0) - COALESCE(p.amt_waived,0)) AS fully_paid_threshold,
                p.total_sales, p.is_paid, p.is_owned, p.is_printed,
                (SELECT COALESCE(SUM(t.amt_sales),0) FROM mp_l_preownership_threshold t
                  WHERE t.mp_l_preownership_id = p.mp_l_preownership_id) AS official_figure
           FROM mp_l_preownership p WHERE p.mp_l_preownership_id = ?',
        [$preId]
    );
    $own = $db->selectOne(
        'SELECT mp_l_ownership_id, is_active, mp_l_preownership_id
           FROM mp_l_ownership WHERE mp_l_preownership_id = ?',
        [$preId]
    );
    $lot = $db->selectOne(
        'SELECT is_owned, is_preowned, is_reserved, status_code, mp_i_lotstatus_id
           FROM mp_i_lot WHERE mp_i_lot_id = ?',
        [$lotId]
    );

    $log('=== IMS#16747 PRE-CANCEL — BEFORE STATE (SAVE THIS for rollback) ===');
    $log("documentno={$documentno}  preownership={$preId}  lot={$lotId}");
    $log('preownership: ' . json_encode($pre));
    $log('ownership:    ' . json_encode($own));
    $log('lot:          ' . json_encode($lot));

    // ── Precondition — fully-paid threshold must exceed the floor we set ─────
    $threshold = (float) $pre->fully_paid_threshold;
    if (! ($threshold > $floorTotal)) {
        throw new RuntimeException(
            "Precondition failed: fully_paid_threshold ({$threshold}) is not > floor ({$floorTotal}). "
            . 'Choose a lower floorTotal before running. Aborting.'
        );
    }

    // ── Steps 1-3 — apply inside one ERP transaction ────────────────────────
    $applied = $db->transaction(function () use ($db, $preId, $lotId, $floorTotal, $tag, $log) {
        // Step 1 — lift the "owned" lock (detach + retire ownership)
        $a1 = $db->update(
            'UPDATE mp_l_ownership
                SET is_active = 0, mp_l_preownership_id = NULL, updated = ?
              WHERE mp_l_preownership_id = ?',
            [$tag, $preId]
        );

        // Step 2 — lower running total below the fully-paid threshold + un-own
        $a2 = $db->update(
            'UPDATE mp_l_preownership
                SET is_owned = 0, is_printed = 0, total_sales = ?, updated = ?
              WHERE mp_l_preownership_id = ?',
            [$floorTotal, $tag, $preId]
        );

        // Step 3 — return the lot to AVAILABLE
        $a3 = $db->update(
            'UPDATE mp_i_lot
                SET is_owned = 0, is_preowned = 0, is_reserved = 0, status_code = ?, updated = ?
              WHERE mp_i_lot_id = ?',
            ['AVL', $tag, $lotId]
        );

        $log("Step 1 (ownership detach) changed: {$a1} row(s)");
        $log("Step 2 (preownership un-own + total_sales) changed: {$a2} row(s)");
        $log("Step 3 (lot AVAILABLE) changed: {$a3} row(s)");
        $log('(0 changed is normal on a re-run when values already match — verifying end state.)');

        // Verify END STATE, not affected-row counts. MySQL returns 0 affected
        // when an UPDATE matches a row but the values are already correct, so a
        // re-run would otherwise look like a failure. We instead assert the
        // record now holds the intended values (idempotent-safe).
        $pOut = $db->selectOne(
            'SELECT is_owned, total_sales FROM mp_l_preownership WHERE mp_l_preownership_id = ?', [$preId]
        );
        $lOut = $db->selectOne(
            'SELECT is_owned, is_preowned, is_reserved, status_code FROM mp_i_lot WHERE mp_i_lot_id = ?', [$lotId]
        );
        $activeOwn = (int) $db->selectOne(
            'SELECT COUNT(*) AS c FROM mp_l_ownership WHERE mp_l_preownership_id = ? AND is_active = 1', [$preId]
        )->c;

        $okPre = $pOut && (int) $pOut->is_owned === 0 && abs((float) $pOut->total_sales - $floorTotal) < 0.005;
        $okLot = $lOut && $lOut->status_code === 'AVL'
              && (int) $lOut->is_owned === 0 && (int) $lOut->is_preowned === 0 && (int) $lOut->is_reserved === 0;
        $okOwn = $activeOwn === 0;

        if (! $okPre || ! $okLot || ! $okOwn) {
            $log('preownership: ' . json_encode($pOut));
            $log('lot:          ' . json_encode($lOut));
            $log("active ownership rows still attached: {$activeOwn}");
            throw new RuntimeException('End-state verification failed. Rolling back.');
        }

        $log('End-state verified: ownership detached, preownership un-owned at floor, lot AVAILABLE.');

        return [
            'changed'   => ['ownership' => $a1, 'preownership' => $a2, 'lot' => $a3],
            'end_state' => ['preownership' => $pOut, 'lot' => $lOut, 'active_ownership' => $activeOwn],
        ];
    });

    $log('=== PRE-CANCEL DONE ===');
    $log("NEXT (manual): run 'Cancel LSP' for {$documentno} in the application (doc Step 4).");
    $log('THEN: move the post-cancel script into scripts/pending/ and deploy to apply Steps 5-6.');

    // Structured result (returned to the caller; also useful when run via tinker).
    $result = [
        'ok'           => true,
        'script'       => 'ims16747_cancel_lsp_precancel',
        'documentno'   => $documentno,
        'preownership' => $preId,
        'lot'          => $lotId,
        'before'       => ['preownership' => $pre, 'ownership' => $own, 'lot' => $lot],
        'applied'      => $applied,
        'next'         => "Manual Cancel LSP for {$documentno}, then run the post-cancel script.",
    ];
    $log('RESULT: ' . json_encode($result));

    return $result;
};
