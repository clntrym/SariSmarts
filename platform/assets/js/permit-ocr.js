/*
 * Reads a DTI or BIR certificate and fills the form from it.
 *
 * The owner photographs or scans the certificate; this pulls the numbers and
 * dates off it so nobody retypes what is already printed. It runs entirely
 * in the browser through tesseract.js, so the document is never uploaded
 * anywhere to be read - it goes to our server once, as the attachment, and
 * nowhere else.
 *
 * The fields it fills stay visible and editable, on purpose. OCR on a phone
 * photo gets a digit wrong often enough that locking the values in would
 * make a bad scan unfixable, and a wrong TIN saved silently is worse than
 * one the owner can see and correct. The reading is a first draft; the
 * person confirms it.
 *
 * PDFs are not read: rasterising one in the browser needs a second library
 * and a lot of memory on a phone. A PDF attaches fine, the fields just stay
 * empty for the owner to fill.
 */

(function () {
    "use strict";

    var WORKER = "https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js";
    var CORE = "https://cdn.jsdelivr.net/npm/tesseract.js-core@5";
    var LANG = "https://tessdata.projectnaptha.com/4.0.0";

    var MONTHS = {
        jan: "01", feb: "02", mar: "03", apr: "04", may: "05", jun: "06",
        jul: "07", aug: "08", sep: "09", oct: "10", nov: "11", dec: "12"
    };

    /* "18 May 2023" and "September 11, 2023" both appear on these forms. */
    function toIsoDate(text) {
        if (!text) return "";

        var m = text.match(/(\d{1,2})\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})/);

        if (m) {
            var mon = MONTHS[m[2].slice(0, 3).toLowerCase()];
            if (mon) return m[3] + "-" + mon + "-" + ("0" + m[1]).slice(-2);
        }

        m = text.match(/([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(\d{4})/);

        if (m) {
            var mon2 = MONTHS[m[1].slice(0, 3).toLowerCase()];
            if (mon2) return m[3] + "-" + mon2 + "-" + ("0" + m[2]).slice(-2);
        }

        return "";
    }

    /*
     * Returns what happened, not just whether it wrote.
     *
     *   "filled"  -- the field was empty and now holds the value
     *   "already" -- the certificate gave a value and the field already had
     *                one, so it was left alone
     *   false     -- nothing was read for this field
     *
     * The three used to collapse into true/false, and the caller reported
     * "Nothing could be read from that image" whenever nothing was written.
     * After a failed submit every field comes back filled from the POST, so
     * re-choosing the same file read the certificate perfectly and then
     * announced that it had not. The owner was told their scan was unreadable
     * by the code that had just read it.
     */
    function setIfEmpty(el, value) {
        if (!el || !value) return false;
        if (el.value && el.value.trim() !== "") return "already";

        el.value = value;
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
        return "filled";
    }

    /* ---- DTI Certificate of Business Name Registration -------------------
       The number is printed as "Business Name No. 4955922". The dates read
       "valid from 18 May 2023 to 18 May 2028"; only the first is taken,
       because the expiry is worked out from it rather than trusted. */
    function readDti(text) {
        var found = {};

        var number = text.match(/Business\s*Name\s*No\.?\s*[:#]?\s*(\d{5,10})/i);
        if (number) found.number = number[1];

        var from = text.match(/valid\s+from\s+(\d{1,2}\s+[A-Za-z]{3,9}\.?\s+\d{4})/i);
        if (from) found.date = toIsoDate(from[1]);

        if (!found.date) {
            var issued = text.match(/issue\s+the\s+same\s+on\s+(\d{1,2}\s+[A-Za-z]{3,9}\.?\s+\d{4})/i);
            if (issued) found.date = toIsoDate(issued[1]);
        }

        return found;
    }

    /* ---- BIR Form 2303 Certificate of Registration ----------------------- */
    function readBir(text) {
        var found = {};

        var tin = text.match(/(\d{3}\s*-\s*\d{3}\s*-\s*\d{3}\s*-\s*\d{3,5})/);
        if (!tin) tin = text.match(/(\d{3}\s*-\s*\d{3}\s*-\s*\d{3})/);
        if (tin) found.tin = tin[1].replace(/\s/g, "");

        var ocn = text.match(/OCN\s*[:#]?\s*([A-Za-z0-9]{8,40})/i);
        if (ocn) found.ocn = ocn[1];

        var rdo = text.match(/DISTRICT\s+OFFICE\s+NO\.?\s*(\d{1,3})/i);
        if (rdo) found.rdo = rdo[1];

        /* "REGISTRATION DATE" sits above the date in the business details
           block; the TIN issuance date elsewhere must not be mistaken for
           it, so the search starts after that label. */
        var at = text.search(/REGISTRATION\s+DATE/i);

        if (at !== -1) {
            found.date = toIsoDate(text.slice(at, at + 160));
        }

        return found;
    }

    function note(box, message, tone) {
        box.className = "permit-ocr-note " + (tone || "");
        box.textContent = message;
        box.hidden = false;
    }

    function fillFrom(group, text) {
        var kind = group.dataset.permitOcr;
        var found = kind === "dti" ? readDti(text) : readBir(text);
        var box = group.querySelector("[data-ocr-note]");
        var filled = [];
        var alreadyFilled = [];

        function field(name) {
            return group.querySelector('[name="' + name + '"]');
        }

        function take(name, value, label) {
            var outcome = setIfEmpty(field(name), value);

            if (outcome === "filled") {
                filled.push(label);
            } else if (outcome === "already") {
                alreadyFilled.push(label);
            }
        }

        if (kind === "dti") {
            take("dti_registration_number", found.number, "Business Name No.");
            take("dti_registration_date", found.date, "registration date");
        } else {
            take("bir_tin", found.tin, "TIN");
            take("bir_registration_date", found.date, "registration date");
            take("bir_rdo_code", found.rdo, "RDO code");
            take("bir_ocn", found.ocn, "OCN");
        }

        if (filled.length) {
            note(box, "Read from your file: " + filled.join(", ")
                + ". Please check each one against the certificate before you continue.", "ok");
            return;
        }

        /*
         * The certificate was read; the fields simply already had answers --
         * which is what happens on the second attempt after a failed submit,
         * because the form comes back filled from the POST. Saying "nothing
         * could be read" here tells the owner their scan is bad when it is
         * not, and sends them looking for a better photograph of a perfectly
         * good certificate.
         */
        if (alreadyFilled.length) {
            note(box, "Read from your file: " + alreadyFilled.join(", ")
                + ". These already match what is typed below, so nothing was changed.", "ok");
            return;
        }

        note(box, "Nothing could be read from that image. Please type the details in yourself.", "warn");
    }

    function run(group, file) {
        var box = group.querySelector("[data-ocr-note]");

        if (!box) return;

        if (!/^image\//.test(file.type)) {
            note(box, "The file is attached. A PDF cannot be read automatically, so please type the details in.", "");
            return;
        }

        if (typeof Tesseract === "undefined") {
            note(box, "The file is attached. Reading it automatically is unavailable, so please type the details in.", "");
            return;
        }

        note(box, "Reading your certificate...", "");

        Tesseract.recognize(file, "eng", {
            workerPath: WORKER,
            corePath: CORE,
            langPath: LANG
        })
            .then(function (result) {
                fillFrom(group, (result && result.data && result.data.text) || "");
            })
            .catch(function () {
                note(box, "That image could not be read. Please type the details in yourself.", "warn");
            });
    }

    /*
     * The DTI expiry, shown as the owner types.
     *
     * A preview of what the server will store, not an input. The expiry is
     * five years from the registration date by law, so there is only one
     * right answer; a field for it could only ever disagree with the date
     * above it.
     */
    function wireExpiryPreview() {

        var field = document.querySelector("[data-dti-registration]");
        var box = document.querySelector("[data-dti-expiry-box]");
        var out = document.querySelector("[data-dti-expiry]");

        if (!field || !box || !out) return;

        function show() {
            var value = field.value;

            if (!value) {
                box.hidden = true;
                return;
            }

            var parts = value.split("-");
            var d = new Date(Date.UTC(+parts[0] + 5, +parts[1] - 1, +parts[2]));

            /* 29 February rolls into March five years on. Pull it back, the
               same way the server does: early is safe, late is not. */
            if (d.getUTCDate() !== +parts[2]) {
                d.setUTCDate(0);
            }

            out.textContent = d.toLocaleDateString(undefined, {
                year: "numeric", month: "long", day: "numeric", timeZone: "UTC"
            });

            box.hidden = false;
        }

        field.addEventListener("input", show);
        field.addEventListener("change", show);
        show();
    }

    document.addEventListener("DOMContentLoaded", function () {

        wireExpiryPreview();

        var groups = document.querySelectorAll("[data-permit-ocr]");

        if (!groups.length) return;

        groups.forEach(function (group) {

            var input = group.querySelector('input[type="file"]');

            if (!input) return;

            input.addEventListener("change", function () {
                if (input.files && input.files[0]) {
                    run(group, input.files[0]);
                }
            });
        });
    });
}());
