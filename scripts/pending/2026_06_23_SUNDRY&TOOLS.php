<?php // scripts/pending/2026_06_23_SUNDRY&TOOLS.php
// Asset 3008 JE WALL FORMS — Asset Integrity fix
// Account 12115 SUNDRY, TOOLS, AND OTHERS / org 162012 RP Tan A
//
// CREATED 2026-06-27: backfills the missing closure entries in ast_l_asset_history
//   (and legacy a_l_asset_history) that SAERP's Project Closure workflow
//   failed to write when the project's cycles ran in 2025.
//
// AUDIT TAGS (per 2026-06-29 convention):
//   created = 'IMS-SCRIPT-WEB-16523'   (IMS#16523 references this finding)
//   updated = 'IMS-SCRIPT-WEB-16523'   (when UPDATEs are made)
//
// DEFECT:
//   Project 11455 (RP INTERMENT BACKDROP) closed to asset 3008 via 3 cycles in 2025:
//     Cycle 1: NWPCL-ACPR01537 (Feb 24) + NWPCLRACPR00225 reversal (Mar 3) — net 0
//     Cycle 2: NWPCL-AST00080  (Mar 26) + NWPCLRAST00012 reversal (Mar 26) — net 0
//     Cycle 3: NWPCL-AST00094  (Oct 13) — stuck, no reversal (net +49,847.62)
//   Plus consumption NIRQ0002421 (Apr 9, 2026) +3,780.
//
//   GL correctly shows asset 3008 at ₱53,627.62.
//   But ast_l_asset_history only has 1 entry (NWPCLRAST00012 -49,847.62) + NIRQ +3,780
//   = -46,067.62 (broken).
//
//   SAERP's workflow wrote the cycle 2 reversal to history but NOT the cycle 2 closure
//   itself, NOT cycle 3 closure either. The other 3 events stayed in GL only.
//
// FIX CASCADE (FK-safe order, all INSERTs):
//   1. ast_l_asset_history (NEW schema) ×2 — the missing closure entries
//      - NWPCL-AST00080  +49,847.62  (cycle 2 closure)
//      - NWPCL-AST00094  +49,847.62  (cycle 3 closure)
//   2. ast_l_asset_docline (NEW schema) ×2 — descriptive labels for the above
//   3. a_l_asset_history (LEGACY schema) ×2 — same closures, for SAERP UI report's join
//   4. a_l_asset_docline (LEGACY schema) ×2 — legacy labels
//   5. UPDATE ast_l_asset_history rows linking them to (3) and (4) + reference numbers
//
// Effect:
//   Asset 3008 history net:  -46,067.62 → +53,627.62  (matches GL)
//   Variance (FRS Scanner):   99,695.24 → 0          ✓
//   Variance (SAERP Asset Integrity Summary):  53,627.62 → 0  ✓
//   SAERP Asset Ledger Detail report:  unchanged (movement report, by design)
//
// REPLICA-TESTED 2026-06-27 — full 4-table cascade validated end-to-end.

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $ASSET_ID         = 3008;
    $GL_ACCT          = 12115;
    $ORG              = 162012;
    $AMT              = 49847.62;
    $TOL              = 0.01;

    // Audit marker — IMS#16523
    $TAG              = 'IMS-SCRIPT-WEB-16523';

    // Pre-existing accountability + reference numbers (looked up Jun 27)
    $ACC_CYCLE2_REV   = 11312;        // ast_l_asset_accountability for NWPCLRAST00012
    $ACC_ARQ_ORIG     = 11131;        // ast_l_asset_accountability for ARQ0000134 (original asset requisition)
    $REF_NWPCL080     = 1443278;      // doc_t_reference_number for NWPCL-AST00080
    $REF_NWPCL094     = 1534516;      // doc_t_reference_number for NWPCL-AST00094
    $LEGACY_ACC       = 5862;         // a_l_asset_accountability (legacy) for asset 3008
    $SUBMOD_WPCL_AST  = 292;          // doc_i_submod for WPCL-AST

    $line  = str_repeat('=', 95);
    $say   = function ($s) { echo $s . PHP_EOL; };
    $money = fn (float $x) => number_format($x, 2, '.', ',');

    $say($line);
    $say(" ASSET 3008 JE WALL FORMS — backfill 2 missing closure entries");
    $say(" Account 12115 SUNDRY TOOLS / Org 162012 RP Tan A");
    $say(" Adds: NWPCL-AST00080 +49,847.62  AND  NWPCL-AST00094 +49,847.62");
    $say($line);

    // IDEMPOTENCY CHECK
    $existing = (int) $db->selectOne(
        "SELECT COUNT(*) AS c FROM ast_l_asset_history
         WHERE documentno IN ('NWPCL-AST00080', 'NWPCL-AST00094')
           AND ast_l_asset_accountability_id IN (?, ?)",
        [$ACC_CYCLE2_REV, $ACC_ARQ_ORIG]
    )->c;
    if ($existing === 2) {
        $say(""); $say(" NO-OP — both backfill entries already present."); $say($line); return;
    }
    if ($existing !== 0) {
        throw new \RuntimeException("Partial state: expected 0 or 2 backfill entries, found $existing");
    }

    // PRE-CHECK — verify asset 3008 exists and is broken as expected
    $asset = $db->selectOne(
        "SELECT ast_i_asset_id, asset_name, ad_org_id FROM ast_i_asset WHERE ast_i_asset_id = ?",
        [$ASSET_ID]
    );
    if (!$asset || $asset->asset_name !== 'JE WALL FORMS') {
        throw new \RuntimeException("Asset 3008 not found or name mismatch");
    }
    $say(""); $say(" PRE-CHECK ✓ Asset 3008 JE WALL FORMS exists (ad_org_id=" . $asset->ad_org_id . ")");

    // Compute current variance (org-level, account 12115)
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

    // APPLY
    $say(""); $say(" APPLYING (transaction):");
    $db->beginTransaction();
    try {
        // ===== 1+2. NEW SCHEMA: ast_l_asset_history + ast_l_asset_docline =====

        // 1a. NEW docline for NWPCL-AST00080
        $db->insert(
            "INSERT INTO ast_l_asset_docline (description, a_l_asset_docline_id, created, date_created)
             VALUES (?, NULL, ?, NOW())",
            ['JE WALL FORMS - Project Closure Cycle 2', $TAG]
        );
        $newDocline1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_docline id=$newDocline1");

        // 1b. NEW docline for NWPCL-AST00094
        $db->insert(
            "INSERT INTO ast_l_asset_docline (description, a_l_asset_docline_id, created, date_created)
             VALUES (?, NULL, ?, NOW())",
            ['JE WALL FORMS - Project Closure Cycle 3 (final)', $TAG]
        );
        $newDocline2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_docline id=$newDocline2");

        // 1c. NEW history for NWPCL-AST00080
        $db->insert(
            "INSERT INTO ast_l_asset_history
             (ast_l_asset_accountability_id, ast_l_asset_docline_id, doc_i_submod_id, documentno,
              date_trans, date_gl, ad_org_id, is_active, amount, is_asset_acct, transaction_type,
              doc_t_reference_number_id, created, date_created)
             VALUES (?, ?, ?, ?, '2025-03-26 11:00:00', '2025-03-26', ?, 1, ?, 0, 'PROJECT', ?, ?, NOW())",
            [$ACC_CYCLE2_REV, $newDocline1, $SUBMOD_WPCL_AST, 'NWPCL-AST00080', $ORG, $AMT, $REF_NWPCL080, $TAG]
        );
        $newAstHist1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_history id=$newAstHist1 (NWPCL-AST00080 +" . $money($AMT) . ")");

        // 1d. NEW history for NWPCL-AST00094
        $db->insert(
            "INSERT INTO ast_l_asset_history
             (ast_l_asset_accountability_id, ast_l_asset_docline_id, doc_i_submod_id, documentno,
              date_trans, date_gl, ad_org_id, is_active, amount, is_asset_acct, transaction_type,
              doc_t_reference_number_id, created, date_created)
             VALUES (?, ?, ?, ?, '2025-10-13 09:00:00', '2025-10-13', ?, 1, ?, 0, 'PROJECT', ?, ?, NOW())",
            [$ACC_ARQ_ORIG, $newDocline2, $SUBMOD_WPCL_AST, 'NWPCL-AST00094', $ORG, $AMT, $REF_NWPCL094, $TAG]
        );
        $newAstHist2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_history id=$newAstHist2 (NWPCL-AST00094 +" . $money($AMT) . ")");

        // ===== 3+4. LEGACY SCHEMA: a_l_asset_history + a_l_asset_docline =====

        // 2a. LEGACY docline for NWPCL-AST00080 (note: a_l_asset_docline has no 'created' column)
        $db->insert(
            "INSERT INTO a_l_asset_docline (description, date_created, is_active)
             VALUES (?, NOW(), 1)",
            ['JE WALL FORMS - Project Closure Cycle 2']
        );
        $legacyDocline1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT a_l_asset_docline id=$legacyDocline1 (legacy)");

        // 2b. LEGACY docline for NWPCL-AST00094
        $db->insert(
            "INSERT INTO a_l_asset_docline (description, date_created, is_active)
             VALUES (?, NOW(), 1)",
            ['JE WALL FORMS - Project Closure Cycle 3 (final)']
        );
        $legacyDocline2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT a_l_asset_docline id=$legacyDocline2 (legacy)");

        // 2c. LEGACY history for NWPCL-AST00080
        $db->insert(
            "INSERT INTO a_l_asset_history
             (a_l_asset_accountability_id, date_trans, date_gl, ad_org_id, is_active, amount,
              is_asset_acct, is_glitem_acct, transaction_type, documentno, a_l_asset_docline_id,
              created, date_created)
             VALUES (?, '2025-03-26 11:00:00', '2025-03-26', ?, 1, ?, 0, 0, 'PROJECT', 'NWPCL-AST00080', ?, ?, NOW())",
            [$LEGACY_ACC, $ORG, $AMT, $legacyDocline1, $TAG]
        );
        $legacyHist1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT a_l_asset_history id=$legacyHist1 (legacy NWPCL-AST00080)");

        // 2d. LEGACY history for NWPCL-AST00094
        $db->insert(
            "INSERT INTO a_l_asset_history
             (a_l_asset_accountability_id, date_trans, date_gl, ad_org_id, is_active, amount,
              is_asset_acct, is_glitem_acct, transaction_type, documentno, a_l_asset_docline_id,
              created, date_created)
             VALUES (?, '2025-10-13 09:00:00', '2025-10-13', ?, 1, ?, 0, 0, 'PROJECT', 'NWPCL-AST00094', ?, ?, NOW())",
            [$LEGACY_ACC, $ORG, $AMT, $legacyDocline2, $TAG]
        );
        $legacyHist2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT a_l_asset_history id=$legacyHist2 (legacy NWPCL-AST00094)");

        // ===== 5. Link NEW to LEGACY (update bridges) =====
        $db->update(
            "UPDATE ast_l_asset_docline SET a_l_asset_docline_id = ?, updated = ?, date_updated = NOW()
             WHERE ast_l_asset_docline_id = ?",
            [$legacyDocline1, $TAG, $newDocline1]
        );
        $db->update(
            "UPDATE ast_l_asset_docline SET a_l_asset_docline_id = ?, updated = ?, date_updated = NOW()
             WHERE ast_l_asset_docline_id = ?",
            [$legacyDocline2, $TAG, $newDocline2]
        );
        $db->update(
            "UPDATE ast_l_asset_history SET a_l_asset_history_id = ?, updated = ?, date_updated = NOW()
             WHERE ast_l_asset_history_id = ?",
            [$legacyHist1, $TAG, $newAstHist1]
        );
        $db->update(
            "UPDATE ast_l_asset_history SET a_l_asset_history_id = ?, updated = ?, date_updated = NOW()
             WHERE ast_l_asset_history_id = ?",
            [$legacyHist2, $TAG, $newAstHist2]
        );
        $say("    UPDATE 4 bridge links (new→legacy)");

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
    $say(" SUCCESS — Asset 3008 JE WALL FORMS variance closed via 4-table backfill.");
    $say(" Asset Integrity Summary report should now show WALL FORMS row at +53,627.62.");
    $say(" Note: Asset Ledger Detail report unchanged (movement report by design).");
    $say($line);
};
