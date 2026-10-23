<?php
/**
 * Minimal Microsoft 365 SMTP sender (STARTTLS or SSL).
 * Requires PHP OpenSSL extension (extension=openssl in php.ini for IIS/Apache).
 */

class Ms365Mailer
{
    private $host;
    private $port;
    private $secure;
    private $user;
    private $pass;
    private $fromEmail;
    private $fromName;
    private $socket;
    private $connectTimeout;
    private $log = [];

    public function __construct(array $config)
    {
        $this->host           = $config['smtp_host']   ?? 'smtp.office365.com';
        $this->port           = (int) ($config['smtp_port'] ?? 587);
        $this->secure         = strtolower((string) ($config['smtp_secure'] ?? 'tls'));
        $this->user           = $config['smtp_user']    ?? '';
        $this->pass           = $config['smtp_pass']    ?? '';
        $this->fromEmail      = $config['from_email']   ?? $this->user;
        $this->fromName       = $config['from_name']    ?? 'Mailer';
        $this->connectTimeout = (int) ($config['connect_timeout'] ?? 15);
    }

    public static function opensslReady()
    {
        return extension_loaded('openssl');
    }

    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param string       $to
     * @param string       $subject
     * @param string       $htmlBody
     * @param string|null  $textBody
     * @param array        $attachments  Each: ['name' => '', 'data' => binary, 'mime' => 'application/pdf']
     */
    public function send($to, $subject, $htmlBody, $textBody = null, array $attachments = [])
    {
        if ($this->user === '' || $this->pass === '') {
            throw new RuntimeException('SMTP username/password not configured.');
        }

        $recipients = is_array($to) ? $to : [$to];
        $recipients = array_values(array_unique(array_filter(array_map(function ($email) {
            return trim((string) $email);
        }, $recipients))));

        if (empty($recipients)) {
            throw new InvalidArgumentException('No recipient email address provided.');
        }

        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid recipient email address: ' . $email);
            }
        }

        $this->requireOpenSsl();

        $this->connect();
        $this->expect('220');

        if ($this->secure === 'ssl') {
            // Port 465 — encrypted from the first byte; no STARTTLS step.
            $this->cmd('EHLO ' . $this->ehloHost());
            $this->expect('250');
        } else {
            // Port 587 — plain connect, then STARTTLS.
            $this->cmd('EHLO ' . $this->ehloHost());
            $this->expect('250');
            $this->cmd('STARTTLS');
            $this->expect('220');
            $this->enableStartTls();
            $this->cmd('EHLO ' . $this->ehloHost());
            $this->expect('250');
        }

        $this->cmd('AUTH LOGIN');
        $this->expect('334');
        $this->cmd(base64_encode($this->user));
        $this->expect('334');
        $this->cmd(base64_encode($this->pass));
        $this->expect('235');

        $this->cmd('MAIL FROM:<' . $this->fromEmail . '>');
        $this->expect('250');
        foreach ($recipients as $recipient) {
            $this->cmd('RCPT TO:<' . $recipient . '>');
            $this->expect('250');
        }
        $this->cmd('DATA');
        $this->expect('354');

        $message = $this->buildMessage($recipients, $subject, $htmlBody, $textBody, $attachments);
        fwrite($this->socket, $message . "\r\n.\r\n");
        $this->expect('250');
        $this->cmd('QUIT');
        fclose($this->socket);
        $this->socket = null;

        return true;
    }

    private function requireOpenSsl()
    {
        if (self::opensslReady()) {
            return;
        }

        $ini = php_ini_loaded_file() ?: '(unknown php.ini)';
        throw new RuntimeException(
            'PHP OpenSSL extension is not enabled for this web server. '
            . 'Edit ' . $ini . ' and uncomment extension=openssl (or extension=php_openssl.dll), '
            . 'then restart IIS/Apache.'
        );
    }

    private function sslContext()
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'peer_name'         => $this->host,
                'allow_self_signed' => false,
            ],
        ]);
    }

    private function connect()
    {
        $errno  = 0;
        $errstr = '';
        $ctx    = $this->sslContext();

        if ($this->secure === 'ssl') {
            $target = 'ssl://' . $this->host . ':' . $this->port;
        } else {
            $target = 'tcp://' . $this->host . ':' . $this->port;
        }

        $this->socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$this->socket) {
            $hint = ($this->secure === 'ssl' && $this->port === 465)
                ? ' Microsoft 365 does not support port 465 — use port 587 with smtp_secure=tls.'
                : '';
            throw new RuntimeException('SMTP connect failed (' . $target . '): ' . $errstr . ' (' . $errno . ').' . $hint);
        }

        stream_set_timeout($this->socket, $this->connectTimeout);
    }

    private function tlsCryptoMethods()
    {
        $method = 0;
        foreach ([
            'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
            'STREAM_CRYPTO_METHOD_TLS_CLIENT',
        ] as $const) {
            if (defined($const)) {
                $method |= constant($const);
            }
        }

        return $method ?: STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }

    private function enableStartTls()
    {
        $cryptoType = $this->tlsCryptoMethods();
        $lastError  = '';

        set_error_handler(function ($severity, $message) use (&$lastError) {
            $lastError = $message;
            return true;
        });

        $ok = stream_socket_enable_crypto($this->socket, true, $cryptoType);
        restore_error_handler();

        if (!$ok) {
            $detail = $lastError !== '' ? $lastError : 'unknown TLS error';
            throw new RuntimeException(
                'Could not enable STARTTLS: ' . $detail
                . '. If this persists, set smtp_port=465 and smtp_secure=ssl in the external mail config file.'
            );
        }
    }

    private function ehloHost()
    {
        if (!empty($_SERVER['SERVER_NAME'])) {
            return preg_replace('/[^a-zA-Z0-9\.\-]/', '', $_SERVER['SERVER_NAME']);
        }
        return 'localhost';
    }

    private function cmd($line)
    {
        $this->log[] = '>> ' . (stripos($line, 'AUTH LOGIN') === 0 || preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $line) ? '[redacted]' : $line);
        fwrite($this->socket, $line . "\r\n");
    }

    private function expect($code)
    {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException(
                        'SMTP timed out after ' . $this->connectTimeout . 's waiting for ' . $code
                        . '. Check firewall allows outbound TCP port ' . $this->port . ' to ' . $this->host . '.'
                    );
                }
                throw new RuntimeException('SMTP connection closed while waiting for ' . $code);
            }

            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $this->log[] = '<< ' . trim($response);
        if ($response === '' || strpos($response, $code) !== 0) {
            throw new RuntimeException('SMTP error (expected ' . $code . '): ' . trim($response));
        }
    }

    private function buildMessage($to, $subject, $htmlBody, $textBody, array $attachments)
    {
        $recipients = is_array($to) ? $to : [$to];
        $toHeader   = implode(', ', $recipients);

        $inlineAttachments = [];
        $fileAttachments   = [];
        foreach ($attachments as $file) {
            if (!empty($file['inline']) && !empty($file['content_id'])) {
                $inlineAttachments[] = $file;
            } else {
                $fileAttachments[] = $file;
            }
        }

        $boundary     = 'b_' . bin2hex(random_bytes(8));
        $relatedBoundary = 'r_' . bin2hex(random_bytes(8));
        $altBoundary  = 'a_' . bin2hex(random_bytes(8));
        $date         = date('r');
        $messageId    = '<' . bin2hex(random_bytes(12)) . '@' . $this->ehloHost() . '>';
        $encodedFrom  = $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';

        if ($textBody === null) {
            $textBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody)));
        }

        $headers = [
            'Date: ' . $date,
            'From: ' . $encodedFrom,
            'To: ' . $toHeader,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];

        if (empty($fileAttachments) && empty($inlineAttachments)) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
            $body  = "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textBody . "\r\n\r\n";
            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= "--{$altBoundary}--";
        } else {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body  = "--{$boundary}\r\n";

            if (!empty($inlineAttachments)) {
                $body .= 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"' . "\r\n\r\n";
                $body .= "--{$relatedBoundary}\r\n";
            }

            $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textBody . "\r\n\r\n";
            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlBody . "\r\n\r\n";
            $body .= "--{$altBoundary}--\r\n\r\n";

            foreach ($inlineAttachments as $file) {
                $name = $file['name'] ?? 'inline.bin';
                $mime = $file['mime'] ?? 'application/octet-stream';
                $data = $file['data'] ?? '';
                $cid  = trim((string) ($file['content_id'] ?? 'inline-image'));
                $body .= "--{$relatedBoundary}\r\n";
                $body .= 'Content-Type: ' . $mime . '; name="' . $this->escapeQuotes($name) . "\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= 'Content-ID: <' . $this->escapeQuotes($cid) . ">\r\n";
                $body .= "Content-Disposition: inline; filename=\"" . $this->escapeQuotes($name) . "\"\r\n\r\n";
                $body .= chunk_split(base64_encode($data), 76, "\r\n") . "\r\n";
            }

            if (!empty($inlineAttachments)) {
                $body .= "--{$relatedBoundary}--\r\n\r\n";
            }

            foreach ($fileAttachments as $file) {
                $name = $file['name'] ?? 'attachment.bin';
                $mime = $file['mime'] ?? 'application/octet-stream';
                $data = $file['data'] ?? '';
                $body .= "--{$boundary}\r\n";
                $body .= 'Content-Type: ' . $mime . '; name="' . $this->escapeQuotes($name) . "\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . $this->escapeQuotes($name) . "\"\r\n\r\n";
                $body .= chunk_split(base64_encode($data), 76, "\r\n") . "\r\n";
            }

            $body .= "--{$boundary}--";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function encodeHeader($value)
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function escapeQuotes($value)
    {
        return str_replace('"', '', $value);
    }
}
