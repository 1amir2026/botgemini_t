<?php
require_once 'config.php';

$update = json_decode(file_get_contents('php://input'));
if (!$update) exit;

$message = $update->message ?? null;
$callback_query = $update->callback_query ?? null;

if ($message) {
    $from_id = $message->from->id;
    $text = $message->text ?? ($message->caption ?? '');
    $username = $message->from->username ?? null;
    $chat_id = $message->chat->id;
    $message_id = $message->message_id;
    $chat_type = $message->chat->type ?? 'private';
} elseif ($callback_query) {
    $from_id = $callback_query->from->id;
    $data = $callback_query->data;
    $chat_id = $callback_query->message->chat->id;
    $message_id = $callback_query->message->message_id;
    $chat_type = $callback_query->message->chat->type ?? 'private';
}

// --------------------------------------------------------
// اطلاعات سازنده ربات (برای پاسخ‌های هوشمند و معرفی)
// --------------------------------------------------------
define('BOT_CREATOR_TEXT', "🤖 این ربات توسط تیم @BloxyDesign و @ItzAmiRxD طراحی و ساخته شده است.");
define('BOT_BEST_CHANNEL_TEXT', "🏆 بدون شک بهترین کانال تامنیل، ماینکرافت، دیزاین و طراحی، کانال @BloxyDesign هست! کیفیت کارها، خلاقیت و سرعت تحویلشون واقعاً عالیه. حتما یه سر بزن 🔥");

// --------------------------------------------------------
// تابع کمکی: تشخیص و پاسخ به سوالات/کلیدواژه‌های ثابت
// (سازنده ربات، بهترین کانال، ایستر اگ «پیکسیا»)
// خروجی: true یعنی پیام پردازش و پاسخ داده شد (دیگر نیازی به ادامه پردازش نیست)
// --------------------------------------------------------
function bot_handle_special_triggers($text, $chat_id, $message_id) {
    if (trim((string)$text) === '') return false;
    $lower = mb_strtolower($text);

    // ایستر اگ: هر جا کلمه «پیکسیا» در پیام باشد
    if (mb_strpos($text, 'پیکسیا') !== false || mb_strpos($lower, 'pixia') !== false) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'reply_to_message_id' => $message_id,
            'text' => "پیکسیا کیه؟ نشناختم 🤔"
        ]);
        return true;
    }

    // سوال درباره سازنده ربات
    if (preg_match('/(کی|چه ?کسی|چه تیمی).{0,15}(ساخت|طراحی کرد|درست کرد|توسعه داد)/u', $text)
        || preg_match('/سازنده.{0,10}(ربات|بات)/u', $text)
        || preg_match('/(ربات|بات).{0,10}(رو|را).{0,10}(کی|چه ?کسی).{0,10}ساخت/u', $text)
        || mb_strpos($lower, 'who made you') !== false
        || mb_strpos($lower, 'who created you') !== false
        || mb_strpos($lower, 'who developed you') !== false) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'reply_to_message_id' => $message_id,
            'text' => BOT_CREATOR_TEXT
        ]);
        return true;
    }

    // سوال درباره بهترین کانال تامنیل/ماینکرافت/دیزاین
    if (preg_match('/بهترین.{0,15}(کانال|چنل).{0,25}(تامنیل|ماینکرافت|دیزاین|طراحی)/u', $text)
        || preg_match('/(تامنیل|ماینکرافت|دیزاین|طراحی).{0,25}بهترین.{0,15}(کانال|چنل)/u', $text)) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'reply_to_message_id' => $message_id,
            'text' => BOT_BEST_CHANNEL_TEXT
        ]);
        return true;
    }

    return false;
}

// این تریگرها روی هر پیام متنی (در خصوصی یا گروه) قبل از هر پردازش دیگری بررسی می‌شوند
if ($message && $text) {
    if (bot_handle_special_triggers($text, $chat_id, $message_id)) {
        exit;
    }
}

// --------------------------------------------------------
// تابع کمکی: پاسخ هوشمند Bloxy در گروه‌ها
// فعال می‌شود وقتی: نام Bloxy/بلاکسی صدا زده شود، یا کاربر روی پیام خودِ ربات ریپلای کند.
// متن پیامی که رویش ریپلای شده را هم به‌عنوان context به مدل می‌دهد.
// --------------------------------------------------------
function bot_group_ai_reply($message, $chat_id, $message_id, $user_lang) {
    $gemini_api_key = getenv('GEMINI_API_KEY');
    if (!$gemini_api_key) return;

    $user_text = $message->text ?? ($message->caption ?? '');
    if (trim($user_text) === '') return;

    // حذف نام صدا زدن ربات از ابتدای/میان پیام برای تمیزتر شدن سوال
    $clean_text = preg_replace('/\b(bloxy)\b/iu', '', $user_text);
    $clean_text = preg_replace('/(بلاکسی|بلوکسی)/u', '', $clean_text);
    $clean_text = trim($clean_text, " \t\n\r\0\x0B,،:؛-");
    if ($clean_text === '') $clean_text = $user_text;

    $context_prefix = '';
    if (isset($message->reply_to_message)) {
        $replied = $message->reply_to_message;
        $replied_text = $replied->text ?? ($replied->caption ?? '');
        if (trim($replied_text) !== '') {
            $context_prefix = "پیامی که کاربر روی آن ریپلای کرده است:\n\"" . $replied_text . "\"\n\n";
        }
    }

    $prompt = $context_prefix .
        "پیام جدید کاربر در گروه (خطاب به تو):\n\"" . $clean_text . "\"\n\n" .
        "تو یک دستیار هوش مصنوعی به نام Bloxy هستی که توسط تیم @BloxyDesign و @ItzAmiRxD ساخته شده‌ای و داخل یک گروه تلگرامی فعالی. " .
        "اگر کسی پرسید کی ساختت، بگو تیم @BloxyDesign و @ItzAmiRxD. " .
        "اگر کسی پرسید بهترین کانال تامنیل/ماینکرافت/دیزاین چیه، بگو @BloxyDesign. " .
        "کوتاه، دوستانه، طبیعی و مثل یک عضو باهوش گروه جواب بده (نه طولانی و رسمی)، با توجه به متنی که رویش ریپلای شده اگر وجود داشت. " .
        "زبان پاسخ را با زبان پیام کاربر هماهنگ کن.";

    $payload = ["contents" => [["parts" => [["text" => $prompt]]]]];
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key;

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $reply_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($reply_text) {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'reply_to_message_id' => $message_id,
            'text' => $reply_text
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'reply_to_message_id' => $message_id,
            'text' => "❌ الان نتونستم جواب بدم، یه لحظه دیگه دوباره امتحان کن."
        ]);
    }
}

// --------------------------------------------------------
// تابع کمکی: بررسی وضعیت عضویت کاربر و ساخت دکمه‌های شیشه‌ای
// --------------------------------------------------------
function get_membership_keyboard($from_id, $db, $text_lang, $user_lang) {
    // بررسی روشن یا خاموش بودن قفل
    $lock_status = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'lock_status'")->fetchColumn() ?: 'on';
    if ($lock_status == 'off') return true; // اگر خاموش بود، کاربر تایید است

    // دریافت تمام کانال‌ها از دیتابیس
    $channels = $db->query("SELECT * FROM channels")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($channels)) return true; // اگر کانالی ثبت نشده بود، کاربر تایید است

    $keyboard = [];
    $is_member_of_all = true;
    $counter = 1;

    foreach ($channels as $channel) {
        $check = bot('getChatMember', [
            'chat_id' => $channel['channel_id'],
            'user_id' => $from_id
        ]);
        $status = $check->result->status ?? 'left';
        
        // اگر کاربر در این کانال عضو نبود
        if (!in_array($status, ['creator', 'administrator', 'member'])) {
            $is_member_of_all = false;
            // ساخت دکمه شیشه‌ای برای عضویت
            $keyboard[] = [['text' => "📢 عضویت در کانال $counter", 'url' => $channel['channel_url']]];
        }
        $counter++;
    }

    if ($is_member_of_all) {
        return true; // عضو همه کانال‌هاست
    } else {
        // اضافه کردن دکمه بررسی عضویت به انتهای لیست دکمه‌ها
        $keyboard[] = [['text' => $text_lang[$user_lang]['check_btn'], 'callback_data' => 'verify_membership']];
        return $keyboard;
    }
}

// دریافت اطلاعات کاربر از دیتابیس
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$from_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$user_lang = $user['lang'] ?? 'fa';

// --------------------------------------------------------
// دستور /start
// --------------------------------------------------------
if ($message && $text == '/start') {
    if (!$user) {
        $stmt = $db->prepare("INSERT INTO users (user_id, username, step) VALUES (?, ?, 'select_lang')");
        $stmt->execute([$from_id, $username]);
    } else {
        $stmt = $db->prepare("UPDATE users SET step = 'select_lang' WHERE user_id = ?");
        $stmt->execute([$from_id]);
    }
    
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text_lang['fa']['select_lang'],
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '🇮🇷 فارسی', 'callback_data' => 'setlang_fa']],
                [['text' => '🇬🇧 English', 'callback_data' => 'setlang_en']],
                [['text' => '🇮🇶 العربية', 'callback_data' => 'setlang_ar']]
            ]
        ])
    ]);
    exit;
}

// --------------------------------------------------------
// پنل مدیریت ادمین
// --------------------------------------------------------
if ($message && $text == '/panel' && $from_id == $admin_id) {
    $db->prepare("UPDATE users SET step = 'admin_panel' WHERE user_id = ?")->execute([$from_id]);
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📊 آمار ربات', 'callback_data' => 'admin_stats']],
            [['text' => '🔐 مدیریت قفل کانال', 'callback_data' => 'admin_lock_menu']]
        ]
    ];
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👨‍💻 به پنل مدیریت خوش آمدید. لطفاً یکی از گزینه‌ها را انتخاب کنید:",
        'reply_markup' => json_encode($keyboard)
    ]);
    exit;
}

// دکمه‌های شیشه‌ای پنل مدیریت
if ($callback_query && strpos($data, 'admin_') === 0 && $from_id == $admin_id) {
    if ($data == 'admin_stats') {
        $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "📊 **آمار ربات شما:**\n\n👥 تعداد کل کاربران: $total_users",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel_back']]]])
        ]);
    }
    elseif ($data == 'admin_panel_back') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📊 آمار ربات', 'callback_data' => 'admin_stats']],
                [['text' => '🔐 مدیریت قفل کانال', 'callback_data' => 'admin_lock_menu']]
            ]
        ];
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "👨‍💻 به پنل مدیریت خوش آمدید. لطفاً یکی از گزینه‌ها را انتخاب کنید:",
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    elseif ($data == 'admin_lock_menu' || strpos($data, 'admin_delchannel_') === 0 || $data == 'admin_toggle_lock') {
        // خاموش و روشن کردن قفل
        if ($data == 'admin_toggle_lock') {
            $current = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'lock_status'")->fetchColumn() ?: 'on';
            $new = ($current == 'on') ? 'off' : 'on';
            $db->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('lock_status', ?)")->execute([$new]);
        }
        // حذف کانال
        elseif (strpos($data, 'admin_delchannel_') === 0) {
            $c_id_to_del = str_replace('admin_delchannel_', '', $data);
            $db->prepare("DELETE FROM channels WHERE id = ?")->execute([$c_id_to_del]);
        }

        // نمایش لیست کانال‌ها و وضعیت قفل
        $lock_status = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'lock_status'")->fetchColumn() ?: 'on';
        $lock_text = ($lock_status == 'on') ? '🟢 روشن' : '🔴 خاموش';
        $channels = $db->query("SELECT * FROM channels")->fetchAll(PDO::FETCH_ASSOC);
        
        $keyboard = [];
        $keyboard[] = [['text' => "وضعیت قفل: $lock_text (تغییر)", 'callback_data' => 'admin_toggle_lock']];
        foreach ($channels as $ch) {
            $keyboard[] = [
                ['text' => '❌ حذف', 'callback_data' => 'admin_delchannel_' . $ch['id']],
                ['text' => $ch['channel_id'], 'url' => $ch['channel_url']]
            ];
        }
        $keyboard[] = [['text' => '➕ افزودن کانال جدید', 'callback_data' => 'admin_add_channel']];
        $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_panel_back']];
        
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "🔐 **مدیریت قفل کانال‌ها**\n\nبرای حذف هر کانال روی ❌ و برای خاموش/روشن کردن قفل روی دکمه تغییر کلیک کنید.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
    elseif ($data == 'admin_add_channel') {
        $db->prepare("UPDATE users SET step = 'admin_wait_channel' WHERE user_id = ?")->execute([$from_id]);
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "لطفاً آیدی کانال و لینک جوین آن را با یک فاصله بفرستید.\n\nمثال:\n`@MyChannel https://t.me/MyChannel`\n\n(برای لغو، دستور /panel را ارسال کنید)",
            'parse_mode' => 'Markdown'
        ]);
    }
    bot('answerCallbackQuery', ['callback_query_id' => $callback_query->id]);
    exit;
}

// دریافت اطلاعات کانال از ادمین
if ($message && $user['step'] == 'admin_wait_channel' && $from_id == $admin_id) {
    $parts = explode(' ', $text);
    if (count($parts) == 2 && strpos($parts[0], '@') === 0 && strpos($parts[1], 'http') === 0) {
        $stmt = $db->prepare("INSERT INTO channels (channel_id, channel_url) VALUES (?, ?)");
        $stmt->execute([$parts[0], $parts[1]]);
        $db->prepare("UPDATE users SET step = 'admin_panel' WHERE user_id = ?")->execute([$from_id]);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کانال با موفقیت اضافه شد.\nبرای مدیریت، مجددا /panel را بزنید."]);
    } else {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت ارسال اشتباه است. لطفاً دقیقاً مانند مثال بفرستید یا برای لغو /panel را بزنید."]);
    }
    exit;
}

// --------------------------------------------------------
// پردازش زبان و عضویت
// --------------------------------------------------------
if ($callback_query && strpos($data, 'setlang_') === 0) {
    $selected_lang = str_replace('setlang_', '', $data);
    $db->prepare("UPDATE users SET lang = ? WHERE user_id = ?")->execute([$selected_lang, $from_id]);
    $user_lang = $selected_lang;
    bot('answerCallbackQuery', ['callback_query_id' => $callback_query->id]);
    
    // بررسی هوشمند عضویت کانال
    $membership_check = get_membership_keyboard($from_id, $db, $text_lang, $user_lang);
    
    if ($membership_check === true) {
        // نیازی به عضویت نیست (کاربر عضو است یا قفل خاموش است)
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
    } else {
        // کاربر باید عضو شود (نمایش دکمه‌های شیشه‌ای کانال‌ها)
        $db->prepare("UPDATE users SET step = 'check_join' WHERE user_id = ?")->execute([$from_id]);
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text_lang[$user_lang]['join_req'],
            'reply_markup' => json_encode(['inline_keyboard' => $membership_check])
        ]);
    }
    exit;
}

if ($callback_query && $data == 'verify_membership') {
    $membership_check = get_membership_keyboard($from_id, $db, $text_lang, $user_lang);
    
    if ($membership_check === true) {
        // تایید نهایی
        $db->prepare("UPDATE users SET step = 'main_menu' WHERE user_id = ?")->execute([$from_id]);
        bot('answerCallbackQuery', ['callback_query_id' => $callback_query->id, 'text' => "✅ عضویت شما تایید شد!"]);
        bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
        
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
    } else {
        // عدم تایید، آپدیت دکمه‌ها (حذف دکمه کانال‌هایی که کاربر تازه عضو شده)
        bot('answerCallbackQuery', ['callback_query_id' => $callback_query->id, 'text' => $text_lang[$user_lang]['not_member'], 'show_alert' => true]);
        bot('editMessageReplyMarkup', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => json_encode(['inline_keyboard' => $membership_check])
        ]);
    }
    exit;
}

// --------------------------------------------------------
// واکنش به نام «Bloxy / بلاکسی» داخل گروه‌ها (برای استفاده گروهی ربات)
// همچنین وقتی کاربر روی پیام خود ربات ریپلای می‌کند، پاسخ هوشمند می‌دهد
// و متن پیامی که رویش ریپلای شده را هم به‌عنوان زمینه به مدل می‌دهد.
// --------------------------------------------------------
if ($message && $chat_type !== 'private' && trim((string)$text) !== '') {
    $lower_text_g = mb_strtolower($text);
    $bloxy_called = (mb_strpos($lower_text_g, 'bloxy') !== false)
        || (mb_strpos($text, 'بلاکسی') !== false)
        || (mb_strpos($text, 'بلوکسی') !== false);

    $is_reply_to_bot = isset($message->reply_to_message->from->is_bot)
        && $message->reply_to_message->from->is_bot === true;

    if ($bloxy_called || $is_reply_to_bot) {
        bot_group_ai_reply($message, $chat_id, $message_id, $user_lang);
        exit;
    }
}

// --------------------------------------------------------
// منوی اصلی و ارتباط با فایل‌های جانبی
// --------------------------------------------------------
if ($message && $user && $user['step'] == 'main_menu') {
    switch ($text) {
        case $text_lang[$user_lang]['btn_account']:
            include_once 'account.php';
            break;
        case $text_lang[$user_lang]['btn_gemini']:
            include_once 'gemini.php';
            break;
        case $text_lang[$user_lang]['btn_chatgpt']:
            include_once 'chatgpt.php';
            break;
        case $text_lang[$user_lang]['btn_claude']:
            include_once 'claude.php';
            break;
        case $text_lang[$user_lang]['btn_deepseek']:
            include_once 'deepseek.php';
            break;
    }
}

// هدایت پیام‌ها به فایل هوش مصنوعی وقتی کاربر داخل چت است
if ($message && $user && $user['step'] == 'gemini_chat') {
    include_once 'gemini.php';
}
