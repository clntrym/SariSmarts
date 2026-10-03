<?php

/*
|--------------------------------------------------------------------------
| NEW STOCK REQUEST -- shared form
|--------------------------------------------------------------------------
|
| The modal, its item-row templates and its JavaScript, shared by
| inventory/stock_requests.php and admin/stock_requests.php. Its companion is
| includes/stock_request_create.php, which handles the POST.
|
| Self-sufficient on purpose: it loads the products, categories and requestor
| details it renders unless the including page has already built them, so a
| page can show the form by requiring this one file.
|
| The form posts back to whichever page is displaying it, so the handler --
| and its redirect -- stay on that page.
|
*/

if (!isset($conn, $companyId)) {
    http_response_code(403);
    exit('This page cannot be opened directly.');
}

if (!isset($expenseCategories)) {
    $expenseCategories = [
        'Restocking',
        'Storage Equipment',
        'Office Supplies',
        'Equipment Maintenance',
        'Utilities',
        'Others'
    ];
}

/*
| A plan with no Finance approver books its requests as approved on the spot,
| so the modal says that rather than promising a review that never comes.
*/
$formAutoApproves = !companyHasModule($conn, $companyId, 'finance_approval');

if (!isset($requestor)) {

    $requestor = [
        'employee_name' => $_SESSION['fullname'] ?? 'N/A',
        'employee_id' => 'N/A',
        'department' => 'N/A',
        'position' => ucfirst((string) ($_SESSION['role'] ?? 'N/A')),
        'branch_name' => 'N/A',
        'branch_address' => 'N/A',
    ];

    $formUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

    if ($formUserId > 0) {

        $requestorQuery = $conn->prepare("
            SELECT
                u.fullname,
                e.employee_code,
                j.department,
                j.job_title,
                b.branch_name,
                b.complete_address
            FROM users u
            LEFT JOIN employees e ON u.employee_id = e.employee_id AND e.company_id = u.company_id
            LEFT JOIN job j ON e.job_id = j.job_id AND j.company_id = u.company_id
            LEFT JOIN branch b ON e.branch_id = b.branch_id AND b.company_id = u.company_id
            WHERE u.user_id = ? AND u.company_id = ?
            LIMIT 1
        ");
        $requestorQuery->bind_param("ii", $formUserId, $companyId);
        $requestorQuery->execute();

        if ($row = $requestorQuery->get_result()->fetch_assoc()) {
            $requestor['employee_name'] = $row['fullname'] ?: $requestor['employee_name'];
            $requestor['employee_id'] = $row['employee_code'] ?: 'N/A';
            $requestor['department'] = $row['department'] ?: $requestor['department'];
            $requestor['position'] = $row['job_title'] ?: $requestor['position'];
            $requestor['branch_name'] = $row['branch_name'] ?: 'N/A';
            $requestor['branch_address'] = $row['complete_address'] ?: 'N/A';
        }

        $requestorQuery->close();
    }
}

if (!isset($products)) {

    $products = [];

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
}

?>

<style>
    /*
    | Scoped to the modal so a host page's own styles are left alone.
    | inventory/stock_requests.php still carries its original copies of these
    | rules for the rest of its layout; these are what make the modal stand up
    | on a page that has none.
    */
    #newStockRequestModal .modal-dialog {
        max-width: 1100px;
    }

    #newStockRequestModal .modal-content {
        border: none;
        border-radius: 16px;
    }

    #newStockRequestModal .modal-body {
        max-height: 72vh;
        overflow-y: auto;
    }

    #newStockRequestModal .item-row-total {
        font-weight: 600;
        color: #00224c;
    }

    .btn-new-request {
        background: #00224c;
        color: #fff;
        border: none;
        border-radius: 30px;
        padding: 10px 20px;
        font-weight: 600;
    }

    .btn-new-request:hover {
        background: #fbbd23;
        color: #00224c;
    }
</style>

<!-- =========================================================
     NEW STOCK REQUEST MODAL
========================================================= -->

<div class="modal fade" id="newStockRequestModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <div>
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-box-seam me-2"></i>
                        New Stock Request
                    </h5>
                    <small class="text-muted">
                        Request additional stock for one or more products.
                    </small>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <!-- FORM -->
            <form method="POST" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" id="stockRequestForm">

                <div class="modal-body">


                    <!-- =============================================
                         1. REQUESTOR INFORMATION
                    ============================================== -->

                    <div class="request-section">

                        <div class="request-section-title">
                            <span class="request-section-badge">1</span>
                            Requestor Information
                        </div>

                        <div class="row g-3">

                            <div class="col-md-3">
                                <label class="form-label">Employee Name</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['employee_name']) ?>" readonly>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Employee ID</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['employee_id']) ?>" readonly>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['department']) ?>" readonly>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Position</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['position']) ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Assigned Branch</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['branch_name']) ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Branch Address</label>
                                <input type="text" class="form-control"
                                    value="<?= htmlspecialchars($requestor['branch_address']) ?>" readonly>
                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         2. REQUEST DETAILS
                    ============================================== -->

                    <div class="request-section">

                        <div class="request-section-title">
                            <span class="request-section-badge">2</span>
                            Request Details
                        </div>

                        <div class="row g-3">

                            <div class="col-md-7">
                                <label class="form-label">
                                    Purpose / Reason for Request
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea name="reason" id="requestReason" class="form-control" rows="3" maxlength="500"
                                    placeholder="Example: Stock is below reorder point." required></textarea>
                            </div>

                            <div class="col-md-5">

                                <label class="form-label">
                                    Expense Category
                                    <span class="text-danger">*</span>
                                </label>

                                <select name="expense_category" id="expenseCategory" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($expenseCategories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>">
                                            <?= htmlspecialchars($category) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div id="categoryOtherWrap" class="mt-2" style="display:none;">
                                    <label class="form-label">If Others, please specify</label>
                                    <input type="text" name="category_other" id="categoryOther" class="form-control"
                                        maxlength="150" placeholder="Type here...">
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         3. REQUESTED ITEMS
                    ============================================== -->

                    <div class="request-section">

                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <div class="request-section-title mb-0">
                                <span class="request-section-badge">3</span>
                                Requested Items
                            </div>

                            <button type="button" class="btn-add-item" id="addItemRow">
                                <i class="bi bi-plus-lg me-1"></i>
                                Add Item
                            </button>

                        </div>

                        <div class="table-responsive">

                            <table id="itemsTable">

                                <thead id="itemsTableHead">
                                    <!-- header row injected by JS based on category mode -->
                                </thead>

                                <tbody id="itemsTableBody">
                                    <!-- rows injected by JS, starting with one row below -->
                                </tbody>

                            </table>

                        </div>

                        <div class="form-text mb-2" id="itemModeNote">
                            <strong>Restocking</strong> lets you pick items from your product inventory; every
                            other category uses free-text items. Restocking, Storage Equipment, Office Supplies,
                            Equipment Maintenance, and Others all go through Finance/Admin approval before being
                            received. <strong>Utilities</strong> is the only category that skips approval — it
                            goes straight to <strong>Accounts Payable</strong> for Finance to pay later.
                        </div>

                        <div class="total-box mt-3">
                            <span class="total-label">GRAND TOTAL (₱)</span>
                            <span class="total-value" id="grandTotal">₱0.00</span>
                        </div>

                    </div>

                </div>


                <!-- FOOTER -->

                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" name="createStockRequest" class="btn text-white px-4"
                        style="background:#00224c;">
                        <i class="bi bi-send me-1"></i>
                        Submit Request
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     ITEM ROW TEMPLATES (used by JS to add rows)
     - "Product" template is used when category = Restocking
     - "Text" template is used for every other category
       (these go straight to Accounts Payable, no approval)
========================================================= -->

<template id="itemRowTemplateProduct">
    <tr class="item-row">

        <td>
            <select name="item_product_id[]" class="form-select item-select" required>
                <option value="">Select Product</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['product_id'] ?>"
                        data-supplier="<?= htmlspecialchars($product['supplier_name'] ?? 'No Supplier', ENT_QUOTES) ?>">
                        <?= htmlspecialchars($product['product_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="item-supplier-note"></div>
        </td>

        <td>
            <input type="number" name="item_quantity[]" class="form-control item-qty" min="1" step="1" placeholder="0"
                required>
        </td>

        <td>
            <input type="number" name="item_unit_price[]" class="form-control item-price" min="0.01" step="0.01"
                placeholder="0.00" required>
        </td>

        <td>
            <input type="number" name="item_markup[]" class="form-control item-markup" min="0" step="0.01" value="20"
                placeholder="20">
        </td>

        <td class="item-row-total">₱0.00</td>

        <td>
            <button type="button" class="btn-remove-item" title="Remove item">
                <i class="bi bi-trash"></i>
            </button>
        </td>

    </tr>
</template>

<template id="itemRowTemplateText">
    <tr class="item-row">

        <td>
            <input type="text" name="item_vendor[]" class="form-control item-vendor" maxlength="150"
                placeholder="e.g. Meralco">
        </td>

        <td>
            <input type="text" name="item_description[]" class="form-control item-desc" maxlength="150"
                placeholder="e.g. Electric Bill - August" required>
        </td>

        <td>
            <input type="number" name="item_unit_price[]" class="form-control item-price" min="0.01" step="0.01"
                placeholder="0.00" required>
            <!-- quantity/markup don't apply to Accounts Payable items; fixed values sent silently -->
            <input type="hidden" name="item_quantity[]" class="item-qty" value="1">
            <input type="hidden" name="item_markup[]" class="item-markup" value="0">
        </td>

        <td class="item-row-total">₱0.00</td>

        <td>
            <button type="button" class="btn-remove-item" title="Remove item">
                <i class="bi bi-trash"></i>
            </button>
        </td>

    </tr>
</template>


<!-- =========================================================
     DATATABLE
========================================================= -->



<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | EXPENSE CATEGORY -> SHOW/HIDE "OTHERS" FIELD
        |--------------------------------------------------------------------------
        */

        const expenseCategory = document.getElementById("expenseCategory");
        const categoryOtherWrap = document.getElementById("categoryOtherWrap");
        const categoryOther = document.getElementById("categoryOther");

        function toggleCategoryOther() {

            if (expenseCategory.value === "Others") {
                categoryOtherWrap.style.display = "block";
                categoryOther.setAttribute("required", "required");
            } else {
                categoryOtherWrap.style.display = "none";
                categoryOther.removeAttribute("required");
                categoryOther.value = "";
            }
        }

        expenseCategory.addEventListener("change", toggleCategoryOther);


        /*
        |--------------------------------------------------------------------------
        | ITEMS TABLE: ADD / REMOVE / CALCULATE
        | Mode switches based on Expense Category:
        |   - "Restocking" -> pick from real inventory products
        |   - anything else -> free-text item description (-> Accounts Payable)
        |--------------------------------------------------------------------------
        */

        const itemsTableBody = document.getElementById("itemsTableBody");
        const itemRowTemplateProduct = document.getElementById("itemRowTemplateProduct");
        const itemRowTemplateText = document.getElementById("itemRowTemplateText");
        const itemsTableHead = document.getElementById("itemsTableHead");
        const addItemBtn = document.getElementById("addItemRow");
        const grandTotalEl = document.getElementById("grandTotal");

        let currentMode = null; // "product" or "text"

        function formatPeso(value) {
            return "₱" + value.toLocaleString("en-PH", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function getModeForCategory(categoryValue) {
            return categoryValue === "Restocking" ? "product" : "text";
        }

        const HEAD_HTML = {
            product: `
                <tr>
                    <th style="width:34%;">Product</th>
                    <th style="width:14%;">Quantity</th>
                    <th style="width:16%;">Unit Cost (₱)</th>
                    <th style="width:14%;">Markup (%)</th>
                    <th style="width:16%;">Estimated Total</th>
                    <th style="width:6%;"></th>
                </tr>
            `,
            text: `
                <tr>
                    <th style="width:20%;">Vendor</th>
                    <th style="width:34%;">Item / Description</th>
                    <th style="width:18%;">Amount (₱)</th>
                    <th style="width:18%;">Estimated Total</th>
                    <th style="width:6%;"></th>
                </tr>
            `
        };

        function addItemRow() {

            const template = currentMode === "product" ? itemRowTemplateProduct : itemRowTemplateText;

            const fragment = template.content.cloneNode(true);
            itemsTableBody.appendChild(fragment);
            bindRow(itemsTableBody.lastElementChild);
            recalcGrandTotal();
        }

        function bindRow(row) {

            const select = row.querySelector(".item-select");
            const qty = row.querySelector(".item-qty");
            const price = row.querySelector(".item-price");
            const markup = row.querySelector(".item-markup");
            const rowTotal = row.querySelector(".item-row-total");
            const supplierNote = row.querySelector(".item-supplier-note");
            const removeBtn = row.querySelector(".btn-remove-item");

            function recalcRow() {

                const q = parseFloat(qty.value) || 0;
                const p = parseFloat(price.value) || 0;

                const total = q * p;

                rowTotal.textContent = formatPeso(total);

                recalcGrandTotal();
            }

            if (select) {

                select.addEventListener("change", function () {

                    const option = this.options[this.selectedIndex];

                    supplierNote.textContent =
                        this.value !== ""
                            ? "Supplier: " + (option.dataset.supplier || "No Supplier")
                            : "";
                });
            }

            qty.addEventListener("input", recalcRow);
            price.addEventListener("input", recalcRow);
            markup.addEventListener("input", recalcRow);

            removeBtn.addEventListener("click", function () {

                if (itemsTableBody.querySelectorAll(".item-row").length <= 1) {
                    // keep at least one row
                    row.querySelectorAll("input").forEach(el => el.value = "");
                    if (select) {
                        select.value = "";
                    }
                    if (supplierNote) {
                        supplierNote.textContent = "";
                    }
                    rowTotal.textContent = "₱0.00";
                    recalcGrandTotal();
                    return;
                }

                row.remove();
                recalcGrandTotal();
            });
        }

        function recalcGrandTotal() {

            let grand = 0;

            itemsTableBody.querySelectorAll(".item-row").forEach(function (row) {

                const q = parseFloat(row.querySelector(".item-qty").value) || 0;
                const p = parseFloat(row.querySelector(".item-price").value) || 0;

                grand += q * p;
            });

            grandTotalEl.textContent = formatPeso(grand);
        }

        function switchItemsMode(newMode, options = {}) {

            if (newMode === currentMode && !options.force) {
                return;
            }

            currentMode = newMode;

            itemsTableHead.innerHTML = HEAD_HTML[newMode];

            itemsTableBody.innerHTML = "";
            addItemRow();
        }

        addItemBtn.addEventListener("click", addItemRow);

        // switch items mode whenever the category changes
        expenseCategory.addEventListener("change", function () {
            switchItemsMode(getModeForCategory(this.value));
        });

        // start in text mode (default state, no category picked yet)
        switchItemsMode("text", { force: true });


        /*
        |--------------------------------------------------------------------------
        | RESET FORM WHEN MODAL CLOSES
        |--------------------------------------------------------------------------
        */

        const modalEl = document.getElementById("newStockRequestModal");

        modalEl.addEventListener("hidden.bs.modal", function () {

            document.getElementById("stockRequestForm").reset();
            toggleCategoryOther();
            switchItemsMode("text", { force: true });
        });


        /*
        |--------------------------------------------------------------------------
        | DATATABLE
        |--------------------------------------------------------------------------
        */

        if (typeof DataTable !== "undefined") {

            new DataTable("#stockRequestTable", {

                pageLength: 8,
                lengthChange: false,
                searching: true,
                ordering: true,
                info: true,
                paging: true,
                pagingType: "full_numbers",

                order: [[5, "desc"]],

                columnDefs: [
                    { orderable: false, targets: [6] }
                ],

                language: {
                    search: "",
                    searchPlaceholder: "Search...",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    infoEmpty: "Showing 0 of 0",
                    zeroRecords: "No stock requests found",
                    emptyTable: "No stock requests available",
                    paginate: {
                        first: "«",
                        previous: "‹",
                        next: "›",
                        last: "»"
                    }
                }
            });
        }

    });

</script>
