-- ============================================================
-- RetailCore - the inquiry assistant on the public pages
--
-- A visitor asks about plans, pricing or what the system does.
-- When they show real interest the assistant asks for their
-- details, and that becomes a row in marketing_leads with
-- source 'Website' - the same queue the Leads module already
-- works through.
--
-- WHY THE CONVERSATION IS KEPT
--
-- A lead that says only "wants Retail Professional" tells the
-- person who rings them nothing. The thread behind it says what
-- they actually asked, which is what makes the call useful.
--
-- WHERE THE API KEY IS NOT
--
-- Not here. It lives in C:\xampp\sarismart_secrets.php, outside
-- the webroot, which is where the RetailCore chatbot already
-- keeps it. A key in a database is one query away from a log,
-- and a key under htdocs is one misconfiguration away from being
-- downloaded.
--
-- lead_id is nullable and stays null for every conversation that
-- never turns into one, which is most of them.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;


CREATE TABLE IF NOT EXISTS landing_chats (

    chat_id       INT(11)     NOT NULL AUTO_INCREMENT,

    -- The browser's only handle on its own conversation. Random
    -- and unguessable: it is the sole thing authorising a reader
    -- to see these messages, since nobody is signed in.
    session_token CHAR(40)    NOT NULL,

    lead_id       INT(11)     NULL,
    visitor_ip    VARCHAR(45) NULL,

    -- Counted rather than derived, so the per-conversation limit
    -- is one read instead of a scan of the messages.
    message_count INT(11)     NOT NULL DEFAULT 0,

    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (chat_id),
    UNIQUE KEY uniq_landing_chat_token (session_token),
    KEY idx_landing_chat_lead (lead_id),
    KEY idx_landing_chat_ip (visitor_ip, created_at),

    CONSTRAINT fk_landing_chat_lead
        FOREIGN KEY (lead_id)
        REFERENCES marketing_leads (lead_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS landing_chat_messages (

    message_id INT(11)   NOT NULL AUTO_INCREMENT,
    chat_id    INT(11)   NOT NULL,

    role       ENUM('visitor','assistant') NOT NULL,
    body       TEXT      NOT NULL,

    -- Null for a scripted reply, so the cost of the AI path can
    -- be seen rather than guessed at.
    ai_model   VARCHAR(60) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (message_id),
    KEY idx_landing_chat_message (chat_id, message_id),

    CONSTRAINT fk_landing_chat_message_chat
        FOREIGN KEY (chat_id)
        REFERENCES landing_chats (chat_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
