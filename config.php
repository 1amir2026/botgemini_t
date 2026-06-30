<?php
// تنظیمات اتصال به دیتابیس (PDO)
$db_host = 'mysql.railway.internal';
$db_name = 'railway';
$db_user = 'root';
$db_pass = 'REjgNgimvyaVGhsHgAdfJMIBfZQpcoEi';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// تنظیمات تلگرام
$token = '8850924162:AAHjZ0mBHRbFMJqyDJCW5KGiLU20Vbotkwk'; 
$admin_id = 6586028596; // آیدی عددی تلگرام خودتان
// $channel_username = '@YourChannel'; // آیدی کانال قفل اجباری (با @)

// متون چندزبانه ربات
$text_lang = [
    'fa' => [
        'select_lang' => "لطفاً زبان خود را انتخاب کنید:\nPlease choose your language:\nالرجاء اختيار لغتك:",
        'join_req' => "لطفاً ابتدا در کانال زیر عضو شوید، سپس دکمه «بررسی عضویت» را بزنید:",
        'check_btn' => "✅ بررسی عضویت",
        'not_member' => "❌ شما هنوز عضو کانال نشده‌اید!",
        'welcome' => "🎉 به ربات هوش مصنوعی خوش آمدید! یکی از گزینه‌ها را انتخاب کنید:",
        'btn_account' => "👤 حساب کاربری",
        'btn_gemini' => "🧠 هوش مصنوعی Gemini",
        'btn_chatgpt' => "🤖 هوش مصنوعی ChatGPT",
        'btn_claude' => "☁️ هوش مصنوعی Claude",
        'btn_deepseek' => "🐋 هوش مصنوعی DeepSeek",
    ],
    'en' => [
        'join_req' => "Please join our channel first, then click 'Check Membership':",
        'check_btn' => "✅ Check Membership",
        'not_member' => "❌ You have not joined the channel yet!",
        'welcome' => "🎉 Welcome to AI Bot! Select one of the options:",
        'btn_account' => "👤 Account",
        'btn_gemini' => "🧠 Gemini AI",
        'btn_chatgpt' => "🤖 ChatGPT",
        'btn_claude' => "☁️ Claude AI",
        'btn_deepseek' => "🐋 DeepSeek AI",
    ],
    'ar' => [
        'join_req' => "الرجاء الاشتراك في القناة أولاً، ثم اضغط على زر «التحقق من الاشتراك»:",
        'check_btn' => "✅ التحقق من الاشتراك",
        'not_member' => "❌ أنت لم تشترك في القناة بعد!",
        'welcome' => "🎉 أهلاً بك في بوت الذكاء الاصطناعي! اختر أحد الخيارات:",
        'btn_account' => "👤 حسابي",
        'btn_gemini' => "🧠 ذكاء Gemini",
        'btn_chatgpt' => "🤖 ذكاء ChatGPT",
        'btn_claude' => "☁️ ذكاء Claude",
        'btn_deepseek' => "🐋 ذكاء DeepSeek",
    ]
];

// تابع ارسال درخواست به تلگرام
function bot($method, $datas = []) {
    global $token;
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res);
}
