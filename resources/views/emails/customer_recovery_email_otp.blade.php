<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi Email Pemulihan</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6fa;
            margin: 0;
            padding: 24px;
            color: #1e293b;
        }
        .container {
            max-width: 540px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #003fb3;
            color: #ffffff;
            padding: 28px 32px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 6px 0 0 0;
            font-size: 13px;
            opacity: 0.9;
        }
        .body {
            padding: 32px;
        }
        .greeting {
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .otp-box {
            background-color: #f0f4ff;
            border: 2px dashed #003fb3;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin: 28px 0;
        }
        .otp-code {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #003fb3;
            font-family: monospace, sans-serif;
        }
        .otp-caption {
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
        }
        .warning-card {
            background-color: #fff7ed;
            border-left: 4px solid #ea580c;
            padding: 14px 16px;
            border-radius: 6px;
            margin: 24px 0;
            font-size: 13px;
            line-height: 1.5;
            color: #9a3412;
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 20px 32px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>E-Teller Bank Mini</h1>
            <p>Sistem Informasi Bank Mini Sekolah</p>
        </div>
        <div class="body">
            <p class="greeting">
                Halo <strong>{{ $userName }}</strong>,
            </p>
            <p style="font-size: 14px; line-height: 1.6; color: #475569;">
                Kamu sedang mendaftarkan atau memperbarui email pemulihan akun nasabah pada aplikasi <strong>E-Teller Bank Mini Sekolah</strong>.
            </p>
            <p style="font-size: 14px; line-height: 1.6; color: #475569;">
                Gunakan kode One-Time Password (OTP) berikut untuk memverifikasi kepemilikan alamat email ini:
            </p>

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-caption">Kode ini berlaku selama <strong>10 menit</strong></div>
            </div>

            <div class="warning-card">
                <strong>Penting:</strong> Jangan berikan kode ini kepada siapa pun, termasuk pihak sekolah atau teller bank. Kode OTP hanya dimasukkan pada aplikasi resmi E-Teller Bank Mini.
            </div>

            <p style="font-size: 13px; line-height: 1.6; color: #64748b; margin-top: 24px;">
                Jika kamu tidak merasa melakukan permintaan verifikasi email ini, abaikan pesan ini. Email pemulihan kamu tidak akan diubah tanpa verifikasi kode di atas.
            </p>
        </div>
        <div class="footer">
            <p style="margin: 0;">Email ini dikirim secara otomatis oleh sistem <strong>E-Teller Bank Mini Sekolah</strong>.</p>
            <p style="margin: 4px 0 0 0;">Mohon untuk tidak membalas email ini.</p>
        </div>
    </div>
</body>
</html>
