<?
use Psr\Log\LoggerInterface;

class CallMeLogger implements LoggerInterface
{
    public function alert($message, array $context = array())
    {
        $this->log('alert', $message);
    }

    public function critical($message, array $context = array())
    {
        $this->log('critical', $message);
    }

    public function debug($message, array $context = array())
    {
        $this->log('debug', $message);
    }

    public function emergency($message, array $context = array())
    {
        $this->log('message', $message);
    }
    public function error($message, array $context = array())
    {
        $this->log('error', $message);
    }
    public function info($message, array $context = array())
    {
        $this->log('info', $message);
    }

    public function notice($message, array $context = array())
    {
        $this->log('notice', $message);
    }

    public function warning($message, array $context = array())
    {
        $this->log('warning', $message);
    }

    public function log($level, $message, array $context = array())
    {
        $helper = new HelperFuncs();
        $helper->writeToLog($message, 'callmein', $level);
    }


}