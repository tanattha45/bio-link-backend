<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>รหัส OTP ของคุณ</title>
</head>
<body style="font-family: sans-serif; color: #333; background-color: #f9f9f9; padding: 20px;">
    <div style="max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; rounded: 12px; border: 1px solid #eee;">
        <h2 style="color: #4f46e5; margin-bottom: 10px;">สวัสดีค่ะ 👋</h2>
        <p style="font-size: 14px; color: #666;">คุณได้ทำเรื่องขอรีเซ็ทรหัสผ่านในระบบ รหัส OTP สำหรับใช้ยืนยันตัวตนของคุณคือ:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <span style="font-size: 32px; font-bold: true; color: #4f46e5; letter-spacing: 5px; background: #f3f4f6; padding: 10px 25px; border-radius: 8px;">
                {{ $otp }}
            </span>
        </div>

        <p style="font-size: 12px; color: #ef4444;">⚠️ รหัสนี้มีอายุการใช้งาน 5 นาทีเท่านั้นเพื่อความปลอดภัย</p>
        <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
        <p style="font-size: 12px; color: #999;">หากคุณไม่ได้เป็นผู้ส่งคำขอนี้ สามารถปล่อยผ่านอีเมลฉบับนี้ไปได้เลยค่ะ</p>
    </div>
</body>
</html>