<?php
// فایل کامل و یکپارچه gemini.php - سیستم حل سوالات با جمنای + تولید تصویر + دستیار برنامه‌نویسی حرفه‌ای + پشتیبان خودکار

// =================================================================
// بخش ۱: فرمت‌کننده متن گوگل -> HTML تلگرام (parse_mode = HTML)
// =================================================================
// چرا HTML به‌جای Markdown؟ چون پارسر Markdown/MarkdownV2 تلگرام به شدت شکننده است:
// هر کاراکتر خاص اسکیپ‌نشده (مثل کاراکترهای فارسی، پرانتز و...) باعث rejected شدن کل پیام می‌شود
// و پیام بدون parse_mode (متن خام) ارسال می‌شود - دقیقا همان چیزی که باعث می‌شد بولد و --- کار نکنند.
// پارسر HTML تلگرام خیلی مقاوم‌تر است: فقط باید &, <, > را escape کنیم و تگ‌های ساده <b>, <code>, <pre> بگذاریم.

// escape کاراکترهای خاص HTML (باید همیشه روی متن خامِ غیر تگ اعمال شود)
function gemini_escape_html($text) {
    $text = str_replace('&', '&amp;', $text);
    $text = str_replace('<', '&lt;', $text);
    $text = str_replace('>', '&gt;', $text);
    return $text;
}

/**
 * تبدیل خروجی متنی Gemini (که از Markdown معمولی استفاده می‌کند) به HTML معتبر تلگرام.
 * پشتیبانی می‌کند از:
 *   ```lang \n code \n ```   => <pre><code class="language-lang">...</code></pre>  (بلوک کد، قابل کپی با یک لمس)
 *   `inline code`            => <code>...</code>
 *   **bold**                 => <b>...</b>
 *   *italic* یا _italic_     => <i>...</i>
 *   ---  یا ___ یا ***  (در یک خط جدا) => خط جداکننده افقی واقعی
 */
function gemini_to_telegram_html($text) {
    // یکسان‌سازی line breakها
    $text = str_replace("\r\n", "\n", $text);

    // ۱) استخراج بلوک‌های کد ```...``` قبل از هر کار دیگری، تا محتوای داخلشان escape جداگانه و امن بگیرد
    $code_blocks = [];
    $cb_index = 0;
    $text = preg_replace_callback('/```([a-zA-Z0-9_+\-]*)\n?([\s\S]*?)```/', function ($m) use (&$code_blocks, &$cb_index) {
        $lang = trim($m[1]);
        $code = rtrim($m[2], "\n");
        $escaped_code = gemini_escape_html($code);
        $lang_attr = $lang !== '' ? ' class="language-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $lang) . '"' : '';
        $placeholder = "\x01CB" . $cb_index . "\x01";
        $code_blocks[$placeholder] = "<pre><code{$lang_attr}>{$escaped_code}</code></pre>";
        $cb_index++;
        return $placeholder;
    }, $text);

    // ۲) استخراج کدهای درون‌خطی `code`
    $inline_codes = [];
    $ic_index = 0;
    $text = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$inline_codes, &$ic_index) {
        $placeholder = "\x02IC" . $ic_index . "\x02";
        $inline_codes[$placeholder] = '<code>' . gemini_escape_html($m[1]) . '</code>';
        $ic_index++;
        return $placeholder;
    }, $text);

    // ۳) تبدیل خطوط جداکننده (---، ___، ***) که به‌تنهایی در یک خط آمده‌اند، به یک خط افقی واقعی
    //    (HTML تلگرام تگ <hr> ندارد، پس از یک خط نماد استفاده می‌کنیم)
    $text = preg_replace('/^[ \t]*([-_*])\1{2,}[ \t]*$/m', "\x03HR\x03", $text);

    // ۴) بولد: **text** -> نشانگر موقت (قبل از escape کلی متن انجام می‌شود تا ** خودش escape نشود)
    $text = preg_replace('/\*\*(.+?)\*\*/s', "\x04B\x04" . '$1' . "\x04B\x04", $text);

    // ۵) ایتالیک تک‌ستاره یا زیرخط: *text* یا _text_ (بعد از حذف بولد، باقیمانده تک‌ستاره‌ها ایتالیک محسوب می‌شوند)
    $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', "\x05I\x05" . '$1' . "\x05I\x05", $text);
    $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', "\x05I\x05" . '$1' . "\x05I\x05", $text);

    // ۶) حالا کل متن باقیمانده (متن خام معمولی) را escape می‌کنیم تا &,<,> مشکل‌ساز نشوند
    $text = gemini_escape_html($text);

    // ۷) برگرداندن نشانگرهای بولد/ایتالیک به تگ واقعی HTML
    $text = str_replace("\x04B\x04", '~~~B~~~', $text); // جداسازی موقت برای جلوگیری از تداخل با replace بعدی
    $parts = explode('~~~B~~~', $text);
    $text = '';
    foreach ($parts as $i => $p) {
        $text .= ($i % 2 === 1) ? "<b>{$p}</b>" : $p;
    }

    $text = str_replace("\x05I\x05", '~~~I~~~', $text);
    $parts = explode('~~~I~~~', $text);
    $text = '';
    foreach ($parts as $i => $p) {
        $text .= ($i % 2 === 1) ? "<i>{$p}</i>" : $p;
    }

    // ۸) برگرداندن خط جداکننده
    $text = str_replace("\x03HR\x03", str_repeat('▬', 18), $text);

    // ۹) برگرداندن کدهای درون‌خطی
    foreach ($inline_codes as $ph => $val) {
        $text = str_replace($ph, $val, $text);
    }

    // ۱۰) برگرداندن بلوک‌های کد
    foreach ($code_blocks as $ph => $val) {
        $text = str_replace($ph, $val, $text);
    }

    return $text;
}

// =================================================================
// بخش ۲.۶: ارسال عکس مرتبط از گوگل برای کمک به یادگیری بهتر (اختیاری)
// نیاز به دو متغیر محیطی دارد: GOOGLE_CSE_API_KEY و GOOGLE_CSE_CX
// (از Google Programmable Search Engine با قابلیت Image Search ساخته می‌شود)
// اگر این متغیرها تنظیم نشده باشند، این تابع بدون خطا کاری انجام نمی‌دهد.
// =================================================================
function gemini_maybe_send_related_image($chat_id, $query) {
    $cse_key = getenv('GOOGLE_CSE_API_KEY');
    $cse_cx  = getenv('GOOGLE_CSE_CX');
    if (!$cse_key || !$cse_cx || trim((string)$query) === '') return;

    $url = "https://www.googleapis.com/customsearch/v1?key=" . urlencode($cse_key) .
           "&cx=" . urlencode($cse_cx) .
           "&searchType=image&num=1&safe=active&q=" . urlencode($query);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return;

    $data = json_decode($resp, true);
    $image_url = $data['items'][0]['link'] ?? null;
    if (!$image_url) return;

    bot('sendPhoto', [
        'chat_id' => $chat_id,
        'photo' => $image_url,
        'caption' => "🖼 یک تصویر مرتبط برای درک بهتر موضوع"
    ]);
}

// تابع کمکی: ارسال امن پیام با parse_mode HTML و Fallback به متن ساده در صورت خطا
function gemini_safe_send($chat_id, $raw_text, $reply_markup = null) {
    $formatted = gemini_to_telegram_html($raw_text);
    $params = [
        'chat_id' => $chat_id,
        'text' => $formatted,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) $params['reply_markup'] = $reply_markup;

    $resp = bot('sendMessage', $params);
    if (!($resp->ok ?? false)) {
        // اگر فرمت‌بندی به هر دلیلی رد شد (مثلا تگ ناقص)، بدون parse_mode (متن خام) دوباره ارسال کن
        error_log("[GEMINI HTML FALLBACK] " . json_encode($resp));
        $fallback_params = ['chat_id' => $chat_id, 'text' => $raw_text];
        if ($reply_markup) $fallback_params['reply_markup'] = $reply_markup;
        $resp = bot('sendMessage', $fallback_params);
    }
    return $resp;
}

// =================================================================
// بخش ۲: تشخیص کد / فایل لاگ و ارسال هوشمند (پیام یا فایل سند)
// =================================================================

// آیا متن ورودی کاربر، یک تکه کد یا لاگ به نظر می‌رسد؟ (برای تنظیم پرامپت سیستم)
function gemini_looks_like_code_or_log($text) {
    if (preg_match('/```/', $text)) return true;
    if (preg_match('/\b(def |class |import |function |public |private |#include|SELECT |Traceback|Exception|Error:|at [a-zA-Z0-9_.]+\(.*\)|\bnull\b|\bundefined\b)/i', $text)) return true;
    // خطوط زیاد با تورفتگی/نشانه‌های کد
    $lines = explode("\n", $text);
    if (count($lines) > 6) {
        $code_like = 0;
        foreach ($lines as $l) {
            if (preg_match('/^[\s]*[\{\}\[\]();]|^[\s]{2,}\S|=>|==|!=|::/', $l)) $code_like++;
        }
        if ($code_like > count($lines) * 0.3) return true;
    }
    return false;
}

// تشخیص پسوند مناسب فایل بر اساس نام زبان اعلام‌شده در fence یا محتوای کد
function gemini_guess_file_extension($lang, $code) {
    $lang = strtolower(trim($lang));
    $map = [
        'python' => 'py', 'py' => 'py',
        'javascript' => 'js', 'js' => 'js', 'node' => 'js',
        'typescript' => 'ts', 'ts' => 'ts',
        'php' => 'php',
        'java' => 'java',
        'c' => 'c', 'cpp' => 'cpp', 'c++' => 'cpp',
        'csharp' => 'cs', 'c#' => 'cs', 'cs' => 'cs',
        'html' => 'html', 'css' => 'css',
        'json' => 'json', 'yaml' => 'yaml', 'yml' => 'yml',
        'bash' => 'sh', 'shell' => 'sh', 'sh' => 'sh',
        'sql' => 'sql', 'go' => 'go', 'rust' => 'rs', 'kotlin' => 'kt',
        'log' => 'log', 'text' => 'txt', 'txt' => 'txt',
    ];
    if (isset($map[$lang])) return $map[$lang];

    // حدس بر اساس محتوا
    if (preg_match('/^\s*<\?php/m', $code)) return 'php';
    if (preg_match('/def\s+\w+\(.*\):|import\s+\w+/m', $code)) return 'py';
    if (preg_match('/Traceback|Exception in thread|at\s+[\w.$]+\(.+\)|^\[\d{4}-\d{2}-\d{2}/m', $code)) return 'log';
    if (preg_match('/function\s+\w+\s*\(|=>|const\s+\w+\s*=/m', $code)) return 'js';
    if (preg_match('/^\s*\{[\s\S]*\}\s*$/', $code)) return 'json';
    return 'txt';
}

/**
 * متن نهایی Gemini را بررسی می‌کند:
 *  - اگر بلوک کد طولانی/سنگین داشت (یا کل پیام خیلی بلند بود) آن را به‌صورت فایل سند ارسال می‌کند
 *  - در غیر این صورت با فرمت HTML (کدباکس، بولد، خط جداکننده) به صورت پیام ارسال می‌کند
 */
function gemini_deliver_reply($chat_id, $reply_text, $from_id) {
    // پیدا کردن بلوک‌های کد داخل پاسخ
    preg_match_all('/```([a-zA-Z0-9_+\-]*)\n?([\s\S]*?)```/', $reply_text, $matches, PREG_SET_ORDER);

    $send_as_file = false;
    $file_lang = '';
    $file_code = '';
    $biggest_match_full = '';

    if (!empty($matches)) {
        // بزرگ‌ترین بلوک کد را پیدا کن
        $longest = '';
        $longest_lang = '';
        foreach ($matches as $m) {
            if (mb_strlen($m[2]) > mb_strlen($longest)) {
                $longest = $m[2];
                $longest_lang = $m[1];
                $biggest_match_full = $m[0];
            }
        }
        $code_lines = substr_count($longest, "\n") + 1;
        // اگر کد طولانی بود (بیش از ۳۵ خط یا ۳۰۰۰ کاراکتر) یا کل پیام از سقف تلگرام رد می‌شود -> فایل بفرست
        if ($code_lines > 35 || mb_strlen($longest) > 3000 || mb_strlen($reply_text) > 3800) {
            $send_as_file = true;
            $file_lang = $longest_lang;
            $file_code = $longest;
        }
    }

    if ($send_as_file) {
        $ext = gemini_guess_file_extension($file_lang, $file_code);
        $tmp_path = sys_get_temp_dir() . '/gemini_code_' . $from_id . '_' . time() . '.' . $ext;
        file_put_contents($tmp_path, $file_code);

        // متنی که خارج از بلوک کد اصلی نوشته شده را به‌عنوان توضیح/کپشن می‌فرستیم
        $explanation = trim(str_replace($biggest_match_full, '', $reply_text));
        if ($explanation === '') {
            $explanation = "📄 کد/فایل شما آماده شد. می‌توانید آن را دانلود کنید.";
        }

        global $token;
        $send_url = "https://api.telegram.org/bot" . $token . "/sendDocument";
        $post_fields = [
            'chat_id' => $chat_id,
            'caption' => mb_substr($explanation, 0, 1000),
            'document' => new CURLFile($tmp_path, 'text/plain', basename($tmp_path))
        ];
        $ch = curl_init($send_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_exec($ch);
        curl_close($ch);
        @unlink($tmp_path);

        // اگر متن توضیحات هم طولانی بود (بیشتر از کپشن جا داشت)، جداگانه با فرمت کامل بفرست
        if (mb_strlen($explanation) > 1000) {
            gemini_safe_send($chat_id, $explanation);
        }
    } else {
        if (mb_strlen($reply_text) > 4000) {
            $reply_text = mb_substr($reply_text, 0, 4000) . "...\n[ادامه متن به دلیل طولانی بودن بریده شد]";
        }
        gemini_safe_send($chat_id, $reply_text);
    }
}

// =================================================================
// بخش ۲.۵: ادیت پیام انتظار به‌صورت دوره‌ای (نمایش "در حال انجام چه کاری" + درصد فیک)
// تا کاربر در طول پردازش طولانی احساس کند ربات در حال کار است و صبرش بیشتر شود
// =================================================================
function gemini_edit_wait_progress(&$state, $steps_arr) {
    if (empty($state['msg_id'])) return;

    $now = microtime(true);
    // حداقل فاصله بین دو ادیت پشت‌سرهم (برای جلوگیری از Rate Limit تلگرام)
    if (($now - ($state['last_edit_time'] ?? 0)) < 2.5) return;

    $state['last_edit_time'] = $now;

    $step_text = $steps_arr[$state['step_index'] % count($steps_arr)];
    $state['step_index']++;

    // درصد فیک ولی منطقی: هر بار کمی بیشتر می‌شود ولی هیچ‌وقت به ۱۰۰ نمی‌رسد تا واقعی‌تر به‌نظر برسد
    $state['percent'] = min(93, ($state['percent'] ?? 5) + rand(6, 13));

    $progress_text = $state['base_text'] .
        "\n\n<i>" . $step_text . "</i>" .
        "\n<i>پیشرفت تقریبی: " . $state['percent'] . "٪</i>";

    bot('editMessageText', [
        'chat_id'    => $state['chat_id'],
        'message_id' => $state['msg_id'],
        'text'       => $progress_text,
        'parse_mode' => 'HTML'
    ]);
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
    $msg = ($user_lang == 'en') ? "🧠 Welcome to Gemini.\nType your question, send a photo of the exam, send your *code* or *log file* for debugging, or ask me to *draw* / *generate an image* of something:" :
          (($user_lang == 'ar') ? "🧠 مرحباً بك في Gemini.\nاكتب سؤالك، أرسل صورة الامتحان، أرسل *الكود* أو *ملف السجل* للمراجعة، أو اطلب مني *رسم* / *إنشاء صورة*:" :
          "🧠 به بخش هوش مصنوعی Gemini خوش آمدید.\nسوال درسی خود را تایپ کنید، عکس بفرستید، کد یا فایل لاگ خود را برای بررسی و رفع باگ ارسال کنید، یا بگویید مثلاً «یک تصویر از ... بساز»:");

    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => gemini_to_telegram_html($msg),
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['keyboard' => [[$back_btn]], 'resize_keyboard' => true])
    ]);
    exit;
}

// 3. پردازش پیام ارسالی (متن یا عکس یا فایل) در وضعیت چت
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
    // پشتیبانی از فایل آپلودی (سند) - مثلا فایل پایتون/لاگ که کاربر مستقیما به‌عنوان فایل فرستاده
    // ---------------------------------------------------------------
    $uploaded_file_content = null;
    $uploaded_file_name = null;
    if (isset($message->document)) {
        $doc = $message->document;
        $uploaded_file_name = $doc->file_name ?? 'file.txt';
        $file_size = $doc->file_size ?? 0;

        // فقط فایل‌های متنی/کد را می‌خوانیم (سقف ۲ مگابایت برای جلوگیری از مصرف زیاد حافظه)
        if ($file_size > 0 && $file_size < 2 * 1024 * 1024) {
            $file_info = bot('getFile', ['file_id' => $doc->file_id]);
            $doc_file_path = $file_info->result->file_path ?? null;
            if ($doc_file_path) {
                $doc_url = "https://api.telegram.org/file/bot" . $token . "/" . $doc_file_path;
                $raw_content = @file_get_contents($doc_url);
                if ($raw_content !== false) {
                    $uploaded_file_content = $raw_content;
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // تشخیص اینکه آیا کاربر درخواست «ساخت تصویر» دارد یا سوال معمولی
    // ---------------------------------------------------------------
    $is_image_request = false;
    if (!empty($text) && !isset($message->photo) && !$uploaded_file_content) {
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
                'caption' => gemini_to_telegram_html($caption_text),
                'parse_mode' => 'HTML',
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
    // مسیر ب) حل سوال متنی / تصویری / بررسی کد و فایل (مسیر اصلی)
    // =================================================================

    $models = [
        'gemini-2.5-flash',
        'gemini-2.5-pro'
    ];

    $wait_text = "⏳ در حال تحلیل و حل سوال... لطفاً چند ثانیه صبر کنید.";

    // تشخیص اینکه ورودی شبیه کد/لاگ است تا پیام انتظار و پرامپت سیستم را عوض کنیم
    $input_for_detection = $uploaded_file_content ?? $text;
    $is_code_task = $input_for_detection ? gemini_looks_like_code_or_log($input_for_detection) : false;
    if ($is_code_task || $uploaded_file_content) {
        $wait_text = "🛠 در حال بررسی دقیق کد/لاگ شما (خط به خط)... لطفاً چند لحظه صبر کنید.";
    }

    $wait_msg = bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $wait_text
    ]);
    $wait_msg_id = $wait_msg->result->message_id ?? null;

    // وضعیت‌های نمایشی که هر چند لحظه یک‌بار (حین درخواست به Gemini) جایگزین هم می‌شوند
    $gemini_progress_steps = $is_code_task || $uploaded_file_content
        ? [
            "در حال خواندن خط به خط کد/لاگ شما...",
            "در حال شناسایی باگ‌ها و خطاهای احتمالی...",
            "در حال بررسی منطق و ساختار کد...",
            "در حال آماده‌سازی نسخه اصلاح‌شده...",
            "کمی پیچیده است، لطفاً چند لحظه دیگر صبر کنید...",
        ]
        : [
            "در حال خواندن و تحلیل دقیق سوال شما...",
            "در حال بررسی زمینه و جزئیات موضوع...",
            "در حال جست‌وجو برای بهترین پاسخ ممکن...",
            "در حال بررسی صحت و دقت پاسخ...",
            "در حال آماده‌سازی و فرمت‌بندی پاسخ نهایی...",
        ];
    $gemini_progress_state = [
        'msg_id'         => $wait_msg_id,
        'chat_id'        => $chat_id,
        'base_text'      => $wait_text,
        'step_index'     => 0,
        'percent'        => 5,
        'last_edit_time' => 0,
    ];

    $payload = [];

    // پرامپت سیستم پایه (همیشه فعال)
    $base_instruction = "\nYou are a helpful, friendly tutor and an expert senior software engineer. " .
        "IMPORTANT: You must reply entirely in this language code: " . $user_lang . ". " .
        "Formatting rules (very important, follow exactly): " .
        "Use **double asterisks** around important terms/headings for bold emphasis. " .
        "Use a line containing only --- to separate distinct sections when it improves readability. " .
        "Whenever you show any code, command, file content, error message, or log output, you MUST wrap it in a fenced code block using triple backticks with the language name right after the opening backticks, e.g. ```python\\n...code...\\n```. Never show code outside of a code block.";

    // پرامپت اضافه‌ی تخصصی برنامه‌نویسی، فقط زمانی که ورودی شبیه کد/لاگ/فایل بود
    $code_instruction = "";
    if ($is_code_task || $uploaded_file_content) {
        $code_instruction = "\n\nThe user has sent code, a log file, or asked a programming question. " .
            "Act as an extremely careful, senior-level code reviewer and debugger, similar to a top-tier AI coding assistant. " .
            "Do the following precisely: " .
            "1) Identify the programming language automatically. " .
            "2) Read the ENTIRE code or log carefully, line by line — do not skim. " .
            "3) Identify every bug, syntax error, logic error, security issue, or exception cause you find, citing the relevant line(s) or function names. " .
            "4) If it's a log/traceback, explain exactly what failed and why, in plain language. " .
            "5) Provide a corrected, complete, working version of the code in a single fenced code block with the correct language tag — not just a diff or snippet, unless the user only asked about one specific part. " .
            "6) Briefly explain what you changed and why, using **bold** for key terms. " .
            "7) If relevant, suggest best-practice improvements (performance, readability, security) after the main fix. " .
            "Be thorough and precise — accuracy matters more than brevity for code tasks.";
    }

    $system_instruction = $base_instruction . $code_instruction;

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
    elseif ($uploaded_file_content !== null) {
        // فایل کد/لاگ آپلودشده توسط کاربر
        $user_caption = $message->caption ?? "این فایل را با دقت کامل بررسی کن، باگ‌ها/خطاها را پیدا کن و نسخه اصلاح‌شده را بده.";
        $file_text_for_prompt = "نام فایل: " . ($uploaded_file_name ?? 'unknown') . "\n\n" .
            "محتوای فایل:\n```\n" . $uploaded_file_content . "\n```\n\n" . $user_caption;

        $payload = [
            "contents" => [
                ["parts" => [["text" => $file_text_for_prompt . $system_instruction]]]
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
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت پشتیبانی نمی‌شود. لطفاً متن، عکس، یا فایل کد/لاگ ارسال کنید."]);
        exit;
    }

    $success = false;
    $reply_text = '';
    $debug_log = [];

    foreach ($models as $current_model) {
        // هر بار که مدل عوض می‌شود (تلاش مجدد)، یک‌بار هم پیام انتظار را به‌روزرسانی کن
        gemini_edit_wait_progress($gemini_progress_state, $gemini_progress_steps);

        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/" . $current_model . ":generateContent?key=" . $gemini_api_key;

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        // فعال‌سازی progress callback تا حین انتظار برای پاسخ Gemini، پیام کاربر هر چند ثانیه ادیت شود
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$gemini_progress_state, $gemini_progress_steps) {
            gemini_edit_wait_progress($gemini_progress_state, $gemini_progress_steps);
        });
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
        // ارسال هوشمند: اگر کد سنگین بود به‌صورت فایل، در غیر اینصورت پیام فرمت‌شده با HTML
        gemini_deliver_reply($chat_id, $reply_text, $from_id);

        // برای سوالات آموزشی معمولی (نه کد/لاگ)، یک تصویر مرتبط هم برای درک بهتر می‌فرستیم
        if (!$is_code_task && !$uploaded_file_content && !empty($text)) {
            gemini_maybe_send_related_image($chat_id, $text);
        }
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
