<?php // scripts/pending/12520_162011_07_07_2026.php
// PROJ 12520 — RP AREA 6 SIDEWALK 141.12LN.M. BOQ 3 (org 162011)
// Fix: soft-delete 1 duplicate PR closure (WPCL-ACPR0959, ₱11,241.81).
//
// Root cause: same as NLIO00355 pattern (SAERP defect + JEM/KAS duplicate closure workflow).
// Closures on this project (Account Pair, submod 293):
//   25939 WPCL-ACPR0956 (2025-12-08)  ← KEEP (legit)
//   26109 WPCL-ACPR0948 (2025-11-28)  ← ALREADY REVERSED by 26282 WPCLRACCPR0171 (leave alone)
//   26117 WPCL-ACPR0959 (2025-12-12)  ← SOFT-DELETE (duplicate)
//   26282 WPCLRACCPR0171 (-11,241.81, targets 26109) — LEAVE ALONE (legit reversal)
// Effect: WIP variance −₱11,241.81 → ₱0.00 on subacct 38118 / acct 12502 / org 162011.

return function ($cmd) {
    $db  = \DB::connection('mysql_secondary');
    $DEPLOY_DATE = date('Y-m-d H:i:s');
    $TAG = 'SCRIPT-WEB';
    $TOL = 0.01;

    $WIP_SUB = 38118;
    $ORG_ID  = 162011;
    $EXPECTED_VARIANCE = -11241.81;

    $KILLS = [
        [26117, 11241.81, 103806870],
    ];

    $line  = str_repeat('=', 90);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(' PROJ 12520 — RP AREA 6 SIDEWALK (org 162011)');
    $say(' Kill 1 duplicate closure (WPCL-ACPR0959, ₱11,241.81)');
    $say(' Effect: WIP variance ' . $money($EXPECTED_VARIANCE) . ' → 0.00');
    $say(' Deploy timestamp: ' . $DEPLOY_DATE);
    $say($line);

    $c = $db->selectOne("SELECT amt_closure FROM wip_t_project_closure WHERE wip_t_project_closure_id = ?", [$KILLS[0][0]]);
    if (!$c) throw new \RuntimeException('closure not found');
    if (abs((float) $c->amt_closure) < $TOL) { $say(''); $say(' NO-OP.'); $say($line); return; }

    $wipNet = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = 12502 AND ad_org_id = ?', [$WIP_SUB, $ORG_ID])->v;
    $say(''); $say(' PRE-CHECK WIP net = ' . $money($wipNet));
    if (abs($wipNet - $EXPECTED_VARIANCE) > $TOL) throw new \RuntimeException("WIP net is $wipNet, expected {$EXPECTED_VARIANCE}");

    $db->beginTransaction();
    try {
        foreach ($KILLS as [$cid, $amt, $docId]) {
            $say(''); $say('  -- Closing duplicate ' . $cid . ' --');
            $c = $db->selectOne('SELECT amt_closure FROM wip_t_project_closure WHERE wip_t_project_closure_id = ?', [$cid]);
            if (abs((float)$c->amt_closure - $amt) > $TOL) throw new \RuntimeException("closure $cid amt is {$c->amt_closure}");

            foreach ($db->select('SELECT acct_gl_id, gl_acct_id, gl_subacct_id, ad_org_id, debit, credit, date_gl FROM acct_gl WHERE acct_doc_id = ?', [$docId]) as $g) {
                $db->update("UPDATE acct_balance SET debit = debit - ?, credit = credit - ?, updated = ?, date_updated = ?
                             WHERE gl_acct_id = ? AND gl_subacct_id <=> ? AND ad_org_id = ? AND date_gl = ? ORDER BY acct_balance_id LIMIT 1",
                    [$g->debit, $g->credit, $TAG, $DEPLOY_DATE, $g->gl_acct_id, $g->gl_subacct_id, $g->ad_org_id, $g->date_gl]);
                $a = $db->update("UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_gl_id = ?", [$TAG, $DEPLOY_DATE, $g->acct_gl_id]);
                if ($a !== 1) throw new \RuntimeException("acct_gl {$g->acct_gl_id} affected $a");
                $say("    acct_gl " . $g->acct_gl_id . " zeroed + balance decremented");
            }

            $db->update("UPDATE wip_t_project_closure_signee SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_id = ?", [$TAG, $DEPLOY_DATE, $cid]);
            $db->update("UPDATE wip_t_project_closure SET amt_closure = 0, docstatus = 'PR', updated = ?, date_updated = ? WHERE wip_t_project_closure_id = ?", [$TAG, $DEPLOY_DATE, $cid]);
            $db->update("UPDATE acct_doc SET is_active = 0, updated = ?, date_updated = ? WHERE acct_doc_id = ?", [$TAG, $DEPLOY_DATE, $docId]);
            $say("    closure/signees/acct_doc marked");
        }

        $wipAfter = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = 12502 AND ad_org_id = ?', [$WIP_SUB, $ORG_ID])->v;
        $say(''); $say(' POST-CHECK WIP net = ' . $money($wipAfter));
        if (abs($wipAfter) > $TOL) throw new \RuntimeException("WIP net after fix = $wipAfter");

        $db->commit();
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }

    $say(''); $say($line); $say(' SUCCESS.'); $say($line);
};
