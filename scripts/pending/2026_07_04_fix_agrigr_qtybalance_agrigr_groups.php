<?php // scripts/pending/2026_07_04_fix_agrigr_qtybalance_agrigr_groups.php
/**
 * ============================================================================
 * Fix stock-card qtybalance for AGRIGR-touched (sku, locator) groups
 * Date: 2026-07-04 · Target: mysql_secondary (SAS SAERPRP)
 * ============================================================================
 *
 * WHY
 * ---
 * Recompute qtybalance for every (sku, locator) group that AGRIGR posted to
 * (rows with created = 'SYSTEM'), wherever the stored balance != the running
 * SUM(qtymovement) over the active rows in canonical (date_gl, id) order.
 * Same validated fix already applied for documents P00315–P00348, here scoped
 * to all AGRIGR groups.
 *
 * WHAT IT TOUCHES
 * ---------------
 *   nvt_l_stockcard_locatorqty.qtybalance — only rows whose running balance is
 *   wrong, and only within (sku, locator) groups AGRIGR posted to.
 *   Data only — no schema/logic change.
 *
 * SAFETY
 * ------
 *   - Before any write, snapshots every affected group into
 *     bak_scloc_agrigr_groups (dropped + recreated each run) for rollback.
 *   - Balances are recomputed with BCMath over active rows only.
 *   - Updates run in an explicit transaction on mysql_secondary; any error
 *     rolls the batch back. This script COMMITS.
 *
 * NOTE: the CREATE TABLE ... AS SELECT backup is DDL and auto-commits on MySQL;
 * it runs before the explicit transaction that performs the corrections. It
 * only touches mysql_secondary, independent of the runner's default-connection
 * transaction wrapper.
 *
 * ROLLBACK
 *   UPDATE nvt_l_stockcard_locatorqty t
 *     JOIN bak_scloc_agrigr_groups b USING (nvt_l_stockcard_locatorqty_id)
 *      SET t.qtybalance = b.qtybalance;
 */

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $ro = $db->selectOne('SELECT @@read_only ro, @@hostname hn');
    echo "Server: {$ro->hn}  read_only=" . ($ro->ro ? 'YES' : 'no') . "  mode=*** COMMIT ***\n\n";

    // All active rows in AGRIGR-touched groups, in canonical order.
    $rows = $db->select("
        SELECT nvt_l_stockcard_locatorqty_id id, nvt_i_sku_id sku, nvt_i_locator_id loc,
               documentno doc, date_gl dt, qtymovement mv, qtybalance bal, created
          FROM nvt_l_stockcard_locatorqty
         WHERE COALESCE(is_active,1)=1
           AND (nvt_i_sku_id, nvt_i_locator_id) IN (
               SELECT DISTINCT nvt_i_sku_id, nvt_i_locator_id
               FROM nvt_l_stockcard_locatorqty WHERE created='SYSTEM')
         ORDER BY nvt_i_sku_id, nvt_i_locator_id, date_gl ASC, nvt_l_stockcard_locatorqty_id ASC
    ");

    $run = '0'; $key = null; $fixes = [];
    foreach ($rows as $r) {
        $k = $r->sku.'_'.$r->loc;
        if ($k !== $key) { $key = $k; $run = '0'; }
        $run = bcadd($run, (string) $r->mv, 6);
        if (bccomp((string) $r->bal, $run, 2) !== 0) {
            $fixes[] = ['id' => $r->id, 'sku' => $r->sku, 'loc' => $r->loc, 'doc' => $r->doc, 'dt' => $r->dt,
                        'from' => (string) $r->bal, 'to' => $run, 'by' => ($r->created ?: 'native')];
        }
    }

    echo "rows to correct: " . count($fixes) . "\n";
    foreach ($fixes as $f) {
        printf("  #%-9s sku=%-5s loc=%-6s %-14s %-11s %10s -> %-10s [%s]\n",
            $f['id'], $f['sku'], $f['loc'], substr((string) $f['doc'], 0, 14), $f['dt'], $f['from'], $f['to'], $f['by']);
    }
    if (! $fixes) { echo "nothing to fix.\n"; return; }

    // Snapshot every affected group before touching anything (DDL auto-commits).
    $db->statement("DROP TABLE IF EXISTS bak_scloc_agrigr_groups");
    $db->statement("CREATE TABLE bak_scloc_agrigr_groups AS
        SELECT * FROM nvt_l_stockcard_locatorqty
        WHERE (nvt_i_sku_id, nvt_i_locator_id) IN (
            SELECT DISTINCT nvt_i_sku_id, nvt_i_locator_id
            FROM nvt_l_stockcard_locatorqty WHERE created='SYSTEM')");
    echo "backup: bak_scloc_agrigr_groups\n";

    $db->beginTransaction();
    try {
        $n = 0;
        foreach ($fixes as $f) {
            $n += $db->update(
                "UPDATE nvt_l_stockcard_locatorqty SET qtybalance=? WHERE nvt_l_stockcard_locatorqty_id=?",
                [$f['to'], $f['id']]
            );
        }
        echo "updated: {$n}\n";
        $db->commit();
        echo ">> COMMITTED.\n";
        if (isset($cmd)) $cmd->info("agrigr-qtybalance: {$n} row(s) corrected and committed.");
    } catch (\Throwable $ex) {
        $db->rollBack();
        echo "!! ERROR rolled back: " . $ex->getMessage() . "\n";
        throw $ex;
    }
};
