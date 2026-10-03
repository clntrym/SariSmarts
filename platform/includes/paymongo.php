<?php

/*
|--------------------------------------------------------------------------
| PAYMONGO CLIENT
|--------------------------------------------------------------------------
|
| Thin wrapper around the PayMongo REST API, matching the one SariSmarts
| already uses for supplier invoices so both sides behave the same way.
|
| Nothing here decides whether a payment happened. The browser coming back
| from PayMongo is only a signal to go and check; the Checkout Session is
| always re-fetched from the API before anything is recorded.
|
*/

function paymongoRequest($method, $endpoint, $data = null)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            "The PHP cURL extension isn't enabled, so online payments can't be started. "
            . "Enable it in php.ini and try again."
        );
    }

    if (PAYMONGO_SECRET_KEY === '') {
        throw new RuntimeException('No PayMongo secret key is configured.');
    }

    $ch = curl_init("https://api.paymongo.com/v1" . $endpoint);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":"),
        "Content-Type: application/json",
        "Accept: application/json",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("PayMongo connection error: " . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}


/**
 * Build the http(s)://host/platform base for the success and cancel URLs.
 */
function platformBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/platform';
}
