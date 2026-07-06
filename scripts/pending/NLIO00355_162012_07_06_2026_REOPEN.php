<?php // scripts/pending/NLIO00355_162012_07_06_2026_REOPEN.php
// NLIO00355 - CONCRETE VAULT (project 5654, org 162012 RP Tan A)
// Reopen the project so future consumption (e.g., lapida repair) can post.
//
// Companion to NLIO00355_162012_07_02_2026.php (variance fix).
// Order: (1) variance fix must run first, (2) then this reopen.
//
// Business reason from senior: if a lapida breaks in a concrete vault,
// operations need to open the vault and put a replacement inside. That
// creates new consumption on the project, which requires the project to
// be in OPEN status (COMMENCED). Currently the project is CLOSED, so
// consumption postings are rejected.
//
// Fix: 3-field UPDATE on wip_i_project row 5654
//   project_status: 'CLOSED' → 'COMMENCED'
//   date_end_actual: '2026-06-12' → NULL
//   wip_t_project_closure_id: 27137 → NULL
//
// After: project accepts new consumption / new closures. Variance stays 0.
// All 3 existing closures remain intact (legit 1932.96 + duplicate 0 + auto-closer 2774.01).

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    // Deployment date — per feedback_script_audit_tag_convention
    $DEPLOY_DATE = '2026-07-06 00:00:00';

    $TAG = 'IMS-SCRIPT-WEB-15862';
    $PROJECT_ID = 5654;
    $SUBACCT    = 25822;
    $ORG        = 162012;
    $ACCT_WIP   = 12502;
    $CLOSED_END_DATE = '2026-06-12';       // current date_end_actual (for rollback reference)
    $FINAL_CLOSURE_ID = 27137;             // current wip_t_project_closure_id (NWPCL-NVT0000413)

    $line = str_repeat('=', 95);
    $say  = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(' NLIO00355 REOPEN — flip project 5654 CLOSED → COMMENCED so future consumption can post');
    $say(' Business reason: lapida repair operations need to add new consumption to the vault project');
    $say($line);

    // IDEMPOTENCY — if already COMMENCED, skip
    $p = $db->selectOne('SELECT project_status, date_end_actual, wip_t_project_closure_id FROM wip_i_project WHERE wip_i_project_id = ?', [$PROJECT_ID]);
    if (!$p) throw new \RuntimeException("Project {$PROJECT_ID} not found");
    if ($p->project_status !== 'CLOSED') {
        $say(''); $say(" NO-OP — project_status is '{$p->project_status}', not CLOSED. Already reopened or in unexpected state.");
        $say($line); return;
    }

    // PRE-CHECK 1: variance is 0 (must run variance-fix script first)
    $variance = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = ? AND ad_org_id = ?', [$SUBACCT, $ACCT_WIP, $ORG])->v;
    $say(''); $say(' PRE-CHECK 1 WIP variance = ' . $money($variance) . '  (must be 0.00; run NLIO00355 variance-fix first if not)');
    if (abs($variance) > 0.01) throw new \RuntimeException("Variance is {$variance}, expected 0. Run the variance-fix script first.");

    // PRE-CHECK 2: current values match what we expect to rollback from
    $say(' PRE-CHECK 2 project_status = ' . $p->project_status . ' ✓');
    $say(' PRE-CHECK 2 date_end_actual = ' . ($p->date_end_actual ?? 'NULL') . ' ✓');
    $say(' PRE-CHECK 2 wip_t_project_closure_id = ' . ($p->wip_t_project_closure_id ?? 'NULL'));

    // APPLY
    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $a = $db->update(
            "UPDATE wip_i_project
             SET project_status = 'COMMENCED',
                 date_end_actual = NULL,
                 wip_t_project_closure_id = NULL,
                 updated = ?,
                 date_updated = ?
             WHERE wip_i_project_id = ?
               AND project_status = 'CLOSED'",
            [$TAG, $DEPLOY_DATE, $PROJECT_ID]
        );
        $say("    UPDATE wip_i_project {$PROJECT_ID}: affected=$a");
        if ($a !== 1) throw new \RuntimeException("wip_i_project update affected $a, expected 1");

        // POST-CHECK — project is now COMMENCED
        $after = $db->selectOne('SELECT project_status, date_end_actual, wip_t_project_closure_id FROM wip_i_project WHERE wip_i_project_id = ?', [$PROJECT_ID]);
        if ($after->project_status !== 'COMMENCED') throw new \RuntimeException("project_status is {$after->project_status}, expected COMMENCED");
        if ($after->date_end_actual !== null) throw new \RuntimeException("date_end_actual not NULL");
        if ($after->wip_t_project_closure_id !== null) throw new \RuntimeException("wip_t_project_closure_id not NULL");
        $say(''); $say(' POST-CHECK project_status = COMMENCED ✓, date_end_actual = NULL ✓, wip_t_project_closure_id = NULL ✓');

        // POST-CHECK — variance still 0
        $varAfter = (float) $db->selectOne('SELECT ROUND(SUM(debit-credit),2) v FROM acct_balance WHERE gl_subacct_id = ? AND gl_acct_id = ? AND ad_org_id = ?', [$SUBACCT, $ACCT_WIP, $ORG])->v;
        $say(' POST-CHECK WIP variance = ' . $money($varAfter) . ' (must stay 0.00)');
        if (abs($varAfter) > 0.01) throw new \RuntimeException("Variance drifted to {$varAfter} after reopen");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — NLIO00355 reopened. Project can now accept future consumption (lapida repair, etc.).');
    $say(' Variance stays at 0.00. All 3 existing closures remain intact.');
    $say($line);
};
