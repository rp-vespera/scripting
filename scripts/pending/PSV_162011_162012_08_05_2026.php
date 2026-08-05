<?php // PSV_162011_162012_08_05_2026.php
// ============================================================================
// Project Stage Variance — LMC payout headers double-book the account-pair
// credit. Orgs 162011 + 162012 · mysql_secondary · 2026-08-03
//
//   268 payout headers written by 'WEB Accounting' between 2026-07-27 and
//   07-30 carry the account-pair amount in amt_total_payout and
//   amt_total_payout_net as well. Those two columns hold the LABOUR total, and
//   none of these documents has a labour payout line. The Project Stage
//   Variance scope-details subreport reads
//     amt_total_payout_net + amt_total_acctpair_credit_payout_net
//   so every one is counted twice — 951,853.09 across 104 projects.
//
//   Sets both labour totals to the sum of each document's own payout lines,
//   which is 0.00 on all 268. Account-pair columns, payout lines, LMC balances
//   and the ledger are untouched and asserted unchanged before commit.
//
//   Supersedes NLIO00927_162012_08_02_2026.php and NLIO00929_162012_08_02_2026.php
//   (payouts 55810 and 55812); both are in the set below and are SKIPPED if
//   those scripts already ran.
//
//   Java-written payouts are never touched: 55,243 follow the convention, and
//   the 1,034 carrying a labour total with no lines are all negative
//   cancellations, which is correct by convention.
//
//   PREREQUISITE — the mandays auto-draft writer must be DISABLED
//   (config mandays.lmc_draft_writer_enabled) and SalaryLmcPayoutDraftService
//   must already read the account-pair total in its "paid" calculation and its
//   duplicate guard. Zeroing amt_total_payout_net makes these scopes read as
//   unpaid to the current writer, which would re-draft all 268.
//
// WRITES     wip_t_lmc_payout — one UPDATE over the set, 2 amount columns each
//
//   Reads are issued once for the whole set and indexed by payout id, so the
//   script costs ~12 round-trips rather than ~2,415. Every gate and post-check
//   is still evaluated per row and still names the payout it failed on.
// ROLLBACK   PSV268_162011_162012_08_03_2026_rollback.php
// CASE       PSV268_162011_162012_08_03_2026.md
// ============================================================================
return function ($cmd) {
    // ---------------- CONFIGURATION ----------------
    $POSTED = date('Y-m-d');                 // stamped with the day it actually runs
    $IMS    = null;
    $TAG    = ($IMS !== null && $IMS !== '') ? '#IMS-' . $IMS : 'SCRIPT-WEB-' . $POSTED;
    $STAMP  = $POSTED . ' 00:00:00';

    $ORGS      = [162011, 162012];
    $WRITER    = 'WEB Accounting';
    $EXP_TOTAL = 951853.09;

    // payout_id => [audited labour total, original date_updated]
    $ROWS = [
        55699 => [3543.74, '2026-07-27 15:34:41'], // LMC0000099 162011
        55701 => [2074.04, '2026-07-27 15:35:15'], // LMC0000100 162011
        55702 => [1936.12, '2026-07-27 15:35:33'], // LMC0000101 162011
        55703 => [1892.25, '2026-07-27 15:35:51'], // LMC0000102 162011
        55705 => [917.79, '2026-07-27 15:36:25'], // LMC0000103 162011
        55708 => [10995.48, '2026-07-27 15:48:54'], // LMC0000104 162011
        55709 => [10532.27, '2026-07-27 15:49:12'], // LMC0000105 162011
        55710 => [3358.50, '2026-07-27 15:49:29'], // LMC0000106 162011
        55711 => [4044.75, '2026-07-27 15:49:46'], // LMC0000107 162011
        55722 => [24074.37, '2026-07-27 16:04:55'], // LMC0000108 162011
        55723 => [13454.59, '2026-07-27 16:05:12'], // LMC0000109 162011
        55724 => [5963.24, '2026-07-27 16:05:30'], // LMC0000110 162011
        55730 => [2803.95, '2026-07-27 16:07:13'], // LMC0000111 162011
        55732 => [667.50, '2026-07-27 16:07:47'], // LMC0000112 162011
        55733 => [696.00, '2026-07-27 16:08:04'], // LMC0000113 162011
        55736 => [1042.87, '2026-07-27 16:08:56'], // LMC0000114 162011
        55737 => [1028.25, '2026-07-27 16:09:13'], // LMC0000115 162011
        55738 => [24196.10, '2026-07-27 16:13:23'], // LMC0000116 162011
        55739 => [5849.74, '2026-07-27 16:13:41'], // LMC0000117 162011
        55740 => [5452.50, '2026-07-27 16:13:58'], // LMC0000118 162011
        55741 => [4754.26, '2026-07-27 16:14:15'], // LMC0000119 162011
        55747 => [1059.75, '2026-07-27 16:15:58'], // LMC0000120 162011
        55748 => [538.87, '2026-07-27 16:16:15'], // LMC0000121 162011
        55752 => [720.00, '2026-07-27 16:17:29'], // LMC0000122 162011
        55755 => [16226.03, '2026-07-27 16:33:34'], // LMC0000123 162011
        55756 => [13324.82, '2026-07-27 16:33:51'], // LMC0000124 162011
        55757 => [9702.37, '2026-07-27 16:34:08'], // LMC0000125 162011
        55758 => [7892.99, '2026-07-27 16:34:25'], // LMC0000126 162011
        55759 => [6833.75, '2026-07-27 16:34:42'], // LMC0000127 162011
        55760 => [6067.12, '2026-07-27 16:35:00'], // LMC0000128 162011
        55761 => [720.00, '2026-07-27 16:35:17'], // LMC0000129 162011
        55763 => [4661.51, '2026-07-27 16:35:51'], // LMC0000130 162011
        55767 => [540.00, '2026-07-27 16:36:59'], // LMC0000131 162011
        55768 => [9950.61, '2026-07-27 16:49:20'], // LMC0000132 162011
        55769 => [3600.00, '2026-07-27 16:49:37'], // LMC0000133 162011
        55770 => [13053.32, '2026-07-27 16:49:54'], // LMC0000134 162011
        55771 => [5325.36, '2026-07-27 16:50:12'], // LMC0000135 162011
        55776 => [3473.12, '2026-07-27 16:51:37'], // LMC0000136 162011
        55777 => [3203.95, '2026-07-27 16:51:55'], // LMC0000137 162011
        55778 => [2449.99, '2026-07-27 16:52:12'], // LMC0000138 162011
        55779 => [540.00, '2026-07-27 16:52:29'], // LMC0000139 162011
        55784 => [19397.75, '2026-07-27 17:06:19'], // LMC0000140 162011
        55785 => [1307.25, '2026-07-27 17:06:37'], // LMC0000141 162011
        55786 => [990.00, '2026-07-27 17:06:54'], // LMC0000142 162011
        55787 => [3770.77, '2026-07-27 17:07:11'], // LMC0000143 162011
        55788 => [2657.02, '2026-07-27 17:07:28'], // LMC0000144 162011
        55789 => [7240.92, '2026-07-27 17:07:45'], // LMC0000145 162011
        55793 => [1879.99, '2026-07-27 17:08:54'], // LMC0000146 162011
        55794 => [720.00, '2026-07-27 17:09:11'], // LMC0000147 162011
        55805 => [262.53, '2026-07-27 17:12:20'], // LMC0000148 162011
        55806 => [17825.35, '2026-07-28 09:02:18'], // LMC0000149 162011
        55807 => [8386.71, '2026-07-28 09:02:35'], // LMC0000150 162011
        55808 => [6969.37, '2026-07-28 09:02:53'], // LMC0000151 162011
        55811 => [4899.99, '2026-07-28 09:03:44'], // LMC0000152 162011
        55815 => [17349.00, '2026-07-28 09:08:40'], // LMC0000153 162011
        55816 => [9361.10, '2026-07-28 09:08:57'], // LMC0000154 162011
        55818 => [4576.50, '2026-07-28 09:09:32'], // LMC0000155 162011
        55823 => [1198.57, '2026-07-28 09:10:57'], // LMC0000156 162011
        55825 => [4791.74, '2026-07-28 09:18:20'], // LMC0000157 162011
        55828 => [2493.00, '2026-07-28 09:19:12'], // LMC0000158 162011
        55829 => [2143.49, '2026-07-28 09:19:29'], // LMC0000159 162011
        55830 => [770.00, '2026-07-28 09:19:46'], // LMC0000160 162011
        55841 => [6663.24, '2026-07-29 16:54:48'], // LMC0000161 162011
        55842 => [5437.80, '2026-07-29 16:55:13'], // LMC0000162 162011
        55843 => [3481.80, '2026-07-29 16:55:37'], // LMC0000163 162011
        55844 => [1590.00, '2026-07-29 16:55:59'], // LMC0000164 162011
        55845 => [1538.39, '2026-07-29 16:56:21'], // LMC0000165 162011
        55847 => [1370.00, '2026-07-29 16:57:06'], // LMC0000166 162011
        55849 => [770.00, '2026-07-29 16:57:53'], // LMC0000167 162011
        55850 => [540.00, '2026-07-29 16:58:17'], // LMC0000168 162011
        55851 => [540.00, '2026-07-29 16:58:41'], // LMC0000169 162011
        55853 => [20226.99, '2026-07-30 09:48:38'], // LMC0000170 162011
        55854 => [9216.36, '2026-07-30 09:48:56'], // LMC0000171 162011
        55855 => [7117.49, '2026-07-30 09:49:13'], // LMC0000172 162011
        55856 => [3780.00, '2026-07-30 09:49:30'], // LMC0000173 162011
        55857 => [720.00, '2026-07-30 09:49:48'], // LMC0000174 162011
        55858 => [3248.59, '2026-07-30 09:50:05'], // LMC0000175 162011
        55860 => [1890.00, '2026-07-30 09:50:40'], // LMC0000176 162011
        55864 => [1260.00, '2026-07-30 09:52:27'], // LMC0000177 162011
        55865 => [1260.00, '2026-07-30 09:52:45'], // LMC0000178 162011
        55866 => [1080.00, '2026-07-30 09:53:02'], // LMC0000179 162011
        55867 => [770.00, '2026-07-30 09:53:20'], // LMC0000180 162011
        55868 => [22354.50, '2026-07-30 10:02:40'], // LMC0000181 162011
        55869 => [11964.78, '2026-07-30 10:02:58'], // LMC0000182 162011
        55870 => [5580.00, '2026-07-30 10:03:15'], // LMC0000183 162011
        55871 => [5345.99, '2026-07-30 10:03:33'], // LMC0000184 162011
        55872 => [2700.00, '2026-07-30 10:03:51'], // LMC0000185 162011
        55875 => [2212.12, '2026-07-30 10:04:43'], // LMC0000186 162011
        55876 => [1309.99, '2026-07-30 10:05:01'], // LMC0000187 162011
        55878 => [810.00, '2026-07-30 10:05:35'], // LMC0000188 162011
        55879 => [720.00, '2026-07-30 10:05:52'], // LMC0000189 162011
        55880 => [720.00, '2026-07-30 10:06:10'], // LMC0000190 162011
        55884 => [298.75, '2026-07-30 10:07:20'], // LMC0000191 162011
        55885 => [270.00, '2026-07-30 10:07:38'], // LMC0000192 162011
        55886 => [244.12, '2026-07-30 10:08:04'], // LMC0000193 162011
        55887 => [14575.19, '2026-07-30 10:21:53'], // LMC0000194 162011
        55888 => [10734.30, '2026-07-30 10:22:10'], // LMC0000195 162011
        55889 => [7859.97, '2026-07-30 10:22:27'], // LMC0000196 162011
        55890 => [4320.00, '2026-07-30 10:22:44'], // LMC0000197 162011
        55891 => [3779.99, '2026-07-30 10:23:01'], // LMC0000198 162011
        55894 => [2498.62, '2026-07-30 10:24:09'], // LMC0000199 162011
        55899 => [495.00, '2026-07-30 10:25:51'], // LMC0000200 162011
        55900 => [8388.48, '2026-07-30 10:31:46'], // LMC0000201 162011
        55901 => [7331.36, '2026-07-30 10:32:02'], // LMC0000202 162011
        55902 => [6918.65, '2026-07-30 10:32:19'], // LMC0000203 162011
        55904 => [3021.61, '2026-07-30 10:32:53'], // LMC0000204 162011
        55905 => [2970.00, '2026-07-30 10:33:10'], // LMC0000205 162011
        55907 => [2880.00, '2026-07-30 10:33:44'], // LMC0000206 162011
        55910 => [990.00, '2026-07-30 10:34:35'], // LMC0000207 162011
        55913 => [5588.00, '2026-07-30 10:47:57'], // LMC0000208 162011
        55914 => [4704.49, '2026-07-30 10:48:14'], // LMC0000209 162011
        55915 => [3819.99, '2026-07-30 10:48:31'], // LMC0000210 162011
        55916 => [2244.87, '2026-07-30 10:48:48'], // LMC0000211 162011
        55919 => [628.50, '2026-07-30 10:49:40'], // LMC0000212 162011
        55920 => [540.00, '2026-07-30 10:49:57'], // LMC0000213 162011
        55921 => [540.00, '2026-07-30 10:50:14'], // LMC0000214 162011
        55922 => [540.00, '2026-07-30 10:50:31'], // LMC0000215 162011
        55923 => [8932.85, '2026-07-30 11:06:13'], // LMC0000216 162011
        55924 => [5986.48, '2026-07-30 11:06:30'], // LMC0000217 162011
        55925 => [5540.17, '2026-07-30 11:06:47'], // LMC0000218 162011
        55926 => [5400.00, '2026-07-30 11:07:04'], // LMC0000219 162011
        55931 => [1260.00, '2026-07-30 11:08:29'], // LMC0000220 162011
        55932 => [241.87, '2026-07-30 11:08:47'], // LMC0000221 162011
        55934 => [1260.00, '2026-07-30 11:09:21'], // LMC0000222 162011
        55935 => [1080.00, '2026-07-30 11:09:38'], // LMC0000223 162011
        55936 => [720.00, '2026-07-30 11:09:55'], // LMC0000224 162011
        55937 => [720.00, '2026-07-30 11:10:12'], // LMC0000225 162011
        55938 => [540.00, '2026-07-30 11:10:29'], // LMC0000226 162011
        55940 => [270.00, '2026-07-30 11:11:03'], // LMC0000227 162011
        55942 => [8999.99, '2026-07-30 13:07:13'], // LMC0000228 162011
        55943 => [7576.93, '2026-07-30 13:07:31'], // LMC0000229 162011
        55944 => [6273.40, '2026-07-30 13:07:48'], // LMC0000230 162011
        55946 => [2666.49, '2026-07-30 13:08:23'], // LMC0000231 162011
        55949 => [720.00, '2026-07-30 13:09:14'], // LMC0000232 162011
        55950 => [720.00, '2026-07-30 13:09:31'], // LMC0000233 162011
        55953 => [1030.85, '2026-07-30 13:10:23'], // LMC0000234 162011
        55955 => [720.00, '2026-07-30 13:10:57'], // LMC0000235 162011
        55956 => [540.00, '2026-07-30 13:11:14'], // LMC0000236 162011
        55959 => [526.25, '2026-07-30 13:12:05'], // LMC0000237 162011
        55961 => [9430.10, '2026-07-30 13:29:29'], // LMC0000238 162011
        55962 => [7404.39, '2026-07-30 13:29:47'], // LMC0000239 162011
        55963 => [6976.44, '2026-07-30 13:30:05'], // LMC0000240 162011
        55964 => [5219.99, '2026-07-30 13:30:22'], // LMC0000241 162011
        55966 => [1080.00, '2026-07-30 13:30:56'], // LMC0000242 162011
        55967 => [540.00, '2026-07-30 13:31:14'], // LMC0000243 162011
        55968 => [720.00, '2026-07-30 13:31:31'], // LMC0000244 162011
        55969 => [1260.00, '2026-07-30 13:31:48'], // LMC0000245 162011
        55970 => [720.00, '2026-07-30 13:32:08'], // LMC0000246 162011
        55975 => [2226.62, '2026-07-30 13:33:32'], // LMC0000247 162011
        55978 => [1620.00, '2026-07-30 13:34:23'], // LMC0000248 162011
        55980 => [770.00, '2026-07-30 13:34:58'], // LMC0000249 162011
        55982 => [4.50, '2026-07-30 13:35:32'], // LMC0000250 162011
        55700 => [2305.78, '2026-07-27 15:34:58'], // NLMC0008320 162012
        55704 => [1463.38, '2026-07-27 15:36:08'], // NLMC0008321 162012
        55712 => [4982.67, '2026-07-27 15:50:04'], // NLMC0008322 162012
        55713 => [4206.56, '2026-07-27 15:50:21'], // NLMC0008323 162012
        55714 => [4106.69, '2026-07-27 15:50:38'], // NLMC0008324 162012
        55715 => [3814.03, '2026-07-27 15:50:55'], // NLMC0008325 162012
        55716 => [3345.04, '2026-07-27 15:51:12'], // NLMC0008326 162012
        55725 => [5203.91, '2026-07-27 16:05:47'], // NLMC0008327 162012
        55726 => [4419.93, '2026-07-27 16:06:04'], // NLMC0008328 162012
        55727 => [3970.76, '2026-07-27 16:06:21'], // NLMC0008329 162012
        55728 => [3885.08, '2026-07-27 16:06:39'], // NLMC0008330 162012
        55729 => [3464.51, '2026-07-27 16:06:56'], // NLMC0008331 162012
        55731 => [1846.77, '2026-07-27 16:07:30'], // NLMC0008332 162012
        55734 => [1309.99, '2026-07-27 16:08:22'], // NLMC0008333 162012
        55735 => [1273.99, '2026-07-27 16:08:39'], // NLMC0008334 162012
        55742 => [3986.51, '2026-07-27 16:14:32'], // NLMC0008335 162012
        55743 => [3944.88, '2026-07-27 16:14:49'], // NLMC0008336 162012
        55744 => [3767.14, '2026-07-27 16:15:06'], // NLMC0008338 162012
        55745 => [3461.14, '2026-07-27 16:15:24'], // NLMC0008339 162012
        55746 => [1620.00, '2026-07-27 16:15:41'], // NLMC0008340 162012
        55749 => [1538.39, '2026-07-27 16:16:38'], // NLMC0008341 162012
        55750 => [1523.95, '2026-07-27 16:16:55'], // NLMC0008342 162012
        55751 => [1035.00, '2026-07-27 16:17:12'], // NLMC0008344 162012
        55753 => [526.50, '2026-07-27 16:17:46'], // NLMC0008345 162012
        55754 => [525.37, '2026-07-27 16:18:03'], // NLMC0008346 162012
        55762 => [5195.88, '2026-07-27 16:35:34'], // NLMC0008352 162012
        55764 => [4657.16, '2026-07-27 16:36:08'], // NLMC0008353 162012
        55765 => [4011.91, '2026-07-27 16:36:25'], // NLMC0008354 162012
        55766 => [770.00, '2026-07-27 16:36:42'], // NLMC0008355 162012
        55772 => [5271.59, '2026-07-27 16:50:29'], // NLMC0008356 162012
        55773 => [5016.98, '2026-07-27 16:50:46'], // NLMC0008357 162012
        55774 => [4658.94, '2026-07-27 16:51:03'], // NLMC0008358 162012
        55775 => [4501.89, '2026-07-27 16:51:20'], // NLMC0008359 162012
        55780 => [540.00, '2026-07-27 16:52:46'], // NLMC0008360 162012
        55781 => [270.00, '2026-07-27 16:53:04'], // NLMC0008361 162012
        55782 => [270.00, '2026-07-27 16:53:21'], // NLMC0008362 162012
        55783 => [270.00, '2026-07-27 16:53:38'], // NLMC0008363 162012
        55790 => [5933.98, '2026-07-27 17:08:02'], // NLMC0008364 162012
        55791 => [5633.06, '2026-07-27 17:08:19'], // NLMC0008365 162012
        55792 => [5479.76, '2026-07-27 17:08:36'], // NLMC0008366 162012
        55795 => [540.00, '2026-07-27 17:09:28'], // NLMC0008367 162012
        55796 => [405.00, '2026-07-27 17:09:45'], // NLMC0008368 162012
        55797 => [405.00, '2026-07-27 17:10:02'], // NLMC0008369 162012
        55798 => [270.00, '2026-07-27 17:10:19'], // NLMC0008370 162012
        55799 => [270.00, '2026-07-27 17:10:36'], // NLMC0008371 162012
        55800 => [270.00, '2026-07-27 17:10:54'], // NLMC0008372 162012
        55801 => [270.00, '2026-07-27 17:11:11'], // NLMC0008373 162012
        55802 => [270.00, '2026-07-27 17:11:28'], // NLMC0008374 162012
        55803 => [270.00, '2026-07-27 17:11:45'], // NLMC0008375 162012
        55804 => [270.00, '2026-07-27 17:12:02'], // NLMC0008376 162012
        55809 => [5105.12, '2026-07-28 09:03:10'], // NLMC0008377 162012
        55810 => [5098.82, '2026-07-28 09:03:27'], // NLMC0008378 162012
        55812 => [4804.97, '2026-07-28 09:04:01'], // NLMC0008379 162012
        55813 => [4499.20, '2026-07-28 09:04:18'], // NLMC0008380 162012
        55814 => [2649.06, '2026-07-28 09:04:35'], // NLMC0008381 162012
        55817 => [6021.15, '2026-07-28 09:09:14'], // NLMC0008382 162012
        55819 => [4536.18, '2026-07-28 09:09:49'], // NLMC0008383 162012
        55820 => [3987.32, '2026-07-28 09:10:06'], // NLMC0008384 162012
        55821 => [3975.26, '2026-07-28 09:10:23'], // NLMC0008385 162012
        55822 => [3707.20, '2026-07-28 09:10:40'], // NLMC0008386 162012
        55824 => [1068.75, '2026-07-28 09:11:14'], // NLMC0008387 162012
        55826 => [4172.15, '2026-07-28 09:18:38'], // NLMC0008388 162012
        55827 => [3442.48, '2026-07-28 09:18:55'], // NLMC0008389 162012
        55831 => [766.77, '2026-07-28 09:20:03'], // NLMC0008390 162012
        55832 => [383.39, '2026-07-28 09:20:20'], // NLMC0008391 162012
        55846 => [1420.16, '2026-07-29 16:56:44'], // NLMC0008401 162012
        55848 => [1306.77, '2026-07-29 16:57:27'], // NLMC0008402 162012
        55852 => [540.00, '2026-07-29 16:59:06'], // NLMC0008403 162012
        55859 => [1892.65, '2026-07-30 09:50:23'], // NLMC0008404 162012
        55861 => [1890.00, '2026-07-30 09:50:58'], // NLMC0008405 162012
        55862 => [1822.49, '2026-07-30 09:51:15'], // NLMC0008406 162012
        55863 => [1420.15, '2026-07-30 09:52:10'], // NLMC0008407 162012
        55873 => [2429.99, '2026-07-30 10:04:08'], // NLMC0008408 162012
        55874 => [2386.77, '2026-07-30 10:04:26'], // NLMC0008409 162012
        55877 => [1193.38, '2026-07-30 10:05:18'], // NLMC0008410 162012
        55881 => [540.00, '2026-07-30 10:06:28'], // NLMC0008411 162012
        55882 => [540.00, '2026-07-30 10:06:45'], // NLMC0008412 162012
        55883 => [540.00, '2026-07-30 10:07:03'], // NLMC0008413 162012
        55892 => [2994.26, '2026-07-30 10:23:18'], // NLMC0008414 162012
        55893 => [2543.38, '2026-07-30 10:23:52'], // NLMC0008415 162012
        55895 => [2159.99, '2026-07-30 10:24:26'], // NLMC0008416 162012
        55896 => [2138.38, '2026-07-30 10:24:43'], // NLMC0008417 162012
        55897 => [1868.38, '2026-07-30 10:25:00'], // NLMC0008418 162012
        55898 => [1306.77, '2026-07-30 10:25:17'], // NLMC0008419 162012
        55903 => [4522.49, '2026-07-30 10:32:36'], // NLMC0008420 162012
        55906 => [2926.77, '2026-07-30 10:33:27'], // NLMC0008421 162012
        55908 => [1463.38, '2026-07-30 10:34:01'], // NLMC0008422 162012
        55909 => [1193.38, '2026-07-30 10:34:18'], // NLMC0008423 162012
        55911 => [270.00, '2026-07-30 10:34:52'], // NLMC0008424 162012
        55917 => [1306.77, '2026-07-30 10:49:05'], // NLMC0008425 162012
        55918 => [810.00, '2026-07-30 10:49:22'], // NLMC0008426 162012
        55927 => [3974.34, '2026-07-30 11:07:21'], // NLMC0008427 162012
        55928 => [2996.92, '2026-07-30 11:07:38'], // NLMC0008428 162012
        55929 => [2543.38, '2026-07-30 11:07:55'], // NLMC0008429 162012
        55930 => [2160.00, '2026-07-30 11:08:12'], // NLMC0008430 162012
        55933 => [1463.38, '2026-07-30 11:09:03'], // NLMC0008431 162012
        55939 => [540.00, '2026-07-30 11:10:46'], // NLMC0008432 162012
        55941 => [270.00, '2026-07-30 11:11:20'], // NLMC0008433 162012
        55945 => [3037.49, '2026-07-30 13:08:05'], // NLMC0008434 162012
        55947 => [2003.38, '2026-07-30 13:08:40'], // NLMC0008435 162012
        55948 => [1733.38, '2026-07-30 13:08:57'], // NLMC0008436 162012
        55951 => [1193.38, '2026-07-30 13:09:48'], // NLMC0008437 162012
        55952 => [1080.00, '2026-07-30 13:10:05'], // NLMC0008438 162012
        55954 => [818.77, '2026-07-30 13:10:40'], // NLMC0008439 162012
        55957 => [540.00, '2026-07-30 13:11:31'], // NLMC0008440 162012
        55958 => [540.00, '2026-07-30 13:11:48'], // NLMC0008441 162012
        55960 => [270.00, '2026-07-30 13:12:22'], // NLMC0008442 162012
        55965 => [4390.15, '2026-07-30 13:30:39'], // NLMC0008443 162012
        55971 => [3723.27, '2026-07-30 13:32:25'], // NLMC0008444 162012
        55972 => [2813.38, '2026-07-30 13:32:42'], // NLMC0008445 162012
        55973 => [2543.38, '2026-07-30 13:32:59'], // NLMC0008446 162012
        55974 => [2319.27, '2026-07-30 13:33:16'], // NLMC0008447 162012
        55976 => [2164.40, '2026-07-30 13:33:49'], // NLMC0008448 162012
        55977 => [1960.15, '2026-07-30 13:34:06'], // NLMC0008449 162012
        55979 => [1080.00, '2026-07-30 13:34:41'], // NLMC0008450 162012
        55981 => [551.25, '2026-07-30 13:35:15'], // NLMC0008451 162012
    ];

    $db = \DB::connection('mysql_secondary'); set_time_limit(0);
    $RUN = date('Y-m-d H:i:s'); $L = str_repeat('=', 92);
    $say = fn($s) => print($s . PHP_EOL);
    $m   = fn($x) => number_format((float) $x, 2, '.', ',');
    $schema = (string) $db->selectOne('SELECT DATABASE() d')->d;

    $t0 = microtime(true);
    for ($i = 0; $i < 5; $i++) $db->selectOne('SELECT 1 x');
    $rtt = (microtime(true) - $t0) / 5 * 1000;

    // Every read below is issued once for the whole set and indexed by payout id.
    // The per-row assertions are unchanged; only the number of round-trips is.
    $IDS  = array_map('intval', array_keys($ROWS));
    $IDPH = implode(',', array_fill(0, count($IDS), '?'));

    $headersFor = function (array $ids) use ($db) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($db->select("SELECT wip_t_lmc_payout_id id, documentno, docstatus, ad_org_id,
                                     amt_total_payout gross, amt_total_payout_net net,
                                     amt_total_payout_tax tax, amt_total_payout_wtax wtax,
                                     amt_total_acctpair_credit_payout ap_gross,
                                     amt_total_acctpair_credit_payout_net ap_net,
                                     COALESCE(created, '') created, COALESCE(updated, '') updated
                                FROM wip_t_lmc_payout
                               WHERE wip_t_lmc_payout_id IN ({$ph})", $ids) as $r) {
            $out[(int) $r->id] = $r;
        }

        return $out;
    };

    $labourFor = function (array $ids) use ($db) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = array_fill_keys($ids, 0.0);
        foreach ($db->select("SELECT wip_t_lmc_payout_id id,
                                     ROUND(IFNULL(SUM(IFNULL(amt,0) - IFNULL(l_amt_returned,0)),0),2) v
                                FROM wip_t_lmc_payoutline
                               WHERE wip_t_lmc_payout_id IN ({$ph})
                               GROUP BY wip_t_lmc_payout_id", $ids) as $r) {
            $out[(int) $r->id] = (float) $r->v;
        }

        return $out;
    };

    $acctpairFor = function (array $ids) use ($db) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = array_fill_keys($ids, 0.0);
        foreach ($db->select("SELECT wip_t_payout_id id,
                                     ROUND(IFNULL(SUM(IFNULL(amt_acctpair_credit_payout,0)
                                                    - IFNULL(l_amt_acctpair_credit_payout_ret,0)),0),2) v
                                FROM wip_t_lmc_payoutline_acctpair_credit
                               WHERE wip_t_payout_id IN ({$ph})
                               GROUP BY wip_t_payout_id", $ids) as $r) {
            $out[(int) $r->id] = (float) $r->v;
        }

        return $out;
    };

    $glSnapshot = function () use ($db, $ORGS) {
        $ph = implode(',', array_fill(0, count($ORGS), '?'));
        $r  = $db->selectOne("SELECT COUNT(*) n, ROUND(IFNULL(SUM(debit-credit),0),2) v
                                FROM acct_gl WHERE ad_org_id IN ({$ph})", $ORGS);
        return [(int) $r->n, (float) $r->v];
    };

    $say($L);
    $say(' Project Stage Variance — ' . count($ROWS) . ' payout headers double-booking the account-pair credit');
    $say(' Run ' . $RUN . ' · ' . $schema . ' · orgs ' . implode('/', $ORGS) . ' · tag ' . $TAG . ' · COMMIT');
    $say(sprintf(' Link %.1f ms per round-trip · 12 data round-trips (+6 probe/schema)', $rtt));
    $say($L);

    // ---------------- MAP INTEGRITY · the approved set must be intact ----------------
    $mapTotal = 0.0; foreach ($ROWS as [$a, $d]) $mapTotal += $a;
    if (count($ROWS) !== 268)
        throw new \RuntimeException('GATE FAILED: the audited map holds ' . count($ROWS)
            . ' payouts, approved set is 268. ABORT.');
    if (abs($mapTotal - $EXP_TOTAL) > 0.01)
        throw new \RuntimeException('GATE FAILED: the audited map sums to ' . $m($mapTotal)
            . ', approved total is ' . $m($EXP_TOTAL) . '. ABORT.');
    $say(' MAP    268 payouts · ' . $m($mapTotal) . ' matches the approved total ..... PASS');

    // ---------------- GATES · every row, before anything is written ----------------
    $isTagged = fn($u) => (bool) preg_match('/^(SCRIPT-WEB-|#IMS-|IMS_SCRIPT_WEB-)/', (string) $u);
    $todo = []; $skipped = 0; $auditTotal = 0.0;

    $headers  = $headersFor($IDS);
    $labourAt = $labourFor($IDS);
    $apLineAt = $acctpairFor($IDS);

    foreach ($ROWS as $id => [$amt, $origDt]) {
        $h = $headers[$id] ?? null;
        if (! $h) throw new \RuntimeException("GATE FAILED: payout $id not found. ABORT.");

        $isZero = abs((float) $h->gross) <= 0.001 && abs((float) $h->net) <= 0.001;
        $tagged = $isTagged($h->updated);

        if ($isZero && $tagged) { $skipped++; continue; }
        if ($isZero !== $tagged)
            throw new \RuntimeException("GATE FAILED: payout $id is "
                . ($isZero ? 'zeroed but untagged' : 'tagged but not zeroed')
                . ' — a partial run needs rolling back before this can proceed. ABORT.');

        if ($h->created !== $WRITER)
            throw new \RuntimeException("GATE FAILED: payout $id created='{$h->created}', expected '$WRITER'. "
                . 'Only backend-written headers carry this defect. ABORT.');
        if ($h->docstatus !== 'PR' || ! in_array((int) $h->ad_org_id, $ORGS, true))
            throw new \RuntimeException("GATE FAILED: payout $id is {$h->docstatus} / org {$h->ad_org_id}. ABORT.");
        if (abs((float) $h->gross - $amt) > 0.001 || abs((float) $h->net - $amt) > 0.001)
            throw new \RuntimeException("GATE FAILED: payout $id labour totals are " . $m($h->gross) . ' / '
                . $m($h->net) . ', audited ' . $m($amt) . ' on both. ABORT.');
        if (abs((float) $h->ap_gross - $amt) > 0.001 || abs((float) $h->ap_net - $amt) > 0.001)
            throw new \RuntimeException("GATE FAILED: payout $id account-pair is " . $m($h->ap_gross) . ' / '
                . $m($h->ap_net) . ', expected ' . $m($amt) . '. ABORT.');
        if (abs((float) $h->tax) > 0.001 || abs((float) $h->wtax) > 0.001)
            throw new \RuntimeException("GATE FAILED: payout $id carries tax " . $m($h->tax) . ' / wtax '
                . $m($h->wtax) . '; the labour totals are not a clean copy. ABORT.');

        $lab = $labourAt[$id];
        if (abs($lab) > 0.01)
            throw new \RuntimeException("GATE FAILED: payout $id has labour lines totalling " . $m($lab)
                . '; its header total may be legitimate. ABORT.');

        $apl = $apLineAt[$id];
        if (abs($apl - $amt) > 0.01)
            throw new \RuntimeException("GATE FAILED: payout $id account-pair lines total " . $m($apl)
                . ', expected ' . $m($amt) . '. ABORT.');

        $todo[$id] = $amt; $auditTotal += $amt;
    }

    $say(sprintf('        %d audited · %d to fix · %d already applied', count($ROWS), count($todo), $skipped));
    $say('        overstated by ' . $m($auditTotal));
    $say(' GATES  writer · status · header totals · account-pair · tax · no labour lines · lines ..... PASS');

    if ($todo === []) {
        $say('');
        $say(' NOTHING TO DO — all ' . count($ROWS) . ' already applied.');
        $say($L);
        if (isset($cmd)) $cmd->info('PSV268: nothing to do.');
        return;
    }

    $say('');
    $say('   PLAN — ' . count($todo) . ' rows on wip_t_lmc_payout in one UPDATE, 2 amount columns each');
    $say('   amt_total_payout / amt_total_payout_net  ->  0.00');
    $say('   account-pair columns, payout lines and acct_gl untouched');

    // ---------------- APPLY ----------------
    $todoIds = array_map('intval', array_keys($todo));
    $todoPh  = implode(',', array_fill(0, count($todoIds), '?'));

    $db->beginTransaction();
    try {
        // Both snapshots are read inside the transaction so REPEATABLE READ gives
        // them one consistent view. Taken outside it, a journal entry another user
        // commits while this runs reads as movement this script caused.
        [$glCountBefore, $glSumBefore] = $glSnapshot();
        $apBeforeAt = $acctpairFor($todoIds);
        $apBefore = 0.0; foreach ($todoIds as $id) $apBefore += $apBeforeAt[$id];

        $done = $db->update("UPDATE wip_t_lmc_payout
                                SET amt_total_payout = 0.00, amt_total_payout_net = 0.00,
                                    updated = ?, date_updated = ?
                              WHERE wip_t_lmc_payout_id IN ({$todoPh})",
                            array_merge([$TAG, $STAMP], $todoIds));
        if ($done !== count($todo))
            throw new \RuntimeException("UPDATE touched $done rows, expected " . count($todo) . '. ABORT.');

        // ---------------- POST-CHECKS ----------------
        $after     = $headersFor($todoIds);
        $labAfter  = $labourFor($todoIds);
        $apAfterAt = $acctpairFor($todoIds);

        foreach ($todo as $id => $amt) {
            $a = $after[$id] ?? null;
            if (! $a)
                throw new \RuntimeException("POST-CHECK FAILED: payout $id not readable after update. ABORT.");
            if (abs((float) $a->gross) > 0.001 || abs((float) $a->net) > 0.001)
                throw new \RuntimeException("POST-CHECK FAILED: payout $id labour totals are "
                    . $m($a->gross) . ' / ' . $m($a->net) . ', expected 0.00. ABORT.');
            if (abs((float) $a->ap_gross - $amt) > 0.001 || abs((float) $a->ap_net - $amt) > 0.001)
                throw new \RuntimeException("POST-CHECK FAILED: payout $id account-pair columns moved. ABORT.");
            if ($a->updated !== $TAG)
                throw new \RuntimeException("POST-CHECK FAILED: payout $id updated='{$a->updated}'. ABORT.");
            if (abs($labAfter[$id]) > 0.01)
                throw new \RuntimeException("POST-CHECK FAILED: payout $id payout lines moved. ABORT.");
        }

        $apAfter = 0.0; foreach ($todoIds as $id) $apAfter += $apAfterAt[$id];
        if (abs($apAfter - $apBefore) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: account-pair lines moved by '
                . $m($apAfter - $apBefore) . '. ABORT.');

        [$glCountAfter, $glSumAfter] = $glSnapshot();
        if ($glCountAfter !== $glCountBefore || abs($glSumAfter - $glSumBefore) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: acct_gl moved. It must never be written. ABORT.');

        $ph   = implode(',', array_fill(0, count($ROWS), '?'));
        $left = (float) $db->selectOne("SELECT ROUND(IFNULL(SUM(amt_total_payout_net),0),2) v
                                          FROM wip_t_lmc_payout WHERE wip_t_lmc_payout_id IN ({$ph})",
                                       array_keys($ROWS))->v;
        if (abs($left) > 0.01)
            throw new \RuntimeException('POST-CHECK FAILED: labour totals across the set still sum to '
                . $m($left) . ', expected 0.00. ABORT.');

        $db->commit();

        $say('');
        $say(' POST-CHECK  ' . $done . ' headers zeroed and tagged ' . $TAG);
        $say('             account-pair ' . $m($apAfter) . ' unchanged · payout lines unchanged · acct_gl unchanged');
        $say('             overstatement removed  ' . $m($auditTotal)
             . ($skipped ? '  (' . $skipped . ' applied earlier)' : ''));
        $say('             set total ' . $m($EXP_TOTAL) . ' across ' . count($ROWS) . ' documents');
        $say(' COMPLETE — reprint Project Stage Variance for orgs ' . implode(' and ', $ORGS));
        $say($L);
        if (isset($cmd)) $cmd->info('PSV268 applied: ' . $done . ' headers, ' . $m($auditTotal) . ' removed.');
    } catch (\Throwable $e) { $db->rollBack(); throw $e; }
};
