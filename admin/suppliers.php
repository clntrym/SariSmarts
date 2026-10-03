<?php

require_once("../init.php");
requireRole(['admin']);

$companyId = requireCompany();

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$success = "";
$error = "";


/*
|--------------------------------------------------------------------------
| ADD SUPPLIER
|--------------------------------------------------------------------------
*/

if (isset($_POST['saveSupplier'])) {

    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');

    if ($supplier_name === '') {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Supplier name is required."];
        header("Location: suppliers.php");
        exit;
    }
    if (preg_match('/^[^a-zA-Z0-9\s]+$/', $supplier_name)) {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Supplier name cannot contain only special characters."];
        header("Location: suppliers.php");
        exit;
    }
    if ($contact_email === '') {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Contact email is required."];
        header("Location: suppliers.php");
        exit;
    }
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Email", "text" => "Please enter a valid email address."];
        header("Location: suppliers.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE SUPPLIER
    |--------------------------------------------------------------------------
    */

    $check = $conn->prepare("
        SELECT supplier_id
        FROM suppliers
        WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
          AND company_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "si",
        $supplier_name,
        $companyId
    );

    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows > 0) {

        $check->close();

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Supplier Already Exists",
            "text" => "A supplier with this name already exists."
        ];

        header("Location: suppliers.php");
        exit;
    }

    $check->close();


    /*
    |--------------------------------------------------------------------------
    | INSERT SUPPLIER
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO suppliers
        (
            company_id,
            supplier_name,
            contact_email
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare supplier registration."
        ];

        header("Location: suppliers.php");
        exit;
    }

    $stmt->bind_param(
        "iss",
        $companyId,
        $supplier_name,
        $contact_email
    );

    if ($stmt->execute()) {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Supplier Added",
            "text" => "Supplier has been added successfully."
        ];

    } else {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Failed",
            "text" => "Failed to add supplier."
        ];
    }

    header("Location: suppliers.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE SUPPLIER
|--------------------------------------------------------------------------
*/

if (isset($_POST['updateSupplier'])) {

    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');

    if ($supplier_id <= 0) {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Supplier", "text" => "Invalid supplier ID."];
        header("Location: suppliers.php");
        exit;
    }
    if ($supplier_name === '') {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Supplier name is required."];
        header("Location: suppliers.php");
        exit;
    }
    if (preg_match('/^[^a-zA-Z0-9\s]+$/', $supplier_name)) {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Supplier name cannot contain only special characters."];
        header("Location: suppliers.php");
        exit;
    }
    if ($contact_email === '') {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Validation Error", "text" => "Contact email is required."];
        header("Location: suppliers.php");
        exit;
    }
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['alert'] = ["icon" => "error", "title" => "Invalid Email", "text" => "Please enter a valid email address."];
        header("Location: suppliers.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE SUPPLIER
    |--------------------------------------------------------------------------
    */

    $check = $conn->prepare("
        SELECT supplier_id
        FROM suppliers
        WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))
          AND supplier_id != ?
          AND company_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "sii",
        $supplier_name,
        $supplier_id,
        $companyId
    );

    $check->execute();

    $result = $check->get_result();

    if ($result->num_rows > 0) {

        $check->close();

        $_SESSION['alert'] = [
            "icon" => "warning",
            "title" => "Supplier Already Exists",
            "text" => "Another supplier with this name already exists."
        ];

        header("Location: suppliers.php");
        exit;
    }

    $check->close();


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE suppliers
        SET
            supplier_name = ?,
            contact_email = ?
        WHERE supplier_id = ? AND company_id = ?
    ");

    if (!$stmt) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Database Error",
            "text" => "Unable to prepare supplier update."
        ];

        header("Location: suppliers.php");
        exit;
    }

    $stmt->bind_param(
        "ssii",
        $supplier_name,
        $contact_email,
        $supplier_id,
        $companyId
    );

    if ($stmt->execute()) {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Supplier Updated",
            "text" => "Supplier information has been updated successfully."
        ];

    } else {

        $stmt->close();

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Update Failed",
            "text" => "Failed to update supplier."
        ];
    }

    header("Location: suppliers.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET SUPPLIERS
|--------------------------------------------------------------------------
*/

$suppliers = [];

$query = $conn->query("
    SELECT
        supplier_id,
        supplier_name,
        contact_email,
        created_at,
        updated_at
    FROM suppliers
    WHERE company_id = " . (int) $companyId . "
    ORDER BY supplier_id DESC
");

if ($query) {

    while ($row = $query->fetch_assoc()) {
        $suppliers[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

include("admin_header.php");

?>

<style>
    /* =========================================================
   SUPPLIER TABLE
========================================================= */

    #supplierTable {
        min-width: 850px;
        width: 100%;
    }

    #supplierTable thead th {
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
    }

    #supplierTable tbody tr {
        border-bottom: 1px solid #e2e8f0;
    }

    #supplierTable tbody tr:hover {
        background: #f8fafc;
    }

    #supplierTable tbody tr:last-child {
        border-bottom: none;
    }




    /* =========================================================
   SUPPLIER CARD
========================================================= */

    .supplier-table-card {
        background: #fff;
        border: 1px solid #dce3ea;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 34, 76, 0.05);
    }


    /* =========================================================
   SUPPLIER NAME
========================================================= */

    .supplier-name {
        font-weight: 600;
        color: #00224c;
    }

    .supplier-id {
        font-size: 12px;
        color: #8290a0;
        margin-top: 2px;
    }

    .supplier-email {
        color: #53657a;
    }


    /* =========================================================
   EDIT BUTTON
========================================================= */

    .btn-edit-supplier {
        width: 38px;
        height: 38px;

        border: 1px solid #d9e0e7;
        background: #fff;
        color: #00224c;

        border-radius: 9px;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        transition: .2s;
    }

    .btn-edit-supplier:hover {
        background: #00224c;
        color: #fff;
        border-color: #00224c;
    }


    /* =========================================================
   MOBILE
========================================================= */

    @media (max-width: 768px) {

        #supplierTable {
            min-width: 850px;
        }

    }
</style>

<style>
</style>

<div class="container-fluid supplier-page">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">

        <div>
            <h1 class="supplier-title">
                Suppliers
            </h1>

            <div class="supplier-subtitle">
                Manage your product suppliers.
            </div>
        </div>

        <button type="button" class="btn btn-add-supplier" data-bs-toggle="modal" data-bs-target="#addSupplierModal">

            <i class="bi bi-plus-lg me-1"></i>
            Add Supplier

        </button>

    </div>


    <!-- =====================================================
         TABLE
    ====================================================== -->

    <div class="supplier-table-card">

        <div class="table-responsive">

            <table id="supplierTable" class="table align-middle mb-0">

                <thead>

                    <tr>

                        <th class="px-4 py-3">
                            Supplier
                        </th>

                        <th class="py-3">
                            Contact Email
                        </th>

                        <th class="py-3">
                            Last Updated
                        </th>

                        <th class="py-3 text-center">
                            Action
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!empty($suppliers)): ?>

                        <?php foreach ($suppliers as $supplier): ?>

                            <tr class="supplier-row">

                                <!-- SUPPLIER -->

                                <td class="px-4 py-3">

                                    <div class="supplier-name">

                                        <?= htmlspecialchars(
                                            $supplier['supplier_name']
                                        ) ?>

                                    </div>

                                    <div class="supplier-id">

                                        SUP-
                                        <?= str_pad(
                                            $supplier['supplier_id'],
                                            3,
                                            '0',
                                            STR_PAD_LEFT
                                        ) ?>

                                    </div>

                                </td>


                                <!-- EMAIL -->

                                <td class="py-3">

                                    <span class="supplier-email">

                                        <?= htmlspecialchars(
                                            $supplier['contact_email']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- LAST UPDATED -->

                                <td class="py-3" data-order="<?= htmlspecialchars(
                                    $supplier['updated_at']
                                ) ?>">

                                    <?= date(
                                        'M d, Y',
                                        strtotime($supplier['updated_at'])
                                    ) ?>

                                </td>


                                <!-- ACTION -->

                                <td class="py-3 text-center">

                                    <button type="button" class="btn-edit-supplier editSupplierBtn" title="Edit Supplier"
                                        data-id="<?= (int) $supplier['supplier_id'] ?>" data-name="<?= htmlspecialchars(
                                               $supplier['supplier_name'],
                                               ENT_QUOTES
                                           ) ?>" data-email="<?= htmlspecialchars(
                                                $supplier['contact_email'],
                                                ENT_QUOTES
                                            ) ?>" data-bs-toggle="modal" data-bs-target="#editSupplierModal">

                                        <i class="bi bi-pencil"></i>

                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="4" class="text-center py-5 text-muted">

                                <i class="bi bi-building fs-1 d-block mb-2"></i>

                                No suppliers found.

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


    </div>

</div>


<!-- =========================================================
     ADD SUPPLIER MODAL
========================================================= -->

<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="suppliers.php">

                <div class="modal-header">

                    <div>

                        <h5 class="modal-title fw-bold">

                            <i class="bi bi-building me-2"></i>

                            Add Supplier

                        </h5>

                        <small class="text-muted">

                            Add a new supplier to your system.

                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>


                <div class="modal-body">

                    <!-- SUPPLIER NAME -->

                    <div class="mb-3">

                        <label class="form-label">

                            Supplier Name

                        </label>

                        <input type="text" name="supplier_name" class="form-control" placeholder="Enter supplier name"
                            maxlength="150" required>

                    </div>


                    <!-- EMAIL -->

                    <div class="mb-3">

                        <label class="form-label">

                            Contact Email

                        </label>

                        <input type="email" name="contact_email" class="form-control" placeholder="supplier@example.com"
                            maxlength="150" required>

                    </div>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="saveSupplier" class="btn btn-primary"
                        style="background:#00224c;border-color:#00224c;">

                        <i class="bi bi-save me-1"></i>

                        Save Supplier

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     EDIT SUPPLIER MODAL
========================================================= -->

<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" action="suppliers.php">

                <input type="hidden" name="supplier_id" id="edit_supplier_id">

                <div class="modal-header">

                    <div>

                        <h5 class="modal-title fw-bold">

                            <i class="bi bi-pencil-square me-2"></i>

                            Edit Supplier

                        </h5>

                        <small class="text-muted">

                            Update supplier information.

                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>


                <div class="modal-body">

                    <!-- SUPPLIER NAME -->

                    <div class="mb-3">

                        <label class="form-label">

                            Supplier Name

                        </label>

                        <input type="text" name="supplier_name" id="edit_supplier_name" class="form-control"
                            maxlength="150" required>

                    </div>


                    <!-- EMAIL -->

                    <div class="mb-3">

                        <label class="form-label">

                            Contact Email

                        </label>

                        <input type="email" name="contact_email" id="edit_contact_email" class="form-control"
                            maxlength="150" required>

                    </div>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="updateSupplier" class="btn btn-primary"
                        style="background:#00224c;border-color:#00224c;">

                        <i class="bi bi-check-lg me-1"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll(".editSupplierBtn").forEach(function (button) {
        button.addEventListener("click", function () {
            document.getElementById("edit_supplier_id").value = this.getAttribute("data-id");
            document.getElementById("edit_supplier_name").value = this.getAttribute("data-name");
            document.getElementById("edit_contact_email").value = this.getAttribute("data-email");
        });
    });

    new DataTable("#supplierTable", {
        pageLength: 10,
        lengthChange: false,
        ordering: true,
        order: [],
        columnDefs: [{ orderable: false, targets: 3 }],
        language: {
            search: "",
            searchPlaceholder: "Search supplier...",
            info: "Showing _START_ to _END_ of _TOTAL_",
            infoEmpty: "No records",
            zeroRecords: "No matching suppliers",
            emptyTable: "No suppliers found",
            paginate: { previous: "Previous", next: "Next" }
        }
    });

});
</script>


<!-- =========================================================
     SESSION ALERT
========================================================= -->

<?php if (isset($_SESSION['alert'])): ?>

    <script>

        Swal.fire({

            icon: <?= json_encode(
                $_SESSION['alert']['icon']
            ) ?>,

            title: <?= json_encode(
                $_SESSION['alert']['title']
            ) ?>,

            text: <?= json_encode(
                $_SESSION['alert']['text']
            ) ?>,

            confirmButtonColor: '#00224c'

        });

    </script>

    <?php

    unset($_SESSION['alert']);

endif;

?>

<?php include("admin_footer.php"); ?>