<?php
/*
| The floating assistant.
|
| Included once per role header, so it rides along on every page without each
| page knowing about it. A signed-out visitor gets nothing at all.
|
| Answers are rendered with textContent, never innerHTML: a product or employee
| name containing markup is shown as text, not run as script.
*/

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    return;
}

if (empty($_SESSION['chatbot_csrf'])) {
    $_SESSION['chatbot_csrf'] = bin2hex(random_bytes(16));
}
?>

<div id="chatbotRoot" class="chatbot-root">
    <button type="button" id="chatbotToggle" class="chatbot-bubble" aria-label="Assistant">
        <i class="bi bi-chat-dots-fill"></i>
    </button>

    <div id="chatbotPanel" class="chatbot-panel shadow" hidden>
        <div class="chatbot-head">
            <span class="fw-semibold">Assistant</span>
            <button type="button" id="chatbotClose" class="btn-close btn-close-white"></button>
        </div>

        <div id="chatbotLog" class="chatbot-log"></div>
        <div id="chatbotChips" class="chatbot-chips"></div>

        <form id="chatbotForm" class="chatbot-form">
            <input type="text" id="chatbotInput" class="form-control form-control-sm"
                   placeholder="Ask a question..." autocomplete="off" maxlength="500">
            <button class="btn btn-primary btn-sm" type="submit">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
</div>

<style>
    .chatbot-root { position: fixed; right: 20px; bottom: 20px; z-index: 1080; }
    .chatbot-bubble { width: 54px; height: 54px; border-radius: 50%; border: 0;
        background: #0d6efd; color: #fff; font-size: 1.4rem;
        box-shadow: 0 6px 18px rgba(0, 0, 0, .2); }
    .chatbot-panel { position: absolute; right: 0; bottom: 66px;
        width: min(360px, calc(100vw - 40px)); max-height: 70vh; background: #fff;
        border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; }
    .chatbot-head { background: #0d6efd; color: #fff; padding: 10px 14px;
        display: flex; align-items: center; justify-content: space-between; }
    .chatbot-log { flex: 1; overflow-y: auto; padding: 12px; font-size: .875rem; }
    .chatbot-msg { margin-bottom: 10px; }
    .chatbot-msg.me { text-align: right; color: #0d6efd; }
    .chatbot-card { background: #f6f8fb; border-radius: 12px; padding: 10px; }
    .chatbot-prose { margin-bottom: 6px; line-height: 1.4; }
    .chatbot-card table { width: 100%; font-size: .8rem; }
    .chatbot-card th, .chatbot-card td { padding: 2px 4px; text-align: left; }
    .chatbot-chips { padding: 0 12px 8px; display: flex; flex-wrap: wrap; gap: 6px; }
    .chatbot-chip { border: 1px solid #cfd8e3; background: #fff; border-radius: 999px;
        padding: 3px 10px; font-size: .75rem; cursor: pointer; }
    .chatbot-form { display: flex; gap: 6px; padding: 10px; border-top: 1px solid #e9eef5; }
</style>

<script>
(function () {
    const token = <?= json_encode($_SESSION['chatbot_csrf']) ?>;
    const endpoint = '../includes/chatbot/ask.php';

    const panel = document.getElementById('chatbotPanel');
    const log = document.getElementById('chatbotLog');
    const chips = document.getElementById('chatbotChips');
    const form = document.getElementById('chatbotForm');
    const input = document.getElementById('chatbotInput');

    document.getElementById('chatbotToggle').addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) { input.focus(); }
    });

    document.getElementById('chatbotClose').addEventListener('click', function () {
        panel.hidden = true;
    });

    function bubble(text, mine) {
        const div = document.createElement('div');
        div.className = 'chatbot-msg' + (mine ? ' me' : '');
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function renderAnswer(answer) {
        const card = document.createElement('div');
        card.className = 'chatbot-card';

        const title = document.createElement('div');
        title.className = 'fw-semibold mb-1';
        title.textContent = answer.title || '';
        card.appendChild(title);

        /* A conversational answer is prose: it is the answer, not a caveat. */
        if (answer.prose) {
            const prose = document.createElement('div');
            prose.className = 'chatbot-prose';
            prose.textContent = answer.prose;
            card.appendChild(prose);
        }

        (answer.lines || []).forEach(function (line) {
            const row = document.createElement('div');
            row.className = 'd-flex justify-content-between';

            const label = document.createElement('span');
            label.textContent = line[0];

            const value = document.createElement('span');
            value.className = 'fw-semibold';
            value.textContent = line[1];

            row.appendChild(label);
            row.appendChild(value);
            card.appendChild(row);
        });

        if (answer.table) {
            const table = document.createElement('table');
            const head = document.createElement('tr');

            answer.table.columns.forEach(function (column) {
                const th = document.createElement('th');
                th.textContent = column;
                head.appendChild(th);
            });

            table.appendChild(head);

            answer.table.rows.forEach(function (row) {
                const tr = document.createElement('tr');

                row.forEach(function (cell) {
                    const td = document.createElement('td');
                    td.textContent = cell;
                    tr.appendChild(td);
                });

                table.appendChild(tr);
            });

            card.appendChild(table);
        }

        if (answer.note) {
            const note = document.createElement('div');
            note.className = 'text-muted small mt-1';
            note.textContent = answer.note;
            card.appendChild(note);
        }

        (answer.tables || []).forEach(function (result) {
            const table = document.createElement('table');
            const head = document.createElement('tr');

            result.columns.forEach(function (column) {
                const th = document.createElement('th');
                th.textContent = column;
                head.appendChild(th);
            });

            table.appendChild(head);

            result.rows.forEach(function (row) {
                const tr = document.createElement('tr');

                row.forEach(function (cell) {
                    const td = document.createElement('td');
                    td.textContent = cell;
                    tr.appendChild(td);
                });

                table.appendChild(tr);
            });

            card.appendChild(table);

            if (result.truncated) {
                const more = document.createElement('div');
                more.className = 'text-muted small';
                more.textContent = 'More matched than are shown here.';
                card.appendChild(more);
            }
        });

        if (answer.link) {
            const link = document.createElement('a');
            link.className = 'small d-inline-block mt-1';
            link.href = answer.link.href;
            link.textContent = answer.link.label;
            card.appendChild(link);
        }

        const wrap = document.createElement('div');
        wrap.className = 'chatbot-msg';
        wrap.appendChild(card);
        log.appendChild(wrap);
        log.scrollTop = log.scrollHeight;
    }

    function renderChips(suggestions) {
        chips.replaceChildren();

        (suggestions || []).forEach(function (suggestion) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chatbot-chip';
            chip.textContent = suggestion.label;

            chip.addEventListener('click', function () {
                /* A question that needs a product name is a prompt, not a
                   question: put it in the box and let them finish it. */
                if (suggestion.needs_input) {
                    input.value = suggestion.label + ' ';
                    input.focus();
                    return;
                }

                ask(suggestion.label);
            });

            chips.appendChild(chip);
        });
    }

    function post(question) {
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, csrf_token: token })
        }).then(function (response) { return response.json(); });
    }

    function ask(question) {
        bubble(question, true);
        input.value = '';

        post(question).then(function (data) {
            if (data.ok) {
                renderAnswer(data.answer);
            } else {
                bubble(data.message || 'I cannot answer that.', false);
            }

            renderChips(data.suggestions);
        }).catch(function () {
            bubble('I cannot reach the server right now. Please try again.', false);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const question = input.value.trim();
        if (question !== '') { ask(question); }
    });

    /* An empty question returns the suggestion list and nothing else, so the
       panel opens already showing what this user may ask. */
    post('').then(function (data) {
        renderChips(data.suggestions);
    }).catch(function () {
        /* The chips are a convenience; typing still works without them. */
    });
})();
</script>
