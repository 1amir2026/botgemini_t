<?php
// فایل کامل و یکپارچه gemini.php - سیستم حل سوالات با جمنای + تولید تصویر + پشتیبان خودکار

// تابع کمکی: تبدیل **بولد** سبک مارک‌داون معمولی به فرمت بولد تلگرام (Markdown legacy => *bold*)
// و خنثی‌سازی کاراکترهای خاصی که می‌توانند parse_mode را خراب کنند.
function gemini_to_telegram_markdown($text) {
    // ابتدا **بولد** -> *بولد* (تلگرام Markdown قدیمی فقط با یک ستاره بولد می‌کند)
    $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text);
    // حذف ستاره‌های یتیمی که جفت ندارند تا تلگرام خطای entity ندهد
    $stars = substr_count($text, '*');
    if ($stars % 2 !== 0) {
        $text = str_replace('*', '', $text);
    }
    return $text;
}

// تابع کمکی: ارسال امن پیام با parse_mode Markdown و Fallback به متن ساده در صورت خطا
function gemini_safe_send($chat_id, $text) {
    $formatted = gemini_to_telegram_markdown($text);
    $resp = bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $formatted,
        'parse_mode' => 'Markdown'
    ]);
    if (!($resp->ok ?? false)) {
        // اگر بخاطر فرمت نادرست رد شد، بدون parse_mode دوباره ارسال کن
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text
        ]);
    }
    return $resp;
}

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
    $msg = ($user_lang == 'en') ? "🧠 Welcome to Gemini.\nType your question, send a photo of the exam, or ask me to *draw* / *generate an image* of something:" :
          (($user_lang == 'ar') ? "🧠 مرحباً بك في Gemini.\nاكتب سؤالك، أرسل صورة الامتحان، أو اطلب مني *رسم* / *إنشاء صورة*:" :
          "🧠 به بخش هوش مصنوعی Gemini خوش آمدید.\nسوال درسی خود را تایپ کنید، عکس بفرستید، یا بگویید مثلاً «یک تصویر از ... بساز»:");

    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $msg,
        'parse_mode' => 'Markdown',
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

    if (strpos($gemini_api_key, 'AIza') !== 0) {
        error_log("[GEMINI WARNING] API key does not start with 'AIza'. Key prefix: " . substr($gemini_api_key, 0, 6));
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⚠️ هشدار: کلید API شما با 'AIza' شروع نمی‌شود (شروع فعلی: " . substr($gemini_api_key, 0, 6) . "...).\n" .
                      "کلیدهای استاندارد Gemini از Google AI Studio (https://aistudio.google.com/apikey) باید با AIza شروع شوند.\n" .
                      "احتمالاً کلید شما ناقص کپی شده یا از منبع اشتباهی گرفته شده. با این حال تلاش برای اتصال ادامه می‌یابد..."
        ]);
    }

    // ---------------------------------------------------------------
    // تشخیص اینکه آیا کاربر درخواست «ساخت تصویر» دارد یا سوال معمولی
    // ---------------------------------------------------------------
    $is_image_request = false;
    if (!empty($text) && !isset($message->photo)) {
        $image_keywords = [
            'بساز', 'طراحی کن', 'نقاشی کن', 'یک تصویر', 'تصویری از', 'عکس بساز', 'تصویر بساز',
            'draw', 'generate image', 'create image', 'create an image', 'make an image', 'an image of',
            'ارسم', 'انشئ صورة', 'صورة لـ', 'image of'
        ];
        $lower_text = mb_strtolower($text);
        foreach ($image_keywords as $kw) {
            if (mb_strpos($lower_text, mb_strtolower($kw)) !== false) {
                $is_image_request = true;
                break;
            }
        }
    }

    // =================================================================
    // مسیر الف) تولید تصویر با مدل Gemini Image (Nano Banana)
    // =================================================================
    if ($is_image_request) {

        $wait_msg = bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🎨 در حال ساخت تصویر... لطفاً چند لحظه صبر کنید."
        ]);
        $wait_msg_id = $wait_msg->result->message_id ?? null;

        $image_models = [
            'gemini-2.5-flash-image',
            'gemini-2.0-flash-preview-image-generation'
        ];

        $image_payload = [
            "contents" => [
                ["parts" => [["text" => $text]]]
            ],
            "generationConfig" => [
                "responseModalities" => ["IMAGE", "TEXT"]
            ]
        ];

        $img_success = false;
        $img_base64 = null;
        $img_caption = '';
        $debug_log = [];

        foreach ($image_models as $current_model) {
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/" . $current_model . ":generateContent?key=" . $gemini_api_key;

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($image_payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            $result = json_decode($response, true);
            $debug_log[] = ['model' => $current_model, 'http_code' => $http_code, 'curl_error' => $curl_error, 'raw' => $response];
            error_log("[GEMINI IMAGE DEBUG] model={$current_model} http_code={$http_code} response=" . substr((string)$response, 0, 1500));

            if ($http_code == 200 && isset($result['candidates'][0]['content']['parts'])) {
                foreach ($result['candidates'][0]['content']['parts'] as $part) {
                    // کلید می‌تواند inlineData (camelCase) یا inline_data (snake_case) باشد
                    $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                    if ($inline && isset($inline['data'])) {
                        $img_base64 = $inline['data'];
                        $img_success = true;
                    }
                    if (isset($part['text'])) {
                        $img_caption .= $part['text'];
                    }
                }
                if ($img_success) break;
            }
        }

        if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);

        if ($img_success && $img_base64) {
            // ذخیره موقت تصویر روی دیسک برای آپلود به تلگرام
            $tmp_path = sys_get_temp_dir() . '/gemini_img_' . $from_id . '_' . time() . '.png';
            file_put_contents($tmp_path, base64_decode($img_base64));

            $caption_text = trim($img_caption) !== '' ? mb_substr(trim($img_caption), 0, 1000) : "🎨 تصویر شما آماده شد.";

            // ارسال مستقیم با cURL multipart (مستقل از پیاده‌سازی داخلی bot())
            $send_url = "https://api.telegram.org/bot" . $token . "/sendPhoto";
            $post_fields = [
                'chat_id' => $chat_id,
                'caption' => gemini_to_telegram_markdown($caption_text),
                'parse_mode' => 'Markdown',
                'photo' => new CURLFile($tmp_path, 'image/png', 'image.png')
            ];

            $ch = curl_init($send_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            $photo_response = curl_exec($ch);
            curl_close($ch);

            @unlink($tmp_path);

            $decoded_photo_resp = json_decode($photo_response, true);
            if (!($decoded_photo_resp['ok'] ?? false)) {
                // اگر ارسال با کپشن مارک‌داون خطا داد، بدون فرمت دوباره تلاش کن
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "⚠️ تصویر ساخته شد اما ارسال آن با خطا مواجه شد:\n" . ($decoded_photo_resp['description'] ?? 'خطای نامشخص')
                ]);
            }
        } else {
            $error_details = "❌ ساخت تصویر ناموفق بود. جزئیات:\n\n";
            foreach ($debug_log as $attempt) {
                $error_details .= "🔹 مدل: " . $attempt['model'] . " | HTTP: " . $attempt['http_code'] . "\n";
                $decoded = json_decode($attempt['raw'], true);
                if (isset($decoded['error']['message'])) {
                    $error_details .= "پیام گوگل: " . $decoded['error']['message'] . "\n";
                }
                $error_details .= "—\n";
            }
            if (mb_strlen($error_details) > 4000) $error_details = mb_substr($error_details, 0, 4000) . "...";
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => $error_details]);
        }

        exit;
    }

    // =================================================================
    // مسیر ب) حل سوال متنی / تصویری (مسیر قبلی پروژه)
    // =================================================================

    $models = [
        'gemini-2.5-flash',
        'gemini-2.5-pro'
    ];

    $wait_msg = bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "⏳ در حال تحلیل و حل سوال... لطفاً چند ثانیه صبر کنید."
    ]);
    $wait_msg_id = $wait_msg->result->message_id ?? null;

    $payload = [];
    $system_instruction = "\nYou are a helpful tutor. Answer the user's question accurately. " .
        "Use **double asterisks** around important terms/headings for bold emphasis where useful. " .
        "IMPORTANT: You must reply entirely in this language code: " . $user_lang;

    if (isset($message->photo)) {
        $photo_array = $message->photo;
        $file_id = end($photo_array)->file_id;
        $caption = $message->caption ?? "لطفا این سوال درسی را حل کن و مرحله به مرحله توضیح بده.";

        $file_info = bot('getFile', ['file_id' => $file_id]);
        $file_path = $file_info->result->file_path;
        $file_url = "https://api.telegram.org/file/bot" . $token . "/" . $file_path;

        $image_data = @file_get_contents($file_url);
        if (!$image_data) {
            if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا در دانلود عکس از تلگرام. لطفاً مجدداً ارسال کنید."]);
            exit;
        }
        $base64_image = base64_encode($image_data);

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
    elseif (!empty($text)) {
        $payload = [
            "contents" => [
                ["parts" => [["text" => $text . $system_instruction]]]
            ]
        ];
    }
    else {
        if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت پشتیبانی نمی‌شود. لطفاً فقط متن یا عکس بفرستید."]);
        exit;
    }

    $success = false;
    $reply_text = '';
    $debug_log = [];

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

        $debug_log[] = [
            'model'        => $current_model,
            'http_code'    => $http_code,
            'curl_errno'   => $curl_errno,
            'curl_error'   => $curl_error,
            'raw_response' => $response,
        ];

        error_log("[GEMINI DEBUG] model={$current_model} http_code={$http_code} curl_errno={$curl_errno} curl_error={$curl_error} response=" . substr((string)$response, 0, 2000));

        if ($http_code == 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $reply_text = $result['candidates'][0]['content']['parts'][0]['text'];
            $success = true;
            break;
        }
    }

    if ($wait_msg_id) bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $wait_msg_id]);

    if ($success) {
        if (mb_strlen($reply_text) > 4000) {
            $reply_text = mb_substr($reply_text, 0, 4000) . "...\n[ادامه متن به دلیل طولانی بودن بریده شد]";
        }

        gemini_safe_send($chat_id, $reply_text);
    } else {
        $error_details = "❌ سرورهای گوگل پاسخ موفق ندادند. جزئیات خطا برای دیباگ:\n\n";

        foreach ($debug_log as $attempt) {
            $error_details .= "🔹 مدل: " . $attempt['model'] . "\n";
            $error_details .= "HTTP Code: " . $attempt['http_code'] . "\n";

            if ($attempt['curl_errno'] != 0) {
                $error_details .= "خطای cURL (" . $attempt['curl_errno'] . "): " . $attempt['curl_error'] . "\n";
            }

            $decoded = json_decode($attempt['raw_response'], true);
            if (isset($decoded['error']['message'])) {
                $error_details .= "پیام گوگل: " . $decoded['error']['message'] . "\n";
                if (isset($decoded['error']['status'])) {
                    $error_details .= "وضعیت: " . $decoded['error']['status'] . "\n";
                }
            } else {
                $error_details .= "پاسخ خام: " . mb_substr((string)$attempt['raw_response'], 0, 300) . "\n";
            }
            $error_details .= "—\n";
        }

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
