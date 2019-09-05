<?php

/**
 * Class Globals
 */
class Globals
{

    static private $instance = null;

    /**
     * Массив с CALL_ID из битрикса, ключ - Uniqueid из asterisk
     */
    public $calls = [];

    /**
     * Массив с uniqueid внешних звонкнов
     */
    public $uniqueids = [];

    /**
     * Массив FullFname (url'ы записей разговоров), ключ - Uniqueid из asterisk
     */
    public $FullFnameUrls = [];

    /**
     * Массив внутренних номеров, ключ - Uniqueid из asterisk
     */
    public $intNums = [];

    /**
     * Массив duration звонков, ключ - Uniqueid из asterisk
     */
    public $Durations = [];

    /**
     * Массив disposition звонков, ключ - Uniqueid из asterisk
     */
    public $Dispositions = [];
    
    /**
     * Массив extensions - внешние номера, звонки на которые мы отслеживаем
     */
    public $extensions = [];

    /**
     * Массив transfers - тут каналы переведенных звонков
     */
    public $transfers = [];

    /**
     * @return Globals|null
     */
    static public function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }
}
