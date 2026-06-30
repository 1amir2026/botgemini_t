<?php
// فایل کامل و یکپارچه gemini.php - سیستم حل سوالات با جمنای و پشتیبان خودکار

// 1. بررسی دکمه بازگشت به منوی اصلی
if ($text == '🔙 بازگشت' || $text == '🔙 Back' || $text == '🔙 عودة') {
    $db->prepare("UPDATE users SET step = 'main_menu' WHERE user_id = ?")->execute([$from_id]);
    $menu_buttons = [
        [$text_lang[$user_lang]['btn_gemini'], $text_lang[$user_lang]['btn_chatgpt']],
        [$text_lang[$user_lang]['btn_claude'], $text_lang[$user_lang]['btn_deepseek']],
        [$text_lang[$user_lang]['btn_account']]
    ];
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text_lang[$user_lang]['welcome'],
        'reply_markup' => json_encode(['keyboard' => $menu_buttons, 'resize_keyboard' => true])
    ]);
    exit;
}

// 2. پیام خوش‌آمدگویی هنگام ورود اولیه به بخش جمنای
if ($text == $text_lang[$user_lang]['btn_gemini']) {
    $db->prepare("UPDATE users SET step = 'gemini_chat' WHERE user_id = ?")->execute([$from_id]);
    
    $back_btn = ($user_lang == 'en') ? '🔙 Back' : (($user_lang == 'ar') ? '🔙 عودة' : '🔙 بازگشت');
    $msg = ($user_lang == 'en') ? "🧠 Welcome to Gemini.\nPlease type your question or send a photo of the exam:" : 
          (($user_lang == 'ar') ? "🧠 مرحباً بك في Gemini.\nالرجاء كتابة سؤالك أو إرسال صورة للامتحان:" : 
          "🧠 به بخش هوش مصنوعی Gemini خوش آمدید.\nلطفاً سوال درسی خود را تایپ کنید یا یک عکس از روی سوال بفرستید:");

    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $msg,
        'reply_markup' => json_encode(['keyboard' => [[$back_btn]], 'resize_keyboard' => true])
    ]);
    exit;
}

// 3. پردازش پیام ارسالی (متن یا عکس) در وضعیت چت
if ($user['step'] == 'gemini_chat') {
    
    // کلید API گوگل (توصیه می‌شود در صورت افشا شدن حتما تعویض شود)
    $gemini_api_key = getenv('GEMINI_API_KEY');
    
    if (!$gemini_api_key) {
        die('GEMINI_API_KEY not found in Railway Variables');
    }

    // بررسی فرمت کلید: کلیدهای استاندارد Gemini (از Google AI Studio) با "AIza" شروع می‌شوند.
    // اگر کلید با چیز دیگری شروع شود (مثل AQ...)، احتمالاً کلید اشتباه/ناقص کپی شده یا از منبع دیگری (مثل Vertex AI / OAuth) است
    // که با این endpoint (Generative Language API) کار نمی‌کند.
    if (strpos($gemini_api_key, 'AIza') !== 0) {
        error_log("[GEMINI WARNING] API key does not start with 'AIza'. Key prefix: " . substr($gemini_api_key, 0, 6));
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⚠️ هشدار: کلید API شما با 'AIza' شروع نمی‌شود (شروع فعلی: " . substr($gemini_api_key, 0, 6) . "...).\n" .
                      "کلیدهای استاندارد Gemini از Google AI Studio (https://aistudio.google.com/apikey) باید با AIza شروع شوند.\n" .
                      "احتمالاً کلید شما ناقص کپی شده یا از منبع اشتباهی گرفته شده. با این حال تلاش برای اتصال ادامه می‌یابد..."
        ]);
    }
    
    // لیست مدل‌ها به ترتیب اولویت برای سیستم Fallback
    $models = [
        'gemini-2.5-flash',
        'gemini-2.5-pro'
    ];

    // ارسال پیام لودینگ موقت به کاربر
    $wait_msg = bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "⏳ در حال تحلیل و حل سوال... لطفاً چند ثانیه صبر کنید."
    ]);
    $wait_msg_id = $wait_msg->result->message_id ?? null;

    $payload = [];
    // دستور پنهان سیستم به هوش مصنوعی برای اجبار به رعایت زبان انتخابی کاربر
    $system_instruction = "\nYou are a helpful tutor. Answer the user's question accurately. IMPORTANT: You must reply entirely in this language code: " . $user_lang;

    // الف) اگر کاربر عکس فرستاده باشد
    if (isset($message->photo)) {
        $photo_array = $message->photo;
        $file_id = end($photo_array)->file_id; // گرفتن باکیفیت‌ترین سایز عکس
        $caption = $message->caption ?? "لطفا این سوال درسی را حل کن و مرحله به مرحله توضیح بده.";

        // دریافت مسیر ذخیره‌سازی موقت عکس در تلگرام
        $file_info = bot('getFile', ['file_id' => $file_id]);
        $file_path = $file_info->result->file_path;
        $file_url = "https://api.telegram.org/file/bot" . $token . "/" . $file_path;
        
        // دانلود تصویر و تبدیل به کدهای Base64
        $image_data = @file_get_contents($file_url);
        if (!$image_data) {
            if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا در دانلود عکس از تلگرام. لطفاً مجدداً ارسال کنید."]);
            exit;
        }
        $base64_image = base64_encode($image_data);

        // چیدمان جی‌سان ساختار مولتی‌مدیا برای گوگل کلاود
        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $caption . $system_instruction],
                        [
                            "inline_data" => [
                                "mime_type" => "image/jpeg",
                                "data" => $base64_image
                            ]
                        ]
                    ]
                ]
            ]
        ];
    } 
    // ب) اگر کاربر متن فرستاده باشد
    elseif (!empty($text)) {
        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $text . $system_instruction]
                    ]
                ]
            ]
        ];
    } 
    // ج) فرمت نامعتبر (مثل ویس، فایل استیکر و...)
    else {
        if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت پشتیبانی نمی‌شود. لطفاً فقط متن یا عکس بفرستید."]);
        exit;
    }

    // 4. حلقه سوئیچ خودکار بین مدل‌ها (Fallback System)
    $success = false;
    $reply_text = '';
    $debug_log = []; // برای ثبت کامل جزئیات هر تلاش

    foreach ($models as $current_model) {
        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/" . $current_model . ":generateContent?key=" . $gemini_api_key;

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        // ثبت کامل جزئیات این تلاش برای دیباگ
        $debug_log[] = [
            'model'        => $current_model,
            'http_code'    => $http_code,
            'curl_errno'   => $curl_errno,
            'curl_error'   => $curl_error,
            'raw_response' => $response,
        ];

        // لاگ کردن در فایل لاگ سرور (در Railway در بخش Logs قابل مشاهده است)
        error_log("[GEMINI DEBUG] model={$current_model} http_code={$http_code} curl_errno={$curl_errno} curl_error={$curl_error} response=" . substr((string)$response, 0, 2000));

        // بررسی اینکه آیا مدل فعلی با موفقیت پاسخ داده است؟
        if ($http_code == 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $reply_text = $result['candidates'][0]['content']['parts'][0]['text'];
            $success = true;
            break; // خروج از حلقه به دلیل دریافت پاسخ موفقیت‌آمیز
        }
        // اگر کدی غیر از 200 آمد، حلقه ادامه یافته و مدل بعدی را تست می‌کند
    }

    // حذف پیام لودینگ "در حال پردازش..." از صفحه چت تلگرام
    if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);

    // 5. بررسی نهایی و ارسال پاسخ به تلگرام
    if ($success) {
        // مدیریت سقف کاراکتر پیام‌های تلگرام (رعایت لیمیت ۴۰۹۶ کاراکتر)
        if (mb_strlen($reply_text) > 4000) {
            $reply_text = mb_substr($reply_text, 0, 4000) . "...\n[ادامه متن به دلیل طولانی بودن بریده شد]";
        }
        
        $telegram_response = bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $reply_text
        ]);

        // اگر تلگرام به خاطر ساختار متن پیام را رد کرد، لاگ آن برای ادمین ارسال شود
        if (!$telegram_response->ok) {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "⚠️ پاسخ از گوگل دریافت شد اما تلگرام خطای ارسال داد:\n" . $telegram_response->description
            ]);
        }
    } else {
        // اگر تمام مدل‌های تعریف شده با خطا مواجه شدند، جزئیات دقیق هر تلاش را برای دیباگ نمایش بده
        $error_details = "❌ سرورهای گوگل پاسخ موفق ندادند. جزئیات خطا برای دیباگ:\n\n";

        foreach ($debug_log as $attempt) {
            $error_details .= "🔹 مدل: " . $attempt['model'] . "\n";
            $error_details .= "HTTP Code: " . $attempt['http_code'] . "\n";

            if ($attempt['curl_errno'] != 0) {
                $error_details .= "خطای cURL (" . $attempt['curl_errno'] . "): " . $attempt['curl_error'] . "\n";
            }

            // تلاش برای استخراج پیام خطای دقیق گوگل از پاسخ JSON
            $decoded = json_decode($attempt['raw_response'], true);
            if (isset($decoded['error']['message'])) {
                $error_details .= "پیام گوگل: " . $decoded['error']['message'] . "\n";
                if (isset($decoded['error']['status'])) {
                    $error_details .= "وضعیت: " . $decoded['error']['status'] . "\n";
                }
            } else {
                // اگر پاسخ JSON معتبر نبود، خام آن را (محدود) نشان بده
                $error_details .= "پاسخ خام: " . mb_substr((string)$attempt['raw_response'], 0, 300) . "\n";
            }
            $error_details .= "—\n";
        }

        // محدود کردن طول پیام برای جلوگیری از خطای تلگرام
        if (mb_strlen($error_details) > 4000) {
            $error_details = mb_substr($error_details, 0, 4000) . "...";
        }

        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $error_details
        ]);
    }
}
?>
