<!-- =====================================================
    ADD PLATFORM CARD MODAL
===================================================== -->

<div class="modal fade" id="addPlatformCard" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <form method="POST" data-confirm="Add this card to the platform page?"
                data-confirm-button="Add Card">

                <div class="modal-header">

                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add Platform Card
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Display Order</label>
                            <input type="number" class="form-control" name="card_order" value="1" min="1" required>
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Icon</label>
                            <input type="text" class="form-control" name="icon" value="bi bi-box-seam" required>
                            <div class="form-text"><code>bi bi-...</code> (Bootstrap Icons)</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Card Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" rows="4" name="description" required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" name="saveCard" class="btn sa-btn">
                        <i class="bi bi-check-circle me-2"></i>
                        Save Card
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =====================================================
    EDIT PLATFORM CARD MODAL
===================================================== -->

<div class="modal fade" id="editPlatformCard" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <form method="POST" data-confirm="Save changes to this card?" data-confirm-button="Save">

                <input type="hidden" id="edit_card_id" name="card_id">

                <div class="modal-header">

                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i>
                        Edit Platform Card
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- The update handler has always read card_order, but this
                             field was missing, so every edit blanked the order. -->
                        <div class="col-md-3">
                            <label class="form-label">Display Order</label>
                            <input type="number" class="form-control" id="edit_order" name="card_order" min="1" required>
                        </div>

                        <div class="col-md-9">
                            <label class="form-label">Icon</label>
                            <input type="text" class="form-control" id="edit_icon" name="icon" required>
                            <div class="form-text"><code>bi bi-...</code> (Bootstrap Icons)</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="edit_title" name="title" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" rows="4" id="edit_description" name="description" required></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn sa-btn-soft" data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" name="updateCard" class="btn sa-btn">
                        <i class="bi bi-save me-2"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =====================================================
    VIEW PLATFORM CARD MODAL
===================================================== -->

<div class="modal fade" id="viewPlatformCard" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-eye me-2"></i>
                    Platform Card Details
                </h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <div class="modal-body">

                <div class="text-center mb-4">

                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center"
                        style="width:80px;height:80px;background:#e0f2fe;color:#0284c7;">

                        <i id="view_icon" class="fs-2"></i>

                    </div>

                </div>

                <table class="table table-bordered">

                    <tr>
                        <th width="180">Display Order</th>
                        <td id="view_order"></td>
                    </tr>

                    <tr>
                        <th>Title</th>
                        <td id="view_title"></td>
                    </tr>

                    <tr>
                        <th>Description</th>
                        <td id="view_description"></td>
                    </tr>

                    <tr>
                        <th>Status</th>
                        <td id="view_status"></td>
                    </tr>

                </table>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Close
                </button>

            </div>

        </div>

    </div>

</div>
