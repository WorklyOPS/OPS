<?php
/**
 * Native PHP Gmail OAuth 2.0 service.
 * Uses cURL and Google's OAuth/Gmail REST endpoints; no Composer SDK required.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';

final class GmailOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const PROFILE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    public static function scopes(): array
    {
        return [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar.events',
        ];
    }

    /** Build Google's OAuth authorization URL. */
    public static function createAuthorizationUrl(string $state): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $state)) {
            throw new InvalidArgumentException('Invalid OAuth state.');
        }

        [$clientId, , $redirectUri] = self::credentials();
        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::scopes()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent select_account',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange an OAuth authorization code for tokens. */
    public static function exchangeAuthorizationCode(string $code): array
    {
        if ($code === '') {
            throw new InvalidArgumentException('Google authorization code is missing.');
        }

        [$clientId, $clientSecret, $redirectUri] = self::credentials();

        return self::postTokenRequest([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
    }

    /** Refresh an expired access token using the stored refresh token. */
    public static function refreshAccessToken(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw new InvalidArgumentException('Google refresh token is missing.');
        }

        [$clientId, $clientSecret] = self::credentials();

        return self::postTokenRequest([
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
        ]);
    }

    private static function postTokenRequest(array $parameters): array
    {
        $response = self::httpRequest(self::TOKEN_URL, 'POST', [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));

        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            $reason = is_array($data)
                ? (string) ($data['error_description'] ?? $data['error'] ?? 'Google OAuth request failed.')
                : 'Google OAuth returned an invalid response.';
            throw new RuntimeException($reason);
        }

        if (empty($data['access_token'])) {
            throw new RuntimeException('Google did not return an access token.');
        }

        return $data;
    }

    public static function fetchProfile(string $accessToken): array
    {
        $response = self::httpRequest(self::PROFILE_URL, 'GET', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException('Gmail profile could not be retrieved. Check Gmail API access and OAuth scopes.');
        }

        return $data;
    }

    /** Shared cURL transport with TLS verification and bounded timeouts. */
    private static function httpRequest(string $url, string $method, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for Gmail integration.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Google API request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if (!is_string($raw)) {
            throw new RuntimeException('Google API request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
        }

        return ['status' => $status, 'body' => $raw];
    }

    /** Build RFC 2822 base64url raw MIME message with plain + HTML alternative bodies. */
    public static function buildRawMime(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyPlain,
        string $bodyHtml = ''
    ): string {
        $safeSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $safeFromName = str_replace(["\r", "\n", '"'], '', $fromName);
        $headers = [
            "From: \"{$safeFromName}\" <{$fromEmail}>",
            "To: {$toEmail}",
            "Subject: {$safeSubj}",
            'MIME-Version: 1.0',
        ];

        $hasHtml = trim($bodyHtml) !== '';
        if (!$hasHtml) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($bodyPlain));
        } else {
            $altBoundary = 'wrk_alt_' . bin2hex(random_bytes(9));
            $htmlWrapped = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;">'
                . $bodyHtml
                . '</body></html>';
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"";
            $parts = [
                "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyPlain)),
                "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($htmlWrapped)),
                "--{$altBoundary}--",
            ];
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Attempt to deliver an email via the specified Workly user's connected Gmail OAuth mailbox. */
    public static function sendEmailViaMailbox(
        PDO $db,
        string $senderUserId,
        string $senderFallbackName,
        string $toEmail,
        string $subject,
        string $bodyPlain,
        string $bodyHtml = ''
    ): array {
        $result = [
            'sent'             => false,
            'sender_email'     => null,
            'gmail_message_id' => null,
            'gmail_thread_id'  => null,
            'error'            => null,
        ];

        if ($senderUserId === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['error'] = 'Invalid sender or recipient email';
            return $result;
        }

        try {
            $stmt = $db->prepare("
                SELECT email, oauth_status, oauth_scope, access_token_encrypted, refresh_token_encrypted, token_expires_at
                FROM staff_connected_mailboxes
                WHERE user_id = :uid AND oauth_status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':uid' => $senderUserId]);
            $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mailbox || empty($mailbox['refresh_token_encrypted'])) {
                $result['error'] = 'No active Gmail OAuth mailbox connected';
                return $result;
            }

            $scope = (string) ($mailbox['oauth_scope'] ?? '');
            if ($scope !== '' && !str_contains($scope, 'gmail.send') && !str_contains($scope, 'mail.google.com')) {
                $result['error'] = 'Connected Google account lacks gmail.send scope';
                return $result;
            }

            $accessToken = self::decrypt((string) ($mailbox['access_token_encrypted'] ?? ''));
            $refreshToken = self::decrypt((string) ($mailbox['refresh_token_encrypted'] ?? ''));
            $expiresAt = !empty($mailbox['token_expires_at']) ? strtotime((string) $mailbox['token_expires_at']) : 0;

            if (($accessToken === '' || $expiresAt <= time() + 60) && $refreshToken !== '') {
                $token = self::refreshAccessToken($refreshToken);
                $accessToken = (string) ($token['access_token'] ?? '');
                if ($accessToken !== '') {
                    $newRefresh = (string) ($token['refresh_token'] ?? $refreshToken);
                    $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
                    $db->prepare("
                        UPDATE staff_connected_mailboxes
                        SET access_token_encrypted = :at,
                            refresh_token_encrypted = :rt,
                            token_expires_at = NOW() + (CAST(:ei AS INTEGER) * INTERVAL '1 second'),
                            token_type = :tt,
                            updated_at = NOW()
                        WHERE user_id = :uid
                    ")->execute([
                        ':at'  => self::encrypt($accessToken),
                        ':rt'  => self::encrypt($newRefresh),
                        ':ei'  => $expiresIn,
                        ':tt'  => (string) ($token['token_type'] ?? 'Bearer'),
                        ':uid' => $senderUserId,
                    ]);
                }
            }

            if ($accessToken === '') {
                $result['error'] = 'Unable to obtain Gmail access token';
                return $result;
            }

            $fromEmail = (string) ($mailbox['email'] ?? '');
            $result['sender_email'] = $fromEmail;
            $rawMime = self::buildRawMime($fromEmail, $senderFallbackName, $toEmail, $subject, $bodyPlain, $bodyHtml);

            $resp = self::httpRequest(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
                'POST',
                [
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                json_encode(['raw' => $rawMime]) ?: '{}'
            );
            $decoded = json_decode($resp['body'], true);
            if ($resp['status'] >= 200 && $resp['status'] < 300 && is_array($decoded) && !empty($decoded['id'])) {
                $result['sent'] = true;
                $result['gmail_message_id'] = (string) $decoded['id'];
                $result['gmail_thread_id'] = !empty($decoded['threadId']) ? (string) $decoded['threadId'] : null;
                return $result;
            }
            $result['error'] = is_array($decoded) && !empty($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : ('Gmail API HTTP ' . $resp['status']);
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            error_log('Workly Gmail sendEmailViaMailbox error: ' . $e->getMessage());
        }

        return $result;
    }

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt Gmail OAuth token.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);
        $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return is_string($plainText) ? $plainText : '';
    }

    private static function credentials(): array
    {
        $clientId = Secrets::get('GOOGLE_CLIENT_ID');
        $clientSecret = Secrets::get('GOOGLE_CLIENT_SECRET');
        $redirectUri = Secrets::get('GOOGLE_REDIRECT_URI');

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new RuntimeException('Google OAuth environment variables are not configured.');
        }

        return [$clientId, $clientSecret, $redirectUri];
    }

    private static function encryptionKey(): string
    {
        $configured = Secrets::get('GMAIL_TOKEN_ENCRYPTION_KEY');
        if ($configured === '' || str_starts_with($configured, 'REPLACE_WITH_')) {
            throw new RuntimeException('GMAIL_TOKEN_ENCRYPTION_KEY is not configured.');
        }

        return hash('sha256', $configured, true);
    }
}
