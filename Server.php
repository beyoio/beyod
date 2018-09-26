<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

use Yii;
use beyod\event\EventLooper;
use yii\console\Exception;
use yii\helpers\Json;
use beyod\helpers\Formatter;

/**
 * server component class.
 * 
 * use \Yii::$app->server Reference this component
 * 
 * @since 1.0
 * 
 * @property float $startAt timestamp when worker started.
 * @property int $workerId  current worker process' sequence number
 * @property \beyod\event\Event $eventLooper network io event looper
 *
 */

class Server extends \yii\base\Component
{
    const STATUS_RUNNING = 2;
    
    const STATUS_STOPPING = 3;
    
    /**
     * When An error occurred while sending or receiving.
     * @var string
     */
    const ON_ERROR = 'onError';
    
    /**
     * When connection is successfully established
     * @var string
     */
    const ON_CONNECT = 'onConnect';
    
    /**
     * When connect fails
     * @var string
     */
    const ON_CONNECT_FAILED = 'onConnectFailed';
    
    /**
     * When oneself or the peer closed the connection
     * @var string
     */
    const ON_CLOSE = 'onClose';
    
    /**
     * When unrecognized packets are received
     * @var string
     */
    const ON_BAD_PACKET = 'onBadPacket';
    
    /**
     * When the sending buffer is full
     * @var string
     */
    const ON_BUFFER_FULL = 'onBufferFull';
    
    /**
     * When the sending buffer is empty
     * @var string
     */
    const ON_BUFFER_DRAIN = 'onBufferDrain';
    
    /**
     * When valid packets are received
     * @var string
     */
    const ON_MESSAGE = 'onMessage';
    
    /**
     * When StreamClient prepared to connect
     * @var string
     */
    const ON_BEFORE_CONNECT = 'onBeforeConnect';
    
    const ON_SSL_HANDSHAKED = 'onSSLHandShaked';
    
    const ON_UDP_PACKET = 'onUdpPacket';
    
    const ON_START_ACCEPT = 'onStartAccept';
    
    const ON_STOP_ACCEPT = 'onStopAccept';
    
    const ON_ACCEPT = 'onAccept';
    
    
    const ERROR_LARGE_PACKET = 100;
    
    const ERROR_INVALID_PACKET = 110;
    
    /**
     * Server token string, use \Yii::$app->server->server_token access it.
     * @var string
     */
    public $server_token = "beyod/1.0.1";
    
    /**
     * server instance ID, it is used in cluster environment for each server node. 
     * @var int
     */
    public $server_id = 1;
    
    /**
     * whether daemonize the  server.
     * @var bool
     */
    public $daemonize = true;
    
    /**
     * the number of worker processes.
     * @var int
     */
    public $worker_num = 4;
    
    /**
     * worker process's user
     * 
     * @var string
     */
    public $user = 'nobody';
    
    /**
    * worker process's group
     *
     * @var string
     */

    public $group = 'nobody';
    
    /**
     * 
     * @since php 7.0
     * @var int reset rlimit no_file value, empty value means no set.
     */
    public $rlimit_nofile = 65535;
    
    public $memory_limit = '1024mb'; //MB
    
    /**
     * @var Listener[]
     */
    public $listeners = [];
    
    protected $master_pid = null;
    
    protected $worker_id = 0;
    
    protected $gpid = 0;
    
    protected $pid_file=null;
    
    
    protected $status= self::STATUS_RUNNING;
    
    /**
     * @var array The work process list, the keys means the worker process's PIN, and the value means it's pid.
     * Only available in master process's scope.
     */
    protected $workers = [];
    
    /**
     * @var float The server start dateline timestamp.
     */
    protected $start_at = 0;
    
    public function getStartAt()
    {
        return $this->start_at;
    }
    
    public function setPid_file($path)
    {
        $this->pid_file = Yii::getAlias($path);
    }
    
    public function getPid_file()
    {
        if($this->pid_file === null){
            $this->pid_file = Yii::getAlias('@runtime/'.basename($_SERVER['argv'][0],'.php')).'.pid';
        }
        
        return $this->pid_file;
    }
    
    public function getWorkerId()
    {
        return $this->worker_id;
    }
    
    /**
     * Get current worker's GPID(Global process unique identification)
     * @return int
     */
    public function getGpid()
    {
        if(!$this->gpid) {
            $this->gpid = ($this->server_id << 16) + $this->worker_id;
        }
        return $this->gpid;
    }
    
    public function getMasterPid()
    {
        return $this->master_pid;
    }
    
    /**
     * @param string|int $name
     * @return \beyod\Listener
     */
    public function getListener($name){
        return isset($this->listeners[$name]) ? $this->listeners[$name]: null;
    }
    
    /**
     * callback after worker started(the listeners has been listened and started accept)
     * @param int $workerId current worker'id 
     * @param int $GPID current worker's GPID(Global process unique identification)
     */
    public function onWorkerStart($workerId, $GPID)
    {
        Yii::trace("worker(server_id: $this->server_id worder_id: $workerId  GPID: $GPID) started", 'beyod');
    }
    
    protected function daemonize() {
        
        
        $this->master_pid = getmypid();
        
        if (!$this->daemonize) {
            return ;
        }
        
        if($this->daemonize && !$this->canFork() ){
            Yii::info("Hint: master-worker process model require pcntl");
            return ;
        }
        
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('fork failed');
        } elseif ($pid > 0) {
            exit(0);
        }elseif(!$pid){
            $this->master_pid = getmypid();
            
            if(function_exists('posix_setsid')) {
                posix_setsid();
            }
        }
    }
    
    
    protected function savePid()
    {
        file_put_contents($this->pid_file, $this->master_pid );
    }
    
    
    public function run(){
        
        $this->beforeRun();
        
        foreach($this->listeners as $name => $listener){
            if(!isset($listener['class'])) {
                $listener['class'] = __NAMESPACE__. '\Listener';    
            }
            
            $this->listeners[$name] = Yii::createObject($listener);
            if(!$this->listeners[$name]->getOption('SO_REUSEPORT')){
                $this->listeners[$name]->listen();
            }
        }
        
        $this->daemonize();
        
        $this->forkWorkers();
        $this->monitorWorkers();
    }
    
    /**
     * @param Listener|array $listener
     * @return Listener
     */
    public function addListener($name, $listener)
    {
        if(isset($this->listeners[$name])) {
            throw new \Exception("listener $name already exists");
        }
        
        return $this->listeners[$name] = is_object($listener) ? $listener : Yii::createObject($listener);
    }
    
    protected function beforeRun()
    {
        $this->worker_num = max($this->worker_num, 1);
        $this->server_id = max($this->server_id, 1);
        
        if(!$this->canFork()) {
            $this->worker_num = 1;
        }
        
        if($this->rlimit_nofile >0 && function_exists('posix_setrlimit')) {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $this->rlimit_nofile, $this->rlimit_nofile);
        }
        
        if(function_exists('posix_getrlimit')) {
            $limit = posix_getrlimit();
            if(isset($limit['openfiles']) && $limit['openfiles'] < 65536) {
                Yii::warning("please config system rlimit_nofile above 65536!");
            }
        }
        
        ini_set('memory_limit', Formatter::getBytes($this->memory_limit));
    }
    
    protected function isMaster()
    {
        return $this->master_pid === getmypid();
    }
    
    protected function setTitle($title) {
        if(function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        }
    }
    
    protected function sendSignalToWorkers($signal){
        foreach($this->workers as $index => $pid){
            Yii::debug("send signal ".static::getSignalName($signal)." to $pid");
            posix_kill($pid, $signal);
        }
    }
    
    public function masterSignalHandler($signal) {
        Yii::info("signal received: ".static::getSignalName($signal) );
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
            case SIGHUP;
                $this->status = static::STATUS_STOPPING;
                $this->sendSignalToWorkers(SIGTERM);
            break;
            case SIGUSR1:
                $this->sendSignalToWorkers(SIGUSR1);
                break;
        }
    }
    
    public function stop($exitCode=0)
    {
        if(function_exists('posix_kill')) {
            posix_kill($this->master_pid, SIGTERM);
        }
        
        Yii::$app->eventLooper->destroy();
        
        exit($exitCode);
    }
    
    public static function getSignalName($signal)
    {
        static $signalNames = [
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGHUP => 'SIGHUP',
            SIGUSR1 => 'SIGUSR1',
        ];
        
        return isset($signalNames[$signal]) ? $signalNames[$signal] : 'unkown';
    }
    
    /**
     * return eventLooper component
     * @return \beyod\event\Event
     */
    public static function getEventLooper()
    {
        return Yii::$app->eventLooper;
    }
    
    protected function monitorWorkers(){
        Yii::info($this->server_token . " started. workers: ". Json::encode($this->workers), 'beyod');
        
        if(!$this->isMaster()) return ;
        
        $this->savePid();
        
        register_shutdown_function(function(){
            if(!$this->isMaster()) return ;
            is_file($this->pid_file) && unlink($this->pid_file);
        });
        
        if(!$this->canFork()) {
            foreach($this->listeners as $name => $listener){
                $listener->startAccept();
            }
            
            $this->onWorkerStart($this->getWorkerId(), $this->getGpid());
            
            Yii::$app->eventLooper->loop();
            return ;
        }
        
        $this->setTitle('beyod master');
    
        pcntl_signal(SIGINT,  [$this, 'masterSignalHandler'],false);
        pcntl_signal(SIGTERM, [$this, 'masterSignalHandler'],false);
        pcntl_signal(SIGHUP,  [$this, 'masterSignalHandler'],false);
        pcntl_signal(SIGUSR1, [$this, 'masterSignalHandler'],false);
    
        while(1) {
            $status=0;
            $pid = pcntl_wait($status, WUNTRACED);
            if($pid <0){
                Yii::debug("signal arrived",'byoio');
                pcntl_signal_dispatch();
            }else{
                $Id = array_search($pid, $this->workers);
                $exitCode = pcntl_wexitstatus($status);
                Yii::warning("worker(id: $Id, pid: $pid) exited($exitCode)");
                unset($this->workers[$Id]);
                if($this->status === static::STATUS_STOPPING){
                    if(empty($this->workers)) {
                        exit(0);
                    }
                }else if(count($this->workers) !== $this->worker_num){
                    $this->forkWorkers($Id);
                }
            }
        }
    }
    
    protected function forkWorkers($Id=null)
    {
        $this->start_at = microtime(true);
        
        if(!$this->canFork()) {
          $this->worker_id=1;
          return ;
        }
        
        static $idCounter = 0;
        
        while(count($this->workers) < $this->worker_num ) {
            $pid = pcntl_fork();
            
            $this->worker_id = $Id ? $Id : ++$idCounter;
            $this->workers[$this->worker_id] = $pid;
            if(!$pid){
                $this->workers = [];
                $this->workerForked();
            }else if($pid < 0) {
                Yii::warning("fork worder failed");
            }else{
                $this->worker_id = 0;
                Yii::debug("forked workers: ".Json::encode($this->workers), 'beyod');
            }
        }
    }
    
    protected function workerForked()
    {
        Yii::debug("worker forked(Id: $this->worker_id)");
        $this->setSignalHandler();
        foreach($this->listeners as $name => $listener){
            if($listener->getOption('SO_REUSEPORT')){
                $listener->listen();
            }
            
            $listener->startAccept();
        }
        
        $this->setTitle("beyod worker");
        $this->setUserAndGroup();
        
        $this->onWorkerStart($this->getWorkerId(), $this->getGpid());
        
        Yii::$app->eventLooper->loop();
    }
    
    protected function setUserAndGroup()
    {
        if(!extension_loaded('posix')){
            Yii::warning("posix extension is unavailable, setUserAndGroup skipped",'beyod');
        }
        if($this->user){
            $res = posix_getpwnam($this->user);
            isset($res['uid']) && posix_seteuid($res['uid']);
            isset($res['gid']) && posix_setegid($res['gid']);
        }
        
        if($this->group){
            $res = posix_getgrnam($this->group);
            isset($res['gid']) && posix_setegid($res['gid']);
        }
    }
    
    protected function setSignalHandler()
    {
        if($this->isMaster()) return ;
        
        pcntl_signal(SIGINT, SIG_IGN, true);
        pcntl_signal(SIGTERM, SIG_IGN, true);
        pcntl_signal(SIGHUP, SIG_IGN, true);
        pcntl_signal(SIGUSR1, SIG_IGN, true);
        pcntl_signal(SIGUSR2, SIG_IGN, true);
        
        Yii::$app->eventLooper->add(SIGINT,  EventLooper::EV_SIGNAL, [$this, 'workerSignalHandler']);
        Yii::$app->eventLooper->add(SIGTERM, EventLooper::EV_SIGNAL, [$this, 'workerSignalHandler']);
        Yii::$app->eventLooper->add(SIGHUP,  EventLooper::EV_SIGNAL, [$this, 'workerSignalHandler']);
        Yii::$app->eventLooper->add(SIGUSR1, EventLooper::EV_SIGNAL, [$this, 'workerSignalHandler']);
    }
    
    public function workerSignalHandler($signal)
    {
        Yii::debug("woker received signal ".static::getSignalName($signal));
        
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
            case SIGHUP;
                $this->workerExit($signal);
                break;
            case SIGUSR1:
                $this->workerExit(SIGUSR1);
                break;
        }
    }
    
    protected function workerExit($status) {
        foreach($this->listeners as $service) {
            $service->stopAccept();
        }
    
        Yii::$app->eventLooper->destroy();
    
        exit($status);
    }
    
    protected function canFork()
    {
        return function_exists('pcntl_fork');
    }
}