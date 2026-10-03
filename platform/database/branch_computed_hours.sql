/*
|--------------------------------------------------------------------------
| BRANCH: COMPUTED OPERATING HOURS, NO CONTACT / EMAIL / MANAGER
|--------------------------------------------------------------------------
|
| The branch form no longer asks for a contact number, email or branch
| manager, and it no longer lets anyone type Operating Hours by hand -- the
| figure is computed from Opening Time and Closing Time, on the server.
|
| email and branch_manager were NOT NULL, so a branch created without them
| would have to store an empty string pretending to be an answer. They allow
| NULL now. The columns themselves stay: existing rows keep what they hold,
| and nothing else in the app reads them.
|
| operating_hours was INT. 8:00 AM to 5:30 PM is 9.5 hours, and an INT
| would quietly store 9. DECIMAL(4,2) holds the real figure up to 24.00.
|
*/

ALTER TABLE branch
    MODIFY COLUMN email VARCHAR(255) NULL,
    MODIFY COLUMN branch_manager VARCHAR(255) NULL,
    MODIFY COLUMN operating_hours DECIMAL(4,2) NULL;

/* Any branch that already has both times gets its hours recomputed. */
UPDATE branch
SET operating_hours = ROUND(
        MOD(TIME_TO_SEC(closing_time) - TIME_TO_SEC(opening_time) + 86400, 86400) / 3600,
        2)
WHERE opening_time IS NOT NULL
  AND closing_time IS NOT NULL
  AND opening_time <> closing_time;
