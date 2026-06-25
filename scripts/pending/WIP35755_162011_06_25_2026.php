<?php // scripts/pending/WIP35755_162011_06_25_2026.php
// Project 11229 (RP AREA 5&6-24" U CULVERT 464.94 L.M & CATCH BASIN 12 SETS)
// org 162011 â€” remove the 2 duplicate closures, restoring WIP variance to 0.
//
// Defect: 3 WPCL closures posted for the same project on different dates, all by the
// same MKR (bp 23078) and same CKR (bp 1355), each for the same â‚±125,662.31.
// Only the first (WPCL-ACPR0969 on 2026-01-10) is legitimate; the 2nd (WPCL-ACPR0974
// on 2026-02-02) and 3rd (WPCL-ACPR0979 on 2026-03-09) are duplicates that
// over-drained WIP by â‚±251,324.62 and inflated Memorial Lot Inventory (acct 11310 /
// subacct 25766) by the same amount.
//
// Root cause hypothesis: operator drafted multiple closure forms over weeks without
// realizing prior drafts were already in flight; SAERP does not enforce uniqueness
// on (project, organization) for closures and silently let all three process.
//
// Cascade per duplicate (FK-safe order):
//   1. wip_t_project_closure_signee   (2 rows MKR+CKR)   DELETE
//   2. wip_t_project_closure_acctpair (1 row)            DELETE
//   3. acct_balance MEMORIAL LOT      (shared row)       UPDATE â€” debit -= 125,662.31
//   4. acct_balance WIP               (exclusive row)    DELETE
//   5. acct_gl                        (2 rows DR/CR)     DELETE
//   6. wip_t_project_closure          (1 row)            DELETE
//   7. acct_doc                       (1 row exclusive)  DELETE
//
// Effect:
//   WIP project net:                 -251,324.62 â†’ 0.00   (variance closed)
//   Memorial Lot Inventory (sub):    inflated -251,324.62 (phantom inventory drained)
//   Both books stay in sync.
//
// REPLICA-TESTED 2026-06-25 â€” boss-approved for live deletion of these 2 duplicates.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $PROJ = 11229;
    $ORG  = 162011;
    $WIP_ACCT = 12502;
    $AMT = 125662.31;
    $TOL = 0.01;

    $DUPLICATES = [
        [
            'docno'        => 'WPCL-ACPR0974',
            'closure_id'   => 25177,
            'acct_doc_id'  => 103820222,
            'signee_ids'   => [47260, 50138],
            'acct_gl_ids'  => [2343203, 2343204],
            'ml_bal_id'    => 861945,
            'wip_bal_id'   => 861957,
        ],
        [
            'docno'        => 'WPCL-ACPR0979',
            'closure_id'   => 26860,
            'acct_doc_id'  => 103828748,
            'signee_ids'   => [50352, 50632],
            'acct_gl_ids'  => [2367820, 2367821],
            'ml_bal_id'    => 871414,
            'wip_bal_id'   => 871423,
        ],
    ];

    $line  = str_repeat('=', 95);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" PROJECT 11229 (RP AREA 5&6-24\" U CULVERT) â€” remove 2 duplicate closures");
    $say(" Boss-approved. Pattern: same MKR/CKR/amount drafted on 3 different dates, only first is legit.");
    $say($line);

    // IDEMPOTENCY CHECK
    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM wip_t_project_closure WHERE wip_t_project_closure_id IN (25177, 26860)"
    )->c;
    if ($existing === 0) {
        $say("");
        $say(" NO-OP â€” both duplicate closures already gone. Already applied?");
        $say($line);
        return;
    }
    if ($existing !== 2) {
        throw new \RuntimeException("Partial state: expected 2 duplicate closures, found $existing");
    }

    // PRE-CHECK
    $say("");
    $say(" PRE-CHECK â€” verifying cascade for both duplicates:");
    foreach ($DUPLICATES as $i => $d) {
        $say("  #" . ($i+1) . " {$d['docno']}");
        foreach ([
            ['closure',  "SELECT COUNT(*) c FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']} AND amt_closure = $AMT AND docstatus='PR'", 1],
            ['signees',  "SELECT COUNT(*) c FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")", 2],
            ['acctpair', "SELECT COUNT(*) c FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_id = {$d['closure_id']}", 1],
            ['acct_gl',  "SELECT COUNT(*) c FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")", 2],
            ['acct_doc', "SELECT COUNT(*) c FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}", 1],
        ] as [$lbl, $q, $expect]) {
            $got = (int) $db->selectOne($q)->c;
            if ($got !== $expect) throw new \RuntimeException("$lbl: got=$got expected=$expect for {$d['docno']}");
            $say("    $lbl: $got âœ“");
        }
        // WIP balance row exclusive (only this closure on the project subacct that date+submod)
        $wb = $db->selectOne("SELECT credit FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']}");
        if (!$wb) throw new \RuntimeException("wip_bal {$d['wip_bal_id']} not found");
        if (abs((float)$wb->credit - $AMT) > $TOL) throw new \RuntimeException("wip_bal credit={$wb->credit}, expected $AMT");
        $say("    wip_bal credit=" . $money($AMT) . " âœ“");

        // MemLot balance row shared - must include this closure's contribution
        $mb = $db->selectOne("SELECT debit FROM acct_balance WHERE acct_balance_id = {$d['ml_bal_id']}");
        if (!$mb) throw new \RuntimeException("ml_bal {$d['ml_bal_id']} not found");
        if ((float)$mb->debit < $AMT - $TOL) throw new \RuntimeException("ml_bal debit={$mb->debit}, expected >= $AMT");
        $say("    ml_bal debit=" . $money((float)$mb->debit) . " (will reduce by " . $money($AMT) . ") âœ“");
    }

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
         JOIN gl_subacct sub USING (gl_subacct_id)
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say("");
    $say(" WIP net BEFORE = " . $money($wipNet) . "  (expected -251,324.62)");
    if (abs($wipNet + 251324.62) > $TOL) throw new \RuntimeException("WIP net is $wipNet");

    // APPLY
    $say("");
    $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        foreach ($DUPLICATES as $d) {
            $say("");
            $say("  -- {$d['docno']} --");
            $a = $db->delete("DELETE FROM wip_t_project_closure_signee WHERE wip_t_project_closure_signee_id IN (".implode(',',$d['signee_ids']).")");
            $say("    DELETE signees(2): affected=$a");
            if ($a !== 2) throw new \RuntimeException("signees affected $a");

            $a = $db->delete("DELETE FROM wip_t_project_closure_acctpair WHERE wip_t_project_closure_id = {$d['closure_id']}");
            $say("    DELETE acctpair(1): affected=$a");
            if ($a !== 1) throw new \RuntimeException("acctpair affected $a");

            $a = $db->update("UPDATE acct_balance SET debit = debit - ? WHERE acct_balance_id = ?", [$AMT, $d['ml_bal_id']]);
            $say("    UPDATE acct_balance ML(" . $d['ml_bal_id'] . ") debit -= " . $money($AMT) . ": affected=$a");
            if ($a !== 1) throw new \RuntimeException("ML balance update affected $a");

            $a = $db->delete("DELETE FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']}");
            $say("    DELETE acct_balance WIP(" . $d['wip_bal_id'] . "): affected=$a");
            if ($a !== 1) throw new \RuntimeException("WIP balance delete affected $a");

            $a = $db->delete("DELETE FROM acct_gl WHERE acct_gl_id IN (".implode(',',$d['acct_gl_ids']).")");
            $say("    DELETE acct_gl(2): affected=$a");
            if ($a !== 2) throw new \RuntimeException("acct_gl affected $a");

            $a = $db->delete("DELETE FROM wip_t_project_closure WHERE wip_t_project_closure_id = {$d['closure_id']}");
            $say("    DELETE closure(" . $d['closure_id'] . "): affected=$a");
            if ($a !== 1) throw new \RuntimeException("closure delete affected $a");

            $a = $db->delete("DELETE FROM acct_doc WHERE acct_doc_id = {$d['acct_doc_id']}");
            $say("    DELETE acct_doc(" . $d['acct_doc_id'] . "): affected=$a");
            if ($a !== 1) throw new \RuntimeException("acct_doc affected $a");
        }

        // POST-CHECK
        $wipBalNet = (float) $db->selectOne(
            "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
             JOIN gl_subacct sub USING (gl_subacct_id)
             WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $wipGlNet = (float) $db->selectOne(
            "SELECT IFNULL(SUM(ag.debit-ag.credit),0) AS s FROM acct_gl ag
             JOIN gl_subacct sub USING (gl_subacct_id)
             WHERE sub.wip_i_project_id = ? AND ag.gl_acct_id = ? AND ag.ad_org_id = ?",
            [$PROJ, $WIP_ACCT, $ORG]
        )->s;
        $say("");
        $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance WIP net = " . $money($wipBalNet));
        $say("   acct_gl      WIP net = " . $money($wipGlNet));
        if (abs($wipBalNet) > $TOL) throw new \RuntimeException("WIP balance net is $wipBalNet");
        if (abs($wipGlNet) > $TOL) throw new \RuntimeException("WIP gl net is $wipGlNet");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say("");
    $say($line);
    $say(" SUCCESS â€” variance closed. 2 duplicates removed, Memorial Lot Inventory phantom drained.");
    $say($line);
};

