<?php // GRNI21138_162012_07_31_2026.php
// ============================================================================
// acct_balance rollup repair — three May-2026 IGR documents
// Org 162012 · mysql_secondary · 2026-07-31
//
//   NIGR0006897, NIGR0006857 (07 May) and NIGR0006898 (14 May) were written to
//   acct_gl by the PHP backend (created = 'SYSTEM'). Seven legs never reached
//   acct_balance; their Java-written cancellation legs did, so the rollup holds
//   reversals of postings it never recorded.
//
//     date_gl      submod  acct   subacct   debit        credit      source
//     2026-05-07   153     11309  NULL      28,000.00                NIGR0006897
//     2026-05-07   153     11309  NULL      28,000.00                NIGR0006857
//     2026-05-14   153     11309  NULL      29,000.00                NIGR0006898
//     2026-05-07   192     11313  NULL                   28,000.00   NIGR0006897
//     2026-05-07   192     11313  NULL                   28,000.00   NIGR0006857
//     2026-05-14   192     11313  NULL                   29,000.00   NIGR0006898
//     2026-05-07   192     21138  20034     28,000.00                second leg
//                                           113,000.00   85,000.00
//
//   INSERT rather than the writer's update-and-net, so rollback is a DELETE and
//   no existing row is touched. is_active and created stay NULL, matching
//   AcctBalancePostingService, which sets neither.
//
//   The 11309 rows do not move the GRNI variance. Omitting them would repeat the
//   partial repair of 2026-05-26 and grow the Trial Balance gap to 312,300.01.
//
//   Leaves GRNI at -286.25: NIGR0006870-CA never reversed its 21138 and 21101
//   legs, which needs a Journal Voucher, not a rollup row.
//   Excluded: 11309 2026-06-12 (WPCL413) and 38 keys on 12502.
//   Accepts either run order with WPCL413_162012_07_31_2026.php.
//
// WRITES     acct_balance — 7 INSERTs. acct_gl asserted unchanged.
// ROLLBACK   GRNI21138_162012_07_31_2026_rollback.php
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $APPROVED = true;                       // <-- set true only with approval on record

    $POSTED   = '2026-07-31';                // posting date; audit stamp date
    $IMS      = null;                        // real IMS ticket number, or null
    $TAG      = ($IMS !== null && $IMS !== '') ? '#IMS-' . $POSTED : 'SCRIPT-WEB-' . $POSTED;

    $ORG      = 162012;
    $DOCS     = ['NIGR0006897', 'NIGR0006857', 'NIGR0006898'];

    // asserted, never used as input. 11309 has two valid pre-states because WPCL413
    // repairs the same account on a different key and may run before or after this.
    $ACCEPT_BEFORE = [11309 => [-87774.02, -85000.00], 11313 => [85000.00], 21138 => [-28000.00]];
    $DELTA         = [11309 =>   85000.00, 11313 => -85000.00, 21138 => 28000.00];
    $EXP_GRNI_BEFORE     = -57286.25;
    $EXP_GRNI_AFTER      =   -286.25;
    $ACCEPT_12502       = [-224525.99, -227300.00];   // WPCL413 not run / run

    $plan = [
        ['acct' => 11309, 'date' => '2026-05-07', 'sm' => 153, 'sub' => null,  'dr' => 28000.00, 'cr' => 0.00, 'src' => 'NIGR0006897'],
        ['acct' => 11309, 'date' => '2026-05-07', 'sm' => 153, 'sub' => null,  'dr' => 28000.00, 'cr' => 0.00, 'src' => 'NIGR0006857'],
        ['acct' => 11309, 'date' => '2026-05-14', 'sm' => 153, 'sub' => null,  'dr' => 29000.00, 'cr' => 0.00, 'src' => 'NIGR0006898'],
        ['acct' => 11313, 'date' => '2026-05-07', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 28000.00, 'src' => 'NIGR0006897'],
        ['acct' => 11313, 'date' => '2026-05-07', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 28000.00, 'src' => 'NIGR0006857'],
        ['acct' => 11313, 'date' => '2026-05-14', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 29000.00, 'src' => 'NIGR0006898'],
        ['acct' => 21138, 'date' => '2026-05-07', 'sm' => 192, 'sub' => 20034, 'dr' => 28000.00, 'cr' => 0.00, 'src' => 'second leg'],
    ];

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 96);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    // net drift of one account: acct_balance minus acct_gl
    $drift = function (int $acct) use ($db, $ORG) {
        $b = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_balance
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        $g = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_gl
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        return [round($b - $g, 2), $b, $g];
    };

    // GRNI vs Suspense as the scanner computes it, from acct_balance
    $grni = function () use ($db, $ORG) {
        $v = fn(int $a, string $e) => (float) $db->selectOne(
            "SELECT ROUND(IFNULL({$e},0),2) v FROM acct_balance WHERE ad_org_id = ? AND gl_acct_id = ?",
            [$ORG, $a])->v;
        $cd = 'SUM(credit)-SUM(debit)'; $dc = 'SUM(debit)-SUM(credit)';
        return round($v(21138, $cd) - ($v(11313, $dc) + $v(11302, $dc) + $v(11311, $dc) + $v(92005, $dc)), 2);
    };

    $say($L);
    $say(' GRNI 21138 — acct_balance rollup repair, three May-2026 IGR documents');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · org ' . $ORG . ' · tag ' . $TAG
         . ' · MODE: ' . ($APPROVED ? 'APPLY' : 'DRY-RUN'));
    $say($L);

    // ---------------- GATE 0 · replica only ----------------
    if ($APPROVED && stripos($schema, 'replica') === false)
        throw new \RuntimeException("GATE 0 FAILED: '$schema' is not a replica. Live execution "
            . 'requires the approved maker/checker procedure, not this script.');

    // ---------------- GATE 1 · the source journal is exactly as audited ----------------
    $want = [
        'NIGR0006897' => [11309 => 28000.00, 11313 => -28000.00, 21138 => 28000.00],
        'NIGR0006857' => [11309 => 28000.00, 11313 => -28000.00, 21138 => 28000.00],
        'NIGR0006898' => [11309 => 29000.00, 11313 => -29000.00, 21138 => 29000.00],
    ];
    foreach ($DOCS as $doc) {
        foreach ($want[$doc] as $acct => $exp) {
            $r = $db->selectOne(
                'SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v, COUNT(*) n
                   FROM acct_gl WHERE documentno = ? AND ad_org_id = ? AND gl_acct_id = ?
                    AND created = ?', [$doc, $ORG, $acct, 'SYSTEM']);
            if ((int) $r->n === 0)
                throw new \RuntimeException("GATE 1 FAILED: no SYSTEM-written acct_gl row for "
                    . "$doc / $acct. The source journal has changed. ABORT.");
            if (abs((float) $r->v - $exp) > 0.001)
                throw new \RuntimeException("GATE 1 FAILED: $doc / $acct nets " . $m($r->v)
                    . ', expected ' . $m($exp) . '. ABORT.');
        }
    }

    // ---------------- GATE 2 · not already applied ----------------
    // Scoped to this script's own keys: a sibling script posted the same day shares $TAG.
    $tagged = (int) $db->selectOne(
        "SELECT COUNT(*) n FROM acct_balance WHERE updated = ? AND ad_org_id = ?
           AND date_gl IN ('2026-05-07','2026-05-14') AND doc_i_submod_id IN (153,192)",
        [$TAG, $ORG])->n;
    if ($tagged > 0)
        throw new \RuntimeException("GATE 2 FAILED: $tagged acct_balance row(s) on this script's own "
            . "keys already carry $TAG. Roll back the earlier run before re-applying. ABORT.");

    // ---------------- GATE 3 · pre-state drift matches the audit ----------------
    $before = []; $expAfter = [];
    foreach ([11309, 11313, 21138] as $acct) {
        [$d, $b, $g] = $drift($acct);
        $ok = false;
        foreach ($ACCEPT_BEFORE[$acct] as $cand) if (abs($d - $cand) <= 0.01) $ok = true;
        if (!$ok)
            throw new \RuntimeException("GATE 3 FAILED: $acct drift is " . $m($d) . ', expected one of '
                . implode(' / ', array_map($m, $ACCEPT_BEFORE[$acct])) . '. Another repair has run since '
                . 'the audit, or the source moved. Re-baseline deliberately. ABORT.');
        $before[$acct]   = $d;
        $expAfter[$acct] = round($d + $DELTA[$acct], 2);
        $say(sprintf('        %-6s acct_balance %16s · acct_gl %16s · drift %14s  ->  %14s',
             $acct, $m($b), $m($g), $m($d), $m($expAfter[$acct])));
    }
    if (abs($before[11309] - -85000.00) <= 0.01)
        $say('        NOTE  11309 is already at -85,000.00 — WPCL413 has run. This takes it to 0.00.');
    [$d12502] = $drift(12502);
    $ok12502 = false;
    foreach ($ACCEPT_12502 as $c) if (abs($d12502 - $c) <= 0.01) $ok12502 = true;
    if (!$ok12502)
        throw new \RuntimeException('GATE 3 FAILED: 12502 drift is ' . $m($d12502) . ', expected one of '
            . implode(' / ', array_map($m, $ACCEPT_12502)) . '. The out-of-scope population moved. ABORT.');

    // ---------------- GATE 4 · the reported variance matches ----------------
    $grniBefore = $grni();
    if (abs($grniBefore - $EXP_GRNI_BEFORE) > 0.01)
        throw new \RuntimeException('GATE 4 FAILED: GRNI variance is ' . $m($grniBefore)
            . ', expected ' . $m($EXP_GRNI_BEFORE) . '. ABORT.');

    $say(' GATES  0 replica · 1 journal (3 docs, SYSTEM-written) · 2 not applied · 3 drift'
         . ' · 4 GRNI ' . $m($grniBefore) . ' ..... PASS');
    $say('');
    $say('   PLAN — 7 INSERTs into acct_balance');
    $say('   date_gl      submod  acct    subacct        debit         credit   source');
    $totDr = 0.0; $totCr = 0.0;
    foreach ($plan as $p) {
        $totDr += $p['dr']; $totCr += $p['cr'];
        $say(sprintf('   %-12s %-7s %-7s %-9s %12s %14s   %s',
             $p['date'], $p['sm'], $p['acct'], $p['sub'] ?? 'NULL', $m($p['dr']), $m($p['cr']), $p['src']));
    }
    $say(sprintf('   %-38s %12s %14s', 'TOTAL', $m($totDr), $m($totCr)));

    // ---------------- APPROVAL GUARD ----------------
    if (!$APPROVED) {
        $say('');
        $say(' DRY-RUN — zero database writes, zero files created.');
        $say(' Expected after apply:  11309 ' . $m($expAfter[11309])
             . ' · 11313 ' . $m($expAfter[11313]) . ' · 21138 ' . $m($expAfter[21138]));
        $say(' Expected GRNI variance: ' . $m($EXP_GRNI_BEFORE) . '  ->  ' . $m($EXP_GRNI_AFTER)
             . '   (0.00 once the 286.25 Journal Voucher is posted)');
        $tbNow = round($before[11309] + $before[11313] + $before[21138] + $d12502, 2);
        $tbAft = round($expAfter[11309] + $expAfter[11313] + $expAfter[21138] + $d12502, 2);
        $say(' Trial Balance imbalance: ' . $m($tbNow) . '  ->  ' . $m($tbAft));
        $say(' APPROVAL REQUIRED before this runs.');
        $say($L);
        if (isset($cmd)) $cmd->info('GRNI21138 DRY-RUN: 7 inserts planned, no writes.');
        return;
    }

    // ---------------- APPLY ----------------
    $glBefore  = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                   FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
    $balBefore = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_balance WHERE ad_org_id = ?', [$ORG])->n;

    $db->beginTransaction();
    try {
        $ids = [];
        foreach ($plan as $p) {
            $db->insert(
                'INSERT INTO acct_balance
                   (gl_acct_id, date_gl, ad_org_id, created, date_created, updated, date_updated,
                    is_active, debit, credit, gl_subacct_id, gl_subgroup_id, term_days, doc_i_submod_id)
                 VALUES (?, DATE(?), ?, NULL, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, ?)',
                [$p['acct'], $p['date'], $ORG, $POSTED . ' 00:00:00', $TAG, $POSTED . ' 00:00:00',
                 $p['dr'], $p['cr'], $p['sub'], $p['sm']]);
            $ids[] = (int) $db->selectOne('SELECT LAST_INSERT_ID() id')->id;
        }
        if (count($ids) !== count($plan))
            throw new \RuntimeException('inserted ' . count($ids) . ', planned ' . count($plan) . '. ABORT.');

        // ---------------- POST-CHECKS ----------------
        $glAfter = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                     FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
        if ((int) $glAfter->n !== (int) $glBefore->n || abs((float) $glAfter->v - (float) $glBefore->v) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl moved. It must never be written. ABORT.');

        $balAfter = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_balance WHERE ad_org_id = ?', [$ORG])->n;
        if ($balAfter !== $balBefore + count($plan))
            throw new \RuntimeException('POST-CHECK FAILED: acct_balance row count moved by '
                . ($balAfter - $balBefore) . ', expected ' . count($plan) . '. ABORT.');

        $t = (int) $db->selectOne(
            "SELECT COUNT(*) n FROM acct_balance WHERE updated = ? AND ad_org_id = ?
               AND date_gl IN ('2026-05-07','2026-05-14') AND doc_i_submod_id IN (153,192)",
            [$TAG, $ORG])->n;
        if ($t !== count($plan))
            throw new \RuntimeException("POST-CHECK FAILED: $t rows carry $TAG, expected "
                . count($plan) . '. ABORT.');

        $after = [];
        foreach ([11309, 11313, 21138] as $acct) {
            [$d] = $drift($acct);
            $after[$acct] = $d;
            if (abs($d - $expAfter[$acct]) > 0.01)
                throw new \RuntimeException("POST-CHECK FAILED: $acct drift is " . $m($d)
                    . ', expected ' . $m($expAfter[$acct]) . '. ABORT.');
        }
        [$d12502after] = $drift(12502);
        if (abs($d12502after - $d12502) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: 12502 drift moved from ' . $m($d12502)
                . ' to ' . $m($d12502after) . '. This script must not touch it. ABORT.');

        $grniAfter = $grni();
        if (abs($grniAfter - $EXP_GRNI_AFTER) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: GRNI variance is ' . $m($grniAfter)
                . ', expected ' . $m($EXP_GRNI_AFTER) . '. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK  7 rows inserted (ids ' . implode(', ', $ids) . ') · acct_gl unchanged'
             . ' · acct_balance +7 · tagged 7 · 12502 untouched');
        foreach ([11309, 11313, 21138] as $acct)
            $say(sprintf('             %-6s drift %14s  ->  %14s',
                 $acct, $m($before[$acct]), $m($after[$acct])));
        $say('             GRNI variance ' . $m($grniBefore) . '  ->  ' . $m($grniAfter));
        $say(abs($after[11309]) < 0.01
             ? '             11309 fully reconciled — WPCL413 had already run'
             : '             11309 residual ' . $m($after[11309]) . ' is the WPCL413 key');
        $say(' COMPLETE — post the 286.25 Journal Voucher to take GRNI to 0.00');
        $say($L);
        if (isset($cmd)) $cmd->info('GRNI21138 applied: 7 rows, ids ' . implode(',', $ids));
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
