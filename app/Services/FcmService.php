<?php

namespace App\Services;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FcmService
{
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const FCM_SCOPE =
        'https://www.googleapis.com/auth/firebase.messaging';

    private const FCM_BASE_URL =
        'https://fcm.googleapis.com/v1/projects/';

    /**
     * Mendaftarkan atau memperbarui FCM device token milik User.
     */
    public function registerToken(
        User $user,
        string $token,
        ?string $deviceType = 'android'
    ): void {
        $token = trim($token);

        if ($token === '') {
            return;
        }

        // Satu token perangkat hanya boleh terkait dengan satu user.
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
            ]
        );
    }

    /**
     * Menghapus token perangkat milik User saat logout.
     *
     * Jika token tidak diberikan, seluruh token milik User dihapus.
     */
    public function removeToken(
        User $user,
        ?string $token = null
    ): void {
        $query = FcmDeviceToken::where('user_id', $user->id);

        if ($token !== null && trim($token) !== '') {
            $query->where('token', trim($token));
        }

        $query->delete();
    }

    /**
     * Mengirim notifikasi ke seluruh perangkat milik User.
     *
     * Mengembalikan true jika minimal satu pengiriman berhasil.
     */
    public function sendToUser(
        ?User $user,
        string $title,
        string $body,
        array $data = []
    ): bool {
        if (!$user) {
            return false;
        }

        $tokens = FcmDeviceToken::where('user_id', $user->id)
            ->whereNotNull('token')
            ->pluck('token')
            ->filter(
                fn ($token) => is_string($token) && trim($token) !== ''
            )
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            Log::info(
                "[FCM] Tidak ada device token terdaftar untuk user ID {$user->id}."
            );

            return false;
        }

        $success = false;

        foreach ($tokens as $token) {
            if ($this->sendToToken($token, $title, $body, $data)) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Mengirim notifikasi ke satu token menggunakan FCM HTTP v1.
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): bool {
        $token = trim($token);

        if ($token === '') {
            Log::warning('[FCM] Pengiriman dibatalkan: token kosong.');

            return false;
        }

        $credentialsPath = config('services.firebase.credentials');

        $projectId = config('services.firebase.project_id');

        if (
            !is_string($credentialsPath)
            || trim($credentialsPath) === ''
        ) {
            Log::error(
                '[FCM] FIREBASE_CREDENTIALS belum dikonfigurasi.'
            );

            return false;
        }

        if (
            !is_string($projectId)
            || trim($projectId) === ''
        ) {
            Log::error(
                '[FCM] FIREBASE_PROJECT_ID belum dikonfigurasi.'
            );

            return false;
        }

        if (!is_file($credentialsPath) || !is_readable($credentialsPath)) {
            Log::error(
                '[FCM] File kredensial tidak ditemukan atau tidak dapat dibaca.'
            );

            return false;
        }

        $payloadData = $this->normalizeData($data);

        return $this->sendViaHttpV1(
            $credentialsPath,
            $projectId,
            $token,
            $title,
            $body,
            $payloadData
        );
    }

    /**
     * Mengirim pesan menggunakan Firebase Cloud Messaging HTTP v1 API.
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
            $credentials = $this->loadCredentials($credentialsPath);

            if ($credentials === null) {
                Log::error(
                    '[FCM v1] File service account tidak valid.'
                );

                return false;
            }

            // Pastikan service account berasal dari proyek yang sesuai.
            if (($credentials['project_id'] ?? null) !== $projectId) {
                Log::error(
                    '[FCM v1] Project ID pada kredensial tidak sesuai dengan konfigurasi.'
                );

                return false;
            }

            $accessToken = $this->getOAuth2AccessToken($credentials);

            if ($accessToken === null) {
                Log::error(
                    '[FCM v1] Gagal memperoleh OAuth2 access token.'
                );

                return false;
            }

            $url = self::FCM_BASE_URL
                . rawurlencode($projectId)
                . '/messages:send';

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url, [
                    'message' => [
                        // Field tujuan FCM HTTP v1 yang benar.
                        'token' => $token,

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
                Log::info(
                    '[FCM v1] Permintaan pengiriman notifikasi berhasil diterima Firebase.'
                );

                return true;
            }

            $status = $response->status();
            $errorBody = $response->json();

            $errorMessage = data_get(
                $errorBody,
                'error.message',
                'Unknown Firebase error'
            );

            $errorStatus = data_get(
                $errorBody,
                'error.status',
                ''
            );

            // FCM dapat memberikan errorCode melalui error details.
            $details = data_get(
                $errorBody,
                'error.details',
                []
            );

            $errorCodes = [];

            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (isset($detail['errorCode'])) {
                        $errorCodes[] = $detail['errorCode'];
                    }
                }
            }

            // Hapus token hanya jika Firebase menyatakan token tidak
            // terdaftar. Jangan menghapus token hanya karena HTTP 404.
            if (
                in_array('UNREGISTERED', $errorCodes, true)
                || $errorStatus === 'UNREGISTERED'
            ) {
                FcmDeviceToken::where('token', $token)->delete();

                Log::warning(
                    '[FCM v1] Token perangkat tidak terdaftar lagi dan telah dihapus.'
                );

                return false;
            }

            Log::warning(
                "[FCM v1] Pengiriman gagal. HTTP {$status}; "
                . "status={$errorStatus}; pesan={$errorMessage}"
            );

            return false;
        } catch (ConnectionException $e) {
            Log::error(
                '[FCM v1] Koneksi ke layanan Firebase gagal atau timeout.'
            );

            return false;
        } catch (Throwable $e) {
            // Jangan mencatat isi kredensial atau token perangkat.
            Log::error(
                '[FCM v1] Terjadi kesalahan internal: '
                . get_class($e)
            );

            return false;
        }
    }

    /**
     * Membaca dan memvalidasi service account JSON.
     */
    protected function loadCredentials(string $credentialsPath): ?array
    {
        try {
            $contents = file_get_contents($credentialsPath);

            if ($contents === false || trim($contents) === '') {
                return null;
            }

            $credentials = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($credentials)) {
                return null;
            }

            if (($credentials['type'] ?? null) !== 'service_account') {
                return null;
            }

            if (
                empty($credentials['project_id'])
                || empty($credentials['client_email'])
                || empty($credentials['private_key'])
                || empty($credentials['token_uri'])
            ) {
                return null;
            }

            if (
                !filter_var(
                    $credentials['client_email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                return null;
            }

            // Gunakan hanya endpoint token Google yang diharapkan.
            if (
                $credentials['token_uri'] !== self::OAUTH_TOKEN_URL
            ) {
                return null;
            }

            return $credentials;
        } catch (Throwable $e) {
            Log::error(
                '[FCM] Kredensial JSON tidak dapat dibaca atau divalidasi.'
            );

            return null;
        }
    }

    /**
     * Menghasilkan Google OAuth2 access token dari service account.
     *
     * JWT harus menggunakan Base64 URL-safe, bukan Base64 standar.
     */
    protected function getOAuth2AccessToken(
        array $credentials
    ): ?string {
        $privateKeyResource = null;

        try {
            $privateKeyResource = openssl_pkey_get_private(
                $credentials['private_key']
            );

            if ($privateKeyResource === false) {
                Log::error(
                    '[FCM OAuth2] Private key tidak dapat dibaca oleh OpenSSL.'
                );

                return null;
            }

            $now = time();

            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT',
            ];

            $claims = [
                'iss' => $credentials['client_email'],
                'scope' => self::FCM_SCOPE,
                'aud' => self::OAUTH_TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $encodedHeader = $this->base64UrlEncode(
                json_encode($header, JSON_THROW_ON_ERROR)
            );

            $encodedClaims = $this->base64UrlEncode(
                json_encode($claims, JSON_THROW_ON_ERROR)
            );

            $signatureInput = $encodedHeader . '.' . $encodedClaims;

            $signed = openssl_sign(
                $signatureInput,
                $signature,
                $privateKeyResource,
                OPENSSL_ALGO_SHA256
            );

            if (!$signed) {
                Log::error(
                    '[FCM OAuth2] Gagal menandatangani JWT.'
                );

                return null;
            }

            $jwt = $signatureInput . '.' . $this->base64UrlEncode(
                $signature
            );

            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(self::OAUTH_TOKEN_URL, [
                    'grant_type' =>
                        'urn:ietf:params:oauth:grant-type:jwt-bearer',

                    'assertion' => $jwt,
                ]);

            if (!$response->successful()) {
                Log::warning(
                    '[FCM OAuth2] Google menolak permintaan token. HTTP '
                    . $response->status()
                    . '; status='
                    . $response->json('error', 'unknown_error')
                );

                return null;
            }

            $accessToken = $response->json('access_token');

            if (
                !is_string($accessToken)
                || trim($accessToken) === ''
            ) {
                Log::warning(
                    '[FCM OAuth2] Respons Google tidak berisi access token yang valid.'
                );

                return null;
            }

            return $accessToken;
        } catch (ConnectionException $e) {
            Log::error(
                '[FCM OAuth2] Koneksi ke layanan OAuth Google gagal atau timeout.'
            );

            return null;
        } catch (Throwable $e) {
            Log::error(
                '[FCM OAuth2] Terjadi kesalahan internal: '
                . get_class($e)
            );

            return null;
        } finally {
            if ($privateKeyResource !== null && $privateKeyResource !== false) {
                openssl_pkey_free($privateKeyResource);
            }
        }
    }

    /**
     * Mengubah data tambahan menjadi pasangan string.
     *
     * FCM HTTP v1 mengharuskan nilai data berupa string.
     * Nilai array, object, dan resource tidak dikirim.
     */
    protected function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    /**
     * Mengodekan data menggunakan Base64 URL-safe untuk JWT.
     */
    protected function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }
}