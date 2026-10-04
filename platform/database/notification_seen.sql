-- ============================================================
-- RetailCore - unread marker for the platform notification bell
--
-- The bell builds its list from live data rather than from a
-- table of copies: an application awaiting review is the row in
-- company, an unpaid subscription is the row in
-- company_subscriptions. Deriving it means the bell can never
-- disagree with the module it links to.
--
-- What cannot be derived is whether a person has looked yet, so
-- that one fact is stored: the moment each operator last opened
-- the bell. Anything with a later timestamp counts as unread.
--
-- One column instead of a row per operator per item, because the
-- question is only ever "since when", and the answer stays
-- correct as items appear and disappear on their own.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS notifications_seen_at DATETIME NULL AFTER status;
