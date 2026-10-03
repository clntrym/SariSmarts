<?php
require_once("../init.php");
requireRole(['employee']);
include("employee_header.php");

$user_id = (int) $_SESSION['user_id'];

$stmtEmp = $conn->prepare("
SELECT u.employee_id, u.fullname, u.role, eb.face_descriptor
FROM users u
JOIN employees e ON u.employee_id = e.employee_id AND e.company_id = u.company_id
LEFT JOIN employee_biometrics eb
    ON eb.employee_id = e.employee_id AND eb.company_id = e.company_id
WHERE u.user_id=? LIMIT 1
");
$stmtEmp->bind_param("i", $user_id);
$stmtEmp->execute();
$employee = $stmtEmp->get_result()->fetch_assoc();
$stmtEmp->close();
$employee_id = (int) $employee['employee_id'];

$stmtRec = $conn->prepare("
SELECT * FROM attendance
WHERE employee_id=?
ORDER BY attendance_date DESC, attendance_id DESC
");
$stmtRec->bind_param("i", $employee_id);
$stmtRec->execute();
$records = $stmtRec->get_result();
?>

<style>
    .attendance-table {
        max-height: 450px;
        overflow-y: auto;
        overflow-x: auto;
    }

    .attendance-table thead th {
        position: sticky;
        top: 0;
        background: #00224C;
        color: white;
        z-index: 10;
    }
</style>

<div class="container-fluid mt-0">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h2 class="fw-bold mb-1" style="color: #00224c;">Attendance</h2>
            <p class="text-muted mb-0">
                Tract your attendance and time logs
            </p>
        </div>
    </div>
    <!-- ================= TOP CARD ================= -->
    <div class="card shadow rounded-4 mb-4">
        <div class="card-body">
            <div class="row">
                <!-- Employee -->
                <div class="col-lg-4 border-end">
                    <h5 class="fw-bold mb-3">
                        Today's Attendance
                    </h5>
                    <div class="d-flex">
                        <img src="../uploads/profile/default.png" width="90" height="90" class="rounded-circle border">
                        <div class="ms-3">
                            <h6 class="fw-bold mb-1">
                                <?= htmlspecialchars($_SESSION['fullname']) ?>
                            </h6>
                            <p class="mb-0">
                                EMP-
                                <?php echo str_pad($employee['employee_id'], 4, "0", STR_PAD_LEFT); ?>
                            </p>
                            <p>
                                <?= htmlspecialchars($employee['role']) ?>
                            </p>
                        </div>
                    </div>
                    <hr>
                    <label class="text-muted">
                        Shift Schedule
                    </label>
                    <h4>
                        8:00 AM - 5:00 PM
                    </h4>
                    <h6>
                        Status :
                        <span class="badge bg-success">
                            Ready
                        </span>
                    </h6>
                </div>
                <!-- Time -->
                <div class="col-lg-4 text-center border-end">
                    <h6>
                        Current Time
                    </h6>
                    <h2 id="clock"></h2>
                    <h5 id="date"></h5>
                    <button class="btn btn-warning w-100 mb-2" onclick="registerFace()">
                        <i class="fa fa-user-check"></i>
                        Register / Update Face
                    </button>
                    <button class="btn btn-success w-100 mb-2" onclick="timeIn()">
                        <i class="fa fa-camera"></i>
                        Time In
                    </button>
                    <button class="btn btn-danger w-100" onclick="timeOut()">
                        <i class="fa fa-camera"></i>
                        Time Out
                    </button>
                </div>
                <!-- Camera -->
                <div class="col-lg-4 text-center">
                    <div class="card shadow rounded-4 h-100">
                        <div class="card-header fw-bold">
                            Camera Preview
                        </div>
                        <div class="card-body text-center">
                            <video id="video" autoplay playsinline width="100%" style="
                                border-radius:15px;
                                height:250px;
                                object-fit:cover;
                                border:2px solid #ddd;
                            ">
                            </video>
                            <canvas id="canvas" style="display:none"></canvas>
                            <hr>
                            <?php if (empty($employee['face_descriptor'])) { ?>
                                <div class="alert alert-warning">
                                    <i class="fa fa-face-frown"></i>
                                    Face Not Registered
                                </div>
                            <?php } else { ?>
                                <div class="alert alert-success">
                                    <i class="fa fa-circle-check"></i>
                                    Face Registered
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card shadow rounded-4 mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-4">
                Quick Request
            </h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="card rounded-4 shadow-sm">
                        <div class="card-body text-center">
                            <h5>
                                Leave Request
                            </h5>
                            <p class="text-muted">
                                File Leave Request
                            </p>
                            <a href="leave_request.php" class="btn btn-primary rounded-pill">
                                New Request
                            </a>
                        </div>
                    </div>
                </div>
                <!-- <div class="col-md-4">
                    <div class="card rounded-4 shadow-sm">
                        <div class="card-body text-center">
                            <h5>
                                Overtime Request
                            </h5>
                            <p class="text-muted">
                                Request OT
                            </p>
                            <a href="overtime_request.php" class="btn btn-primary rounded-pill">
                                New Request
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card rounded-4 shadow-sm">
                        <div class="card-body text-center">
                            <h5>
                                Attendance Correction
                            </h5>
                            <p class="text-muted">
                                Correct Attendance
                            </p>
                            <a href="attendance_correction.php" class="btn btn-primary rounded-pill">
                                New Request
                            </a>
                        </div>
                    </div>
                </div> -->
            </div>
        </div>
    </div>
    <!-- <div class="card shadow rounded-4">
        <div class="card-body">
            <div class="d-flex justify-content-between mb-3">
                <h5>
                    Attendance Records
                </h5>
            </div>
            <div class="table-responsive attendance-table">
                <table class="table table-bordered table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Working Hours</th>
                            <th>Late</th>
                            <th>Overtime</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (mysqli_num_rows($records) > 0) {
                            while ($row = mysqli_fetch_assoc($records)) {
                                ?>
                                <tr>
                                    <td>
                                        <?= date("M d, Y", strtotime($row['attendance_date'])) ?>
                                    </td>
                                    <td>
                                        <?= $row['time_in']
                                            ? date("h:i A", strtotime($row['time_in']))
                                            : "-" ?>
                                    </td>
                                    <td>
                                        <?= $row['time_out']
                                            ? date("h:i A", strtotime($row['time_out']))
                                            : "-" ?>
                                    </td>
                                    <td>
                                        <?= number_format($row['working_hours'], 2) ?>
                                        hrs
                                    </td>
                                    <td>
                                        <?= $row['late_minutes'] ?>
                                        mins
                                    </td>
                                    <td>
                                        <?= number_format($row['overtime_hours'], 2) ?>
                                        hrs
                                    </td>
                                    <td>
                                        <?php
                                        switch ($row['status']) {
                                            case "Present":
                                                echo '<span class="badge bg-success">Present</span>';
                                                break;
                                            case "Late":
                                                echo '<span class="badge bg-warning text-dark">Late</span>';
                                                break;
                                            case "Half Day":
                                                echo '<span class="badge bg-info">Half Day</span>';
                                                break;
                                            case "Absent":
                                                echo '<span class="badge bg-danger">Absent</span>';
                                                break;
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                            data-bs-target="#view<?= $row['attendance_id'] ?>">
                                            View
                                        </button>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            ?>
                            <tr>
                                <td colspan="9" class="text-center">
                                    No attendance records found.
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> -->
    <?php
    $records->data_seek(0);
    while ($row = $records->fetch_assoc()) {
        ?>
        <div class="modal fade" id="view<?= $row['attendance_id'] ?>">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5>Attendance Details</h5>
                        <button class="btn-close" data-bs-dismiss="modal">
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Time In Photo</h6>
                                <img src="../<?= htmlspecialchars($row['photo_in']) ?>" class="img-fluid rounded border">
                            </div>
                            <div class="col-md-6">
                                <h6>Time Out Photo</h6>
                                <?php if ($row['photo_out']) { ?>
                                    <img src="../<?= htmlspecialchars($row['photo_out']) ?>" class="img-fluid rounded border">
                                <?php } else { ?>
                                    <p class="text-muted">
                                        No Time Out Photo
                                    </p>
                                <?php } ?>
                            </div>
                        </div>
                        <hr>
                        <table class="table">
                            <tr>
                                <th>Date</th>
                                <td>
                                    <?= date("F d, Y", strtotime($row['attendance_date'])) ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Time In</th>
                                <td>
                                    <?= $row['time_in']
                                        ? date("h:i:s A", strtotime($row['time_in']))
                                        : "-" ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Time Out</th>
                                <td>
                                    <?= $row['time_out']
                                        ? date("h:i:s A", strtotime($row['time_out']))
                                        : "-" ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Late</th>
                                <td>
                                    <?= (int)$row['late_minutes'] ?>
                                    minutes
                                </td>
                            </tr>
                            <tr>
                                <th>Undertime</th>
                                <td>
                                    <?= (int)$row['undertime_minutes'] ?>
                                    minutes
                                </td>
                            </tr>
                            <tr>
                                <th>Working Hours</th>
                                <td>
                                    <?= number_format((float)$row['working_hours'], 2) ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Overtime</th>
                                <td>
                                    <?= number_format((float)$row['overtime_hours'], 2) ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <?= htmlspecialchars($row['status']) ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<script src="../assets/js/face-api.min.js"></script>

<script>

    async function loadFaceModels() {
        await faceapi.nets.tinyFaceDetector.loadFromUri("../models");
        await faceapi.nets.faceLandmark68Net.loadFromUri("../models");
        await faceapi.nets.faceRecognitionNet.loadFromUri("../models");
        console.log("✅ Face AI Loaded");
    }

    loadFaceModels();

    function updateClock() {
        const now = new Date();
        document.getElementById("clock").innerHTML =
            now.toLocaleTimeString();
        document.getElementById("date").innerHTML =
            now.toDateString();
    }

    setInterval(updateClock, 1000);
    updateClock();
    const video = document.getElementById("video");

    if (video) {
        navigator.mediaDevices.getUserMedia({
            video: true
        })
            .then(function (stream) {
                video.srcObject = stream;

            })
            .catch(function (error) {
                alert("Camera permission denied.");
            });
    }


    async function captureAttendance(type) {
        navigator.geolocation.getCurrentPosition(async function (position) {
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            const video = document.getElementById("video");
            const canvas = document.getElementById("canvas");
            const ctx = canvas.getContext("2d");

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);

            const detection = await faceapi
                .detectSingleFace(
                    video,
                    new faceapi.TinyFaceDetectorOptions()
                )
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                Swal.fire(
                    "No Face Detected",
                    "Please position your face in front of the camera.",
                    "warning"
                );
                return;
            }

            const response = await fetch("verify_face.php");
            const employee = await response.json();

            if (!employee.success) {
                Swal.fire(
                    "Error",
                    employee.message,
                    "error"
                );
                return;
            }

            // Convert saved descriptor back into Float32Array
            const labeled = new faceapi.LabeledFaceDescriptors(
                "Employee",
                [
                    new Float32Array(JSON.parse(employee.face_descriptor))
                ]
            );

            // Compare live face with registered face
            const matcher = new faceapi.FaceMatcher(labeled, 0.5);
            const result = matcher.findBestMatch(detection.descriptor);
            console.log(result.toString());

            if (result.label === "unknown") {
                Swal.fire(
                    "Access Denied",
                    "Face does not match the registered employee.",
                    "error"
                );
                return;
            }

            const image = canvas.toDataURL("image/jpeg");

            fetch("save_attendance.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body:
                    "type=" + encodeURIComponent(type) +
                    "&image=" + encodeURIComponent(image) +
                    "&latitude=" + encodeURIComponent(latitude) +
                    "&longitude=" + encodeURIComponent(longitude)
            })

                .then(async (res) => {
                    const text = await res.text();
                    console.log("save_attendance.php response:");
                    console.log(text);
                    return JSON.parse(text);
                })

                .then(data => {
                    Swal.fire({
                        icon: data.success ? "success" : "error",
                        title: data.message
                    }).then(() => {
                        if (data.success) {
                            location.reload();
                        }
                    });
                });
        },

            function () {
                Swal.fire(
                    "GPS Error",
                    "Please enable your location.",
                    "error"
                );
            });
    }

    async function registerFace() {
        const detection = await faceapi
            .detectSingleFace(
                video,
                new faceapi.TinyFaceDetectorOptions()
            )
            .withFaceLandmarks()
            .withFaceDescriptor();
        if (!detection) {
            Swal.fire(
                "No Face Detected",
                "Please look at the camera.",
                "warning"
            );
            return;
        }
        fetch("save_face.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                descriptor: Array.from(detection.descriptor)
            })
        })
            .then(res => res.json())
            .then(data => {
                Swal.fire({
                    icon: data.success ? "success" : "error",
                    title: data.message
                }).then(() => {
                    if (data.success) {
                        location.reload();
                    }
                });
            });
    }

    function timeIn() {
        captureAttendance("IN");
    }

    function timeOut() {
        captureAttendance("OUT");
    }

</script>

<?php include("employee_footer.php"); ?>