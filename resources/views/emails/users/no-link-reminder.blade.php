<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>เริ่มต้นสร้างหน้าโปรไฟล์ของคุณให้สมบูรณ์กันเถอะ</title>
</head>
<body style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #334155; background-color: #f8fafc; padding: 40px 20px; margin: 0;">
    <div style="max-width: 550px; margin: 0 auto; background: #ffffff; padding: 40px; border-radius: 24px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        
        <h2 style="font-size: 20px; color: #1e293b; margin-top: 0; margin-bottom: 20px;">ยินดีต้อนรับอีกครั้งค่ะ {{ $user->display_name ?? 'คุณลูกค้า' }} 👋</h2>
        
        <p style="font-size: 15px; line-height: 1.6; color: #475569; margin-bottom: 16px;">
            ทีมงานเห็นว่าคุณได้ทำการสมัครบัญชีไว้เรียบร้อยแล้ว แต่ยังไม่ได้เริ่มสร้างปุ่มหรือเพิ่มลิงก์ใดๆ ลงในหน้าโปรไฟล์ของคุณเลย 
        </p>
        
        <p style="font-size: 15px; line-height: 1.6; color: #475569; margin-bottom: 32px;">
            การเริ่มต้นนั้นง่ายมากค่ะ! เพียงแค่คุณล็อกอินเข้าสู่ระบบ คุณก็สามารถเพิ่มช่องทางการติดต่อ โซเชียลมีเดีย หรือผลงานต่างๆ ของคุณ เพื่อรวมทุกอย่างไว้ในลิงก์เดียวให้ผู้ติดตามเข้าถึงได้ง่ายๆ
        </p>
        
        <div style="text-align: center; margin: 35px 0;">
            <a href="{{ env('FRONTEND_URL') }}/login" style="display: inline-block; background-color: #f59e0b; color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; padding: 14px 32px; border-radius: 12px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25); transition: all 0.2s;">
                สร้างลิงก์แรกของคุณเลย 
            </a>
        </div>

        <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 32px 0;">
        
        <p style="font-size: 13px; line-height: 1.6; color: #94a3b8; margin-bottom: 0;">
            หากคุณพบปัญหาในการใช้งาน หรือต้องการคำแนะนำเพิ่มเติม สามารถตอบกลับอีเมลฉบับนี้ได้ทันทีเลยนะคะ ทีมงานพร้อมช่วยเหลือคุณในทุกขั้นตอนค่ะ
        </p>
    </div>
</body>
</html>