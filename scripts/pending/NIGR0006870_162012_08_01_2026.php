<?php // NIGR0006870_162012_08_01_2026.php
// ============================================================================
// Complete Phase 4 of wrongful_variance_fix.php — restore BUILDMORE's invoice
// Org 162012 · mysql_secondary · 2026-08-01
//
//   NIGR0006870 (ref SCI# KOROST 2267, 2026-05-14, BUILDMORE CONSTRUCTION
//   DEPOT, subacct 3149) is a live 286.25 invoice. Its document number was also
//   reused by the Auto IGR for JR BOY SAND AND GRAVEL's 29,000, so when JR BOY's
//   transaction was cancelled the cancellation hit BUILDMORE's invoice too.
//
//   wrongful_variance_fix.php (kurukokok, commit 1664485, ran 2026-05-29
//   10:30:28) undid that. Phase 4 zeroed the wrongful legs on 21101 and 21138 —
//   the two that carry subacct 3149. It targeted "rows that landed on
//   BUILDMORE's sub=3149", and 11309/11313 never carry a subaccount at all
//   (11313 has 0 of 19,331), so the inventory legs were unreachable by that
//   filter and stayed live.
//
//   Result: the invoice stands on 21138 and 21101, cancelled on 11309 and
//   11313. GRNI vs Suspense compares 21138 against 11313, so it reports 286.25.
//
//   Zeroes the two remaining wrongful legs and their rollup rows, restoring the
//   invoice in full. Same operation and same UPDATE shape Phases 3 and 4 use,
//   stamped with this repair's own audit tag — Phase 4 ran 2026-05-29 under
//   'SYSTEM' and these rows are a separate write.
//
//     acct_gl        2387993  11309  sm 191  CR 286.25
//                    2387998  11313  sm 198  DR 286.25
//     acct_balance    927677  11309  sm 191  CR 286.25
//                     927682  11313  sm 198  DR 286.25
//
//   Confirmed by the author 2026-07-31: two legs were missed.
//
//   Direction derived, not asserted — zeroing these moves the GRNI variance by
//   exactly +286.25 from whatever it currently reads, so this runs safely before
//   or after GRNI21138 and before or after NJV0003843 is undone.
//
// WRITES  acct_gl · acct_balance — 4 UPDATEs. No INSERT, no DELETE, no files.
// ROLLBACK  NIGR0006870_162012_08_01_2026_rollback.php
// CASE      NIGR0006870_162012_08_01_2026.md
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $APPROVED = true;                       // <-- set true only with approval on record

    $POSTED  = '2026-08-01';                // posting date; audit stamp date
    $IMS     = null;                        // real IMS ticket number, or null
    $TAG     = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;

    $ORG     = 162012;
    $DOCNO   = 'NIGR0006870-CA';
    $DATE_GL = '2026-05-14';
    $AMT     = 286.25;

    // the wrongful legs, and the invoice legs they cancel
    $GL  = [2387993 => [11309, 191, 0.00, $AMT], 2387998 => [11313, 198, $AMT, 0.00]];
    $BAL = [927677  => [11309, 191, 0.00, $AMT], 927682  => [11313, 198, $AMT, 0.00]];

    // Phase 4's own rows — must already be zeroed, else this is not the state we audited
    $PHASE4 = [2387994, 2387997];

    // BUILDMORE's four invoice legs — asserted present and untouched
    $INVOICE = [2387960 => 21138, 2387961 => 11313, 2387962 => 11309, 2387963 => 21101];

    $GRNI_DELTA = 286.25;                    // effect of zeroing the 11313 leg

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 92);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    $grni = function () use ($db, $ORG) {
        $v = fn(int $a, string $e) => (float) $db->selectOne(
            "SELECT ROUND(IFNULL({$e},0),2) v FROM acct_balance WHERE ad_org_id = ? AND gl_acct_id = ?",
            [$ORG, $a])->v;
        $cd = 'SUM(credit)-SUM(debit)'; $dc = 'SUM(debit)-SUM(credit)';
        return round($v(21138, $cd) - ($v(11313, $dc) + $v(11302, $dc) + $v(11311, $dc) + $v(92005, $dc)), 2);
    };
    $drift = function (int $acct) use ($db, $ORG) {
        $b = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_balance
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        $g = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit-credit),0),2) v FROM acct_gl
                                      WHERE ad_org_id = ? AND gl_acct_id = ?', [$ORG, $acct])->v;
        return round($b - $g, 2);
    };

    $say($L);
    $say(' NIGR0006870 — complete Phase 4, restore BUILDMORE\'s invoice');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · org ' . $ORG . ' · tag ' . $TAG
         . ' · MODE: ' . ($APPROVED ? 'APPLY' : 'DRY-RUN'));
    $say($L);

    // ---------------- GATE 0 · replica only ----------------
    if ($APPROVED && stripos($schema, 'replica') === false)
        throw new \RuntimeException("GATE 0 FAILED: '$schema' is not a replica. Live execution "
            . 'requires the approved maker/checker procedure, not this script.');

    // ---------------- GATE 1 · the four wrongful rows are exactly as audited ----------------
    foreach ($GL as $id => [$acct, $sm, $dr, $cr]) {
        $r = $db->selectOne('SELECT gl_acct_id, doc_i_submod_id, date_gl, debit, credit, documentno,
                                    ad_org_id, gl_subacct_id, COALESCE(updated,\'\') upd
                               FROM acct_gl WHERE acct_gl_id = ?', [$id]);
        if (!$r) throw new \RuntimeException("GATE 1 FAILED: acct_gl $id not found. ABORT.");
        if ((int) $r->ad_org_id !== $ORG || (int) $r->gl_acct_id !== $acct
            || (int) $r->doc_i_submod_id !== $sm
            || substr((string) $r->date_gl, 0, 10) !== $DATE_GL
            || $r->gl_subacct_id !== null
            || abs((float) $r->debit - $dr) > 0.001 || abs((float) $r->credit - $cr) > 0.001)
            throw new \RuntimeException("GATE 1 FAILED: acct_gl $id is not the audited row "
                . "({$r->documentno}, {$r->gl_acct_id}, sm {$r->doc_i_submod_id}, "
                . $m($r->debit) . '/' . $m($r->credit) . '). ABORT.');
        if ($r->upd !== '')
            throw new \RuntimeException("GATE 1 FAILED: acct_gl $id already carries updated='{$r->upd}' — "
                . 'it has been touched since the audit. ABORT.');
        $say(sprintf('        acct_gl      %-8s %-6s sm %-4s dr %10s  cr %10s', $id, $acct, $sm, $m($dr), $m($cr)));
    }
    foreach ($BAL as $id => [$acct, $sm, $dr, $cr]) {
        $r = $db->selectOne('SELECT gl_acct_id, doc_i_submod_id, date_gl, debit, credit, ad_org_id,
                                    gl_subacct_id, COALESCE(updated,\'\') upd
                               FROM acct_balance WHERE acct_balance_id = ?', [$id]);
        if (!$r) throw new \RuntimeException("GATE 1 FAILED: acct_balance $id not found. ABORT.");
        if ((int) $r->ad_org_id !== $ORG || (int) $r->gl_acct_id !== $acct
            || (int) $r->doc_i_submod_id !== $sm
            || substr((string) $r->date_gl, 0, 10) !== $DATE_GL
            || $r->gl_subacct_id !== null
            || abs((float) $r->debit - $dr) > 0.001 || abs((float) $r->credit - $cr) > 0.001)
            throw new \RuntimeException("GATE 1 FAILED: acct_balance $id is not the audited row. ABORT.");
        if ($r->upd !== '')
            throw new \RuntimeException("GATE 1 FAILED: acct_balance $id already carries "
                . "updated='{$r->upd}'. ABORT.");
        $say(sprintf('        acct_balance %-8s %-6s sm %-4s dr %10s  cr %10s', $id, $acct, $sm, $m($dr), $m($cr)));
    }

    // ---------------- GATE 2 · Phase 4 has run and its rows are zeroed ----------------
    foreach ($PHASE4 as $id) {
        $r = $db->selectOne('SELECT debit, credit, COALESCE(updated,\'\') upd
                               FROM acct_gl WHERE acct_gl_id = ?', [$id]);
        if (!$r || (float) $r->debit != 0.0 || (float) $r->credit != 0.0 || $r->upd !== 'SYSTEM')
            throw new \RuntimeException("GATE 2 FAILED: acct_gl $id is not Phase 4's zeroed row. "
                . 'This script completes that repair and must not run without it. ABORT.');
    }

    // ---------------- GATE 3 · BUILDMORE's invoice is intact ----------------
    foreach ($INVOICE as $id => $acct) {
        $r = $db->selectOne('SELECT gl_acct_id, debit, credit, documentno
                               FROM acct_gl WHERE acct_gl_id = ?', [$id]);
        if (!$r || (int) $r->gl_acct_id !== $acct
            || abs(((float) $r->debit + (float) $r->credit) - $AMT) > 0.001)
            throw new \RuntimeException("GATE 3 FAILED: invoice leg acct_gl $id is not "
                . $m($AMT) . " on account $acct. ABORT.");
    }

    // ---------------- GATE 4 · rollup agrees with the journal ----------------
    foreach ([11309, 11313] as $acct) {
        $d = $drift($acct);
        // the 11309/11313 rollup gap belongs to GRNI21138, not to this repair
        if (abs($d) > 0.01 && abs(abs($d) - 85000.00) > 0.01)
            throw new \RuntimeException("GATE 4 FAILED: $acct drift is " . $m($d)
                . ', expected 0.00 or +/-85,000.00 (the GRNI21138 gap). ABORT.');
    }

    $grniBefore = $grni();
    $grniExpAfter = round($grniBefore + $GRNI_DELTA, 2);

    $say(' GATES  0 replica · 1 four wrongful rows · 2 Phase 4 zeroed · 3 invoice intact'
         . ' · 4 rollup ..... PASS');
    $say('');
    $say('   PLAN — 4 UPDATEs to 0.00, stamped ' . $TAG . '.');
    $say('   GRNI variance  ' . $m($grniBefore) . '  ->  ' . $m($grniExpAfter));
    $say('   BUILDMORE\'s invoice becomes live on all four accounts.');

    // ---------------- APPROVAL GUARD ----------------
    if (!$APPROVED) {
        $say('');
        $say(' DRY-RUN — zero database writes, zero files created.');
        $say(' APPROVAL REQUIRED before this runs.');
        $say($L);
        if (isset($cmd)) $cmd->info('NIGR0006870 DRY-RUN: 4 updates planned, no writes.');
        return;
    }

    // ---------------- APPLY ----------------
    $STAMP = $POSTED . ' 00:00:00';
    $glCountBefore = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl WHERE ad_org_id = ?', [$ORG])->n;

    $db->beginTransaction();
    try {
        $db->table('acct_gl')->whereIn('acct_gl_id', array_keys($GL))
           ->update(['debit' => 0, 'credit' => 0, 'updated' => $TAG, 'date_updated' => $STAMP]);
        $db->table('acct_balance')->whereIn('acct_balance_id', array_keys($BAL))
           ->update(['debit' => 0, 'credit' => 0, 'updated' => $TAG, 'date_updated' => $STAMP]);

        // ---------------- POST-CHECKS ----------------
        foreach (array_keys($GL) as $id) {
            $r = $db->selectOne('SELECT debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$id]);
            if ((float) $r->debit != 0.0 || (float) $r->credit != 0.0)
                throw new \RuntimeException("POST-CHECK FAILED: acct_gl $id not zeroed. ABORT.");
        }
        foreach (array_keys($BAL) as $id) {
            $r = $db->selectOne('SELECT debit, credit FROM acct_balance WHERE acct_balance_id = ?', [$id]);
            if ((float) $r->debit != 0.0 || (float) $r->credit != 0.0)
                throw new \RuntimeException("POST-CHECK FAILED: acct_balance $id not zeroed. ABORT.");
        }
        if ((int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl WHERE ad_org_id = ?', [$ORG])->n !== $glCountBefore)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl row count changed. ABORT.');

        // scoped to this script's own ids: a sibling script posted the same day shares $TAG
        foreach ([['acct_gl', 'acct_gl_id', $GL], ['acct_balance', 'acct_balance_id', $BAL]] as [$t, $pk, $set]) {
            $ids = array_keys($set);
            $n = (int) $db->selectOne("SELECT COUNT(*) n FROM {$t} WHERE updated = ? AND {$pk} IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$TAG], $ids))->n;
            if ($n !== count($ids))
                throw new \RuntimeException("POST-CHECK FAILED: $n of " . count($ids) . " $t rows carry $TAG. ABORT.");
        }

        foreach ($INVOICE as $id => $acct) {
            $r = $db->selectOne('SELECT debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$id]);
            if (abs(((float) $r->debit + (float) $r->credit) - $AMT) > 0.001)
                throw new \RuntimeException("POST-CHECK FAILED: invoice leg $id was modified. ABORT.");
        }

        $grniAfter = $grni();
        if (abs($grniAfter - $grniExpAfter) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: GRNI variance is ' . $m($grniAfter)
                . ', expected ' . $m($grniExpAfter) . '. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK  4 rows zeroed and tagged ' . $TAG
             . ' · acct_gl count unchanged · invoice legs untouched');
        $say('             GRNI variance  ' . $m($grniBefore) . '  ->  ' . $m($grniAfter));
        $say(' COMPLETE — BUILDMORE\'s invoice is live on 11309, 11313, 21138 and 21101.');
        $say('            Reprint Subaccount Ledger Details on 11309/11313 W/O Sub Acct to confirm.');
        $say($L);
        if (isset($cmd)) $cmd->info('NIGR0006870 Phase 4 completed: 4 rows zeroed.');
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
