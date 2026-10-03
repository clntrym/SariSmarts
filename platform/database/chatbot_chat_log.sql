/*
| The conversational layer logs which tools answered a question.
|
| Tool NAMES only -- never their results, and never the model's answer. The
| Phase 1 rule stands: an answer can hold takings and employee names, and a
| second copy of those is a second thing to protect.
|
| tools_used is also how we learn which tools earn their keep, and which
| questions arrive with no tool able to answer them.
*/
ALTER TABLE chatbot_messages
    MODIFY COLUMN matched_by ENUM('keyword','ai','ai_chat') NULL,
    ADD COLUMN tools_used VARCHAR(255) NULL AFTER matched_by;
