<?php // ULP16000000712_162011_08_03_2026.php
// ============================================================================
// ULP payment posted one ledger line for five settled debts — Debt Aging
// Org 162011 · mysql_secondary · 2026-08-03
//
//   ULP16000000712 (2026-07-27, 25,602.80) settled five debts. The subledger
//   recorded all five correctly; the ledger received a single debit, the whole
//   25,602.80 charged to 21119.
//
//     account  subacct  partner                    settled     ledger got
//     21116    76       Social Security System    11,000.00          0.00
//     21117    158      PhilHealth                 5,500.00          0.00
//     21118    42       HDMF Pag-IBIG              3,600.00          0.00
//     21119    76       Social Security System     2,789.61     25,602.80
//     21120    42       HDMF Pag-IBIG              2,713.19          0.00
//                                                 25,602.80     25,602.80
//
//   Debt Aging Integrity therefore shows four accounts still owing amounts that
//   were paid, and 21119 over-charged by 22,813.19 — enough to put an asset
//   account into a credit balance of 18,593.56. All five read 0.00 on 15 July;
//   this one document created the whole variance.
//
//   Splits the single debit into the five the subledger already states. Amounts
//   are read from fin_l_debt_history, not derived. The cash leg is untouched and
//   the document still balances at 25,602.80.
//
//   Known defect: submodule 395 emits one ledger debit per payment rather than
//   one per debt line. This repairs the instance, not the cause.
//
// WRITES     acct_gl — 1 UPDATE, 4 INSERTs · acct_balance — 1 UPDATE, 4 INSERTs
// ROLLBACK   ULP16000000712_162011_08_03_2026_rollback.php
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $POSTED   = date('Y-m-d');               // stamped with the day it actually runs
    $IMS      = null;
    $TAG      = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;
    $STAMP    = $POSTED . ' 00:00:00';

    $ORG      = 162011;
    $DOCNO    = 'ULP16000000712';
    $DATE_GL  = '2026-07-27';
    $TRANS    = '2026-07-27 17:40:13';
    $SUBMOD   = 395;
    $ACCTDOC  = 103841791;
    $REFNO    = 2874232;
    $TOTAL    = 25602.80;

    $GL_ROW   = 2410599;                     // the collapsed 21119 debit
    $CASH_ROW = 2410600;                     // asserted untouched
    $BAL_ROW  = 942992;                      // its rollup counterpart

    // account => [subacct, correct debit] — as recorded in fin_l_debt_history
    $SPLIT = [
        21116 => [76,  11000.00],
        21117 => [158,  5500.00],
        21118 => [42,   3600.00],
        21119 => [76,   2789.61],            // stays on the existing row
        21120 => [42,   2713.19],
    ];
    $KEEP = 21119;

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 96);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    // Debt Aging Integrity variance for one account: subledger minus ledger
    $variance = function (int $acct) use ($db, $ORG) {
        $sub = (float) $db->selectOne(
            'SELECT ROUND(IFNULL(SUM(IF(t.normal_balance = 1,
                        IF(d.direction = \'I\', h.amount, 0),
                        IF(d.direction = \'O\', h.amount, 0))),0),2) v
               FROM fin_l_debt d
               JOIN fin_l_debt_history h ON h.fin_l_debt_id = d.fin_l_debt_id
               JOIN s_bpartner sb ON sb.s_bpartner_id = d.s_bpartner_id
               JOIN gl_acct a ON a.gl_acct_id = d.gl_acct_id
               JOIN gl_acct_type t ON t.gl_acct_type_id = a.gl_acct_type_id
              WHERE d.gl_acct_id = ? AND d.ad_org_id = ?
                AND d.status = \'PR\' AND h.status = \'PR\' AND h.date_gl <= CURDATE()',
            [$acct, $ORG])->v;
        $led = (float) $db->selectOne(
            'SELECT ROUND(IFNULL(SUM(b.debit - b.credit) * MAX(t.normal_balance),0),2) v
               FROM acct_balance b
               JOIN gl_acct a ON a.gl_acct_id = b.gl_acct_id
               JOIN gl_acct_type t ON t.gl_acct_type_id = a.gl_acct_type_id
              WHERE b.gl_acct_id = ? AND b.ad_org_id = ? AND b.date_gl <= CURDATE()',
            [$acct, $ORG])->v;
        return round($sub - $led, 2);
    };

    $say($L);
    $say(' ULP16000000712 — one ledger line for five settled debts');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · org ' . $ORG . ' · tag ' . $TAG
         . ' · COMMIT');
    $say($L);

    // ---------------- GATE 1 · the collapsed debit is exactly as audited ----------------
    $g = $db->selectOne('SELECT gl_acct_id, documentno, date_gl, debit, credit, gl_subacct_id sub,
                                doc_i_submod_id sm, ad_org_id, acct_doc_id,
                                COALESCE(updated,\'\') upd
                           FROM acct_gl WHERE acct_gl_id = ?', [$GL_ROW]);
    if (!$g) throw new \RuntimeException("GATE 1 FAILED: acct_gl $GL_ROW not found. ABORT.");

    // ---------------- STATE · already applied? ----------------
    // Detect its own prior application and skip rather than abort, so a re-deploy
    // or a second pass on the same environment is a no-op instead of a failure.
    if (preg_match('/^(SCRIPT-WEB-|#IMS-|IMS_SCRIPT_WEB-)/', $g->upd)
        && abs((float) $g->debit - $SPLIT[$KEEP][1]) <= 0.001) {
        $lines = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl
                                        WHERE documentno = ? AND ad_org_id = ? AND acct_gl_id <> ?
                                          AND gl_acct_id IN (?,?,?,?)',
                                      [$DOCNO, $ORG, $GL_ROW, 21116, 21117, 21118, 21120])->n;
        if ($lines !== count($SPLIT) - 1)
            throw new \RuntimeException("STATE UNCLEAR: $GL_ROW is split but $lines of "
                . (count($SPLIT) - 1) . ' ledger lines exist. A partial run needs rolling back. ABORT.');
        $open = [];
        foreach ($SPLIT as $acct => $_) if (abs($variance($acct)) > 0.01) $open[] = $acct;
        if ($open !== [])
            throw new \RuntimeException('STATE UNCLEAR: already applied but ' . implode(', ', $open)
                . ' still vary. Investigate before re-running. ABORT.');
        $say('        acct_gl ' . $GL_ROW . ' already split, tagged ' . $g->upd);
        $say('');
        $say(' NOTHING TO DO — already applied; all ' . count($SPLIT) . ' accounts reconcile.');
        $say($L);
        if (isset($cmd)) $cmd->info('ULP16000000712: nothing to do, already applied.');
        return;
    }

    if ((int) $g->gl_acct_id !== $KEEP || $g->documentno !== $DOCNO
        || substr((string) $g->date_gl, 0, 10) !== $DATE_GL || (int) $g->ad_org_id !== $ORG
        || (int) $g->sm !== $SUBMOD || (int) $g->sub !== $SPLIT[$KEEP][0]
        || abs((float) $g->debit - $TOTAL) > 0.001 || abs((float) $g->credit) > 0.001)
        throw new \RuntimeException("GATE 1 FAILED: acct_gl $GL_ROW is not the audited row "
            . "({$g->documentno}, acct {$g->gl_acct_id}, " . $m($g->debit) . '). ABORT.');
    if ($g->upd !== '')
        throw new \RuntimeException("GATE 1 FAILED: acct_gl $GL_ROW carries updated='{$g->upd}'. ABORT.");

    // ---------------- GATE 2 · the cash leg is intact ----------------
    $c = $db->selectOne('SELECT gl_acct_id, debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$CASH_ROW]);
    if (!$c || abs((float) $c->credit - $TOTAL) > 0.001 || abs((float) $c->debit) > 0.001)
        throw new \RuntimeException("GATE 2 FAILED: cash leg $CASH_ROW is not a credit of "
            . $m($TOTAL) . '. ABORT.');

    // ---------------- GATE 3 · the subledger states this exact split ----------------
    $rows = $db->select('SELECT d.gl_acct_id acct, ROUND(SUM(ABS(h.amount)),2) amt
                           FROM fin_l_debt_history h
                           JOIN fin_l_debt d ON d.fin_l_debt_id = h.fin_l_debt_id
                          WHERE h.documentno = ? GROUP BY d.gl_acct_id', [$DOCNO]);
    if (count($rows) !== count($SPLIT))
        throw new \RuntimeException('GATE 3 FAILED: subledger settled ' . count($rows)
            . ' account(s), expected ' . count($SPLIT) . '. ABORT.');
    $sum = 0.0;
    foreach ($rows as $r) {
        $acct = (int) $r->acct;
        if (!isset($SPLIT[$acct]))
            throw new \RuntimeException("GATE 3 FAILED: subledger settled account $acct, "
                . 'which is not in the audited split. ABORT.');
        if (abs((float) $r->amt - $SPLIT[$acct][1]) > 0.001)
            throw new \RuntimeException("GATE 3 FAILED: subledger settled " . $m($r->amt)
                . " on $acct, audited " . $m($SPLIT[$acct][1]) . '. ABORT.');
        $sum += (float) $r->amt;
    }
    if (abs($sum - $TOTAL) > 0.01)
        throw new \RuntimeException('GATE 3 FAILED: split totals ' . $m($sum) . ', expected '
            . $m($TOTAL) . '. ABORT.');

    // ---------------- GATE 4 · no ledger line exists yet for the other four ----------------
    foreach (array_keys($SPLIT) as $acct) {
        if ($acct === $KEEP) continue;
        $n = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl
                                    WHERE documentno = ? AND gl_acct_id = ? AND ad_org_id = ?',
                                  [$DOCNO, $acct, $ORG])->n;
        if ($n !== 0)
            throw new \RuntimeException("GATE 4 FAILED: $n acct_gl row(s) already exist for $acct "
                . "on $DOCNO. ABORT.");
    }

    // ---------------- GATE 5 · the rollup mirrors the collapsed debit ----------------
    $b = $db->selectOne('SELECT gl_acct_id, debit, credit, gl_subacct_id sub, doc_i_submod_id sm,
                                date_gl, ad_org_id, COALESCE(updated,\'\') upd
                           FROM acct_balance WHERE acct_balance_id = ?', [$BAL_ROW]);
    if (!$b || (int) $b->gl_acct_id !== $KEEP || abs((float) $b->debit - $TOTAL) > 0.001
        || (int) $b->sm !== $SUBMOD || (int) $b->ad_org_id !== $ORG
        || substr((string) $b->date_gl, 0, 10) !== $DATE_GL || $b->upd !== '')
        throw new \RuntimeException("GATE 5 FAILED: acct_balance $BAL_ROW is not the audited row. ABORT.");

    // ---------------- GATE 6 · the report reads what we audited ----------------
    $before = [];
    $expBefore = [21116 => -11000.00, 21117 => -5500.00, 21118 => -3600.00,
                  21119 => 22813.19,  21120 => -2713.19];
    foreach ($SPLIT as $acct => $_) {
        $before[$acct] = $variance($acct);
        if (abs($before[$acct] - $expBefore[$acct]) > 0.01)
            throw new \RuntimeException("GATE 6 FAILED: $acct variance is " . $m($before[$acct])
                . ', expected ' . $m($expBefore[$acct]) . '. ABORT.');
    }

    $say('        acct_gl ' . $GL_ROW . '  21119 sub 76  DR ' . $m($TOTAL) . '  (collapsed)');
    $say('        cash leg ' . $CASH_ROW . '  11102  CR ' . $m($TOTAL) . '  (untouched)');
    $say(' GATES  1 collapsed debit · 2 cash intact · 3 subledger split'
         . ' · 4 no duplicate lines · 5 rollup · 6 report ..... PASS');
    $say('');
    $say('   PLAN — split the single debit into the five the subledger states');
    $say('   acct   subacct        now            ->  after        variance');
    foreach ($SPLIT as $acct => [$sub, $amt]) {
        $now = ($acct === $KEEP) ? $TOTAL : 0.00;
        $say(sprintf('   %-6s %-8s %14s  ->  %12s   %14s  ->  0.00',
             $acct, $sub, $m($now), $m($amt), $m($before[$acct])));
    }
    $say('   document total stays ' . $m($TOTAL) . ' DR against ' . $m($TOTAL) . ' CR cash');

    // ---------------- APPLY ----------------
    $db->beginTransaction();
    try {
        // the retained 21119 share
        $db->update('UPDATE acct_gl SET debit = ?, updated = ?, date_updated = ?
                      WHERE acct_gl_id = ?', [$SPLIT[$KEEP][1], $TAG, $STAMP, $GL_ROW]);
        $db->update('UPDATE acct_balance SET debit = ?, updated = ?, date_updated = ?
                      WHERE acct_balance_id = ?', [$SPLIT[$KEEP][1], $TAG, $STAMP, $BAL_ROW]);

        // the four missing ledger lines and their rollup rows
        $newGl = []; $newBal = [];
        foreach ($SPLIT as $acct => [$sub, $amt]) {
            if ($acct === $KEEP) continue;
            $db->insert('INSERT INTO acct_gl
                (ad_submodule_id, ad_org_id, gl_acct_id, documentno, date_gl, date_trans,
                 debit, credit, created, date_created, updated, date_updated, is_active,
                 gl_subacct_id, acct_doc_id, acct_docline_id, gl_subgroup_id, term_days,
                 doc_i_submod_id, doc_t_reference_number_id)
                VALUES (NULL, ?, ?, ?, DATE(?), ?, ?, 0, NULL, NULL, ?, ?, NULL,
                        ?, ?, NULL, NULL, NULL, ?, ?)',
                [$ORG, $acct, $DOCNO, $DATE_GL, $TRANS, $amt, $TAG, $STAMP,
                 $sub, $ACCTDOC, $SUBMOD, $REFNO]);
            $newGl[] = (int) $db->selectOne('SELECT LAST_INSERT_ID() id')->id;

            $db->insert('INSERT INTO acct_balance
                (gl_acct_id, date_gl, ad_org_id, created, date_created, updated, date_updated,
                 is_active, debit, credit, gl_subacct_id, gl_subgroup_id, term_days, doc_i_submod_id)
                VALUES (?, DATE(?), ?, NULL, NULL, ?, ?, NULL, ?, 0, ?, NULL, NULL, ?)',
                [$acct, $DATE_GL, $ORG, $TAG, $STAMP, $amt, $sub, $SUBMOD]);
            $newBal[] = (int) $db->selectOne('SELECT LAST_INSERT_ID() id')->id;
        }

        // ---------------- POST-CHECKS ----------------
        $docDr = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(debit),0),2) v FROM acct_gl
                                          WHERE documentno = ? AND ad_org_id = ?', [$DOCNO, $ORG])->v;
        $docCr = (float) $db->selectOne('SELECT ROUND(IFNULL(SUM(credit),0),2) v FROM acct_gl
                                          WHERE documentno = ? AND ad_org_id = ?', [$DOCNO, $ORG])->v;
        if (abs($docDr - $TOTAL) > 0.01 || abs($docCr - $TOTAL) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: document is DR ' . $m($docDr) . ' / CR '
                . $m($docCr) . ', expected ' . $m($TOTAL) . ' both sides. ABORT.');

        $cash = $db->selectOne('SELECT debit, credit FROM acct_gl WHERE acct_gl_id = ?', [$CASH_ROW]);
        if (abs((float) $cash->credit - $TOTAL) > 0.001 || abs((float) $cash->debit) > 0.001)
            throw new \RuntimeException('POST-CHECK FAILED: cash leg moved. ABORT.');

        // Scoped to the rows this script wrote. A whole-org row count would also
        // register entries other staff post while this runs, and abort on their work.
        $EXP_NEW = $TOTAL - $SPLIT[$KEEP][1];

        $w = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit),0),2) v, SUM(updated = ?) tagged
                               FROM acct_gl WHERE acct_gl_id IN (' . implode(',', $newGl) . ')', [$TAG]);
        if ((int) $w->n !== 4 || (int) $w->tagged !== 4)
            throw new \RuntimeException('POST-CHECK FAILED: ' . $w->n . ' of 4 inserted acct_gl rows readable, '
                . $w->tagged . ' tagged. ABORT.');
        if (abs((float) $w->v - $EXP_NEW) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: inserted acct_gl debits total ' . $m($w->v)
                . ', expected ' . $m($EXP_NEW) . '. ABORT.');

        $wb = $db->selectOne('SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit),0),2) v, SUM(updated = ?) tagged
                                FROM acct_balance WHERE acct_balance_id IN (' . implode(',', $newBal) . ')', [$TAG]);
        if ((int) $wb->n !== 4 || (int) $wb->tagged !== 4)
            throw new \RuntimeException('POST-CHECK FAILED: ' . $wb->n . ' of 4 inserted acct_balance rows readable, '
                . $wb->tagged . ' tagged. ABORT.');
        if (abs((float) $wb->v - $EXP_NEW) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: inserted acct_balance debits total ' . $m($wb->v)
                . ', expected ' . $m($EXP_NEW) . '. ABORT.');

        $docLines = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl
                                           WHERE documentno = ? AND ad_org_id = ? AND debit > 0',
                                         [$DOCNO, $ORG])->n;
        if ($docLines !== 5)
            throw new \RuntimeException("POST-CHECK FAILED: document carries $docLines debit lines, expected 5. ABORT.");

        $after = [];
        foreach ($SPLIT as $acct => $_) {
            $after[$acct] = $variance($acct);
            if (abs($after[$acct]) > 0.01)
                throw new \RuntimeException("POST-CHECK FAILED: $acct variance is "
                    . $m($after[$acct]) . ', expected 0.00. ABORT.');
        }

        $db->commit();

        $say('');
        $say(' POST-CHECK  document DR ' . $m($docDr) . ' / CR ' . $m($docCr)
             . ' · cash untouched · acct_gl +4 · acct_balance +4');
        $say('             acct_gl ids     ' . implode(', ', $newGl));
        $say('             acct_balance    ' . implode(', ', $newBal));
        foreach ($SPLIT as $acct => $_)
            $say(sprintf('             %-6s variance %14s  ->  %8s', $acct,
                 $m($before[$acct]), $m($after[$acct])));
        $say(' COMPLETE — reprint Debt Aging Integrity for org ' . $ORG);
        $say($L);
        if (isset($cmd)) $cmd->info('ULP16000000712 split: 5 accounts now reconcile.');
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
