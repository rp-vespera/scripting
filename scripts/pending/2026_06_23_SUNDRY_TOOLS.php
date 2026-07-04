<?php // scripts/pending/2026_06_23_SUNDRY_TOOLS.php
// Asset 3008 JE WALL FORMS — Asset Integrity fix
// Account 12115 SUNDRY, TOOLS, AND OTHERS / org 162012 RP Tan A
//
// UPDATED 2026-07-04 after replica testing revealed 3 more requirements:
//   1) Each backfilled closure needs its OWN accountability row (new + legacy).
//      Report groups movements by accountability documentno; reusing an existing
//      accountability lumps our closures under the wrong context.
//   2) is_active on backfilled history rows must be NULL (SAERP convention for
//      naturally-generated closure rows).
//   3) wip_i_project.project_type must be 'ACCESSION' (was 'ACCESSORY') so the
//      Asset Ledger Detail JRXML v2 renders direct closures. The report has
//      hardcoded WHERE proj.project_type='ACCESSION' for direct-closure sections;
//      ACCESSORY-type projects only render reversal-side entries.
//
// AUDIT TAGS:
//   created = 'IMS-SCRIPT-WEB-16523'
//   updated = 'IMS-SCRIPT-WEB-16523' (bridge UPDATEs) / '-C' variant on project_type UPDATE
//
// DEFECT:
//   Project 11455 → 10545 (JE WALL FORMS REPAIR AND FABRICATION) closed to asset
//   3008 via 3 cycles in 2025:
//     Cycle 1: NWPCL-ACPR01537 (Feb 24) + NWPCLRACPR00225 reversal (Mar 3) — net 0
//     Cycle 2: NWPCL-AST00080  (Mar 26) + NWPCLRAST00012 reversal (Mar 26) — net 0
//     Cycle 3: NWPCL-AST00094  (Oct 13) — stuck, no reversal (+49,847.62)
//   Plus consumption NIRQ0002421 (Apr 9, 2026) +3,780.
//
//   GL correctly shows asset 3008 at ₱53,627.62.
//   But ast_l_asset_history had only 1 entry (NWPCLRAST00012 -49,847.62) + NIRQ +3,780
//   = -46,067.62 (broken). SAERP's workflow wrote the cycle 2 reversal to history
//   but NOT the cycle 2 closure itself, NOT cycle 3 closure either.
//
// FIX CASCADE (FK-safe order):
//   1. INSERT ast_l_asset_docline × 2 (new schema doclines)
//   2. INSERT ast_l_asset_accountability × 2 (new schema — one per closure doc)
//   3. INSERT ast_l_asset_history × 2 (linked to new acc + docline, is_active=NULL)
//   4. INSERT a_l_asset_docline × 2 (legacy)
//   5. INSERT a_l_asset_accountability × 2 (legacy)
//   6. INSERT a_l_asset_history × 2 (legacy, is_active=NULL)
//   7. UPDATE new→legacy bridges on docline, accountability, history (3 pairs × 2 = 6)
//   8. UPDATE wip_i_project SET project_type='ACCESSION' WHERE wip_i_project_id=10545
//
// Effect:
//   FRS Asset Integrity scanner variance: 99,695.24 → 0 ✓
//   Asset Ledger Detail (SAERP): NWPCL-AST00080 and NWPCL-AST00094 now render
//     under ASSET ACCESSION with proper description "JE WALL FORMS REPAIR AND FABRICATION"
//   Account 12115 As Of Asset = 1,711,881.81 exactly matches As Of GL (variance 0)

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    // Deployment date — used for every date_updated / date_created stamp.
    // Per feedback_script_audit_tag_convention: date_updated = deployment
    // date (never runtime UTC_TIMESTAMP or current-time). Update this to the
    // actual go-live date before running on staging/prod so the audit trail matches
    // when the fix landed (not when the row was materially inserted).
    $DEPLOY_DATE      = '2026-07-04 00:00:00';

    // Constants
    $ASSET_ID         = 3008;
    $GL_ACCT          = 12115;
    $ORG              = 162012;
    $PROJECT_ID       = 10545;        // wip_i_project.wip_i_project_id
    $AMT              = 49847.62;
    $TOL              = 0.01;

    // Audit markers
    $TAG              = 'IMS-SCRIPT-WEB-16523';
    $TAG_PROJTYPE     = 'IMS-SCRIPT-WEB-16523-C';   // separate tag for project_type update (rollback filter)

    // Legacy IDs
    $LEGACY_ASSET_ID   = 2685;        // a_asset.a_asset_id (legacy id for asset 3008)
    $LEGACY_BPARTNER   = 6561;        // s_bpartner.s_bpartner_id (Rico Jhon Erfe)
    $BPAR_PERSON_ID    = 9082;        // bpar_i_person.bpar_i_person_id (new schema custodian)

    // Reference IDs
    $REF_NWPCL080      = 1443278;     // doc_t_reference_number for NWPCL-AST00080
    $REF_NWPCL094      = 1534516;     // doc_t_reference_number for NWPCL-AST00094
    $SUBMOD_WPCL_AST   = 292;         // doc_i_submod for WPCL-AST

    // Docline text (SAERP convention: asset/project name only)
    $DOCLINE_DESC      = 'JE WALL FORMS REPAIR AND FABRICATION';

    $line  = str_repeat('=', 95);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" ASSET 3008 JE WALL FORMS — backfill closures + accountabilities + project_type fix");
    $say(" Account 12115 SUNDRY TOOLS / Org 162012 RP Tan A / Project 10545");
    $say(" Adds: NWPCL-AST00080 +49,847.62  AND  NWPCL-AST00094 +49,847.62");
    $say(" Also: creates 4 accountabilities, sets project_type ACCESSORY→ACCESSION");
    $say($line);

    // IDEMPOTENCY — both closure history rows already exist means fix already applied
    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM ast_l_asset_history
         WHERE documentno IN ('NWPCL-AST00080', 'NWPCL-AST00094')
           AND created = ?",
        [$TAG]
    )->c;
    if ($existing === 2) {
        $say(""); $say(" NO-OP — both backfill entries already present."); $say($line); return;
    }
    if ($existing !== 0) {
        throw new \RuntimeException("Partial state: expected 0 or 2 backfill entries, found $existing");
    }

    // PRE-CHECK — asset exists
    $asset = $db->selectOne(
        "SELECT ast_i_asset_id, asset_name, ad_org_id FROM ast_i_asset WHERE ast_i_asset_id = ?",
        [$ASSET_ID]
    );
    if (!$asset || $asset->asset_name !== 'JE WALL FORMS') {
        throw new \RuntimeException("Asset 3008 not found or name mismatch");
    }
    $say(""); $say(" PRE-CHECK ✓ Asset 3008 JE WALL FORMS exists (ad_org_id=" . $asset->ad_org_id . ")");

    // PRE-CHECK — project_type is ACCESSORY
    $proj = $db->selectOne(
        "SELECT wip_i_project_id, project_name, project_type FROM wip_i_project WHERE wip_i_project_id = ?",
        [$PROJECT_ID]
    );
    if (!$proj || $proj->project_type !== 'ACCESSORY') {
        throw new \RuntimeException("Project $PROJECT_ID expected project_type=ACCESSORY, got " . ($proj->project_type ?? 'null'));
    }
    $say(" PRE-CHECK ✓ Project $PROJECT_ID {$proj->project_name} is ACCESSORY (will flip to ACCESSION)");

    // PRE-CHECK — variance = 99,695.24
    $variance = (float) $db->selectOne(
        "SELECT ROUND((
           (SELECT IFNULL(SUM(debit - credit), 0) FROM acct_gl WHERE gl_acct_id = ? AND ad_org_id = ?)
           -
           (SELECT IFNULL(SUM(h.amount), 0)
            FROM ast_l_asset_history h
            JOIN ast_l_asset_accountability acc ON acc.ast_l_asset_accountability_id = h.ast_l_asset_accountability_id
            JOIN ast_i_asset ia ON ia.ast_i_asset_id = acc.ast_i_asset_id
            JOIN ast_i_asset_type_acct ata ON ata.ast_i_asset_type_id = ia.ast_i_asset_type_id
            WHERE h.ad_org_id = ? AND ata.gl_acct_id_asset = ?)
         ), 2) AS v",
        [$GL_ACCT, $ORG, $ORG, $GL_ACCT]
    )->v;
    $say(""); $say(" Variance BEFORE = " . $money($variance) . "  (expected 99,695.24)");
    if (abs($variance - 99695.24) > $TOL) throw new \RuntimeException("Variance is $variance, expected 99,695.24");

    // Common per-closure data
    $CLOSURES = [
        [
            'documentno' => 'NWPCL-AST00080',
            'date_gl'    => '2025-03-26',
            'date_trans' => '2025-03-26 11:00:00',
            'ref_id'     => $REF_NWPCL080,
        ],
        [
            'documentno' => 'NWPCL-AST00094',
            'date_gl'    => '2025-10-13',
            'date_trans' => '2025-10-13 09:00:00',
            'ref_id'     => $REF_NWPCL094,
        ],
    ];

    // APPLY
    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        $newAccIds     = []; // ast_l_asset_accountability_id per closure
        $legacyAccIds  = []; // a_l_asset_accountability_id per closure
        $newDoclineIds = [];
        $legacyDoclineIds = [];
        $newHistIds    = [];
        $legacyHistIds = [];

        foreach ($CLOSURES as $i => $c) {
            $say(""); $say("  -- closure #" . ($i + 1) . ": {$c['documentno']} --");

            // 1. NEW-schema docline (SAERP convention: asset/project name)
            $db->insert(
                "INSERT INTO ast_l_asset_docline (description, a_l_asset_docline_id, created, date_created)
                 VALUES (?, NULL, ?, '{$DEPLOY_DATE}')",
                [$DOCLINE_DESC, $TAG]
            );
            $newDoclineIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT ast_l_asset_docline id={$newDoclineIds[$i]}");

            // 2. NEW-schema accountability (one per closure — required for report grouping)
            $db->insert(
                "INSERT INTO ast_l_asset_accountability
                 (documentno, date_gl, date_trans, ast_i_asset_id, bpar_i_person_id,
                  doc_t_reference_number_id, created, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '{$DEPLOY_DATE}')",
                [$c['documentno'], $c['date_gl'], $c['date_trans'], $ASSET_ID, $BPAR_PERSON_ID, $c['ref_id'], $TAG]
            );
            $newAccIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT ast_l_asset_accountability id={$newAccIds[$i]}");

            // 3. NEW-schema history (is_active=NULL, is_glitem_acct=0 explicit — per review)
            $db->insert(
                "INSERT INTO ast_l_asset_history
                 (ast_l_asset_accountability_id, ast_l_asset_docline_id, doc_i_submod_id, documentno,
                  date_trans, date_gl, ad_org_id, is_active, amount, is_asset_acct, is_glitem_acct,
                  transaction_type, doc_t_reference_number_id, created, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 0, 0, 'PROJECT', ?, ?, '{$DEPLOY_DATE}')",
                [$newAccIds[$i], $newDoclineIds[$i], $SUBMOD_WPCL_AST, $c['documentno'],
                 $c['date_trans'], $c['date_gl'], $ORG, $AMT, $c['ref_id'], $TAG]
            );
            $newHistIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT ast_l_asset_history id={$newHistIds[$i]} ({$c['documentno']} +" . $money($AMT) . ")");

            // 4. LEGACY docline (no 'created' column on this table)
            $db->insert(
                "INSERT INTO a_l_asset_docline (description, date_created, is_active)
                 VALUES (?, '{$DEPLOY_DATE}', 1)",
                [$DOCLINE_DESC]
            );
            $legacyDoclineIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT a_l_asset_docline id={$legacyDoclineIds[$i]} (legacy)");

            // 5. LEGACY accountability
            $db->insert(
                "INSERT INTO a_l_asset_accountability
                 (documentno, date_gl, date_trans, a_asset_id, s_bpartner_id, created, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, '{$DEPLOY_DATE}')",
                [$c['documentno'], $c['date_gl'], $c['date_trans'], $LEGACY_ASSET_ID, $LEGACY_BPARTNER, $TAG]
            );
            $legacyAccIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT a_l_asset_accountability id={$legacyAccIds[$i]} (legacy)");

            // 6. LEGACY history (is_active=NULL)
            $db->insert(
                "INSERT INTO a_l_asset_history
                 (a_l_asset_accountability_id, date_trans, date_gl, ad_org_id, is_active, amount,
                  is_asset_acct, is_glitem_acct, transaction_type, documentno, a_l_asset_docline_id,
                  created, date_created)
                 VALUES (?, ?, ?, ?, NULL, ?, 0, 0, 'PROJECT', ?, ?, ?, '{$DEPLOY_DATE}')",
                [$legacyAccIds[$i], $c['date_trans'], $c['date_gl'], $ORG, $AMT,
                 $c['documentno'], $legacyDoclineIds[$i], $TAG]
            );
            $legacyHistIds[$i] = (int) $db->getPdo()->lastInsertId();
            $say("    INSERT a_l_asset_history id={$legacyHistIds[$i]} (legacy)");
        }

        // 7. Bridge UPDATEs: new schema rows point at their legacy counterparts
        $say(""); $say("  -- bridge new→legacy --");
        foreach ($CLOSURES as $i => $c) {
            $db->update(
                "UPDATE ast_l_asset_docline SET a_l_asset_docline_id = ?, updated = ?, date_updated = '{$DEPLOY_DATE}'
                 WHERE ast_l_asset_docline_id = ?",
                [$legacyDoclineIds[$i], $TAG, $newDoclineIds[$i]]
            );
            $db->update(
                "UPDATE ast_l_asset_accountability SET a_l_asset_accountability_id = ?, updated = ?, date_updated = '{$DEPLOY_DATE}'
                 WHERE ast_l_asset_accountability_id = ?",
                [$legacyAccIds[$i], $TAG, $newAccIds[$i]]
            );
            $db->update(
                "UPDATE ast_l_asset_history SET a_l_asset_history_id = ?, updated = ?, date_updated = '{$DEPLOY_DATE}'
                 WHERE ast_l_asset_history_id = ?",
                [$legacyHistIds[$i], $TAG, $newHistIds[$i]]
            );
        }
        $say("    UPDATE 6 bridges (docline×2 + accountability×2 + history×2)");

        // 8. project_type ACCESSORY → ACCESSION (required for Asset Ledger Detail JRXML v2)
        $a = $db->update(
            "UPDATE wip_i_project SET project_type = 'ACCESSION', updated = ?, date_updated = '{$DEPLOY_DATE}'
             WHERE wip_i_project_id = ? AND project_type = 'ACCESSORY'",
            [$TAG_PROJTYPE, $PROJECT_ID]
        );
        if ($a !== 1) throw new \RuntimeException("project_type update affected $a, expected 1");
        $say("    UPDATE wip_i_project $PROJECT_ID project_type ACCESSORY→ACCESSION");

        // POST-CHECK — variance must close to 0
        $varianceAfter = (float) $db->selectOne(
            "SELECT ROUND((
               (SELECT IFNULL(SUM(debit - credit), 0) FROM acct_gl WHERE gl_acct_id = ? AND ad_org_id = ?)
               -
               (SELECT IFNULL(SUM(h.amount), 0)
                FROM ast_l_asset_history h
                JOIN ast_l_asset_accountability acc ON acc.ast_l_asset_accountability_id = h.ast_l_asset_accountability_id
                JOIN ast_i_asset ia ON ia.ast_i_asset_id = acc.ast_i_asset_id
                JOIN ast_i_asset_type_acct ata ON ata.ast_i_asset_type_id = ia.ast_i_asset_type_id
                WHERE h.ad_org_id = ? AND ata.gl_acct_id_asset = ?)
             ), 2) AS v",
            [$GL_ACCT, $ORG, $ORG, $GL_ACCT]
        )->v;
        $say(""); $say(" POST-CHECK variance AFTER = " . $money($varianceAfter) . "  (must be 0.00)");
        if (abs($varianceAfter) > $TOL) throw new \RuntimeException("Variance after fix is $varianceAfter, expected 0");

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(""); $say($line);
    $say(" SUCCESS — Asset 3008 JE WALL FORMS variance closed.");
    $say(" Asset Ledger Detail: NWPCL-AST00080 and NWPCL-AST00094 now render under ASSET ACCESSION.");
    $say(" Asset Integrity Summary: 12115 SUNDRY variance closed to 0.00 as of April 10, 2026.");
    $say($line);
};
