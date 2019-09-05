<?php

/**
 * CallMe calls handler for output calls
 */

require __DIR__ . '/vendor/autoload.php';

$helper = new HelperFuncs();
$callami = new CallAMI();

$tech = $helper->getConfig('tech');
$authToken = $helper->getConfig('authToken');
$context = $helper->getConfig('context');

$request = $_REQUEST;

$group_id = 34; // Группа для которой будут сохраняться звонки в Битрикс24

// Проверяем не пустой ли request
if (!empty($request)) {

    // Определение группы сотрудника
    $groups = [];
    if (isset($request["CallIntNum"])) {
        $groups = $helper->getUserGroups(['UF_PHONE_INNER' => intval($request["CallIntNum"])]);
    } elseif (isset($request['data']['USER_ID'])) {
        $groups = $helper->getUserGroups(['ID' => intval($request['data']['USER_ID'])]);
    }

    $groups = is_array($groups) ? $groups : [$groups];

    if (!in_array($group_id, $groups)) {
        die();
    }

    /**
     * Если указано действие
     */
    if (!is_null($request['action'])) {

        switch ($request['action']) {

            /**
             * Отправляем инфу о начале звонка в битрикс и возвращаем id
             */
            case 'sendcall2b24start':

                $helper->writeToLog($request, $request['CallIntNum'], 'Начало звонка');

                if (is_null($request['CallIntNum']) || is_null($request['CallerId'])) {
                    $helper->writeToLog($request, $request['CallIntNum'], 'Ошибка в параметрах');
                    exit("");
                }

                $result = false;
                $arUser = $helper->getUserByIntNum($request['CallIntNum']);
                $result = $helper->runOutputCall($request);

                if ($result) {
                    $helper->writeToLog($result, $request['CallIntNum'], 'Успешная отправка начала звонка в Битрикс');
                    exit($result);
                } else {
                    $helper->writeToLog($result, $request['CallIntNum'], 'Ошибка отправки звонка в Битрикс');
                    exit("");
                }
                break;

            /**
             * Отправляем инфу об окончании звонка в битрикс
             */
            case 'sendcall2b24end':

                $helper->writeToLog($request, $request['CallIntNum'], 'Окончание звонка');

                if (is_null($request['call_id']) || is_null($request['FullFname']) || is_null($request['CallIntNum']) || is_null($request['CallDuration']) || is_null($request['CallDisposition'])) {
                    $helper->writeToLog($request, $request['CallIntNum'], 'Ошибка в параметрах');
                    exit('error in params');
                }

                $endOtputCall = $helper->endOutputCall($request['call_id'], $request['CallIntNum'], $request['CallDuration'], $request['CallDisposition']);
                $uploadRecordedFile = $helper->uploadRecordedFile($request['call_id'], $request['FullFname'], $request['CallDisposition']);

                $helper->writeToLog($endOtputCall, $request['CallIntNum'], 'Отправка данных о завершении звонка в битрикс');
                $helper->writeToLog($uploadRecordedFile, $request['CallIntNum'], 'Отправка записи звонка в битрикс');
                break;

            /**
             * Отправляем лесом
             */
            default:
                $helper->writeToLog($request, $request['CallIntNum'], 'Действие не определено');
                break;
        }

    } else {

        /**
         * Действие не указано
         * Проверяем авторизацию по токену
         */
        if ($request['auth']['application_token'] === $authToken) {

            $CalledNumber =  preg_replace("/[^0-9]/", '', $request['data']['PHONE_NUMBER_INTERNATIONAL']);
            if (substr($CalledNumber, 0, 1) == '7') {
                substr_replace($CalledNumber, '8', 0, 1);
            }

            $intNum = $helper->getIntNumByUserId($request['data']['USER_ID']);
            $CallID = $request['data']['CALL_ID'];

            $helper->writeToLog($request, $intNum, 'Запрос пришел из вебхука Битрикса. ID в Битрикс - ' . $CallID);

            switch ($request['event']) {

                /**
                 * Звонок из битрикса
                 */
                case 'ONEXTERNALCALLSTART':

                    try {
                        $response = $callami->OriginateCall($intNum, $CalledNumber, $tech, $CallID, $context, $request);
                        $helper->showInputCall($intNum, $CallID);
                        $helper->writeToLog($response->GetMessage(), $intNum, 'Событие OnExternalCallStart - Переадресация звонка на аппарат сотрудника');
                    } catch (\PAMI\Client\Exception\ClientException $e) {
                        $helper->writeToLog($response->GetMessage(), $intNum, 'Событие OnExternalCallStart - Ошибка');
                    }
                    break;

                default:
                    $helper->writeToLog($request, $intNum, 'Событие не определено');
                    break;
            }
        } else {
            $helper->writeToLog($request, $request['CallIntNum'], 'Не авторизован');
        }
    }
} else {
    exit('Ты втираешь какую то дичь!');
}