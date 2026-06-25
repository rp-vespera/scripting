<?php // scripts/pending/NLIO00273_162012_06_25_2026_rollback.php
// Rollback: reverts the 4 SCRIPT-WEB-tagged UPDATEs back to their original ₱551 values
// and clears the `updated` audit tag (restores byte-identical pre-change state).

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ_ID = 4795;
    $ORG     = 162012;
    $WIP_ACCT = 12502;
    $OLD = 551.00;
    $NEW = 511.00;
    $TOL = 0.01;

    $TARGETS = [
        ['acct_gl',      'acct_gl_id',      1364286, 'debit'],
        ['acct_gl',      'acct_gl_id',      1364287, 'credit'],
        ['acct_balance', 'acct_balance_id', 552562,  'debit'],
        ['acct_balance', 'acct_balance_id', 552634,  'credit'],
    ];

    $line  = str_repeat('=', 90);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" NLIO00273_162012 — ROLLBACK (revert 511 → 551, restore -40 variance)");
    $say($line);

    $tag = $db->selectOne(
        "SELECT updated AS t FROM acct_gl WHERE acct_gl_id = 1364286 AND updated LIKE 'SCRIPT-WEB-%'"
    );
    if (!$tag) {
        $say("");
        $say(" NO-OP — acct_gl 1364286 has no SCRIPT-WEB tag. Already rolled back or never applied.");
        $say($line);
        return;
    }
    $batch = $tag->t;
    $say("");
    $say(" Found batch_id = $batch");

    $say("");
    $say(" PRE-CHECK — verifying all 4 rows are at +511 with this tag:");
    foreach ($TARGETS as [$tbl, $keyCol, $id, $side]) {
        $row = $db->selectOne("SELECT $side AS v, updated FROM $tbl WHERE $keyCol = ?", [$id]);
        if (!$row)                              throw new \RuntimeException("$tbl id=$id NOT FOUND");
        if (abs((float)$row->v - $NEW) > $TOL)  throw new \RuntimeException("$tbl id=$id $side=$row->v, expected $NEW");
        if ($row->updated !== $batch)           throw new \RuntimeException("$tbl id=$id updated='$row->updated', expected '$batch'");
        $say("   $tbl id=$id  $side=" . $money((float)$row->v) . "  tag=$row->updated  ✓");
    }

    $say("");
    $say(" APPLYING ROLLBACK (transaction)");
    $db->beginTransaction();
    try {
        foreach ($TARGETS as [$tbl, $keyCol, $id, $side]) {
            $db->update(
                "UPDATE $tbl SET $side = ?, updated = NULL, date_updated = NULL WHERE $keyCol = ?",
                [$OLD, $id]
            );
            $say("   UPDATE $tbl id=$id  $side: " . $money($NEW) . " → " . $money($OLD) . "  (tag cleared)");
        }

        $glAftBal = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit - bal.credit), 0) AS gl
             FROM acct_balance bal JOIN gl_subacct sub ON sub.gl_subacct_id = bal.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $glAftGl = (float) $db->selectOne(
            "SELECT IFNULL(SUM(gl.debit - gl.credit), 0) AS gl
             FROM acct_gl gl JOIN gl_subacct sub ON sub.gl_subacct_id = gl.gl_subacct_id
             WHERE sub.wip_i_project_id = ? AND gl.gl_acct_id = ? AND gl.ad_org_id = ?",
            [$PROJ_ID, $WIP_ACCT, $ORG]
        )->gl;
        $say("");
        $say(" POST-CHECK (both must be +40.00):");
        $say("   acct_balance net = " . $money($glAftBal));
        $say("   acct_gl      net = " . $money($glAftGl));
        if (abs($glAftBal - 40.0) > $TOL) throw new \RuntimeException("Post-check acct_balance is $glAftBal");
        if (abs($glAftGl  - 40.0) > $TOL) throw new \RuntimeException("Post-check acct_gl is $glAftGl");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS — pre-fix state restored. NLIO00273 variance back to -40.");
    $say($line);
};
