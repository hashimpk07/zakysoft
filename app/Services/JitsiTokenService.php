<?php

namespace App\Services;

use App\Log;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;

class JitsiTokenService
{
    private const PRIVATE_KEY_PATH = 'app/jitsi_private_key.pem'; // storage_path segment

    public static function generateToken(string $room, string $userName, string $email, bool $moderator = false, bool $audioOnly = false): array
    {
        $privateKey = file_get_contents(storage_path(self::PRIVATE_KEY_PATH));

        $appId = config('services.jitsi.app');
        $secret = config('services.jitsi.app_secret');

        // room must include AppID prefix for JaaS
        $fullRoomName = $appId . '/' . $room;

        $payload = [
            'iss' => 'chat',
            'aud' => 'jitsi',
            'exp' => time() + 3600,
            'nbf' => time(),
            'sub' => $appId,
            'room' => $room, // or '*' for any room
            'context' => [
                'user' => [
                    'id' => bin2hex(random_bytes(8)),
                    'name' => $userName,
                    'email' => $email,
                    // For JaaS docs, moderator is typically "true"/"false" strings, but booleans also work in practice
                    'moderator' => $moderator ? 'true' : 'false',
                ],
                'features' => [
                    'livestreaming' => 'false',
                    'file-upload' => 'false',
                    'outbound-call' => 'false',
                    'sip-outbound-call' => 'false',
                    'transcription' => 'false',
                    'list-visitors' => 'false',
                    'recording' => 'false',
                    'flip' => 'false',
                ],
            ],
        ];

        // explicit header with kid
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $secret,
        ];
        $token = JWT::encode($payload, $privateKey, 'RS256', null, $header);

        return [
            'token' => $token,
            'room' => $fullRoomName,
        ];
    }

    /**
     * Verify the JaaS webhook signature.
     */
    public static function verifySignature(Request $request): bool
    {
        $secret = config('services.jitsi.webhook_secret', env('JITSI_WEBHOOK_SECRET'));
        $signatureHeader = $request->header('X-Jaas-Signature');

        if (!$signatureHeader || !$secret) {
            Log::channel('jaas_webhook')->warning('JaaS Webhook: Missing signature header or secret.', [
                'headers' => $request->headers->all(),
                'signature' => $signatureHeader,
                'secret' => $secret,
            ]);
            return false;
        }

        // Parse the header: t=timestamp,v1=signature
        $elements = collect(explode(',', $signatureHeader))->mapWithKeys(function ($element) {
            [$key, $value] = explode('=', $element, 2);
            return [$key => $value];
        });

        $timestamp = $elements['t'] ?? null;
        $signature = $elements['v1'] ?? null;

        if (!$timestamp || !$signature) {
            Log::channel('jaas_webhook')->warning('JaaS Webhook: Invalid signature header format.', [
                'headers' => $request->headers->all(),
                'signature' => $signatureHeader,
                'secret' => $secret,
            ]);
            return false;
        }

        // Prepare signed payload: timestamp + '.' + raw body
        $payload = "{$timestamp}.{$request->getContent()}";

        // Compute expected signature (base64-encoded HMAC-SHA256)
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        // Optional: timestamp tolerance check (e.g., 5 minutes)
        $tolerance = 5 * 60; // 5 minutes
        if (abs(time() - (int) $timestamp) > $tolerance) {

            Log::channel('jaas_webhook')->warning('JaaS Webhook: Timestamp outside tolerance window.', [
                'headers' => $request->headers->all(),
                'signature' => $signatureHeader,
                'secret' => $secret,
            ]);
            
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            Log::channel('jaas_webhook')->warning('JaaS Webhook: Signature mismatch.', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
        }

        return $isValid;
    }
}
