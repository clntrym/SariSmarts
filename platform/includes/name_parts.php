<?php

/*
|--------------------------------------------------------------------------
| PERSON NAMES
|--------------------------------------------------------------------------
|
| Two screens collect a person's name in three parts - the platform account
| form and the HR employee form - and both store a composed display name
| beside the parts. The rule for what a name may contain lives here so the
| two can never disagree, and so the pattern attribute on the inputs is the
| same string the server checks against.
|
| What is allowed: letters and spaces, plus the three marks that are part of
| real names - the hyphen in Ma-ann, the apostrophe in D'Souza, a period
| after a shortened name in Ma. Teresa, and a trailing period for Jr.
| Everything else is refused: digits, @, #, %, slashes, brackets,
| underscores. A name has to start and end on a letter, so "-ann" and
| "Ma--ann" are out too.
|
| "Letters" means letters in any script, not A to Z. Pena, Munoz and Nino
| are written with an n-tilde, and an A-Z rule would have told a third of
| one province that their own surname is not a name. That is the kind of
| validation that is tidy and wrong.
|
| To allow letters only, drop the marks from the character class below and
| every input follows.
|
*/

if (!defined('NAME_PATTERN')) {
    define('NAME_PATTERN', "\p{L}+(?:[ '-]\p{L}+|\. ?\p{L}+)*\.?");
}

if (!defined('NAME_PART_MAX')) {
    define('NAME_PART_MAX', 40);
}


if (!function_exists('namePartProblem')) {

    /* Returns the problem with one name part, or null when it is usable. */
    function namePartProblem(string $value, string $label, bool $required): ?string
    {
        if ($value === '') {
            return $required ? $label . ' is required.' : null;
        }

        /*
        | preg_match with /u returns false on a subject that is not valid
        | UTF-8, which would otherwise be reported as a forbidden character.
        | Say what really happened instead.
        */
        if (!mb_check_encoding($value, 'UTF-8')) {
            return $label . ' did not arrive as readable text. Please retype it.';
        }

        /*
        | Characters, not bytes. The column is VARCHAR(40), which counts
        | characters too, and an n-tilde takes two bytes - counting bytes
        | would shorten the limit for exactly the names that need it.
        */
        if (mb_strlen($value) > NAME_PART_MAX) {
            return $label . ' cannot be longer than ' . NAME_PART_MAX . ' characters.';
        }

        /* Grouped and /u: the pattern uses \p{L}, which needs the flag. */
        if (!preg_match('/^(?:' . NAME_PATTERN . ')$/u', $value)) {
            return $label . ' may only contain letters, spaces, hyphens and apostrophes.';
        }

        return null;
    }
}


if (!function_exists('nameProblem')) {

    /*
    | Checks all three parts and the length of what they compose, in the
    | order somebody reads a form. $max is the display column's own limit.
    */
    function nameProblem(string $last, string $first, string $middle, int $max): ?string
    {
        $problem = namePartProblem($last, 'Last name', true)
            ?? namePartProblem($first, 'First name', true)
            ?? namePartProblem($middle, 'Middle name', false);

        if ($problem !== null) {
            return $problem;
        }

        if (mb_strlen(composeFullName($last, $first, $middle)) > $max) {
            return 'Those three names are too long to store together. Please shorten one.';
        }

        return null;
    }
}


if (!function_exists('composeFullName')) {

    /*
    | The forms ask in the order a Philippine form does - last, first,
    | middle - but the display name is what greetings and tables print, so
    | it is composed the way a name is read out. A missing middle name must
    | not leave a double space behind.
    */
    function composeFullName(string $last, string $first, string $middle): string
    {
        return trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));
    }
}
