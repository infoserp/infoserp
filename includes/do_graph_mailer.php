<?php
/**
 * Microsoft Graph mail sender (OAuth2 client credentials + Mail.Send).
 * Works with Security Defaults ON — no SMTP basic auth required.
 * Requires PHP curl + openssl on IIS (HTTPS outbound to graph.microsoft.com).
 */

class DoGraphMailer
{
    private $tenantId;
    private $clientId;
    private $clientSecret;
    private $fromEmail;
    private $fromName;
    private $timeout;
    private $log = [];

    /** @var string|null */
    private static $accessToken;

    /** @var int */
    private static $tokenExpiresAt = 0;

    public function __construct(array $config)
    {
        $this->tenantId     = trim((string) ($config['tenant_id'] ?? ''));
        $this->clientId     = trim((string) ($config['client_id'] ?? ''));
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $this->fromEmail    = trim((string) ($config['from_email'] ?? ''));
        $this->fromName     = trim((string) ($config['from_name'] ?? 'Mailer'));
        $this->timeout      = (int) ($config['timeout'] ?? 30);
    }

    public static function curlReady()
    {
        return function_exists('curl_init');
    }

    public static function opensslReady()
    {
        return extension_loaded('openssl');
    }

    /**
     * CA bundle for curl SSL verification on Windows/IIS when php.ini is unset.
     */
    public static function resolveCaBundlePath()
    {
        $candidates = array_filter([
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            'C:\\PHP84\\extras\\ssl\\cacert.pem',
            __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem',
        ]);

        foreach ($candidates as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param string|array $to  One email or list of recipient emails
     * @param string       $subject
     * @param string       $htmlBody
     * @param string|null  $textBody  Unused by Graph (HTML is sent); kept for API parity with SMTP mailer.
     * @param array        $attachments Each: ['name' => '', 'data' => binary, 'mime' => 'application/pdf']
     */
    public function send($to, $subject, $htmlBody, $textBody = null, array $attachments = [])
    {
        unset($textBody);

        $this->requireEnvironment();
        $this->validateConfig();

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

        $token = $this->getAccessToken();
        $user  = rawurlencode($this->fromEmail);
        $url   = 'https://graph.microsoft.com/v1.0/users/' . $user . '/sendMail';

        $toRecipients = [];
        foreach ($recipients as $email) {
            $toRecipients[] = [
                'emailAddress' => ['address' => $email],
            ];
        }

        $message = [
            'subject' => $subject,
            'body'    => [
                'contentType' => 'HTML',
                'content'     => $htmlBody,
            ],
            'from' => [
                'emailAddress' => [
                    'name'    => $this->fromName,
                    'address' => $this->fromEmail,
                ],
            ],
            'toRecipients' => $toRecipients,
        ];

        if (!empty($attachments)) {
            $message['attachments'] = [];
            foreach ($attachments as $file) {
                $name = $file['name'] ?? 'attachment.bin';
                $mime = $file['mime'] ?? 'application/octet-stream';
                $data = $file['data'] ?? '';
                $attachment = [
                    '@odata.type'  => '#microsoft.graph.fileAttachment',
                    'name'         => $name,
                    'contentType'  => $mime,
                    'contentBytes' => base64_encode($data),
                ];
                if (!empty($file['inline']) && !empty($file['content_id'])) {
                    $attachment['isInline']  = true;
                    $attachment['contentId'] = (string) $file['content_id'];
                }
                $message['attachments'][] = $attachment;
            }
        }

        $payload = json_encode([
            'message'         => $message,
            'saveToSentItems' => true,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode Graph mail payload.');
        }

        $this->log[] = '>> POST ' . $url;
        $this->log[] = '>> To: ' . implode(', ', $recipients) . ' | Subject: ' . $subject;

        $response = $this->httpRequest('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload);

        // sendMail returns 202 Accepted with empty body on success.
        if ($response['code'] === 202 || ($response['code'] >= 200 && $response['code'] < 300)) {
            $this->log[] = '<< HTTP ' . $response['code'] . ' — mail accepted by Microsoft Graph';
            return true;
        }

        $this->log[] = '<< HTTP ' . $response['code'] . ' ' . $response['body'];
        $this->throwGraphError($response['code'], $response['body']);
    }

    private function requireEnvironment()
    {
        if (!self::curlReady()) {
            throw new RuntimeException(
                'PHP curl extension is not enabled. In php.ini (IIS), uncomment extension=curl and restart IIS.'
            );
        }
        if (!self::opensslReady()) {
            throw new RuntimeException(
                'PHP OpenSSL extension is not enabled. Required for HTTPS to Microsoft Graph.'
            );
        }
    }

    private function validateConfig()
    {
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException(
                'Graph mail is not configured. Fill tenant_id, client_id, and client_secret in the external mail config file (Approval_config/do_mail_config.php).'
            );
        }
        if ($this->fromEmail === '' || !filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid or missing from_email in Graph mail config.');
        }
    }

    private function getAccessToken()
    {
        if (self::$accessToken !== null && time() < self::$tokenExpiresAt - 60) {
            return self::$accessToken;
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $post     = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]);

        $this->log[] = '>> POST ' . $tokenUrl . ' (client credentials)';

        $response = $this->httpRequest('POST', $tokenUrl, [
            'Content-Type: application/x-www-form-urlencoded',
        ], $post);

        if ($response['code'] !== 200) {
            $this->log[] = '<< HTTP ' . $response['code'] . ' ' . $response['body'];
            throw new RuntimeException(
                'OAuth2 token request failed (HTTP ' . $response['code'] . '). Check tenant_id, client_id, and client_secret.'
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('OAuth2 response did not include access_token.');
        }

        self::$accessToken    = $data['access_token'];
        self::$tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);
        $this->log[]          = '<< Token acquired (expires in ' . (int) ($data['expires_in'] ?? 3600) . 's)';

        return self::$accessToken;
    }

    private function httpRequest($method, $url, array $headers, $body = null)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caBundle = self::resolveCaBundlePath();
        if ($caBundle !== null) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $options);

        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $hint = (stripos($curlError, 'SSL certificate') !== false)
                ? ' Set curl.cainfo and openssl.cafile in the IIS php.ini (see test page php.ini path), then run iisreset.'
                : ' Ensure IIS server can reach the internet on HTTPS port 443.';
            throw new RuntimeException('HTTP request failed: ' . $curlError . '.' . $hint);
        }

        return [
            'code' => $httpCode,
            'body' => (string) $responseBody,
        ];
    }

    private function throwGraphError($httpCode, $body)
    {
        $data    = json_decode($body, true);
        $message = is_array($data) && isset($data['error']['message'])
            ? $data['error']['message']
            : trim($body);

        if ($httpCode === 403 && stripos($message, 'Access denied') !== false) {
            throw new RuntimeException(
                'Graph Mail.Send permission denied. Admin must grant Application permission Mail.Send and click "Grant admin consent" in Azure.'
            );
        }

        if ($httpCode === 404) {
            throw new RuntimeException(
                'Mailbox not found: ' . $this->fromEmail . '. Verify the mailbox exists and is licensed in Exchange Online.'
            );
        }

        throw new RuntimeException('Microsoft Graph sendMail failed (HTTP ' . $httpCode . '): ' . $message);
    }
}
