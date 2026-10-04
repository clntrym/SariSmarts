<?php
/*
|--------------------------------------------------------------------------
| WHERE THIS APPLICATION LIVES
|--------------------------------------------------------------------------
|
| One answer to "what is the address of this site", for the handful of places
| that genuinely need an absolute one.
|
| It exists because those places were guessing. Password reset emails were
| built from the literal string "http://localhost/SariSmarts/..." -- which is
| correct on the machine it was written on and useless everywhere else, so
| every reset link sent from the deployed site pointed the recipient at their
| own computer. Redirects were built from "/SariSmarts/...", which is right
| under XAMPP and a 404 on Render, where the application is served from the
| root.
|
| Paths INSIDE the app do not need this. A link to "/accounts/acc_log_in.php"
| is root-absolute and correct in both places; that is the convention the rest
| of the codebase now follows. This is only for the cases where a full
| scheme-and-host URL has to be put in an email or a payment callback, where
| there is no request for the browser to resolve a relative path against.
*/

if (!function_exists('appBaseUrl')) {

    /**
     * The scheme and host this request arrived on, with no trailing slash.
     *
     * Taken from the request rather than configured, so it is right on
     * localhost, on onrender.com, and on whatever domain comes next, without
     * anybody remembering to change a setting.
     */
    function appBaseUrl(): string
    {
        /* A configured value wins, for the cases with no request at all --
           a cron job sending reminder mail, for instance. */
        $configured = getenv('APP_BASE_URL');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            /* Render terminates TLS at its proxy, so the request reaching PHP
               is plain HTTP. Without this every generated link would say
               http:// on a site served over https://. */
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        return ($https ? 'https://' : 'http://') . $host;
    }
}

if (!function_exists('appUrl')) {

    /**
     * An absolute URL for a path inside this application.
     *
     *     appUrl('/accounts/reset-password.php?token=' . $token)
     */
    function appUrl(string $path): string
    {
        return appBaseUrl() . '/' . ltrim($path, '/');
    }
}
