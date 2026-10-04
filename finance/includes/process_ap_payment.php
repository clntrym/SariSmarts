<?php

/*
|--------------------------------------------------------------------------
| PROCESS AN ACCOUNTS PAYABLE PAYMENT
|--------------------------------------------------------------------------
| Shared by:
|   - accounts_payable.php's `pay_invoice` handler (Cash / Check — recorded
|     immediately, right when the form is submitted)
|   - paymongo_callback.php (GCash / Bank Transfer — recorded only after
|     PayMongo confirms the checkout session was actually paid)
|
| Both end up recording the payment and generating the receipt PDF exactly
| the same way, so this only lives in one place.
|
| Returns:
|   ['success' => true,  'invoice_no' => string, 'relative_path' => string]
|   ['success' => false, 'message' => string]
|--------------------------------------------------------------------------
*/

function processApPayment($conn, $ap_id, $amount_paid, $payment_method, $reference_no, $notes, $paid_by, $company_id)
{
    /*
    |--------------------------------------------------------------------------
    | FPDF AVAILABILITY CHECK
    |--------------------------------------------------------------------------
    */

    $fpdfPath = __DIR__ . '/../../libs/fpdf/fpdf.php';

    if (!file_exists($fpdfPath)) {

        return [
            'success' => false,
            'message' => "The FPDF library isn't installed, so a receipt can't be generated. " .
                "Download it from fpdf.org and place fpdf.php (plus its font folder) in libs/fpdf/, then try again."
        ];
    }

    require_once($fpdfPath);


    /*
    |--------------------------------------------------------------------------
    | PROCESS PAYMENT (transaction)
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT * FROM accounts_payable WHERE ap_id = ? AND company_id = ? FOR UPDATE");
        $stmt->bind_param("ii", $ap_id, $company_id);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ap) {
            throw new Exception("Invoice not found.");
        }

        $remaining = round((float) $ap['amount'] - (float) $ap['paid_amount'], 2);

        if ($ap['status'] === 'Paid' || $remaining <= 0) {
            throw new Exception("This invoice is already fully paid.");
        }

        if ($amount_paid > $remaining + 0.01) {
            throw new Exception("Amount exceeds the remaining balance of ₱" . number_format($remaining, 2) . ".");
        }

        // Insert payment
        $insertPay = $conn->prepare("
            INSERT INTO ap_payments (company_id, ap_id, amount_paid, payment_method, reference_no, notes, paid_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertPay->bind_param("iidsssi", $company_id, $ap_id, $amount_paid, $payment_method, $reference_no, $notes, $paid_by);
        $insertPay->execute();
        $payment_id = $insertPay->insert_id;
        $insertPay->close();

        // Update invoice balance
        $newPaidAmount = round((float) $ap['paid_amount'] + $amount_paid, 2);
        $newRemaining = round((float) $ap['amount'] - $newPaidAmount, 2);
        $newStatus = $newRemaining <= 0.01 ? 'Paid' : 'Partial';

        $updateAp = $conn->prepare("UPDATE accounts_payable SET paid_amount = ?, status = ? WHERE ap_id = ? AND company_id = ?");
        $updateAp->bind_param("dsii", $newPaidAmount, $newStatus, $ap_id, $company_id);
        $updateAp->execute();
        $updateAp->close();


        /*
        |--------------------------------------------------------------------------
        | GENERATE RECEIPT PDF
        |--------------------------------------------------------------------------
        */

        $receiptCode = 'RCPT-' . date('Y') . '-' . str_pad((string) $payment_id, 5, '0', STR_PAD_LEFT);

        $pdf = new FPDF('P', 'mm', [148, 210]);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'RetailCore', 0, 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Official Payment Receipt', 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 11);

        $lines = [
            ['Receipt No:', $receiptCode],
            ['Date:', date('F d, Y h:i A')],
            ['Invoice No:', $ap['invoice_no']],
            ['PO Number:', $ap['po_number']],
            ['Supplier:', $ap['supplier']],
            ['Description:', (string) $ap['description']],
        ];

        foreach ($lines as $l) {
            $pdf->Cell(50, 8, $l[0], 0, 0);
            $pdf->MultiCell(0, 8, $l[1]);
        }

        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(50, 8, 'Amount Paid:', 0, 0);
        $pdf->Cell(0, 8, 'PHP ' . number_format($amount_paid, 2), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 8, 'Payment Method:', 0, 0);
        $pdf->Cell(0, 8, $payment_method, 0, 1);

        if ($reference_no !== '') {
            $pdf->Cell(50, 8, 'Reference No:', 0, 0);
            $pdf->Cell(0, 8, $reference_no, 0, 1);
        }

        $pdf->Cell(50, 8, 'Remaining Balance:', 0, 0);
        $pdf->Cell(0, 8, 'PHP ' . number_format($newRemaining, 2), 0, 1);

        $pdf->Ln(8);
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 6, 'This receipt was generated automatically by RetailCore Finance.', 0, 1, 'C');

        $receiptDir = __DIR__ . '/../../uploads/ap_receipts';

        if (!is_dir($receiptDir)) {
            mkdir($receiptDir, 0775, true);
        }

        $fileName = $receiptCode . '.pdf';
        $filePath = $receiptDir . '/' . $fileName;
        $relativePath = 'uploads/ap_receipts/' . $fileName;

        $pdf->Output('F', $filePath);


        // Insert receipt record, link back to the payment
        $insertReceipt = $conn->prepare("
            INSERT INTO ap_receipts (company_id, receipt_code, ap_id, payment_id, amount, file_path)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertReceipt->bind_param("isiids", $company_id, $receiptCode, $ap_id, $payment_id, $amount_paid, $relativePath);
        $insertReceipt->execute();
        $receipt_id = $insertReceipt->insert_id;
        $insertReceipt->close();

        $linkReceipt = $conn->prepare("UPDATE ap_payments SET receipt_id = ? WHERE payment_id = ? AND company_id = ?");
        $linkReceipt->bind_param("iii", $receipt_id, $payment_id, $company_id);
        $linkReceipt->execute();
        $linkReceipt->close();

        $conn->commit();

        return [
            'success' => true,
            'invoice_no' => $ap['invoice_no'],
            'relative_path' => $relativePath,
        ];

    } catch (Exception $e) {

        $conn->rollback();

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}