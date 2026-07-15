<?php // scripts/pending/12297_162011_07_07_2026.php
// PROJ 12297 — RP AREA 5&6 ROAD 160LM BOQ 4 (org 162011 VRC/Tan B)
// Fix: soft-delete 2 duplicate PR closures (WPCL-ACPR0975 + WPCL-ACPR0980).
//
// Root cause: 3 identical PR closures of ₱64,505.12 by MKR James Earl MENDOZA
// + CKR Karl Adrian SISON. Same defect class as NLIO00355 — SAERP's
// WipTProjectClosureService.java:170 only checks project_status=CLOSED but
// closure Process doesn't auto-flip project_status, so duplicates slip through.
//
// Closures on this project (Account Pair, submod 293):
//   19374 WPCL-ACPR0972 (2026-01-17)  ← KEEP (legit — processed first)
//   25176 WPCL-ACPR0975 (2026-02-02)  ← SOFT-DELETE (duplicate #1)
//   26862 WPCL-ACPR0980 (2026-03-09)  ← SOFT-DELETE (duplicate #2)
//
// Effect: WIP variance −₱129,010.24 → ₱0.00 on subacct 37572 / acct 12502 / org 162011
// Project stays CLOSED — SAERP guards prevent any future duplicates on this project.
//
// Per-closure cascade (repeated for each of the 2 duplicates):
//   wip_t_project_closure          amt_closure = 0 (keep docstatus=PR per senior)
//   wip_t_project_closure_signee   is_active = 0 (2 signees per closure)
//   acct_gl (DR CMI 11310)         debit = 0
//   acct_gl (CR WIP 12502)         credit = 0
//   acct_balance (DR side)         debit -= orig  (dynamic decrement, works for aggregated rows)
//   acct_balance (CR side)         credit -= orig
//   acct_doc                       is_active = 0
//
// Every UPDATE tagged updated='SCRIPT-WEB', date_updated=script run time.

return function ($cmd) {
    $db  = \DB::connection('mysql_secondary');
    $DEPLOY_DATE = date('Y-m-d H:i:s');
    $TAG = 'SCRIPT-WEB';
    $TOL = 0.01;

    $PROJECT_ID = 12297;
    $ORG_ID     = 162011;
    $WIP_SUB    = 37572;
    $EXPECTED_VARIANCE = -129010.24;

    // Duplicates to kill — each row: [closure_id, expected_amt, acct_doc_id]
    $KILLS = [
        [25176, 64505.12, 103820223],
        [26862, 64505.12, 103828749],
    ];

    $line  = str_repeat('=', 90);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(' PROJ 12297 — RP AREA 5&6 ROAD 160LM BOQ 4 (org 162011)');
    $say(' Kill 2 duplicate closures (WPCL-ACPR0975 + WPCL-ACPR0980, ₱64,505.12 each)');
    $say(' Effect: WIP variance ' . $money($EXPECTED_VARIANCE) . ' → 0.00');
    $say(' Deploy timestamp: ' . $DEPLOY_DATE);
    $say($line);

    // IDEMPOTENCY — if first duplicate already zeroed, skip
    $firstClosure = $db->selectOne("SELECT amt_closure FROM wip_t_project_closure WHERE wip_t_project_closure_id = ?", [$KILLS[0][0]]);
    if (!$firstClosure) throw new \RuntimeException('closure ' . $KILLS[0][0] . ' not found');
    if (abs((float) $firstClosure->amt_closure) < $TOL) {
        $say(''); $say(' NO-OP — closure ' . $KILLS[0][0] . ' already zeroed.'); $say($line); return;
    }

    // PRE-CHECK variance
    $wipNet = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = 12502 AND ad_org_id = ?', [$WIP_SUB, $ORG_ID])->v;
    $say(''); $say(' PRE-CHECK WIP net = ' . $money($wipNet) . '  (must be ' . $money($EXPECTED_VARIANCE) . ')');
    if (abs($wipNet - $EXPECTED_VARIANCE) > $TOL) throw new \RuntimeException("WIP net is $wipNet, expected {$EXPECTED_VARIANCE}");

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        foreach ($KILLS as [$cid, $amt, $docId]) {
            $say(''); $say('  -- Closing duplicate closure ' . $cid . ' (amt ' . $money($amt) . ', acct_doc ' . $docId . ') --');

            // Verify closure amt matches expected
            $c = $db->selectOne('SELECT amt_closure, acct_doc_id FROM wip_t_project_closure WHERE wip_t_project_closure_id = ?', [$cid]);
            if (abs((float)$c->amt_closure - $amt) > $TOL) throw new \RuntimeException("closure $cid amt is {$c->amt_closure}, expected $amt");
            if ((int)$c->acct_doc_id !== $docId) throw new \RuntimeException("closure $cid acct_doc_id is {$c->acct_doc_id}, expected $docId");

            // 1. Get + decrement acct_balance rows via each acct_gl entry
            foreach ($db->select('SELECT acct_gl_id, gl_acct_id, gl_subacct_id, ad_org_id, debit, credit, date_gl FROM acct_gl WHERE acct_doc_id = ?', [$docId]) as $g) {
                $glId = $g->acct_gl_id;
                $balSql = "UPDATE acct_balance SET debit = debit - ?, credit = credit - ?, updated = ?, date_updated = ?
                           WHERE gl_acct_id = ? AND gl_subacct_id <=> ? AND ad_org_id = ? AND date_gl = ?
                           ORDER BY ABS((debit - ?) + (credit - ?)) ASC LIMIT 1";
                $a = $db->update($balSql, [$g->debit, $g->credit, $TAG, $DEPLOY_DATE, $g->gl_acct_id, $g->gl_subacct_id, $g->ad_org_id, $g->date_gl, $g->debit, $g->credit]);
                $say("    acct_balance for acct=" . $g->gl_acct_id . " sub=" . ($g->gl_subacct_id ?? 'NULL') . " date=" . $g->date_gl . " decremented by DR=" . $g->debit . " CR=" . $g->credit . ": affected=$a");

                // Zero the acct_gl entry
                $a = $db->update("UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_gl_id = ?", [$TAG, $DEPLOY_DATE, $glId]);
                if ($a !== 1) throw new \RuntimeException("acct_gl $glId affected $a");
                $say("    acct_gl $glId → 0/0: affected=$a");
            }

            // 2. Inactivate signees
            $a = $db->update("UPDATE wip_t_project_closure_signee SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_id = ?", [$TAG, $DEPLOY_DATE, $cid]);
            $say("    signees for closure $cid → is_active=0: affected=$a");

            // 3. Zero closure amt (keep docstatus=PR)
            $a = $db->update("UPDATE wip_t_project_closure SET amt_closure = 0, docstatus = 'PR', updated = ?, date_updated = ? WHERE wip_t_project_closure_id = ?", [$TAG, $DEPLOY_DATE, $cid]);
            if ($a !== 1) throw new \RuntimeException("closure $cid update affected $a");
            $say("    closure $cid amt→0: affected=$a");

            // 4. Inactivate acct_doc
            $a = $db->update("UPDATE acct_doc SET is_active = 0, updated = ?, date_updated = ? WHERE acct_doc_id = ?", [$TAG, $DEPLOY_DATE, $docId]);
            $say("    acct_doc $docId → is_active=0: affected=$a");
        }

        // POST-CHECK variance = 0
        $wipAfter = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = 12502 AND ad_org_id = ?', [$WIP_SUB, $ORG_ID])->v;
        $say(''); $say(' POST-CHECK WIP net = ' . $money($wipAfter) . '  (must be 0.00)');
        if (abs($wipAfter) > $TOL) throw new \RuntimeException("WIP net after fix = $wipAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — 2 duplicates soft-deleted, WIP variance closed to 0.00.');
    $say($line);
};
