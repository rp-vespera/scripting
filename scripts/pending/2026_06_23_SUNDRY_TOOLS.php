<?php

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
        // Description follows SAERP convention: asset/project name only (matches pre-existing
        // docline 6413 for NWPCLRAST00012 reversal).
        $db->insert(
            "INSERT INTO ast_l_asset_docline (description, a_l_asset_docline_id, created, date_created)
             VALUES (?, NULL, ?, NOW())",
            ['JE WALL FORMS REPAIR AND FABRICATION', $TAG]
        );
        $newDocline1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_docline id=$newDocline1");

        // 1b. NEW docline for NWPCL-AST00094 (same SAERP wording as 1a)
        $db->insert(
            "INSERT INTO ast_l_asset_docline (description, a_l_asset_docline_id, created, date_created)
             VALUES (?, NULL, ?, NOW())",
            ['JE WALL FORMS REPAIR AND FABRICATION', $TAG]
        );
        $newDocline2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_docline id=$newDocline2");

        // 1c. NEW history for NWPCL-AST00080 — is_glitem_acct=0 explicit (was NULL default; per review 2026-07-04)
        $db->insert(
            "INSERT INTO ast_l_asset_history
             (ast_l_asset_accountability_id, ast_l_asset_docline_id, doc_i_submod_id, documentno,
              date_trans, date_gl, ad_org_id, is_active, amount, is_asset_acct, is_glitem_acct,
              transaction_type, doc_t_reference_number_id, created, date_created)
             VALUES (?, ?, ?, ?, '2025-03-26 11:00:00', '2025-03-26', ?, 1, ?, 0, 0, 'PROJECT', ?, ?, NOW())",
            [$ACC_CYCLE2_REV, $newDocline1, $SUBMOD_WPCL_AST, 'NWPCL-AST00080', $ORG, $AMT, $REF_NWPCL080, $TAG]
        );
        $newAstHist1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_history id=$newAstHist1 (NWPCL-AST00080 +" . $money($AMT) . ")");

        // 1d. NEW history for NWPCL-AST00094 — is_glitem_acct=0 explicit (was NULL default; per review 2026-07-04)
        $db->insert(
            "INSERT INTO ast_l_asset_history
             (ast_l_asset_accountability_id, ast_l_asset_docline_id, doc_i_submod_id, documentno,
              date_trans, date_gl, ad_org_id, is_active, amount, is_asset_acct, is_glitem_acct,
              transaction_type, doc_t_reference_number_id, created, date_created)
             VALUES (?, ?, ?, ?, '2025-10-13 09:00:00', '2025-10-13', ?, 1, ?, 0, 0, 'PROJECT', ?, ?, NOW())",
            [$ACC_ARQ_ORIG, $newDocline2, $SUBMOD_WPCL_AST, 'NWPCL-AST00094', $ORG, $AMT, $REF_NWPCL094, $TAG]
        );
        $newAstHist2 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT ast_l_asset_history id=$newAstHist2 (NWPCL-AST00094 +" . $money($AMT) . ")");

        // ===== 3+4. LEGACY SCHEMA: a_l_asset_history + a_l_asset_docline =====

        // 2a. LEGACY docline for NWPCL-AST00080 (same SAERP wording as 1a; note: no 'created' column)
        $db->insert(
            "INSERT INTO a_l_asset_docline (description, date_created, is_active)
             VALUES (?, NOW(), 1)",
            ['JE WALL FORMS REPAIR AND FABRICATION']
        );
        $legacyDocline1 = (int) $db->getPdo()->lastInsertId();
        $say("    INSERT a_l_asset_docline id=$legacyDocline1 (legacy)");

        // 2b. LEGACY docline for NWPCL-AST00094 (same SAERP wording)
        $db->insert(
            "INSERT INTO a_l_asset_docline (description, date_created, is_active)
             VALUES (?, NOW(), 1)",
            ['JE WALL FORMS REPAIR AND FABRICATION']
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
