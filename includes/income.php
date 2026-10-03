<?php

/*
|--------------------------------------------------------------------------
| INCOME -- shared page body
|--------------------------------------------------------------------------
|
| Every POS sale the company has taken, with what was sold in each and a
| summary across cash, GCash and utang.
|
| Two roles need it. Retail Professional has Finance Staff who watch the
| takings; Retail Starter has no such seat, so its owner does. Both entry
| points are thin wrappers around this file:
|
|     finance/income.php  requireRole(['finance'])
|     admin/income.php    requireRole(['admin'])
|
| The caller authenticates, sets $companyId, and names its own chrome in
| $MODULE_HEADER / $MODULE_FOOTER. Both sit one directory below the webroot,
| so the "../" asset paths inside resolve the same from either.
|
*/

if (!isset($companyId, $MODULE_HEADER, $MODULE_FOOTER)) {
    http_response_code(403);
    exit('This page cannot be opened directly.');
}

include($MODULE_HEADER);


/*
|--------------------------------------------------------------------------
| LOAD POS SALES
|--------------------------------------------------------------------------
|
| sales       = main POS transaction
| sale_items  = products sold
| products    = product information
|
*/

$incomeRows = [];

$sql = "
    SELECT
        s.sale_id,
        s.total_amount,
        s.tax_amount,
        s.cash_received,
        s.change_amount,
        s.payment_method,
        s.sale_date,

        GROUP_CONCAT(
            CONCAT(
                p.product_name,
                ' × ',
                si.quantity
            )
            ORDER BY p.product_name
            SEPARATOR ', '
        ) AS products_sold,

        COUNT(si.sale_item_id) AS item_count

    FROM sales s

    LEFT JOIN sale_items si
        ON si.sale_id = s.sale_id AND si.company_id = s.company_id

    LEFT JOIN products p
        ON p.product_id = si.product_id AND p.company_id = s.company_id

    WHERE s.company_id = " . (int) $companyId . "

    GROUP BY
        s.sale_id,
        s.total_amount,
        s.tax_amount,
        s.cash_received,
        s.change_amount,
        s.payment_method,
        s.sale_date

    ORDER BY s.sale_id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $incomeRows[] = $row;
    }

}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$totalSales = 0;
$totalTax = 0;
$totalTransactions = count($incomeRows);

$totalCash = 0;
$totalGCash = 0;
$totalUtang = 0;

foreach ($incomeRows as $row) {

    $amount = (float) $row['total_amount'];

    $totalSales += $amount;
    $totalTax += (float) $row['tax_amount'];

    $paymentMethod = $row['payment_method'] ?? 'Cash';

    if ($paymentMethod === 'Cash') {
        $totalCash += $amount;
    }

    if ($paymentMethod === 'GCash') {
        $totalGCash += $amount;
    }

    if ($paymentMethod === 'Utang') {
        $totalUtang += $amount;
    }
}

?>

<style>
    .dashboard-card {
        border: none;
        border-radius: 18px;
        transition: .3s;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .05);
    }

    .dashboard-card:hover {
        transform: translateY(-4px);
    }

    .card-icon {
        width: 55px;
        height: 55px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #fff;
    }

    .bg-navy {
        background: #00224c;
    }

    .bg-yellow {
        background: #fbbd23;
    }

    .bg-green {
        background: #198754;
    }

    .bg-red {
        background: #dc3545;
    }

    .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: #00224c;
    }

    .section-card {
        border: none;
        border-radius: 18px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .05);
    }

    .request-page {
        padding: 10px 4px 30px;
    }

    .request-title {
        color: #00224c;
        font-size: 34px;
        font-weight: 700;
        margin-bottom: 2px;
    }

    .request-subtitle {
        color: #64748b;
        margin-bottom: 20px;
    }

    /* DataTable search styling */
    .dataTables_wrapper .dataTables_filter {
        float: none;
        text-align: left;
        padding: 18px 18px 12px;
    }

    .dataTables_wrapper .dataTables_filter label {
        width: 100%;
        font-size: 0;
    }

    .dataTables_wrapper .dataTables_filter input {
        margin-left: 0 !important;
        width: 430px;
        max-width: 100%;
        height: 43px;
        border: 1px solid #d9e1e8;
        border-radius: 22px;
        padding: 0 18px;
        font-size: 14px;
        outline: none;
    }

    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 3px rgba(0, 34, 76, .08);
    }

    .dataTables_wrapper .dt-layout-row:last-child {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
    }
</style>



<style>
/*
| This page was written against finance_header.php, which is the only header
| in the app that pulls in the Tailwind CDN. Under admin_header.php none of
| its utility classes resolved, so the cards stacked full-width and the
| controls lost their styling.
|
| Loading Tailwind into the admin header would fix it and break far more:
| Tailwind's preflight resets fight Bootstrap, which is what broke the
| collapse behaviour on the payroll screen earlier.
|
| So the handful of utilities this page actually uses are defined here
| instead, scoped to .income-page so nothing leaks into the rest of the app.
| On the finance page Tailwind still supplies the same rules; these simply
| agree with it.
*/

.income-page { --ip-slate-200:#e2e8f0; --ip-slate-300:#cbd5e1; }

.income-page .flex { display:flex; }
.income-page .inline-flex { display:inline-flex; }
.income-page .hidden { display:none; }
.income-page .flex-col { flex-direction:column; }
.income-page .items-center { align-items:center; }
.income-page .justify-between { justify-content:space-between; }
.income-page .justify-center { justify-content:center; }
.income-page .gap-2 { gap:.5rem; }
.income-page .gap-3 { gap:.75rem; }
.income-page .gap-4 { gap:1rem; }

.income-page .grid { display:grid; }
.income-page .grid-cols-1 { grid-template-columns:repeat(1,minmax(0,1fr)); }

.income-page .relative { position:relative; }
.income-page .absolute { position:absolute; }
.income-page .right-0 { right:0; }
.income-page .top-full { top:100%; }
.income-page .z-50 { z-index:50; }

.income-page .w-full { width:100%; }
.income-page .w-44 { width:11rem; }
.income-page .h-10 { height:2.5rem; }
.income-page .max-w-\[300px\] { max-width:300px; }
.income-page .min-w-\[1250px\] { min-width:1250px; }

.income-page .p-4 { padding:1rem; }
.income-page .px-3 { padding-left:.75rem; padding-right:.75rem; }
.income-page .px-4 { padding-left:1rem; padding-right:1rem; }
.income-page .px-6 { padding-left:1.5rem; padding-right:1.5rem; }
.income-page .py-1 { padding-top:.25rem; padding-bottom:.25rem; }
.income-page .py-3 { padding-top:.75rem; padding-bottom:.75rem; }
.income-page .py-4 { padding-top:1rem; padding-bottom:1rem; }
.income-page .mb-0 { margin-bottom:0; }
.income-page .mb-1 { margin-bottom:.25rem; }
.income-page .mb-5 { margin-bottom:1.25rem; }
.income-page .mb-6 { margin-bottom:1.5rem; }
.income-page .mt-0\.5 { margin-top:.125rem; }
.income-page .mt-1 { margin-top:.25rem; }
.income-page .mt-2 { margin-top:.5rem; }

.income-page .border { border:1px solid var(--ip-slate-200); }
.income-page .border-b { border-bottom:1px solid var(--ip-slate-200); }
.income-page .border-slate-200 { border-color:var(--ip-slate-200); }
.income-page .border-slate-300 { border-color:var(--ip-slate-300); }
.income-page .border-blue-200 { border-color:#bfdbfe; }
.income-page .border-emerald-200 { border-color:#a7f3d0; }
.income-page .border-amber-200 { border-color:#fde68a; }

.income-page .rounded-lg { border-radius:.5rem; }
.income-page .rounded-xl { border-radius:.75rem; }
.income-page .rounded-full { border-radius:9999px; }
.income-page .overflow-hidden { overflow:hidden; }
.income-page .overflow-x-auto { overflow-x:auto; }
.income-page .shadow-sm { box-shadow:0 1px 2px rgba(0,0,0,.05); }
.income-page .shadow-lg { box-shadow:0 10px 25px rgba(0,34,76,.12); }
.income-page .transition { transition:all .15s ease; }
.income-page .truncate { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.income-page .whitespace-nowrap { white-space:nowrap; }
.income-page .text-left { text-align:left; }

.income-page .bg-white { background-color:#fff; }
.income-page .bg-slate-50 { background-color:#f8fafc; }
.income-page .bg-blue-50 { background-color:#eff6ff; }
.income-page .bg-emerald-50 { background-color:#ecfdf5; }
.income-page .bg-amber-50 { background-color:#fffbeb; }
.income-page .hover\:bg-slate-50:hover { background-color:#f8fafc; }

.income-page .text-xs { font-size:.75rem; line-height:1rem; }
.income-page .text-sm { font-size:.875rem; line-height:1.25rem; }
.income-page .text-2xl { font-size:1.5rem; line-height:2rem; }
.income-page .font-medium { font-weight:500; }
.income-page .font-semibold { font-weight:600; }
.income-page .font-bold { font-weight:700; }

.income-page .text-slate-400 { color:#94a3b8; }
.income-page .text-slate-500 { color:#64748b; }
.income-page .text-slate-600 { color:#475569; }
.income-page .text-slate-700 { color:#334155; }
.income-page .text-slate-900 { color:#0f172a; }
.income-page .text-blue-700 { color:#1d4ed8; }
.income-page .text-emerald-600 { color:#059669; }
.income-page .text-emerald-700 { color:#047857; }
.income-page .text-amber-700 { color:#b45309; }
.income-page .text-red-600 { color:#dc2626; }
.income-page .text-\[\#00224c\] { color:#00224c; }

.income-page .focus\:outline-none:focus { outline:none; }
.income-page .focus\:border-\[\#00224c\]:focus { border-color:#00224c; }

@media (min-width:640px) {
    .income-page .sm\:px-6 { padding-left:1.5rem; padding-right:1.5rem; }
}

@media (min-width:768px) {
    .income-page .md\:grid-cols-2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .income-page .md\:flex-row { flex-direction:row; }
    .income-page .md\:items-center { align-items:center; }
    .income-page .md\:justify-between { justify-content:space-between; }
}

@media (min-width:1024px) {
    .income-page .lg\:px-8 { padding-left:2rem; padding-right:2rem; }
    .income-page .lg\:flex-row { flex-direction:row; }
    .income-page .lg\:items-center { align-items:center; }
    .income-page .lg\:w-40 { width:10rem; }
    .income-page .lg\:w-auto { width:auto; }
}

@media (min-width:1280px) {
    .income-page .xl\:grid-cols-3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
}

/* The summary tiles and headings, which relied on the finance page's own CSS. */
.income-page .dashboard-card {
    border:1px solid var(--ip-slate-200);
    border-radius:.75rem;
    box-shadow:0 1px 2px rgba(0,0,0,.05);
}

.income-page .card-icon {
    width:44px;
    height:44px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    color:#fff;
}

.income-page .card-icon.bg-navy { background:#00224c; }
.income-page .card-icon.bg-yellow { background:#fbbd23; color:#00224c; }
.income-page .card-icon.bg-green { background:#16a34a; }

.income-page .stat-number { font-size:1.75rem; font-weight:700; color:#00224c; }
.income-page .request-title { font-size:1.75rem; font-weight:700; color:#00224c; margin:0; }
.income-page .request-subtitle { color:#718096; font-size:.9rem; }
.income-page .report-header { margin-bottom:1rem; }

.income-page table { border-collapse:collapse; }
.income-page thead th { font-weight:600; color:#0f172a; }
.income-page tbody tr + tr { border-top:1px solid var(--ip-slate-200); }
</style>

<div class="income-page w-full px-4 sm:px-6 lg:px-8 py-1">

    <!-- =========================================================
         PAGE HEADER
    ========================================================== -->

    <!-- <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-6">

        <div>

            <h1 class="text-2xl font-bold text-[#00224c]">
                Income
            </h1>

            <p class="text-sm text-slate-500 mt-1">
                POS sales income and transaction records
            </p>

        </div>

    </div> -->

    <div class="mb-0">

        <h1 class="request-title">
            Income
        </h1>

        <div class="request-subtitle">
            POS sales income and transaction records
        </div>

    </div>


    <!-- =========================================================
         SUMMARY CARDS
    ========================================================== -->

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-5">


        <!-- TOTAL SALES -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Total POS Sales
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($totalSales, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-navy">

                    <i class="bi bi-cash-stack"></i>

                </div>

            </div>

        </div>


        <!-- TRANSACTIONS -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Transactions
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalTransactions) ?>
                    </div>

                </div>

                <div class="card-icon bg-yellow">

                    <i class="bi bi-receipt"></i>

                </div>

            </div>

        </div>


        <!-- CASH -->

        <div class="dashboard-card bg-white p-4">

            <div class="flex items-center justify-between">

                <div>

                    <div class="text-sm text-slate-500 mb-1">
                        Cash Sales
                    </div>

                    <div class="stat-number">
                        ₱<?= number_format($totalCash, 2) ?>
                    </div>

                </div>

                <div class="card-icon bg-green">

                    <i class="bi bi-wallet2"></i>

                </div>

            </div>

        </div>



    </div>


    <!-- =========================================================
         INCOME TABLE
    ========================================================== -->

    <div class="w-full rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">


        <!-- =====================================================
             FILTERS / EXPORT — moved onto the search row by the
             shared DataTables theme (assets/js/datatable-theme.js)
        ====================================================== -->

        <div data-dt-toolbar="incomeTable">

            <div class="flex flex-col lg:flex-row lg:items-center gap-3">


                <!-- PAYMENT FILTER -->

                <select id="paymentFilter"
                    class="h-10 w-full lg:w-40 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">

                    <option value="">
                        All Payments
                    </option>

                    <option value="Cash">
                        Cash
                    </option>

                    <option value="GCash">
                        GCash
                    </option>

                </select>


                <!-- STATUS FILTER -->

                <select id="statusFilter"
                    class="h-10 w-full lg:w-40 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:border-[#00224c]">

                    <option value="">
                        All Status
                    </option>

                    <option value="Completed">
                        Completed
                    </option>

                    <option value="Credit Sale">
                        Credit Sale
                    </option>

                </select>


                <!-- EXPORT DROPDOWN -->

                <div class="relative">

                    <button type="button" id="exportButton"
                        class="h-10 w-full lg:w-auto px-4 inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 transition">

                        <i class="bi bi-download"></i>

                        Export

                        <i class="bi bi-chevron-down text-xs"></i>

                    </button>


                    <!-- EXPORT MENU -->

                    <div id="exportMenu"
                        class="hidden absolute right-0 top-full mt-2 w-44 bg-white border border-slate-200 rounded-lg shadow-lg z-50 overflow-hidden">

                        <button type="button" id="exportExcel"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-file-earmark-excel text-emerald-600"></i>

                            Excel

                        </button>


                        <button type="button" id="exportPdf"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-file-earmark-pdf text-red-600"></i>

                            PDF

                        </button>


                        <button type="button" id="printIncome"
                            class="w-full px-4 py-3 flex items-center gap-3 text-sm text-slate-700 hover:bg-slate-50 text-left">

                            <i class="bi bi-printer text-[#00224c]"></i>

                            Print

                        </button>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             TABLE
        ====================================================== -->

        <div class="overflow-x-auto">

            <table id="incomeTable" class="w-full text-sm min-w-[1250px]" style="width:100%">

                <thead class="bg-white border-b border-slate-200">

                    <tr class="text-left text-sm text-slate-900">

                        <th class="px-6 py-4 font-medium">
                            #
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Transaction No.
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Date
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Source Module
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Products
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Items
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Payment Method
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Reference
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Tax
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Total Income
                        </th>

                        <th class="px-6 py-4 font-medium whitespace-nowrap">
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody id="incomeTableBody">

                    <?php if (count($incomeRows) > 0): ?>

                        <?php foreach ($incomeRows as $index => $row): ?>

                            <?php

                            $paymentMethod = $row['payment_method'] ?? 'Cash';

                            if ($paymentMethod === 'Utang') {

                                $status = 'Credit Sale';

                            } else {

                                $status = 'Completed';

                            }

                            $searchText = strtolower(
                                'SALE-' .
                                str_pad(
                                    $row['sale_id'],
                                    6,
                                    '0',
                                    STR_PAD_LEFT
                                )
                                . ' '
                                . ($row['products_sold'] ?? '')
                                . ' '
                                . $paymentMethod
                                . ' '
                                . $status
                                . ' POS'
                            );

                            ?>


                            <tr class="income-row border-b border-slate-200 hover:bg-slate-50 transition"
                                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                                data-payment="<?= htmlspecialchars($paymentMethod, ENT_QUOTES) ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>">


                                <!-- NUMBER -->

                                <td class="px-6 py-4 text-slate-500 row-number">
                                    <?= $index + 1 ?>
                                </td>


                                <!-- TRANSACTION -->

                                <td class="px-6 py-4">

                                    <span class="font-medium text-slate-900 whitespace-nowrap">

                                        SALE-<?= htmlspecialchars(str_pad(
                                            $row['sale_id'],
                                            6,
                                            '0',
                                            STR_PAD_LEFT
                                        ), ENT_QUOTES) ?>

                                    </span>

                                </td>


                                <!-- DATE -->

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">

                                    <?= date(
                                        'M d, Y',
                                        strtotime($row['sale_date'])
                                    ) ?>

                                    <div class="text-xs text-slate-400 mt-0.5">

                                        <?= date(
                                            'h:i A',
                                            strtotime($row['sale_date'])
                                        ) ?>

                                    </div>

                                </td>


                                <!-- SOURCE -->

                                <td class="px-6 py-4">

                                    <span
                                        class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">

                                        POS Sales

                                    </span>

                                </td>


                                <!-- PRODUCTS -->

                                <td class="px-6 py-4 text-slate-700">

                                    <div class="max-w-[300px] truncate" title="<?= htmlspecialchars(
                                        $row['products_sold'] ?? ''
                                    ) ?>">

                                        <?= htmlspecialchars(
                                            $row['products_sold']
                                            ?: 'No product details'
                                        ) ?>

                                    </div>

                                </td>


                                <!-- ITEMS -->

                                <td class="px-6 py-4 text-slate-700">

                                    <?= number_format(
                                        (int) $row['item_count']
                                    ) ?>

                                </td>


                                <!-- PAYMENT -->

                                <td class="px-6 py-4">

                                    <?php if ($paymentMethod === 'Cash'): ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">

                                            Cash

                                        </span>

                                    <?php elseif ($paymentMethod === 'GCash'): ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">

                                            GCash

                                        </span>

                                    <?php elseif ($paymentMethod === 'Utang'): ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">

                                            Utang

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700">

                                            <?= htmlspecialchars(
                                                $paymentMethod
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- REFERENCE -->

                                <td class="px-6 py-4 text-slate-600 whitespace-nowrap">

                                    POS-<?= htmlspecialchars(str_pad(
                                        $row['sale_id'],
                                        6,
                                        '0',
                                        STR_PAD_LEFT
                                    ), ENT_QUOTES) ?>

                                </td>


                                <!-- TAX -->

                                <td class="px-6 py-4 text-slate-700 whitespace-nowrap">

                                    ₱<?= number_format(
                                        (float) $row['tax_amount'],
                                        2
                                    ) ?>

                                </td>


                                <!-- TOTAL -->

                                <td class="px-6 py-4 font-semibold text-slate-900 whitespace-nowrap">

                                    ₱<?= number_format(
                                        (float) $row['total_amount'],
                                        2
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td class="px-6 py-4">

                                    <?php if ($status === 'Completed'): ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">

                                            Completed

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">

                                            Credit Sale

                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>

                </tbody>

            </table>

        </div>



    </div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================== -->

<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | DATATABLE INITIALIZATION
        |--------------------------------------------------------------------------
        */

        var table = new DataTable("#incomeTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search income...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Previous", next: "Next" },
                emptyTable: "No income transactions found",
                zeroRecords: "No matching transactions"
            }
        });


        /*
        |--------------------------------------------------------------------------
        | CUSTOM FILTERS (Payment & Status)
        |--------------------------------------------------------------------------
        */

        var paymentFilter = document.getElementById("paymentFilter");
        var statusFilter = document.getElementById("statusFilter");

        DataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== "incomeTable") return true;
            var payment = paymentFilter.value;
            if (payment === "") return true;
            var row = settings.aoData[dataIndex].nTr;
            return row.getAttribute("data-payment") === payment;
        });

        DataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== "incomeTable") return true;
            var status = statusFilter.value;
            if (status === "") return true;
            var row = settings.aoData[dataIndex].nTr;
            return row.getAttribute("data-status") === status;
        });

        paymentFilter.addEventListener("change", function () {
            table.draw();
        });

        statusFilter.addEventListener("change", function () {
            table.draw();
        });


        /*
        |--------------------------------------------------------------------------
        | EXPORT DROPDOWN
        |--------------------------------------------------------------------------
        */

        var exportButton = document.getElementById("exportButton");
        var exportMenu = document.getElementById("exportMenu");
        var exportExcel = document.getElementById("exportExcel");
        var exportPdf = document.getElementById("exportPdf");
        var printButton = document.getElementById("printIncome");

        exportButton.addEventListener("click", function (event) {
            event.stopPropagation();
            exportMenu.classList.toggle("hidden");
        });

        document.addEventListener("click", function (event) {
            if (!exportMenu.contains(event.target) && !exportButton.contains(event.target)) {
                exportMenu.classList.add("hidden");
            }
        });


        /*
        |--------------------------------------------------------------------------
        | GET FILTERED DATA
        |--------------------------------------------------------------------------
        */

        function getExportRows() {
            return Array.from(table.rows({ search: "applied" }).nodes());
        }


        /*
        |--------------------------------------------------------------------------
        | EXPORT EXCEL
        |--------------------------------------------------------------------------
        */

        exportExcel.addEventListener("click", function () {

            exportMenu.classList.add("hidden");

            var filteredRows = getExportRows();

            if (filteredRows.length === 0) {
                alert("There are no transactions to export.");
                return;
            }

            var html = '<html><head><meta charset="UTF-8"><title>SariSmart Income Report</title></head><body>';
            html += '<h2>SariSmart POS Income Report</h2>';
            html += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
            html += '<table border="1"><thead><tr>';
            html += '<th>#</th><th>Transaction No.</th><th>Date</th><th>Source Module</th>';
            html += '<th>Products</th><th>Items</th><th>Payment Method</th><th>Reference</th>';
            html += '<th>Tax</th><th>Total Income</th><th>Status</th>';
            html += '</tr></thead><tbody>';

            filteredRows.forEach(function (row) {
                var cells = row.querySelectorAll("td");
                html += '<tr>';
                cells.forEach(function (cell) {
                    html += '<td>' + cell.innerText.trim().replace(/\s+/g, ' ') + '</td>';
                });
                html += '</tr>';
            });

            html += '</tbody></table></body></html>';

            var blob = new Blob([html], { type: "application/vnd.ms-excel" });
            var url = URL.createObjectURL(blob);
            var link = document.createElement("a");
            link.href = url;
            link.download = "sarismart_income.xls";
            link.click();
            URL.revokeObjectURL(url);

        });


        /*
        |--------------------------------------------------------------------------
        | PRINT / PDF
        |--------------------------------------------------------------------------
        */

        function openPrintWindow() {

            var filteredRows = getExportRows();

            if (filteredRows.length === 0) {
                alert("There are no transactions to print.");
                return;
            }

            var tableRows = "";

            filteredRows.forEach(function (row) {
                var cells = row.querySelectorAll("td");
                tableRows += '<tr>';
                cells.forEach(function (cell) {
                    tableRows += '<td>' + cell.innerText.trim().replace(/\s+/g, ' ') + '</td>';
                });
                tableRows += '</tr>';
            });

            var printWindow = window.open("", "", "width=1400,height=900");

            if (!printWindow) {
                alert("Please allow pop-ups for this page.");
                return;
            }

            printWindow.document.write(
                '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
                '<title>SariSmart POS Income Report</title>' +
                '<style>' +
                '* { box-sizing: border-box; }' +
                'body { font-family: Arial, sans-serif; padding: 30px; color: #111827; }' +
                'h1 { margin: 0; color: #00224c; font-size: 24px; }' +
                'p { color: #64748b; margin-top: 5px; }' +
                'table { width: 100%; border-collapse: collapse; margin-top: 25px; font-size: 11px; }' +
                'th { background: #f1f5f9; color: #0f172a; font-weight: 600; }' +
                'th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }' +
                '.report-header { margin-bottom: 20px; }' +
                '@media print { body { padding: 10px; } @page { size: landscape; margin: 10mm; } }' +
                '</style></head><body>' +
                '<div class="report-header">' +
                '<h1>SariSmart POS Income Report</h1>' +
                '<p>Generated: ' + new Date().toLocaleString() + '</p>' +
                '</div>' +
                '<table><thead><tr>' +
                '<th>#</th><th>Transaction No.</th><th>Date</th><th>Source Module</th>' +
                '<th>Products</th><th>Items</th><th>Payment Method</th><th>Reference</th>' +
                '<th>Tax</th><th>Total Income</th><th>Status</th>' +
                '</tr></thead><tbody>' +
                tableRows +
                '</tbody></table></body></html>'
            );

            printWindow.document.close();
            printWindow.focus();

            setTimeout(function () {
                printWindow.print();
                printWindow.close();
            }, 300);

        }


        /*
        |--------------------------------------------------------------------------
        | PRINT
        |--------------------------------------------------------------------------
        */

        printButton.addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });


        /*
        |--------------------------------------------------------------------------
        | PDF
        |--------------------------------------------------------------------------
        |
        | Browser print dialog can save the report
        | directly as PDF.
        |
        */

        exportPdf.addEventListener("click", function () {
            exportMenu.classList.add("hidden");
            openPrintWindow();
        });

    });

</script>


<?php include($MODULE_FOOTER); ?>