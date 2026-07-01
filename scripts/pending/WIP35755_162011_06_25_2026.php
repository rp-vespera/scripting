<?php 

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

    $UPD_TAG = 'SCRIPT-WEB'; 

    $say($line);
    $say(" PROJECT 11229 (RP AREA 5&6-24\" U CULVERT) — soft-delete 2 duplicate closures");
    $say(" Same MKR/CKR/amount drafted on 3 different dates, only first is legit.");
    $say(" Soft delete: UPDATE values to 0, docstatus='PR' (rows preserved for audit).");
    $say($line);

    // IDEMPOTENCY CHECK — if already soft-deleted (amt_closure = 0), skip
    $alreadySoftDeleted = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM wip_t_project_closure
         WHERE wip_t_project_closure_id IN (25177, 26860)
           AND amt_closure = 0"
    )->c;
    if ($alreadySoftDeleted === 2) {
        $say(""); $say(" NO-OP — both duplicates already soft-deleted."); $say($line); return;
    }

    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM wip_t_project_closure
         WHERE wip_t_project_closure_id IN (25177, 26860) AND amt_closure = $AMT AND docstatus = 'PR'"
    )->c;
    if ($existing !== 2) {
        throw new \RuntimeException("Partial state: expected 2 active duplicates with amt=$AMT and docstatus=PR, found $existing");
    }

    // PRE-CHECK — verify the cascade is intact
    $say("");
    $say(" PRE-CHECK — verifying cascade for both duplicates:");
    foreach ($DUPLICATES as $i => $d) {
        $say("  #" . ($i+1) . " {$d['docno']}");
        $wb = $db->selectOne("SELECT credit FROM acct_balance WHERE acct_balance_id = {$d['wip_bal_id']}");
        if (!$wb) throw new \RuntimeException("wip_bal {$d['wip_bal_id']} not found");
        if (abs((float)$wb->credit - $AMT) > $TOL) throw new \RuntimeException("wip_bal credit={$wb->credit}, expected $AMT");
        $say("    wip_bal credit=" . $money($AMT) . " ✓");

        $mb = $db->selectOne("SELECT debit FROM acct_balance WHERE acct_balance_id = {$d['ml_bal_id']}");
        if (!$mb) throw new \RuntimeException("ml_bal {$d['ml_bal_id']} not found");
        if ((float)$mb->debit < $AMT - $TOL) throw new \RuntimeException("ml_bal debit={$mb->debit}, expected >= $AMT");
        $say("    ml_bal debit=" . $money((float)$mb->debit) . " (will reduce by " . $money($AMT) . ") ✓");
    }

    $wipNet = (float) $db->selectOne(
        "SELECT IFNULL(SUM(bal.debit-bal.credit),0) AS s FROM acct_balance bal
         JOIN gl_subacct sub USING (gl_subacct_id)
         WHERE sub.wip_i_project_id = ? AND bal.gl_acct_id = ? AND bal.ad_org_id = ?",
        [$PROJ, $WIP_ACCT, $ORG]
    )->s;
    $say(""); $say(" WIP net BEFORE = " . $money($wipNet) . "  (expected -251,324.62)");
    if (abs($wipNet + 251324.62) > $TOL) throw new \RuntimeException("WIP net is $wipNet");

    // APPLY (all UPDATEs, no DELETE)
    $say(""); $say(" APPLYING (transaction, soft-delete via UPDATE):");
    $db->beginTransaction();
    try {
        // Backfill missing doc_t_reference_number_id on closure 25177
        // Reference by documentno_pr so it works across replica/live (IDs differ per environment)
        $a = $db->update(
            "UPDATE wip_t_project_closure
                SET doc_t_reference_number_id = (
                    SELECT doc_t_reference_number_id FROM doc_t_reference_number
                     WHERE documentno_pr = 'WPCL-ACPR0974'
                ),
                updated = ?, date_updated = NOW()
              WHERE wip_t_project_closure_id = 25177 AND doc_t_reference_number_id IS NULL",
            [$UPD_TAG]
        );
        $say(""); $say("  Backfill doc_t_reference_number_id on 25177: affected=$a");

        foreach ($DUPLICATES as $d) {
            $say(""); $say("  -- {$d['docno']} --");

            // 1. Signees: mark inactive
            $a = $db->update(
                "UPDATE wip_t_project_closure_signee
                    SET is_active = 0, updated = ?, date_updated = NOW()
                  WHERE wip_t_project_closure_signee_id IN (" . implode(',', $d['signee_ids']) . ")",
                [$UPD_TAG]
            );
            $say("    UPDATE signees(2) is_active=0: affected=$a");

            // 2. Acctpair: mark inactive
            $a = $db->update(
                "UPDATE wip_t_project_closure_acctpair
                    SET is_active = 0, updated = ?, date_updated = NOW()
                  WHERE wip_t_project_closure_id = {$d['closure_id']}",
                [$UPD_TAG]
            );
            $say("    UPDATE acctpair is_active=0: affected=$a");

            // 3. Memorial Lot balance: decrement (must adjust shared row)
            $a = $db->update(
                "UPDATE acct_balance
                    SET debit = debit - ?, updated = ?, date_updated = NOW()
                  WHERE acct_balance_id = ?",
                [$AMT, $UPD_TAG, $d['ml_bal_id']]
            );
            $say("    UPDATE acct_balance ML(" . $d['ml_bal_id'] . ") debit -= " . $money($AMT) . ": affected=$a");
            if ($a !== 1) throw new \RuntimeException("ML balance update affected $a");

            // 4. WIP balance row: zero out
            $a = $db->update(
                "UPDATE acct_balance
                    SET debit = 0, credit = 0, updated = ?, date_updated = NOW()
                  WHERE acct_balance_id = ?",
                [$UPD_TAG, $d['wip_bal_id']]
            );
            $say("    UPDATE acct_balance WIP(" . $d['wip_bal_id'] . ") debit=0, credit=0: affected=$a");
            if ($a !== 1) throw new \RuntimeException("WIP balance soft-delete affected $a");

            // 5. acct_gl rows: zero out
            $a = $db->update(
                "UPDATE acct_gl
                    SET debit = 0, credit = 0, updated = ?, date_updated = NOW()
                  WHERE acct_gl_id IN (" . implode(',', $d['acct_gl_ids']) . ")",
                [$UPD_TAG]
            );
            $say("    UPDATE acct_gl(2) debit=0, credit=0: affected=$a");
            if ($a !== 2) throw new \RuntimeException("acct_gl soft-delete affected $a");

            // 6. wip_t_project_closure: mark PR from VO, zero amount
            $a = $db->update(
                "UPDATE wip_t_project_closure
                    SET amt_closure = 0, docstatus = 'PR', updated = ?, date_updated = NOW()
                  WHERE wip_t_project_closure_id = {$d['closure_id']}",
                [$UPD_TAG]
            );
            $say("    UPDATE closure(" . $d['closure_id'] . ") amt=0, docstatus=PR: affected=$a");
            if ($a !== 1) throw new \RuntimeException("closure soft-delete affected $a");

            // 7. acct_doc: mark inactive
            $a = $db->update(
                "UPDATE acct_doc
                    SET is_active = 0, updated = ?, date_updated = NOW()
                  WHERE acct_doc_id = {$d['acct_doc_id']}",
                [$UPD_TAG]
            );
            $say("    UPDATE acct_doc(" . $d['acct_doc_id'] . ") is_active=0: affected=$a");
        }

        // POST-CHECK — variance should close exactly the same
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
        $say(""); $say(" POST-CHECK (both must be 0.00):");
        $say("   acct_balance WIP net = " . $money($wipBalNet));
        $say("   acct_gl      WIP net = " . $money($wipGlNet));
        if (abs($wipBalNet) > $TOL) throw new \RuntimeException("WIP balance net is $wipBalNet");
        if (abs($wipGlNet) > $TOL) throw new \RuntimeException("WIP gl net is $wipGlNet");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(""); $say($line);
    $say(" SUCCESS — variance closed via SOFT DELETE. 2 duplicates voided (rows preserved).");
    $say(" docstatus='PR', amt_closure=0, all amounts neutralized. Audit trail intact.");
    $say($line);
};
