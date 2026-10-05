<?php
/*
|--------------------------------------------------------------------------
| THE RESUME AN APPLICANT ATTACHED
|--------------------------------------------------------------------------
|
| applications.resume was written as a bare filename -- "1759_cv.pdf" --
| while the file sat in uploads/resume/. Every other document in this system
| stores its folder, and the Resume card rendered the column straight into
| an href, so a CV that WAS on file linked to /hr/1759_cv.pdf, which is
| nowhere.
|
| Old rows still hold bare names and must go on working, so one function
| decides what a stored value means, and new rows are written through it.
|
| THREE STATES, NOT TWO
|
| The card asked whether the column was empty and answered both "nobody
| attached a CV" and "the CV is gone" with the word Missing. Those want
| different things done -- ask the applicant, or stop looking -- and the
| same confusion sent somebody hunting for a file that was never there.
*/

if (!function_exists('resumePath')) {

    /**
     * Where a stored resume value points, or '' when it points nowhere.
     *
     * The value reaches an href and a file lookup, and it was built from a
     * name the applicant chose, so anything climbing out of the folder is
     * refused rather than cleaned -- a name worth rewriting is a name worth
     * rejecting.
     */
    function resumePath(?string $stored): string
    {
        $stored = trim(str_replace('\\', '/', (string) $stored));

        if ($stored === '') {
            return '';
        }

        if (str_contains($stored, '..')) {
            return '';
        }

        $path = str_starts_with($stored, 'uploads/resume/')
            ? $stored
            : 'uploads/resume/' . basename($stored);

        /* basename() of a directory-only value leaves nothing to serve. */
        return $path === 'uploads/resume/' ? '' : $path;
    }
}

if (!function_exists('resumeState')) {

    /**
     * 'none' -- nothing was attached.
     * 'stored' -- it is on file and can be served.
     * 'lost' -- it was attached, and the file is gone.
     *
     * @param string $root the application root, for the pre-store files
     *                     still sitting on the disk of a host that has one
     */
    function resumeState(mysqli $conn, ?string $stored, string $root): string
    {
        $path = resumePath($stored);

        if ($path === '') {
            return 'none';
        }

        require_once __DIR__ . '/stored_files.php';

        if (platformFileRead($conn, $path) !== null) {
            return 'stored';
        }

        /* Attached before the store existed, on a host with a disk. */
        return is_file(rtrim($root, '/\\') . '/' . $path) ? 'stored' : 'lost';
    }
}
