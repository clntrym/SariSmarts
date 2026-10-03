<?php

require_once("../init.php");
requireRole(['inventory']);

$companyId = requireCompany();

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$products = [];
$stockRequests = [];

$expenseCategories = [
    'Restocking',
    'Storage Equipment',
    'Office Supplies',
    'Equipment Maintenance',
    'Utilities',
    'Others'
];


/*
|--------------------------------------------------------------------------
| CURRENT USER / REQUESTOR INFO
|--------------------------------------------------------------------------
| Pulled from users -> employees -> job -> branch, joined on the
| logged-in user's employee_id. Falls back to "N/A" per field
| if a link is missing (e.g. a user with no linked employee record).
|--------------------------------------------------------------------------
*/

$requestor = [
    'employee_name' => 'N/A',
    'employee_id' => 'N/A',
    'department' => 'N/A',
    'position' => 'N/A',
    'branch_name' => 'N/A',
    'branch_address' => 'N/A',
];

$currentUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($currentUserId > 0) {

    $requestorQuery = $conn->prepare("
        SELECT
            u.fullname,
            e.employee_code,
            j.department,
            j.job_title,
            b.branch_name,
            b.complete_address
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.employee_id
        LEFT JOIN job j ON e.job_id = j.job_id
        LEFT JOIN branch b ON e.branch_id = b.branch_id
        WHERE u.user_id = ?
        LIMIT 1
    ");

    if ($requestorQuery) {

        $requestorQuery->bind_param("i", $currentUserId);
        $requestorQuery->execute();
        $requestorResult = $requestorQuery->get_result();

        if ($requestorResult->num_rows > 0) {

            $requestorRow = $requestorResult->fetch_assoc();

            $requestor['employee_name'] = $requestorRow['fullname'] ?? 'N/A';
            $requestor['employee_id'] = $requestorRow['employee_code'] ?? 'N/A';
            $requestor['department'] = $requestorRow['department'] ?? 'N/A';
            $requestor['position'] = $requestorRow['job_title'] ?? 'N/A';
            $requestor['branch_name'] = $requestorRow['branch_name'] ?? 'N/A';
            $requestor['branch_address'] = $requestorRow['complete_address'] ?? 'N/A';
        }

        $requestorQuery->close();
    }
}


require __DIR__ . "/../includes/stock_request_create.php";
/*
|--------------------------------------------------------------------------
| GET PRODUCTS (for the item dropdowns)
|--------------------------------------------------------------------------
*/

$productQuery = $conn->query("
    SELECT
        p.product_id,
        p.product_name,
        p.supplier_id,
        s.supplier_name
    FROM products p
    LEFT JOIN suppliers s
        ON p.supplier_id = s.supplier_id AND s.company_id = p.company_id
    WHERE p.company_id = " . (int) $companyId . "
    ORDER BY p.product_name ASC
");

if ($productQuery) {

    while ($row = $productQuery->fetch_assoc()) {
        $products[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| GET STOCK REQUESTS (header + item summary)
|--------------------------------------------------------------------------
*/

$requestQuery = $conn->query("
    SELECT
        sr.request_id,
        sr.request_code,
        sr.expense_category,
        sr.category_other,
        sr.reason,
        sr.status,
        sr.total_price,
        sr.created_at,

        COUNT(sri.item_id) AS item_count,
        GROUP_CONCAT(
            COALESCE(p.product_name, sri.item_description)
            SEPARATOR ', '
        ) AS product_names,
        GROUP_CONCAT(
            DISTINCT sri.vendor
            SEPARATOR ', '
        ) AS vendors

    FROM stock_requests sr

    LEFT JOIN stock_request_items sri
        ON sr.request_id = sri.request_id AND sri.company_id = sr.company_id

    LEFT JOIN products p
        ON sri.product_id = p.product_id AND p.company_id = sr.company_id

    WHERE sr.company_id = " . (int) $companyId . "

    GROUP BY sr.request_id

    ORDER BY sr.request_id DESC
");

if ($requestQuery) {

    while ($row = $requestQuery->fetch_assoc()) {
        $stockRequests[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

include("inventory_header.php");

?>


<style>
    /* =========================================================
   STOCK REQUEST PAGE
========================================================= */

    .stock-request-page {
        padding: 10px 4px 30px;
    }

    .stock-request-title {
        color: #00224c;
        font-size: 34px;
        font-weight: 700;
        margin-bottom: 2px;
    }

    .stock-request-subtitle {
        color: #64748b;
        margin-bottom: 20px;
    }


    /* =========================================================
   NEW REQUEST BUTTON
========================================================= */

    .btn-new-request {
        background: #00224c;
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 10px 18px;
        font-weight: 500;
    }

    .btn-new-request:hover {
        background: #001a3a;
        color: #fff;
    }


    /* =========================================================
   TABLE CARD
========================================================= */

    .stock-request-table-card {
        background: #fff;
        border: 1px solid #dce3ea;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 34, 76, 0.05);
    }


    /* =========================================================
   DATATABLE
========================================================= */

    #stockRequestTable {
        width: 100% !important;
    }

    #stockRequestTable thead th {
        background: #f4f7fa;
        color: #53657a;
        font-size: 14px;
        font-weight: 600;
        padding: 16px 18px;
        border-bottom: 1px solid #dce3ea;
        white-space: nowrap;
    }

    #stockRequestTable tbody td {
        padding: 16px 18px;
        vertical-align: middle;
        border-bottom: 1px solid #e3e8ee;
        color: #19324d;
        font-size: 14px;
    }

    #stockRequestTable tbody tr:last-child td {
        border-bottom: none;
    }

    #stockRequestTable tbody tr:hover {
        background: #fafcff;
    }


    /* =========================================================
   REF
========================================================= */

    .request-code {
        font-weight: 700;
        color: #00224c;
    }

    .request-category-pill {
        display: inline-block;
        font-size: 11px;
        font-weight: 600;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 2px 10px;
        margin-top: 4px;
    }


    /* =========================================================
   ITEMS
========================================================= */

    .request-items {
        font-weight: 600;
        color: #00224c;
        max-width: 260px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .request-item-count {
        font-size: 12px;
        color: #8290a0;
        margin-top: 2px;
    }


    /* =========================================================
   STATUS
========================================================= */

    .request-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-pending {
        background: #fef3c7;
        color: #a16207;
        border: 1px solid #fde68a;
    }

    .status-finance-approved {
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }

    .status-admin-approved {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    .status-rejected {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    .status-received {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }


    /* =========================================================
   SEARCH
========================================================= */

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


    /* =========================================================
   FOOTER
========================================================= */

    .dataTables_wrapper .dt-layout-row:last-child {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 18px 18px;
        gap: 15px;
    }

    .dataTables_wrapper .dt-info {
        color: #64748b;
        font-size: 13px;
    }


    /* =========================================================
   PAGINATION
========================================================= */

    .dataTables_wrapper .dt-paging .dt-paging-button {
        min-width: 34px;
        height: 34px;

        margin: 0 2px !important;
        padding: 6px 10px !important;

        border: none !important;
        border-radius: 7px !important;

        background: transparent !important;
        color: #64748b !important;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        cursor: pointer;
    }

    .dataTables_wrapper .dt-paging .dt-paging-button.current {
        background: #00224c !important;
        color: #fff !important;
    }

    .dataTables_wrapper .dt-paging .dt-paging-button:hover:not(.disabled) {
        background: #eef3f8 !important;
        color: #00224c !important;
    }


    /* =========================================================
   MODAL
========================================================= */

    .modal-content {
        border: none;
        border-radius: 16px;
    }

    .modal-header {
        border-bottom: 1px solid #e5e9ef;
    }

    .modal-title {
        color: #00224c;
    }

    .form-label {
        color: #34495e;
        font-weight: 600;
    }

    .form-control,
    .form-select {
        border-radius: 9px;
        border: 1px solid #d7dee7;
        min-height: 44px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #00224c;
        box-shadow: 0 0 0 3px rgba(0, 34, 76, .08);
    }

    .form-control[readonly] {
        background: #f8fafc;
        color: #64748b;
    }


    /* =========================================================
   NEW STOCK REQUEST MODAL — force a scrollable body so the
   footer (Cancel / Submit Request) always stays visible without
   needing to zoom out, no matter how many item rows are added.
========================================================= */

    #newStockRequestModal .modal-dialog {
        max-height: 90vh;
    }

    #newStockRequestModal .modal-content {
        max-height: 90vh;
        display: flex;
        flex-direction: column;
    }

    #newStockRequestModal .modal-body {
        overflow-y: auto;
        flex: 1 1 auto;
    }


    /* =========================================================
   SECTION CARDS (Requestor Info / Request Details)
========================================================= */

    .request-section {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px;
        margin-bottom: 18px;
    }

    .request-section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        color: #00224c;
        font-size: 15px;
        margin-bottom: 16px;
    }

    .request-section-badge {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #00224c;
        color: #fff;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }


    /* =========================================================
   ITEMS TABLE
========================================================= */

    #itemsTable {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        border-collapse: separate;
        border-spacing: 0;
    }

    #itemsTable thead th {
        background: #00224c;
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .3px;
        padding: 10px 12px;
        white-space: nowrap;
    }

    #itemsTable tbody td {
        padding: 8px;
        border-top: 1px solid #eef1f5;
        vertical-align: middle;
    }

    #itemsTable .item-select,
    #itemsTable .item-qty,
    #itemsTable .item-price,
    #itemsTable .item-markup {
        min-height: 40px;
        font-size: 13px;
    }

    #itemsTable .item-supplier-note {
        font-size: 11px;
        color: #8290a0;
        margin-top: 2px;
    }

    .item-row-total {
        font-weight: 700;
        color: #00224c;
        white-space: nowrap;
    }

    .btn-remove-item {
        border: none;
        background: #fee2e2;
        color: #dc2626;
        border-radius: 8px;
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-remove-item:hover {
        background: #fecaca;
    }

    .btn-add-item {
        border: 1px dashed #00224c;
        background: #fff;
        color: #00224c;
        border-radius: 9px;
        padding: 8px 16px;
        font-weight: 600;
        font-size: 13px;
    }

    .btn-add-item:hover {
        background: #f4f7fb;
    }


    /* =========================================================
   TOTAL BOX
========================================================= */

    .total-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .total-label {
        color: #64748b;
        font-size: 13px;
        font-weight: 600;
    }

    .total-value {
        color: #00224c;
        font-size: 24px;
        font-weight: 700;
    }


    /* =========================================================
   MOBILE
========================================================= */

    @media(max-width:768px) {

        .stock-request-title {
            font-size: 28px;
        }

        .btn-new-request {
            width: 100%;
            margin-top: 12px;
        }

        .dataTables_wrapper .dataTables_filter input {
            width: 100%;
        }

        .dataTables_wrapper .dt-layout-row:last-child {
            flex-direction: column;
            align-items: flex-start;
        }

        #itemsTable {
            font-size: 12px;
        }
    }
</style>




<div class="container-fluid stock-request-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">

        <div>

            <h1 class="stock-request-title">
                Stock Requests
            </h1>

            <div class="stock-request-subtitle">
                Request replenishment for your products.
            </div>

        </div>


        <button type="button" class="btn btn-new-request" data-bs-toggle="modal" data-bs-target="#newStockRequestModal">

            <i class="bi bi-plus-lg me-1"></i>

            New Request

        </button>

    </div>


    <!-- =====================================================
         TABLE
    ====================================================== -->

    <div class="stock-request-table-card">

        <table id="stockRequestTable" class="table mb-0">

            <thead>

                <tr>

                    <th>Ref</th>
                    <th>Items</th>
                    <th>Category</th>
                    <th>Total</th>
                    <th>Reason</th>
                    <th>Date</th>
                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($stockRequests as $request): ?>

                    <?php

                    $statusClass = "status-pending";

                    if ($request['status'] === 'Finance Approved') {
                        $statusClass = "status-finance-approved";
                    } elseif ($request['status'] === 'Admin Approved') {
                        $statusClass = "status-admin-approved";
                    } elseif (
                        $request['status'] === 'Finance Rejected' ||
                        $request['status'] === 'Admin Rejected'
                    ) {
                        $statusClass = "status-rejected";
                    } elseif ($request['status'] === 'Received') {
                        $statusClass = "status-received";
                    }

                    $categoryLabel =
                        $request['expense_category'] === 'Others' && !empty($request['category_other'])
                        ? $request['category_other']
                        : $request['expense_category'];

                    ?>

                    <tr>

                        <!-- REF -->
                        <td>
                            <span class="request-code">
                                <?= htmlspecialchars($request['request_code']) ?>
                            </span>
                        </td>

                        <!-- ITEMS -->
                        <td>
                            <div class="request-items"
                                title="<?= htmlspecialchars($request['product_names'] ?? '', ENT_QUOTES) ?>">
                                <?= htmlspecialchars($request['product_names'] ?? '-') ?>
                            </div>
                            <div class="request-item-count">
                                <?= (int) $request['item_count'] ?>
                                item<?= ((int) $request['item_count'] === 1 ? '' : 's') ?>
                                <?php if (!empty($request['vendors'])): ?>
                                    &middot; <?= htmlspecialchars($request['vendors']) ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- CATEGORY -->
                        <td>
                            <span class="request-category-pill">
                                <?= htmlspecialchars($categoryLabel) ?>
                            </span>
                        </td>

                        <!-- TOTAL -->
                        <td>
                            ₱<?= number_format((float) $request['total_price'], 2) ?>
                        </td>

                        <!-- REASON -->
                        <td>
                            <span title="<?= htmlspecialchars($request['reason'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars(
                                    strlen($request['reason']) > 35
                                    ? substr($request['reason'], 0, 35) . '...'
                                    : $request['reason']
                                ) ?>
                            </span>
                        </td>

                        <!-- DATE -->
                        <td data-order="<?= htmlspecialchars($request['created_at']) ?>">
                            <?= date('Y-m-d', strtotime($request['created_at'])) ?>
                        </td>

                        <!-- STATUS -->
                        <td>
                            <span class="request-status <?= $statusClass ?>">
                                <?= htmlspecialchars($request['status']) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (empty($stockRequests)): ?>

                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard-check fs-1 d-block mb-2"></i>
                            No stock requests found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require __DIR__ . "/../includes/stock_request_form.php"; ?>


<!-- =========================================================
     SWEETALERT
========================================================= -->

<?php

if (isset($_SESSION['alert'])):

    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);

    ?>

    <script>

        Swal.fire({
            icon: <?= json_encode($alert['icon']) ?>,
            title: <?= json_encode($alert['title']) ?>,
            text: <?= json_encode($alert['text']) ?>,
            confirmButtonColor: "#00224c"
        });

    </script>

<?php endif; ?>


<?php include("inventory_footer.php"); ?>