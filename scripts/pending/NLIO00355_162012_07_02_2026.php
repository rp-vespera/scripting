<?php // scripts/pending/NLIO00355_162012_07_02_2026.php
// NLIO00355 - CONCRETE VAULT (project 5654, org 162012 RP Tan A)
// Fix: soft-delete DUPLICATE closure NWPCL-NVT0000411
//
// Root cause: 2 closures posted for same 1,932.96 consumption
//   NWPCL-NVT0000143 (2023-11-09) — legit closure
//   NWPCL-NVT0000411 (2025-09-18) — duplicate posted 2 years later, over-drained WIP
// Someone tried to reverse it via NVT0000124DR but it's stuck in DR (not processed).
// Result: WIP is at -1,932.96 (should be 0). Scanner shows +1,932.96 variance.
//
// Fix cascade (8 UPDATEs, no DELETE):
//   closure 25071 amt=0 (keep docstatus=PR per senior's rule)
//   contra 1867 is_active=0 (breaks the stuck-reversal link)
//   signees 47105, 47120 is_active=0
//   acct_gl 2231641 debit=0 credit=0 (DR 11309 side)
//   acct_gl 2231642 debit=0 credit=0 (CR 12502 side)
//   acct_balance 822043 debit=0 credit=0 (11309 side, exclusive row)
//   acct_balance 822063 debit=0 credit=0 (12502 side, exclusive row)
//   acct_doc 103783708 is_active=0
//
// After: WIP net -1,932.96 → 0.00
// Every UPDATE tagged updated='IMS-SCRIPT-WEB-15862', date_updated=deploy date

return function ($cmd) {
    $db  = \DB::connection('mysql_secondary');

    // Deployment date — update to actual go-live before running on staging/prod
    // (per feedback_script_audit_tag_convention: date_updated = deploy date).
    $DEPLOY_DATE = '2026-07-04 00:00:00';

    $TAG = 'IMS-SCRIPT-WEB-15862';
    $AMT = 1932.96;
    $TOL = 0.01;

    $line  = str_repeat('=', 90);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(' NLIO00355 - CONCRETE VAULT — soft-delete duplicate closure NWPCL-NVT0000411');
    $say(' Effect: WIP variance -1,932.96 → 0.00 (org 162012, subacct 25822)');
    $say($line);

    // IDEMPOTENCY — if closure 25071 already zeroed, skip
    $c = $db->selectOne("SELECT amt_closure FROM wip_t_project_closure WHERE wip_t_project_closure_id = 25071");
    if (!$c) throw new \RuntimeException('closure 25071 not found');
    if (abs((float) $c->amt_closure) < $TOL) {
        $say(''); $say(' NO-OP — closure 25071 already zeroed.'); $say($line); return;
    }
    if (abs((float) $c->amt_closure - $AMT) > $TOL) {
        throw new \RuntimeException("closure 25071 amt_closure is {$c->amt_closure}, expected {$AMT}");
    }

    // PRE-CHECK — WIP net = -1,932.96
    $wipNet = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = 25822 AND gl_acct_id = 12502 AND ad_org_id = 162012')->v;
    $say(''); $say(' PRE-CHECK WIP net = ' . $money($wipNet) . '  (must be -' . $money($AMT) . ')');
    if (abs($wipNet + $AMT) > $TOL) throw new \RuntimeException("WIP net is $wipNet, expected -{$AMT}");

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $a = $db->update("UPDATE wip_t_project_closure SET amt_closure = 0, docstatus = 'PR', updated = ?, date_updated = ? WHERE wip_t_project_closure_id = 25071", [$TAG, $DEPLOY_DATE]);
        $say("    closure 25071 amt→0, docstatus=PR: affected=$a");
        if ($a !== 1) throw new \RuntimeException("closure update affected $a");

        $a = $db->update("UPDATE wip_t_project_closure_contra SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_contra_id = 1867", [$TAG, $DEPLOY_DATE]);
        $say("    contra 1867 is_active=0: affected=$a");

        $a = $db->update("UPDATE wip_t_project_closure_signee SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_signee_id IN (47105, 47120)", [$TAG, $DEPLOY_DATE]);
        $say("    signees (47105, 47120) is_active=0: affected=$a");

        $a = $db->update("UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_gl_id = 2231641", [$TAG, $DEPLOY_DATE]);
        $say("    acct_gl 2231641 (11309 DR) → 0: affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_gl 2231641 affected $a");

        $a = $db->update("UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_gl_id = 2231642", [$TAG, $DEPLOY_DATE]);
        $say("    acct_gl 2231642 (12502 CR) → 0: affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_gl 2231642 affected $a");

        $a = $db->update("UPDATE acct_balance SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_balance_id = 822043", [$TAG, $DEPLOY_DATE]);
        $say("    acct_balance 822043 (11309) → 0: affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_balance 822043 affected $a");

        $a = $db->update("UPDATE acct_balance SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_balance_id = 822063", [$TAG, $DEPLOY_DATE]);
        $say("    acct_balance 822063 (12502) → 0: affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_balance 822063 affected $a");

        $a = $db->update("UPDATE acct_doc SET is_active = 0, updated = ?, date_updated = ? WHERE acct_doc_id = 103783708", [$TAG, $DEPLOY_DATE]);
        $say("    acct_doc 103783708 is_active=0: affected=$a");

        // POST-CHECK — WIP variance = 0
        $wipAfter = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = 25822 AND gl_acct_id = 12502 AND ad_org_id = 162012')->v;
        $say(''); $say(' POST-CHECK WIP net = ' . $money($wipAfter) . '  (must be 0.00)');
        if (abs($wipAfter) > $TOL) throw new \RuntimeException("WIP net after fix = $wipAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — NLIO00355 duplicate closure soft-deleted. WIP variance closed.');
    $say($line);
};
