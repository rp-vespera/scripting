<?php // scripts/pending/NLIO00469_162012_07_02_2026.php
// NLIO00469 - CONCRETE VAULT (project 8405, org 162012 RP Tan A)
// Fix: soft-delete the DUPLICATE reversal NWPCLR-NVT0000063
//
// Root cause: 2 reversals posted against same closure NVT0000252
//   contra 1595 → NWPCLR-NVT0000061 reverses NVT0000252  (legit)
//   contra 1590 → NWPCLR-NVT0000063 reverses NVT0000252  (duplicate — this is the bug)
// The duplicate DR 421.95 has no offset → WIP shows +421.95 residual.
//
// Fix cascade (6 UPDATEs, no DELETE):
//   closure 20920 amt=0 (keep docstatus=PR per senior's rule)
//   contra   1590 is_active=0
//   signees  39030, 39111 is_active=0
//   acct_gl  1961539 debit=0 credit=0
//   acct_balance 733506 debit -= 421.95  (shared row with NVT0000061 — decrement, don't zero)
//   acct_doc 103704833 is_active=0
//
// After: WIP variance 421.95 → 0.00
// Every UPDATE tagged updated='IMS-SCRIPT-WEB-17064', date_updated=deploy date

return function ($cmd) {
    $db  = \DB::connection('mysql_secondary');

    // Deployment date — update to actual go-live before running on staging/prod
    // (per feedback_script_audit_tag_convention: date_updated = deploy date).
    $DEPLOY_DATE = '2026-07-04 00:00:00';

    $TAG = 'IMS-SCRIPT-WEB-17064';
    $AMT = 421.95;
    $TOL = 0.01;

    $line  = str_repeat('=', 90);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(' NLIO00469 - CONCRETE VAULT — soft-delete duplicate reversal NWPCLR-NVT0000063');
    $say(' Effect: WIP variance +421.95 → 0.00 (org 162012, subacct 29987)');
    $say($line);

    // IDEMPOTENCY — if closure 20920 already zeroed, skip
    $c = $db->selectOne("SELECT amt_closure FROM wip_t_project_closure WHERE wip_t_project_closure_id = 20920");
    if (!$c) throw new \RuntimeException('closure 20920 not found');
    if (abs((float) $c->amt_closure) < $TOL) {
        $say(''); $say(' NO-OP — closure 20920 already zeroed.'); $say($line); return;
    }
    // NLIO00469's closure stores as -421.95 (reversal amount) so compare abs value
    if (abs(abs((float) $c->amt_closure) - $AMT) > $TOL) {
        throw new \RuntimeException("closure 20920 amt_closure is {$c->amt_closure}, expected ±{$AMT}");
    }

    // PRE-CHECK — WIP net = +421.95
    $wipNet = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = 29987 AND gl_acct_id = 12502 AND ad_org_id = 162012')->v;
    $say(''); $say(' PRE-CHECK WIP net = ' . $money($wipNet) . '  (must be +' . $money($AMT) . ')');
    if (abs($wipNet - $AMT) > $TOL) throw new \RuntimeException("WIP net is $wipNet, expected +{$AMT}");

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $a = $db->update("UPDATE wip_t_project_closure SET amt_closure = 0, docstatus = 'PR', updated = ?, date_updated = ? WHERE wip_t_project_closure_id = 20920", [$TAG, $DEPLOY_DATE]);
        $say("    closure 20920 amt→0, docstatus=PR: affected=$a");
        if ($a !== 1) throw new \RuntimeException("closure update affected $a");

        $a = $db->update("UPDATE wip_t_project_closure_contra SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_contra_id = 1590", [$TAG, $DEPLOY_DATE]);
        $say("    contra 1590 is_active=0: affected=$a");

        $a = $db->update("UPDATE wip_t_project_closure_signee SET is_active = 0, updated = ?, date_updated = ? WHERE wip_t_project_closure_signee_id IN (39030, 39111)", [$TAG, $DEPLOY_DATE]);
        $say("    signees (39030, 39111) is_active=0: affected=$a");

        $a = $db->update("UPDATE acct_gl SET debit = 0, credit = 0, updated = ?, date_updated = ? WHERE acct_gl_id = 1961539", [$TAG, $DEPLOY_DATE]);
        $say("    acct_gl 1961539 → 0: affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_gl 1961539 affected $a");

        // acct_balance 733506 is SHARED with the legit reversal — decrement, don't zero.
        $a = $db->update("UPDATE acct_balance SET debit = debit - ?, updated = ?, date_updated = ? WHERE acct_balance_id = 733506", [$AMT, $TAG, $DEPLOY_DATE]);
        $say("    acct_balance 733506 debit -= " . $money($AMT) . " (shared row): affected=$a");
        if ($a !== 1) throw new \RuntimeException("acct_balance 733506 affected $a");

        $a = $db->update("UPDATE acct_doc SET is_active = 0, updated = ?, date_updated = ? WHERE acct_doc_id = 103704833", [$TAG, $DEPLOY_DATE]);
        $say("    acct_doc 103704833 is_active=0: affected=$a");

        // POST-CHECK — WIP variance = 0
        $wipAfter = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = 29987 AND gl_acct_id = 12502 AND ad_org_id = 162012')->v;
        $say(''); $say(' POST-CHECK WIP net = ' . $money($wipAfter) . '  (must be 0.00)');
        if (abs($wipAfter) > $TOL) throw new \RuntimeException("WIP net after fix = $wipAfter");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — NLIO00469 duplicate reversal soft-deleted. WIP variance closed.');
    $say($line);
};
