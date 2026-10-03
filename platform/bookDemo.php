<?php include("header.php"); ?>

<section class="book-demo-hero">
    <div class="container">
        <span class="demo-badge">
            BOOK A DEMO
        </span>
        <h1 class="demo-title mt-4">
            Thirty minutes, your store type,<br>
            real workflows
        </h1>
        <p class="demo-description mt-4">
            No slide deck. We open the platform, load a branch that
            looks like yours and run a shift end to end.
        </p>
    </div>
</section>

<section class="py-5 bg-light">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-5">
                <h5 class="mb-4 fw-bold">
                    What we'll cover
                </h5>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    A live sale at the POS terminal, including a return
                </div>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    Stock movement, transfers and reorder triggers
                </div>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    Branch performance rollups for regional managers
                </div>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    Attendance capture feeding a payroll run
                </div>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    HR review and Admin approval on a payslip release
                </div>
                <div class="cover-card">
                    <i class="fa-solid fa-check text-success me-2"></i>
                    Pricing and a rollout plan for your branch count
                </div>
            </div>
            <div class="col-lg-7">
                <div class="request-card">
                    <h4 class="mb-4">
                        Request your session
                    </h4>
                    <form>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Full name
                                </label>
                                <input type="text" class="form-control" placeholder="Juan Dela Cruz">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Company
                                </label>
                                <input type="text" class="form-control" placeholder="QuickStop Mart">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Work email
                                </label>
                                <input type="email" class="form-control" placeholder="you@company.ph">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Mobile
                                </label>
                                <input type="text" class="form-control" placeholder="+63 917 000 0000">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Store type
                                </label>
                                <select class="form-select">
                                    <option selected disabled>
                                        Select Store Type
                                    </option>
                                    <option>
                                        Mini Mart Chain
                                    </option>
                                    <option>
                                        Grocery Store
                                    </option>
                                    <option>
                                        Convenience Store
                                    </option>
                                    <option>
                                        Pharmacy
                                    </option>
                                    <option>
                                        Hardware
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Preferred date
                                </label>
                                <input type="date" class="form-control">
                            </div>
                            <div class="col-12 mb-4">
                                <label class="form-label">
                                    Anything specific to show you?
                                </label>
                                <textarea rows="5" class="form-control"
                                    placeholder="We care most about payroll and inventory transfers."></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-demo-submit w-100">
                                    Book my demo
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
</section>

<?php include("footer.php"); ?>

<style>
    .book-demo-hero {
        background: linear-gradient(135deg, #06264B, #0A4B8C);
        color: #fff;
        padding: 100px 0 80px;
    }

    .demo-badge {
        display: inline-block;
        background: rgba(255, 255, 255, .08);
        border: 1px solid rgba(255, 255, 255, .20);
        padding: 10px 18px;
        border-radius: 30px;
        font-size: 13px;
        letter-spacing: 2px;
        font-weight: 600;
    }

    .demo-title {
        font-size: 64px;
        font-weight: 800;
        line-height: 1.1;
        max-width: 760px;
    }

    .demo-description {
        font-size: 20px;
        color: #dbe4ef;
        line-height: 1.8;
        max-width: 650px;
    }

    .cover-card {
        background: #fff;
        border-radius: 18px;
        padding: 18px 22px;
        margin-bottom: 18px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .08);
        display: flex;
        align-items: flex-start;
        gap: 12px;
        transition: .3s;
    }

    .cover-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 18px 40px rgba(0, 0, 0, .12);
    }

    .cover-card i {
        font-size: 18px;
        margin-top: 4px;
    }

    .request-card {
        background: #fff;
        border-radius: 25px;
        padding: 35px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, .10);
    }

    .request-card h4 {
        font-weight: 700;
        margin-bottom: 30px;
    }

    .form-label {
        font-weight: 600;
        color: #374151;
    }

    .form-control,
    .form-select {
        border-radius: 12px;
        padding: 12px 15px;
        min-height: 52px;
        border: 1px solid #d1d5db;
        box-shadow: none;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #2495EA;
        box-shadow: 0 0 0 .2rem rgba(36, 149, 234, .15);
    }

    textarea.form-control {
        min-height: 130px;
        resize: none;
    }

    .btn-demo-submit {
        background: #2495EA;
        color: #fff;
        border: none;
        border-radius: 14px;
        padding: 15px;
        font-size: 18px;
        font-weight: 600;
        transition: .3s;
    }

    .btn-demo-submit:hover {
        background: #0f7fd2;
        color: #fff;
    }

    @media(max-width:991px) {
        .demo-title {
            font-size: 46px;
        }

        .demo-description {
            font-size: 18px;
        }

        .request-card {
            margin-top: 30px;
        }
    }

    @media(max-width:576px) {
        .book-demo-hero {
            padding: 70px 0;
        }

        .demo-title {
            font-size: 34px;
        }

        .demo-description {
            font-size: 16px;
        }

        .request-card {
            padding: 20px;
        }

        .cover-card {
            font-size: 14px;
        }
    }
</style>