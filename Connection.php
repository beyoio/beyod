<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

use Yii;
use beyod\event\EventLooper;
use yii\base\Event;

/**
 * Connection represents a server side tcp connection for the client.
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 * 
 * @property int $id the connection's Id
 */
 
class Connection extends BaseConnection
{
    
    /**
     *@var Connection[] All established connections
     */
    public static $connections = [];
    
    /**
     * Client Unique Identify of the connection.
     * @var string|int
     */
    protected $ClientId = null;
    
    
    protected $paused = false;
    
    /**
     * The connection's Listener
     * @var Listenner $listenner
     */
    public $listenner;
    /**
     * The timestamp when the connection established.
     * @var int $connect_at
     */
    public $connect_at = 0;
    
    /**
     * The timestamp when last package received.
     * @var int $connect_at
     */
    public $request_at = 0;
    
    public $accept_wait_timer_name = 'accept_timer';
    
    /**
     *  the count of the all the onnections.
     * @var int $count
     */
    protected static $count=0;
    
    protected $status = self::STATUS_ESTABLISHED;
    
    protected static $unusedIds = [];
    
    protected static $counterId = 0;
    
    /**
     * Generate an available ID
     * @return int
     */
    public static function generateId()
    {
        if(static::$unusedIds) {
            return array_shift(static::$unusedIds);
        }
        
        return ++static::$counterId;
    }
    
    /**
     * Marking a ID can be reused.
     * @param int $id
     */
    public static function unsetId($id)
    {
        static::$unusedIds[$id] = $id;
    }
    
    public function __construct($socket, Listenner $listenner=null)
    {
        $this->id = static::generateId();
        $this->socket =  $socket;
        $this->listenner = $listenner;
        $this->init();
    }
    
    public function init()
    {
        $this->status = self::STATUS_ESTABLISHED;
        if($this->listenner->isSSL()) {
            stream_socket_enable_crypto($this->socket, false);
        }
        
        $this->connect_at = microtime(true);
        
        stream_set_blocking($this->socket, 0);
        
        $r = stream_socket_get_name($this->socket, true);
        
        $this->peer = stream_socket_get_name($this->socket, true);
        $this->local = stream_socket_get_name($this->socket, false);
        
        static::$count++;
        $this->listenner->connections[$this->id] = $this;
        
        static::$connections[$this->id] = $this;
        
        Yii::debug($this->__toString()." connected", 'beyod');
    }
    
    
    public function ready()
    {
        $timeout = $this->listenner->accept_timeout;
        if($timeout>0){
            $timerId = Yii::$app->eventLooper->addTimeout($timeout*1000, function(){
                Yii::warning($this." accept timedout, close it", 'beyod');
                $this->close();
            });
                
          $this->setAttribute($this->accept_wait_timer_name, $timerId);
        }
        
        Yii::$app->eventLooper->add($this->socket, EventLooper::EV_READ, [$this, 'read']);
        
        $this->trigger(Server::ON_CONNECT, new IOEvent());
    }
    
    public function getId()
    {
        return $this->id;
    }
    
    
    /**
     * get connection count.
     */
    public static function getCount(){
        return static::$count;
    }
    
    /**
     * return CUID of the connection.
     * @return string|int
     */
    public function getClientId() {
        return $this->ClientId;
    }
    
    /**
     * set CUID of the connection.
     * @return string|int
     */
    public function setClientId($value){
        $this->ClientId = $value;
        return $this;
    }
    
    public function checkSSLHandshake($check_eof)
    {
        if(!$this->listenner->isSSL() || $this->getAttribute('sslhandshaked')){
            return true;
        }
        
        if ($check_eof && (feof($this->socket) || !is_resource($this->socket) )) {
            $this->status = static::STATUS_CLOSED;
            $this->trigger(Server::ON_CLOSE, new CloseEvent(['by' => CloseEvent::BY_PEER]));
            $this->destroy();
            return false;
        }
        
        Yii::debug($this.' ssl handshake begin', 'beyod');
        
        $r = stream_socket_enable_crypto(
            $this->socket,
            true,
            $this->listenner->ssl_version);
        
        if($r === false) {
            Yii::warning($this. " ssl handshake failed");
            
            $this->status = static::STATUS_CLOSED;
            
            $event = new CloseEvent([
                'by' => CloseEvent::BY_PEER
            ]);
            
            $this->trigger(Server::ON_CLOSE, $event);
            return $this->destroy();
            
            return false;
        } elseif(0 === $r) {
            Yii::debug($this.' ssl handshake retry. no enough data', 'beyod');
            return false;
        }else {
            
            $this->setAttribute('sslhandshaked', true);
            Yii::debug($this.' ssl handshake ok', 'beyod');
            $this->trigger(Server::ON_SSL_HANDSHAKED, new IOEvent());
        }
        
        return true;
    }
    
    public function read($socket, $check_eof = true)
    {
        if($this->listenner->isSSL() && !$this->getAttribute('sslhandshaked')){
            return $this->checkSSLHandshake($check_eof);
        }
        
        $buffer = fread($socket, $this->listenner->read_buffer_size);
        
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) )) {
                $this->status = static::STATUS_CLOSED;
                                
                $event  = new CloseEvent([
                    'by' => CloseEvent::BY_PEER,
                ]);
                
                Yii::debug($this.' closed by peer', 'beyod');
                $this->trigger(Server::ON_CLOSE, $event);
                $this->destroy();
            }
            return false;
        } else {
            $this->recvBuffer .= $buffer;
        }
        
        if($this->_pipe) {
            if($this->_pipe->isClosed()){
                Yii::warning("pipe connection closed, cannot pass to it",'beyod');
                return false;
            }
            
            $this->request_at = microtime(true);
            $event = new MessageEvent();
            $event->message = $buffer;
            
            $this->trigger(Server::ON_MESSAGE, $event);
            
            $this->_pipe->send($buffer, true);
            return ;
        }
        
        while($this->recvBuffer && !$this->isPaused)
        {
            try{
                $len = $this->listenner->parser ?
                    call_user_func([$this->listenner->parser, 'input'], $this->recvBuffer, $this)
                    : strlen($this->recvBuffer);
                
                if($len === 0) {
                    return ;
                }
                
            }catch (\Exception $e){
                $this->recvBuffer = '';
                
                $event = new ErrorEvent([
                    'code' => $e->getCode(),
                    'errstr' => $e->getMessage()
                ]);
                
                $this->trigger(Server::ON_BAD_PACKET, $event);
                return ;
            }
            
            if(strlen($this->recvBuffer) < $len) {
                return ;
            }
            
            $message = substr($this->recvBuffer, 0, $len);
            $this->recvBuffer = substr($this->recvBuffer, $len);
            try{
                if($this->listenner->parser){
                    $message = call_user_func([$this->listenner->parser, 'decode'], $message, $this);
                }
            }catch(\Exception $e){
                
                $event = new ErrorEvent([
                    'code' => $e->getCode(),
                    'errstr' => $e->getMessage()
                ]);
                
                $this->trigger(Server::ON_BAD_PACKET, $event);
                break;
            }
            
            
            $event = new MessageEvent();
            $event->message = $message;
            
            $this->request_at = microtime(true);
            
            //ignore Assignment in condition
            if(($timerid = $this->getAttribute($this->accept_wait_timer_name)) != null) {
                Yii::$app->eventLooper->delTimeout($timerid);
                $this->setAttribute($this->accept_wait_timer_name, null);
            }
            
            $this->trigger(Server::ON_MESSAGE, $event);
        }
    }
    
    public function send($message, $raw = false){
        if ($this->getIsClosed()) {
            Yii::warning($this . " connection is closed, send failed", 'beyod');
            return false;
        }
        
        if(!$raw && $this->listenner->parser){
            $message = call_user_func([$this->listenner->getParser(), 'encode'], $message, $this);
        }
        
        if($message === false || $message === null) {
            return false;
        }
        
        if(!is_string($message)) {
            $message = (string)$message;
        }
        
        if($this->sendBuffer === ''){
            $len = @fwrite($this->socket, $message);
            
            if ($len === strlen($message)) {
                Yii::$app->eventLooper->del($this->socket, EventLooper::EV_WRITE);
                $this->trigger(Server::ON_BUFFER_DRAIN, new IOEvent());
                return $len;
            }else if($len >0) {
                $this->sendBuffer = substr($message, $len);
                Yii::$app->eventLooper->add($this->socket, EventLooper::EV_WRITE, [$this, 'baseWrite']);
                return $len;
            } else {
                
                $this->sendBuffer .= $message;
                
                if ($this->bufferIsFull()) {
                    Yii::debug($this." send buffer is full", 'beyod');
                    $this->trigger(Server::ON_BUFFER_FULL, new IOEvent());
                    Yii::$app->eventLooper->add($this->socket, EventLooper::EV_WRITE, [$this, 'baseWrite']);
                }
                
                if(!is_resource($this->socket) || feof($this->socket)){
                    Yii::warning($this." send no bytes to peer", 'beyod');
                                        
                    $this->status = static::STATUS_CLOSED;
                    
                    $event = new CloseEvent([
                        'by' => CloseEvent::BY_PEER, 
                        'message'=>'peer closed connection while sending to it'
                    ]);
                    $this->trigger(Server::ON_ERROR, $event);                    
                    $this->destroy();
                    return false;                        
                }
            }
            
            return $len;
        }else{
            if ($this->bufferIsFull()) {
                Yii::error($this." send buffer is fulled, dropped packets!", 'beyod');
                $this->trigger(Server::ON_BUFFER_FULL, new IOEvent());
                return false;
            }
            
            $this->sendBuffer .= $message;
            
            if ($this->bufferIsFull()) {
                Yii::debug($this." send buffer is fulled", 'beyod');
                $this->trigger(Server::ON_BUFFER_FULL, new IOEvent());
            }
            
            return true;
        }
    }
    
    public function bufferIsFull() 
    {  
        return strlen($this->sendBuffer) >= $this->listenner->max_sendbuffer_size;
    }
    
    public function bufferIsDrain()
    {
        return $this->sendBuffer === '';
    }
    
    
    public function baseWrite($socket) {
        
        $len = @fwrite($socket, $this->sendBuffer);
        
        if ($len === strlen($this->sendBuffer)) {
            Yii::$app->eventLooper->del($this->socket, EventLooper::EV_WRITE);
            $this->sendBuffer = '';
            
            return $this->trigger(Server::ON_BUFFER_DRAIN, new IOEvent());
        }
        if ($len > 0) {
            $this->sendBuffer = substr($this->sendBuffer, $len);
        } else { 
            $error = error_get_last();
            
            if($error) {
                $event = new ErrorEvent([
                    'code' => $error['type'],
                    'errstr' => $error['message'],
                ]);
                
                $this->trigger(Server::ON_ERROR, $event);
            }
            
            if(feof($this->socket) || !is_resource($this->socket)) {
                
                $this->status = static::STATUS_CLOSED;
                
                $event = new CloseEvent([
                    'by' => CloseEvent::BY_PEER
                ]);
                
                $this->trigger(Server::ON_CLOSE, $event);
                return $this->destroy();
            }
        }
    }
    
    /**
     * Close the connection
     * @param mixed|null $message  The message will be send to client before close the socket. 
     *  null means no message will be send before close.
     * @param boolean $raw    if true, the message will not be encoded by parser and directly send to client.
     */
    public function close($message=null, $raw=false) {
        if(!is_null($message)) {
            $this->send($message, $raw);
        }
        
        $this->trigger(Server::ON_CLOSE, new CloseEvent());
        
        $this->destroy();
    }
    
    public function getFileHandler($key)
    {
        return isset($this->fileHandlers[$key]) ? $this->fileHandlers[$key] : null;
    }
    
    public function addFileHandler($key, $fp)
    {
        if(isset($this->fileHandlers[$key])) {
            @fclose($this->fileHandlers[$key]);
        }
        $this->fileHandlers[$key] = $fp;
    }
    
    public function closeFileHandler($key)
    {
        foreach($this->fileHandlers as $i => $value) {
            if($key === null || $key === $i) {
                $value && is_resource($value) && fclose($value);
                unset($this->fileHandlers[$i]);
            }
        }
    }
    
    public function destroy() {
        
        Yii::debug($this . " destroyed ", 'beyod');
        $this->removeEventLooper();
        
        $this->sendBuffer = '';
        foreach($this->fileHandlers as $key => $fp){
            $fp && is_resource($fp) && fclose($fp);
        }
        
        $this->closeFileHandler(null);
        
        foreach($this->attributes as $name => $value){
            if(is_resource($name)) {
                @fclose($name);
            }
            
            unset($this->attributes[$name]);
        }
        
        if(!$this->id){
            Yii::info($this." has already closed", 'beyod');
            return false;
        }
        
        if($this->id) {
            if(isset(static::$connections[$this->id])){
                unset(static::$connections[$this->id]);
            }
            
            if(isset($this->listenner->connections[$this->id])){
                unset($this->listenner->connections[$this->id]);
            }
        }
        
        static::unsetId($this->id);
        $this->id = null;

        $this->socket && fclose($this->socket);
        $this->socket = null;
        
        
        $this->status = static::STATUS_CLOSED;
    }
    
    public function pauseRecv() {
        if($this->paused === false){
            Yii::debug($this.' paused receive', 'beyod');
            Yii::$app->eventLooper->del($this->socket, EventLooper::EV_READ);
            $this->paused = true;
        }
    }
    
    public function isPaused(){
        return $this->paused;
    }
    
    public function resumeRecv() {
        if ($this->paused === true) {
            Yii::debug($this.' resumed receive', 'beyod');
            $this->read($this->socket);
            Yii::$app->eventLooper->add($this->socket, EventLooper::EV_READ, [$this, 'read']);
            $this->paused = false;
        }
    }
    
    public function removeEventLooper()
    {
        foreach($this->listenner->handler->events() as $name => $callback) {
            $this->off($name);
        }
        Yii::$app->eventLooper->del($this->socket, EventLooper::EV_READ);
        Yii::$app->eventLooper->del($this->socket, EventLooper::EV_WRITE);
    }
    
    
    /**
     * get a Connection by id
     * @param int $id
     * @return Connection
     */
    
    public static function getConnection($id){
        
        if(isset(static::$connections[$id]) && !is_object(static::$connections[$id]) ){
            unset(static::$connections[$id]);
        }
        
        return isset(static::$connections[$id]) ? static::$connections[$id] : null;
    }
    
    public function isConnnected()
    {
        return $this->status === static::STATUS_ESTABLISHED && $this->socket;
    }
    
    /**
     * Whether the connection is closed or not established
     * @return boolean
     */
    public function isClosed()
    {
        return !$this->isConnnected();
    }
}