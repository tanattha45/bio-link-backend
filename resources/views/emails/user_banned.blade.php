<!DOCTYPE html>
<html>
<head>
    <title>แจ้งเตือนการระงับบัญชี</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
        <h2 style="color: #e53e3e;">บัญชีของคุณถูกระงับการใช้งาน 🚫</h2>
        
        <p>เรียนคุณ <strong>{{ $user->username }}</strong>,</p>
        
        <p>เราขอแจ้งให้ทราบว่า บัญชีผู้ใช้งานของคุณถูกระงับการใช้งาน เนื่องจากตรวจพบการกระทำที่อาจขัดต่อนโยบายข้อตกลงการใช้งานของระบบ</p>
        
        <p><strong>สิ่งที่คุณต้องทำ:</strong></p>
        <ul>
            <li>หากคุณเชื่อว่านี่คือความผิดพลาด กรุณาติดต่อทีมงานเพื่อตรวจสอบ</li>
            <li>ตอบกลับอีเมลฉบับนี้ หรือติดต่อเราที่: support@example.com</li>
        </ul>
        
        <p>ขออภัยในความไม่สะดวกมา ณ ที่นี้</p>
        <p>ทีมงานผู้ดูแลระบบ</p>
    </div>
</body>
</html>