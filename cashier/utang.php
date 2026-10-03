<?php
require_once("../init.php");
requireRole(['cashier']);

/*----------------------------------
AUTO UPDATE OVERDUE STATUS
-----------------------------------*/

$companyId = requireCompany();

$overdueStmt = mysqli_prepare($conn, "
UPDATE utang
SET status='Overdue'
WHERE balance > 0
  AND due_date < CURDATE()
  AND status <> 'Paid'
  AND company_id = ?
");
mysqli_stmt_bind_param($overdueStmt, 'i', $companyId);
mysqli_stmt_execute($overdueStmt);
mysqli_stmt_close($overdueStmt);

/*----------------------------------
GET ALL RECORDS
-----------------------------------*/

$listStmt = mysqli_prepare($conn, "
SELECT
    u.*,

    (
        SELECT COALESCE(SUM(balance),0)
        FROM utang
        WHERE customer_name = u.customer_name
          AND status <> 'Paid'
          AND company_id = u.company_id
    ) AS total_balance

FROM utang u
WHERE u.company_id = ?
ORDER BY u.utang_id DESC
");
mysqli_stmt_bind_param($listStmt, 'i', $companyId);
mysqli_stmt_execute($listStmt);
$result = mysqli_stmt_get_result($listStmt);

/*==========================================
SAVE PAYMENT AJAX
==========================================*/

if(isset($_POST['record_payment'])){
    header("Content-Type: application/json");
    $utangID=(int)$_POST['utang_id'];
    $payment=(float)$_POST['payment'];
    $remarks=mysqli_real_escape_string(
        $conn,
        $_POST['remarks']
    );
    if($payment<=0){
        echo json_encode([
            "status"=>"error",
            "message"=>"Invalid payment amount."
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    try{
        $lookup = mysqli_prepare($conn, "
            SELECT *
            FROM utang
            WHERE utang_id = ? AND company_id = ?
        ");
        mysqli_stmt_bind_param($lookup, 'ii', $utangID, $companyId);
        mysqli_stmt_execute($lookup);
        $utang = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
        mysqli_stmt_close($lookup);

        if(!$utang){
            throw new Exception("Utang not found.");
        }
        $balance=$utang['balance'];
        if($payment>$balance){
            throw new Exception(
                "Payment is greater than remaining balance."
            );
        }
        $payStmt = mysqli_prepare($conn, "
            INSERT INTO utang_payments
            (company_id, utang_id, payment_amount, remarks)
            VALUES (?,?,?,?)
        ");
        mysqli_stmt_bind_param($payStmt, 'iids', $companyId, $utangID, $payment, $remarks);
        mysqli_stmt_execute($payStmt);
        mysqli_stmt_close($payStmt);

        $paid=$utang['paid_amount']+$payment;
        $newBalance=$utang['balance']-$payment;
        if($newBalance<=0){
            $status="Paid";
            $newBalance=0;
        }
        elseif($paid>0){
            if(
                strtotime($utang['due_date'])
                <time()
            ){
                $status="Overdue";
            }else{
                $status="Partial";
            }
        }
        else{
            $status="Unpaid";
        }
        $updStmt = mysqli_prepare($conn, "
            UPDATE utang
            SET paid_amount = ?, balance = ?, status = ?
            WHERE utang_id = ? AND company_id = ?
        ");
        mysqli_stmt_bind_param($updStmt, 'ddsii', $paid, $newBalance, $status, $utangID, $companyId);
        mysqli_stmt_execute($updStmt);
        mysqli_stmt_close($updStmt);
        mysqli_commit($conn);
        echo json_encode([
            "status"=>"success"
        ]);
    }
    catch(Exception $e){
        mysqli_rollback($conn);
        echo json_encode([
            "status"=>"error",
            "message"=>$e->getMessage()
        ]);
    }
    exit;
}

include("cashier_header.php");
?>

<link rel="stylesheet" href="utang.css">

<div class="container-fluid">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body">
            <div class="d-flex justify-content-end align-items-center mb-3">
                <div>
                    <button class="btn btn-primary filter-btn active" data-filter="Unpaid"> Unpaid </button>
                    <button class="btn btn-outline-secondary filter-btn" data-filter="Overdue"> Overdue </button>
                    <button class="btn btn-outline-secondary filter-btn" data-filter="Paid"> Paid </button>
                    <button class="btn btn-outline-secondary filter-btn" data-filter="All"> All </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle" id="utangTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>ID Type</th>
                            <th>ID Number</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th width="180">Action</th>
                        </tr>
                    </thead>
                    <tbody id="utangBody">
                        <?php while($row=mysqli_fetch_assoc($result)){ ?>
                            <?php
                                $status=$row['status'];
                                $badge="badge bg-secondary";
                                if($status=="Paid"){
                                    $badge="badge bg-success";
                                }
                                if($status=="Unpaid"){
                                    $badge="badge bg-primary";
                                }
                                if($status=="Partial"){
                                    $badge="badge bg-warning text-dark";
                                }
                                if($status=="Overdue"){
                                    $badge="badge bg-danger";
                                }
                            ?>
                            <tr data-status="<?= htmlspecialchars($status) ?>">
                                <td><?= htmlspecialchars($row['customer_name']) ?></td>
                                <td><?= htmlspecialchars($row['contact']) ?></td>
                                <td><?= htmlspecialchars($row['id_type']) ?></td>
                                <td><?= htmlspecialchars($row['id_number']) ?></td>
                                <td><?= date("m/d/Y",strtotime($row['date_created'])) ?></td>
                                <td><?= date("Y-m-d",strtotime($row['due_date'])) ?></td>
                                <td>₱<?= number_format($row['total_amount'],2) ?></td>
                                <td>₱<?= number_format($row['paid_amount'],2) ?></td>
                                <td><strong>₱<?= number_format($row['total_balance'],2) ?></strong></td>
                                <td><span class="<?= $badge ?>"><?= $status ?></span></td>
                                <td>
                                    <button
                                        class="btn btn-primary btn-sm paymentBtn"
                                        data-id="<?= $row['utang_id'] ?>"
                                        data-name="<?= htmlspecialchars($row['customer_name']) ?>"
                                        data-balance="<?= $row['balance'] ?>">
                                        <i class="bi bi-cash"></i>
                                        Record Payment
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="paymentModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5>Record Payment</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="utangID">
                <div class="mb-3">
                    <label>Customer</label>
                    <input id="customerName" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label>Current Balance</label>
                    <input id="currentBalance" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label>Payment Amount
                    </label>
                    <input type="number" id="paymentAmount" class="form-control">
                </div>
                <div class="mb-3">
                    <label>Remarks</label>
                    <textarea id="remarks" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="savePayment" class="btn btn-primary">Save Payment</button>
            </div>
        </div>
    </div>
</div>

<style>
    .dataTables_wrapper .dataTables_filter { float:none; text-align:left; padding:18px 18px 12px; }
    .dataTables_wrapper .dataTables_filter label { width:100%; font-size:0; }
    .dataTables_wrapper .dataTables_filter input { margin-left:0!important; width:430px; max-width:100%; height:43px; border:1px solid #d9e1e8; border-radius:22px; padding:0 18px; font-size:14px; outline:none; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color:#00224c; box-shadow:0 0 0 3px rgba(0,34,76,.08); }
    .dataTables_wrapper .dt-layout-row:last-child { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; }
</style>

<script>

var currentStatusFilter = "Unpaid";

DataTable.ext.search.push(function(settings, data, dataIndex) {
    if (settings.nTable.id !== "utangTable") return true;
    if (currentStatusFilter === "All") return true;
    var row = settings.aoData[dataIndex].nTr;
    return row.getAttribute("data-status") === currentStatusFilter;
});

var utangDT = new DataTable("#utangTable", {
    pageLength: 10,
    lengthChange: false,
    ordering: true,
    order: [],
    columnDefs: [{ orderable: false, targets: -1 }],
    language: {
        search: "",
        searchPlaceholder: "Search customer name or contact...",
        info: "Showing _START_ to _END_ of _TOTAL_",
        paginate: { previous: "Previous", next: "Next" }
    }
});

document.querySelectorAll(".filter-btn").forEach(function(btn){
    btn.onclick = function(){
        document.querySelectorAll(".filter-btn").forEach(function(b){ b.classList.remove("active"); b.classList.add("btn-outline-secondary"); b.classList.remove("btn-primary"); });
        this.classList.add("active","btn-primary");
        this.classList.remove("btn-outline-secondary");
        currentStatusFilter = this.dataset.filter;
        utangDT.draw();
    };
});

document.querySelectorAll(".paymentBtn").forEach(btn=>{
    btn.onclick=function(){
        document.getElementById("utangID").value=this.dataset.id;
        document.getElementById("customerName").value=this.dataset.name;
        document.getElementById("currentBalance").value="₱"+this.dataset.balance;
        new bootstrap.Modal(
            document.getElementById("paymentModal")
        ).show();
    };
});

document.getElementById("savePayment").onclick=function(){
    let utangID=document.getElementById("utangID").value;
    let payment=document.getElementById("paymentAmount").value;
    let remarks=document.getElementById("remarks").value;

    if(payment==""||payment<=0){
        Swal.fire({
            icon:"warning",
            title:"Invalid Payment",
            text:"Enter payment amount."
        });
        return;
    }

    Swal.fire({
        title:"Record Payment?",
        text:"This payment will be saved.",
        icon:"question",
        showCancelButton:true,
        confirmButtonText:"Save",
        confirmButtonColor:"#00224c"
    }).then(result=>{

        if(!result.isConfirmed)return;
        let form=new FormData();
        form.append("record_payment",1);
        form.append("utang_id",utangID);
        form.append("payment",payment);
        form.append("remarks",remarks);

        fetch("utang.php",{
            method:"POST",
            body:form
        }).then(r=>r.json()).then(data=>{
            if(data.status=="success"){
                Swal.fire({
                    icon:"success",
                    title:"Payment Recorded",
                    text:"Payment saved successfully."
                }).then(()=>{
                    location.reload();
                });
            }else{
                Swal.fire({
                    icon:"error",
                    title:"Error",
                    text:data.message
                });
            }
        });
    });
}

</script>

<?php
include ('cashier_footer.php');
?>