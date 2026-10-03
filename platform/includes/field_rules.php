<?php

/*
|--------------------------------------------------------------------------
| WHAT A TEXT FIELD MAY CONTAIN
|--------------------------------------------------------------------------
|
| The registration and resubmission forms ask for the same things, so the
| rule for each one lives here and both forms share it. Every pattern is
| also written into the field's own pattern attribute, so the browser and
| the server can never disagree about what was acceptable.
|
| The point is to keep junk and markup out of fields that should hold plain
| text, not to be clever about it. Two fields are deliberately exempt:
|
|   Password - any character at all. Restricting it would do nothing but
|              weaken the passwords people are allowed to choose.
|   Email    - validated by filter_var against the actual address format,
|              which already allows exactly what an address may contain.
|
| Everything else is held to the smallest set that still accepts real
| input. A Philippine address genuinely needs # and /; a business name
| genuinely needs & and an apostrophe. Rejecting those would be tidy and
| wrong.
|
| "Letters" below means \p{L} - letters in any script, not A to Z. Las
| Pinas, Paranaque, Dasmarinas and Los Banos are all spelled with an
| n-tilde, so an A-Z rule would have refused four cities in Metro Manila
| alone. Checked against the shipped location data: 15 municipalities fail
| an A-Z rule and none fail this one.
|
| Because of \p{L} every pattern here needs the u flag in PHP and the u or
| v flag in a browser. fieldProblem() passes it; the HTML pattern attribute
| is compiled with it by the browser; any hand-written JavaScript check has
| to ask for it too.
|
| Person names have their own rule in name_parts.php.
|
*/

require_once __DIR__ . '/name_parts.php';


/* Letters, digits, spaces and the punctuation a registered business name
   actually carries: Mang Juan's Sari-Sari Store (Main), J & R Trading Co. */
if (!defined('RULE_BUSINESS')) {
    define('RULE_BUSINESS', "[\p{L}0-9][\p{L}0-9 .,&'()-]*");
}

/* As above plus # and /, which appear in almost every PH street address:
   #12 Blk 4 Lot 3, Rizal St. */
if (!defined('RULE_ADDRESS')) {
    define('RULE_ADDRESS', "[\p{L}0-9#][\p{L}0-9 .,&'()#/\\r\\n-]*");
}

/* A city or municipality: letters, spaces, hyphens, periods, apostrophes,
   and the parentheses that the PSGC puts around a former name. */
if (!defined('RULE_PLACE')) {
    define('RULE_PLACE', "[\p{L}][\p{L} .,'()-]*");
}

/* Digits and the separators people write a number with. */
if (!defined('RULE_PHONE')) {
    define('RULE_PHONE', "[0-9+][0-9 ()+-]*");
}

/* Four digits. */
if (!defined('RULE_POSTAL')) {
    define('RULE_POSTAL', '[0-9]{4}');
}


if (!function_exists('fieldProblem')) {

    /*
    | Checks one free-text field. Returns the problem as a sentence, or null
    | when the value is usable.
    |
    | The message names the characters that are allowed rather than the ones
    | that are not, because somebody who has just been refused needs to know
    | what to type, not what they did wrong.
    */
    function fieldProblem(
        ?string $value,
        string $label,
        string $pattern,
        bool $required = true,
        int $max = 150,
        string $allows = 'letters, numbers and ordinary punctuation'
    ): ?string {

        $value = trim((string) $value);

        if ($value === '') {
            return $required ? $label . ' is required.' : null;
        }

        /*
        | Every pattern below runs with the u flag, and preg_match returns
        | false - not 0 - when the subject is not valid UTF-8. Refusing it
        | here means the person is told what actually happened instead of
        | being told their own city name contains a forbidden character.
        |
        | A browser posting this page sends UTF-8, so reaching this is a sign
        | something re-encoded the request on the way in.
        */
        if (!mb_check_encoding($value, 'UTF-8')) {
            return $label . ' did not arrive as readable text. Please retype it.';
        }

        if (mb_strlen($value) > $max) {
            return $label . ' cannot be longer than ' . $max . ' characters.';
        }

        /*
        | The delimiter is ~ rather than /, because RULE_ADDRESS contains a
        | forward slash and would otherwise close the pattern early - which
        | failed open-ended, refusing every address.
        |
        | The pattern is wrapped in a group so that a rule containing a
        | top-level | keeps both anchors. Without it, "^a|b$" means "starts
        | with a, or ends with b", which is not a rule anyone wrote.
        */
        if (!preg_match('~^(?:' . $pattern . ')$~u', $value)) {
            return $label . ' may only contain ' . $allows . '.';
        }

        return null;
    }
}
