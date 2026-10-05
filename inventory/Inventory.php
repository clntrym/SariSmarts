<?php

require_once("../init.php");
requireRole(['inventory']);

$companyId = requireCompany();

$success = "";
$error = "";
$uploadDir = "../uploads/products/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_POST['saveProduct'])) {

    $product_name = trim($_POST['product_name'] ?? '');
    $category_id = (int) ($_POST['category'] ?? 0);
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($product_name === '') {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Product name is required."
        ];
        header("Location: Inventory.php");
        exit;
    }

    if ($category_id <= 0) {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please select a category."
        ];
        header("Location: Inventory.php");
        exit;
    }

    if ($supplier_id <= 0) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Please select a supplier."
        ];

        header("Location: Inventory.php");
        exit;
    }

    $categoryCheck = mysqli_prepare(
        $conn,
        "
        SELECT category_id
        FROM categories
        WHERE category_id = ? AND company_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $categoryCheck,
        "ii",
        $category_id,
        $companyId
    );

    mysqli_stmt_execute($categoryCheck);
    $categoryResult = mysqli_stmt_get_result($categoryCheck);

    if (!$categoryResult || mysqli_num_rows($categoryResult) === 0) {
        mysqli_stmt_close($categoryCheck);
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Category",
            "text" => "The selected category does not exist."
        ];
        header("Location: Inventory.php");
        exit;
    }

    mysqli_stmt_close($categoryCheck);

    $supplierCheck = mysqli_prepare(
        $conn,
        "
    SELECT supplier_id
    FROM suppliers
    WHERE supplier_id = ? AND company_id = ?
    LIMIT 1
    "
    );

    mysqli_stmt_bind_param(
        $supplierCheck,
        "ii",
        $supplier_id,
        $companyId
    );

    mysqli_stmt_execute($supplierCheck);

    $supplierResult = mysqli_stmt_get_result(
        $supplierCheck
    );

    if (
        !$supplierResult ||
        mysqli_num_rows($supplierResult) === 0
    ) {

        mysqli_stmt_close($supplierCheck);

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Supplier",
            "text" => "The selected supplier does not exist."
        ];

        header("Location: Inventory.php");
        exit;
    }

    mysqli_stmt_close($supplierCheck);

    $duplicateCheck = mysqli_prepare(
        $conn,
        "
        SELECT product_id
        FROM products
        WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND company_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $duplicateCheck,
        "si",
        $product_name,
        $companyId
    );

    mysqli_stmt_execute($duplicateCheck);
    $duplicateResult = mysqli_stmt_get_result($duplicateCheck);

    if ($duplicateResult && mysqli_num_rows($duplicateResult) > 0) {
        mysqli_stmt_close($duplicateCheck);
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Product Already Exists",
            "text" => "A product with this name already exists."
        ];
        header("Location: Inventory.php");
        exit;
    }

    mysqli_stmt_close($duplicateCheck);
    $imageName = "no-image.png";

    if (
        isset($_FILES['image']) &&
        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Image Upload Error",
                "text" => "There was a problem uploading the image."
            ];
            header("Location: Inventory.php");
            exit;
        }

        $filename = $_FILES['image']['name'];
        $tmpFile = $_FILES['image']['tmp_name'];
        $fileSize = $_FILES['image']['size'];
        $allowedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'webp'
        ];

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );

        if (!in_array($extension, $allowedExtensions, true)) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Invalid Image",
                "text" => "Only JPG, JPEG, PNG, and WEBP images are allowed."
            ];
            header("Location: Inventory.php");
            exit;
        }

        if ($fileSize > 2097152) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Image Too Large",
                "text" => "Image size must not exceed 2MB."
            ];
            header("Location: Inventory.php");
            exit;
        }

        $imageInfo = getimagesize($tmpFile);
        if ($imageInfo === false) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Invalid Image",
                "text" => "The uploaded file is not a valid image."
            ];
            header("Location: Inventory.php");
            exit;
        }

        $imageName =
            time() . "_" .
            bin2hex(random_bytes(5)) .
            "." .
            $extension;

        if (
            !move_uploaded_file(
                $tmpFile,
                $uploadDir . $imageName
            )
        ) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Upload Failed",
                "text" => "Unable to save the product image."
            ];
            header("Location: Inventory.php");
            exit;
        }
    }

    mysqli_begin_transaction($conn);
    try {
        $insertProduct = mysqli_prepare(
            $conn,
            "
            INSERT INTO products
            (
                company_id,
                product_name,
                category_id,
                supplier_id,
                description,
                image
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            "
        );

        if (!$insertProduct) {
            throw new Exception(
                "Failed to prepare product insertion: " .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $insertProduct,
            "isiiss",
            $companyId,
            $product_name,
            $category_id,
            $supplier_id,
            $description,
            $imageName
        );

        if (!mysqli_stmt_execute($insertProduct)) {
            throw new Exception(
                "Failed to save product: " .
                mysqli_stmt_error($insertProduct)
            );
        }

        $product_id = mysqli_insert_id($conn);
        mysqli_stmt_close($insertProduct);

        $quantity = 0;
        $purchase_cost = 0;
        $profit_markup = 0;
        $selling_price = 0;
        $reorder_level = 5;
        $purchase_date = date('Y-m-d');

        $insertInventory = mysqli_prepare(
            $conn,
            "
            INSERT INTO inventory
            (
                company_id,
                product_id,
                quantity,
                purchase_cost,
                profit_markup,
                selling_price,
                reorder_level,
                purchase_date
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            "
        );

        if (!$insertInventory) {
            throw new Exception(
                "Failed to prepare inventory insertion: " .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $insertInventory,
            "iiidddis",
            $companyId,
            $product_id,
            $quantity,
            $purchase_cost,
            $profit_markup,
            $selling_price,
            $reorder_level,
            $purchase_date
        );


        if (!mysqli_stmt_execute($insertInventory)) {
            throw new Exception(
                "Failed to create inventory record: " .
                mysqli_stmt_error($insertInventory)
            );
        }

        mysqli_stmt_close($insertInventory);
        mysqli_commit($conn);

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Product Added!",
            "text" => "Product has been added successfully with zero stock."
        ];

    } catch (Exception $e) {
        mysqli_rollback($conn);

        if (
            $imageName !== "no-image.png" &&
            file_exists($uploadDir . $imageName)
        ) {
            unlink($uploadDir . $imageName);
        }

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Failed",
            "text" => $e->getMessage()
        ];
    }

    header("Location: Inventory.php");
    exit;
}

if (isset($_POST['saveCategory'])) {
    $category_name = trim(
        $_POST['category_name'] ?? ''
    );

    if ($category_name === '') {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Validation Error",
            "text" => "Category name is required."
        ];
        header("Location: Inventory.php");
        exit;
    }


    $checkCategory = mysqli_prepare(
        $conn,
        "
        SELECT category_id
        FROM categories
        WHERE LOWER(TRIM(category_name))
              =
              LOWER(TRIM(?))
          AND company_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $checkCategory,
        "si",
        $category_name,
        $companyId
    );

    mysqli_stmt_execute($checkCategory);
    $categoryResult = mysqli_stmt_get_result(
        $checkCategory
    );

    if (
        $categoryResult &&
        mysqli_num_rows($categoryResult) > 0
    ) {

        mysqli_stmt_close($checkCategory);
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Category Already Exists",
            "text" => "That category already exists."
        ];
        header("Location: Inventory.php");
        exit;
    }

    mysqli_stmt_close($checkCategory);

    $insertCategory = mysqli_prepare(
        $conn,
        "
        INSERT INTO categories
        (company_id, category_name)
        VALUES
        (?, ?)
        "
    );

    mysqli_stmt_bind_param(
        $insertCategory,
        "is",
        $companyId,
        $category_name
    );

    if (mysqli_stmt_execute($insertCategory)) {
        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Category Added!",
            "text" => "Category has been added successfully."
        ];
    } else {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Failed",
            "text" => "Unable to add category."
        ];
    }


    mysqli_stmt_close($insertCategory);
    header("Location: Inventory.php");
    exit;
}

$search = trim(
    $_GET['search'] ?? ''
);

$sql = "
SELECT
    products.product_id,
    products.product_name,
    products.description,
    products.image,
    products.supplier_id,
    categories.category_name,
    suppliers.supplier_name,
    COALESCE(inventory.quantity, 0)
        AS quantity,
    COALESCE(inventory.purchase_cost, 0)
        AS purchase_cost,
    COALESCE(inventory.selling_price, 0)
        AS selling_price,
    COALESCE(inventory.profit_markup, 0)
        AS profit_markup,
    COALESCE(inventory.reorder_level, 5)
        AS reorder_level,
    inventory.purchase_date,
    (
        COALESCE(inventory.selling_price, 0)
        -
        COALESCE(inventory.purchase_cost, 0)
    )
        AS profit_per_item
FROM products
LEFT JOIN categories
    ON products.category_id = categories.category_id
   AND categories.company_id = products.company_id
LEFT JOIN suppliers
    ON products.supplier_id = suppliers.supplier_id
   AND suppliers.company_id = products.company_id
LEFT JOIN inventory
    ON products.product_id = inventory.product_id
   AND inventory.company_id = products.company_id
";

$where = ["products.company_id = " . (int) $companyId];

if ($search !== '') {
    $safeSearch = mysqli_real_escape_string(
        $conn,
        $search
    );
    $where[] = "
        (
            products.product_name
                LIKE '%$safeSearch%'
            OR products.description
                LIKE '%$safeSearch%'
            OR categories.category_name
                LIKE '%$safeSearch%'
            OR inventory.quantity
                LIKE '%$safeSearch%'
            OR inventory.purchase_cost
                LIKE '%$safeSearch%'
            OR inventory.selling_price
                LIKE '%$safeSearch%'
            OR inventory.reorder_level
                LIKE '%$safeSearch%'
        )
    ";
}

if (count($where) > 0) {
    $sql .=
        " WHERE " .
        implode(" AND ", $where);
}

$sql .= "
    ORDER BY products.product_id DESC
";

$inventoryQuery = mysqli_query(
    $conn,
    $sql
);

$categories = mysqli_query(
    $conn,
    "
    SELECT
        category_id,
        category_name
    FROM categories
    WHERE company_id = " . (int) $companyId . "
    ORDER BY category_name ASC
    "
);

$suppliers = mysqli_query(
    $conn,
    "
    SELECT
        supplier_id,
        supplier_name
    FROM suppliers
    WHERE company_id = " . (int) $companyId . "
    ORDER BY supplier_name ASC
    "
);

include("inventory_header.php");

?>


<!-- =========================================================
     INVENTORY PAGE
========================================================= -->

<div class="container-fluid px-0">

    <div class="d-flex justify-content-between align-items-start mb-3">

        <div>

            <h1 class="fw-bold mb-0" style="color:#00224c;">
                Inventory
            </h1>

            <div class="text-muted">
                Manage products and inventory information.
            </div>

        </div>


        <button type="button" class="btn text-white rounded-3 px-4" style="background:#00224c;" data-bs-toggle="modal"
            data-bs-target="#addProductModal">

            <i class="bi bi-plus-lg me-1"></i>

            Add Product

        </button>

    </div>


    <!-- =====================================================
         TABLE CONTAINER
    ====================================================== -->

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">



        <!-- =================================================
             TABLE
        ================================================== -->

        <div class="table-responsive">

            <table id="inventoryTable" class="table align-middle mb-0">

                <thead style="
                        background:#f1f5f9;
                        color:#475569;
                    ">

                    <tr>

                        <th class="px-4 py-3">
                            Product
                        </th>

                        <th class="py-3">
                            Category
                        </th>
                        <th class="py-3">
                            Supplier
                        </th>
                        <th class="py-3">
                            Stock Quantity
                        </th>

                        <th class="py-3">
                            Cost Per Item
                        </th>

                        <th class="py-3">
                            Selling Price
                        </th>

                        <th class="py-3">
                            Profit Per Item
                        </th>

                        <th class="py-3">
                            Low Stock
                        </th>

                        <th class="py-3">
                            Status
                        </th>

                        <th class="py-3">
                            Last Updated
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php

                    if (
                        $inventoryQuery &&
                        mysqli_num_rows($inventoryQuery) > 0
                    ):

                        while (
                            $row =
                            mysqli_fetch_assoc(
                                $inventoryQuery
                            )
                        ):

                            $quantity =
                                (int) $row['quantity'];

                            $reorderLevel =
                                (int) $row['reorder_level'];


                            /*
                            |--------------------------------------------------------------------------
                            | STATUS
                            |--------------------------------------------------------------------------
                            */

                            if ($quantity <= 0) {

                                $status = "Out";

                                $statusClass =
                                    "status-out";

                            } elseif (
                                $quantity <=
                                $reorderLevel
                            ) {

                                $status = "Low Stock";

                                $statusClass =
                                    "status-low";

                            } else {

                                $status = "In Stock";

                                $statusClass =
                                    "status-ok";
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | LAST UPDATED
                            |--------------------------------------------------------------------------
                            */

                            $lastUpdated =
                                !empty(
                                $row['purchase_date']
                            )
                                ? date(
                                    'M d, Y',
                                    strtotime(
                                        $row['purchase_date']
                                    )
                                )
                                : '-';

                            ?>

                            <tr class="inventory-row">

                                <!-- PRODUCT -->

                                <td class="px-4 py-3">

                                    <div class="fw-semibold" style="
                                    color:#0f172a;
                                    font-size:15px;
                                ">

                                        <?= htmlspecialchars(
                                            $row['product_name']
                                        ) ?>

                                    </div>


                                    <?php if (
                                        !empty(
                                        $row['description']
                                    )
                                    ): ?>

                                        <div class="small text-muted mt-1" style="
                                        max-width:260px;
                                        white-space:nowrap;
                                        overflow:hidden;
                                        text-overflow:ellipsis;
                                    ">

                                            <?= htmlspecialchars(
                                                $row['description']
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- CATEGORY -->

                                <td class="py-3">

                                    <?= htmlspecialchars(
                                        $row['category_name']
                                        ?? 'Uncategorized'
                                    ) ?>

                                </td>

                                <td class="py-3">

                                    <?= htmlspecialchars(
                                        $row['supplier_name']
                                        ?? 'No Supplier'
                                    ) ?>

                                </td>


                                <!-- STOCK -->

                                <td class="py-3 fw-semibold">

                                    <?= number_format(
                                        $quantity
                                    ) ?>

                                </td>


                                <!-- COST -->

                                <td class="py-3">

                                    ₱<?= number_format(
                                        (float) $row['purchase_cost'],
                                        2
                                    ) ?>

                                </td>


                                <!-- SELLING -->

                                <td class="py-3">

                                    ₱<?= number_format(
                                        (float) $row['selling_price'],
                                        2
                                    ) ?>

                                </td>


                                <!-- PROFIT -->

                                <td class="py-3">

                                    ₱<?= number_format(
                                        (float) $row['profit_per_item'],
                                        2
                                    ) ?>

                                </td>


                                <!-- LOW STOCK -->

                                <td class="py-3">

                                    <?= number_format(
                                        $reorderLevel
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td class="py-3">

                                    <span class="inventory-status <?= $statusClass ?>">

                                        <?= htmlspecialchars(
                                            $status
                                        ) ?>

                                    </span>

                                </td>


                                <!-- LAST UPDATED -->

                                <td class="py-3">

                                    <?= htmlspecialchars(
                                        $lastUpdated
                                    ) ?>

                                </td>

                            </tr>

                            <?php

                        endwhile;

                    else:

                        ?>

                        <tr>

                            <td colspan="10" class="text-center py-5 text-muted">

                                <i class="bi bi-box-seam fs-1 d-block mb-2">
                                </i>

                                No products found.

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- =================================================
             FOOTER / PAGINATION
        ================================================== -->

        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top">

            <div id="tableInfo" class="text-muted small">

                Showing 0 of 0

            </div>


            <div class="d-flex align-items-center gap-3">

                <button type="button" id="prevPage" class="btn btn-sm border-0">

                    <i class="bi bi-chevron-left"></i>

                </button>


                <span id="pageInfo" class="small text-muted">

                    Page 1 of 1

                </span>


                <button type="button" id="nextPage" class="btn btn-sm border-0">

                    <i class="bi bi-chevron-right"></i>

                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     ADD PRODUCT MODAL
========================================================= -->

<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow rounded-4">

            <!-- HEADER -->

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold" style="color:#00224c;">

                        <i class="bi bi-box-seam me-2">
                        </i>

                        Add Product

                    </h5>


                    <small class="text-muted">

                        Add a new product to the inventory.

                    </small>

                </div>


                <button type="button" class="btn-close" data-bs-dismiss="modal">
                </button>

            </div>


            <!-- FORM -->

            <form method="POST" enctype="multipart/form-data" id="addProductForm">

                <div class="modal-body">

                    <div class="row g-4">


                        <!-- PRODUCT NAME -->

                        <div class="col-md-7">

                            <label class="form-label fw-semibold">

                                Product Name
                                <span class="text-danger">*</span>

                            </label>


                            <input type="text" name="product_name" class="form-control" placeholder="Enter product name"
                                maxlength="150" required>

                        </div>


                        <!-- CATEGORY -->

                        <div class="col-md-5">

                            <label class="form-label fw-semibold">

                                Category
                                <span class="text-danger">*</span>

                            </label>


                            <div class="input-group">

                                <select name="category" id="productCategory" class="form-select" required>

                                    <option value="">
                                        Select Category
                                    </option>

                                    <?php

                                    if ($categories):

                                        while (
                                            $category =
                                            mysqli_fetch_assoc(
                                                $categories
                                            )
                                        ):

                                            ?>

                                            <option value="<?= (int) $category['category_id'] ?>">

                                                <?= htmlspecialchars(
                                                    $category['category_name']
                                                ) ?>

                                            </option>

                                            <?php

                                        endwhile;

                                    endif;

                                    ?>

                                </select>


                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                                    data-bs-target="#addCategoryModal">

                                    <i class="bi bi-plus-lg"></i>

                                </button>

                            </div>

                        </div>

                        <!-- SUPPLIER -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                Supplier
                                <span class="text-danger">*</span>
                            </label>
                            <select name="supplier_id" id="productSupplier" class="form-select" required>
                                <option value="">
                                    Select Supplier
                                </option>
                                <?php if ($suppliers): ?>
                                    <?php while ($supplier = mysqli_fetch_assoc($suppliers)): ?>
                                        <option value="<?= (int) $supplier['supplier_id'] ?>">
                                            <?= htmlspecialchars(
                                                $supplier['supplier_name']
                                            ) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- DESCRIPTION -->

                        <div class="col-12">

                            <label class="form-label fw-semibold">

                                Description

                            </label>


                            <textarea name="description" class="form-control" rows="3" maxlength="500"
                                placeholder="Enter product description">
                            </textarea>

                        </div>


                        <!-- IMAGE -->

                        <div class="col-12">

                            <label class="form-label fw-semibold">

                                Product Picture

                            </label>


                            <input type="file" name="image" id="productImage" class="form-control"
                                accept=".jpg,.jpeg,.png,.webp">


                            <div class="form-text">

                                JPG, JPEG, PNG, or WEBP.
                                Maximum size: 2MB.

                            </div>

                        </div>


                        <!-- IMAGE PREVIEW -->

                        <div class="col-12 text-center" id="imagePreviewContainer" style="display:none;">

                            <img id="imagePreview" src="" alt="Product Preview" style="
                                    max-width:180px;
                                    max-height:180px;
                                    object-fit:contain;
                                    border-radius:12px;
                                    border:1px solid #e2e8f0;
                                    padding:5px;
                                ">

                        </div>


                        <!-- DEFAULT STOCK INFORMATION -->

                        <div class="col-12">

                            <div class="alert alert-light border rounded-3 mb-0">

                                <div class="d-flex gap-3">

                                    <i class="bi bi-info-circle text-primary fs-5">
                                    </i>


                                    <div>

                                        <div class="fw-semibold">

                                            Initial Inventory

                                        </div>


                                        <div class="small text-muted">

                                            New products will automatically
                                            start with
                                            <strong>0 stock</strong>.
                                            Stock and purchasing costs will
                                            be handled through the stock
                                            request module.

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- FOOTER -->

                <div class="modal-footer">

                    <button type="button" class="btn btn-light border rounded-3" data-bs-dismiss="modal">

                        Cancel

                    </button>


                    <button type="submit" name="saveProduct" class="btn text-white rounded-3 px-4"
                        style="background:#00224c;">

                        <i class="bi bi-plus-lg me-1">
                        </i>

                        Add Product

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     ADD CATEGORY MODAL
========================================================= -->

<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow rounded-4">

            <div class="modal-header">

                <h5 class="modal-title fw-bold" style="color:#00224c;">

                    Add Category

                </h5>


                <button type="button" class="btn-close" data-bs-dismiss="modal">
                </button>

            </div>


            <form method="POST">

                <div class="modal-body">

                    <label class="form-label fw-semibold">

                        Category Name

                    </label>


                    <input type="text" name="category_name" class="form-control" placeholder="Enter category name"
                        maxlength="100" required>

                </div>


                <div class="modal-footer">

                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">

                        Cancel

                    </button>


                    <button type="submit" name="saveCategory" class="btn text-white" style="background:#00224c;">

                        Save Category

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     CSS
========================================================= -->

<style>
    #inventoryTable {
        min-width: 1200px;
    }


    #inventoryTable thead th {
        font-weight: 600;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
    }


    #inventoryTable tbody tr {
        border-bottom: 1px solid #e2e8f0;
    }


    #inventoryTable tbody tr:hover {
        background: #f8fafc;
    }


    .inventory-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }


    .status-ok {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }


    .status-low {
        background: #fef3c7;
        color: #b45309;
        border: 1px solid #fde68a;
    }


    .status-out {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }


    #prevPage,
    #nextPage {
        color: #64748b;
    }


    #prevPage:hover,
    #nextPage:hover {
        background: #f1f5f9;
    }


    #prevPage:disabled,
    #nextPage:disabled {
        opacity: .35;
        cursor: not-allowed;
    }


    .modal-content {
        overflow: hidden;
    }
</style>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

    document.addEventListener("DOMContentLoaded", function () {


        /*
        |--------------------------------------------------------------------------
        | IMAGE PREVIEW
        |--------------------------------------------------------------------------
        */

        const imageInput =
            document.getElementById("productImage");

        const imagePreview =
            document.getElementById("imagePreview");

        const imagePreviewContainer =
            document.getElementById(
                "imagePreviewContainer"
            );


        if (imageInput) {

            imageInput.addEventListener(
                "change",
                function () {

                    const file =
                        this.files[0];

                    if (!file) {

                        imagePreviewContainer.style.display =
                            "none";

                        imagePreview.src = "";

                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | SIZE CHECK
                    |--------------------------------------------------------------------------
                    */

                    if (
                        file.size >
                        2097152
                    ) {

                        Swal.fire({
                            icon: "error",
                            title: "Image Too Large",
                            text: "Image size must not exceed 2MB.",
                            confirmButtonColor: "#00224c"
                        });

                        this.value = "";

                        imagePreviewContainer.style.display =
                            "none";

                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | PREVIEW
                    |--------------------------------------------------------------------------
                    */

                    const reader =
                        new FileReader();


                    reader.onload =
                        function (event) {

                            imagePreview.src =
                                event.target.result;

                            imagePreviewContainer.style.display =
                                "block";
                        };


                    reader.readAsDataURL(file);

                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | DATATABLE INIT
        |--------------------------------------------------------------------------
        */

        new DataTable("#inventoryTable", {
            pageLength: 10,
            lengthChange: false,
            ordering: true,
            order: [],
            scrollX: true,
            columnDefs: [{ orderable: false, targets: -1 }],
            language: {
                search: "",
                searchPlaceholder: "Search...",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Previous", next: "Next" }
            }
        });

    });

</script>


<?php

/*
|--------------------------------------------------------------------------
| SWEETALERT SESSION MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['alert'])) {

    $alert =
        $_SESSION['alert'];

    unset($_SESSION['alert']);

    ?>

    <script>

        Swal.fire({
            icon: <?= json_encode(
                $alert['icon']
            ) ?>,
            title: <?= json_encode(
                $alert['title']
            ) ?>,
            text: <?= json_encode(
                $alert['text']
            ) ?>,
            confirmButtonColor: "#00224c"
        });

    </script>

    <?php

}

?>

<?php include("inventory_footer.php"); ?>