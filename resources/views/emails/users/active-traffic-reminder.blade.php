<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ข่าวดี! โปรไฟล์ของคุณยังมีคนเข้าชมอยู่นะ</title>
</head>
<body style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #334155; background-color: #f8fafc; padding: 40px 20px; margin: 0;">
    <div style="max-width: 550px; margin: 0 auto; background: #ffffff; padding: 40px; border-radius: 24px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        
        <h2 style="font-size: 20px; color: #1e293b; margin-top: 0; margin-bottom: 20px;">สวัสดีค่ะ {{ $user->display_name ?? 'คุณลูกค้า' }} 👋</h2>
        
        <p style="font-size: 15px; line-height: 1.6; color: #475569; margin-bottom: 16px;">
            ทีมงานสังเกตเห็นว่าถึงแม้คุณจะไม่ได้แวะเข้ามาอัปเดตระบบหลังบ้านมาระยะหนึ่งแล้ว แต่ในช่วงสัปดาห์ที่ผ่านมา <strong>หน้าโปรไฟล์ของคุณยังคงมีคนกดเข้าชมและคลิกลิงก์อยู่นะคะ!</strong> 🎉
        </p>
        
        <p style="font-size: 15px; line-height: 1.6; color: #475569; margin-bottom: 32px;">
            ผลงานของคุณยังคงได้รับความสนใจอย่างต่อเนื่อง เพื่อไม่ให้พลาดโอกาสดีๆ ลองแวะเข้ามาเช็คสถิติ (Analytics) ของคุณดูสิคะ ว่าลิงก์ไหนกำลังฮิตที่สุด หรือจะเพิ่มลิงก์ผลงานใหม่ๆ เพื่อต่อยอดความสนใจจากผู้ติดตามของคุณก็ได้ค่ะ
        </p>
        
        <div style="text-align: center; margin: 35px 0;">
            <a href="{{ env('FRONTEND_URL') }}/login" style="display: inline-block; background-color: #3b82f6; color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; padding: 14px 32px; border-radius: 12px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25); transition: all 0.2s;">
                เข้าดูสถิติโปรไฟล์ของคุณเลย 📊
            </a>
        </div>

        <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 32px 0;">
        
        <p style="font-size: 13px; line-height: 1.6; color: #94a3b8; margin-bottom: 0;">
            หากคุณลืมรหัสผ่านหรือต้องการความช่วยเหลือ สามารถตอบกลับอีเมลฉบับนี้เพื่อพูดคุยกับทีมงานได้ตลอดเวลาเลยนะคะ เราพร้อมซัพพอร์ตคุณเสมอค่ะ! 🚀
        </p>
    </div>
</body>
</html>