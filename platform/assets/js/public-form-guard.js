/*
|--------------------------------------------------------------------------
| THE PUBLIC FORM GUARD
|--------------------------------------------------------------------------
|
| Two public forms send a business application to be read by a person:
| register.php and resubmit.php. Both want the same two things, so they
| share this file rather than each keeping its own copy that drifts.
|
|   1. Check every field against the same rule the server will apply, so
|      somebody hears about a stray character before uploading two
|      certificates and losing the whole form to a reload.
|
|   2. Ask once, plainly, before sending. An application is read by a human
|      and cannot be taken back by the person who sent it, so it should not
|      leave on a mis-click.
|
| Both forms carry novalidate, which means none of their required or
| pattern attributes are enforced by the browser. This applies them.
|
| The server stays the authority. Everything here is a courtesy that a
| determined browser can skip, and the server refuses the same input again.
|
| OPTING IN
|
| Put data-guard on the form, and optionally:
|
|   data-confirm-title   heading of the dialog
|   data-confirm-text    body; {email} is replaced with the email the form
|                        carries, or with a plain phrase when it has none
|   data-confirm-ok      label on the confirming button
|
| WHAT IS NOT CHECKED
|
| Email and password, deliberately. An email is left to type="email" and to
| the server's own address check; a password is never held to a character
| set, because restricting one only makes it weaker.
|
| THE RULE ITSELF
|
| It rides in the pattern attribute, or in data-pattern where the field is
| a textarea and has no pattern attribute of its own. The strings come from
| includes/field_rules.php and includes/name_parts.php, so this check and
| the server's are the same rule written once.
|
*/

(function () {
    "use strict";

    /*
    | The patterns use \p{L} - letters in any script, because Las Pinas and
    | Pena are spelled with an n-tilde - and that needs the u flag. A browser
    | too old for property escapes gets no character check rather than a
    | broken one; the server still has the last word.
    */
    function matches(rule, value) {
        try {
            return new RegExp("^(?:" + rule + ")$", "u").test(value);
        } catch (error) {
            return true;
        }
    }

    function ruleOf(field) {
        return field.getAttribute("pattern") || field.getAttribute("data-pattern");
    }

    function labelOf(field) {
        var label = field.parentNode.querySelector("label");

        if (!label) {
            return "This field";
        }

        // Drop the asterisk that marks a field as required.
        return label.textContent.replace("*", "").trim();
    }

    /*
    | Marks the field and writes the reason into the invalid-feedback div
    | beside it, which is how every form on the site shows a refusal.
    |
    | Returns false when there is no such div to write into - a plan picker
    | made of cards, for one. The caller then has to say it some other way,
    | because a field marked red with no words next to it tells somebody that
    | something is wrong and not what.
    */
    function reject(field, message) {
        field.classList.add("is-invalid");

        var feedback = field.parentNode.querySelector(".invalid-feedback");

        if (!feedback) {
            return false;
        }

        feedback.textContent = message;
        return true;
    }

    /* Said out loud, for a field with nowhere to print it. */
    function announce(message) {
        if (window.Swal) {
            window.Swal.fire({
                text: message,
                icon: "info",
                confirmButtonText: "OK",
                confirmButtonColor: "#00224c"
            });
        } else {
            window.alert(message);
        }
    }

    function emailIn(form) {
        var field = form.querySelector('input[name="email"]');
        var value = field ? (field.value || "").trim() : "";

        return value !== "" ? value : "the address you gave";
    }

    function guard(form) {

        var confirmed = false;

        /* Typing again clears the mark, so the form stops nagging. */
        form.addEventListener("input", function (event) {
            if (event.target.classList) {
                event.target.classList.remove("is-invalid");
            }
        });

        /* The first field that is not acceptable, or null. */
        function firstProblem() {

            var fields = form.querySelectorAll("[required], [pattern], [data-pattern]");
            var askedGroup = {};

            for (var i = 0; i < fields.length; i++) {

                var field = fields[i];
                var type = field.type;

                if (type === "password" || type === "email") continue;
                if (type === "file" || type === "checkbox" || type === "hidden") continue;

                /*
                | A radio's value is its own, not the group's, so the usual
                | "is it empty" test says yes on every unselected option. What
                | matters is whether anything in the group is checked, and the
                | group is reported once rather than once per option.
                */
                if (type === "radio") {

                    if (askedGroup[field.name]) continue;
                    askedGroup[field.name] = true;

                    if (!field.hasAttribute("required")) continue;
                    if (form.querySelector('[name="' + field.name + '"]:checked')) continue;

                    var choose = field.getAttribute("title") || "Please choose one.";

                    if (!reject(field, choose)) {
                        announce(choose);
                    }

                    return field;
                }

                var value = (field.value || "").trim();
                var rule = ruleOf(field);

                if (value === "") {
                    if (field.hasAttribute("required")) {
                        var needed = labelOf(field) + " is required.";

                        if (!reject(field, needed)) {
                            announce(needed);
                        }

                        return field;
                    }
                    continue;
                }

                if (rule && !matches(rule, value)) {
                    var refused = field.getAttribute("title")
                        || "That value is not accepted here.";

                    if (!reject(field, refused)) {
                        announce(refused);
                    }

                    return field;
                }
            }

            return null;
        }

        form.addEventListener("submit", function (event) {

            if (confirmed) return;              // the second pass, after Yes
            if (event.defaultPrevented) return; // an earlier check already said no

            var bad = firstProblem();

            if (bad) {
                event.preventDefault();
                bad.scrollIntoView({ behavior: "smooth", block: "center" });
                bad.focus();
                return;
            }

            var agree = form.querySelector('input[name="agree"]');

            if (agree && !agree.checked) {
                event.preventDefault();

                var accurate = "Please confirm the information is accurate.";

                if (!reject(agree, accurate)) {
                    announce(accurate);
                }

                agree.scrollIntoView({ behavior: "smooth", block: "center" });
                return;
            }

            event.preventDefault();

            var submitter = event.submitter || null;

            function send() {
                confirmed = true;

                /*
                | requestSubmit, not submit: submit() skips the listeners
                | above and drops the submitting button's name and value,
                | which is what the handler reads to tell one action from
                | another.
                */
                if (form.requestSubmit) {
                    form.requestSubmit(submitter);
                } else {
                    form.submit();
                }
            }

            var title = form.getAttribute("data-confirm-title") || "Submit this form?";
            var text = (form.getAttribute("data-confirm-text") || "")
                .replace("{email}", emailIn(form));

            if (!window.Swal) {
                if (window.confirm(title)) send();
                return;
            }

            window.Swal.fire({
                title: title,
                text: text,
                icon: "question",
                showCancelButton: true,
                confirmButtonText: form.getAttribute("data-confirm-ok") || "Yes, submit",
                cancelButtonText: "Keep editing",
                confirmButtonColor: "#00224c",
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) send();
            });
        });
    }

    /*
    | Registered on DOMContentLoaded so that the guard's submit listener is
    | added after the per-page ones above it - the upload size check, the
    | business name check. It has to run last to be able to stand down when
    | one of those has already stopped the submit: confirming a submit that
    | is not going to happen would be worse than not confirming at all.
    */
    document.addEventListener("DOMContentLoaded", function () {
        var forms = document.querySelectorAll("form[data-guard]");

        for (var i = 0; i < forms.length; i++) {

            /*
            | Guarded once, whatever happens. A page that includes this file
            | twice would otherwise ask twice, and answering the first dialog
            | would leave the second one standing over a form already sent.
            */
            if (forms[i].hasAttribute("data-guarded")) continue;
            forms[i].setAttribute("data-guarded", "");

            guard(forms[i]);
        }
    });
})();
