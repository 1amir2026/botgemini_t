<?php
// فایل موقت هوش مصنوعی
$bot_name = str_replace('.php', '', basename(__FILE__));

bot('sendMessage', [
    'chat_id' => $chat_id,
    'text' => "بخش هوش مصنوعی $bot_name به زودی در مرحله بعد کدنویسی خواهد شد.\nمتن ارسالی شما: $text"
]);