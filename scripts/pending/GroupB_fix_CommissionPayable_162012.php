<?php // scripts/pending/GroupB_fix_CommissionPayable_162012.php
// IMS-SCRIPT #13714 — Commission Payable (21136) GROUP B — FINAL unified correction, RP Tan A (org 162012)
// *** POSTS TO THE GENERAL LEDGER (acct_gl) — boss-approved. Supersedes GB_/GB2_ drafts. ***
//
// Per Sir Mark: use a REAL General-Journal number from doc_i_stub (incremented); the IMS tag goes
// ONLY in the `updated` field; the DESCRIPTION (acct_doc.explanation) uses native-style wording
// like the existing JVs -- NOT a custom 'IMS...' documentno and NOT 'IMS' inside the explanation.
//
// Corrects all 5 Group-B agents:
//   18335 LORELIE JOPSON BACOY  sub 28084  -12,449.92 -> 0   DR 52006 Commission   (missing accrual)
//   18992 SALES AGENT           sub 30009  -29,858.69 -> 0   DR 52006 Commission   (missing accrual, placeholder)
//   3314  WALK IN               sub 3765    -3,960.00 -> 0   DR 42001 Other Income (over-reversal, placeholder)
//   3028  RUBY DOMINGO          sub 3366    -2,560.00 -> 0   DR 52006 Commission   (over-reversal; lot 2-26-137 BLOCKED -> held)
//   7939  COLGUE                sub 10328  -36,000.00 -> 0   DR 52006 Commission   (LSPCA over-reversal of a PAID commission; IMS-13714 = keep, de-count cancelled LSP)
//
// Each agent = one balanced General Journal:
//   doc_i_stub  -> allocate NJV #### (PR, cat 62 stub 268) + NJV####DR (DR, cat 64 stub 267), bump currentno
//   acct_doc    -> explanation carries "IMS-13714" + agent + reason  (this is the ledger Description)
//   acct_gl     -> CR 21136 (agent subacct) + DR offset acct, linked to acct_doc + reference
//   acct_balance-> cache mirror.  Aging untouched (already 0).
//
// AUDIT: created=NULL ; updated='#IMS-13714'. Idempotent + rollback keyed on updated + submod 4.
// Rollback: GroupB_fix_CommissionPayable_162012_ROLLBACK.php

return function ($cmd) {
    $db = \DB::connection('mysql_secondary');

    $UPD     = '#IMS-13714';
    $SUBMOD  = 4;                          // GJL — General Journal
    $ACCT_CP = 21136;
    $ORG     = 162012;
    $PR_STUB = 268;                        // doc_i_stub: NJV        (cat 62, the documentno_pr)
    $DR_STUB = 267;                        // doc_i_stub: NJV...DR    (cat 64, the documentno_dr)
    $RUN     = date('Y-m-d H:i:s');
    $DATE_GL = date('Y-m-d');
    $TOL     = 0.01;
    // bp => [name, cp subacct, current NEGATIVE, TARGET, offset acct (DR leg), reason for explanation]
    $TARGETS = [
        18335 => ['LORELIE JOPSON BACOY', 28084, -12449.92, 0.00,    52006, 'To record commission payable of Lorelie Jopson Bacoy at RP A 162012 for RP sales agent commission liquidated through advances without a recorded accrual.'],
        18992 => ['SALES AGENT',          30009, -29858.69, 0.00,    52006, 'To record commission payable of RP sales agent at RP A 162012 liquidated through advances without a recorded accrual.'],
        3314  => ['WALK IN',              3765,  -3960.00,  0.00,    42001, 'To reverse over-recorded other income and restore commission payable of Walk In at RP A 162012 (excess nonreleasal of commission).'],
        3028  => ['RUBY DOMINGO',         3366,  -2560.00,  0.00,    52006, 'To reverse excess nonreleasal and restore commission payable to zero for sales person (Ruby Domingo); lot 2-26-137 is on hold (blocked) so the commission stays unreleased.'],
        7939  => ['COLGUE,CAROL NOEBEN M.', 10328, -36000.00, 0.00, 52006, 'To reverse the erroneous LSP-cancellation reversal of an already-paid commission and restore commission payable to zero for Colgue, Carol Noeben M.; lot 2-19-31 sale was cancelled, the commission is retained by the agent (per IMS-13714: cancelled LSP no longer counted) and the reversed commission expense reinstated.'],
    ];
    $EXPECTED_TOTAL = 84828.61;            // 12,449.92 + 29,858.69 + 3,960.00 + 2,560.00 + 36,000.00  (all 5 Group B)

    $line  = str_repeat('=', 96);
    $say   = fn ($s) => print($s . PHP_EOL);
    $money = fn (float $x) => number_format($x, 2, '.', ',');
    $sub = "(SELECT child.ad_org_id FROM ad_org child JOIN (SELECT * FROM ad_org WHERE orgcode=$ORG) AS mother ON child.lft>=mother.lft AND child.ryt<=mother.ryt)";

    $glNet    = fn (int $sa) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(g.credit-g.debit),0),2) v FROM acct_gl g      WHERE g.gl_acct_id=$ACCT_CP AND g.ad_org_id IN $sub AND g.gl_subacct_id=$sa")->v;
    $cacheNet = fn (int $sa) => (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(b.credit-b.debit),0),2) v FROM acct_balance b WHERE b.gl_acct_id=$ACCT_CP AND b.ad_org_id IN $sub AND b.gl_subacct_id=$sa")->v;
    $fmt      = fn (string $p, int $n, string $s, int $len) => $p . str_pad((string) $n, $len, '0', STR_PAD_LEFT) . $s;

    $say($line);
    $say(' IMS-SCRIPT #13714 — Commission Payable (21136) GROUP B FINAL (native NJV + acct_doc explanation)');
    $say(' *** POSTS TO GENERAL LEDGER + cache — boss-approved.  Numbers from doc_i_stub. ***');
    $say(' Run: ' . $RUN . '   submod 4 (General Journal)   created=NULL  updated=' . $UPD);
    $say($line);

    // IDEMPOTENCY
    $already = (int) $db->selectOne("SELECT COUNT(*) n FROM acct_gl WHERE updated='$UPD' AND doc_i_submod_id=$SUBMOD AND ad_org_id IN $sub AND gl_acct_id=$ACCT_CP")->n;
    if ($already > 0) { $say(''); $say(" NO-OP — $already correction row(s) already carry updated='$UPD'. Nothing to do."); $say($line); return; }

    // stub positions
    $prStub = $db->selectOne("SELECT currentno, prefix, suffix, length FROM doc_i_stub WHERE doc_i_stub_id=$PR_STUB");
    $drStub = $db->selectOne("SELECT currentno, prefix, suffix, length FROM doc_i_stub WHERE doc_i_stub_id=$DR_STUB");
    if (!$prStub || !$drStub) throw new \RuntimeException('GJL doc_i_stub not found — ABORT.');
    $prNo = (int) $prStub->currentno; $drNo = (int) $drStub->currentno;
    $say(sprintf(' GJL stub start: PR %s%s(next %d)   DR %s..%s(next %d)', $prStub->prefix, $prStub->suffix, $prNo, $drStub->prefix, $drStub->suffix, $drNo));

    $say(''); $say(' PRE-CHECK (each agent must be exactly its known negative, GL==cache):');
    foreach ($TARGETS as $bp => [$name, $sa, $cur, $target, $offset, $reason]) {
        $gl = $glNet($sa); $ca = $cacheNet($sa);
        $say(sprintf('   %-22s sub=%-6s GL=%-12s cache=%-12s expect=%s', $name, $sa, $money($gl), $money($ca), $money($cur)));
        if (abs($gl - $cur) > $TOL) throw new \RuntimeException("$name GL " . $money($gl) . " != " . $money($cur) . " — drifted, ABORT.");
        if (abs($ca - $cur) > $TOL) throw new \RuntimeException("$name cache " . $money($ca) . " != " . $money($cur) . " — GL/cache disagree, ABORT.");
    }

    $say(''); $say(' APPLYING (transaction):');
    $db->beginTransaction();
    try {
        $grand = 0.0;
        foreach ($TARGETS as $bp => [$name, $sa, $cur, $target, $offset, $reason]) {
            $amt   = round($target - $cur, 2);                 // credit to CP
            $grand += $amt;
            $docPr = $fmt($prStub->prefix, $prNo, $prStub->suffix, (int) $prStub->length);
            $docDr = $fmt($drStub->prefix, $drNo, $drStub->suffix, (int) $drStub->length);
            $prNo++; $drNo++;

            // acct_doc — the header that carries the visible Description (with the IMS reference)
            $acctDocId = (int) $db->table('acct_doc')->insertGetId([
                'reference'      => 'Approved pre-entry',
                'date_reference' => $DATE_GL,
                'explanation'    => $reason,   // native-style wording; the IMS tag lives in `updated`, per Sir Mark
                'ad_org_id'      => $ORG,
                'created'        => null,
                'date_created'   => $RUN,
                'updated'        => $UPD,
                'date_updated'   => $RUN,
                'is_active'      => 1,
                'date_gl'        => $DATE_GL,
            ]);

            $refId = (int) $db->table('doc_t_reference_number')->insertGetId([
                'doc_i_submod_id' => $SUBMOD,
                'documentno_dr'   => $docDr,
                'date_draft'      => $DATE_GL,
                'documentno_pr'   => $docPr,
                'date_process'    => $DATE_GL,
                'ad_org_id'       => $ORG,
                'created'         => null,
                'date_created'    => $RUN,
                'updated'         => $UPD,
                'date_updated'    => $RUN,
                'is_active'       => 1,
            ]);

            // GL leg 1 — CR Commission Payable (agent subacct)
            $db->insert("INSERT INTO acct_gl (ad_org_id, gl_acct_id, documentno, date_gl, date_trans, debit, credit, created, date_created, updated, date_updated, gl_subacct_id, acct_doc_id, doc_i_submod_id, doc_t_reference_number_id)
                         VALUES (?, $ACCT_CP, ?, DATE(?), ?, 0, ?, NULL, ?, ?, ?, ?, ?, $SUBMOD, ?)",
                        [$ORG, $docPr, $DATE_GL, $RUN, $amt, $RUN, $UPD, $RUN, $sa, $acctDocId, $refId]);
            // GL leg 2 — DR offset (42001 Other Income for Walk In / 52006 Commission for the rest), no subacct
            $db->insert("INSERT INTO acct_gl (ad_org_id, gl_acct_id, documentno, date_gl, date_trans, debit, credit, created, date_created, updated, date_updated, gl_subacct_id, acct_doc_id, doc_i_submod_id, doc_t_reference_number_id)
                         VALUES (?, $offset, ?, DATE(?), ?, ?, 0, NULL, ?, ?, ?, NULL, ?, $SUBMOD, ?)",
                        [$ORG, $docPr, $DATE_GL, $RUN, $amt, $RUN, $UPD, $RUN, $acctDocId, $refId]);

            // CACHE mirror
            $db->insert("INSERT INTO acct_balance (gl_acct_id, date_gl, ad_org_id, created, date_created, updated, date_updated, debit, credit, gl_subacct_id, doc_i_submod_id)
                         VALUES ($ACCT_CP, DATE(?), ?, NULL, ?, ?, ?, 0, ?, ?, $SUBMOD)",
                        [$DATE_GL, $ORG, $RUN, $UPD, $RUN, $amt, $sa]);
            $db->insert("INSERT INTO acct_balance (gl_acct_id, date_gl, ad_org_id, created, date_created, updated, date_updated, debit, credit, gl_subacct_id, doc_i_submod_id)
                         VALUES ($offset, DATE(?), ?, NULL, ?, ?, ?, ?, 0, NULL, $SUBMOD)",
                        [$DATE_GL, $ORG, $RUN, $UPD, $RUN, $amt]);

            $say(sprintf('    %-22s %-13s DR %d / CR 21136 = %-11s -> target %s  (acct_doc %d)', $name, $docPr, $offset, $money($amt), $money($target), $acctDocId));
        }

        // bump the stubs so the app never reuses these numbers
        $db->update("UPDATE doc_i_stub SET currentno=$prNo, date_updated='$RUN', updated='$UPD' WHERE doc_i_stub_id=$PR_STUB");
        $db->update("UPDATE doc_i_stub SET currentno=$drNo, date_updated='$RUN', updated='$UPD' WHERE doc_i_stub_id=$DR_STUB");

        if (abs($grand - $EXPECTED_TOTAL) > $TOL) throw new \RuntimeException("Total " . $money($grand) . " != expected " . $money($EXPECTED_TOTAL) . ", ABORT.");

        $say(''); $say(' POST-CHECK (each agent lands on its TARGET in both books):');
        foreach ($TARGETS as $bp => [$name, $sa, $cur, $target, $offset, $reason]) {
            $gl = $glNet($sa); $ca = $cacheNet($sa);
            $say(sprintf('   %-22s GL=%-10s cache=%-10s (target %s)', $name, $money($gl), $money($ca), $money($target)));
            if (abs($gl - $target) > $TOL) throw new \RuntimeException("$name GL landed " . $money($gl) . " != target " . $money($target) . " — ABORT.");
            if (abs($ca - $target) > $TOL) throw new \RuntimeException("$name cache landed " . $money($ca) . " != target " . $money($target) . " — ABORT.");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $say(''); $say($line);
    $say(' SUCCESS — all 5 Group-B agents corrected via native General Journals (CP credited +' . $money($EXPECTED_TOTAL) . ').');
    $say(' IMS-13714 in each acct_doc.explanation. Aging untouched. Colgue = keep (cancelled LSP de-counted per ticket).');
    $say(' Rollback: GroupB_fix_CommissionPayable_162012_ROLLBACK.php');
    $say($line);
};
