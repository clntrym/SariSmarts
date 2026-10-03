/*
 * Super Admin form guard.
 *
 * Loaded once by sAdminHeader.php, so every Super Admin screen gets the
 * same behaviour without wiring its own handlers.
 *
 *
 * VALIDATION
 *
 * Browsers already refuse to submit a form with an empty required field,
 * but each one shows its own bubble, it points at whichever field the
 * browser found first, and it vanishes on the next click. Forms are
 * marked novalidate here and checked on submit instead, so the offending
 * fields stay outlined, the first one is focused, and the message looks
 * like the rest of the panel.
 *
 * novalidate is set from JavaScript rather than in the markup: if this
 * file fails to load, the forms simply fall back to native validation
 * instead of losing it.
 *
 *
 * CONFIRMATION
 *
 * A form carrying data-confirm asks before it submits:
 *
 *   data-confirm          the question
 *   data-confirm-text     optional supporting line
 *   data-confirm-button   optional label for the confirm button
 *   data-confirm-danger   present for a red confirm button
 *
 * data-sa-skip opts a form out of both behaviours.
 *
 * Re-submitting has to carry the button that was pressed. form.submit()
 * drops it, which is why every delete keyed on <button name="deleteX">
 * did nothing at all once it had been confirmed: PHP never saw the key.
 * requestSubmit(submitter) keeps it, and the old-browser fallback below
 * carries it in a hidden field.
 */

(function () {
    "use strict";

    var CONFIRMED = "saGuardConfirmed";
    var BRAND = "#00224c";
    var DANGER = "#dc3545";

    function firstInvalidField(form) {
        var fields = form.querySelectorAll("input:invalid, select:invalid, textarea:invalid");
        return fields.length ? fields[0] : null;
    }

    function labelFor(field) {
        if (field.id) {
            var tied = field.form ? field.form.querySelector('label[for="' + field.id + '"]') : null;
            if (tied) {
                return tied.textContent.replace(/\*/g, "").trim();
            }
        }

        var group = field.closest(".mb-3, .mb-0, .col-12, .col-md-3, .col-md-4, .col-md-6, .col-md-8, .col-md-9, .col-lg-6");
        var label = group ? group.querySelector(".form-label") : null;

        return label ? label.textContent.replace(/\*/g, "").trim() : "";
    }

    function reportInvalid(form) {

        form.classList.add("was-validated");

        var field = firstInvalidField(form);

        if (field) {
            /* Focusing a field inside a modal that is not open scrolls the
               page to nowhere, so only do it when it can be seen. */
            var modal = field.closest(".modal");

            if (!modal || modal.classList.contains("show")) {
                try {
                    field.focus();
                } catch (ignored) {
                    /* a hidden or disabled field cannot take focus */
                }
            }
        }

        var name = field ? labelFor(field) : "";

        Swal.fire({
            icon: "error",
            title: "Missing Details",
            text: name
                ? name + " needs to be filled in before this can be saved."
                : "Please complete the highlighted fields.",
            confirmButtonColor: BRAND
        });
    }

    function confirmOptions(form) {

        var options = {
            icon: "question",
            title: form.dataset.confirm,
            showCancelButton: true,
            confirmButtonText: form.dataset.confirmButton || "Confirm",
            cancelButtonText: "Cancel",
            confirmButtonColor: BRAND,
            reverseButtons: true
        };

        if (form.dataset.confirmText) {
            options.text = form.dataset.confirmText;
        }

        if (form.dataset.confirmDanger !== undefined) {
            options.icon = "warning";
            options.confirmButtonColor = DANGER;
        }

        return options;
    }

    function resubmit(form, submitter) {

        form[CONFIRMED] = true;

        if (typeof form.requestSubmit === "function") {
            form.requestSubmit(submitter || null);
            return;
        }

        if (submitter && submitter.name) {
            var carry = document.createElement("input");
            carry.type = "hidden";
            carry.name = submitter.name;
            carry.value = submitter.value || "1";
            form.appendChild(carry);
        }

        form.submit();
    }

    document.addEventListener("submit", function (e) {

        var form = e.target;

        if (!form || form.nodeName !== "FORM") {
            return;
        }

        if (form.dataset.saSkip !== undefined) {
            return;
        }

        /* Second pass, after the operator confirmed. */
        if (form[CONFIRMED]) {
            delete form[CONFIRMED];
            return;
        }

        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            reportInvalid(form);
            return;
        }

        form.classList.remove("was-validated");

        if (!form.dataset.confirm) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        var submitter = e.submitter ||
            form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');

        Swal.fire(confirmOptions(form)).then(function (result) {
            if (result.isConfirmed) {
                resubmit(form, submitter);
            }
        });

    }, true);

    document.addEventListener("DOMContentLoaded", function () {

        document.querySelectorAll("form").forEach(function (form) {
            if (form.dataset.saSkip === undefined) {
                form.setAttribute("novalidate", "");
            }
        });

    });

    /* A modal closed mid-edit should not reopen still covered in red. */
    document.addEventListener("hidden.bs.modal", function (e) {

        e.target.querySelectorAll("form.was-validated").forEach(function (form) {
            form.classList.remove("was-validated");
        });

    });

}());
