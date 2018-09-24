<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod;

use Yii;
use beyod\ErrorEvent;

use beyod\event\EventLooper;
use yii\base\Event;
use yii\base\BaseObject;

/**
* Asynchronous non-blocking socket client  implement
* 
* @property float $responseAt The timestamp at which the latest packets arrive The time point at which the latest packets arrive
* @property float $connectAt The timestamp at which the connection was successfully established
*/

class StreamClient extends BaseConnection
{
    const STATUS_CONNECTING = 1;
    const STATUS_ESTABLISHED = 2;
    const STATUS_CLOSED = 1;
    
    /**
     *@var StreamClient[] all established connections
     */
    public static $connections = [];
    
    /**
     * Number of seconds paused to reconnect after connection failed or connection closed by peer.
     * zero to disable reconnect when fails.
     * @var int
     */
    public $reconnect_interval = 0;
    
    /**
     * @var array socket context options
     * @see http://php.net/manual/en/context.socket.php
     * @see http://php.net/manual/en/function.socket-set-option.php
     
     * @link http://php.net/manual/en/sockets.constants.php
     * @var array
     */
    public $options = [
        'backlog' => 256,
        'SO_REUSEADDR' => 1,
        'SO_KEEPALIVE' => 1,
        'SO_LINGER' => null,
        'SO_SNDBUF' => null,
        'SO_RCVBUF' => null,
        'TCP_NODELAY' => 1, //Nagle TCP algorithm is disabled.
    ];
    
    /**
     * @var array SSL context options
     * @see http://php.net/manual/en/context.ssl.php
     * @see https://www.devdungeon.com/content/how-use-ssl-sockets-php
     */
    public $ssl = [
        'enabled' => false,
        'peer_name' => null,
        'verify_perr' => false,
        'verify_perr_name' => false,
        'allow_self_signaed' => true,
        'cafile' => null,
        'capath' => null,
        'local_cert' => null,
        'local_pk'   => null,
        'passphrase' => null,
        'CN_match'   => null,
        'verify_depth' => null,
        'ciphers' => null,
        'capture_peer_cert' => null,
        'capture_peer_cert_chain' => null,
        'SNI_enabled' => null,
        'SNI_server_name' => null,
        'disable_compression' => null,
        'peer_fingerprint' => null,
    ];
    
    /**
     * @var string Destination server address
     * @link http://php.net/manual/en/function.stream-socket-client.php
     * @link http://php.net/manual/en/transports.inet.php
     * @link http://php.net/manual/en/transports.unix.php
     */
    public $target;
    
    /**
     * @var int Seconds of connection time out
     */
    public $timeout = 30;
    
    /**
     * @var int Number of bytes read per read
     */
    public $read_buffer_size = 65536;
    
    /**
     * @var int The maximum number of bytes of the send buffer, 
     * If the unsent byte exceeds this value, onBufferFull event will be triggered
     */
    public $max_send_buffer_size = 10485760; //10M
    
    /**
     * 
     * @var string|array|Parser parser component configration
     */
    public $parser;
    
    /**
     * The timestamp at which the latest packets arrive
     * @var float
     */
    protected $response_at;
    
    /**
     * The timestamp at which the connection was successfully established
     * @var float
     */
    protected $connect_at;
    
    protected $status = self::STATUS_CLOSED;
    
    protected $scheme;
    
    protected $socket;
    
    protected $connect_timerid = 0;
    
    protected $sendBuffer='';
    
    protected $recvBuffer='';
    
    protected static $unusedIds = [];
    
    protected static $counterId = 0;
    
    protected $context;
    
    protected static $count=0;
    
    
    protected $connect_flag;
    
    
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
    
    
    /**
     * Get the connection id, null means the connection has been closed or is not established yet.
     */
    public function getId()
    {
        return $this->id;
    }
    
    public function keepalive()
    {
        if($this->reconnect_interval <=0) return ;
        
        $this->reconnect_interval = max(1, $this->reconnect_interval);
        
        $this->off(Server::ON_CONNECT_FAILED);
        $this->off(Server::ON_CLOSE);
        
        $this->on(Server::ON_CONNECT_FAILED, [$this, 'reConnect']);
        $this->on(Server::ON_CLOSE, [$this, 'reConnect']);
    }
    
    public function reConnect($event)
    {
        //skip reconnect when closed by self
        if(($event instanceof CloseEvent) && $event->by != CloseEvent::BY_PEER){
            Yii::debug($this. " $this->target closed by self, skip reconnect", 'beyod');
            return false;
        }
        
        Yii::debug($this->target.' reconnect after '.$this->reconnect_interval.' second(s)', 'beyod');
        Yii::$app->eventLooper->addTimeout($this->reconnect_interval*1000, function(){
            $this->connect();
        });
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();
        
        if($this->parser && !is_object($this->parser)) {
            $this->parser = Yii::createObject($this->parser);
        }
        
        $this->keepalive();
    }
    
    /**
     * Start connecting to remote server. All event bindings should be set before connect.
     * @return static
     */
    
    public function connect($renew=false)
    {
        if(!$renew && $this->isConnnected()) {
            return $this;
        }else if($renew && $this->isConnnected()){
            $this->close();
        }
        
        $this->beforeConnect();
        
        $this->socket =  stream_socket_client($this->target,$errno, $errstr, 0, $this->connect_flag, $this->context);
        if(!$this->socket || $errno) {
            Yii::error("connect error($this->target) $errstr $errno", 'beyod');
            $this->trigger(Server::ON_CONNECT_FAILED, new ErrorEvent(['code'=>$errno, 'errstr'=>$errstr]));
            return ;
        }
        
        stream_set_blocking($this->socket, 0);
        
        if($this->isTCP() || $this->isUnix()){
            $this->timeoutLimit();
            Yii::$app->eventLooper->addFdEvent($this->socket, EventLooper::EV_WRITE, [$this, 'checkConnectResult']);
        }else{
            Yii::$app->eventLooper->addFdEvent($this->socket, EventLooper::EV_READ, [$this, 'readBuffer']);
        }
        
        if($this->sendBuffer) {
            $this->baseWrite();
        }
        
        return $this;
    }
    
    /**
     * prepare a udp client before sending to receiving.
     * @return static
     */
    public function prepare()
    {
        return $this->connect();
    }
    
    protected function beforeConnect()
    {
        $this->connectComplete();
        $this->removeEventLooper();
        
        Yii::debug("try connecting to $this->target", 'beyod');
        $parts = parse_url(strtolower($this->target));
        if(empty($parts['scheme'])) {
            throw new \Exception("empty scheme of $this->target");
        }
        
        $this->scheme = $parts['scheme'];
        
        if(in_array($this->scheme, ['ssl', 'tls', 'sslv2', 'sslv3'])) {
            $this->ssl['enabled'] = true;
        }
        
        $this->context = stream_context_create(['socket' => $this->options]);
        if($this->isSSL()){
            Yii::debug("$this->target ssl enabled",'beyod');
            foreach($this->ssl as $name => $value){
                if($value === null) continue;
                stream_context_set_option($this->context, 'ssl', $name, $value);
            }
        }
        
        if($this->isTCP() || $this->isUnix()) {
            $this->connect_flag = STREAM_CLIENT_CONNECT |STREAM_CLIENT_ASYNC_CONNECT |STREAM_CLIENT_PERSISTENT;
        }else{
            $this->connect_flag = STREAM_CLIENT_CONNECT;
        }
        
        $this->status = static::STATUS_CONNECTING;
        
        $this->trigger(Server::ON_BEFORE_CONNECT, new IOEvent());
    }
    
    public function getScheme()
    {
        return $this->scheme;
    }
    
    public function isSSL(){
        return !$this->isUDP() && $this->ssl['enabled'];
    }
    
    public function isTCP(){
        return $this->scheme === 'tcp';
    }
    
    public function isUDP()
    {
        return $this->scheme === 'udp' || $this->scheme === 'udg';
    }
    
    public function isUnix()
    {
        return $this->scheme === 'unix';
    }
    
    protected function timeoutLimit()
    {
        if($this->timeout <=0) return ;
        $this->connect_timerid = Yii::$app->eventLooper->addTimeout(
            $this->timeout*1000,
            function($id, $intval, $sock){
                $this->socket = null;
                $this->removeEventLooper();
                $this->status = static::STATUS_CLOSED;
                
                $event = new ErrorEvent();
                $event->code = ErrorEvent::ERROR_TIMEDOUT;
                $seconds = \Yii::t('yii', '{delta, plural, =1{1 second} other{# seconds}}',['delta'=>$this->timeout], 'en');
                
                $event->errstr = $this->target." connect time out after $seconds";
                Yii::warning($event->errstr, 'beyod');
                $this->trigger(Server::ON_CONNECT_FAILED, $event);
                
            }
        );
    }
    
    /**
     * Check the result of the connection.
     * @param resource $sock
     * @param int $flag
     * @param mixed $arg
     */
    public function checkConnectResult($sock, $flag, $arg)
    {
        $this->connectComplete();
        $this->removeEventLooper();
        
        $sock = socket_import_stream($this->socket);
        $code = socket_get_option($sock, SOL_SOCKET, SO_ERROR);
        $errstr = socket_strerror($code);
        
        if($code) {
            Yii::warning($this->target ." connect failed($code), $errstr", 'beyod');
            $this->trigger(Server::ON_CONNECT_FAILED, new ErrorEvent(['code'=>$code, 'errstr'=>$errstr]));
            return ;
        }
        
        $this->status = static::STATUS_ESTABLISHED;
        $this->id = static::generateId();
        
        static::$count++;
        
        static::$connections[$this->id] = $this;
        
        $this->peer = stream_socket_get_name($this->socket, true);
        $this->local = stream_socket_get_name($this->socket, false);
        
        Yii::debug('connected to '.$this->target.', '.$this, 'beyod');
        
        $this->trigger(Server::ON_CONNECT, new IOEvent());
        
        Yii::$app->eventLooper->addFdEvent($this->socket, EventLooper::EV_READ, [$this, 'readBuffer']);
        
        $this->afterConnected();
    }
    
    public function afterConnected()
    {
        
    }
    
    /**
     * When the buffer has data readable, Read it and try to parse the packet, 
     * and trigger the onMessage event if one or more valid packet are received.
     */
    public function readBuffer()
    {
        $buffer = fread($this->socket, $this->read_buffer_size);
        
        if ($buffer === '' || $buffer === false) {
            if (feof($this->socket) || !is_resource($this->socket)) {
                
                $this->removeEventLooper();
                $this->status = static::STATUS_CLOSED;
                
                $event  = new CloseEvent([
                    'by' => CloseEvent::BY_PEER,
                ]);
                
                $this->trigger(Server::ON_CLOSE, $event);
                $this->destroy();
            }
            
            return false;
        } else {
            $this->recvBuffer .= $buffer;
        }
        
        if($this->_pipe && !$this->_pipe->isClosed()){
            return $this->_pipe->send($buffer, true);
        }
        
        while($this->recvBuffer){
            
            try{
                $len = $this->parser ?
                    call_user_func([$this->parser, 'input'], $this->recvBuffer, $this)
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
            
            $message = substr($this->recvBuffer, 0, $len);
            $this->recvBuffer = substr($this->recvBuffer, $len);
            
            if($this->parser) {
                try{
                    $message = call_user_func([$this->parser, 'decode'], $message, $this);
                    
                }catch (\Exception $e){
                    $this->recvBuffer = '';
                    
                    $event = new ErrorEvent([
                        'code' => $e->getCode(),
                        'errstr' => $e->getMessage()
                    ]);
                    
                    $this->trigger(Server::ON_BAD_PACKET, $event);
                    return ;
                }
            }
            
            
            $this->response_at = microtime(true);
            $event = new MessageEvent($this);
            $event->message = $message;
            
            $this->trigger(Server::ON_MESSAGE, $event);
        }
    }
    
    /**
     * send mesage to peer.
     * @param mixed $message
     * @param bool $raw Is packet encode operation performed?
     */
    public function send($message, $raw=false)
    {
        if(!$raw && $this->parser){
            $message = call_user_func([$this->parser, 'encode'], $message, $this);
        }
        
        if($message === false || $message === null) {
            return false;
        }
        
        $message = (string)$message;
        
        if($this->isUDP() ) {
            return stream_socket_sendto($this->socket, $message);
        }
        
        if ($this->bufferIsFull()) {
            if($this->_pipe) {
                $this->_pipe->pauseRecv();
            }
            $this->trigger(Server::ON_BUFFER_FULL, new IOEvent());
            return false;
        }
        
        if(!$this->isConnnected()) {
            $this->sendBuffer .= $message;
            return ;
        }
        
        if($this->sendBuffer === ''){
            $len = fwrite($this->socket, $message);
            if ($len === strlen($message)) {
                return true;
            }else if($len >0) {
                $this->sendBuffer = substr($message, $len);
                Yii::$app->eventLooper->add($this->socket, EventLooper::EV_WRITE, [$this, 'baseWrite']);
            } else {
                $error = error_get_last();
                
                $event = new ErrorEvent([
                    'code' => $error['type'],
                    'errstr' => $error['message'],
                ]);
                
                $this->trigger(Server::ON_ERROR, $event);
                
                if(!is_resource($this->socket) || feof($this->socket)){
                    
                    $this->status = static::STATUS_CLOSED;
                    
                    $this->removeEventLooper();
                    
                    $event = new CloseEvent([
                        'by' => CloseEvent::BY_PEER,
                    ]);
                    $this->trigger(Server::ON_CLOSE, $event);
                    $this->destroy();
                    return false;
                }
            }
            
            return $len;
        }else{
            if ($this->bufferIsFull()) {
                $this->trigger(Server::ON_BUFFER_FULL, new IOEvent());
                return false;
            }
            
            $this->sendBuffer .= $message;
            
            return strlen($message);
        }
    }
    
    public function baseWrite() {
        $len = @fwrite($this->socket, $this->sendBuffer);
        if ($len === strlen($this->sendBuffer)) {
            Yii::$app->eventLooper->del($this->socket, EventLooper::EV_WRITE);
            $this->sendBuffer = '';
            
            if($this->_pipe) {
                $this->_pipe->resumeRecv();
            }
            
            return $this->trigger(Server::ON_BUFFER_DRAIN, new IOEvent());
        }
        if ($len > 0) {
            $this->sendBuffer = substr($this->sendBuffer, $len);
        } else {
            $error = error_get_last();
            
            $event = new ErrorEvent([
                'code' => $error['type'],
                'errstr' => $error['message'],
            ]);
            
            $this->trigger(Server::ON_ERROR, $event);
            
            if(feof($this->socket) || !is_resource($this->socket)) {
                
                $this->status = static::STATUS_CLOSED;
                
                $this->removeEventLooper();
                
                $event = new CloseEvent([
                    'by' => CloseEvent::BY_PEER,
                ]);
                
                $this->trigger(Server::ON_CLOSE, $event);
                return $this->destroy();
            }
        }
    }
    
    public function bufferIsFull()
    {
        return strlen($this->sendBuffer) >= $this->max_send_buffer_size;
    }
    
    /**
     * close the connection
     */
    public function close()
    {
        if($this->isUDP()) {
            Yii::warning("Clos UDP connection is meaningless.", 'beyod');
            return ;
        }
        
        $this->status = static::STATUS_CLOSED;
        $this->removeEventLooper();
        
        $this->trigger(Server::ON_CLOSE, new CloseEvent());
        $this->destroy();
    }
    
    public function removeEventLooper()
    {
        if($this->socket){
            Yii::$app->eventLooper->del($this->socket, EventLooper::EV_READ);
            Yii::$app->eventLooper->del($this->socket, EventLooper::EV_WRITE);
        }
    }
    
    
    /**
     * The timestamp at which the latest packets arrive
     * @return float
     */
    
    public function getResponseAt()
    {
        return $this->response_at;
    }
    
    /**
     * The timestamp at which the connection was successfully established
     * @return float
     */
    public function getConnectAt()
    {
        return $this->connect_at;
    }
    
    public function __destruct()
    {
        static::$count--;
        if($this->id && isset(static::$connections[$this->id])){
            unset(static::$connections[$this->id]);
        }
        
        static::unsetId($this->id);
        $this->id = null;
        $this->destroy();
    }
    
    /**
     * Whether the connection has been established
     * @return boolean
     */
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
    
    protected function destroy()
    {
        if($this->isUDP()) return ;
        
        $this->socket && fclose($this->socket);
        $this->socket = null;
        $this->status = static::STATUS_CLOSED;
    }
    
    protected function connectComplete()
    {
        if($this->connect_timerid){
            Yii::$app->eventLooper->delTimeout($this->connect_timerid);
            $this->connect_timerid = null;
        }
    }
    
    public function __toString(){
        return "$this->id $this->target $this->local";
    }
}

