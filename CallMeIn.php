<?php

/**
 * Демон слушающий входящие и переведенные звонки по AMI
 */

use PAMI\Client\Exception\ClientException;
use PAMI\Message\Action\SetVarAction;
use PAMI\Message\Event\AttendedTransferEvent;
use PAMI\Message\Event\DialBeginEvent;
use PAMI\Message\Event\DialEndEvent;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event\HangupEvent;
use PAMI\Message\Event\NewextenEvent;

// Проверка на запуск из браузера
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die('access error');

require __DIR__ . '/vendor/autoload.php';

$helper = new HelperFuncs();
$callami = new CallAMI();
$logger = new CallMeLogger();

// Объект с глобальными массивами
$globalsObj = Globals::getInstance();

// Экстеншен для входящих
$globalsObj->extensions = $helper->getConfig('extensions');

$pamiClient = $callami->NewPAMIClient();

try {
    $pamiClient->open();
    $helper->writeToLog('Started', 'supervisor', 'NewPAMIClient');
} catch (ClientException $e) {
    $helper->writeToLog($e, 'supervisor', 'NewPAMIClient');
}

/**
 * NewextenEvent
 * Обработчик события создания канала
 */
$pamiClient->registerEventListener(

    function (NewextenEvent $event) use ($helper, $callami, $globalsObj) {

        $callUniqueId = $event->getUniqueid(); // Уникальный id звонка
        $extNum = $event->getKey('CallerIDNum'); // Входящий телефон
        $callChannel = $event->getChannel(); // Канал звонка
        
        $filename = "/var/spool/asterisk/monitor/" . date("Y/m/d") . "/in-s-" . $extNum . "-" . date("Ymd-His") . '-' . $callUniqueId . '.wav';
        $globalsObj->FullFnameUrls[$callUniqueId] = $filename;

        $helper->writeToLog([
            'extNum' => $extNum,
            'callUniqueId' => $callUniqueId,
            'callChannel' => $callChannel,
            'filename' => $filename,
        ], 'callmein', "Новый входящий звонок c $extNum [NewextenEvent]");

        // Добавляем звонок в глобальный массив, для обработки в других событиях
        $globalsObj->uniqueids[] = $callUniqueId;

        // Получаем из битрикса полное имя контакта/компании по номеру телефона и отправляем переменную в астериск
        if ($CallerData = $helper->getCrmDataByExtNum($extNum)) {

            $pamiClient = $callami->NewPAMIClient();
            $pamiClient->open();
            $pamiClient->send(new SetVarAction('CallMeCallerIDName', $CallerData['name'], $callChannel));
            $resultFromB24 = $pamiClient->send(new SetVarAction('CallMeCallerResponsible', $CallerData['responsible'], $callChannel));
            $pamiClient->close();

            $helper->writeToLog(['Данные из Битрикс24' => $CallerData, 'Ответ Астериска' => $resultFromB24->getMessage()], 'callmein', "$extNum есть в Битрикс24 [NewextenEvent]");
        } else {
            $helper->writeToLog(['Данные из Битрикс24' => "Нет данных"], 'callmein', "Новый телефонный номер $extNum [NewextenEvent]");
        }
        return true;
    },
    function (EventMessage $event) use ($globalsObj, $helper) {
        return
            $event instanceof NewextenEvent
            && in_array($event->getExten(), $globalsObj->extensions) // только от экстеншенов из настроек
            && $event->getKey('Application') == 'Wait'; // только экшен Wait
    }
);

/**
 * DialBeginEvent
 * Обработчик события начала дозвона
 */
$pamiClient->registerEventListener(

    function (EventMessage $event) use ($helper, $globalsObj) {

        $callUniqueId = $event->getUniqueid();
        $intNum = $event->getDestCallerIDNum();
        $extNum = $event->getCallerIDNum();

        $globalsObj->Durations[$callUniqueId] = time(); // Время начала звонка

        $helper->writeToLog([
            'callUniqueid' => $callUniqueId,
            'intNum' => $intNum,
            'extNum' => $extNum,
        ], 'callmein', "Начало дозвона с $extNum на $intNum [DialBeginEvent]");

        // Регистриуем звонок в битриксе
        $globalsObj->calls[$callUniqueId] = $helper->runInputCall($intNum, $extNum);
    },
    function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof DialBeginEvent
            && in_array($event->getUniqueid(), $globalsObj->uniqueids); // Проверяем входит ли событие в массив с uniqueid внешних звоноков
    }
);

/**
 * DialEndEvent
 * Обработчик события окончания дозвона
 */
$pamiClient->registerEventListener(

    function (EventMessage $event) use ($helper, $globalsObj) {

        $callUniqueId = $event->getUniqueid();
        $intNum = $globalsObj->intNums[$callUniqueId] = $event->getDestCallerIDNum();
        $extNum = $event->getCallerIDNum();

        $arLog = [
            'intNum' => $intNum,
            'extNum' => $extNum,
            'callUniqueid' => $callUniqueId,
            'CALL_ID' => $globalsObj->calls[$callUniqueId]
        ];

        switch ($event->getDialStatus()) {

            case 'ANSWER': // Отвеченный звонок
                $helper->hideInputCallExcept($intNum, $globalsObj->calls[$callUniqueId]);
                $globalsObj->Dispositions[$callUniqueId] = "ANSWERED";
                $helper->writeToLog($arLog, 'callmein', "Окончание дозвона с $extNum на $intNum - ANSWER [DialEndEvent]");
                break;

            case 'BUSY': // Занято
                $helper->hideInputCall($intNum, $globalsObj->calls[$callUniqueId]);
                $globalsObj->Dispositions[$callUniqueId] = "BUSY";
                $helper->writeToLog($arLog, 'callmein', "Окончание дозвона с $extNum на $intNum - BUSY [DialEndEvent]");
                break;

            case 'CANCEL': // Звонивший бросил трубу
                $helper->hideInputCall($intNum, $globalsObj->calls[$callUniqueId]);
                $globalsObj->Dispositions[$callUniqueId] = "NO ANSWER";
                $helper->writeToLog($arLog, 'callmein', "Окончание дозвона с $extNum на $intNum - CANCEL [DialEndEvent]");
                break;
        }
    },
    function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof DialEndEvent
            && in_array($event->getUniqueid(), $globalsObj->uniqueids); // Проверяем входит ли событие в массив с uniqueid внешних звонков
    }
);

/**
 * HangupEvent
 * Окончание разговора
 */
$pamiClient->registerEventListener(

    function (EventMessage $event) use ($helper, $globalsObj) {

        $callUniqueId = $event->getUniqueID();
        $CallIntNum = $globalsObj->intNums[$callUniqueId];
        $extNum = $event->getCallerIDNum();
        $FullFname = $globalsObj->FullFnameUrls[$callUniqueId];
        $CallDuration = $event->getCreatedDate() - $globalsObj->Durations[$callUniqueId];
        $CallDisposition = $globalsObj->Dispositions[$callUniqueId];
        $call_id = $globalsObj->calls[$callUniqueId];

        $helper->writeToLog([
            'callUniqueId' => $callUniqueId,
            'call_id' => $call_id,
            'intNum' => $CallIntNum,
            'extNum' => $extNum,
            'FullFname' => $FullFname,
            'duration' => $CallDuration,
            'disposition' => $CallDisposition,
            'date' => $event->getCreatedDate()
        ],
            'callmein', "Окончание разговора $extNum c $CallIntNum [HangupEvent]");

        // Отправляем событие окончания звонка в Битрикс24
        $endOtputCall = $helper->endOutputCall($call_id, $CallIntNum, $CallDuration, $CallDisposition);
        $resultFromB24 = $helper->uploadRecordedFile($call_id, $FullFname, $CallDisposition);

        $helper->writeToLog(['Ответ от Битрикс24' => $resultFromB24], 'callmein', "Отправка записи разговора $extNum с $CallIntNum в Битрикс24 [HangupEvent]");
        $helper->writeToLog(['Ответ от Битрикс24' => $endOtputCall], 'callmein', "Завершение звонка $extNum с $CallIntNum [HangupEvent]");

        // Удаляем из массивов тот вызов, который завершился
        $helper->removeItemFromArray($globalsObj->uniqueids, $callUniqueId, 'value');
        $helper->removeItemFromArray($globalsObj->intNums, $callUniqueId, 'key');
        $helper->removeItemFromArray($globalsObj->FullFnameUrls, $callUniqueId, 'key');
        $helper->removeItemFromArray($globalsObj->Durations, $callUniqueId, 'key');
        $helper->removeItemFromArray($globalsObj->Dispositions, $callUniqueId, 'key');
        $helper->removeItemFromArray($globalsObj->calls, $callUniqueId, 'key');
    },
    function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof HangupEvent
            && in_array($event->getUniqueID(), $globalsObj->uniqueids);
    }
);


//////////////////////////////////////////////////////////////////
///                         ТРАНСФЕРЫ !!!
//////////////////////////////////////////////////////////////////

/**
 * AttendedTransferEvent
 * Обработчик события перевода звонка
 */
$pamiClient->registerEventListener(

    function (AttendedTransferEvent $event) use ($helper, $globalsObj) {

        $callUniqueId = $event->getOrigTransfererUniqueid();
        $external = $event->getKey('TransferTargetCallerIDNum');

        // Взависимости от того что куда перетащили в клиенте
        if (strlen($external) <= 4) {
            $action = 'внешний на внутренний';
            $external = $event->getTransfereeCallerIDNum();
            $bitrixId = $event->getOrigTransfererCallerIDName();
            $from = $event->getSecondTransfererCallerIDNum();
            $to = $event->getSecondTransfererConnectedLineNum();
            //$end_id = $event->getKey('TransferTargetUniqueid');
            $end_id = trim($event->getKey('TransferTargetUniqueid')) ?: $event->getKey('TransfereeUniqueid');
            $mask_id = $callUniqueId;

            $helper->writeToLog($event->getRawContent(), 'transfers', "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");

        } else {
            $action = 'внутренний на внешний';
            $bitrixId = $event->getSecondTransfererCallerIDName();
            $from = $event->getOrigTransfererCallerIDNum();
            $to = $event->getTransfereeCallerIDNum();
            $end_id = $event->getKey('TransfereeUniqueid');
            $mask_id = $event->getSecondTransfererUniqueid();
        }

        // Входящий/Исходящий
        if (stristr($bitrixId, 'externalCall') === false) {
            $type = 'incoming';
            if (array_key_exists($event->getTransfereeUniqueid(), $globalsObj->calls)) {
                $bitrixId = $globalsObj->calls[$event->getTransfereeUniqueid()];
            } else {
                // preg_match('/TransferTargetLinkedid: ([0-9].+)/', $event->getRawContent(), $matches);
                // $bitrixId = $globalsObj->calls[trim($matches[1])];
                $bitrixId = $globalsObj->calls[$event->getKey('TransferTargetLinkedid')];
            }
        } else {
            $type = 'outgoing';
        }

        // Пишем в глобальную переменную
        $globalsObj->transfers[$end_id] = [
            'external' => $external,
            'callUniqueId' => $callUniqueId,
            'bitrixId' => $bitrixId,
            'from' => $from,
            'to' => $to,
            'to_id' => $event->getSecondTransfererUniqueid(),
            'type' => $type,
            'mask_id' => $mask_id
        ];

        $helper->writeToLog([
            "тип" => $type,
            "перевод" => $action,
            "id" => $callUniqueId,
            'to_id' => $event->getSecondTransfererUniqueid(),
            "end_id" => $end_id,
            "внешний номер" => $external,
            "кто перевел" => $from,
            "на кого перевел" => $to,
            "id в битриксе" => $bitrixId
        ],
            'transfers', "Перевод звонка $external c $from на $to [AttendedTransferEvent]");

        // Прячем карточку у того кто перевел звонок
        $hide = $helper->hideInputCall($from, $bitrixId);

        // Показываем карточку тому на кого перевели звонок
        $show = $helper->showInputCall($to, $bitrixId);

        $helper->writeToLog([
            "Убрать катрочку у $from" => $hide,
            "Показать карточку $to" => $show
        ],
            'transfers', "Перевод карточки звонка [AttendedTransferEvent]");
    },
    function (EventMessage $event) use ($globalsObj) {
        return $event instanceof AttendedTransferEvent;
    }
);

/**
 * HangupEvent
 * Окончание переведенного звонка
 */
$pamiClient->registerEventListener(

    function (EventMessage $event) use ($helper, $globalsObj) {

        $data = $globalsObj->transfers[$event->getKey('Uniqueid')];
        unset($globalsObj->transfers[$event->getKey('Uniqueid')]);

        // Поиск нужного файла
        if ($data['type'] == 'incoming') {
            $file_masks = [
                "/var/spool/asterisk/monitor/" . date('Y/m/d') . "/in-s-" . $data['external'] . "-" . date('Ymd') . "-*-" . $event->getKey('Linkedid') . ".wav"
            ];
        } else {
            $file_masks = [
                "/var/spool/asterisk/monitor/" . date('Y/m/d') . "/out-" . $data['external'] . "-" . $data['from'] . "-" . date('Ymd') . "-*-" . $data['mask_id'] . ".wav",
                "/var/spool/asterisk/monitor/" . date('Y/m/d') . "/out-bitrix-" . $data['external'] . "-" . $data['from'] . "-" . date('Ymd') . "-093801.wav"
            ];
        }
        $file = '';
        foreach ($file_masks as $mask) {
            foreach (glob("$mask") as $rec) {
                $file = $rec;
            }
        }

        // Обновление записи звонка
        $BXattachRecord = !empty($file) ? $helper->uploadRecordedFile($data['bitrixId'], $file, 'ANSWERED', 'full_') : '';
        $helper->endOutputCall($data['bitrixId'], $data['to'], '', 'ANSWERED');

        $helper->writeToLog([
            'id' => $event->getKey('Uniqueid'),
            'external' => $event->getKey('ConnectedLineNum'),
            'file_masks' => $file_masks,
            'file' => $file,
            'data' => $data,
            'BXattachRecord' => $BXattachRecord
        ],
            'transfers', "HangupEvent");

    },
    function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof HangupEvent
            && ($event->getKey('Context') == 'from-internal' || $event->getKey('Context') == 'macro-dial')
            && array_key_exists($event->getKey('Uniqueid'), $globalsObj->transfers);
    }
);


$pamiClient->registerEventListener(

    function (EventMessage $event) use ($helper, $globalsObj) {
        $helper->writeToLog($event->getKey('Uniqueid'), 'transfers', "HangupEvent");

    },
    function (EventMessage $event) use ($globalsObj) {
        return
            $event instanceof HangupEvent
            && ($event->getKey('Context') == 'from-internal' || $event->getKey('Context') == 'macro-dial');
    }
);

while (true) {
    $pamiClient->process();
    //$pamiClient->setLogger($logger);
    usleep($helper->getConfig('listener_timeout'));
}

$pamiClient->ClosePAMIClient($pamiClient);