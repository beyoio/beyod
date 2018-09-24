<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod;

use Yii;
use yii\console\Exception;
use yii\helpers\Console;

/**
 * beyod server controller.
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 *
 */
class ServerController extends \yii\console\Controller
{
    public $defaultAction = 'help';
    
    public function beforeAction($action)
    {
        $this->requirement();
        return parent::beforeAction($action);
    }
    
    public function actionHelp()
    {
        $text = "\r\nUsage: ".$_SERVER['argv'][0]." ".$_SERVER['argv'][1]."/<start|stop|reload|status|help>"
            ." [--appconfig=config_file_path]\r\n"
            ."\r\nstart:\tstart server instance\r\n"
            ."stop:\tstop server instance\r\n"
            ."reload:\treload server graced\r\n"
            ."status:\tshow server running status\r\n"
            ."help:\tshow this help\r\n"
            ."--appconfig=path:\tcustomized server configuration file(default config/main.php)\r\n";
        Console::error($text);
        $this->actionEnv();
    }
    
    /**
     * start the server.
     */
    
    public function actionStart()
    {
        Yii::$app->server->run();
    }
    
    /**
     * stop the server.
     */
    public function actionStop($trys=3)
    {
        $pid = $this->getMasterPid();
        Console::stdout("stopping $pid ...");
        while( $trys>0 ){
            if(!posix_kill($pid, SIGTERM)) {
                Console::stdout(" success.\r\n");
                exit(0);
            }
            
            $trys--;
            usleep(300000);
        }
        
        if($trys <=0){
            Console::error(" failed !\r\n");
        }
        exit(5);
    }
    
    /**
     * reload the server's worker process graced.
     */
    
    public function actionReload()
    {
        $pid = $this->getMasterPid();
        if(!extension_loaded('posix')) {
            Console::error("posix extension is not loaded");
            exit(6);
        }
        
        Console::stdout("reloading beyod($pid) ...");
        Yii::debug("send SIGUSR1 to $pid ", 'beyod');
        $res = posix_kill($pid, SIGUSR1);
        Console::stdout(($res ? " success ": " failed")."\r\n");
    }
    
    /**
     * output the server's status
     */
    public function actionStatus()
    {
        $pid = $this->getMasterPid();
        Console::stdout("beyod($pid) is running ...\r\n");
        Console::stdout("php ini: ".php_ini_loaded_file()."\r\n");
        Console::stdout("php version: ".phpversion()."\r\n");
        Console::stdout("necessary extensions status:\r\n");
        foreach(['pcntl', 'event', 'posix'] as $ext){
            Console::stdout("\t$ext: \t". (extension_loaded($ext) ? 'yes':'no')."\r\n");
        }
    }
    
    public function actionEnv()
    {
        echo "extension status\r\n";
        echo "\tevent:\t", extension_loaded('event') ? "yes\r\n": "not loaded\r\n";
        echo "\tposix:\t", extension_loaded('posix') ? "yes\r\n": "not loaded\r\n";
        echo "\tpcntl:\t", extension_loaded('pcntl') ? "yes\r\n": "not loaded\r\n";
        
        //没有set
        $nofile = 0;
        if(function_exists('posix_getrlimit')) {
            $limit = posix_getrlimit();
            if(isset($limit['soft openfiles'])) {
                $nofile = $limit['soft openfiles'];
            }
        }
        
        if(function_exists('posix_getrlimit')){
            $limit = posix_getrlimit();
            $nofiles = 0;
            if(isset($limit['soft openfiles'])) {
                $nofiles = $limit['soft openfiles'];
            }
            
            if(!function_exists('posix_setrlimit') && $nofiles < 65535){
                echo "\r\nrlimit_nofile:\t$nofile\r\nnotice: please config system rlimit_nofile above 65536!\r\n";
            }
        }
    }
    
    protected function getMasterPid()
    {
        if(!extension_loaded('posix')) {
            Console::error("posix unavailable");
            exit(1);
        }
        $pid_file = Yii::$app->server->getPid_file();
        if(!$pid_file | !is_file($pid_file)){
            Console::error("can not found pid_file $pid_file");
            exit(2);
        }
        
        $pid = (int)file_get_contents($pid_file);
        if(!$pid) {
            Console::error("empty pid_file $pid_file");
            exit(3);
        }
        
        if(!posix_kill($pid, 0)){
            Console::error("process is not exists.\n");
            exit(4);
        }
        
        return $pid;
    }
    
    protected function requirement()
    {
       $extensions = [
           'sockets' => 'http://pecl.php.net/package/sockets',
           'event' => 'http://pecl.php.net/package/event',           
       ];
       
       $error = [];
       foreach($extensions as $name => $link){
           if(!extension_loaded($name)) {
               $error[$name] = $link;
           }
       }
       
       if(!$error) return true;
       foreach($error as $name => $link) {
           echo "$name extension required: \t$link\r\n";
       }
       
       exit(1);
    }
    
    protected function getServerPid()
    {
        $pid_file = Yii::$app->server->getPid_file();
        if(!$pid_file) {
            throw Exception("can not found pid_file: $pid_file");
        }
        
        return (int)file_get_contents($pid_file);
    }
}