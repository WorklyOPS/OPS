<?php
/**
 * Server-side Google OAuth2 support for Workly Gmail integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

final class GmailOAuthService
{
    public static function scopes(): array
    {
        return [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];
    }

    public static function client(): Google\Client
    {
        if (!class_exists(Google\Client::class)) {
            throw new RuntimeException('Google API Client is missing. Run composer install during deployment.');
        }
        $clientId = Secrets::get('GOOGLE_CLIENT_ID');
        $clientSecret = Secrets::get('GOOGLE_CLIENT_SECRET');
        $redirectUri = Secrets::get('GOOGLE_REDIRECT_URI');
        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new RuntimeException('Google OAuth environment variables are not configured.');
        }

        $client = new Google\Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setScopes(self::scopes());
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);
        $client->setPrompt('consent select_account');
        return $client;
    }

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
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

    public static function fetchProfile(string $accessToken): array
    {
        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/profile');
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Gmail profile request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('Gmail profile could not be retrieved. Check Gmail API access and OAuth scopes.');
        }
        return $data;
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
