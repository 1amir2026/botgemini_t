<?php
// این فایل توسط bot.php اینکلود می‌شود و به متغیرهای آن دسترسی دارد.

$balance = $user['balance'] ?? 0;

if ($user_lang == 'fa') {
    $text_reply = "👤 **مشخصات حساب کاربری شما:**\n\n" .
                  "🆔 آیدی عددی: `$from_id`\n" .
                  "🌐 زبان انتخاب شده: فارسی\n" .
                  "💰 موجودی حساب: $balance تومان";
} elseif ($user_lang == 'en') {
    $text_reply = "👤 **Your Account Details:**\n\n" .
                  "🆔 User ID: `$from_id`\n" .
                  "🌐 Language: English\n" .
                  "💰 Balance: $balance Credits";
} else {
    $text_reply = "👤 **تفاصيل حسابك:**\n\n" .
                  "🆔 المعرف الرقمي: `$from_id`\n" .
                  "🌐 اللغة: العربية\n" .
                  "💰 الرصيد: $balance نقطة";
}

bot('sendMessage', [
    'chat_id' => $chat_id,
    'text' => $text_reply,
    'parse_mode' => 'Markdown'
]);