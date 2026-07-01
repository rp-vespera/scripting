<?php // WIP35755 rollback — restore the 2 duplicate closures to original amounts

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');
    $AMT = 125662.31;
    $TAG = 'SCRIPT-WEB';

    $DUPS = [
        // WPCL-ACPR0974
        ['closure_id' => 25177, 'acct_gl_id_ml' => 2343203, 'acct_gl_id_wip' => 2343204, 'wip_bal_id' => 861957, 'ml_bal_id' => 861945],
        // WPCL-ACPR0979
        ['closure_id' => 26860, 'acct_gl_id_ml' => 2367820, 'acct_gl_id_wip' => 2367821, 'wip_bal_id' => 871423, 'ml_bal_id' => 871414],
    ];

    $db->beginTransaction();
    try {
        foreach ($DUPS as $d) {
            // acct_gl Memorial Lot side → DR 125,662.31
            $db->update(
                "UPDATE acct_gl SET debit = ?, credit = 0, updated = ?, date_updated = NOW()
                 WHERE acct_gl_id = ?",
                [$AMT, $TAG, $d['acct_gl_id_ml']]
            );

            // acct_gl WIP side → CR 125,662.31
            $db->update(
                "UPDATE acct_gl SET debit = 0, credit = ?, updated = ?, date_updated = NOW()
                 WHERE acct_gl_id = ?",
                [$AMT, $TAG, $d['acct_gl_id_wip']]
            );

            // acct_balance WIP row → credit 125,662.31
            $db->update(
                "UPDATE acct_balance SET debit = 0, credit = ?, updated = ?, date_updated = NOW()
                 WHERE acct_balance_id = ?",
                [$AMT, $TAG, $d['wip_bal_id']]
            );

            // acct_balance Memorial Lot → increment 125,662.31 (restore shared row)
            $db->update(
                "UPDATE acct_balance SET debit = debit + ?, updated = ?, date_updated = NOW()
                 WHERE acct_balance_id = ?",
                [$AMT, $TAG, $d['ml_bal_id']]
            );

            // wip_t_project_closure amt_closure → 125,662.31
            $db->update(
                "UPDATE wip_t_project_closure SET amt_closure = ?, updated = ?, date_updated = NOW()
                 WHERE wip_t_project_closure_id = ?",
                [$AMT, $TAG, $d['closure_id']]
            );
        }

        // Restore doc_t_reference_number_id to NULL on closure 25177 (byte-identical to pre-apply)
        $db->update(
            "UPDATE wip_t_project_closure SET doc_t_reference_number_id = NULL, updated = ?, date_updated = NOW()
             WHERE wip_t_project_closure_id = 25177",
            [$TAG]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
