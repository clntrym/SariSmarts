/*
|--------------------------------------------------------------------------
| THE INQUIRY ASSISTANT
|--------------------------------------------------------------------------
|
| A bubble on the public pages. A visitor asks about RetailCore, and when
| they show interest it offers a short form whose answers become a lead.
|
| EVERY REPLY IS INSERTED AS TEXT
|
| Never as HTML. The reply comes from a language model, which means its
| contents are not ours and are not predictable -- a model that has been
| talked into emitting a <script> tag must produce a bubble with a script
| tag written in it, not a running script. textContent is what guarantees
| that, and it is the reason there is no formatting here.
|
| THE TOKEN
|
| The server hands one back on the first question. It is kept in
| localStorage so a visitor who moves from the pricing page to the features
| page carries on the same conversation rather than starting again. It
| names a conversation and nothing else: no account, no session, nothing
| that would matter if it leaked.
|
| WHEN THINGS FAIL
|
| A network error, a rate limit, a server that is down: all end as a
| sentence in the bubble. The widget never shows a spinner that does not
| stop, and never silently swallows a question somebody typed.
|
*/

(function () {
    "use strict";

    var ENDPOINT = "chat.php";
    var STORE = "retailcore_chat_token";

    var root = document.getElementById("retailcoreChat");

    if (!root) {
        return;
    }

    /* The page may live one level down; the endpoint is at the web root. */
    var base = root.getAttribute("data-base") || "";

    var panel = root.querySelector(".sc-panel");
    var toggle = root.querySelector(".sc-toggle");
    var closeBtn = root.querySelector(".sc-close");
    var log = root.querySelector(".sc-log");
    var form = root.querySelector(".sc-ask");
    var input = root.querySelector(".sc-input");
    var send = root.querySelector(".sc-send");
    var leadBox = root.querySelector(".sc-lead");
    var leadForm = root.querySelector(".sc-lead-form");

    var token = "";

    try {
        token = window.localStorage.getItem(STORE) || "";
    } catch (e) {
        /* Private browsing, or storage refused. The conversation simply
           does not survive the next page, which is a smaller loss than
           the widget not working at all. */
        token = "";
    }

    function remember(value) {
        if (!value) {
            return;
        }

        token = value;

        try {
            window.localStorage.setItem(STORE, value);
        } catch (e) {
            /* As above: nothing to do, and nothing worth saying. */
        }
    }

    /* Text, never markup. See the note at the top. */
    function bubble(text, who) {
        var row = document.createElement("div");
        row.className = "sc-msg sc-" + who;

        var body = document.createElement("div");
        body.className = "sc-bubble";
        body.textContent = text;

        row.appendChild(body);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;

        return row;
    }

    function thinking() {
        var row = bubble("...", "bot");
        row.classList.add("sc-thinking");
        return row;
    }

    function busy(state) {
        input.disabled = state;
        send.disabled = state;
    }

    function post(payload, done) {

        payload.token = token;

        fetch(base + ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                remember(data.token);
                done(data);
            })
            .catch(function () {
                done({
                    ok: false,
                    reply: "I could not reach our server just then. Please try again in a moment."
                });
            });
    }

    function open() {
        panel.classList.add("sc-open");
        root.classList.add("sc-is-open");

        if (!log.children.length) {
            bubble(
                "Hi! I can answer questions about RetailCore - our plans, what they include, "
                + "and how to sign up. What would you like to know?",
                "bot"
            );
        }

        input.focus();
    }

    function close() {
        panel.classList.remove("sc-open");
        root.classList.remove("sc-is-open");
    }

    toggle.addEventListener("click", function () {
        if (panel.classList.contains("sc-open")) {
            close();
        } else {
            open();
        }
    });

    closeBtn.addEventListener("click", close);

    /* Escape closes it, the way every other overlay on the web does. */
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && panel.classList.contains("sc-open")) {
            close();
        }
    });


    form.addEventListener("submit", function (event) {

        event.preventDefault();

        var question = input.value.trim();

        if (question === "") {
            return;
        }

        bubble(question, "me");
        input.value = "";
        busy(true);

        var pending = thinking();

        post({ action: "ask", message: question }, function (data) {

            pending.remove();
            busy(false);

            bubble(data.reply || "Sorry, I did not catch that.", "bot");

            /*
            | The server decides when to ask, from what the visitor typed
            | rather than from what the model replied. Shown once: a form
            | that keeps reappearing reads as nagging.
            */
            if (data.askForLead && leadBox.hidden) {
                leadBox.hidden = false;
                log.scrollTop = log.scrollHeight;
            }

            input.focus();
        });
    });


    leadForm.addEventListener("submit", function (event) {

        event.preventDefault();

        var button = leadForm.querySelector("button");
        button.disabled = true;

        post({
            action: "lead",
            name: leadForm.elements.name.value.trim(),
            business: leadForm.elements.business.value.trim(),
            email: leadForm.elements.email.value.trim(),
            phone: leadForm.elements.phone.value.trim()
        }, function (data) {

            button.disabled = false;

            if (!data.ok) {
                var problem = leadForm.querySelector(".sc-lead-error");
                problem.textContent = data.reply || "Please check what you entered.";
                problem.hidden = false;
                return;
            }

            leadBox.hidden = true;
            bubble(data.reply, "bot");
        });
    });
})();
