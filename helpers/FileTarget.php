<?php
namespace beyod\helpers;

class FileTarget extends \yii\log\FileTarget
{
    public $exportInterval = 1;
    
    public $logVars = [];
    
    public $prefix = null;
    
    public function getMessagePrefix($message)
    {
        return "[". gethostname()."][".getmypid()."]";
    }
}