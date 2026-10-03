<?php

/*
|--------------------------------------------------------------------------
| TAX -- shared page body
|--------------------------------------------------------------------------
|
| Two halves:
|
|   Settings  -- the percentage cashier/pointofsales.php applies to every
|                sale. Until this screen existed nothing could write to the
|                `tax` table, so a company was charged 0% forever.
|
|   Collected -- what each sale actually contributed, read from
|                sales.tax_amount rather than recomputed from today's rate.
|                A sale rung up at 12% keeps reporting 12% after the rate
|                changes, which is the only honest way to show it.
|
| Two entry points wrap this file:
|
|     admin/tax.php    requireRole(['admin'])
|     finance/tax.php  requireRole(['finance'])
|
| Styled with Bootstrap and plain scoped CSS. The Income page was written in
| Tailwind utilities and collapsed the moment it was shown under a header
| that does not load the Tailwind CDN -- only finance_header.php does. This
| page avoids that dependency entirely.
|
*/

if (!isset($conn, $companyId, $MODULE_HEADER, $MODULE_FOOTER)) {
    http_response_code(403);
    exit('This page cannot be opened directly.');
}

/*
|--------------------------------------------------------------------------
| UPDATE THE RATE
|--------------------------------------------------------------------------
*/

if (isset($_POST['saveTaxRate'])) {

    $newRate = $_POST['tax_rate'] ?? '';
    $note = trim((string) ($_POST['tax_note'] ?? ''));

    if (!is_numeric($newRate)) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Rate",
            "text" => "Enter the tax rate as a number, for example 12 for 12%."
        ];

    } elseif ((float) $newRate < 0 || (float) $newRate > 100) {

        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Out of Range",
            "text" => "The tax rate must be between 0 and 100."
        ];

    } else {

        $newRate = round((float) $newRate, 2);

        $conn->begin_transaction();

        try {

            $current = ensureCompanyTaxRate($conn, $companyId);
            $oldRate = $current['tax_rate'];

            $update = $conn->prepare("UPDATE tax SET tax_rate = ? WHERE id = ? AND company_id = ?");
            $update->bind_param("dii", $newRate, $current['id'], $companyId);

            if (!$update->execute()) {
                $update->close();
                throw new Exception("Unable to save the tax rate.");
            }

            $update->close();

            /*
            | `tax` is one row that gets overwritten, so the change itself is
            | recorded separately. Without this a shop could not show when it
            | started or stopped charging VAT.
            */
            $changedBy = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
            $changedBy = $changedBy > 0 ? $changedBy : null;
            $noteValue = $note !== '' ? $note : null;

            $log = $conn->prepare("
                INSERT INTO tax_history (company_id, old_rate, new_rate, changed_by, note)
                VALUES (?, ?, ?, ?, ?)
            ");
            $log->bind_param("iddis", $companyId, $oldRate, $newRate, $changedBy, $noteValue);

            if (!$log->execute()) {
                $log->close();
                throw new Exception("Unable to record the change.");
            }

            $log->close();
            $conn->commit();

            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => "Tax Rate Saved",
                "text" => "New sales are now taxed at " . number_format($newRate, 2) . "%. "
                    . "Sales already recorded keep the rate they were rung up at."
            ];

        } catch (Exception $e) {

            $conn->rollback();

            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Failed",
                "text" => $e->getMessage()
            ];
        }
    }

    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit;
}

/*
|--------------------------------------------------------------------------
| CURRENT RATE
|--------------------------------------------------------------------------
*/

$tax = ensureCompanyTaxRate($conn, $companyId);
$taxRate = $tax['tax_rate'];

/*
|--------------------------------------------------------------------------
| TAX COLLECTED
|
| The effective rate per sale is derived from what was actually charged, not
| from the rate configured today, so history stays truthful across changes.
|--------------------------------------------------------------------------
*/

$taxRows = [];
$totalTax = 0.0;
$totalNet = 0.0;
$totalGross = 0.0;

$salesStmt = $conn->prepare("
    SELECT
        s.sale_id,
        s.total_amount,
        s.tax_amount,
        s.payment_method,
        s.sale_date
    FROM sales s
    WHERE s.company_id = ?
    ORDER BY s.sale_id DESC
");
$salesStmt->bind_param("i", $companyId);
$salesStmt->execute();
$salesResult = $salesStmt->get_result();

while ($row = $salesResult->fetch_assoc()) {

    $gross = (float) $row['total_amount'];
    $taxPaid = (float) $row['tax_amount'];
    $net = $gross - $taxPaid;

    $row['net_amount'] = $net;
    $row['effective_rate'] = $net > 0 ? ($taxPaid / $net) * 100 : 0.0;

    $taxRows[] = $row;

    $totalTax += $taxPaid;
    $totalNet += $net;
    $totalGross += $gross;
}

$salesStmt->close();

$taxedCount = 0;
foreach ($taxRows as $row) {
    if ((float) $row['tax_amount'] > 0) {
        $taxedCount++;
    }
}

/*
|--------------------------------------------------------------------------
| RATE CHANGE HISTORY
|--------------------------------------------------------------------------
*/

$history = [];

$historyStmt = $conn->prepare("
    SELECT
        h.old_rate,
        h.new_rate,
        h.note,
        h.changed_at,
        u.fullname
    FROM tax_history h
    LEFT JOIN users u ON u.user_id = h.changed_by AND u.company_id = h.company_id
    WHERE h.company_id = ?
    ORDER BY h.history_id DESC
    LIMIT 20
");
$historyStmt->bind_param("i", $companyId);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();

while ($row = $historyResult->fetch_assoc()) {
    $history[] = $row;
}

$historyStmt->close();

include($MODULE_HEADER);
?>

<style>
    .tax-page { color: #00224c; }

    .tax-page .tax-title { font-size: 1.75rem; font-weight: 700; color: #00224c; margin: 0; }
    .tax-page .tax-subtitle { color: #718096; font-size: .9rem; }

    .tax-page .tax-card {
        border: 1px solid #dfe5ec;
        border-radius: 15px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(0, 34, 76, .06);
    }

    .tax-page .tax-card-body { padding: 20px; }

    .tax-page .stat-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #718096;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .tax-page .stat-value { font-size: 1.6rem; font-weight: 700; color: #00224c; }

    .tax-page .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: #fff;
        flex: 0 0 auto;
    }

    .tax-page .bg-navy { background: #00224c; }
    .tax-page .bg-amber { background: #fbbd23; color: #00224c; }
    .tax-page .bg-green { background: #16a34a; }

    .tax-page .rate-badge {
        font-size: 2.6rem;
        font-weight: 700;
        color: #00224c;
        line-height: 1;
    }

    .tax-page .tax-table thead th {
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: .03em;
        color: #4a5568;
        font-weight: 600;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }

    .tax-page .tax-table td { vertical-align: middle; }

    .tax-page .pill {
        display: inline-block;
        border-radius: 30px;
        padding: 3px 12px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .tax-page .pill-cash { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .tax-page .pill-gcash { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .tax-page .pill-none { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }

    .tax-page .empty-state { padding: 42px 20px; text-align: center; color: #718096; }
    .tax-page .empty-state i { font-size: 34px; color: #cbd5e1; display: block; margin-bottom: 10px; }
</style>

<div class="container-fluid py-3 tax-page">

    <div class="mb-3">
        <h1 class="tax-title">Tax</h1>
        <div class="tax-subtitle">
            The rate applied at the point of sale, and what it has collected.
        </div>
    </div>

    <?php if ($taxRate <= 0): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>No tax is being charged.</strong>
                Your rate is 0%, so every sale records &#8369;0.00 tax. Set a rate below if your
                business is registered to collect it.
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">

        <div class="col-12 col-lg-4">
            <div class="tax-card h-100">
                <div class="tax-card-body">

                    <div class="stat-label">Current Rate</div>
                    <div class="rate-badge mb-3"><?= number_format($taxRate, 2) ?>%</div>

                    <form method="POST" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>">

                        <label class="form-label fw-semibold" style="font-size:13px;" for="taxRate">
                            New rate (%)
                        </label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control"
                            id="taxRate" name="tax_rate" value="<?= htmlspecialchars(number_format($taxRate, 2, '.', '')) ?>"
                            required style="border-radius:10px;border:1px solid #dce3eb;">

                        <label class="form-label fw-semibold mt-3" style="font-size:13px;" for="taxNote">
                            Reason
                        </label>
                        <input type="text" class="form-control" id="taxNote" name="tax_note" maxlength="200"
                            placeholder="e.g. VAT registration approved"
                            style="border-radius:10px;border:1px solid #dce3eb;">

                        <button type="submit" name="saveTaxRate" class="btn btn-primary w-100 mt-3">
                            <i class="bi bi-check2 me-1"></i>Save Rate
                        </button>

                        <div class="form-text mt-2">
                            Applies to new sales only. Sales already recorded keep the rate they
                            were rung up at.
                        </div>

                    </form>

                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="row g-3">

                <div class="col-12 col-md-4">
                    <div class="tax-card h-100">
                        <div class="tax-card-body d-flex align-items-center justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Tax Collected</div>
                                <div class="stat-value">&#8369;<?= number_format($totalTax, 2) ?></div>
                            </div>
                            <div class="stat-icon bg-navy"><i class="bi bi-percent"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="tax-card h-100">
                        <div class="tax-card-body d-flex align-items-center justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Net of Tax</div>
                                <div class="stat-value">&#8369;<?= number_format($totalNet, 2) ?></div>
                            </div>
                            <div class="stat-icon bg-amber"><i class="bi bi-cash-stack"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="tax-card h-100">
                        <div class="tax-card-body d-flex align-items-center justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Taxed Sales</div>
                                <div class="stat-value"><?= (int) $taxedCount ?><span
                                        style="font-size:1rem;color:#718096;"> / <?= count($taxRows) ?></span></div>
                            </div>
                            <div class="stat-icon bg-green"><i class="bi bi-receipt"></i></div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="tax-card">
                        <div class="tax-card-body pb-2">
                            <div class="stat-label mb-0">Rate Change History</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm tax-table mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4">When</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>By</th>
                                        <th class="pe-4">Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($history) > 0): ?>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td class="ps-4 text-nowrap">
                                                    <?= date('M d, Y g:i A', strtotime($h['changed_at'])) ?>
                                                </td>
                                                <td class="text-nowrap">
                                                    <?= $h['old_rate'] === null ? '&mdash;' : number_format((float) $h['old_rate'], 2) . '%' ?>
                                                </td>
                                                <td class="text-nowrap fw-semibold">
                                                    <?= number_format((float) $h['new_rate'], 2) ?>%
                                                </td>
                                                <td><?= htmlspecialchars($h['fullname'] ?? 'Unknown') ?></td>
                                                <td class="pe-4"><?= htmlspecialchars($h['note'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">
                                                <div class="empty-state">
                                                    <i class="bi bi-clock-history"></i>
                                                    The rate has not been changed yet.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <div class="tax-card">

        <div class="tax-card-body pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="stat-label mb-0">Tax Collected per Sale</div>
            <div class="tax-subtitle">
                Rates shown are what each sale was actually charged.
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover tax-table mb-0" id="taxTable" style="width:100%">
                <thead>
                    <tr>
                        <th class="ps-4">Transaction</th>
                        <th>Date</th>
                        <th>Payment</th>
                        <th class="text-end">Net of Tax</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end pe-4">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($taxRows) > 0): ?>
                        <?php foreach ($taxRows as $row): ?>
                            <?php
                            $method = $row['payment_method'] ?? 'Cash';
                            $pill = $method === 'GCash' ? 'pill-gcash' : ($method === 'Cash' ? 'pill-cash' : 'pill-none');
                            ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-nowrap">
                                    SALE-<?= str_pad((string) $row['sale_id'], 6, '0', STR_PAD_LEFT) ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= date('M d, Y g:i A', strtotime($row['sale_date'])) ?>
                                </td>
                                <td>
                                    <span class="pill <?= $pill ?>"><?= htmlspecialchars($method) ?></span>
                                </td>
                                <td class="text-end text-nowrap">
                                    &#8369;<?= number_format($row['net_amount'], 2) ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?= number_format($row['effective_rate'], 2) ?>%
                                </td>
                                <td class="text-end text-nowrap fw-semibold">
                                    &#8369;<?= number_format((float) $row['tax_amount'], 2) ?>
                                </td>
                                <td class="text-end pe-4 text-nowrap">
                                    &#8369;<?= number_format((float) $row['total_amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-receipt"></i>
                                    No sales recorded yet.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if (count($taxRows) > 0): ?>
                    <tfoot>
                        <tr class="fw-semibold" style="background:#f8fafc;">
                            <td class="ps-4" colspan="3">Total</td>
                            <td class="text-end">&#8369;<?= number_format($totalNet, 2) ?></td>
                            <td></td>
                            <td class="text-end">&#8369;<?= number_format($totalTax, 2) ?></td>
                            <td class="text-end pe-4">&#8369;<?= number_format($totalGross, 2) ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

    </div>

</div>

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

<?php include($MODULE_FOOTER); ?>
