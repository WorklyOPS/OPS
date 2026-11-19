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
        curl_close($ch);

        if (!is_string($raw)) {
            throw new RuntimeException('Google API request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
        }

        return ['status' => $status, 'body' => $raw];
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
