<?php // scripts/pending/WIP40792_162011_06_25_2026_rollback.php
// Rollback for WIP40792 fix — reverts the UPDATE on acct_balance 935412
// (2,012.40 → 5,256.80) and clears the SCRIPT-WEB audit tag.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $PROJ = 13360; $ORG = 162011; $WIP_ACCT = 12502;
    $BAL_ID = 935412;
    $OLD_DEBIT = 5256.80;
    $NEW_DEBIT = 2012.40;
    $TOL = 0.01;

    $line = str_repeat('=', 90);
    $say = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 13360 — ROLLBACK (revert acct_balance 935412 to 5,256.80)");
    $say($line);

    $row = $db->selectOne("SELECT debit, updated FROM acct_balance WHERE acct_balance_id = ?", [$BAL_ID]);
    if (!$row) throw new \RuntimeException("acct_balance $BAL_ID not found");
    if (is_null($row->updated) || strpos($row->updated, 'SCRIPT-WEB-') !== 0) {
        $say(""); $say(" NO-OP — row has no SCRIPT-WEB tag (apply never ran)."); $say($line); return;
    }
    if (abs((float)$row->debit - $NEW_DEBIT) > $TOL) {
        throw new \RuntimeException("debit is {$row->debit}, expected $NEW_DEBIT");
    }

    $say(""); $say(" Found batch tag: " . $row->updated);
    $say(""); $say(" APPLYING ROLLBACK (transaction):");
    $db->beginTransaction();
    try {
        $a = $db->update(
            "UPDATE acct_balance SET debit = ?, updated = NULL, date_updated = NULL WHERE acct_balance_id = ?",
            [$OLD_DEBIT, $BAL_ID]
        );
        $say("   UPDATE acct_balance 935412 debit " . $money($NEW_DEBIT) . " → " . $money($OLD_DEBIT) . " (tag cleared)  affected=$a");
        if ($a !== 1) throw new \RuntimeException("affected $a");

        $wipBal = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal JOIN gl_subacct sub USING (gl_subacct_id) WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $say(""); $say(" POST-CHECK: WIP net = " . $money($wipBal) . " (expected +3,244.40)");
        if (abs($wipBal - 3244.40) > $TOL) throw new \RuntimeException("WIP net $wipBal");

        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(""); $say($line);
    $say(" SUCCESS — desync restored. Variance back to −₱3,244.40.");
    $say($line);
};
