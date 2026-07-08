<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */
    // เพิ่ม 'login', 'logout', 'register' เข้าไปใน paths
    'paths' => ['api/*', 'login', 'logout', 'register', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    // เปลี่ยนจาก ['*'] เป็น URL ของ Frontend หน้าบ้านตรงๆ
    'allowed_origins' => ['http://localhost:5173' , 'https://milink.swceservice.com' , 'http://milink.swceservice.com'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ต้องเปลี่ยนเป็น true เพื่อให้ระบบ Authentication (Sanctum/Session) ทำงานข้ามโดเมนได้
    'supports_credentials' => true,

];
