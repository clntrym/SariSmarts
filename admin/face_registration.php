<?php
/*
|--------------------------------------------------------------------------
| FACE REGISTRATION, FOR AN OWNER WITH NO HR OFFICER
|--------------------------------------------------------------------------
|
| Attendance is taken by face. The face is captured during Employee
| Registration, which lives in HRMS -- and Retail Starter sells no HR role,
| so on that plan nobody could register one. An owner could hire staff and
| then watch every time-in fail, with no screen anywhere that would let
| them fix it.
|
| This is not a second copy of the HR flow. That one captures a face as one
| step of onboarding somebody new, among a dozen others. This does the
| single job of attaching a face to an employee who already exists, for the
| person who is both the owner and the HR department.
*/

require_once __DIR__ . "/../init.php";

requireRole(['admin']);

$companyId = requireCompany();

$MODULE_HEADER = __DIR__ . "/admin_header.php";
$MODULE_FOOTER = __DIR__ . "/admin_footer.php";

/*
| The accounts, not the employee records.
|
| This listed the employees table first, and on Retail Starter that table
| is empty. Employee rows are created by HR during onboarding, and a plan
| with no HR role has no onboarding: its staff are created in User
| Management and are users and nothing else. The owner made two accounts,
| opened this page, and was told "No employees yet".
|
| So this lists what they actually created. The employee row is made when
| a face is first registered -- the convention hr/my_attendance.php already
| set for exactly this situation.
|
| Every join is LEFT: an account with no employee row and no face is
| precisely who this page is for, and an INNER JOIN would hide them.
*/
$stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.fullname,
        u.email,
        u.role,
        e.employee_id,
        e.employee_code,
        b.branch_name,
        eb.face_descriptor
    FROM users u
    LEFT JOIN employees e
        ON e.employee_id = u.employee_id AND e.company_id = u.company_id
    LEFT JOIN branch b
        ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    LEFT JOIN employee_biometrics eb
        ON eb.employee_id = e.employee_id AND eb.company_id = e.company_id
    WHERE u.company_id = ?
      AND LOWER(TRIM(u.status)) = 'active'
    ORDER BY u.fullname
");
$stmt->bind_param("i", $companyId);
$stmt->execute();

$staff = [];
$rows = $stmt->get_result();

while ($row = $rows->fetch_assoc()) {
    $row['has_face'] = trim((string) $row['face_descriptor']) !== '';
    unset($row['face_descriptor']);   /* 128 floats per row, and the page
                                         only needs to know yes or no. */
    $staff[] = $row;
}

$stmt->close();

$registered = count(array_filter($staff, static fn (array $s): bool => $s['has_face']));

include $MODULE_HEADER;
?>

<div class="container-fluid px-4 py-4">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="color:#00224C;">Face Registration</h2>
            <p class="text-muted mb-0">
                Register a staff member's face so they can time in with it.
            </p>
        </div>
        <div class="text-end">
            <span class="badge rounded-pill bg-light text-secondary fs-6">
                <?= (int) $registered ?> of <?= count($staff) ?> registered
            </span>
        </div>
    </div>

    <?php if (!$staff): ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-people fs-1 text-secondary"></i>
                <h5 class="mt-3 mb-1">No staff accounts yet</h5>
                <p class="text-muted mb-0">
                    Create them in
                    <a href="/admin/user_management.php">User Management</a>,
                    then come back to register their faces.
                </p>
            </div>
        </div>

    <?php else: ?>

        <div class="row g-4">

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">

                        <h6 class="fw-semibold mb-3">Staff</h6>

                        <div class="list-group list-group-flush" id="employeeList">

                            <?php foreach ($staff as $person): ?>

                                <button type="button"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    data-user-id="<?= (int) $person['user_id'] ?>"
                                    data-user-name="<?= htmlspecialchars((string) $person['fullname']) ?>">

                                    <span>
                                        <span class="fw-semibold">
                                            <?= htmlspecialchars((string) $person['fullname']) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars(roleDisplayName((string) $person['role'])) ?>
                                            <?php if ($person['branch_name']): ?>
                                                &middot; <?= htmlspecialchars($person['branch_name']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </span>

                                    <?php if ($person['has_face']): ?>
                                        <span class="badge rounded-pill bg-success-subtle text-success">Registered</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-light text-secondary">No face</span>
                                    <?php endif; ?>

                                </button>

                            <?php endforeach; ?>

                        </div>

                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">

                        <h6 class="fw-semibold mb-1">Camera</h6>
                        <p class="text-muted small" id="chosenEmployee">
                            Choose somebody on the left to begin.
                        </p>

                        <div class="rounded-4 overflow-hidden bg-dark position-relative"
                            style="aspect-ratio:4/3;">
                            <video id="faceVideo" autoplay muted playsinline
                                class="w-100 h-100" style="object-fit:cover;"></video>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary" id="startCameraBtn" disabled>
                                <i class="bi bi-camera-video me-1"></i> Start camera
                            </button>
                            <button type="button" class="btn btn-primary" id="captureFaceBtn" disabled>
                                <i class="bi bi-person-bounding-box me-1"></i> Capture and save
                            </button>
                        </div>

                        <div class="form-text mt-2">
                            Good light, one face, looking straight at the camera.
                            Registering again replaces the face already on file.
                        </div>

                    </div>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>

<script src="/assets/js/face-api.min.js"></script>
<script>
/*
| The same models and the same detector the HR page uses, so a face
| registered here is compared the same way at the door.
*/
const faceModelsReady = (async () => {
    await faceapi.nets.tinyFaceDetector.loadFromUri("/models");
    await faceapi.nets.faceLandmark68Net.loadFromUri("/models");
    await faceapi.nets.faceRecognitionNet.loadFromUri("/models");
})();

let chosenUserId = 0;
let chosenName = '';
let stream = null;

const video = document.getElementById('faceVideo');
const startBtn = document.getElementById('startCameraBtn');
const captureBtn = document.getElementById('captureFaceBtn');
const chosenLabel = document.getElementById('chosenEmployee');

document.querySelectorAll('#employeeList [data-user-id]').forEach(item => {

    item.addEventListener('click', () => {

        document.querySelectorAll('#employeeList .active')
            .forEach(other => other.classList.remove('active'));

        item.classList.add('active');

        chosenUserId = parseInt(item.dataset.userId, 10);
        chosenName = item.dataset.userName;

        chosenLabel.textContent = 'Registering ' + chosenName + '.';
        startBtn.disabled = false;
    });
});

startBtn.addEventListener('click', async () => {

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: true });
        video.srcObject = stream;
        captureBtn.disabled = false;
        startBtn.disabled = true;
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'No camera',
            text: 'The browser would not open a camera: ' + error.message
        });
    }
});

captureBtn.addEventListener('click', async () => {

    if (!chosenUserId) {
        return;
    }

    const label = captureBtn.innerHTML;
    captureBtn.disabled = true;
    captureBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Detecting...';

    try {

        await faceModelsReady;

        const detection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) {
            Swal.fire({
                icon: 'warning',
                title: 'No face detected',
                text: 'Have them look straight at the camera, in good light, and try again.'
            });
            return;
        }

        const response = await fetch('/admin/save_employee_face.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: chosenUserId,
                descriptor: Array.from(detection.descriptor)
            })
        });

        const result = await response.json();

        if (!result.success) {
            Swal.fire({ icon: 'error', title: 'Not saved', text: result.message });
            return;
        }

        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }

        Swal.fire({ icon: 'success', title: 'Registered', text: result.message })
            .then(() => window.location.reload());

    } catch (error) {

        Swal.fire({
            icon: 'error',
            title: 'Something went wrong',
            text: error.message
        });

    } finally {
        captureBtn.innerHTML = label;
        captureBtn.disabled = false;
    }
});
</script>

<?php include $MODULE_FOOTER; ?>
