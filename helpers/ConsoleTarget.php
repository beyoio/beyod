<?php
namespace beyod\helpers;

use yii\log\Target;

class ConsoleTarget extends Target
{
    public function export()
    {
        $text = implode("\r\n", array_map([$this, 'formatMessage'], $this->messages)) . "\r\n";
        echo $text;
    }
    
    public function getMessagePrefix($message)
    {
        if ($this->prefix !== null) {
            return call_user_func($this->prefix, $message);
        }
        
        return $this->prefix();
    }
    
    public static function prefix()
    {
        return "[". gethostname()."][".getmypid()."]";
    }
}