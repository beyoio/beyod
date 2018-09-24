<?php
namespace beyod\helpers;

use Yii;
use yii\base\ErrorException;

class ErrorHandler extends \yii\console\ErrorHandler
{
    public function handleError($code, $message, $file, $line)
    {
        if (error_reporting() & $code) {
            // load ErrorException manually here because autoloading them will not work
            // when error occurs while autoloading a class
            if (!class_exists('yii\\base\\ErrorException', false)) {
                require_once Yii::getAlias('@yii/base') . '/ErrorException.php';
            }
            
            return $this->handleException(new ErrorException($message, $code, $code, $file, $line));
        }
        
        return false;
    }
    
    public function handleFatalError()
    {
        $error = error_get_last();
        if($error){
            Yii::error(serialize($error), 'beyod');
        }
        
        Yii::getLogger()->flush(true);        
        Yii::$app->server->stop();
    }
    
    public function handleException($exception)
    {
        if ($exception instanceof \yii\base\ExitException) {
            return;
        }
        
        $this->unregister();
        
        try {
            $this->logException($exception);
            $this->renderException($exception);
            
        } catch (\Exception $e) {
            syslog(LOG_ALERT, $e->getMessage());
        } finally {
            //$this->register();
        }
    }
}