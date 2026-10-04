<?php
require_once __DIR__ . "/config.php";

// Gracefully handle case where job table doesn't exist yet
try {
    $jobsQuery = $conn->query("
        SELECT
            j.*,
            b.branch_name
        FROM job j
        INNER JOIN branch b
            ON j.branch_id = b.branch_id
        WHERE j.status = 'Published'
        AND (
            j.application_deadline IS NULL
            OR j.application_deadline = ''
            OR j.application_deadline >= CURDATE()
        )
        ORDER BY j.created_at DESC
    ");
} catch (Exception $e) {
    $jobsQuery = false;
}
if ($jobsQuery === false && $conn->errno) {
    $jobsQuery = null;
}

/* Page copy comes from the Super Admin careers editor. */
$careers = $conn->query("SELECT * FROM website_careers_section ORDER BY section_id LIMIT 1")->fetch_assoc() ?: [];

include __DIR__ . "/header.php";
?>


<section class="pricing-hero">
    <div class="container">
        <span class="pricing-badge">
            <?= htmlspecialchars($careers['hero_badge'] ?? 'CAREERS') ?>
        </span>
        <h1 class="pricing-title mt-4">
            <?= htmlspecialchars($careers['hero_title'] ?? '') ?>
        </h1>
        <p class="pricing-description mt-4">
            <?= nl2br(htmlspecialchars($careers['hero_description'] ?? '')) ?>
        </p>
    </div>
</section>
<!-- <section class="py-24 bg-slate-50">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span
                class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                BENEFITS
            </span>
            <h2 class="text-5xl font-bold text-slate-900 mt-5">
                What we offer
            </h2>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                <div
                    class="w-14 h-14 rounded-xl bg-sky-100 flex items-center justify-center text-sky-600 text-2xl mb-5">
                    <i class="fa-solid fa-cash-register"></i>
                </div>
                <h3 class="text-xl font-semibold text-slate-900">Point of Sale</h3>
                <p class="text-gray-500 mt-2 text-sm">Fast and seamless sales transactions.</p>
            </div>
        </div>
    </div>
</section> -->
<section class="py-24 bg-[#F1F5F9]">

    <div class="max-w-7xl mx-auto px-6">

        <div class="text-center mb-16">

            <span
                class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                <?= htmlspecialchars($careers['jobs_badge'] ?? 'OPEN JOB') ?>
            </span>

            <h2 class="text-5xl font-bold text-slate-900 mt-5">
                <?= htmlspecialchars($careers['jobs_title'] ?? 'Open Positions') ?>
            </h2>

            <p class="text-gray-500 mt-4">
                <?= htmlspecialchars($careers['jobs_description'] ?? '') ?>
            </p>

        </div>


        <?php if ($jobsQuery && $jobsQuery->num_rows > 0): ?>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">

                <?php while ($job = $jobsQuery->fetch_assoc()): ?>

                    <div
                        class="bg-white rounded-2xl border border-gray-200 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col overflow-hidden">

                        <!-- JOB IMAGE HEADER -->

                        <div class="w-full h-48 bg-sky-100 overflow-hidden">

                            <?php if (!empty($job['job_image'])): ?>

                                <img src="../assets/job-images/<?= htmlspecialchars($job['job_image']); ?>"
                                    alt="<?= htmlspecialchars($job['job_title']); ?>" class="w-full h-full object-cover">

                            <?php else: ?>

                                <img src="../assets/careers-banner.jpg" alt="SariSmart Careers"
                                    class="w-full h-full object-cover">


                            <?php endif; ?>

                        </div>

                        <!-- CARD CONTENT -->

                        <div class="p-6">

                            <!-- STATUS -->

                            <div class="mb-4">

                                <span
                                    class="inline-block px-3 py-1 rounded-full bg-green-100 text-green-600 text-xs font-semibold uppercase">

                                    NOW HIRING

                                </span>

                            </div>


                            <!-- JOB TITLE -->

                            <h3 class="text-2xl font-bold text-slate-900">

                                <?= htmlspecialchars($job['job_title']); ?>

                            </h3>


                            <!-- DEPARTMENT -->

                            <div class="flex items-center gap-2 text-gray-500 mt-4 text-sm">

                                <i class="fa-solid fa-building"></i>

                                <span>
                                    <?= htmlspecialchars($job['department']); ?>
                                </span>

                            </div>


                            <!-- BRANCH -->

                            <div class="flex items-center gap-2 text-gray-500 mt-2 text-sm">

                                <i class="fa-solid fa-location-dot"></i>

                                <span>
                                    <?= htmlspecialchars($job['branch_name']); ?>
                                </span>

                            </div>


                            <!-- EMPLOYMENT TYPE -->

                            <div class="flex items-center gap-2 text-gray-500 mt-2 text-sm">

                                <i class="fa-solid fa-briefcase"></i>

                                <span>
                                    <?= htmlspecialchars($job['employment_type']); ?>
                                </span>

                            </div>


                            <!-- VACANCIES -->

                            <div class="flex items-center gap-2 text-gray-500 mt-2 text-sm">

                                <i class="fa-solid fa-users"></i>

                                <span>
                                    <?= (int) $job['vacancies']; ?> Vacancies
                                </span>

                            </div>


                            <!-- SALARY -->

                            <div class="mt-5">

                                <p class="text-xs text-gray-400 uppercase tracking-wide">
                                    Salary
                                </p>

                                <p class="font-semibold text-slate-800 mt-1">

                                    <?php if (
                                        !empty($job['salary_min']) &&
                                        !empty($job['salary_max'])
                                    ): ?>

                                        &#8369;<?= number_format($job['salary_min']); ?>
                                        -
                                        &#8369;<?= number_format($job['salary_max']); ?>

                                    <?php else: ?>

                                        Negotiable

                                    <?php endif; ?>

                                </p>

                            </div>


                            <!-- DEADLINE -->

                            <?php if (!empty($job['application_deadline'])): ?>

                                <div class="mt-3">

                                    <p class="text-xs text-gray-400 uppercase tracking-wide">
                                        Application Deadline
                                    </p>

                                    <p class="text-sm font-medium text-slate-700 mt-1">

                                        <?= date(
                                            'F d, Y',
                                            strtotime($job['application_deadline'])
                                        ); ?>

                                    </p>

                                </div>

                            <?php endif; ?>


                            <!-- APPLY BUTTON -->

                            <!-- VIEW JOB BUTTON -->

                            <div class="mt-auto pt-6">

                                <button type="button"
                                    class="block w-full text-center px-5 py-3 rounded-xl bg-sky-600 text-white font-semibold hover:bg-sky-700 transition viewJobBtn"
                                    data-id="<?= (int) $job['job_id']; ?>"
                                    data-image="<?= htmlspecialchars($job['job_image'] ?? '', ENT_QUOTES); ?>"
                                    data-title="<?= htmlspecialchars($job['job_title'], ENT_QUOTES); ?>"
                                    data-department="<?= htmlspecialchars($job['department'], ENT_QUOTES); ?>"
                                    data-branch="<?= htmlspecialchars($job['branch_name'], ENT_QUOTES); ?>"
                                    data-employment="<?= htmlspecialchars($job['employment_type'], ENT_QUOTES); ?>"
                                    data-vacancies="<?= (int) $job['vacancies']; ?>"
                                    data-description="<?= htmlspecialchars($job['job_description'], ENT_QUOTES); ?>"
                                    data-responsibilities="<?= htmlspecialchars($job['responsibilities'], ENT_QUOTES); ?>"
                                    data-qualification="<?= htmlspecialchars($job['qualifications'], ENT_QUOTES); ?>"
                                    data-salary="<?=
                                        ($job['salary_min'] && $job['salary_max'])
                                        ? '&#8369;' . number_format($job['salary_min']) . ' - &#8369;' . number_format($job['salary_max'])
                                        : 'Negotiable';
                                    ?>"
                                    data-deadline="<?= htmlspecialchars($job['application_deadline'], ENT_QUOTES); ?>"
                                    data-bs-toggle="modal" data-bs-target="#viewJobModal">

                                    View Job

                                    <i class="fa-solid fa-arrow-right ml-2"></i>

                                </button>

                            </div>

                        </div>
                    </div>

                <?php endwhile; ?>

            </div>

        <?php else: ?>

            <!-- NO JOBS -->

            <div class="text-center py-16">

                <div
                    class="w-20 h-20 mx-auto rounded-full bg-sky-100 flex items-center justify-center text-sky-600 text-3xl mb-6">

                    <i class="fa-solid fa-briefcase"></i>

                </div>

                <h3 class="text-2xl font-bold text-slate-900">
                    <?= htmlspecialchars($careers['empty_title'] ?? 'No Open Positions') ?>
                </h3>

                <p class="text-gray-500 mt-3">
                    <?= htmlspecialchars($careers['empty_description'] ?? '') ?>
                </p>

            </div>

        <?php endif; ?>

    </div>

</section>
<!-- JOB VIEW MODAL -->

<div class="modal fade" id="viewJobModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content rounded-4 overflow-hidden">


            <div class="modal-header bg-primary text-white">

                <h4 class="modal-title fw-bold">
                    Job Details
                </h4>

                <button class="btn-close btn-close-white" data-bs-dismiss="modal">
                </button>

            </div>


            <div class="modal-body p-0">


                <!-- Banner -->
                <div class="position-relative">

                    <img src="../assets/careers-banner.jpg" class="w-100"
                        style="height:320px;object-fit:cover;">


                    <div class="position-absolute top-50 start-50 translate-middle text-center text-white">

                        <span class="badge bg-warning text-dark fs-6">
                            WE ARE HIRING
                        </span>


                        <h1 class="fw-bold mt-3" id="viewTitle">
                            Job Title
                        </h1>


                        <p>
                            SariSmart Retail OS
                        </p>

                    </div>

                </div>



                <div class="p-4">


                    <div class="row text-muted mb-4">


                        <div class="col-md-6 mb-2">
                            <i class="fa-solid fa-location-dot me-1"></i>
                            <span id="viewBranch">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            <i class="fa-solid fa-building me-1"></i>
                            <span id="viewDepartment">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            <i class="fa-solid fa-briefcase me-1"></i>
                            <span id="viewEmployment">
                            </span>
                        </div>


                        <div class="col-md-6 mb-2">
                            <i class="fa-solid fa-users me-1"></i>
                            <span id="viewVacancies">
                            </span>
                        </div>


                    </div>



                    <hr>


                    <h5 class="fw-bold">
                        About the Role
                    </h5>

                    <p id="viewDescription"></p>


                    <hr>


                    <h5 class="fw-bold">
                        Responsibilities
                    </h5>

                    <ul id="viewResponsibilities">
                    </ul>



                    <h5 class="fw-bold mt-4">
                        Qualifications
                    </h5>

                    <p id="viewQualification"></p>

                    <div class="row mt-4">

                        <div class="col-md-6">
                            <b>Salary:</b>
                            <span id="viewSalary"></span>
                        </div>


                        <div class="col-md-6">
                            <b>Application Deadline:</b>
                            <span id="viewDeadline"></span>
                        </div>

                    </div>



                    <div class="d-grid mt-4">

                        <a id="applyButton" class="btn btn-warning btn-lg rounded-pill">

                            Apply Now

                        </a>

                    </div>


                </div>


            </div>


        </div>

    </div>

</div>
<script>
    document.querySelectorAll(".viewJobBtn").forEach(function (btn) {

        btn.addEventListener("click", function () {

            // JOB TITLE

            document.getElementById("viewTitle").textContent =
                this.dataset.title;


            // BASIC INFORMATION

            document.getElementById("viewBranch").textContent =
                this.dataset.branch;

            document.getElementById("viewDepartment").textContent =
                this.dataset.department;

            document.getElementById("viewEmployment").textContent =
                this.dataset.employment;

            document.getElementById("viewVacancies").textContent =
                this.dataset.vacancies + " Vacancies";


            // DESCRIPTION

            document.getElementById("viewDescription").textContent =
                this.dataset.description;


            // RESPONSIBILITIES

            document.getElementById("viewResponsibilities").innerHTML =
                this.dataset.responsibilities.replace(/\n/g, "<br>");


            // QUALIFICATIONS

            document.getElementById("viewQualification").innerHTML =
                this.dataset.qualification.replace(/\n/g, "<br>");


            // SALARY

            document.getElementById("viewSalary").textContent =
                this.dataset.salary;


            // DEADLINE

            document.getElementById("viewDeadline").textContent =
                this.dataset.deadline;


            // APPLY BUTTON

            document.getElementById("applyButton").href =
                "../SARISMARTS/accounts/apply.php?page=apply&job_id=" +
                this.dataset.id;

        });

    });
</script>


<?php
include __DIR__ . "/footer.php";
?>