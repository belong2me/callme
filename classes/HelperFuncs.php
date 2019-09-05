<?php
/**
 * Helpers class for working with API
 * @author Автор: ViStep.RU
 * @version 1.0
 * @copyright: ViStep.RU (admin@vistep.ru)
 **/

class HelperFuncs
{

    /**
     * Get Internal number by using USER_ID.
     *
     * @param int $userid
     * @return int internal user number
     */
    public function getIntNumByUserId($userid)
    {
        $result = $this->getBitrixApi(["ID" => $userid], 'user.get');
        return $result ? $result['result'][0]['UF_PHONE_INNER'] : false;
    }

    /**
     * Get USER_ID by Internal number.
     *
     * @param int $intNum
     *
     * @return int user id
     */
    public function getUserIdByIntNum($intNum)
    {
        $result = $this->getBitrixApi([
            'FILTER' => ['UF_PHONE_INNER' => $intNum]
        ], 'user.get');

        if ($result) {
            return $result['result'][0]['ID'];
        } else {
            return false;
        }

    }

    /**
     * @param $filter
     * @return bool
     */
    public function getUserGroups($filter)
    {
        $result = $this->getBitrixApi(["FILTER" => $filter], 'user.groups');
        return $result['result'] ?: false;
    }


    /**
     * @param $intNum
     * @return bool
     */
    public function getUserByIntNum($intNum)
    {
        $result = $this->getBitrixApi([
            'FILTER' => [
                'ACTIVE' => 'Y',
                'UF_PHONE_INNER' => $intNum,
            ],
        ], 'user.get');

        if (!empty($result) && isset($result['result'][0])) {
            return $result['result'][0];
        } else {
            return false;
        }
    }

    /**
     * Run on output call start
     *
     * @param $request
     * @return bool
     */
    public function runOutputCall($request)
    {
        $request['CallerId'] = preg_replace("/[^0-9]/", '', $request['CallerId']);
        if (substr($request['CallerId'], 0, 1) == '7') {
            substr_replace($request['CallerId'], '8', 0, 1);
        }

        $result = $this->getBitrixApi(array(
            'USER_ID' => $this->getUserIdByIntNum($request['CallIntNum']),
            //'USER_PHONE_INNER' => $request['CallIntNum'],
            'PHONE_NUMBER' => $request['CallerId'],
            'TYPE' => 1,
            'CALL_START_DATE' => date("Y-m-d H:i:s"),
            'CRM_CREATE' => 1,
            "CRM_SOURCE" => "CALLBACK",
            'SHOW' => 1,
            'CRM_ENTITY_TYPE' => $request['EntityType'],
            'CRM_ENTITY_ID' => $request['EntityId']
        ), 'telephony.externalcall.register');

        if ($result) {
            return $result['result']['CALL_ID'];
        } else {
            return false;
        }

    }

    /**
     * End Output Call
     *
     * @param $call_id
     * @param $recordedfile
     * @param $intNum
     * @param $duration
     * @param $disposition
     *
     * @return array
     */
    public function endOutputCall($call_id, $intNum, $duration, $disposition)
    {
        switch ($disposition) {
            case 'ANSWERED':
                $sipcode = 200; // успешный звонок
                break;
            case 'NO ANSWER':
                $sipcode = 304; // нет ответа
                break;
            case 'BUSY':
                $sipcode = 486; //  занято
                break;
            default:
                if (empty($disposition)) {
                    $sipcode = 304;
                } //если пустой пришел, то поставим неотвечено
                else {
                    $sipcode = 603;
                } // отклонено, когда все остальное
                break;
        }

        $result = $this->getBitrixApi(array(
            'USER_PHONE_INNER' => $intNum,
            'CALL_ID' => $call_id, // Идентификатор звонка в Битриксе
            'STATUS_CODE' => $sipcode,
            'DURATION' => $duration, // Длительность звонка в секундах
            'ADD_TO_CHAT' => false
        ), 'telephony.externalcall.finish');

        return $result ?: [];
    }

    /**
     * Конвертирование звонка в mp3
     *
     * @param $FullFname - путь к wav файлу
     * @return string - base64-кодированное содержимое записи звонка
     */
    public function convertToMp3Base64($FullFname)
    {
        $dir = "/var/www/html/recordings/mp3/";
        if (!is_dir($dir)) {
            shell_exec("mkdir -p $dir && chown -R asterisk:asterisk $dir");
        }
        $FullFnameMP3 = $dir . basename($FullFname, ".wav") . ".mp3";

        $helper = new HelperFuncs();

        if (!file_exists($FullFname)) {
            $helper->writeToLog(['Нет оригинального файла' => $FullFname], 'files', "Конвертация");
            return false;
        }

        shell_exec("nice -n 19 /usr/bin/lame -b 32 --silent $FullFname $FullFnameMP3 && chmod o+r $FullFnameMP3 && chown asterisk:asterisk $FullFnameMP3");

        if (!file_exists($FullFnameMP3)) {
            $helper->writeToLog(['Нет конвертированного файла' => $FullFname], 'files', "Конвертация");
            return false;
        }

        $fileContent = base64_encode(file_get_contents($FullFnameMP3));
        unlink($FullFnameMP3);
        return $fileContent;

    }

    /**
     * Upload record to Bitrix24.
     *
     * @param string $call_id
     * @param string $recordedfile
     * @param string $disposition
     * @param string $file_suffix
     *
     * @return array
     */
    /* переименовать в uploadRecordedFile */
    public function uploadRecordedFile($call_id, $recordedfile, $disposition, $file_suffix='')
    {
        if ($disposition != 'ANSWERED') {
            return [];
        }

        $result = $this->getBitrixApi(array(
            'CALL_ID' => $call_id, //идентификатор звонка в битрикс
            'FILENAME' => $file_suffix . basename($recordedfile, ".wav") . ".mp3",
            'FILE_CONTENT' => self::convertToMp3Base64($recordedfile),
        ), 'telephony.externalcall.attachRecord');

        return $result ?: [];
    }

    /**
     * Run Bitrix24 REST API method telephony.externalcall.register.json
     *
     * @param int $user_phone_inner (${EXTEN} from the Asterisk server, i.e. internal number)
     * @param int $input_phone (${CALLERID(num)} from the Asterisk server, i.e. number which called us)
     * @param int $type
     *
     * @return array  like this:
     * Array
     *    (
     *        [result] => Array
     *            (
     *                [CALL_ID] => externalCall.cf1649fa0f4479870b76a0686f4a7058.1513888745
     *                [CRM_CREATED_LEAD] =>
     *                [CRM_ENTITY_TYPE] => LEAD
     *                [CRM_ENTITY_ID] => 24
     *            )
     *    )
     * We need only CALL_ID
     */
    public function runInputCall($user_phone_inner, $input_phone, $type = 1)
    {
        $user = $this->getUserByIntNum($user_phone_inner);

        $result = $this->getBitrixApi([
            'USER_PHONE_INNER' => $user_phone_inner,
            'USER_ID' => $user['ID'],
            'PHONE_NUMBER' => $input_phone,
            'TYPE' => $type,
            'CALL_START_DATE' => date("Y-m-d H:i:s"),
            'CRM_CREATE' => 1,
        ], 'telephony.externalcall.register');

        $this->writeToLog(['Данные' => ['USER_PHONE_INNER' => $user_phone_inner, 'PHONE_NUMBER' => $input_phone, 'USER_ID' => $user['ID']], 'Ответ Битрикс24' => $result],
            'callmein', "Регистрация входящего звонка c $input_phone на $user_phone_inner в Битрикс24");

        if ($result) {
            return $result['result']['CALL_ID'];
        } else {
            return [];
        }

    }

    /**
     * Run Bitrix24 REST API method user.get.json return only online users array
     *
     *
     * @return array  like this:
     *    Array
     *    (
     *        [result] => Array
     *            (
     *                [0] => Array
     *                    (
     *                        [ID] => 1
     *                        [ACTIVE] => 1
     *                        [EMAIL] => admin@your-admin.pro
     *                        [NAME] =>
     *                        [LAST_NAME] =>
     *                        [SECOND_NAME] =>
     *                        [PERSONAL_GENDER] =>
     *                        [PERSONAL_PROFESSION] =>
     *                        [PERSONAL_WWW] =>
     *                        [PERSONAL_BIRTHDAY] =>
     *                        [PERSONAL_PHOTO] =>
     *                        [PERSONAL_ICQ] =>
     *                        [PERSONAL_PHONE] =>
     *                        [PERSONAL_FAX] =>
     *                        [PERSONAL_MOBILE] =>
     *                        [PERSONAL_PAGER] =>
     *                        [PERSONAL_STREET] =>
     *                        [PERSONAL_CITY] =>
     *                        [PERSONAL_STATE] =>
     *                        [PERSONAL_ZIP] =>
     *                        [PERSONAL_COUNTRY] =>
     *                        [WORK_COMPANY] =>
     *                        [WORK_POSITION] =>
     *                        [WORK_PHONE] =>
     *                        [UF_DEPARTMENT] => Array
     *                            (
     *                                [0] => 1
     *                            )
     *
     *                        [UF_INTERESTS] =>
     *                        [UF_SKILLS] =>
     *                        [UF_WEB_SITES] =>
     *                        [UF_XING] =>
     *                        [UF_LINKEDIN] =>
     *                        [UF_FACEBOOK] =>
     *                        [UF_TWITTER] =>
     *                        [UF_SKYPE] =>
     *                        [UF_DISTRICT] =>
     *                        [UF_PHONE_INNER] => 555
     *                    )
     *
     *                )
     *
     *        [total] => 1
     *    )
     */
    public function getUsersOnline()
    {
        $result = $this->getBitrixApi(array(
            'FILTER' => array('IS_ONLINE' => 'Y',),
        ), 'user.get');

        if ($result) {
            if (isset($result['total']) && $result['total'] > 0) {
                return $result['result'];
            } else {
                return false;
            }
        } else {
            return false;
        }

    }

    /**
     * Get CRM contact name by phone
     *
     * @param $extNum
     * @return array|bool
     */
    public function getCrmDataByExtNum($extNum)
    {
        $result = $this->getBitrixApi(array(
            'FILTER' => array('PHONE' => $extNum),
            'SELECT' => array('NAME', 'LAST_NAME', 'ASSIGNED_BY_ID'),
        ), 'crm.contact.list');

        $name = '';
        if ($result && isset($result['total']) && $result['total'] > 0) {
            $name = $result['result'][0]['NAME'] . '_' . $result['result'][0]['LAST_NAME'];
        } else {
            $result = $this->getBitrixApi(array(
                'FILTER' => array('PHONE' => $extNum),
                'SELECT' => array('TITLE', 'ASSIGNED_BY_ID'),
            ), 'crm.company.list');

            if ($result && isset($result['total']) && $result['total'] > 0) {
                $name = $result['result'][0]['TITLE'];
            }
        }

        $phone = '';
        if (!empty($name) && !empty($result['result'][0]['ASSIGNED_BY_ID'])) {
            $phone = $this->getIntNumByUserId($result['result'][0]['ASSIGNED_BY_ID']);
        }
        return empty($name) ? false : ['name' => $name, 'responsible' => $phone];
    }

    /**
     * Show input call data for online users
     *
     * @param string $call_id
     *
     * @return bool
     */
    public function showInputCallForOnline($call_id)
    {
        $online_users = $this->getUsersOnline();
        if ($online_users) {
            foreach ($online_users as $user) {
                $result = $this->getBitrixApi(array(
                    'CALL_ID' => $call_id,
                    'USER_ID' => $user['ID'],
                ), 'telephony.externalcall.show');
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Show input call data for user with internal number
     *
     * @param int $intNum (user internal number)
     * @param int $call_id
     *
     * @return bool
     */
    public function showInputCall($intNum, $call_id)
    {
        $user_id = $this->getUserIdByIntNum($intNum);
        if ($user_id) {
            $result = $this->getBitrixApi(array(
                'CALL_ID' => $call_id,
                'USER_ID' => $user_id,
            ), 'telephony.externalcall.show');
            return $result ? "Карточка у $user_id по звонку $call_id показана" : "Ошибка показа карточки у $user_id по звонку $call_id";
        } else {
            return "Юзер c номером $intNum не найден";
        }
    }

    /**
     * Hide input call data for all except user with internal number.
     *
     * @param int $intNum (user internal number)
     * @param int $call_id
     *
     * @return bool
     */
    public function hideInputCallExcept($intNum, $call_id)
    {
        $user_id = $this->getUserIdByIntNum($intNum);
        $online_users = $this->getUsersOnline();
        if (($user_id) && ($online_users)) {
            foreach ($online_users as $user) {
                if ($user['ID'] != $user_id) {
                    $result = $this->getBitrixApi(array(
                        'CALL_ID' => $call_id,
                        'USER_ID' => $user['ID'],
                    ), 'telephony.externalcall.hide');
                }
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Hide input call data for user with internal number
     *
     * @param int $intNum (user internal number)
     * @param int $call_id
     *
     * @return bool
     */
    public function hideInputCall($intNum, $call_id)
    {
        $user_id = $this->getUserIdByIntNum($intNum);
        if ($user_id) {
            $result = $this->getBitrixApi(array(
                'CALL_ID' => $call_id,
                'USER_ID' => $user_id,
            ), 'telephony.externalcall.hide');
            return $result ? "Карточка у $user_id по звонку $call_id закрыта" : "Ошибка закрытия карточки у $user_id по звонку $call_id";
        } else {
            return "Юзер c номером $intNum не найден";
        }
    }

    /**
     * Check string for json data.
     *
     * @param string $string
     *
     * @return bool
     */
    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Api requests to Bitrix24
     *
     * @param array $data
     * @param string $method
     *
     * @return bool|array
     */
    public function getBitrixApi($data, $method)
    {
        if (!$url = $this->getConfig('bitrixApiUrl')) {
            return false;
        }
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url . $method . '.json',
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);

        $result = curl_exec($curl);
        curl_close($curl);

        if ($this->isJson($result)) {
            $result = json_decode($result, true);
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Write data to log file.
     *
     * @param mixed $data
     * @param string $intNum
     * @param string $title
     * @return bool
     */
    public function writeToLog($data, $intNum = 'main', $title = '')
    {
        $debug = $this->getConfig('CallMeDEBUG');
        if ($debug) {
            $log = "------------------------\n";
            $log .= date("Y.m.d G:i:s") . " - ";
            $log .= (strlen($title) > 0 ? $title : 'DEBUG') . "\n";
            $log .= print_r($data, 1);
            $log .= "\n";

            file_put_contents(getcwd() . '/logs/' . $intNum . '.log', $log, FILE_APPEND);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Remove item from array.
     *
     * @param array $data
     * @param mixed $needle
     * @param $what
     */
    public function removeItemFromArray(&$data, $needle, $what)
    {

        if ($what === 'value') {
            if (($key = array_search($needle, $data)) !== false) {
                unset($data[$key]);
            }
        } elseif ($what === 'key') {
            if (array_key_exists($needle, $data)) {
                unset($data[$needle]);
            }
        }

        //return $data;
    }

    /**
     * Return config value.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getConfig($key)
    {
        $config = require(__DIR__ . '/../config.php');
        if (is_array($config)) {
            return $config[$key];
        } else {
            return false;
        }
    }
}