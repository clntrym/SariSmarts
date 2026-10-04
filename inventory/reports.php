<?php

/*
 * REPORTS - INVENTORY
 *
 * This page is new. The inventory sidebar's Reports link pointed at
 * ../admin/reports.php, which begins with requireRole(['admin']), so clicking
 * it sent the stock keeper back to their own dashboard every time. The link
 * had never worked.
 *
 * What an inventory role needs to answer:
 *   - what is on the shelf, and what runs out next
 *   - which of my requests is stuck, and with whom
 *   - what actually arrived, and when
 *   - which supplier carries what
 *   - what is selling, so the next request is the right one
 *
 * NO BRANCH FILTER
 *   inventory, products and stock_requests carry no branch_id. A picker here
 *   would change nothing.
 */

require_once("../init.php");
requireRole(['inventory', 'admin']);

/*
| The owner reaches this too, and keeps their own sidebar.
|
| The first pass at this matched requireRole(['admin']) inside the
| comment at the top of the file rather than the real guard below it,
| so the page still refused the owner and rendered nothing at all.
*/
require_once __DIR__ . '/../includes/role_chrome.php';

$companyId = requireCompany();

require_once(__DIR__ . "/../includes/report_kit.php");

$range = reportRange();

$companyName = (string) reportValue(
    $conn,
    "SELECT company_name FROM company WHERE company_id = ?",
    [$companyId],
    'i',
    'Business'
);

$reports = [];

/* How long the period is, for the cover calculation further down. An
   open-ended range falls back to the spread of the data itself. */
if ($range['from'] !== null && $range['to'] !== null) {
    $periodDays = max(1, (int) ((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1);
} else {
    $periodDays = max(1, (int) reportValue(
        $conn,
        "SELECT GREATEST(DATEDIFF(MAX(sale_date), MIN(sale_date)) + 1, 1)
         FROM sales WHERE company_id = ?",
        [$companyId],
        'i',
        30
    ));
}


/* ===================================================================
 * SHARED - the stock position, used by two tabs
 * =================================================================== */

$stockRows = reportRows($conn, "
    SELECT p.product_id,
           p.product_name,
           COALESCE(c.category_name, 'Uncategorised') AS category_name,
           COALESCE(sp.supplier_name, 'No supplier') AS supplier_name,
           SUM(i.quantity) AS qty,
           MAX(i.reorder_level) AS reorder_level,
           AVG(i.purchase_cost) AS unit_cost,
           AVG(i.selling_price) AS selling_price,
           SUM(i.quantity * i.purchase_cost) AS stock_value,
           MAX(i.purchase_date) AS last_in
    FROM inventory i
    INNER JOIN products p
            ON p.product_id = i.product_id AND p.company_id = i.company_id
    LEFT JOIN categories c
            ON c.category_id = p.category_id AND c.company_id = p.company_id
    LEFT JOIN suppliers sp
            ON sp.supplier_id = p.supplier_id AND sp.company_id = p.company_id
    WHERE i.company_id = ?
    GROUP BY p.product_id, p.product_name, c.category_name, sp.supplier_name
    ORDER BY p.product_name ASC
", [$companyId], 'i');

$stockValue = 0.0;
$outOfStock = 0;
$lowStock = 0;
$restock = [];

foreach ($stockRows as $index => $row) {

    $qty = (int) $row['qty'];
    $reorder = (int) $row['reorder_level'];

    if ($qty <= 0) {
        $state = 'Out of stock';
        $outOfStock++;
    } elseif ($qty <= $reorder) {
        $state = 'Low';
        $lowStock++;
    } else {
        $state = 'Healthy';
    }

    $stockRows[$index]['state'] = $state;

    /* How many to buy to get back above the reorder level, with a little
       headroom - ordering exactly to the line means being low again at once. */
    $stockRows[$index]['suggest'] = $state === 'Healthy'
        ? 0
        : max(1, ($reorder * 2) - $qty);

    $stockValue += (float) $row['stock_value'];

    if ($state !== 'Healthy') {
        $restock[] = $stockRows[$index];
    }
}

$byCategoryValue = [];
foreach ($stockRows as $row) {
    $byCategoryValue[$row['category_name']] = ($byCategoryValue[$row['category_name']] ?? 0)
        + (float) $row['stock_value'];
}
arsort($byCategoryValue);

$stateMix = [
    'Healthy' => count($stockRows) - $lowStock - $outOfStock,
    'Low' => $lowStock,
    'Out of stock' => $outOfStock,
];

$stateBadges = ['Healthy' => 'success', 'Low' => 'warning', 'Out of stock' => 'danger'];


/* ===================================================================
 * 1. STOCK ON HAND
 * =================================================================== */

$reports[] = [
    'id'      => 'stock',
    'timeless' => true,
    'label'   => 'Stock on Hand',
    'icon'    => 'bi-boxes',
    'blurb'   => 'Everything on the shelf right now. Outside the period filter - stock is a position, not a period.',
    'empty'   => 'No product has stock on record. Stock appears once a delivery is received.',
    'kpis'    => [
        ['label' => 'Stock value', 'value' => reportPeso($stockValue),
         'icon' => 'bi-safe', 'tone' => 'success', 'hint' => 'at purchase cost'],
        ['label' => 'Products tracked', 'value' => reportNumber(count($stockRows)),
         'icon' => 'bi-boxes', 'tone' => 'primary'],
        ['label' => 'Units on hand',
         'value' => reportNumber(array_sum(array_column($stockRows, 'qty'))),
         'icon' => 'bi-123', 'tone' => 'info'],
        ['label' => 'Retail value',
         'value' => reportPeso(array_sum(array_map(static function (array $r) {
             return (float) $r['qty'] * (float) $r['selling_price'];
         }, $stockRows))),
         'icon' => 'bi-tag', 'tone' => 'warning', 'hint' => 'if it all sold'],
    ],
    'visuals' => [
        ['title' => 'Stock value by category', 'span' => 7,
         'body' => reportBars(array_slice($byCategoryValue, 0, 8, true), 'peso', 'success')],
        ['title' => 'Shelf health', 'span' => 5,
         'body' => reportDonut($stateMix, [
             'Healthy' => '#198754', 'Low' => '#ffc107', 'Out of stock' => '#dc3545',
         ])],
    ],
    'columns' => [
        ['head' => 'Product',     'key' => 'product_name'],
        ['head' => 'Category',    'key' => 'category_name'],
        ['head' => 'Supplier',    'key' => 'supplier_name'],
        ['head' => 'On hand',     'key' => 'qty',            'type' => 'number'],
        ['head' => 'Reorder at',  'key' => 'reorder_level',  'type' => 'number'],
        ['head' => 'State',       'key' => 'state',          'type' => 'badge',
         'badges' => $stateBadges],
        ['head' => 'Unit cost',   'key' => 'unit_cost',      'type' => 'peso'],
        ['head' => 'Sells at',    'key' => 'selling_price',  'type' => 'peso'],
        ['head' => 'Stock value', 'key' => 'stock_value',    'type' => 'peso'],
        ['head' => 'Last in',     'key' => 'last_in',        'type' => 'date'],
    ],
    'totals'  => [
        'qty'         => array_sum(array_column($stockRows, 'qty')),
        'stock_value' => $stockValue,
    ],
    'rows'    => $stockRows,
];


/* ===================================================================
 * 2. NEEDS RESTOCKING - the action list
 * =================================================================== */

/*
| A separate tab rather than a filter on the one above, because this is the
| list the stock keeper works FROM: the empty shelves first, then the ones
| about to be empty, with a suggested quantity against each. Sorting it by
| product name, as the full list is, would bury the urgent rows.
*/
usort($restock, static function (array $a, array $b) {
    return [(int) $a['qty'], $a['product_name']] <=> [(int) $b['qty'], $b['product_name']];
});

$restockCost = 0.0;
foreach ($restock as $row) {
    $restockCost += (float) $row['suggest'] * (float) $row['unit_cost'];
}

$restockBars = [];
foreach (array_slice($restock, 0, 10) as $row) {
    $restockBars[$row['product_name']] = (int) $row['suggest'];
}

$reports[] = [
    'id'      => 'restock',
    'timeless' => true,
    'label'   => 'Needs Restocking',
    'icon'    => 'bi-exclamation-triangle',
    'blurb'   => 'Out of stock first, then low. The suggested quantity brings each one back to twice its reorder level.',
    'empty'   => 'Nothing is low or out of stock. Every product is above its reorder level.',
    'kpis'    => [
        ['label' => 'Out of stock', 'value' => reportNumber($outOfStock),
         'icon' => 'bi-x-octagon', 'tone' => $outOfStock ? 'danger' : 'success'],
        ['label' => 'Running low', 'value' => reportNumber($lowStock),
         'icon' => 'bi-exclamation-triangle', 'tone' => $lowStock ? 'warning' : 'success'],
        ['label' => 'Units to order',
         'value' => reportNumber(array_sum(array_column($restock, 'suggest'))),
         'icon' => 'bi-cart-plus', 'tone' => 'primary'],
        ['label' => 'Estimated cost', 'value' => reportPeso($restockCost),
         'icon' => 'bi-cash', 'tone' => 'info', 'hint' => 'at current unit cost'],
    ],
    'visuals' => [
        ['title' => 'Suggested order quantities', 'span' => 12,
         'body' => reportBars($restockBars, 'number', 'danger')],
    ],
    'columns' => [
        ['head' => 'Product',    'key' => 'product_name'],
        ['head' => 'Supplier',   'key' => 'supplier_name'],
        ['head' => 'On hand',    'key' => 'qty',           'type' => 'number'],
        ['head' => 'Reorder at', 'key' => 'reorder_level', 'type' => 'number'],
        ['head' => 'State',      'key' => 'state',         'type' => 'badge',
         'badges' => $stateBadges],
        ['head' => 'Suggest',    'key' => 'suggest',       'type' => 'number'],
        ['head' => 'Unit cost',  'key' => 'unit_cost',     'type' => 'peso'],
        ['head' => 'Last in',    'key' => 'last_in',       'type' => 'date'],
    ],
    'totals'  => ['suggest' => array_sum(array_column($restock, 'suggest'))],
    'rows'    => $restock,
];


/* ===================================================================
 * 3. MY STOCK REQUESTS - and where each one is stuck
 * =================================================================== */

$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'sr.created_at', $params, $types);

$requestRows = reportRows($conn, "
    SELECT sr.request_code, sr.created_at, sr.reason,
           sr.expense_category, sr.category_other, sr.payment_type,
           sr.total_price, sr.status,
           COALESCE(cu.fullname, 'Unknown') AS raised_by,
           sr.finance_approved_at, sr.admin_approved_at, sr.received_at,
           sr.finance_remarks, sr.admin_remarks,
           COUNT(sri.item_id) AS item_lines,
           COALESCE(SUM(sri.quantity), 0) AS units
    FROM stock_requests sr
    LEFT JOIN users cu
           ON cu.user_id = sr.created_by AND cu.company_id = sr.company_id
    LEFT JOIN stock_request_items sri
           ON sri.request_id = sr.request_id AND sri.company_id = sr.company_id
    WHERE sr.company_id = ?$clause
    GROUP BY sr.request_id, sr.request_code, sr.created_at, sr.reason,
             sr.expense_category, sr.category_other, sr.payment_type,
             sr.total_price, sr.status, cu.fullname,
             sr.finance_approved_at, sr.admin_approved_at, sr.received_at,
             sr.finance_remarks, sr.admin_remarks
    ORDER BY sr.created_at DESC
", $params, $types);

$awaiting = 0;
$requestStages = [];

foreach ($requestRows as $index => $row) {

    $status = (string) $row['status'];

    /* Said in words, because "Pending Admin" on its own does not tell the
       person who raised it what happens next or who is holding it. */
    $waitingOn = match ($status) {
        'Pending Finance'  => 'Waiting for Finance',
        'Pending Admin'    => 'Waiting for Admin',
        'Finance Approved' => 'Waiting for Admin',
        'Admin Approved'   => 'Ready to receive',
        'Received'         => 'Done',
        'Rejected'         => 'Rejected',
        default            => $status !== '' ? $status : 'Unknown',
    };

    $requestRows[$index]['waiting_on'] = $waitingOn;

    /* The remark that explains a rejection, whichever desk wrote it. */
    $note = '';
    if (!empty($row['admin_remarks'])) {
        $note = 'Admin: ' . $row['admin_remarks'];
    } elseif (!empty($row['finance_remarks'])) {
        $note = 'Finance: ' . $row['finance_remarks'];
    }
    $requestRows[$index]['note'] = $note;

    $requestRows[$index]['category'] = $row['category_other'] !== null
        && $row['category_other'] !== ''
            ? $row['category_other']
            : $row['expense_category'];

    if (in_array($status, ['Pending Finance', 'Pending Admin', 'Finance Approved'], true)) {
        $awaiting++;
    }

    $label = $status !== '' ? $status : 'Unknown';
    $requestStages[$label] = ($requestStages[$label] ?? 0) + 1;
}

$reports[] = [
    'id'      => 'requests',
    'label'   => 'Stock Requests',
    'icon'    => 'bi-clipboard-check',
    'blurb'   => 'Every request raised in this period, and which desk is holding it.',
    'empty'   => 'No stock request was raised in this period.',
    'kpis'    => [
        ['label' => 'Requests raised', 'value' => reportNumber(count($requestRows)),
         'icon' => 'bi-clipboard', 'tone' => 'primary'],
        ['label' => 'Awaiting approval', 'value' => reportNumber($awaiting),
         'icon' => 'bi-hourglass-split', 'tone' => $awaiting ? 'warning' : 'success'],
        ['label' => 'Received', 'value' => reportNumber($requestStages['Received'] ?? 0),
         'icon' => 'bi-truck', 'tone' => 'success'],
        ['label' => 'Value requested',
         'value' => reportPeso(array_sum(array_column($requestRows, 'total_price'))),
         'icon' => 'bi-cash-stack', 'tone' => 'info'],
    ],
    'visuals' => [
        ['title' => 'Where the requests sit', 'span' => 12,
         'body' => reportDonut($requestStages, [
             'Received' => '#198754', 'Rejected' => '#dc3545',
             'Pending Admin' => '#ffc107', 'Pending Finance' => '#fd7e14',
             'Admin Approved' => '#0d6efd',
         ])],
    ],
    'columns' => [
        ['head' => 'Code',       'key' => 'request_code'],
        ['head' => 'Raised',     'key' => 'created_at',   'type' => 'datetime'],
        ['head' => 'By',         'key' => 'raised_by'],
        ['head' => 'Category',   'key' => 'category'],
        ['head' => 'Settles by', 'key' => 'payment_type', 'type' => 'badge',
         'badges' => ['Capital' => 'success', 'Accounts Payable' => 'info']],
        ['head' => 'Lines',      'key' => 'item_lines',   'type' => 'number'],
        ['head' => 'Units',      'key' => 'units',        'type' => 'number'],
        ['head' => 'Value',      'key' => 'total_price',  'type' => 'peso'],
        ['head' => 'Status',     'key' => 'status',       'type' => 'badge'],
        ['head' => 'Next step',  'key' => 'waiting_on'],
        ['head' => 'Remarks',    'key' => 'note',         'type' => 'wrap'],
    ],
    'totals'  => [
        'item_lines'  => array_sum(array_column($requestRows, 'item_lines')),
        'units'       => array_sum(array_column($requestRows, 'units')),
        'total_price' => array_sum(array_column($requestRows, 'total_price')),
    ],
    'rows'    => $requestRows,
];


/* ===================================================================
 * 4. DELIVERIES RECEIVED - line by line
 * =================================================================== */

/*
| Filtered on received_at, not created_at: a request raised last month and
| received this week belongs in this week's deliveries.
*/
$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 'sr.received_at', $params, $types);

$deliveryRows = reportRows($conn, "
    SELECT sr.request_code, sr.received_at,
           COALESCE(ru.fullname, 'Unknown') AS received_by,
           COALESCE(p.product_name, sri.item_description) AS item,
           sri.vendor, sri.quantity, sri.unit_price, sri.total_price
    FROM stock_requests sr
    INNER JOIN stock_request_items sri
            ON sri.request_id = sr.request_id AND sri.company_id = sr.company_id
    LEFT JOIN products p
           ON p.product_id = sri.product_id AND p.company_id = sri.company_id
    LEFT JOIN users ru
           ON ru.user_id = sr.received_by AND ru.company_id = sr.company_id
    WHERE sr.company_id = ? AND sr.status = 'Received'$clause
    ORDER BY sr.received_at DESC, sri.item_id ASC
", $params, $types);

$deliveredUnits = array_sum(array_column($deliveryRows, 'quantity'));
$deliveredValue = array_sum(array_column($deliveryRows, 'total_price'));

$byVendor = [];
foreach ($deliveryRows as $row) {
    $label = $row['vendor'] !== null && $row['vendor'] !== '' ? $row['vendor'] : 'Unnamed vendor';
    $byVendor[$label] = ($byVendor[$label] ?? 0) + (float) $row['total_price'];
}
arsort($byVendor);

$reports[] = [
    'id'      => 'deliveries',
    'label'   => 'Deliveries',
    'icon'    => 'bi-truck',
    'blurb'   => 'What actually arrived in this period, one line per item. Dated by when it was received.',
    'empty'   => 'No delivery was received in this period.',
    'kpis'    => [
        ['label' => 'Lines received', 'value' => reportNumber(count($deliveryRows)),
         'icon' => 'bi-list-check', 'tone' => 'primary'],
        ['label' => 'Units received', 'value' => reportNumber($deliveredUnits),
         'icon' => 'bi-box-seam', 'tone' => 'info'],
        ['label' => 'Value received', 'value' => reportPeso($deliveredValue),
         'icon' => 'bi-cash-stack', 'tone' => 'success'],
        ['label' => 'Vendors', 'value' => reportNumber(count($byVendor)),
         'icon' => 'bi-shop-window', 'tone' => 'warning'],
    ],
    'visuals' => [
        ['title' => 'Value received by vendor', 'span' => 12,
         'body' => reportBars(array_slice($byVendor, 0, 8, true), 'peso', 'primary')],
    ],
    'columns' => [
        ['head' => 'Received',    'key' => 'received_at',  'type' => 'datetime'],
        ['head' => 'Request',     'key' => 'request_code'],
        ['head' => 'Item',        'key' => 'item'],
        ['head' => 'Vendor',      'key' => 'vendor'],
        ['head' => 'Qty',         'key' => 'quantity',     'type' => 'number'],
        ['head' => 'Unit price',  'key' => 'unit_price',   'type' => 'peso'],
        ['head' => 'Line total',  'key' => 'total_price',  'type' => 'peso'],
        ['head' => 'Received by', 'key' => 'received_by'],
    ],
    'totals'  => [
        'quantity'    => $deliveredUnits,
        'total_price' => $deliveredValue,
    ],
    'rows'    => $deliveryRows,
];


/* ===================================================================
 * 5. SUPPLIERS
 * =================================================================== */

$supplierRows = reportRows($conn, "
    SELECT s.supplier_name, s.contact_email, s.created_at,
           COUNT(DISTINCT p.product_id) AS products,
           COALESCE(SUM(i.quantity), 0) AS units_on_hand,
           COALESCE(SUM(i.quantity * i.purchase_cost), 0) AS stock_value
    FROM suppliers s
    LEFT JOIN products p
           ON p.supplier_id = s.supplier_id AND p.company_id = s.company_id
    LEFT JOIN inventory i
           ON i.product_id = p.product_id AND i.company_id = s.company_id
    WHERE s.company_id = ?
    GROUP BY s.supplier_id, s.supplier_name, s.contact_email, s.created_at
    ORDER BY stock_value DESC
", [$companyId], 'i');

$supplierBars = [];
foreach (array_slice($supplierRows, 0, 8) as $row) {
    $supplierBars[$row['supplier_name']] = (float) $row['stock_value'];
}

$reports[] = [
    'id'      => 'suppliers',
    'timeless' => true,
    'label'   => 'Suppliers',
    'icon'    => 'bi-shop-window',
    'blurb'   => 'Who supplies what, and how much of your shelf value sits with each. Outside the period filter.',
    'empty'   => 'No supplier is on record yet.',
    'kpis'    => [
        ['label' => 'Suppliers', 'value' => reportNumber(count($supplierRows)),
         'icon' => 'bi-shop-window', 'tone' => 'primary'],
        ['label' => 'Products covered',
         'value' => reportNumber(array_sum(array_column($supplierRows, 'products'))),
         'icon' => 'bi-boxes', 'tone' => 'info'],
        ['label' => 'Largest by value',
         'value' => $supplierRows ? htmlspecialchars($supplierRows[0]['supplier_name']) : '&mdash;',
         'icon' => 'bi-trophy', 'tone' => 'warning'],
        ['label' => 'Products with no supplier',
         'value' => reportNumber(count(array_filter($stockRows, static function (array $r) {
             return $r['supplier_name'] === 'No supplier';
         }))),
         'icon' => 'bi-question-circle', 'tone' => 'secondary'],
    ],
    'visuals' => [
        ['title' => 'Shelf value by supplier', 'span' => 12,
         'body' => reportBars($supplierBars, 'peso', 'info')],
    ],
    'columns' => [
        ['head' => 'Supplier',    'key' => 'supplier_name'],
        ['head' => 'Email',       'key' => 'contact_email'],
        ['head' => 'Products',    'key' => 'products',      'type' => 'number'],
        ['head' => 'Units held',  'key' => 'units_on_hand', 'type' => 'number'],
        ['head' => 'Stock value', 'key' => 'stock_value',   'type' => 'peso'],
        ['head' => 'On record',   'key' => 'created_at',    'type' => 'date'],
    ],
    'totals'  => [
        'products'      => array_sum(array_column($supplierRows, 'products')),
        'units_on_hand' => array_sum(array_column($supplierRows, 'units_on_hand')),
        'stock_value'   => array_sum(array_column($supplierRows, 'stock_value')),
    ],
    'rows'    => $supplierRows,
];


/* ===================================================================
 * 6. MOVEMENT - what sold, against what is left
 * =================================================================== */

/*
| The reordering question is not "what sold" or "what is left" but the two
| together: a product with ten on the shelf is comfortable if it sells one a
| week and nearly gone if it sells three a day. So this tab carries both, and
| a days-of-cover figure that divides one by the other.
*/
$params = [$companyId];
$types = 'i';
$clause = reportDateClause($range, 's.sale_date', $params, $types);

$soldRows = reportRows($conn, "
    SELECT p.product_id, p.product_name,
           COALESCE(c.category_name, 'Uncategorised') AS category_name,
           SUM(si.quantity) AS sold,
           SUM(si.quantity * si.selling_price) AS revenue
    FROM sale_items si
    INNER JOIN sales s
            ON s.sale_id = si.sale_id AND s.company_id = si.company_id
    INNER JOIN products p
            ON p.product_id = si.product_id AND p.company_id = si.company_id
    LEFT JOIN categories c
            ON c.category_id = p.category_id AND c.company_id = p.company_id
    WHERE si.company_id = ?$clause
    GROUP BY p.product_id, p.product_name, c.category_name
    ORDER BY sold DESC
", $params, $types);

/* On-hand, keyed by product, so the two sides can be put side by side. */
$onHand = [];
foreach ($stockRows as $row) {
    $onHand[(int) $row['product_id']] = $row;
}

$movementRows = [];

foreach ($soldRows as $row) {

    $productId = (int) $row['product_id'];
    $stock = $onHand[$productId] ?? null;
    $qty = $stock ? (int) $stock['qty'] : 0;
    $sold = (float) $row['sold'];
    $perDay = $sold / $periodDays;

    $movementRows[] = [
        'product_name'  => $row['product_name'],
        'category_name' => $row['category_name'],
        'sold'          => $sold,
        'revenue'       => $row['revenue'],
        'per_day'       => $perDay,
        'qty'           => $qty,
        'cover'         => $perDay > 0 ? round($qty / $perDay) : null,
        'state'         => $stock['state'] ?? 'Not stocked',
    ];
}

/* Products that sat still: on the shelf, nothing sold. Money asleep. */
$deadStock = [];

foreach ($stockRows as $row) {

    $productId = (int) $row['product_id'];
    $moved = false;

    foreach ($soldRows as $sold) {
        if ((int) $sold['product_id'] === $productId) {
            $moved = true;
            break;
        }
    }

    if (!$moved && (int) $row['qty'] > 0) {
        $deadStock[] = $row;
        $movementRows[] = [
            'product_name'  => $row['product_name'],
            'category_name' => $row['category_name'],
            'sold'          => 0,
            'revenue'       => 0,
            'per_day'       => 0,
            'qty'           => (int) $row['qty'],
            'cover'         => null,
            'state'         => $row['state'],
        ];
    }
}

$movementBars = [];
foreach (array_slice($soldRows, 0, 8) as $row) {
    $movementBars[$row['product_name']] = (int) $row['sold'];
}

$reports[] = [
    'id'      => 'movement',
    'label'   => 'Movement',
    'icon'    => 'bi-arrow-left-right',
    'blurb'   => 'What sold against what is left. Days of cover is how long the shelf lasts at this rate.',
    'empty'   => 'Nothing sold in this period, so there is no movement to measure.',
    'kpis'    => [
        ['label' => 'Products that moved', 'value' => reportNumber(count($soldRows)),
         'icon' => 'bi-arrow-left-right', 'tone' => 'primary'],
        ['label' => 'Units sold',
         'value' => reportNumber(array_sum(array_column($soldRows, 'sold'))),
         'icon' => 'bi-box-seam', 'tone' => 'success'],
        ['label' => 'Sat still', 'value' => reportNumber(count($deadStock)),
         'icon' => 'bi-pause-circle', 'tone' => $deadStock ? 'warning' : 'success',
         'hint' => 'in stock, nothing sold'],
        ['label' => 'Money asleep',
         'value' => reportPeso(array_sum(array_column($deadStock, 'stock_value'))),
         'icon' => 'bi-moon', 'tone' => 'secondary', 'hint' => 'value that did not move'],
    ],
    'visuals' => [
        ['title' => 'Fastest movers by units sold', 'span' => 12,
         'body' => reportBars($movementBars, 'number', 'success')],
    ],
    'columns' => [
        ['head' => 'Product',       'key' => 'product_name'],
        ['head' => 'Category',      'key' => 'category_name'],
        ['head' => 'Sold',          'key' => 'sold',       'type' => 'number'],
        ['head' => 'Per day',       'key' => 'per_day',    'type' => 'number', 'places' => 2],
        ['head' => 'On hand',       'key' => 'qty',        'type' => 'number'],
        ['head' => 'Days of cover', 'key' => 'cover',      'type' => 'number'],
        ['head' => 'State',         'key' => 'state',      'type' => 'badge',
         'badges' => $stateBadges + ['Not stocked' => 'secondary']],
        ['head' => 'Revenue',       'key' => 'revenue',    'type' => 'peso'],
    ],
    'totals'  => [
        'sold'    => array_sum(array_column($movementRows, 'sold')),
        'revenue' => array_sum(array_column($movementRows, 'revenue')),
    ],
    'rows'    => $movementRows,
];


if (!empty($_GET['export'])) {
    reportExportCsv($reports, (string) $_GET['export'], $range, $companyName);
}

include includeRoleHeader(__DIR__, 'inventory_header.php');

renderReportsPage([
    'title'   => 'Inventory Reports',
    'blurb'   => 'What is on the shelf, what is on the way, and what is moving.',
    'range'   => $range,
    'note'    => 'Stock on Hand, Needs Restocking and Suppliers show today\'s position and ignore the period above. Deliveries are dated by when they were received.',
    'reports' => $reports,
]);

include includeRoleFooter(__DIR__, 'inventory_footer.php');
