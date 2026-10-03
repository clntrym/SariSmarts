<!-- =====================================================
        ADD SUBSCRIPTION PLAN MODAL
====================================================== -->

<div class="modal fade" id="addPlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form action="" method="POST" data-confirm="Add this subscription plan?"
                data-confirm-text="It appears on the public pricing page as soon as it is Active."
                data-confirm-button="Add Plan">
                <div class="modal-header">
                    <div>
                        <h4 class="fw-bold mb-1">
                            Add Subscription Plan
                        </h4>
                        <small class="text-muted">
                            Create a new subscription package for companies.
                        </small>
                    </div>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Plan Name</label>
                                <input type="text" name="plan_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" rows="4" class="form-control" required></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Monthly Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text">&#8369;</span>
                                        <input type="number" step="0.01" min="0" name="monthly_price" class="form-control"
                                            required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Yearly Price
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">&#8369;</span>
                                        <input type="number" step="0.01" min="0" name="yearly_price" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Maximum Branches</label>
                                    <input type="number" min="0" name="max_branches" class="form-control" value="1" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Maximum Users</label>
                                    <input type="number" min="0" name="max_users" class="form-control" value="10" required>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Trial Days</label>
                                    <input type="number" min="0" name="trial_days" class="form-control" value="14" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Badge</label>
                                    <select name="badge" class="form-select">
                                        <option>None</option>
                                        <option>Most Popular</option>
                                        <option>Recommended</option>
                                        <option>Best Value</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label fw-semibold">Status</label>
                                <select name="status" class="form-select">
                                    <option>Active</option>
                                    <option>Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label fw-semibold">Included Features</label>
                            <div class="border rounded-3 p-3" style="max-height:520px;overflow:auto;">
                                <?php
                                $features = [
                                    "Point of Sale",
                                    "Inventory",
                                    "Products",
                                    "Purchasing",
                                    "Suppliers",
                                    "Expenses",
                                    "Dashboard",
                                    "Reports",
                                    "Analytics",
                                    "HR Management",
                                    "Payroll",
                                    "Attendance",
                                    "Recruitment",
                                    "Marketplace",
                                    "Customer Loyalty",
                                    "Promotions",
                                    "API Access",
                                    "White Label",
                                    "Priority Support",
                                    "Dedicated Account Manager",
                                    "Custom Integrations",
                                    "Unlimited Storage",
                                    "SMS Notification"
                                ];
                                foreach ($features as $feature) {
                                    ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="features[]"
                                            value="<?= $feature; ?>">
                                        <label class="form-check-label"> <?= $feature; ?></label>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="savePlan" class="btn btn-success">
                        <i class="bi bi-check-circle me-2"></i>
                        Save Subscription Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
        EDIT SUBSCRIPTION PLAN MODAL
========================================== -->

<div class="modal fade" id="editPlanModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <form action="" method="POST" data-confirm="Save changes to this plan?"
                data-confirm-text="Existing subscribers keep the amount they were billed; the pricing page shows the new figures."
                data-confirm-button="Save">

                <input type="hidden" name="plan_id" id="edit_plan_id">

                <div class="modal-header">

                    <div>

                        <h4 class="fw-bold mb-1">
                            Edit Subscription Plan
                        </h4>

                        <small class="text-muted">
                            Update subscription plan information.
                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="row g-4">

                        <!-- LEFT SIDE -->

                        <div class="col-lg-6">

                            <div class="mb-3">

                                <label class="form-label fw-semibold">

                                    Plan Name

                                </label>

                                <input type="text" class="form-control" name="plan_name" id="edit_plan_name" required>

                            </div>

                            <div class="mb-3">

                                <label class="form-label fw-semibold">

                                    Description

                                </label>

                                <textarea class="form-control" rows="4" name="description"
                                    id="edit_description" required></textarea>

                            </div>

                            <div class="row">

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Monthly Price

                                    </label>

                                    <div class="input-group">

                                        <span class="input-group-text">

                                            &#8369;

                                        </span>

                                        <input type="number" step="0.01" min="0" class="form-control" name="monthly_price"
                                            id="edit_monthly_price" required>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Yearly Price

                                    </label>

                                    <div class="input-group">

                                        <span class="input-group-text">

                                            &#8369;

                                        </span>

                                        <input type="number" step="0.01" min="0" class="form-control" name="yearly_price"
                                            id="edit_yearly_price" required>

                                    </div>

                                </div>

                            </div>

                            <div class="row mt-3">

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Maximum Branches

                                    </label>

                                    <input type="number" min="0" class="form-control" name="max_branches"
                                        id="edit_max_branches" required>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Maximum Users

                                    </label>

                                    <input type="number" min="0" class="form-control" name="max_users" id="edit_max_users" required>

                                </div>

                            </div>

                            <div class="row mt-3">

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Trial Days

                                    </label>

                                    <input type="number" min="0" class="form-control" name="trial_days" id="edit_trial_days" required>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">

                                        Badge

                                    </label>

                                    <select class="form-select" name="badge" id="edit_badge">

                                        <option value="None">None</option>

                                        <option value="Most Popular">
                                            Most Popular
                                        </option>

                                        <option value="Recommended">
                                            Recommended
                                        </option>

                                        <option value="Best Value">
                                            Best Value
                                        </option>

                                    </select>

                                </div>

                            </div>

                            <div class="mt-3">

                                <label class="form-label fw-semibold">

                                    Status

                                </label>

                                <select class="form-select" name="status" id="edit_status">

                                    <option value="Active">
                                        Active
                                    </option>

                                    <option value="Inactive">
                                        Inactive
                                    </option>

                                </select>

                            </div>

                        </div>

                        <!-- RIGHT SIDE -->

                        <div class="col-lg-6">

                            <label class="form-label fw-semibold">

                                Included Features

                            </label>

                            <div class="border rounded-3 p-3" style="max-height:520px;overflow:auto;">

                                <div id="editFeatureList">

                                    <!-- Loaded by AJAX -->

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="updatePlan" class="btn btn-warning">

                        <i class="bi bi-check-circle me-2"></i>

                        Update Plan

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
        VIEW SUBSCRIPTION PLAN MODAL
====================================================== -->

<div class="modal fade" id="viewPlanModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h4 class="fw-bold mb-1">

                        Subscription Plan Details

                    </h4>

                    <small class="text-muted">

                        View complete subscription information.

                    </small>

                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="row">

                    <!-- LEFT -->

                    <div class="col-lg-6">

                        <table class="table table-borderless">

                            <tr>

                                <th width="180">
                                    Plan Name
                                </th>

                                <td id="view_plan_name"></td>

                            </tr>

                            <tr>

                                <th>
                                    Description
                                </th>

                                <td id="view_description"></td>

                            </tr>

                            <tr>

                                <th>
                                    Monthly Price
                                </th>

                                <td>

                                    &#8369;
                                    <span id="view_monthly_price"></span>

                                </td>

                            </tr>

                            <tr>

                                <th>
                                    Yearly Price
                                </th>

                                <td>

                                    &#8369;
                                    <span id="view_yearly_price"></span>

                                </td>

                            </tr>

                            <tr>

                                <th>
                                    Maximum Branches
                                </th>

                                <td id="view_max_branches"></td>

                            </tr>

                            <tr>

                                <th>
                                    Maximum Users
                                </th>

                                <td id="view_max_users"></td>

                            </tr>

                            <tr>

                                <th>
                                    Trial Days
                                </th>

                                <td id="view_trial_days"></td>

                            </tr>

                            <tr>

                                <th>
                                    Badge
                                </th>

                                <td>

                                    <span id="view_badge" class="badge bg-warning text-dark">
                                    </span>

                                </td>

                            </tr>

                            <tr>

                                <th>
                                    Status
                                </th>

                                <td>

                                    <span id="view_status" class="badge bg-success">
                                    </span>

                                </td>

                            </tr>

                        </table>

                    </div>

                    <!-- RIGHT -->

                    <div class="col-lg-6">

                        <div class="card border-0 bg-light">

                            <div class="card-header bg-white">

                                <strong>

                                    Included Features

                                </strong>

                            </div>

                            <div class="card-body" style="max-height:420px;overflow:auto;">

                                <div id="viewFeatureList">

                                    <!-- Loaded by AJAX -->

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                    Close

                </button>

            </div>

        </div>

    </div>

</div>

<!-- =====================================================
        ASSIGN SUBSCRIPTION MODAL
====================================================== -->

<div class="modal fade" id="assignSubscriptionModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <form method="POST" data-confirm="Assign this subscription?"
                data-confirm-text="The company is billed from the start date you chose."
                data-confirm-button="Assign">

                <div class="modal-header">

                    <div>

                        <h4 class="fw-bold mb-1">

                            Assign Subscription

                        </h4>

                        <small class="text-muted">

                            Assign a subscription plan to a company.

                        </small>

                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- COMPANY -->

                        <div class="col-md-12">

                            <label class="form-label fw-semibold">

                                Company

                            </label>

                            <select class="form-select" name="company_id" required>

                                <option value="">
                                    Select Company
                                </option>

                                <?php

                                $company = mysqli_query($conn, "
                                    SELECT *
                                    FROM company
                                    -- 'Approved' is the state a company sits in right
                                    -- after review, which is exactly when it needs a
                                    -- subscription. Listing only 'Active' made newly
                                    -- approved businesses impossible to subscribe.
                                    WHERE status IN ('Approved','Active')
                                    ORDER BY FIELD(status,'Approved','Active'), company_name ASC
                                ");

                                while ($row = mysqli_fetch_assoc($company)) {

                                    ?>

                                    <option value="<?= $row['company_id']; ?>">

                                        <?= $row['company_name']; ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>

                        <!-- PLAN -->

                        <div class="col-md-12">

                            <label class="form-label fw-semibold">

                                Subscription Plan

                            </label>

                            <select class="form-select" name="plan_id" id="subscription_plan" required>

                                <option value="">
                                    Select Plan
                                </option>

                                <?php

                                $plans = mysqli_query($conn, "
                                    SELECT *
                                    FROM subscription_plans
                                    WHERE status='Active'
                                    ORDER BY monthly_price ASC
                                ");

                                while ($plan = mysqli_fetch_assoc($plans)) {

                                    ?>

                                    <option value="<?= $plan['plan_id']; ?>" data-monthly="<?= $plan['monthly_price']; ?>"
                                        data-yearly="<?= $plan['yearly_price']; ?>"
                                        data-trial="<?= $plan['trial_days']; ?>">

                                        <?= $plan['plan_name']; ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>

                        <!-- BILLING -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Billing Cycle

                            </label>

                            <select class="form-select" id="billing_cycle" name="billing_cycle">

                                <option value="Monthly">

                                    Monthly

                                </option>

                                <option value="Yearly">

                                    Yearly

                                </option>

                            </select>

                        </div>

                        <!-- STATUS -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Status

                            </label>

                            <select class="form-select" name="status">

                                <option value="Trial">

                                    Trial

                                </option>

                                <option value="Active">

                                    Active

                                </option>

                            </select>

                        </div>

                        <!-- START DATE -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Start Date

                            </label>

                            <input type="date" class="form-control" id="start_date" name="start_date"
                                value="<?= date('Y-m-d'); ?>" required>

                        </div>

                        <!-- EXPIRY -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Expiry Date

                            </label>

                            <input type="date" class="form-control" id="expiry_date" name="expiry_date" readonly>

                        </div>

                        <!-- PAYMENT -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Payment Status

                            </label>

                            <select class="form-select" name="payment_status">

                                <option value="Pending">

                                    Pending

                                </option>

                                <option value="Paid">

                                    Paid

                                </option>

                            </select>

                        </div>

                        <!-- AMOUNT -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">

                                Amount

                            </label>

                            <div class="input-group">

                                <span class="input-group-text">

                                    &#8369;

                                </span>

                                <input type="text" class="form-control" id="subscription_amount" name="amount" readonly>

                            </div>

                        </div>

                        <!-- NOTES -->

                        <div class="col-12">

                            <label class="form-label fw-semibold">

                                Notes

                            </label>

                            <textarea class="form-control" rows="3" name="notes"
                                placeholder="Optional remarks..."></textarea>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="assignSubscription" class="btn btn-primary">

                        <i class="bi bi-check-circle me-2"></i>

                        Assign Subscription

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ==========================================
EDIT COMPANY SUBSCRIPTION MODAL
========================================== -->

<div class="modal fade" id="editSubscriptionModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <form method="POST" data-confirm="Save changes to this subscription?"
                data-confirm-text="Changing the plan, cycle or start date recalculates the amount and expiry."
                data-confirm-button="Save">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-pencil-square me-2"></i>

                        Edit Company Subscription

                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden" name="subscription_id" id="edit_subscription_id">

                    <div class="row g-3">

                        <!-- Company -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Company

                            </label>

                            <select class="form-select" name="company_id" id="edit_company_id" required>

                                <?php

                                $company = mysqli_query($conn, "
                                    SELECT *
                                    FROM company
                                    ORDER BY company_name ASC
                                ");

                                while ($c = mysqli_fetch_assoc($company)) {

                                    ?>

                                    <option value="<?= $c['company_id']; ?>">

                                        <?= $c['company_name']; ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>

                        <!-- Plan -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Subscription Plan

                            </label>

                            <select class="form-select" name="plan_id" id="edit_sub_plan_id" required>

                                <?php

                                $plan = mysqli_query($conn, "
                                    SELECT *
                                    FROM subscription_plans
                                    WHERE status='Active'
                                    ORDER BY monthly_price
                                ");

                                while ($p = mysqli_fetch_assoc($plan)) {

                                    ?>

                                    <option value="<?= $p['plan_id']; ?>">

                                        <?= $p['plan_name']; ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>

                        <!-- Billing -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Billing Cycle

                            </label>

                            <select class="form-select" name="billing_cycle" id="edit_billing_cycle">

                                <option value="Monthly">

                                    Monthly

                                </option>

                                <option value="Yearly">

                                    Yearly

                                </option>

                            </select>

                        </div>

                        <!-- Start Date -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Start Date

                            </label>

                            <input type="date" class="form-control" name="start_date" id="edit_start_date" required>

                        </div>

                        <!-- Payment -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Payment Status

                            </label>

                            <select class="form-select" name="payment_status" id="edit_payment_status">

                                <option value="Pending">

                                    Pending

                                </option>

                                <option value="Paid">

                                    Paid

                                </option>

                                <option value="Failed">

                                    Failed

                                </option>

                            </select>

                        </div>

                        <!-- Status -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Subscription Status

                            </label>

                            <select class="form-select" name="status" id="edit_sub_status">

                                <option value="Trial">

                                    Trial

                                </option>

                                <option value="Active">

                                    Active

                                </option>

                                <option value="Expired">

                                    Expired

                                </option>

                                <option value="Cancelled">

                                    Cancelled

                                </option>

                            </select>

                        </div>

                        <!-- Notes -->

                        <div class="col-12">

                            <label class="form-label">

                                Notes

                            </label>

                            <textarea class="form-control" rows="4" name="notes" id="edit_notes"></textarea>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="updateSubscription" class="btn btn-primary">

                        <i class="bi bi-save me-2"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
     CHANGE SUBSCRIPTION PLAN MODAL
====================================================== -->

<div class="modal fade" id="changePlanModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow rounded-4">

            <form method="POST" data-confirm="Move this company to the new plan?"
                data-confirm-text="The subscription amount changes to the new plan's price straight away."
                data-confirm-button="Change Plan">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-arrow-up-circle me-2"></i>

                        Upgrade / Downgrade Subscription

                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden" name="subscription_id" id="change_subscription_id">

                    <div class="row g-3">

                        <!-- Company -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Company

                            </label>

                            <input type="text" class="form-control" id="change_company_name" readonly>

                        </div>

                        <!-- Billing -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Billing Cycle

                            </label>

                            <input type="text" class="form-control" id="change_billing_cycle" readonly>

                        </div>

                        <!-- Current Plan -->

                        <div class="col-md-6">

                            <label class="form-label">

                                Current Plan

                            </label>

                            <input type="text" class="form-control" id="current_plan" readonly>

                        </div>

                        <!-- New Plan -->

                        <div class="col-md-6">

                            <label class="form-label">

                                New Subscription Plan

                            </label>

                            <select class="form-select" name="plan_id" id="change_plan_id" required>

                                <?php

                                $plans = mysqli_query($conn, "

                                    SELECT *

                                    FROM subscription_plans

                                    WHERE status='Active'

                                    ORDER BY monthly_price ASC

                                ");

                                while ($plan = mysqli_fetch_assoc($plans)) {

                                    ?>

                                    <option value="<?= $plan['plan_id']; ?>">

                                        <?= $plan['plan_name']; ?>

                                    </option>

                                <?php } ?>

                            </select>

                            <hr class="my-4">

                            <h6 class="fw-bold mb-3">
                                Plan Comparison
                            </h6>

                            <div class="row">

                                <!-- Current -->

                                <div class="col-md-6">

                                    <div class="sa-panel border">

                                        <div class="card-header bg-light">

                                            <strong>Current Plan</strong>

                                        </div>

                                        <div class="card-body">

                                            <p><strong>Price:</strong> <span id="current_price">-</span></p>

                                            <p><strong>Branches:</strong> <span id="current_branches">-</span></p>

                                            <p><strong>Users:</strong> <span id="current_users">-</span></p>

                                            <p><strong>Trial:</strong> <span id="current_trial">-</span></p>

                                            <div id="current_features"></div>

                                        </div>

                                    </div>

                                </div>

                                <!-- New -->

                                <div class="col-md-6">

                                    <div class="sa-panel border border-primary">

                                        <div class="card-header bg-primary text-white">

                                            <strong>Selected Plan</strong>

                                        </div>

                                        <div class="card-body">

                                            <p><strong>Price:</strong> <span id="new_price">-</span></p>

                                            <p><strong>Branches:</strong> <span id="new_branches">-</span></p>

                                            <p><strong>Users:</strong> <span id="new_users">-</span></p>

                                            <p><strong>Trial:</strong> <span id="new_trial">-</span></p>

                                            <div id="new_features"></div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit" name="changePlan" class="btn btn-success">

                        <i class="bi bi-arrow-repeat me-2"></i>

                        Save Plan

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
     VIEW SUBSCRIPTION MODAL
====================================================== -->

<div class="modal fade" id="viewSubscriptionModal" tabindex="-1">

    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow rounded-4">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">

                    <i class="bi bi-eye me-2"></i>

                    Subscription Details

                </h5>

                <button class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body">

                <div class="row g-4">

                    <!-- LEFT SIDE -->

                    <div class="col-lg-4">

                        <div class="sa-panel">

                            <div class="card-body text-center">

                                <i class="bi bi-buildings display-3 text-primary"></i>

                                <h4 class="mt-3 fw-bold" id="view_company_name">
                                    -
                                </h4>

                                <div id="view_company_status"></div>

                                <hr>

                                <div class="text-start">

                                    <p>

                                        <strong>Email</strong><br>

                                        <span id="view_company_email">-</span>

                                    </p>

                                    <p>

                                        <strong>Phone</strong><br>

                                        <span id="view_company_phone">-</span>

                                    </p>

                                    <p>

                                        <strong>Address</strong><br>

                                        <span id="view_company_address">-</span>

                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- RIGHT SIDE -->

                    <div class="col-lg-8">

                        <!-- Subscription -->

                        <div class="sa-panel mb-4">

                            <div class="card-header bg-light">

                                <strong>Subscription Information</strong>

                            </div>

                            <div class="card-body">

                                <div class="row">

                                    <div class="col-md-6">

                                        <p>

                                            <strong>Plan</strong><br>

                                            <span id="view_plan"></span>

                                        </p>

                                        <p>

                                            <strong>Billing</strong><br>

                                            <span id="view_billing"></span>

                                        </p>

                                        <p>

                                            <strong>Amount</strong><br>

                                            <span id="view_amount"></span>

                                        </p>

                                    </div>

                                    <div class="col-md-6">

                                        <p>

                                            <strong>Start Date</strong><br>

                                            <span id="view_start"></span>

                                        </p>

                                        <p>

                                            <strong>Expiry Date</strong><br>

                                            <span id="view_expiry"></span>

                                        </p>

                                        <p>

                                            <strong>Status</strong><br>

                                            <span id="view_sub_status"></span>

                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>

                        <!-- Features -->

                        <div class="sa-panel mb-4">

                            <div class="card-header bg-light">

                                <strong>Plan Features</strong>

                            </div>

                            <div class="card-body">

                                <div id="view_features">

                                </div>

                            </div>

                        </div>

                        <!-- History -->

                        <div class="sa-panel">

                            <div class="card-header bg-light">

                                <strong>Subscription History</strong>

                            </div>

                            <div class="card-body">

                                <div id="view_history">

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>