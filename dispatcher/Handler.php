<?php
/**
 * @link http://www.beyo.io/
 * @copyright Copyright (c) 2017 beyod Software Team.
 * @license http://www.beyod.io/license/
 */

namespace beyod\dispatcher;

use beyod\MessageEvent;
use yii\base\Exception;

use \SplPriorityQueue;
use beyod\CloseEvent;

class Handler extends \beyod\Handler
{
    const ERR_NO_COMMAND = 710;
    
    const ERR_UNKOWN_COMMAND=720;
    
    const ERR_NO_DATA = 730;
    
    const ERR_EXCEED_LIMIT = 740;
    
    const ERR_NO_SEQ = 750;
    
    const ERR_DATA_ERROR = 760;
    
    const ERR_KEY_ERROR = 770;
    
    /**
     * max elements of the hash table.
     * @var integer
     */
    public $hash_size = 655360;
    
    public $queue_size = 65536*100;
    
    public static $COMMANDS = [
        'set',
        'get',
        'delete',
        'count',
        
        'qpush',
        'qpop',
        'qcount',
        'qdelte',
        'qdeleteall',
        
        'publish',
        'subscribe',
        'unsubscribe',
        'broadcast',
        'channels'
    ];
    
    protected $hash = [];
    
    /**
     * @var SplPriorityQueue[]
     */
    protected $queue;
    
    /**
     * 
     * @var array
     */
    protected $channels = [];
    
    protected $clients=[];
    
    public $success = [
        'cmd' => 'ok'
    ];
    
    public $undefined = [
        'command' => 'error',
        'message' => 'undefined command'
    ];
    
    public function init()
    {
        parent::init();
    }
    
    public function onClose(CloseEvent $event)
    {
        $cid= $event->sender->getId();
        foreach($this->channels as $name => $connections){
            foreach($connections as $id => $conn){
                if($cid == $id){
                    unset($this->channels[$name][$id]);
                }
            }
        }
        
        if(isset($this->clients[$event->sender->id])) {
            unset($this->clients[$event->sender->id]);
        }
    }
    
    public function onMessage(MessageEvent $event)
    {
        try{
            
            $this->beforeProcess($event);
            
            $method = 'process'.$event->message['command'];
            
            if(!method_exists($this, $method)){
                throw new \Exception("command not defined", static::ERR_UNKOWN_COMMAND);
            }
            
            $resp =array_merge(
                [
                    'command' => $event->message['command'],
                    'key' => $event->message['key'],
                    'code' => 0, 
                    'message' => 'ok',
                    'seq' => $event->message['seq']
                ],  
                call_user_func([$this, $method], $event)
            );
            
            $resp = $this->beforeSend($event, $resp);
            return $event->sender->send($resp);
            
        }catch(\Exception $e){
            $resp = [
                'command'=>'error', 
                'code' => $e->getCode(),
                'message'=> $e->getMessage(),
            ];
            
            return $event->sender->send($resp);
        }
    }
    
    public function processChannels(MessageEvent $event)
    {
        $ret = [];
        foreach($this->channels as $name => $connections){
            foreach($connections as $id => $conn){
                $ret[$name][] = $conn->peer;
            }
        }
        
        return ['value'=>$ret];
    }
    
    public function processSubscribe(MessageEvent $event)
    {
        $keys = (array)$event->message['key'];
        foreach($keys as $key) {
            if(!is_int($key) && !is_string($key)) {
                throw new \Exception("key must be int or string", static::ERR_KEY_ERROR);
            }
        }
        
        foreach($keys as $key){
            $this->channels[$key][$event->sender->id] = $event->sender;
            $this->clients[$event->sender->id][$key] = $key;
        }
        
        $this->clients[$event->sender->id] = array_values(array_unique($this->clients[$event->sender->id]));
        
        return ['key' => $this->clients[$event->sender->id]];
    }
    
    public function processUnSubscribe(MessageEvent $event)
    {
        $keys = (array)$event->message['key'];
        foreach($keys as $key) {
            if(!is_int($key) && !is_string($key)) {
                throw new \Exception("key must be int or string", static::ERR_KEY_ERROR);
            }
        }
        
        foreach($keys as $key) {
            if(!isset($this->channels[$key])) continue;
            foreach($this->channels as $id => $conn){
                if($conn->id == $event->sender->id){
                    unset($this->channels[$name][$conn->id]);
                }
            }
        }
        
        if(!isset($this->clients[$event->sender->id])){
            $this->clients[$event->sender->id] = [];
        }
        
        $this->clients[$event->sender->id] = array_values(array_diff($this->clients[$event->sender->id], $keys));        
        
        return ['key' => $this->clients[$event->sender->id] ];
    }
    
    public function processPublish(MessageEvent $event)
    {
        $keys = (array)$event->message['key'];
        
        if(!isset($event->message['value'])) {
            throw new \Exception("value is required", static::ERR_DATA_ERROR);
        }
        
        foreach($keys as $key) {
            if(!is_int($key) && !is_string($key)) {
                throw new \Exception("key must be int or string", static::ERR_KEY_ERROR);
            }
        }
        
        $resp = [
            'command' => 'message',
            'value' => $event->message['value'],
            'source' => $event->sender->peer,
        ];
        
        $n=0;
        foreach($keys as $key){
            if(!isset($this->channels[$key])) continue;
            foreach($this->channels[$key] as $id => $connection){
                if($connection->isClosed()) continue;
                $resp['key'] = $key;
                $n++;
                $connection->send($resp);
            }
        }
        
        return [
            'key' => $keys,
            'published' => $n,
        ];
    }
    
    public function processBroadcast(MessageEvent $event)
    {
        $loop = isset($event->message['loop']) ? (bool)$event->message['loop']: false;
        $resp = [
            'command' => 'broadcats',
            'value' => $event->message['value'],
            'source' => $event->sender->peer,
        ];
        
        $n=0;
        foreach($this->channels as $name => $connections){
            foreach($connections as $id => $conn){
                if(!$loop && $event->sender->id == $id) continue;
                if($conn->isClosed()) continue;
                $conn->send($resp);
                $n++;
            }
        }
        
        return [
            'published' => $n,
        ];
    }
    
    public function processQpush(MessageEvent $event)
    {
        $this->checkKey($event);
        $key = $event->message['key'];
        if(!isset($this->queue[$key])) {
            $this->queue[$key] = new \SplPriorityQueue();
        }
        
        if(!isset($event->message['value'])){
            throw new \Exception("key/value are required", static::ERR_DATA_ERROR);
        }
        
        $priority = isset($event->message['priority']) ? intval($event->message['priority']) : 0;
        
        $this->queue[$key]->insert($event->message['value'], $priority);
        return [];
    }
    
    public function processQpop(MessageEvent $event)
    {
        $this->checkKey($event);
        
        $key = $event->message['key'];
        $block = isset($event->message['block']) ? (bool)$event->message['block']:false;
        
        if(isset($this->queue[$event->message['key']]) && $this->queue[$event->message['key']]->count()){
            return ['value'=> $this->queue[$event->message['key']]->extract() ];
        }
        
        return ['value'=>null];
        
        //处理阻塞队列
    }
    
    public function processQcount(MessageEvent $event)
    {
        if(isset($this->queue[$event->message['key']])){
            return ['value'=> $this->queue[$event->message['key']]->count() ];
        }
        
        return ['value' => 0];
    }
    
    public function processQDelete(MessageEvent $event)
    {
        if(isset($this->queue[$event->message['key']])){
            unset($this->queue[$event->message['key']]);
        }
        
        return [];
    }
    
    public function processQDeleteAll(MessageEvent $event)
    {
        $this->queue=[];
        return [];
    }
    
    public function beforeSend(MessageEvent $event, $resp=[])
    {
        return $resp;
    }
    
    public function beforeProcess(MessageEvent $event)
    {
        if(!isset($event->message['key'])) {
            throw new \Exception("key is required", static::ERR_DATA_ERROR);
        }
        
        if(!is_array($event->message) || !isset($event->message['command'])){
            throw new \Exception("command is required", static::ERR_NO_COMMAND);
        }
        
        if(!isset($event->message['seq']) || !is_scalar($event->message['seq'])) {
            throw new \Exception("seq is required", static::ERR_NO_SEQ);
        }
        
        if(!in_array($event->message['command'], static::$COMMANDS)){
            throw new \Exception("unkown command", static::ERR_UNKOWN_COMMAND);
        }
        
        
    }
    
    public function processSet(MessageEvent $event)
    {
        $this->checkKey($event);
        
        if(!isset($event->message['value'])){
            throw new \Exception("data/key are required", static::ERR_DATA_ERROR);
        }
        
        $expire = isset($event->message['expire']) ? $event->message['expire'] : 0;
        
        if(count($this->hash) >= $this->hash_size && $this->hash_size){
            throw new \Exception("exceed hash size limit", static::ERR_EXCEED_LIMIT);
        }
        
        $this->hash[$event->message['key']] = [$event->message['value'], $expire];
        
        return [];
    }
    
    public function processGet(MessageEvent $event)
    {
        $this->checkKey($event);
        if(isset($this->hash[$event->message['key']])){
            if($this->hash[$event->message['key']][1] > 0 && $this->hash[$event->message['key']][1]>time()){
                unset($this->hash[$event->message['key']]);
                return null;
            }
            
            return ['value' => $this->hash[$event->message['key']][0] ];
        }
        
        return ['value' => null];
    }
    
    public function processDelete(MessageEvent $event)
    {
        $this->checkKey($event);
        if(isset($this->hash[$event->message['key']])){
            unset($this->hash[isset($this->hash[$event->message['key']])]);
        }
        
        return [];
    }
    
    public function processCount(MessageEvent $event)
    {
        if(isset($this->hash[$event->message['key']])){
            return ['data'=> count($this->hash[$event->message['key']])];
        }
        
        return ['data'=>0];
    }
    
    protected function checkKey(MessageEvent $event)
    {
        if(!isset($event->message['key'])){
            throw new Exception("key cannot be empty");
        }
        
        if(!is_string($event->message['key']) && !is_int($event->message['key'])) {
            throw new Exception("key must be string or int");
        }
    }
}