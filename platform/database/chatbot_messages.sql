/*
| What each user asked the assistant, and what happened to it.
|
| The question is stored; the answer is not. An answer can hold salaries and
| takings, and a second copy of those is a second thing to protect. The
| history view re-runs the intent instead, which keeps figures current and
| re-applies every access check.
|
| no_match rows are the record of what staff expected the assistant to know,
| and are what decides which question is worth adding next.
*/
CREATE TABLE IF NOT EXISTS chatbot_messages (
    message_id  INT(11) NOT NULL AUTO_INCREMENT,
    company_id  INT(11) NOT NULL,
    user_id     INT(11) NOT NULL,
    role        VARCHAR(20) NOT NULL,
    question    VARCHAR(500) NOT NULL,
    intent_id   VARCHAR(60) NULL,
    matched_by  ENUM('keyword','ai') NULL,
    outcome     ENUM('answered','no_match','denied_role','denied_plan') NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_chatbot_messages_user (company_id, user_id, created_at),
    CONSTRAINT fk_chatbot_messages_company
        FOREIGN KEY (company_id) REFERENCES company(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
