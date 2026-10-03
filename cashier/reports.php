<?php
require_once("../init.php");
requireRole(['cashier']);
include("cashier_header.php");

$companyId = requireCompany();

// Small helper so each chart query below reads the same way.
$scoped = function ($sql) use ($conn, $companyId) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
};

$revenueResult = $scoped("
SELECT
DATE(sale_date) day,
SUM(total_amount) total
FROM sales
WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  AND company_id = ?
GROUP BY DATE(sale_date)
ORDER BY day
");

$revenueLabels = [];
$revenueData = [];

while($row = mysqli_fetch_assoc($revenueResult)){
    $revenueLabels[] = date("M d", strtotime($row['day']));
    $revenueData[] = (float)$row['total'];
}

$bestSellingResult = $scoped("
SELECT
p.product_name,
SUM(si.quantity) qty
FROM sale_items si
INNER JOIN products p
ON si.product_id = p.product_id AND p.company_id = si.company_id
WHERE si.company_id = ?
GROUP BY si.product_id
ORDER BY qty DESC
LIMIT 5
");

$productLabels=[];
$productQty=[];

while($row=mysqli_fetch_assoc($bestSellingResult)){
    $productLabels[]=$row['product_name'];
    $productQty[]=(int)$row['qty'];
}

$utangResult = $scoped("
SELECT status, COUNT(*) total
FROM utang
WHERE company_id = ?
GROUP BY status
");

$utangData=[
    "Paid"=>0,
    "Partial"=>0,
    "Unpaid"=>0,
    "Overdue"=>0
];

while($row=mysqli_fetch_assoc($utangResult)){
    $utangData[$row['status']]=$row['total'];
}

$paymentResult = $scoped("
SELECT payment_method, COUNT(*) total
FROM sales
WHERE company_id = ?
GROUP BY payment_method
");

$paymentData=[
    "Cash"=>0,
    "GCash"=>0,
    "Utang"=>0
];

while($row=mysqli_fetch_assoc($paymentResult)){
    $paymentData[$row['payment_method']]=$row['total'];
}

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .card{
    border:none;
    border-radius:20px;
    box-shadow:0 6px 20px rgba(0,0,0,.08);
}

.card-header{
    background:#fff;
    border-bottom:none;
}

canvas{
    width:100% !important;
}   
</style>

<div class="container-fluid py-1">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class=" mb-0 fw-bold" style="color: #00224c;">
                Reports
            </h1>
            <p class="text-muted mb-0">
                Analytics and business intelligence
            </p>
        </div>
    </div>
    <div class="row g-4">

        <!-- Revenue -->
        <div class="col-lg-12">

            <div class="card shadow-sm border-0 rounded-4">

                <div class="card-header bg-white border-0 pt-4 px-4">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>

                            <h4 class="fw-bold mb-1">
                                Revenue Trend
                            </h4>

                            <small class="text-muted">
                                Sales Revenue
                            </small>

                        </div>

                        <select class="form-select w-auto" id="revenueFilter">
                            <option value="7">Last 7 Days</option>
                            <option value="30">Last Month</option>
                        </select>

                    </div>

                </div>

                <div style="height:380px;">

                    <canvas id="revenueChart"></canvas>

                </div>

            </div>

        </div>

        <!-- Best Selling -->


    </div>

    <div class="row mt-4">

        <!-- Utang -->

        <div class="col-lg-4">

            <div class="card shadow-sm border-0 rounded-4">

                <div class="card-header bg-white">

                    <h5 class="fw-bold">

                        Utang Status

                    </h5>

                </div>

                <div class="card-body">

                    <canvas id="utangChart"></canvas>

                </div>

            </div>

        </div>

        <div class="col-lg-8">

            <div class="card shadow-sm border-0 rounded-4">

                <div class="card-header bg-white">

                    <h5 class="fw-bold">

                        Best Selling Products

                    </h5>

                </div>

                <div class="card-body">

                    <canvas id="bestSellingChart"></canvas>

                </div>

            </div>

        </div>

    </div>

</div>

<script>

    const revenueLabels = <?= json_encode($revenueLabels) ?>;
    const revenueData = <?= json_encode($revenueData) ?>;
    const productLabels = <?= json_encode($productLabels) ?>;
    const productQty = <?= json_encode($productQty) ?>;
    const utangData = <?= json_encode(array_values($utangData)) ?>;
    const paymentData = <?= json_encode(array_values($paymentData)) ?>;

    const ctx = document.getElementById("revenueChart").getContext("2d");

    const gradient = ctx.createLinearGradient(0,0,0,350);

    gradient.addColorStop(0,"rgba(37,99,235,.35)");
    gradient.addColorStop(1,"rgba(37,99,235,0)");

    new Chart(ctx,{

        type:"line",

        data:{

            labels:revenueLabels,

            datasets:[{

                label:"Revenue",

                data:revenueData,

                fill:true,

                backgroundColor:gradient,

                borderColor:"#2563eb",

                borderWidth:2,

                pointRadius:4,

                pointHoverRadius:6,

                pointBackgroundColor:"#2563eb",

                pointBorderColor:"#ffffff",

                pointBorderWidth:2,

                tension:.45

            }]

        },

        options:{

            responsive:true,

            maintainAspectRatio:false,

            interaction:{
                mode:"index",
                intersect:false
            },

            plugins:{

                legend:{
                    display:false
                },

                tooltip:{
                    backgroundColor:"#ffffff",
                    titleColor:"#000",
                    bodyColor:"#000",
                    borderColor:"#ddd",
                    borderWidth:1,
                    displayColors:false,
                    callbacks:{
                        label:function(context){
                            return "Revenue : ₱"+context.parsed.y.toFixed(2);
                        }
                    }
                }

            },

            scales:{

                x:{
                    grid:{
                        color:"#e5e7eb"
                    },
                    ticks:{
                        color:"#64748b"
                    }
                },

                y:{
                    beginAtZero:true,
                    grid:{
                        color:"#e5e7eb"
                    },
                    ticks:{
                        color:"#64748b",
                        callback:function(value){
                            return "₱"+value;
                        }
                    }
                }

            }

        }

    });

    new Chart(document.getElementById("bestSellingChart"),{
        type:"bar",
        data:{
            labels:productLabels,
            datasets:[{
                label:"Quantity Sold",
                data:productQty,
                backgroundColor:"#ffc107"
            }]
        },
        options:{
            indexAxis:"y",
            responsive:true,
            plugins:{
                legend:{
                    display:false
                }
            }
        }
    });

    new Chart(document.getElementById("utangChart"),{
        type:"pie",
        data:{
            labels:["Paid","Partial","Unpaid","Overdue"],
            datasets:[{
                data:utangData,
                backgroundColor:[
                    "#22c55e",
                    "#facc15",
                    "#3b82f6",
                    "#ef4444"
                ]
            }]
        },
        options:{
            responsive:true,
            maintainAspectRatio:true,
            plugins:{
                legend:{
                    position:"bottom"
                }
            }
        }
    });

</script>


<?php include("cashier_footer.php"); ?>