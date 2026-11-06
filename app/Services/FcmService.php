<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FcmService
{
    /**
     * Mendaftarkan atau memperbarui FCM Device Token untuk User.
     */
    public function registerToken(User $user, string $token, ?string $deviceType = 'android'): void
    {
        $token = trim($token);
        if (empty($token)) {
            return;
        }

        // Pastikan token/FID ini tidak tertaut pada user lain jika perangkat pernah dipakai akun lain
        FcmDeviceToken::where('token', $token)
            ->where('user_id', '!=', $user->id)
            ->delete();

        FcmDeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $token,
            ],
            [
                'device_type' => $deviceType ?? 'android',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Menghapus token perangkat tertentu saat logout.
     */
    public function removeToken(User $user, ?string $token = null): void
    {
        $query = FcmDeviceToken::where('user_id', $user->id);

        if (!empty($token)) {
            $query->where('token', trim($token));
        }

        $query->delete();
    }

    /**
     * Mengirimkan notifikasi push ke seluruh perangkat milik User.
     */
    public function sendToUser(?User $user, string $title, string $body, array $data = []): bool
    {
        if (!$user) {
            return false;
        }

        $tokens = FcmDeviceToken::where('user_id', $user->id)
            ->pluck('token')
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            Log::info("[FCM] User {$user->id} ({$user->username}) tidak memiliki device token terdaftar.");
            return false;
        }

        $success = false;

        foreach ($tokens as $token) {
            $sent = $this->sendToToken($token, $title, $body, $data);
            if ($sent) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Mengirim notifikasi ke token FCM tertentu.
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        $credentialsPath = config('services.firebase.credentials') ?: env('FIREBASE_CREDENTIALS');
        $serverKey = config('services.firebase.server_key') ?: env('FCM_SERVER_KEY');
        $projectId = config('services.firebase.project_id') ?: env('FIREBASE_PROJECT_ID', 'e-teller-bank-mini');

        // Pastikan tidak ada kredensial sensitif atau secret di log
        $payloadData = array_map('strval', $data);

        // Kasus 1: Menggunakan Service Account JSON (FCM HTTP v1 API)
        if ($credentialsPath && file_exists($credentialsPath)) {
            return $this->sendViaHttpV1($credentialsPath, $projectId, $token, $title, $body, $payloadData);
        }

        // Kasus 2: Menggunakan Server Key / Legacy FCM API jika dikonfigurasi
        if ($serverKey) {
            return $this->sendViaLegacyApi($serverKey, $token, $title, $body, $payloadData);
        }

        // Kasus 3: Kredensial belum dikonfigurasi di lingkungan ini (safe no-op)
        Log::info("[FCM] Pengiriman notifikasi push dilewati (Kredensial Firebase belum dikonfigurasi pada FIREBASE_CREDENTIALS): [{$title}] {$body}");
        return false;
    }

    /**
     * Mengirim pesan menggunakan Firebase HTTP v1 API dengan Service Account JSON.
     */
    protected function sendViaHttpV1(
        string $credentialsPath,
        string $projectId,
        string $token,
        string $title,
        string $body,
        array $data
    ): bool {
        try {
            $accessToken = $this->getOAuth2AccessToken($credentialsPath);
            if (!$accessToken) {
                Log::warning("[FCM v1] Gagal memperoleh OAuth2 access token dari service account.");
                return false;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, [
                    'message' => [
                        'fid' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $data,
                        'android' => [
                            'priority' => 'HIGH',
                            'notification' => [
                                'channel_id' => 'transaction_approval',
                                'icon' => 'ic_stat_bank_mini',
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return true;
            }

            // Tangani token tidak valid / unregistered
            $status = $response->status();
            $errorBody = $response->json();
            $errorCode = $errorBody['error']['details'][0]['errorCode'] ?? null;

            if ($status === 404 || $errorCode === 'UNREGISTERED') {
                FcmDeviceToken::where('token', $token)->delete();
                Log::info("[FCM v1] Token tidak aktif/unregistered dihapus: " . substr($token, 0, 15) . "...");
            } else {
                Log::warning("[FCM v1] Respons gagal ({$status}): " . ($errorBody['error']['message'] ?? 'Unknown error'));
            }

            return false;
        } catch (Throwable $e) {
            Log::error("[FCM v1] Exception saat pengiriman: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mengirim notifikasi menggunakan FCM legacy endpoint.
     */
    protected function sendViaLegacyApi(
        string $serverKey,
        string $token,
        string $title,
        string $body,
        array $data
    ): bool {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => 'ic_stat_bank_mini',
                    'sound' => 'default',
                ],
                'data' => $data,
                'priority' => 'high',
            ]);

            if ($response->successful()) {
                $result = $response->json();
                if (!empty($result['failure']) && !empty($result['results'][0]['error'])) {
                    $error = $result['results'][0]['error'];
                    if (in_array($error, ['NotRegistered', 'InvalidRegistration'])) {
                        FcmDeviceToken::where('token', $token)->delete();
                    }
                    return false;
                }
                return true;
            }

            return false;
        } catch (Throwable $e) {
            Log::error("[FCM Legacy] Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Membaca Service Account JSON dan menghasilkan Google OAuth2 Access Token.
     */
    protected function getOAuth2AccessToken(string $credentialsPath): ?string
    {
        try {
            $json = json_decode(file_get_contents($credentialsPath), true);
            if (!$json || empty($json['client_email']) || empty($json['private_key'])) {
                return null;
            }

            $now = time();
            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claimSet = base64_encode(json_encode([
                'iss' => $json['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            $signatureInput = $header . '.' . $claimSet;
            openssl_sign($signatureInput, $signature, $json['private_key'], 'sha256WithRSAEncryption');
            $jwt = $signatureInput . '.' . base64_encode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->json('access_token');
        } catch (Throwable $e) {
            Log::error("[FCM OAuth2] Error generate access token: " . $e->getMessage());
            return null;
        }
    }
}
