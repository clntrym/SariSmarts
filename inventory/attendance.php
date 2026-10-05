<?php
require_once("../init.php");
requireRole(['inventory']);
include("inventory_header.php");

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
SELECT
    u.employee_id,
    u.fullname,
    u.role,
    eb.face_descriptor
FROM users u
JOIN employees e
    ON u.employee_id = e.employee_id
LEFT JOIN employee_biometrics eb
    ON e.employee_id = eb.employee_id
   AND eb.biometric_type = 'Face'
WHERE u.user_id=?
LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$getEmployee = $stmt->get_result();

$employee = $getEmployee->fetch_assoc();
$employee_id = (int) $employee['employee_id'];

$stmt2 = $conn->prepare("
SELECT *
FROM attendance
WHERE employee_id=?
ORDER BY attendance_date DESC, attendance_id DESC
");
$stmt2->bind_param("i", $employee_id);
$stmt2->execute();
$records = $stmt2->get_result();

/*
=========================================================
INITIALS (avatar fallback — no profile photo upload exists)
=========================================================
*/

$fullNameForInitials = trim($_SESSION['fullname'] ?? ($employee['fullname'] ?? ''));
$nameParts = preg_split('/\s+/', $fullNameForInitials, -1, PREG_SPLIT_NO_EMPTY);

$initials = '';

if (!empty($nameParts)) {
    $initials .= strtoupper(substr($nameParts[0], 0, 1));

    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr(end($nameParts), 0, 1));
    }
}

/*
=========================================================
TODAY'S STATUS (drives the status pill + button states)
=========================================================
*/

$today = date('Y-m-d');
$todayAttendance = null;

if ($records) {
    $records->data_seek(0);
    $firstRow = $records->fetch_assoc();

    if ($firstRow && $firstRow['attendance_date'] === $today) {
        $todayAttendance = $firstRow;
    }

    $records->data_seek(0);
}

if ($todayAttendance && empty($todayAttendance['time_out'])) {

    $statusLabel = 'Clocked In';
    $statusClass = 'attn-status-in';
    $timeInDisabled = 'disabled';
    $timeOutDisabled = '';

} elseif ($todayAttendance && !empty($todayAttendance['time_out'])) {

    $statusLabel = 'Clocked Out';
    $statusClass = 'attn-status-out';
    $timeInDisabled = 'disabled';
    $timeOutDisabled = 'disabled';

} else {

    $statusLabel = 'Not Timed In';
    $statusClass = 'attn-status-pending';
    $timeInDisabled = '';
    $timeOutDisabled = 'disabled';
}
?>

<style>
    :root {
        --attn-ink: #00224C;
        --attn-ink-deep: #061B36;
        --attn-canvas: #EEF2F8;
        --attn-surface: #FFFFFF;
        --attn-line: #E1E7F0;
        --attn-muted: #63708A;
        --attn-amber: #F5B342;
        --attn-success: #1E9E6B;
        --attn-success-soft: #E4F7EF;
        --attn-warn: #D98C0F;
        --attn-warn-soft: #FDF3E0;
    }

    .attendance-table {
        max-height: 450px;
        overflow-y: auto;
        overflow-x: auto;
    }

    .attendance-table thead th {
        position: sticky;
        top: 0;
        background: var(--attn-ink);
        color: white;
        z-index: 10;
    }

    .attn-page-head p {
        color: var(--attn-muted);
    }

    /* -------- Hero shell -------- */

    .attn-hero {
        background: var(--attn-surface);
        border-radius: 24px;
        border: 1px solid var(--attn-line);
        box-shadow: 0 1px 2px rgba(6, 27, 54, .04), 0 20px 40px -24px rgba(6, 27, 54, .3);
        overflow: hidden;
    }

    .attn-panel {
        padding: 28px 26px;
        height: 100%;
    }

    @media (min-width: 992px) {
        .attn-panel-clock {
            border-left: 1px solid var(--attn-line);
            border-right: 1px solid var(--attn-line);
        }
    }

    /* -------- Profile panel -------- */

    .attn-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--attn-ink);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        flex-shrink: 0;
    }

    .attn-emp-name {
        font-weight: 700;
        color: var(--attn-ink);
        margin-bottom: 2px;
    }

    .attn-emp-meta {
        color: var(--attn-muted);
        font-size: 13.5px;
    }

    .attn-divider {
        border: none;
        border-top: 1px solid var(--attn-line);
        margin: 20px 0;
    }

    .attn-label {
        color: var(--attn-muted);
        font-size: 12.5px;
        margin-bottom: 4px;
    }

    .attn-shift {
        font-weight: 700;
        color: var(--attn-ink);
        font-size: 20px;
        margin-bottom: 16px;
    }

    .attn-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 12.5px;
        font-weight: 600;
    }

    .attn-status-pill::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .attn-status-pending {
        background: var(--attn-canvas);
        color: var(--attn-muted);
    }

    .attn-status-in {
        background: var(--attn-success-soft);
        color: var(--attn-success);
    }

    .attn-status-out {
        background: #E7ECF5;
        color: var(--attn-ink);
    }

    /* -------- Clock panel -------- */

    .attn-panel-clock {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    .attn-clock-display {
        background: var(--attn-ink-deep);
        border-radius: 16px;
        padding: 22px 20px;
        width: 100%;
        margin-bottom: 20px;
    }

    .attn-clock-time {
        font-size: 34px;
        font-weight: 800;
        color: var(--attn-amber);
        font-variant-numeric: tabular-nums;
        letter-spacing: .5px;
        line-height: 1.1;
    }

    .attn-clock-date {
        font-size: 13px;
        color: #9FB2CC;
        margin-top: 6px;
    }

    .attn-punch-btn {
        width: 100%;
        border: none;
        border-radius: 14px;
        padding: 14px 18px;
        font-weight: 700;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-bottom: 12px;
        transition: transform .08s ease, opacity .15s ease;
        cursor: pointer;
    }

    .attn-punch-btn:last-child {
        margin-bottom: 0;
    }

    .attn-punch-btn:active:not(:disabled) {
        transform: scale(.98);
    }

    .attn-punch-btn:disabled {
        opacity: .4;
        cursor: not-allowed;
    }

    .attn-punch-in {
        background: var(--attn-success);
        color: #fff;
        box-shadow: 0 10px 24px -12px rgba(30, 158, 107, .6);
    }

    .attn-punch-out {
        background: #fff;
        color: var(--attn-ink);
        border: 1.5px solid var(--attn-line);
    }

    /* -------- Camera panel -------- */

    .attn-scanner {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        background: #05070C;
        aspect-ratio: 4 / 3;
        margin-bottom: 14px;
    }

    .attn-scanner video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .attn-scanner-off {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #6B7A94;
        font-size: 13px;
        pointer-events: none;
        transition: opacity .2s ease;
    }

    .attn-scanner-off i {
        font-size: 26px;
    }

    .attn-scanner.is-live .attn-scanner-off {
        opacity: 0;
    }

    .attn-scanner.is-live {
        box-shadow: 0 0 0 3px var(--attn-success-soft), 0 0 24px rgba(30, 158, 107, .35);
    }

    .attn-cam-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 12.5px;
        font-weight: 600;
        background: var(--attn-canvas);
        color: var(--attn-muted);
    }

    .attn-cam-badge::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .attn-cam-badge.is-live {
        background: var(--attn-success-soft);
        color: var(--attn-success);
    }

    .attn-face-status {
        margin-top: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 13.5px;
        font-weight: 600;
    }

    .attn-face-ok {
        background: var(--attn-success-soft);
        color: var(--attn-success);
    }

    .attn-face-missing {
        background: var(--attn-warn-soft);
        color: var(--attn-warn);
    }

    /* -------- Requests -------- */

    .attn-requests {
        background: var(--attn-surface);
        border-radius: 24px;
        border: 1px solid var(--attn-line);
        padding: 26px;
    }

    .attn-request-row {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px 4px;
        border-top: 1px solid var(--attn-line);
    }

    .attn-request-row:first-of-type {
        border-top: none;
        padding-top: 4px;
    }

    .attn-request-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: var(--attn-canvas);
        color: var(--attn-ink);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }

    .attn-request-title {
        font-weight: 700;
        color: var(--attn-ink);
        margin-bottom: 2px;
    }

    .attn-request-desc {
        color: var(--attn-muted);
        font-size: 13.5px;
    }

    .attn-request-cta {
        margin-left: auto;
        white-space: nowrap;
        border-radius: 10px;
        font-weight: 600;
        font-size: 13.5px;
        padding: 8px 16px;
        background: var(--attn-ink);
        color: #fff;
        border: none;
        text-decoration: none;
    }

    .attn-request-cta:hover {
        background: #001836;
        color: #fff;
    }

    @media (max-width: 767.98px) {
        .attn-request-row {
            flex-wrap: wrap;
        }

        .attn-request-cta {
            margin-left: 60px;
        }
    }
</style>

<div class="container-fluid mt-0">
    <div class="attn-page-head d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="fw-bold mb-1" style="color: #00224c;">Attendance</h2>
            <p class="mb-0">
                Track your time in and time out
            </p>
        </div>
    </div>

    <!-- ================= TIME CLOCK ================= -->
    <div class="attn-hero mb-4">
        <div class="row g-0">

            <!-- Profile -->
            <div class="col-lg-4">
                <div class="attn-panel">

                    <div class="d-flex align-items-center">
                        <div class="attn-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="ms-3">
                            <div class="attn-emp-name">
                                <?php echo htmlspecialchars($_SESSION['fullname']); ?>
                            </div>
                            <div class="attn-emp-meta">
                                EMP-<?php echo str_pad($employee['employee_id'], 4, "0", STR_PAD_LEFT); ?>
                                &nbsp;·&nbsp;
                                <?php echo htmlspecialchars($employee['role']); ?>
                            </div>
                        </div>
                    </div>

                    <hr class="attn-divider">

                    <div class="attn-label">Shift schedule</div>
                    <div class="attn-shift">8:00 AM – 5:00 PM</div>

                    <span class="attn-status-pill <?= $statusClass ?>">
                        <?= htmlspecialchars($statusLabel) ?>
                    </span>

                </div>
            </div>

            <!-- Clock -->
            <div class="col-lg-4">
                <div class="attn-panel attn-panel-clock">

                    <div class="attn-clock-display">
                        <div class="attn-clock-time" id="clock"></div>
                        <div class="attn-clock-date" id="date"></div>
                    </div>

                    <button class="attn-punch-btn attn-punch-in" onclick="timeIn()" <?= $timeInDisabled ?>>
                        <i class="fa fa-camera"></i>
                        Time In
                    </button>

                    <button class="attn-punch-btn attn-punch-out" onclick="timeOut()" <?= $timeOutDisabled ?>>
                        <i class="fa fa-camera"></i>
                        Time Out
                    </button>

                </div>
            </div>

            <!-- Camera -->
            <div class="col-lg-4">
                <div class="attn-panel">

                    <div class="attn-scanner" id="attnScanner">
                        <video id="video" autoplay playsinline></video>
                        <div class="attn-scanner-off">
                            <i class="fa fa-camera"></i>
                            <span>Camera is off</span>
                        </div>
                    </div>

                    <canvas id="canvas" style="display:none"></canvas>

                    <div class="text-center">
                        <span id="cameraStatusBadge" class="attn-cam-badge">
                            Camera off
                        </span>
                    </div>

                    <?php if (empty($employee['face_descriptor'])) { ?>
                            <div class="attn-face-status attn-face-missing">
                                <i class="fa fa-face-frown"></i>
                                Face not registered
                            </div>
                    <?php } else { ?>
                            <div class="attn-face-status attn-face-ok">
                                <i class="fa fa-circle-check"></i>
                                Face registered
                            </div>
                    <?php } ?>

                </div>
            </div>

        </div>
    </div>

    <!-- ================= REQUESTS ================= -->
    <div class="attn-requests mb-4">

        <h5 class="fw-bold mb-2" style="color:#00224c;">
            Requests
        </h5>

        <div class="attn-request-row">
            <div class="attn-request-icon">
                <i class="fa fa-calendar-days"></i>
            </div>
            <div>
                <div class="attn-request-title">Leave Request</div>
                <div class="attn-request-desc">File a leave request for HR to review</div>
            </div>
            <a href="leave_request.php" class="attn-request-cta">New Request</a>
        </div>

        <div class="attn-request-row">
            <div class="attn-request-icon">
                <i class="fa fa-business-time"></i>
            </div>
            <div>
                <div class="attn-request-title">Overtime Request</div>
                <div class="attn-request-desc">Request overtime hours for approval</div>
            </div>
            <a href="overtime_request.php" class="attn-request-cta">New Request</a>
        </div>

        <!--
        <div class="attn-request-row">
            <div class="attn-request-icon">
                <i class="fa fa-clock-rotate-left"></i>
            </div>
            <div>
                <div class="attn-request-title">Attendance Correction</div>
                <div class="attn-request-desc">Correct a missed or wrong attendance entry</div>
            </div>
            <a href="attendance_correction.php" class="attn-request-cta">New Request</a>
        </div>
        -->

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
                                    <img src="/attendance_photo.php?path=<?= urlencode($row['photo_in']) ?>" class="img-fluid rounded border">
                                </div>
                                <div class="col-md-6">
                                    <h6>Time Out Photo</h6>
                                    <?php if ($row['photo_out']) { ?>
                                            <img src="/attendance_photo.php?path=<?= urlencode($row['photo_out']) ?>" class="img-fluid rounded border">
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

    let cameraStream = null;

    function setCameraStatus(isOn) {

        const badge = document.getElementById("cameraStatusBadge");
        const scanner = document.getElementById("attnScanner");

        if (badge) {
            badge.textContent = isOn ? "Camera on" : "Camera off";
            badge.classList.toggle("is-live", isOn);
        }

        if (scanner) {
            scanner.classList.toggle("is-live", isOn);
        }
    }

    async function openCamera() {

        const video = document.getElementById("video");

        if (!video) {
            return null;
        }

        if (cameraStream) {
            return cameraStream;
        }

        try {

            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: true
            });

            video.srcObject = cameraStream;

            setCameraStatus(true);

            // Make sure the video actually has frames before we
            // let face-api read from it.
            await new Promise((resolve) => {

                if (video.readyState >= 2) {
                    resolve();
                    return;
                }

                video.onloadedmetadata = () => resolve();
            });

            return cameraStream;

        } catch (error) {

            console.error(error);

            Swal.fire(
                "Camera Error",
                "Camera permission denied or unavailable.",
                "error"
            );

            cameraStream = null;

            return null;
        }
    }

    function closeCamera() {

        const video = document.getElementById("video");

        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }

        if (video) {
            video.srcObject = null;
        }

        setCameraStatus(false);
    }


    async function captureAttendance(type) {

        const stream = await openCamera();

        if (!stream) {
            // Permission denied / camera unavailable — already alerted.
            return;
        }

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
                closeCamera();
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
                closeCamera();
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
                closeCamera();
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

                    closeCamera();

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
                closeCamera();
            });
    }

    function timeIn() {
        captureAttendance("IN");
    }

    function timeOut() {
        captureAttendance("OUT");
    }

</script>

<?php include("inventory_footer.php"); ?>