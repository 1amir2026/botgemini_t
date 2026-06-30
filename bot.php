<?php
require_once 'config.php';

$update = json_decode(file_get_contents('php://input'));
if (!$update) exit;

$message = $update->message ?? null;
$callback_query = $update->callback_query ?? null;

if ($message) {
    $from_id = $message->from->id;
    $text = $message->text ?? '';
    $username = $message->from->username ?? null;
    $chat_id = $message->chat->id;
    $message_id = $message->message_id;
} elseif ($callback_query) {
    $from_id = $callback_query->from->id;
    $data = $callback_query->data;
    $chat_id = $callback_query->message->chat->id;
    $message_id = $callback_query->message->message_id;
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