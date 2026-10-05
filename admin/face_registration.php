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
| Everyone on the payroll, and whether their face is on file.
|
| LEFT JOIN, because an employee with no biometric row is exactly who this
| page is for -- an INNER JOIN would hide them.
*/
$stmt = $conn->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.employment_status,
        b.branch_name,
        eb.face_descriptor,
        eb.captured_at
    FROM employees e
    LEFT JOIN branch b
        ON b.branch_id = e.branch_id AND b.company_id = e.company_id
    LEFT JOIN employee_biometrics eb
        ON eb.employee_id = e.employee_id AND eb.company_id = e.company_id
    WHERE e.company_id = ?
      AND (e.archived_at IS NULL)
    ORDER BY e.first_name, e.last_name
");
$stmt->bind_param("i", $companyId);
$stmt->execute();

$employees = [];
$rows = $stmt->get_result();

while ($row = $rows->fetch_assoc()) {
    $row['has_face'] = trim((string) $row['face_descriptor']) !== '';
    unset($row['face_descriptor']);   /* 128 floats per row, and the page
                                         only needs to know yes or no. */
    $employees[] = $row;
}

$stmt->close();

$registered = count(array_filter($employees, static fn (array $e): bool => $e['has_face']));

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
                <?= (int) $registered ?> of <?= count($employees) ?> registered
            </span>
        </div>
    </div>

    <?php if (!$employees): ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-people fs-1 text-secondary"></i>
                <h5 class="mt-3 mb-1">No employees yet</h5>
                <p class="text-muted mb-0">
                    Add staff first, then come back to register their faces.
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

                            <?php foreach ($employees as $employee): ?>

                                <button type="button"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    data-employee-id="<?= (int) $employee['employee_id'] ?>"
                                    data-employee-name="<?= htmlspecialchars(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>">

                                    <span>
                                        <span class="fw-semibold">
                                            <?= htmlspecialchars(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars((string) ($employee['employee_code'] ?: 'No code')) ?>
                                            <?php if ($employee['branch_name']): ?>
                                                &middot; <?= htmlspecialchars($employee['branch_name']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </span>

                                    <?php if ($employee['has_face']): ?>
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

let chosenId = 0;
let chosenName = '';
let stream = null;

const video = document.getElementById('faceVideo');
const startBtn = document.getElementById('startCameraBtn');
const captureBtn = document.getElementById('captureFaceBtn');
const chosenLabel = document.getElementById('chosenEmployee');

document.querySelectorAll('#employeeList [data-employee-id]').forEach(item => {

    item.addEventListener('click', () => {

        document.querySelectorAll('#employeeList .active')
            .forEach(other => other.classList.remove('active'));

        item.classList.add('active');

        chosenId = parseInt(item.dataset.employeeId, 10);
        chosenName = item.dataset.employeeName;

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

    if (!chosenId) {
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
                employee_id: chosenId,
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
