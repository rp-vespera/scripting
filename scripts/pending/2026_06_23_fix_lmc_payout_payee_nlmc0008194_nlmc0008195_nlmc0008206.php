<?php // scripts/pending/2026_06_23_fix_lmc_payout_payee_nlmc0008194_nlmc0008195_nlmc0008206.php

/**
 * Fix wrong payee on three LMC payout documents.
 *
 * NLMC0008194 — was assigned to DAYAP, JENNY
 *               correct payee: ARCO, APRIL JOY S.  (bpar_i_person_id=23337, s_bpartner_id=19852)
 *
 * NLMC0008195 — was assigned to ARCO, APRIL JOY S.
 *               correct payee: GANAN, MICAH MARIE M. (bpar_i_person_id=25872, s_bpartner_id=22387)
 *
 * NLMC0008206 — DR was NLMC0008198DR (NLIO00950 Photographer line)
 *               was assigned to JOHNNY JOSHUA V. TINDOGAN (Singer)
 *               correct payee: BACAOCO, IAN REY     (bpar_i_person_id=27756, s_bpartner_id=24271)
 *
 * Tables updated:
 *   - wip_t_lmc_payout   (always — stores payee on both DR and PR)
 *   - fin_l_debt         (when found via either path):
 *       Path A — fin_l_debt_id FK on payout (portal PR flow)
 *       Path B — documentno match (Java ERP PR flow, fin_l_debt_id is NULL)
 *
 * Safety guards:
 *   - ad_org_id = 162012 — docnos are reused across orgs (shared doc_i_stub sequence);
 *     org filter ensures old records from other orgs are never touched.
 *   - ORDER BY wip_t_lmc_payout_id DESC — always targets the latest (2026) record.
 *   - fin_l_debt resolved by FK first (Path A), fallback to documentno (Path B).
 *   - Idempotent: skips cases where both tables already have the correct payee.
 */

return function ($cmd) {
    $DRY_RUN = false;
    $CONN    = 'mysql_secondary';

    $cases = [
        [
            'doc_no'           => 'NLMC0008194',
            'bpar_i_person_id' => 23337,    // ARCO, APRIL JOY S.
            's_bpartner_id'    => 19852,
        ],
        [
            'doc_no'           => 'NLMC0008195',
            'bpar_i_person_id' => 25872,    // GANAN, MICAH MARIE M.
            's_bpartner_id'    => 22387,
        ],
        [
            'doc_no'           => 'NLMC0008206',
            'bpar_i_person_id' => 27756,    // BACAOCO, IAN REY (Photographer, NLIO00950)
            's_bpartner_id'    => 24271,
        ],
    ];

    $line = str_repeat('─', 64);
    $db   = \DB::connection($CONN);

    echo "$line\n";
    echo "LMC PAYOUT PAYEE CORRECTION\n";
    echo "Connection : $CONN\n";
    echo "Mode       : " . ($DRY_RUN ? 'DRY-RUN (no writes)' : 'APPLY') . "\n";
    echo "$line\n";

    $totalRows = 0;

    foreach ($cases as $case) {
        $docNo           = $case['doc_no'];
        $newBparPersonId = $case['bpar_i_person_id'];
        $newBpartnerId   = $case['s_bpartner_id'];

        echo "\n▶ {$docNo}\n";

        // ad_org_id=162012 = NLIO org. Docnos are reused across orgs (shared doc_i_stub
        // sequence), so filter by org to avoid touching old records from other orgs.
        $payout = $db->selectOne("
            SELECT
                p.wip_t_lmc_payout_id,
                p.documentno,
                p.docstatus,
                p.bpar_i_person_id,
                p.s_bpartner_id,
                p.fin_l_debt_id,
                COALESCE(
                    NULLIF(bp.name1, ''),
                    TRIM(CONCAT(COALESCE(per.firstname,''), ' ', COALESCE(per.lastname,'')))
                ) AS payee_name
            FROM wip_t_lmc_payout p
            LEFT JOIN bpar_i_person per ON per.bpar_i_person_id = p.bpar_i_person_id
            LEFT JOIN s_bpartner bp     ON bp.s_bpartner_id     = p.s_bpartner_id
            WHERE p.documentno = ?
              AND p.ad_org_id  = 162012
            ORDER BY p.wip_t_lmc_payout_id DESC
            LIMIT 1
        ", [$docNo]);

        if (!$payout) {
            echo "  ⚠ Not found — skipping.\n";
            continue;
        }

        $proposed = $db->selectOne("
            SELECT
                COALESCE(
                    NULLIF(bp.name1, ''),
                    TRIM(CONCAT(COALESCE(per.firstname,''), ' ', COALESCE(per.lastname,'')))
                ) AS payee_name
            FROM s_bpartner bp
            JOIN bpar_i_person per ON per.s_bpartner_id = bp.s_bpartner_id
            WHERE bp.s_bpartner_id     = ?
              AND per.bpar_i_person_id = ?
        ", [$newBpartnerId, $newBparPersonId]);

        // Resolve fin_l_debt via Path A (FK) or Path B (documentno).
        // COALESCE(fin_l_debt_id, -1) ensures Path A never matches when FK is NULL.
        $debt = $db->selectOne("
            SELECT fin_l_debt_id, bpar_i_person_id, s_bpartner_id
            FROM fin_l_debt
            WHERE fin_l_debt_id = COALESCE(?, -1)   -- Path A: direct FK
               OR documentno    = ?                  -- Path B: Java ERP flow
            ORDER BY fin_l_debt_id DESC
            LIMIT 1
        ", [$payout->fin_l_debt_id, $docNo]);

        $debtPath = $debt
            ? ($payout->fin_l_debt_id ? 'FK' : 'documentno')
            : 'none';

        $payoutOk = (int) $payout->bpar_i_person_id === $newBparPersonId
                 && (int) $payout->s_bpartner_id    === $newBpartnerId;
        $debtOk   = !$debt
                 || ((int) $debt->bpar_i_person_id  === $newBparPersonId
                  && (int) $debt->s_bpartner_id     === $newBpartnerId);

        echo "  Current payee : {$payout->payee_name} (bpar={$payout->bpar_i_person_id}, bp={$payout->s_bpartner_id})\n";
        echo "  Correct payee : {$proposed->payee_name} (bpar={$newBparPersonId}, bp={$newBpartnerId})\n";
        echo "  fin_l_debt    : " . ($debt ? "id={$debt->fin_l_debt_id} via {$debtPath}" : '(none)') . "\n";

        if ($payoutOk && $debtOk) {
            echo "  ✅ Already correct — skipping.\n";
            continue;
        }

        $db->beginTransaction();
        try {
            $rows = 0;

            if (!$payoutOk) {
                $rows += $db->table('wip_t_lmc_payout')
                    ->where('wip_t_lmc_payout_id', $payout->wip_t_lmc_payout_id)
                    ->update([
                        'bpar_i_person_id' => $newBparPersonId,
                        's_bpartner_id'    => $newBpartnerId,
                        'updated'          => 'Script by Web',
                        'date_updated'     => now(),
                    ]);
                echo "  wip_t_lmc_payout updated : {$rows} row\n";
            }

            if ($debt && !$debtOk) {
                $debtRows = $db->table('fin_l_debt')
                    ->where('fin_l_debt_id', $debt->fin_l_debt_id)
                    ->update([
                        'bpar_i_person_id' => $newBparPersonId,
                        's_bpartner_id'    => $newBpartnerId,
                        'updated'          => 'Script by Web',
                        'date_updated'     => now(),
                    ]);
                $rows += $debtRows;
                echo "  fin_l_debt updated       : {$debtRows} row\n";
            }

            if ($DRY_RUN) {
                $db->rollBack();
                echo "  [DRY-RUN] rolled back — {$rows} row(s) would be updated.\n";
            } else {
                $db->commit();
                echo "  ✅ Committed — {$rows} row(s) updated.\n";
                $totalRows += $rows;
            }

        } catch (\Throwable $e) {
            $db->rollBack();
            echo "  ❌ Error (rolled back): " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    echo "\n$line\n";
    if (!$DRY_RUN) {
        echo "Total rows updated : {$totalRows}\n";
        $cmd->info("lmc-payout-payee-correction: {$totalRows} row(s) updated across all documents.");
    } else {
        echo "DRY-RUN complete — no changes written.\n";
        $cmd->info('lmc-payout-payee-correction: dry-run complete, no changes written.');
    }
    echo "$line\n";
};
