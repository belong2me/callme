<?php
return [
    'CallMeDEBUG' => 1, // Дебаг сообщения в логе: 1 - пишем, 0 - не пишем
    'tech' => 'SIP', // SIP/PJSIP/IAX/etc
    'authToken' => 'n3kpz4vz57ww0b5acwu2exmxry686vp3', // Токен авторизации битрикса
    'bitrixApiUrl' => 'https://bitrix24.site.ru/rest/000/u2vw0s8fty7di2dt/', // Url к api битрикса (входящий вебхук)
    'extensions' => ['666999'], // Экстеншены астериска для входящих звонков
    'context' => 'dial_out', // Исходящий контекст для оригинации звонка
    'asterisk' => [ // Настройки для подключения к астериску
        'host' => '127.0.0.1',
        'scheme' => 'tcp://',
        'port' => 5038,
        'username' => 'username',
        'secret' => '2c036a54a15eca0e3f5a632d80cd1d68',
        'connect_timeout' => 10000,
        'read_timeout' => 10000
    ],
    'listener_timeout' => 300, //скорость обработки событий от asterisk
];