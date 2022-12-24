<?php

/*
    Какие требования у данного бота?

    Для работы бота нужен домен и установленный на нем SSL-сертификат.
    Потому что работает бот через веб-хуки.



    Как создать бота?

    В Телеграм ищем юзера @BotFather и создаем ногово бота командой /newbot.
    Сперва указываем имя, а потом юзернейм. Юзернейм должен быть уникален и поэтому придется поподбирать.
    Впоследствии можно сменить имя боту командой /setname, чтобы оно совпадало с юзернеймом.
    Получаем токен, создаем файл config.php и вписываем его туда:
    <?php
    $bot = array (
        "token" => "1234567890:7PqFMAFHA_ebTJ35Q1ZIZyZCtVARfoWapas",
    );



    Как установить бота?

    Зайдите на свой сайт по адресу https://bot.site.ru/bot.php?cmd=install
    Для удаления хука зайдите по адресу https://bot.site.ru/bot.php?cmd=uninstall
    


    Что делать дальше?

    Пригласите бота в группу, сделайте администратором и дайте права удалять сообщения и блокировать пользователей.



    Что доделать?



*/

// Настройки бота
require( "config.php" );
$timeout = 60; // 1 минута на нажатие кнопки

function telegram( $cmd, $data = array() ) {
    global $bot;
    $curl = curl_init();
    curl_setopt_array( $curl, array(
        CURLOPT_URL => "https://api.telegram.org/bot{$bot['token']}/{$cmd}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => http_build_query( $data ),
    ) );
    $resp = curl_exec( $curl );
    curl_close( $curl );
    // Для отладки раскомментируйте
    file_put_contents( "input.log", $resp . "\n", FILE_APPEND );
    return json_decode( $resp, true );
}


// Команды управления ботом через адресную строку браузера
if ( isset( $_GET["cmd"] ) ) {
    switch ( $_GET["cmd"] ) {

        case "uninstall":
            exit( var_export( telegram( "setWebhook" ), true ) );
        break;

        case "install":
            exit( var_export( telegram( "setWebhook", array( "url" => "https://{$_SERVER['SERVER_NAME']}{$_SERVER['PHP_SELF']}" ) ), true ) );
        break;

    }
}


// Входные данные от Телеграма
$input_raw = file_get_contents( "php://input" );

if ( empty( $input_raw ) ) {
    exit();
}

// Для отладки раскомментируйте
file_put_contents( "input.log", $input_raw . "\n", FILE_APPEND );
$input = json_decode( $input_raw, true );

if ( ! $input ) {
    exit();
}



// Считываем сохраненное состояние
if ( file_exists( "data.php" ) ) {
    // Блокируем файл чтобы не прочитать мусор в тот момент когда
    // работает команда записи в файл
    flock( "data.php", LOCK_SH );
    include( "data.php" );
    // разблокируем
    flock( "data.php", LOCK_UN );
} else {
    $data = array(
        "new_chat_members" => array(),
    );
}


if ( isset( $input["message"]["new_chat_member"] ) ) {
    
    if ( $input["message"]["new_chat_member"]["is_bot"] === false ) {
        // Зашел новый юзер (не бот якобы).

        // Приветствуем и показываем кнопку.
        // https://core.telegram.org/bots/api#sendmessage
        // https://core.telegram.org/bots/api#inlinekeyboardmarkup
        $r = telegram( "sendMessage", array(
            "chat_id" => $input["message"]["chat"]["id"],
            "text" => "Привет, {$input["message"]["new_chat_member"]["first_name"]}! Нажми кнопочку в течении минуты.",
            "reply_markup" => json_encode( array( "inline_keyboard" => array(
                array( // row 1
                    array( // button 1
                        "text" => "Разблокироваться",
                        "callback_data" => "123456",
                    ),
                ),
            ) ) ),
        ) );

        // Добавляем таким образом потому что один и тот же юзер может зайти в разные группы.
        $data["new_chat_members"][] = array(
            "id" => $input["message"]["new_chat_member"]["id"],
            "chat_id" => $input["message"]["chat"]["id"],
            "date" => $input["message"]["date"],                 // для таймаута капчи
            "message_id" => $r["result"]["message_id"],
        );

        save_data();
    }

} elseif ( isset( $input["message"] ) ) {

    // Пришло новое сообщение. Удалим если оно от пользователя в массиве $data["new_chat_members"]
    // Заодно проверим таймауты и выкинем кто не нажал кнопку
    $time = time();

    foreach( $data["new_chat_members"] as $n => $user ) {

        if ( $user["id"] === $input["message"]["from"]["id"] && $user["chat_id"] === $input["message"]["chat"]["id"] ) {

            // https://core.telegram.org/bots/api#deletemessage
            telegram( "deleteMessage", array(
                "chat_id" => $input["message"]["chat"]["id"],
                "message_id" => $input["message"]["message_id"],
            ) );

        }

        // Если истекло время то баним и удаляем кнопку
        if ( $time - $user["date"] > $timeout ) {
            ban_and_clear( $n );
        }

    }

} elseif ( isset( $input["callback_query"] ) ) {

    // Кто-то нажал на кнопку. Удалим его из массива, если не истекло время
    // Заодно проверим таймауты и выкинем кто не нажал кнопку
    $time = time();

    foreach( $data["new_chat_members"] as $n => $user ) {

        if ( $user["id"] ===  $input["callback_query"]["from"]["id"] && $user["chat_id"] === $input["callback_query"]["message"]["chat"]["id"] && $time - $user["date"] <= $timeout ) {

            telegram( "deleteMessage", array(
                "chat_id" => $user["chat_id"],
                "message_id" => $user["message_id"],
            ) );

            unset( $data["new_chat_members"][$n] );
            save_data();

        } elseif ( $time - $user["date"] > $timeout ) {
            ban_and_clear( $n );
        }

    }

}

function ban_and_clear( $n ) {
    global $data;

    telegram( "banChatMember", array(
        "user_id" => $data["new_chat_members"][$n]["id"],
        "chat_id" => $data["new_chat_members"][$n]["chat_id"],
        "until_date" => time() + 60,
    ) );

    telegram( "deleteMessage", array(
        "chat_id" => $data["new_chat_members"][$n]["chat_id"],
        "message_id" => $data["new_chat_members"][$n]["message_id"],
    ) );

    unset( $data["new_chat_members"][$n] );
    save_data();

}


function save_data() {
    global $data;
    @$r = file_put_contents( "data.php", '<?php
$data = ' . var_export( $data, true) . ";\n", LOCK_EX );
    return $r;
}
