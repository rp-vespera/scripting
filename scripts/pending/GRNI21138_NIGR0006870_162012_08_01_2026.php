<?php // GRNI21138_NIGR0006870_162012_08_01_2026.php
// ============================================================================
// GRNI vs Suspense — org 162012 RP Tan A — takes the reported variance to 0.00
// Org 162012 · mysql_secondary · 2026-08-01
//
//   Two unrelated defects sit behind one reported figure of -57,286.25.
//   They must be repaired in this order; PHASE 2 alone leaves -57,000.00.
//
//   PHASE 1 — 57,000.00 · acct_balance rollup gap
//     NIGR0006897, NIGR0006857 (07 May) and NIGR0006898 (14 May) were written to
//     acct_gl by the PHP backend (created = 'SYSTEM'). Seven legs never reached
//     acct_balance; their Java-written cancellation legs did, so the rollup holds
//     reversals of postings it never recorded. Repaired by INSERT.
//
//   PHASE 2 — 286.25 · document-number collision
//     NIGR0006870 (SCI# KOROST 2267, BUILDMORE CONSTRUCTION DEPOT) is a live
//     invoice whose document number the Auto IGR reused for JR BOY SAND AND
//     GRAVEL. Cancelling JR BOY's transaction cancelled BUILDMORE's as well.
//     wrongful_variance_fix.php (commit 1664485, ran 2026-05-29) zeroed the two
//     legs carrying subacct 3149; 11309 and 11313 carry no subaccount and were
//     unreachable by that filter. Repaired by UPDATE to 0.00.
//
//   Each phase detects its own prior application and skips rather than aborting,
//   so the file is correct on an environment where PHASE 1 has already run.
//   Both phases share one transaction: a PHASE 2 failure reverses PHASE 1.
//
// WRITES     acct_balance — 7 INSERTs, 2 UPDATEs · acct_gl — 2 UPDATEs
// ROLLBACK   GRNI21138_NIGR0006870_162012_08_01_2026_rollback.php
// CASE       GRNI21138_162012_07_31_2026.md · NIGR0006870_162012_08_01_2026.md
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $APPROVED = true;                       // <-- set true only with approval on record

    $POSTED   = '2026-08-01';                // posting date; audit stamp date
    $IMS      = null;                        // real IMS ticket number, or null
    $TAG      = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;
    $STAMP    = $POSTED . ' 00:00:00';

    $ORG      = 162012;

    // ---- PHASE 1
    $DOCS          = ['NIGR0006897', 'NIGR0006857', 'NIGR0006898'];
    $ACCEPT_BEFORE = [11309 => [-87774.02, -85000.00], 11313 => [85000.00], 21138 => [-28000.00]];
    $DELTA         = [11309 =>   85000.00, 11313 => -85000.00, 21138 => 28000.00];
    $ACCEPT_12502  = [-224525.99, -227300.00];       // WPCL413 not run / run
    $P1_KEYS       = "date_gl IN ('2026-05-07','2026-05-14') AND doc_i_submod_id IN (153,192)";

    $plan = [
        ['acct' => 11309, 'date' => '2026-05-07', 'sm' => 153, 'sub' => null,  'dr' => 28000.00, 'cr' => 0.00, 'src' => 'NIGR0006897'],
        ['acct' => 11309, 'date' => '2026-05-07', 'sm' => 153, 'sub' => null,  'dr' => 28000.00, 'cr' => 0.00, 'src' => 'NIGR0006857'],
        ['acct' => 11309, 'date' => '2026-05-14', 'sm' => 153, 'sub' => null,  'dr' => 29000.00, 'cr' => 0.00, 'src' => 'NIGR0006898'],
        ['acct' => 11313, 'date' => '2026-05-07', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 28000.00, 'src' => 'NIGR0006897'],
        ['acct' => 11313, 'date' => '2026-05-07', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 28000.00, 'src' => 'NIGR0006857'],
        ['acct' => 11313, 'date' => '2026-05-14', 'sm' => 192, 'sub' => null,  'dr' => 0.00, 'cr' => 29000.00, 'src' => 'NIGR0006898'],
        ['acct' => 21138, 'date' => '2026-05-07', 'sm' => 192, 'sub' => 20034, 'dr' => 28000.00, 'cr' => 0.00, 'src' => 'second leg'],
    ];

    // ---- PHASE 2
    $DATE_GL = '2026-05-14';
    $AMT     = 286.25;
    $GL      = [2387993 => [11309, 191, 0.00, $AMT], 2387998 => [11313, 198, $AMT, 0.00]];
    $BAL     = [927677  => [11309, 191, 0.00, $AMT], 927682  => [11313, 198, $AMT, 0.00]];
    $PHASE4  = [2387994, 2387997];                   // wrongful_variance_fix's own rows
    $INVOICE = [2387960 => 21138, 2387961 => 11313, 2387962 => 11309, 2387963 => 21101];

    $EXP_GRNI_FINAL = 0.00;

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 96);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    $drift = function (int $acct) use ($db, $ORG) {
        $b = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_balance
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        $g = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_gl
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        return [round($b - $g, 2), $b, $g];
    };
    $grni = function () use ($db, $ORG) {
        $v = fn(int $a, string $e) => (float) $db->selectOne(
            "SELECT ROUND(IFNULL({$e},0),2) v FROM acct_balance WHERE ad_org_id = ? AND gl_acct_id = ?",
            [$ORG, $a])->v;
        $cd = 'SUM(credit)-SUM(debit)'; $dc = 'SUM(debit)-SUM(credit)';
        return round($v(21138, $cd) - ($v(11313, $dc) + $v(11302, $dc) + $v(11311, $dc) + $v(92005, $dc)), 2);
    };

    $say($L);
    $say(' GRNI vs Suspense — org ' . $ORG . ' — PHASE 1 rollup gap + PHASE 2 collision');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · tag ' . $TAG
         . ' · MODE: ' . ($APPROVED ? 'APPLY' : 'DRY-RUN'));
    $say($L);

    // ---------------- GATE 0 · replica only ----------------
    if ($APPROVED && stripos($schema, 'replica') === false)
        throw new \RuntimeException("GATE 0 FAILED: '$schema' is not a replica. Live execution "
            . 'requires the approved maker/checker procedure, not this script.');

    // ---------------- PHASE STATE · already applied? ----------------
    // any audit tag, not just this run's: PHASE 1 may have shipped under an earlier date
    $anyTag = "(updated LIKE 'SCRIPT-WEB-%' OR updated LIKE '#IMS-%' OR updated LIKE 'IMS_SCRIPT_WEB-%')";

    $p1Rows = (int) $db->selectOne("SELECT COUNT(*) n FROM acct_balance
                                     WHERE ad_org_id = ? AND {$P1_KEYS} AND {$anyTag}", [$ORG])->n;
    if ($p1Rows !== 0 && $p1Rows !== count($plan))
        throw new \RuntimeException("PHASE 1 STATE UNCLEAR: $p1Rows tagged rows on its keys, expected 0 or "
            . count($plan) . '. A partial run needs rolling back before this can proceed. ABORT.');
    $p1Applied = ($p1Rows === count($plan));

    $p2Zeroed = 0; $p2Audited = 0;
    foreach ([['acct_gl', 'acct_gl_id', $GL], ['acct_balance', 'acct_balance_id', $BAL]] as [$t, $pk, $set]) {
        foreach ($set as $id => [$acct, $sm, $dr, $cr]) {
            $r = $db->selectOne("SELECT debit, credit FROM {$t} WHERE {$pk} = ?", [$id]);
            if (!$r) throw new \RuntimeException("PHASE 2 STATE: {$t} {$id} not found. ABORT.");
            if ((float) $r->debit == 0.0 && (float) $r->credit == 0.0) $p2Zeroed++;
            elseif (abs((float) $r->debit - $dr) <= 0.001 && abs((float) $r->credit - $cr) <= 0.001) $p2Audited++;
        }
    }
    if ($p2Zeroed !== 4 && $p2Audited !== 4)
        throw new \RuntimeException("PHASE 2 STATE UNCLEAR: $p2Zeroed zeroed / $p2Audited as-audited of 4. ABORT.");
    $p2Applied = ($p2Zeroed === 4);

    $grniStart = $grni();
    $say(sprintf('        PHASE 1  %s', $p1Applied ? 'already applied — will SKIP' : 'pending — 7 INSERTs'));
    $say(sprintf('        PHASE 2  %s', $p2Applied ? 'already applied — will SKIP' : 'pending — 4 UPDATEs'));
    $say(sprintf('        GRNI variance now %s', $m($grniStart)));

    if ($p1Applied && $p2Applied) {
        $say('');
        $say(' NOTHING TO DO — both phases already applied.');
        if (abs($grniStart - $EXP_GRNI_FINAL) > 0.01)
            throw new \RuntimeException('but GRNI variance is ' . $m($grniStart) . ', expected '
                . $m($EXP_GRNI_FINAL) . '. Investigate before re-running. ABORT.');
        $say($L);
        if (isset($cmd)) $cmd->info('GRNI21138+NIGR0006870: nothing to do, variance 0.00.');
        return;
    }

    // ---------------- PHASE 1 GATES ----------------
    $before = []; $expAfter = []; $d12502 = null;
    if (!$p1Applied) {
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
                    throw new \RuntimeException("P1 GATE 1 FAILED: no SYSTEM-written acct_gl row for "
                        . "$doc / $acct. The source journal has changed. ABORT.");
                if (abs((float) $r->v - $exp) > 0.001)
                    throw new \RuntimeException("P1 GATE 1 FAILED: $doc / $acct nets " . $m($r->v)
                        . ', expected ' . $m($exp) . '. ABORT.');
            }
        }
        foreach ([11309, 11313, 21138] as $acct) {
            [$d, $b, $g] = $drift($acct);
            $ok = false;
            foreach ($ACCEPT_BEFORE[$acct] as $cand) if (abs($d - $cand) <= 0.01) $ok = true;
            if (!$ok)
                throw new \RuntimeException("P1 GATE 2 FAILED: $acct drift is " . $m($d) . ', expected one of '
                    . implode(' / ', array_map($m, $ACCEPT_BEFORE[$acct])) . '. Re-baseline deliberately. ABORT.');
            $before[$acct]   = $d;
            $expAfter[$acct] = round($d + $DELTA[$acct], 2);
        }
        [$d12502] = $drift(12502);
        $ok = false;
        foreach ($ACCEPT_12502 as $c) if (abs($d12502 - $c) <= 0.01) $ok = true;
        if (!$ok)
            throw new \RuntimeException('P1 GATE 3 FAILED: 12502 drift is ' . $m($d12502)
                . '. The out-of-scope population moved. ABORT.');
    }

    // ---------------- PHASE 2 GATES ----------------
    if (!$p2Applied) {
        foreach ($GL as $id => [$acct, $sm, $dr, $cr]) {
            $r = $db->selectOne('SELECT gl_acct_id, doc_i_submod_id, date_gl, gl_subacct_id, ad_org_id,
                                        COALESCE(updated,\'\') upd, documentno
                                   FROM acct_gl WHERE acct_gl_id = ?', [$id]);
            if ((int) $r->ad_org_id !== $ORG || (int) $r->gl_acct_id !== $acct
                || (int) $r->doc_i_submod_id !== $sm
                || substr((string) $r->date_gl, 0, 10) !== $DATE_GL || $r->gl_subacct_id !== null)
                throw new \RuntimeException("P2 GATE 1 FAILED: acct_gl $id is not the audited row "
                    . "({$r->documentno}). ABORT.");
            if ($r->upd !== '')
                throw new \RuntimeException("P2 GATE 1 FAILED: acct_gl $id carries updated='{$r->upd}'. ABORT.");
        }
        foreach ($PHASE4 as $id) {
            $r = $db->selectOne('SELECT debit, credit, COALESCE(updated,\'\') upd
                                   FROM acct_gl WHERE acct_gl_id = ?', [$id]);
            if (!$r || (float) $r->debit != 0.0 || (float) $r->credit != 0.0 || $r->upd !== 'SYSTEM')
                throw new \RuntimeException("P2 GATE 2 FAILED: acct_gl $id is not wrongful_variance_fix's "
                    . 'zeroed row. This phase completes that repair and must not run without it. ABORT.');
        }
        foreach ($INVOICE as $id => $acct) {
            $r = $db->selectOne('SELECT gl_acct_id, debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$id]);
            if (!$r || (int) $r->gl_acct_id !== $acct
                || abs(((float) $r->debit + (float) $r->credit) - $AMT) > 0.001)
                throw new \RuntimeException("P2 GATE 3 FAILED: invoice leg acct_gl $id is not "
                    . $m($AMT) . " on account $acct. ABORT.");
        }
    }

    $say(' GATES  0 replica' . ($p1Applied ? '' : ' · P1 journal, drift, 12502')
         . ($p2Applied ? '' : ' · P2 rows, prior repair, invoice') . ' ..... PASS');
    $say('');
    if (!$p1Applied) {
        $say('   PHASE 1 — 7 INSERTs into acct_balance');
        $say('   date_gl      submod  acct    subacct        debit         credit   source');
        $totDr = 0.0; $totCr = 0.0;
        foreach ($plan as $p) {
            $totDr += $p['dr']; $totCr += $p['cr'];
            $say(sprintf('   %-12s %-7s %-7s %-9s %12s %14s   %s',
                 $p['date'], $p['sm'], $p['acct'], $p['sub'] ?? 'NULL', $m($p['dr']), $m($p['cr']), $p['src']));
        }
        $say(sprintf('   %-38s %12s %14s', 'TOTAL', $m($totDr), $m($totCr)));
        $say('');
    }
    if (!$p2Applied) {
        $say('   PHASE 2 — 4 UPDATEs to 0.00');
        foreach ($GL as $id => [$acct, $sm, $dr, $cr])
            $say(sprintf('   acct_gl      %-8s %-6s sm %-4s dr %10s  cr %10s', $id, $acct, $sm, $m($dr), $m($cr)));
        foreach ($BAL as $id => [$acct, $sm, $dr, $cr])
            $say(sprintf('   acct_balance %-8s %-6s sm %-4s dr %10s  cr %10s', $id, $acct, $sm, $m($dr), $m($cr)));
        $say('');
    }
    $say('   GRNI variance  ' . $m($grniStart) . '  ->  ' . $m($EXP_GRNI_FINAL));

    // ---------------- APPROVAL GUARD ----------------
    if (!$APPROVED) {
        $say('');
        $say(' DRY-RUN — zero database writes, zero files created.');
        $say(' APPROVAL REQUIRED before this runs.');
        $say($L);
        if (isset($cmd)) $cmd->info('GRNI21138+NIGR0006870 DRY-RUN: no writes.');
        return;
    }

    // ---------------- APPLY ----------------
    $glBefore  = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                   FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
    $balBefore = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_balance WHERE ad_org_id = ?', [$ORG])->n;

    $db->beginTransaction();
    try {
        $ids = [];

        // ---- PHASE 1
        if (!$p1Applied) {
            foreach ($plan as $p) {
                $db->insert(
                    'INSERT INTO acct_balance
                       (gl_acct_id, date_gl, ad_org_id, created, date_created, updated, date_updated,
                        is_active, debit, credit, gl_subacct_id, gl_subgroup_id, term_days, doc_i_submod_id)
                     VALUES (?, DATE(?), ?, NULL, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, ?)',
                    [$p['acct'], $p['date'], $ORG, $STAMP, $TAG, $STAMP,
                     $p['dr'], $p['cr'], $p['sub'], $p['sm']]);
                $ids[] = (int) $db->selectOne('SELECT LAST_INSERT_ID() id')->id;
            }
            if (count($ids) !== count($plan))
                throw new \RuntimeException('P1: inserted ' . count($ids) . ', planned '
                    . count($plan) . '. ABORT.');

            $balMid = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_balance WHERE ad_org_id = ?', [$ORG])->n;
            if ($balMid !== $balBefore + count($plan))
                throw new \RuntimeException('P1 POST-CHECK FAILED: acct_balance moved by '
                    . ($balMid - $balBefore) . ', expected ' . count($plan) . '. ABORT.');
            foreach ([11309, 11313, 21138] as $acct) {
                [$d] = $drift($acct);
                if (abs($d - $expAfter[$acct]) > 0.01)
                    throw new \RuntimeException("P1 POST-CHECK FAILED: $acct drift is " . $m($d)
                        . ', expected ' . $m($expAfter[$acct]) . '. ABORT.');
            }
            [$d12502after] = $drift(12502);
            if (abs($d12502after - $d12502) > 0.01)
                throw new \RuntimeException('P1 POST-CHECK FAILED: 12502 drift moved. ABORT.');
        }

        // ---- PHASE 2
        if (!$p2Applied) {
            $db->table('acct_gl')->whereIn('acct_gl_id', array_keys($GL))
               ->update(['debit' => 0, 'credit' => 0, 'updated' => $TAG, 'date_updated' => $STAMP]);
            $db->table('acct_balance')->whereIn('acct_balance_id', array_keys($BAL))
               ->update(['debit' => 0, 'credit' => 0, 'updated' => $TAG, 'date_updated' => $STAMP]);

            foreach ([['acct_gl', 'acct_gl_id', $GL], ['acct_balance', 'acct_balance_id', $BAL]] as [$t, $pk, $set]) {
                $rowIds = array_keys($set);
                $n = (int) $db->selectOne("SELECT COUNT(*) n FROM {$t} WHERE updated = ? AND debit = 0
                        AND credit = 0 AND {$pk} IN (" . implode(',', array_fill(0, count($rowIds), '?')) . ')',
                    array_merge([$TAG], $rowIds))->n;
                if ($n !== count($rowIds))
                    throw new \RuntimeException("P2 POST-CHECK FAILED: $n of " . count($rowIds)
                        . " {$t} rows zeroed and tagged. ABORT.");
            }
            foreach ($INVOICE as $id => $acct) {
                $r = $db->selectOne('SELECT debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$id]);
                if (abs(((float) $r->debit + (float) $r->credit) - $AMT) > 0.001)
                    throw new \RuntimeException("P2 POST-CHECK FAILED: invoice leg $id was modified. ABORT.");
            }
        }

        // ---- FINAL
        $glAfter = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                     FROM acct_gl WHERE ad_org_id = ?', [$ORG]);
        if ((int) $glAfter->n !== (int) $glBefore->n)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl row count changed. ABORT.');

        $grniEnd = $grni();
        if (abs($grniEnd - $EXP_GRNI_FINAL) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: GRNI variance is ' . $m($grniEnd)
                . ', expected ' . $m($EXP_GRNI_FINAL) . '. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK');
        $say('   PHASE 1  ' . ($p1Applied ? 'skipped — already applied'
             : '7 rows inserted (ids ' . implode(', ', $ids) . ')'));
        if (!$p1Applied)
            foreach ([11309, 11313, 21138] as $acct)
                $say(sprintf('            %-6s drift %14s  ->  %14s',
                     $acct, $m($before[$acct]), $m($expAfter[$acct])));
        $say('   PHASE 2  ' . ($p2Applied ? 'skipped — already applied' : '4 rows zeroed and tagged ' . $TAG));
        $say('   GRNI variance  ' . $m($grniStart) . '  ->  ' . $m($grniEnd));
        $say(' COMPLETE — GRNI vs Suspense reconciles on org ' . $ORG);
        $say($L);
        if (isset($cmd)) $cmd->info('GRNI21138+NIGR0006870 applied: variance ' . $m($grniEnd));
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
