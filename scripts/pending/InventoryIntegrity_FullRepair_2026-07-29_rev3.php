<?php // InventoryIntegrity_FullRepair_2026-07-29_rev3.php
// ============================================================================
// STOCKCARD CUMULATIVE REBUILD — RP Tan C                rev 3 · 2026-07-29
// Self-contained. No manifest, no companion file, no hard-coded row IDs.
// ============================================================================
//
// WHAT IT DOES
//   Per SKU, in chronological order, restores the invariant
//       cumamt = running SUM(amt)      cumqty = running SUM(qty)
//   rebuilding only from each SKU's FIRST broken row onward. Earlier rows
//   satisfy the invariant already and are never touched.
//
// HOW THE POPULATION IS DETERMINED
//   Discovered at runtime by scanning every Tan C row for a violation of
//   cumamt(n) = cumamt(n-1) + amt(n). Rev 2 read this from a JSON manifest
//   holding fixed row IDs; those IDs are not stable across replica syncs
//   (ID 620673 held SKU 20837 before one sync and SKU 8527 after), so
//   discovery is both safer and removes the second file.
//   The expected result is asserted, not assumed: 64 SKUs / 4,418 rows.
//
// SCOPE   orgs 162010, 162011, 162012, 162016, 162017
//         all 64 SKUs, including the six with negative closing quantity
//         (226, 1712, 3906, 19233, 19344, 19719). SKU 3906 is IN SCOPE.
//
// WRITES  nvt_l_stockcard_mac only — cumamt, cumqty, updated, date_updated.
//         One UPDATE statement. No INSERT, no DELETE.
//         Never written, asserted before commit: qty · amt · documentno
//         · acct_gl · acct_balance · fin_l_debt · locatorqty · source documents
//
// VERIFIED POSITION (replica)
//   before   As Of 3,705,316.37 · acct_balance 4,346,916.30 · variance -641,599.93
//   after    As Of 4,352,065.71 · acct_balance 4,346,916.30 · variance    +5,149.41
//   A residual is CORRECT. Zero would mean the rebuild overreached.
//   The +5,149.41 is 2,774.02 (a Project Closure row missing from acct_balance,
//   separate WIP repair) plus 2,375.39 (netting artefact, no single cause,
//   present since 2015, unaffected by this repair).
//
// ORDER   Run BEFORE the WIP/acct_balance repair. GATE 2 asserts acct_balance
//         == 4,346,916.30; the WIP repair moves it to 4,349,690.32.
//         The GRNI repair nets to zero here and does not interfere.
//
// APPROVAL  Resets the moving-average cost used to value FUTURE issues.
//         30 SKUs move >1%, several 3-4x (SKU 307: 94.76 -> 350.76). Posted
//         amounts, documents and the GL do not change. 94.4% of past
//         consumption was priced from the broken average; those stay as posted.
//
// LIMITATION  26 of the rebuilt rows carry amt values that are themselves wrong
//         (supplier-return GAIN rows, 8,583.66, 13 SKUs). This script sums amt
//         faithfully; it neither creates nor corrects that. Separate case.
//
// DO NOT DEPLOY the uncompiled ProductAvgCostUpdateService.java change — it
//         adds an organisation filter to a globally-accumulated ledger, the
//         same defect class PHP commit 31ca076 removed on 2026-06-27.
//
// ROLLBACK  InventoryIntegrity_FullRepair_ROLLBACK_2026-07-29_rev3.php
// ============================================================================

return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $APPROVED = false;                       // <-- set true only with Accounting approval on record

    $POSTED   = '2026-07-28';                // audit stamp date = posting date
    $IMS      = null;                        // real IMS ticket number, or null
    $TAG      = ($IMS !== null && $IMS !== '') ? 'IMS-' . $POSTED : 'SCRIPT-WEB-' . $POSTED;

    $ORGS     = [162010, 162011, 162012, 162016, 162017];
    $TOL_AMT  = 0.005;
    $TOL_QTY  = 0.0005;
    $BASELINE = '2026-06-25';                // last verified root break
    $SNAPDIR  = __DIR__;

    // asserted, never used as input
    $EXP_SKUS          = 64;
    $EXP_ROWS          = 4418;
    $EXP_SUMAMT_SCOPED = 4333266.91;
    $EXP_GL_ASOF       = 4346916.30;
    $EXP_ASOF_AFTER    = 4352065.71;
    $EXP_VARIANCE      = 5149.41;

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 92);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $orgIn  = implode(',', $ORGS);
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    $say($L);
    $say(' INVENTORY INTEGRITY — stockcard cumulative rebuild (rev 3, self-contained)');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · tag ' . $TAG
         . ' · MODE: ' . ($APPROVED ? 'APPLY' : 'DRY-RUN'));
    $say($L);

    // reusable break scanner: returns rows violating the invariant
    $scan = function (string $where) use ($db, $orgIn) {
        return $db->select("
            SELECT sku, id, date_gl, ba, bq FROM (
              SELECT @ba := IF(@ps=r.nvt_i_sku_id, ROUND(IFNULL(r.cumamt,0)-(@pa+IFNULL(r.amt,0)),2),0) ba,
                     @bq := IF(@ps=r.nvt_i_sku_id, ROUND(IFNULL(r.cumqty,0)-(@pq+IFNULL(r.qty,0)),4),0) bq,
                     r.nvt_i_sku_id sku, r.nvt_l_stockcard_mac_id id, r.date_gl,
                     @pa := IFNULL(r.cumamt,0) x1, @pq := IFNULL(r.cumqty,0) x2, @ps := r.nvt_i_sku_id x3
                FROM (SELECT nvt_i_sku_id, date_gl, nvt_l_stockcard_mac_id, qty, amt, cumqty, cumamt
                        FROM nvt_l_stockcard_mac WHERE ad_org_id IN ($orgIn)
                       ORDER BY nvt_i_sku_id, date_gl, nvt_l_stockcard_mac_id) r
                CROSS JOIN (SELECT @ps:=-1, @pa:=0, @pq:=0) i) z
            WHERE (ABS(ba) > 0.004 OR ABS(bq) > 0.0004) $where");
    };

    // ---------------- GATE 0 · replica only ----------------
    if ($APPROVED && stripos($schema, 'replica') === false)
        throw new \RuntimeException("GATE 0 FAILED: '$schema' is not a replica. Live execution "
            . 'requires the approved maker/checker procedure, not this script.');

    // ---------------- GATE 1 · pre-state ----------------
    $catScope = "nvt_i_sku_id IN (SELECT DISTINCT k.nvt_i_sku_id FROM nvt_i_sku k
                   JOIN nvt_i_product ip ON ip.nvt_i_product_id = k.nvt_i_product_id
                   JOIN nvt_i_product_group g ON g.nvt_i_product_group_id = ip.nvt_i_productgroup_id
                   JOIN nvt_i_product_cat c ON c.nvt_i_product_cat_id = g.nvt_i_product_cat_id
                  WHERE c.ad_org_id = 162010)";
    $sumAmt = fn() => (float) $db->selectOne(
        "SELECT ROUND(IFNULL(SUM(amt),0),2) v FROM nvt_l_stockcard_mac
          WHERE ad_org_id IN ($orgIn) AND date_gl <= '$POSTED' AND $catScope")->v;
    $glAsOf = fn() => (float) $db->selectOne(
        "SELECT ROUND(IFNULL(SUM(IF(DATE(date_gl) <= '$POSTED', debit-credit, 0)),0),2) v
           FROM acct_balance WHERE ad_org_id IN ($orgIn)
            AND gl_acct_id IN (11302,11304,11309,11313)")->v;
    $reportAsOf = fn() => (float) $db->selectOne("
        SELECT ROUND(IFNULL(SUM(en.cumamt),0),2) v
          FROM (SELECT DISTINCT k.nvt_i_sku_id sid FROM nvt_i_sku k
                  JOIN nvt_i_product ip ON ip.nvt_i_product_id = k.nvt_i_product_id
                  JOIN nvt_i_product_group g ON g.nvt_i_product_group_id = ip.nvt_i_productgroup_id
                  JOIN nvt_i_product_cat c ON c.nvt_i_product_cat_id = g.nvt_i_product_cat_id
                 WHERE c.ad_org_id = 162010) s
          JOIN nvt_l_stockcard_mac en ON en.nvt_l_stockcard_mac_id = (
               SELECT x.nvt_l_stockcard_mac_id FROM nvt_l_stockcard_mac x
                WHERE x.date_gl <= '$POSTED' AND x.nvt_i_sku_id = s.sid
                  AND x.ad_org_id IN ($orgIn)
                ORDER BY x.date_gl DESC, x.nvt_l_stockcard_mac_id DESC LIMIT 1)")->v;

    $amt0 = $sumAmt(); $gl0 = $glAsOf();
    if (abs($amt0 - $EXP_SUMAMT_SCOPED) > 0.01)
        throw new \RuntimeException('GATE 1 FAILED: SUM(amt) is ' . $m($amt0) . ', expected '
            . $m($EXP_SUMAMT_SCOPED) . ' — source amounts moved since the audit. ABORT.');
    if (abs($gl0 - $EXP_GL_ASOF) > 0.01)
        throw new \RuntimeException('GATE 1 FAILED: acct_balance is ' . $m($gl0) . ', expected '
            . $m($EXP_GL_ASOF) . '. This repair must run BEFORE the WIP/acct_balance repair, which '
            . 'changes the comparison balance. Re-baseline deliberately if that has already run. ABORT.');

    // ---------------- GATE 2 · no root break after the verified live fixes ----------------
    $late = count($scan("AND date_gl > '$BASELINE'"));
    if ($late > 0)
        throw new \RuntimeException("GATE 2 FAILED: $late root break(s) after $BASELINE — an ACTIVE "
            . 'code defect is producing corruption. Do not backfill until it is fixed.');

    // ---------------- GATE 3 · discover the population ----------------
    $firstBreak = [];
    foreach ($scan('') as $b) {
        $k = (int) $b->sku;
        if (!isset($firstBreak[$k]) || [$b->date_gl, (int) $b->id] < $firstBreak[$k])
            $firstBreak[$k] = [$b->date_gl, (int) $b->id];
    }
    ksort($firstBreak);
    if (count($firstBreak) !== $EXP_SKUS)
        throw new \RuntimeException('GATE 3 FAILED: discovered ' . count($firstBreak)
            . " broken SKUs, expected $EXP_SKUS. The population has changed since the audit — "
            . 're-review before repairing. ABORT.');

    $say(' GATES  0 replica · 1 pre-state · 2 no break after ' . $BASELINE
         . ' · 3 population ' . count($firstBreak) . ' SKUs ..... PASS');
    $say('        SUM(amt) ' . $m($amt0) . ' · acct_balance ' . $m($gl0)
         . ' · As Of ' . $m($reportAsOf()));

    // ---------------- BUILD THE PLAN ----------------
    $plan = []; $snapshot = []; $examined = 0;
    foreach ($firstBreak as $sku => [, $bId]) {              // [date, id] — only the id is needed
        $rows = $db->select("SELECT nvt_l_stockcard_mac_id id, date_gl, documentno, doc_i_submod_id,
                                    qty, amt, cumqty, cumamt, created, updated, date_updated
                               FROM nvt_l_stockcard_mac
                              WHERE nvt_i_sku_id = ? AND ad_org_id IN ($orgIn)
                              ORDER BY date_gl, nvt_l_stockcard_mac_id", [$sku]);
        $examined += count($rows);

        $idx = null;
        foreach ($rows as $i => $r) { if ((int) $r->id === $bId) { $idx = $i; break; } }
        if ($idx === null)   throw new \RuntimeException("SKU $sku: break row $bId vanished mid-run. ABORT.");
        if ($idx === 0)      throw new \RuntimeException("SKU $sku: first break is the first row; no "
                                 . 'valid anchor. Requires an accounting decision. ABORT.');

        $anchor = $rows[$idx - 1];
        if ($anchor->cumamt === null || $anchor->cumqty === null)
            throw new \RuntimeException("SKU $sku: anchor row {$anchor->id} has NULL cumulative "
                . 'values. Requires an accounting decision. ABORT.');
        $runAmt = (float) $anchor->cumamt;
        $runQty = (float) $anchor->cumqty;

        $skuPlan = [];
        for ($i = $idx; $i < count($rows); $i++) {
            $r = $rows[$i];
            $runAmt = round($runAmt + (float) $r->amt, 2);
            $runQty = round($runQty + (float) $r->qty, 4);
            $curAmt = $r->cumamt === null ? null : round((float) $r->cumamt, 2);
            $curQty = $r->cumqty === null ? null : round((float) $r->cumqty, 4);
            if ($curAmt !== null && abs($curAmt - $runAmt) <= $TOL_AMT
                && $curQty !== null && abs($curQty - $runQty) <= $TOL_QTY) continue;
            $skuPlan[] = [
                'id' => (int) $r->id, 'sku' => $sku, 'date_gl' => $r->date_gl,
                'documentno' => $r->documentno, 'qty' => $r->qty, 'amt' => $r->amt,
                'old_cumamt' => $r->cumamt, 'new_cumamt' => $runAmt,
                'old_cumqty' => $r->cumqty, 'new_cumqty' => $runQty,
                'old_updated' => $r->updated, 'old_date_updated' => $r->date_updated,
            ];
        }
        if (!$skuPlan) continue;                       // idempotent
        $plan = array_merge($plan, $skuPlan);
        $snapshot[$sku] = ['anchor_id' => (int) $anchor->id, 'rows' => $skuPlan];
    }

    $say(' PLAN   ' . count($firstBreak) . ' SKUs · ' . number_format($examined)
         . ' rows examined · ' . number_format(count($plan)) . ' rows to rewrite');

    if (!$plan) {
        $say(''); $say(' NOTHING TO DO — the invariant already holds. No snapshot, no transaction.');
        $say($L);
        if (isset($cmd)) $cmd->info('Inventory rev3: NOTHING TO DO.');
        return;
    }
    if (count($plan) !== $EXP_ROWS)
        throw new \RuntimeException('GATE 3 FAILED: ' . count($plan) . " rows planned, expected "
            . "$EXP_ROWS. Population changed since the audit — re-review. ABORT.");

    $say('');
    $say('   row id     sku     date_gl     document          old_cumamt     new_cumamt        delta');
    foreach (array_slice($plan, 0, 8) as $p)
        $say(sprintf('   %-9s %-7s %-11s %-17s %13s %14s %12s',
            $p['id'], $p['sku'], $p['date_gl'], substr((string) $p['documentno'], 0, 17),
            $p['old_cumamt'] === null ? 'NULL' : $m($p['old_cumamt']), $m($p['new_cumamt']),
            $m($p['new_cumamt'] - ($p['old_cumamt'] === null ? 0 : (float) $p['old_cumamt']))));
    $say('   ... ' . number_format(count($plan) - 8) . ' more');

    // ---------------- APPROVAL GUARD ----------------
    if (!$APPROVED) {
        $say('');
        $say(' DRY-RUN — zero database writes, zero files created. The snapshot is written only');
        $say(' on an approved run. Expected after apply: As Of ' . $m($EXP_ASOF_AFTER)
             . ' · variance ' . $m($EXP_VARIANCE));
        $say(' ACCOUNTING APPROVAL REQUIRED — covers all 64 SKUs, 3906 included.');
        $say($L);
        if (isset($cmd)) $cmd->info('Inventory rev3 DRY-RUN: ' . count($plan) . ' rows, no writes.');
        return;
    }

    // ---------------- SNAPSHOT · approved runs only ----------------
    $snapFile = $SNAPDIR . '/InventoryIntegrity_rev3_snapshot_' . date('Ymd_His') . '.json';
    if (@file_put_contents($snapFile, json_encode([
            'script' => 'InventoryIntegrity_FullRepair_2026-07-29_rev3', 'revision' => 3,
            'run' => $RUN, 'tag' => $TAG, 'posted' => $POSTED, 'orgs' => $ORGS,
            'captures_date_updated' => true,
            'before' => ['sum_amt' => $amt0, 'acct_balance' => $gl0],
            'skus' => $snapshot,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false)
        throw new \RuntimeException('ABORT: snapshot could not be written. No transaction opened.');
    @chmod($snapFile, 0444);
    if (!is_readable($snapFile) || filesize($snapFile) === 0)
        throw new \RuntimeException('ABORT: snapshot missing or empty after write. No transaction opened.');
    $say(''); $say(' SNAPSHOT ' . $snapFile . ' (' . number_format((int) filesize($snapFile)) . ' b) verified');

    // ---------------- APPLY ----------------
    $db->beginTransaction();
    try {
        $n = 0;
        foreach ($plan as $p) {
            $cur = $db->selectOne('SELECT cumamt, cumqty, qty, amt FROM nvt_l_stockcard_mac
                                    WHERE nvt_l_stockcard_mac_id = ?', [$p['id']]);
            if (!$cur) throw new \RuntimeException("row {$p['id']} vanished mid-run. ABORT.");
            $okAmt = ($cur->cumamt === null && $p['old_cumamt'] === null)
                  || ($cur->cumamt !== null && $p['old_cumamt'] !== null
                      && abs((float) $cur->cumamt - (float) $p['old_cumamt']) <= $TOL_AMT);
            $okQty = ($cur->cumqty === null && $p['old_cumqty'] === null)
                  || ($cur->cumqty !== null && $p['old_cumqty'] !== null
                      && abs((float) $cur->cumqty - (float) $p['old_cumqty']) <= $TOL_QTY);
            if (!$okAmt || !$okQty)
                throw new \RuntimeException("row {$p['id']}: cumulative values changed since planning. ABORT.");
            if (abs((float) $cur->qty - (float) $p['qty']) > $TOL_QTY
                || abs((float) $cur->amt - (float) $p['amt']) > $TOL_AMT)
                throw new \RuntimeException("row {$p['id']}: qty/amt changed since planning. ABORT.");

            $db->update('UPDATE nvt_l_stockcard_mac
                            SET cumamt = ?, cumqty = ?, updated = ?, date_updated = ?
                          WHERE nvt_l_stockcard_mac_id = ?',
                        [$p['new_cumamt'], $p['new_cumqty'], $TAG, $POSTED . ' 00:00:00', $p['id']]);
            $n++;
        }
        if ($n !== count($plan)) throw new \RuntimeException("wrote $n, planned " . count($plan) . '. ABORT.');

        // POST-CHECKS
        $amt1 = $sumAmt();
        if (abs($amt1 - $EXP_SUMAMT_SCOPED) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: SUM(amt) moved to ' . $m($amt1)
                . ' — source amounts must never change. ABORT.');
        $gl1 = $glAsOf();
        if (abs($gl1 - $EXP_GL_ASOF) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: acct_balance moved to ' . $m($gl1) . '. ABORT.');
        $tagged = (int) $db->selectOne('SELECT COUNT(*) n FROM acct_gl WHERE updated = ?', [$TAG])->n
                + (int) $db->selectOne('SELECT COUNT(*) n FROM acct_balance WHERE updated = ?', [$TAG])->n;
        if ($tagged) throw new \RuntimeException("POST-CHECK FAILED: $tagged GL rows carry the tag. ABORT.");

        $repaired = implode(',', array_map('intval', array_keys($snapshot)));
        $left = count($scan("AND sku IN ($repaired)"));
        if ($left > 0)
            throw new \RuntimeException("POST-CHECK FAILED: $left break(s) remain in the repaired set. ABORT.");
        $outside = count($scan("AND sku NOT IN ($repaired)"));

        $asof = $reportAsOf();
        $db->commit();

        $say('');
        $say(' POST-CHECK  ' . number_format($n) . ' rows · SUM(amt) ' . $m($amt1) . ' unchanged'
             . ' · acct_balance ' . $m($gl1) . ' unchanged · GL rows tagged 0');
        $say('             breaks remaining: repaired set ' . $left . ' · outside ' . $outside);
        $say('             As Of ' . $m($asof) . ' · variance ' . $m($asof - $gl1)
             . ' (expect ' . $m($EXP_VARIANCE) . ')');
        $say(' COMPLETE — snapshot ' . basename($snapFile));
        $say($L);
        if (isset($cmd)) $cmd->info('Inventory rev3 applied: ' . $n . ' rows.');
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
