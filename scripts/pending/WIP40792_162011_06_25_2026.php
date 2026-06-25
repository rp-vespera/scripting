<?php // scripts/pending/WIP40792_162011_06_25_2026.php
// Project 13360 (RP AREA 6 LAWN BACKFILLING AND GRADING) org 162011 / WIP subacct 40792
// Re-sync acct_balance row 935412 to match its corresponding acct_gl sum.
//
// Defect pattern: acct_balance / acct_gl desync. Row 935412 (date 2026-06-23, submod 157)
// shows debit=₱5,256.80, but the sum of all acct_gl rows on the same key (WIP subacct,
// org, date, submod) is only ₱2,012.40 — 3 WPC entries × 670.80 each. The acct_balance
// is over-stated by exactly ₱3,244.40, creating a negative variance of −₱3,244.40
// (book-vs-book mismatch, not an operational/closure issue).
//
// This is a different defect from the Tan B duplicate-closure findings. Project 13360
// is COMMENCED (still in-progress) — every other date/submod between acct_balance and
// acct_gl matches perfectly. Only this one row is out of sync.
//
// Fix shape: 1 UPDATE — set acct_balance.debit from 5,256.80 → 2,012.40 so the two
// books agree. Tagged with SCRIPT-WEB-* for rollback.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ = 13360; $ORG = 162011; $WIP_ACCT = 12502; $WIP_SUB = 40792;
    $BAL_ID = 935412;
    $OLD_DEBIT = 5256.80;
    $NEW_DEBIT = 2012.40;
    $TOL = 0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 13360 (LAWN BACKFILLING) — re-sync acct_balance 935412");
    $say($line);

    // Idempotency
    $row = $db->selectOne("SELECT debit, updated FROM acct_balance WHERE acct_balance_id = ?", [$BAL_ID]);
    if (!$row) throw new \RuntimeException("acct_balance $BAL_ID not found");
    if (!is_null($row->updated) && strpos($row->updated, 'SCRIPT-WEB-') === 0) {
        $say(""); $say(" NO-OP — already tagged with SCRIPT-WEB."); $say($line); return;
    }
    if (abs((float)$row->debit - $OLD_DEBIT) > $TOL) {
        throw new \RuntimeException("acct_balance $BAL_ID debit is {$row->debit}, expected $OLD_DEBIT");
    }

    // Cross-verify acct_gl sum equals target
    $glSum = (float) $db->selectOne(
        "SELECT IFNULL(SUM(debit),0) AS s FROM acct_gl
         WHERE gl_subacct_id = ? AND ad_org_id = ? AND gl_acct_id = ?
           AND date_gl = '2026-06-23' AND doc_i_submod_id = 157",
        [$WIP_SUB, $ORG, $WIP_ACCT]
    )->s;
    if (abs($glSum - $NEW_DEBIT) > $TOL) {
        throw new \RuntimeException("acct_gl sum diverged: $glSum vs target $NEW_DEBIT — abort");
    }
    $say(""); $say(" PRE-CHECK:");
    $say("   acct_balance 935412 debit=" . $money((float)$row->debit) . " ✓");
    $say("   acct_gl source-of-truth sum=" . $money($glSum) . " ✓");

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
         JOIN gl_subacct sub USING (gl_subacct_id)
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE: " . $money($wipNet) . " (expected +3,244.40)");
    if (abs($wipNet - 3244.40) > $TOL) throw new \RuntimeException("WIP net $wipNet");

    $ts = gmdate('ymdHis');
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $batch = 'SCRIPT-WEB-' . $ts . $alpha[random_int(0, strlen($alpha) - 1)];

    $say(""); $say(" APPLYING — batch $batch");
    $db->beginTransaction();
    try {
        $a = $db->update(
            "UPDATE acct_balance SET debit = ?, updated = ?, date_updated = UTC_TIMESTAMP() WHERE acct_balance_id = ?",
            [$NEW_DEBIT, $batch, $BAL_ID]
        );
        $say("   UPDATE acct_balance 935412 debit " . $money($OLD_DEBIT) . " → " . $money($NEW_DEBIT) . "  affected=$a");
        if ($a !== 1) throw new \RuntimeException("update affected $a");

        $wipBal = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $wipGl = (float) $db->selectOne(
            "SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $say(""); $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance = " . $money($wipBal));
        $say("   acct_gl      = " . $money($wipGl));
        if (abs($wipBal) > $TOL || abs($wipGl) > $TOL) throw new \RuntimeException("post-check fail");

        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS — books re-synced, variance closed.");
    $say($line);
};
