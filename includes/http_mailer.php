<?php
/*
|--------------------------------------------------------------------------
| MAIL THAT LEAVES OVER HTTPS
|--------------------------------------------------------------------------
|
| The deployed host blocks outbound SMTP. Every mail port -- 587, 465, 25,
| 2525 -- answers "Connection timed out", while 443 opens immediately: it
| reaches the internet and refuses this one protocol. tools/mail_status.php
| asks the host directly and prints that answer, which is how we know it
| rather than suspect it. The credentials were never the problem; two real
| messages sent from a laptop with the same username and password proved
| that.
|
| So there is nothing to fix in the SMTP settings, and nothing that could be.
| Mail has to go out the way the chatbot's requests already do: a POST to a
| provider, on 443, indistinguishable from any other web request.
|
| WHY THIS IS A PHPMailer
|
| Around a dozen pages send mail, and each one does the same four things:
| getMailer(), set a subject and a body, addAddress(), send(). None of them
| should learn a second way to do it -- a parallel sendMailByApi() would mean
| every one of those pages choosing a transport, and the ones nobody
| remembered to change would keep failing silently, which is the fault we are
| here to fix.
|
| PHPMailer has a hook for exactly this. postSend() dispatches on $Mailer,
| and for a name it does not recognise it calls {$Mailer}Send(). Setting the
| name to "brevo" and writing brevoSend() puts the API call where smtpSend()
| would have been, and leaves everything in front of it untouched: preSend()
| still validates the addresses, still rejects a message with no recipient,
| still refuses a malformed From. We replace the last step only.
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/mail_settings.php';

if (!class_exists('HttpApiMailer')) {

    class HttpApiMailer extends PHPMailer
    {
        /* Brevo's transactional endpoint. */
        public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

        /*
        | A send has to finish inside the request that triggered it. Ten
        | seconds is generous for one POST and still well short of a page
        | that looks hung to the person who submitted the form.
        */
        public const TIMEOUT_MS = 10000;

        public function __construct($exceptions = true)
        {
            parent::__construct($exceptions);

            /* The name that routes postSend() to brevoSend(). */
            $this->Mailer = 'brevo';

            /* A n-tilde in an owner's name should not arrive as "?". */
            $this->CharSet = 'UTF-8';
            $this->isHTML(true);
        }

        /**
         * The message, in the shape Brevo's API accepts.
         *
         * Public and separate from the sending so it can be read in a test
         * without a key, a network, or a message anybody receives.
         */
        public function brevoPayload(): array
        {
            $payload = [
                'sender' => array_filter([
                    'email' => $this->From,
                    'name' => $this->FromName !== '' ? $this->FromName : null,
                ]),
                'to' => $this->brevoRecipients($this->getToAddresses()),
                'subject' => $this->Subject,
            ];

            /*
            | isHTML(true) is the default here, as it is in both getMailer()s,
            | so Body is HTML and AltBody is the plain-text alternative. A
            | caller who set isHTML(false) means Body to be read as text, and
            | sending it as htmlContent would show its angle brackets as
            | markup.
            */
            if ($this->ContentType === static::CONTENT_TYPE_PLAINTEXT) {
                $payload['textContent'] = $this->Body;
            } else {
                $payload['htmlContent'] = $this->Body;

                if (trim((string) $this->AltBody) !== '') {
                    $payload['textContent'] = $this->AltBody;
                }
            }

            foreach (['cc' => $this->getCcAddresses(),
                      'bcc' => $this->getBccAddresses()] as $field => $addresses) {

                $list = $this->brevoRecipients($addresses);

                if ($list !== []) {
                    $payload[$field] = $list;
                }
            }

            /*
            | Brevo takes a single replyTo, not a list. PHPMailer allows
            | several; the first is the one that can be honoured, and
            | quietly dropping the rest is better than a rejected request.
            */
            $replyTo = $this->brevoRecipients($this->getReplyToAddresses());

            if ($replyTo !== []) {
                $payload['replyTo'] = $replyTo[0];
            }

            $attachments = $this->brevoAttachments();

            if ($attachments !== []) {
                $payload['attachment'] = $attachments;
            }

            return $payload;
        }

        /**
         * PHPMailer's address pairs as Brevo's objects.
         *
         * getToAddresses() gives [[email, name], ...]; getReplyToAddresses()
         * gives the same pairs keyed by address. Both iterate the same way.
         */
        private function brevoRecipients(array $addresses): array
        {
            $out = [];

            foreach ($addresses as $address) {

                $email = trim((string) ($address[0] ?? ''));

                if ($email === '') {
                    continue;
                }

                $person = ['email' => $email];

                $name = trim((string) ($address[1] ?? ''));

                if ($name !== '') {
                    $person['name'] = $name;
                }

                $out[] = $person;
            }

            return $out;
        }

        /**
         * Attachments, base64 encoded.
         *
         * HR mail carries contracts and payslips, so this is not decoration.
         * Each of PHPMailer's attachment rows is [content-or-path, filename,
         * name, encoding, type, isString, disposition, cid]; an inline image
         * has a cid and belongs in the body, not in this list.
         */
        private function brevoAttachments(): array
        {
            $out = [];

            foreach ($this->getAttachments() as $attachment) {

                if (($attachment[6] ?? 'attachment') !== 'attachment') {
                    continue;
                }

                $name = (string) ($attachment[2] ?? '');

                if (!empty($attachment[5])) {
                    /* Already a string in memory. */
                    $content = (string) $attachment[0];
                } else {
                    $path = (string) $attachment[0];

                    if (!is_readable($path)) {
                        throw new Exception('Attachment not readable: ' . $name);
                    }

                    $content = (string) file_get_contents($path);
                }

                $out[] = [
                    'name' => $name !== '' ? $name : 'attachment',
                    'content' => base64_encode($content),
                ];
            }

            return $out;
        }

        /**
         * The send itself.
         *
         * Named for $Mailer, and called by PHPMailer's postSend() once
         * preSend() has validated the message. The two arguments are the
         * MIME headers and body every other transport needs; this one builds
         * its own request from the properties instead, so they go unused.
         *
         * @param string $header
         * @param string $body
         *
         * @throws Exception
         *
         * @return bool
         */
        protected function brevoSend($header, $body)
        {
            $key = mailApiKey();

            if ($key === '') {
                throw new Exception(
                    'No BREVO_API_KEY is set, so mail cannot be sent over HTTP.'
                );
            }

            if (!function_exists('curl_init')) {
                throw new Exception(
                    'The cURL extension is missing, so mail cannot be sent over HTTP.'
                );
            }

            $json = json_encode($this->brevoPayload(), JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                throw new Exception('The message could not be encoded: ' . json_last_error_msg());
            }

            $curl = curl_init(static::ENDPOINT);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_TIMEOUT_MS => static::TIMEOUT_MS,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_HTTPHEADER => [
                    'api-key: ' . $key,
                    'content-type: application/json',
                    'accept: application/json',
                ],
            ]);

            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $transportError = curl_error($curl);
            curl_close($curl);

            if ($response === false || $status === 0) {
                throw new Exception(
                    'The mail API could not be reached: '
                    . ($transportError !== '' ? $transportError : 'no response')
                );
            }

            /* 201 Created is the documented success; 2xx generally. */
            if ($status >= 200 && $status < 300) {
                $this->edebug('Mail accepted by the API: ' . trim((string) $response));

                return true;
            }

            /*
            | The provider's own words, carried through. A rejected sender, an
            | expired key and an exhausted quota all arrive as one HTTP status
            | each, and the sentence beside it is the only thing that says
            | which -- the same reason chatAnthropicTurn() keeps the API's
            | message rather than replacing it with its own.
            */
            $detail = '';
            $decoded = json_decode((string) $response, true);

            if (is_array($decoded)) {
                $detail = trim((string) ($decoded['message'] ?? ''));

                if ($detail !== '' && !empty($decoded['code'])) {
                    $detail = $decoded['code'] . ': ' . $detail;
                }
            }

            if ($detail === '') {
                $detail = trim(substr((string) $response, 0, 300));
            }

            throw new Exception(
                'The mail API refused the message (HTTP ' . $status . '): ' . $detail
            );
        }
    }
}
