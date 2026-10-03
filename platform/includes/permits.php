<?php

/*
|--------------------------------------------------------------------------
| DTI AND BIR REGISTRATION
|--------------------------------------------------------------------------
|
| The rules for the two documents a business registers with, in one place so
| the public form, the resubmission form and the Super Admin review all
| agree.
|
| DTI expires, BIR does not. A DTI Certificate of Business Name Registration
| runs for five years from the day it was issued - the certificate itself
| prints both dates - so the expiry is never asked for. It is worked out
| from the registration date, which means the two can never contradict each
| other on a form somebody filled in quickly.
|
| A BIR Certificate of Registration (Form 2303) carries no expiry at all. It
| stays valid until the business changes, so there is nothing to monitor and
| nothing to ask.
|
*/


if (!defined('DTI_VALID_YEARS')) {
    define('DTI_VALID_YEARS', 5);
}

/*
| How close the expiry has to be before the status changes. Days remaining:
|
|   below zero   Expired
|   90 or less   Renewal Reminder
|   180 or less  Expiring Soon
|   more         Valid
*/
if (!defined('DTI_RENEWAL_DAYS')) {
    define('DTI_RENEWAL_DAYS', 90);
}

if (!defined('DTI_EXPIRING_DAYS')) {
    define('DTI_EXPIRING_DAYS', 180);
}


if (!function_exists('dtiExpiryFrom')) {

    /*
    | Registration date plus five years.
    |
    | 29 February is the awkward case: adding five years lands on a date
    | that does not exist, and PHP rolls it forward to 1 March. For a
    | compliance monitor that is the wrong direction - it would call a
    | lapsed registration valid for one more day - so it is pulled back to
    | 28 February instead. Early is safe; late is not.
    */
    function dtiExpiryFrom(?string $registrationDate): ?string
    {
        $registrationDate = trim((string) $registrationDate);

        if ($registrationDate === '') {
            return null;
        }

        $date = date_create_from_format('Y-m-d', $registrationDate);

        if (!$date || $date->format('Y-m-d') !== $registrationDate) {
            return null;
        }

        $day = (int) $date->format('d');
        $expiry = (clone $date)->add(new DateInterval('P' . DTI_VALID_YEARS . 'Y'));

        /* Rolled into the next month, so the target day never existed. */
        if ((int) $expiry->format('d') !== $day) {
            $expiry->modify('last day of previous month');
        }

        return $expiry->format('Y-m-d');
    }
}


if (!function_exists('dtiStatus')) {

    /*
    | Where a DTI registration stands today.
    |
    | $today is passed in rather than read from the clock so the caller can
    | hand over the database's idea of now - the same clock that wrote the
    | dates being compared.
    |
    | Returns label, tone, the days remaining, and whether it needs acting
    | on, so a table cell and a notification can be built from one answer.
    */
    function dtiStatus(?string $expiryDate, ?string $today = null): array
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return [
                'label' => 'Not registered',
                'tone' => 'muted',
                'days' => null,
                'needsAction' => false,
            ];
        }

        $today = $today === null ? date('Y-m-d') : substr(trim($today), 0, 10);

        $start = date_create_from_format('Y-m-d', $today);
        $end = date_create_from_format('Y-m-d', substr(trim($expiryDate), 0, 10));

        if (!$start || !$end) {
            return [
                'label' => 'Unknown',
                'tone' => 'muted',
                'days' => null,
                'needsAction' => false,
            ];
        }

        /* Whole days, so a certificate expiring today reads as 0, not -0.4. */
        $days = (int) $start->diff($end)->format('%r%a');

        if ($days < 0) {
            return [
                'label' => 'Expired',
                'tone' => 'bad',
                'days' => $days,
                'needsAction' => true,
            ];
        }

        if ($days <= DTI_RENEWAL_DAYS) {
            return [
                'label' => 'Renewal Reminder',
                'tone' => 'bad',
                'days' => $days,
                'needsAction' => true,
            ];
        }

        if ($days <= DTI_EXPIRING_DAYS) {
            return [
                'label' => 'Expiring Soon',
                'tone' => 'warn',
                'days' => $days,
                'needsAction' => true,
            ];
        }

        return [
            'label' => 'Valid',
            'tone' => 'ok',
            'days' => $days,
            'needsAction' => false,
        ];
    }
}


if (!function_exists('normaliseTin')) {

    /*
    | A TIN is nine digits, with a five digit branch code on a BIR 2303.
    | People type it with dashes, with spaces, or as one run of digits, so
    | the digits are what gets kept and the formatting is put back on.
    |
    | Returns null when it is not a TIN at all.
    */
    function normaliseTin(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '' || strlen($digits) < 9 || strlen($digits) > 14) {
            return null;
        }

        $base = substr($digits, 0, 9);
        $branch = substr($digits, 9);

        $formatted = substr($base, 0, 3) . '-' . substr($base, 3, 3) . '-' . substr($base, 6, 3);

        if ($branch !== '') {
            $formatted .= '-' . str_pad($branch, 5, '0', STR_PAD_LEFT);
        }

        return $formatted;
    }
}


if (!function_exists('permitProblem')) {

    /*
    | Validates the DTI and BIR details against what the certificates
    | actually carry. Returns a field => message map, empty when all of it
    | is usable.
    |
    | $today is the database clock again: a registration date cannot be in
    | the future, and deciding that against a different clock would reject
    | a certificate issued this morning in a server an hour behind.
    */
    function permitProblem(array $post, string $today): array
    {
        $errors = [];

        /* ---- DTI ---------------------------------------------------- */

        $dtiRaw = trim((string) ($post['dti_registration_number'] ?? ''));
        $dtiNumber = preg_replace('/\D/', '', $dtiRaw);

        if ($dtiRaw === '') {
            $errors['dti_registration_number'] = 'The DTI Business Name No. is required.';
        } elseif (!preg_match('/^[0-9 -]+$/', $dtiRaw)) {
            /*
            | Stripping the letters out quietly would turn a misread like
            | 49559AB into 49559 and save it as if it were right. OCR gets a
            | character wrong often enough that this has to be shown, not
            | swallowed.
            */
            $errors['dti_registration_number'] = 'The DTI Business Name No. is digits only.';
        } elseif (strlen($dtiNumber) < 5 || strlen($dtiNumber) > 10) {
            $errors['dti_registration_number'] = 'A DTI Business Name No. is 5 to 10 digits.';
        }

        $dtiDate = trim((string) ($post['dti_registration_date'] ?? ''));

        if ($dtiDate === '') {
            $errors['dti_registration_date'] = 'The DTI registration date is required.';
        } else {
            $parsed = date_create_from_format('Y-m-d', $dtiDate);

            if (!$parsed || $parsed->format('Y-m-d') !== $dtiDate) {
                $errors['dti_registration_date'] = 'The DTI registration date must be a real date.';
            } elseif (strtotime($dtiDate) > strtotime($today)) {
                $errors['dti_registration_date'] = 'The DTI registration date cannot be in the future.';
            } elseif (strtotime($dtiDate) < strtotime('1990-01-01')) {
                $errors['dti_registration_date'] = 'That DTI registration date looks too far back to be right.';
            }
        }

        /* ---- BIR ---------------------------------------------------- */

        $tin = normaliseTin($post['bir_tin'] ?? '');

        if (trim((string) ($post['bir_tin'] ?? '')) === '') {
            $errors['bir_tin'] = 'The TIN on your BIR certificate is required.';
        } elseif ($tin === null) {
            $errors['bir_tin'] = 'A TIN is nine digits, plus a five digit branch code.';
        }

        $birDate = trim((string) ($post['bir_registration_date'] ?? ''));

        if ($birDate === '') {
            $errors['bir_registration_date'] = 'The BIR registration date is required.';
        } else {
            $parsed = date_create_from_format('Y-m-d', $birDate);

            if (!$parsed || $parsed->format('Y-m-d') !== $birDate) {
                $errors['bir_registration_date'] = 'The BIR registration date must be a real date.';
            } elseif (strtotime($birDate) > strtotime($today)) {
                $errors['bir_registration_date'] = 'The BIR registration date cannot be in the future.';
            }
        }

        $rdo = preg_replace('/\D/', '', (string) ($post['bir_rdo_code'] ?? ''));

        if ($rdo !== '' && strlen($rdo) > 3) {
            $errors['bir_rdo_code'] = 'An RDO code is up to three digits.';
        }

        $ocn = trim((string) ($post['bir_ocn'] ?? ''));

        if ($ocn !== '' && !preg_match('/^[A-Za-z0-9]{6,40}$/', $ocn)) {
            $errors['bir_ocn'] = 'An OCN is letters and digits only.';
        }

        return $errors;
    }
}
