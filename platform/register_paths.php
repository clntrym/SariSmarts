<?php
/*
|--------------------------------------------------------------------------
| WHERE A REGISTRATION'S FILES WAIT
|--------------------------------------------------------------------------
|
| An owner registering a business uploads their DTI and BIR certificates
| before the form is judged. Those files have to live somewhere between the
| upload and the moment the registration is accepted -- staged, so a mistake
| in the address field does not cost them the scan they just made.
|
| Somewhere, it turns out, is the hard part.
|
| It used to be __DIR__ . '/../../pending_uploads'. When the platform and the
| main application were siblings in htdocs that landed on C:\xampp -- outside
| the web root, exactly right. In the deployed repository the platform sits
| inside the application, so the same expression resolves to /var/www on
| Render, and the Dockerfile hands www-data only /var/www/html. mkdir()
| failed, its result was discarded, and move_uploaded_file() failed after it.
| Every owner who tried to register was told "The file could not be saved.
| Please try again" at something that could never work.
|
| So the location is chosen rather than computed from a relative path:
|
|   1. REGISTRATION_UPLOAD_DIR, if the host sets it. A deployment with a
|      mounted disk says so here and nothing else has to change.
|   2. The system temp directory. Writable everywhere, by definition, and on
|      Render it is the only thing that is. Files there do not survive a
|      restart -- which is correct for a staging area holding half-finished
|      registrations, and is why the sweep below exists at all.
|   3. A folder beside the application, for XAMPP, where that is outside the
|      web root and survives restarts.
|
| Whichever it lands on, it must be a directory nobody can ask for by URL.
*/

if (!function_exists('registrationStagingRoot')) {

    function registrationStagingRoot(): string
    {
        $configured = getenv('REGISTRATION_UPLOAD_DIR');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), "/\\");
        }

        /*
        | A folder above the web root, if there is one and it is writable.
        |
        | dirname(__DIR__, 3) is C:\xampp on XAMPP -- a level above htdocs,
        | which is the point. The first version of this used dirname(__DIR__,
        | 2), landing on C:\xampp\htdocs\pending_uploads: writable, yes, and
        | also fetchable at http://localhost/pending_uploads/<token>/dti.jpg.
        | These are unreviewed identity documents. Writable was never the only
        | requirement.
        */
        $above = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'pending_uploads';

        if (!registrationPathIsPublic($above)
            && (is_dir($above) || @mkdir($above, 0775, true))
            && is_writable($above)) {
            return $above;
        }

        /*
        | The temp directory. Writable on every host this runs on, and the
        | right kind of impermanent for files that are either claimed within
        | the hour or abandoned.
        */
        return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'retailcore_pending_uploads';
    }
}

if (!function_exists('registrationPathIsPublic')) {

    /**
     * Whether a browser could fetch something from this path by URL.
     *
     * A staging folder inside the document root is a staging folder whose
     * contents anybody can download if they guess a token -- and these are
     * DTI and BIR certificates, which carry a TIN and a registered address.
     * Writable is necessary; not public is just as necessary, and only one
     * of the two announces itself when it is wrong.
     */
    function registrationPathIsPublic(string $path): bool
    {
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        if (trim($documentRoot) === '') {
            /* No request to judge against -- a CLI script, say. The web root
               cannot be ruled out, so the path is treated as public and the
               caller falls through to somewhere that certainly is not. */
            $documentRoot = dirname(__DIR__, 2);
        }

        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $candidate = rtrim(str_replace('\\', '/', $path), '/');

        if ($documentRoot === '') {
            return false;
        }

        /* Compared case-insensitively: Windows paths differ in case and mean
           the same folder, and being wrong here is a leak. */
        return stripos($candidate . '/', $documentRoot . '/') === 0;
    }
}
