<?php

/*
|--------------------------------------------------------------------------
| BUILD THE BLANK SERVICE AGREEMENT
|--------------------------------------------------------------------------
|
| Produces the PDF a business downloads, signs and sends back. It is a
| blank: the boxes are for them to fill in by hand, which is why the
| system records the same facts separately and the Super Admin can compare.
|
| Run it once, then upload the result in Settings > Service Agreement:
|
|     php database/make_service_agreement.php
|
| The wording follows the document already drafted for SariSmart. It is a
| working draft produced from that design, not legal advice, and the terms
| are yours to confirm before anybody signs one.
|
| Re-running overwrites the output file and nothing else. It does not touch
| the database, and it does not replace a template already uploaded --
| that is done deliberately, through Settings.
|
*/

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

const AGREEMENT_OUT = __DIR__ . '/../uploads/SariSmart_Service_Agreement.pdf';

/* The navy and yellow the rest of the site uses. */
const NAVY = [0, 34, 76];
const GOLD = [250, 190, 40];
const RULE = [210, 218, 228];
const INK  = [31, 41, 55];
const MUTE = [100, 116, 139];


class AgreementPdf extends FPDF
{
    public function Header(): void
    {
        /* The band across the top, on every page. */
        $this->SetFillColor(...NAVY);
        $this->Rect(0, 0, 210, 22, 'F');

        $this->SetFillColor(...GOLD);
        $this->Rect(0, 22, 210, 1.2, 'F');

        $this->SetY(7);
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, 'SariSmart', 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->Cell(0, 8, 'Your Smart Partner for Every Sale', 0, 0, 'R');

        $this->SetY(32);
        $this->SetTextColor(...INK);
    }

    public function Footer(): void
    {
        $this->SetY(-16);

        $this->SetDrawColor(...RULE);
        $this->Line(15, $this->GetY(), 195, $this->GetY());

        $this->SetY(-13);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...MUTE);

        $this->Cell(0, 6, 'SariSmart Retail, Inc.  |  (02) 8123-4567  |  info@sarismart.ph', 0, 0, 'L');
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');

        $this->SetTextColor(...INK);
    }

    /* A numbered section heading. */
    public function section(string $number, string $title): void
    {
        $this->Ln(3);

        if ($this->GetY() > 245) {
            $this->AddPage();
        }

        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(...NAVY);
        $this->Cell(0, 7, $number . '. ' . strtoupper($title), 0, 1, 'L');

        $this->SetDrawColor(...GOLD);
        $this->SetLineWidth(0.6);
        $this->Line(15, $this->GetY(), 42, $this->GetY());
        $this->SetLineWidth(0.2);

        $this->Ln(2.5);
        $this->SetTextColor(...INK);
        $this->SetFont('Helvetica', '', 9.5);
    }

    /* A paragraph of body text. */
    public function body(string $text): void
    {
        $this->SetFont('Helvetica', '', 9.5);
        $this->SetTextColor(...INK);
        $this->MultiCell(0, 5, $text, 0, 'J');
        $this->Ln(1.5);
    }

    /* One bullet. */
    public function bullet(string $text): void
    {
        $this->SetFont('Helvetica', '', 9.5);
        $this->Cell(6, 5, '', 0, 0);
        $this->Cell(3, 5, '-', 0, 0);
        $this->MultiCell(0, 5, $text, 0, 'L');
    }

    /*
    | A labelled line for somebody to write on.
    |
    | The line is drawn rather than printed as underscores so it stays
    | straight whatever the label's width.
    */
    public function fillIn(string $label, float $width, bool $newLine = true): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(...NAVY);

        $labelWidth = $this->GetStringWidth($label) + 2;
        $this->Cell($labelWidth, 7, $label, 0, 0);

        $x = $this->GetX();
        $y = $this->GetY() + 5.5;

        $this->SetDrawColor(...RULE);
        $this->Line($x, $y, $x + $width, $y);

        $this->Cell($width, 7, '', 0, $newLine ? 1 : 0);
        $this->SetTextColor(...INK);
    }

    /* A row of the business information table. */
    public function tableRow(string $label, string $value = ''): void
    {
        $this->SetFont('Helvetica', '', 9);
        $this->SetDrawColor(...RULE);

        $this->SetFillColor(248, 250, 252);
        $this->Cell(55, 8, '  ' . $label, 1, 0, 'L', true);
        $this->Cell(125, 8, '  ' . $value, 1, 1, 'L');
    }

    /* A tick box. */
    public function checkbox(string $label, bool $last = false): void
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetDrawColor(...NAVY);
        $this->Rect($x + 1, $y + 2, 3.5, 3.5);

        $this->SetFont('Helvetica', '', 9);
        $this->Cell(6, 7, '', 0, 0);
        $this->Cell($this->GetStringWidth($label) + 6, 7, $label, 0, $last ? 1 : 0);
    }
}


$pdf = new AgreementPdf();
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 22);
$pdf->SetMargins(15, 32, 15);
$pdf->SetTitle('SariSmart Business Subscription and Service Agreement');
$pdf->SetAuthor('SariSmart Retail, Inc.');


/* ==========================================================
   PAGE 1 - THE PARTIES AND THE BUSINESS
   ========================================================== */

$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 15);
$pdf->SetTextColor(...NAVY);
$pdf->Cell(0, 9, 'BUSINESS SUBSCRIPTION & SERVICE AGREEMENT', 0, 1, 'C');
$pdf->Ln(2);

$pdf->SetTextColor(...INK);
$pdf->SetFont('Helvetica', '', 9);

/* The reference the system issues, written in by whoever sends it. */
$pdf->fillIn('CONTRACT NO.:', 60, false);
$pdf->fillIn('DATE ISSUED:', 55);
$pdf->Ln(3);

$pdf->body(
    'This Business Subscription & Service Agreement ("Agreement") is entered into by and between:'
);

$pdf->SetFont('Helvetica', 'B', 9.5);
$pdf->Cell(0, 5, 'SARISMART RETAIL, INC.,', 0, 1);
$pdf->SetFont('Helvetica', '', 9.5);
$pdf->body(
    'a corporation duly organized and existing under the laws of the Republic of the Philippines, '
    . 'with principal office at:'
);

$pdf->fillIn('', 180);
$pdf->body('hereinafter referred to as the "COMPANY" or "SariSmart";');

$pdf->SetFont('Helvetica', 'B', 9.5);
$pdf->Cell(0, 5, '- and -', 0, 1, 'C');
$pdf->Ln(1);

$pdf->fillIn('BUSINESS / COMPANY NAME:', 110);
$pdf->SetFont('Helvetica', '', 9.5);
$pdf->Cell(0, 5, 'with business address at:', 0, 1);
$pdf->fillIn('', 180);
$pdf->Cell(0, 5, 'represented by:', 0, 1);
$pdf->fillIn('AUTHORIZED REPRESENTATIVE:', 70, false);
$pdf->fillIn('POSITION:', 45);

$pdf->body('hereinafter referred to as the "SUBSCRIBER" or "BUSINESS."');
$pdf->body(
    'The Company and the Subscriber may individually be referred to as a "Party" and '
    . 'collectively as the "Parties."'
);

$pdf->Ln(2);

$pdf->SetFillColor(...NAVY);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(0, 8, '  BUSINESS INFORMATION', 0, 1, 'C', true);
$pdf->SetTextColor(...INK);

$pdf->tableRow('Business Name');
$pdf->tableRow('Business Type');
$pdf->tableRow('Business Address');
$pdf->tableRow('Business Email');
$pdf->tableRow('Business Contact No.');
$pdf->tableRow('TIN / Registration No.');
$pdf->tableRow('Authorized Representative');
$pdf->tableRow('Subscription Plan');
$pdf->tableRow('Subscription Fee');
$pdf->tableRow('Billing Date');
$pdf->tableRow('Subscription Start');
$pdf->tableRow('Subscription End');
$pdf->tableRow('No. of Branches');

$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetTextColor(...NAVY);
$pdf->Cell(40, 7, 'SUBSCRIPTION STATUS', 0, 0);
$pdf->SetTextColor(...INK);

foreach (['Trial', 'Active', 'Suspended', 'Cancelled'] as $i => $state) {
    $pdf->checkbox($state, $i === 3);
}


/* ==========================================================
   PAGE 2 - THE TERMS
   ========================================================== */

$pdf->AddPage();

$pdf->section('1', 'Purpose of the Agreement');
$pdf->body(
    'The purpose of this Agreement is to establish the terms and conditions under which the '
    . 'SUBSCRIBER may access and use the SariSmart Retail OS Platform and its available business '
    . 'management features.'
);
$pdf->body('The SariSmart platform may provide business management functionality including, depending on the selected plan:');

foreach ([
    'Point of Sale (POS)',
    'Inventory Management',
    'Product Management',
    'Employee / HR Management',
    'Attendance Management',
    'Payroll Management',
    'Finance Management',
    'Branch Management',
    'Reports and Analytics',
    'User and Role Management',
    'Other modules and services made available under the plan',
] as $item) {
    $pdf->bullet($item);
}

$pdf->section('2', 'Subscription and Fees');
$pdf->body('The SUBSCRIBER agrees to pay the applicable subscription fee associated with the selected plan.');

$pdf->fillIn('Selected Plan:', 100);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetTextColor(...NAVY);
$pdf->Cell(35, 7, 'Billing Frequency:', 0, 0);
$pdf->SetTextColor(...INK);

foreach (['Monthly', 'Quarterly', 'Semi-Annual', 'Annual'] as $i => $cycle) {
    $pdf->checkbox($cycle, $i === 3);
}

$pdf->Ln(1);
$pdf->fillIn('Subscription Fee: PHP', 90);
$pdf->fillIn('Additional Branch Fee: PHP', 83);
$pdf->fillIn('Additional User Fee: PHP', 87);
$pdf->fillIn('Other Applicable Fees: PHP', 84);

$pdf->Ln(1);
$pdf->body(
    'The subscription fee and applicable charges shall be reflected in the Subscriber\'s account '
    . 'and billing records. The subscription is activated only after this Agreement is signed and '
    . 'returned, and payment is settled.'
);

$pdf->section('3', 'Business Account');
$pdf->body('The SUBSCRIBER shall be responsible for maintaining the accuracy and security of its business account information, including:');

foreach ([
    'Maintaining authorized users;',
    'Assigning appropriate user roles;',
    'Protecting account credentials;',
    'Removing access of former employees or unauthorized users;',
    'Ensuring users only access information necessary for their roles; and',
    'Providing accurate business information.',
] as $item) {
    $pdf->bullet($item);
}

$pdf->Ln(1);
$pdf->body('The SUBSCRIBER shall notify SariSmart of any suspected unauthorized access or security incident affecting its account.');


/* ==========================================================
   PAGE 3 - ROLES, DATA, TERM
   ========================================================== */

$pdf->AddPage();

$pdf->section('4', 'User and Role Management');
$pdf->body('The SUBSCRIBER may create authorized users based on the features available under its subscription plan. Roles may include:');

foreach ([
    'Owner / Admin',
    'Cashier',
    'Inventory User',
    'Finance User',
    'HR User',
    'Other authorized business roles',
] as $item) {
    $pdf->bullet($item);
}

$pdf->Ln(1);
$pdf->body('The SUBSCRIBER is responsible for determining which employees or representatives are authorized to access its SariSmart account.');

$pdf->section('5', 'Business Data');
$pdf->body(
    'Information entered into the platform by the SUBSCRIBER, including business records, products, '
    . 'inventory information, employee records, transactions, financial records, and other business '
    . 'information, shall be treated as the SUBSCRIBER\'s business data, subject to applicable law '
    . 'and the platform\'s terms and policies.'
);
$pdf->body(
    'SariSmart shall not disclose the Subscriber\'s business data to another subscriber. Each '
    . 'business\'s records are kept separate from every other business on the platform.'
);

$pdf->section('6', 'Term, Renewal and Cancellation');
$pdf->body(
    'This Agreement takes effect on the subscription start date stated above and continues for the '
    . 'billing period selected, renewing for successive periods unless either Party gives notice '
    . 'before the end of the current period.'
);
$pdf->body(
    'The COMPANY may suspend access where a subscription fee remains unsettled after its due date, '
    . 'or where the account is used in a way that breaches this Agreement. Access is restored once '
    . 'the cause is resolved.'
);

$pdf->section('7', 'Data on Cancellation');
$pdf->body(
    'On cancellation the SUBSCRIBER may request an export of its business data. The COMPANY shall '
    . 'make the data available for a reasonable period before it is removed from active systems.'
);

$pdf->section('8', 'Entire Agreement');
$pdf->body(
    'This Agreement, together with the plan details recorded in the Subscriber\'s account, '
    . 'constitutes the entire agreement between the Parties on its subject, and supersedes any '
    . 'prior understanding on the same subject.'
);


/* ==========================================================
   PAGE 4 - EXECUTION
   ========================================================== */

$pdf->AddPage();

$pdf->section('9', 'Execution');
$pdf->body(
    'IN WITNESS WHEREOF, the Parties have signed this Agreement on the dates written below.'
);
$pdf->body(
    'The SUBSCRIBER signs first and returns the signed copy through the SariSmart subscription '
    . 'page. The COMPANY countersigns on acceptance, and the subscription is activated once '
    . 'payment is settled.'
);

$pdf->Ln(6);

/* Two signature blocks, side by side. */
$blockTop = $pdf->GetY();

$signature = function (AgreementPdf $pdf, float $x, float $y, string $party, string $role): void {

    $pdf->SetXY($x, $y);
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $pdf->SetTextColor(...NAVY);
    $pdf->Cell(85, 6, $party, 0, 2);

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(...MUTE);
    $pdf->Cell(85, 5, $role, 0, 2);

    $pdf->SetTextColor(...INK);
    $pdf->Ln(14);

    /* Signature over the printed name. */
    $lineY = $pdf->GetY();
    $pdf->SetDrawColor(...RULE);
    $pdf->Line($x, $lineY, $x + 80, $lineY);

    $pdf->SetXY($x, $lineY + 1);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(...MUTE);
    $pdf->Cell(80, 5, 'Signature over printed name', 0, 2);

    $pdf->Ln(8);
    $lineY = $pdf->GetY();
    $pdf->Line($x, $lineY, $x + 80, $lineY);

    $pdf->SetXY($x, $lineY + 1);
    $pdf->Cell(80, 5, 'Position', 0, 2);

    $pdf->Ln(8);
    $lineY = $pdf->GetY();
    $pdf->Line($x, $lineY, $x + 80, $lineY);

    $pdf->SetXY($x, $lineY + 1);
    $pdf->Cell(80, 5, 'Date signed', 0, 2);

    $pdf->SetTextColor(...INK);
};

$signature($pdf, 15, $blockTop, 'THE SUBSCRIBER', 'The business availing the subscription');
$signature($pdf, 110, $blockTop, 'SARISMART RETAIL, INC.', 'The Company');

$pdf->SetY($blockTop + 62);

$pdf->section('10', 'Witnesses');
$pdf->body('Signed in the presence of:');

$pdf->Ln(8);
$witnessTop = $pdf->GetY();

foreach ([15, 110] as $x) {
    $pdf->SetDrawColor(...RULE);
    $pdf->Line($x, $witnessTop, $x + 80, $witnessTop);
    $pdf->SetXY($x, $witnessTop + 1);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(...MUTE);
    $pdf->Cell(80, 5, 'Signature over printed name', 0, 0);
}

$pdf->SetTextColor(...INK);
$pdf->SetY($witnessTop + 14);

$pdf->SetFont('Helvetica', 'I', 8.5);
$pdf->SetTextColor(...MUTE);
$pdf->MultiCell(0, 4.5,
    'Return the signed copy through your SariSmart subscription page. Keep one copy for your '
    . 'records. Our team reads the signed agreement before the subscription is switched on.',
    0, 'C');


$pdf->Output('F', AGREEMENT_OUT);

echo "Written: " . realpath(AGREEMENT_OUT) . "\n";
echo "Pages:   " . $pdf->PageNo() . "\n";
echo "Size:    " . number_format(filesize(AGREEMENT_OUT) / 1024, 1) . " KB\n";
