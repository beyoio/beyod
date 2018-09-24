<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod;

use Yii;
use yii\base\Component;
use yii\console\Exception;

use beyod\event\EventLooper;
use beyod\protocol\dns\Message;

/**
 * Listener represents a socket listenner for a socket address. 
 * A listener has three necessary components - listen/handler/parser.
 * listen: The type of socket created is determined by the transport 
 * specified using standard URL formatting: transport://target. 
    * For Internet Domain sockets (AF_INET) such as TCP and UDP, 
    the target portion of the remote_socket parameter should consist 
    of a hostname or IP address followed by a colon and a port number. 
    * For Unix domain sockets, the target portion should point to the socket file on the filesystem. 

 * handler: network io events acceptor class name. you should define your own handler class to process client's request.  
 * parser:  Application layer input package detector/decoder, output package encoder
 * 
 *  http://php.net/manual/en/context.socket.php
 *  http://php.net/manual/en/function.socket-get-option.php
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 */
 
class Listenner extends Component 
{
    /**
     * @var string socket listen address 
     * @example 
     * tcp://0.0.0.0:80
     * tcp://[fe80::1]:80
     * tcp://www.example.com:80
     * udp://localhost:121
     * unix:///tmp/server.sock
     * udg:///tmp/serverudg.sock
     * ssl://0.0.0.0:443
     * sslv2://0.0.0.0:23
     * sslv3://0.0.0.0:23
     * tls://0.0.0.0:23
     * 
     * Note: IPv6 numeric addresses with port numbers
     * In the second example above, while the IPv4 and hostname examples are left untouched apart from the 
     * addition of their colon and portnumber, the IPv6 address is wrapped in square brackets: [fe80::1]. 
     * This is to distinguish between the colons used in an IPv6 address and the colon used to delimit the portnumber.
     * ssl:// will attempt to negotiate an SSL V2, or SSL V3 connection depending on the capabilities and preferences of the remote host. 
     * sslv2:// and sslv3:// will select the SSL V2 or SSL V3 protocol explicitly.
     * 
     * @see http://php.net/manual/en/transports.inet.php
     */
    
    public $listen;
    
    /**
     *@var array|string|Handler IO event handler component configuration.
     */
    public $handler = 'beyod\Handler';
    
    /**
     *@var string|array|Parser packet parser component configuration.
     */
    public $parser = 'beyod\Parser';
    
    /**
     * max send buffer size(bytes), if the pending send buffer's content large this, A onBufferFull event is raised.
     * @var integer
     */
    public $max_sendbuffer_size = 8388608; //16MB
    
    /**
     * how mandy bytes will be read once from the input buffer.
     * @var int
     */
    public $read_buffer_size = 65535;
    
    /**
     * @var int the timeout(seconds) for a tcp connection send packet after connected. 0 means wait forever.
     */
    public $accept_timeout = 0;
    
    /**
     * tcp connection keepalive timeout seconds. if the peer has no incoming message in the seconds, 
     *  the connection will be closedd by server.
     * @var int
     */
    public $keepalive_timeout = 7200;
    
    /**
     * keepalive timeout probe interval (seconds). 0 means disabled keepalive.
     * @var int
     */
    public $keepalive_interval = 723;
    
    /**
     * @var array socket context options
     * @see http://php.net/manual/en/context.socket.php
     * @see http://php.net/manual/en/function.socket-set-option.php
     *
     * SO_REUSEPORT: whether enable reuse_port.
     * note: Linux kernel 3.9 now support SO_REUSEPORT option
     * @link http://php.net/manual/en/sockets.constants.php
     */
    
    public $options = [
        'backlog' => 256,
        'SO_REUSEPORT' => 0,
        'SO_REUSEADDR' => 1,
        'SO_KEEPALIVE' => 1,
        'SO_LINGER' => null,
        'SO_SNDBUF' => null,
        'SO_RCVBUF' => null,
        'TCP_NODELAY' => 1, //Nagle TCP algorithm is disabled.
    ];
    
    /**
     * @var int max udp package size(bytes)
     */
    public $max_udp_packet_size = 8388608; //8MB
    
    /**
     * @var array SSL context options for this Listener.
     * @see http://php.net/manual/en/context.ssl.php
     * @see https://www.devdungeon.com/content/how-use-ssl-sockets-php
     */
    public $ssl = [
        'peer_name' => false,
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
     * @link http://php.net/manual/zh/function.stream-socket-enable-crypto.php
     * @var int
     */
    public $ssl_version = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv3_SERVER
    | STREAM_CRYPTO_METHOD_SSLv23_SERVER |STREAM_CRYPTO_METHOD_TLS_SERVER;
    
    /**
     *
     * @var Connection[]  the listener's connections.
     */
    public $connections = [];   
    
    /**
     * @var string protocol scheme.
     * @see Listener::$transports
     */
    protected $scheme;
    
    /**
     * @var string transport layer protocol of the listener.  
     */
    protected $transport;
    
    /**
     * @var array supported protocol scheme and transport layer protocol.
     * @see http://php.net/manual/en/transports.php
     */
    protected static $transports = [
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix', //unix socket tcp
        'udg'   => 'udg',  //unix socket udp
        'ssl'   => 'tcp',
        'tls'   => 'tcp',
        'sslv2' => 'tcp',
        'sslv3' => 'tcp',
    ];
    
    /**
     * address for stream_socket_server
     * @var string
     */
    protected $socket_name; 
    
    /**
     * @var Resource the listen socket file descriptor.
     */
    protected $socket;
    
    /**
     * @var string  this listener's ip address(only for internet domain: tcp/udp/ssl/tls)
     */
    protected $addr;
    
    /**
     * @var string  this listener's port(only for internet domain: tcp/udp/ssl/tls)
     */
    protected $port;
    
    protected $paused = true;
    
    protected $keepalive_timer = null;
    
    protected $is_ssl = false;
    
    public function getOption($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }
    
    public function init()
    {
        if(empty($this->listen)) {
            throw new Exception("empty listen property");
        }
        
        if($this->options['SO_REUSEPORT'] && !defined('SO_REUSEPORT')) {
            $this->options['SO_REUSEPORT'] = 0;
            Yii::warning("SO_REUSEPORT unavailable", 'beyod');
        }
        
        $this->beforeListen($this->listen);
        
        return parent::init();
    }
    
    protected function beforeListen($listen) {
        $parts = parse_url(strtolower($listen));
        if(!isset($parts['scheme']) || !isset(static::$transports[$parts['scheme']])) {
            throw new Exception("unkown scheme of $listen");
        }
        
        $this->scheme = $parts['scheme'];
        $this->transport = static::$transports[$parts['scheme']];
        
        if(!in_array($this->transport, stream_get_transports())){
            throw new Exception("OS not support $this->transport, only support:".implode(' ', stream_get_transports()));
        }
        
        if(in_array($this->scheme, ['ssl', 'tls', 'sslv2', 'sslv3'])) {
            $this->is_ssl = true;
        }
        
        if($this->isUnix()){        
            list(, $sockfile) = explode('//', $listen, 2);
            $sockfile = realpath($sockfile);
            if(file_exists($sockfile) && !@unlink($sockfile)) {
                throw new Exception("unlink $sockfile failed!");
            }
            
            $this->socket_name = $listen;
        }else{
            if(!isset($parts['port'])){
                throw new Exception("absent port of $listen");
            }

            if(!isset($parts['host'])) {
                throw new Exception("absent host of $listen");
            }
        
            $this->socket_name = $this->transport.'://'.$parts['host'].':'.$parts['port'];
            $this->addr = $parts['host'];
            $this->port = $parts['port'];
        }
        
        $this->listen = $listen;
    }
    
    /**
     * create listen socket.
     */
    
    public function listen() 
    {        
        $context = stream_context_create(['socket' => $this->options]);        
        if($this->getOption('SO_REUSEPORT')) {
            stream_context_set_option($context, 'socket', 'so_reuseport', 1);
        }
        
        $flag = $this->isUDP() ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        
        if($this->is_ssl ){
            foreach($this->ssl as $name => $value){
                if($value === null) continue;
                stream_context_set_option($context, 'ssl', $name, $value);
            }
        }
        
        $errno = $errmsg = null;
        
        $this->socket = @stream_socket_server($this->socket_name, $errno, $errmsg, $flag, $context);
        if(!$this->socket || $errno) {
            $this->socket = null;
            Yii::error("$this->listen $errmsg");
            return false;
        }
        
        /**
        When you create the binding for stream_socket_server(), make sure that you choose the tcp:// wrapper. 
        DO NOT USE ssl:// or tls://. Anything other than tcp:// will not work correctly AS A SERVER, 
        those transports are what you use when making connections with PHP as a client.
         
        Remember that the encryption does not start until after an SSL handshake completed, 
        so the server has to listen in non-encrypted mode for new connections, 
        and encryption doesn't start until certs are exchanged and a cipher is selected. 
        When a new connection arrives you accept it with stream_socket_accept() and then use stream_socket_enable_crypto() to start the SSL session.        
         */
        
        if($this->is_ssl){
            stream_socket_enable_crypto($this->socket, false);            
        }
        
        if($this->isUnix()){
            list(,$sockfile) = explode('://', $this->socket_name, 2);
            $sockfile = realpath($sockfile);
            $this->socket_name = $this->transport.'://'.$sockfile;
            chmod($sockfile, 0777);
        }
        
        if (function_exists('socket_import_stream') && $this->transport === 'tcp' ) {
            $socket = socket_import_stream($this->socket);
            foreach($this->options as $name => $value) {
                if($value === null || !defined($name)) continue;
                socket_set_option($socket, SOL_SOCKET, constant($name), $value);
            }
           
            socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        }
        
        stream_set_blocking($this->socket, 0);
        
        stream_socket_enable_crypto($this->socket, false);
        
        Yii::debug("$this->listen listened", 'beyod');
        
        return $this;
    }
    
    /**
     * whether the socket is listend success
     * @return bool
     */
    public function isListened()
    {
        return !empty($this->socket);
    }
    
    /**
     * whether the socket accept is paused
     * @return bool
     */
    public function isPaused()
    {
        return $this->paused;
    }
    
    /**
     * Get handler component instance
     * @return Handler
     */
    
    public function getHandler()
    {
        return $this->handler;
    }
    
    /**
     * Get parser component instance
     * @return Handler
     */
    public function getParser()
    {
        return $this->parser;
    }
    
    /**
     * whether is unix stream socket(eg: unix:///tmp/beyo.sock) or unix udp socket(eg: udg:///tmp/beyo.sock).
     * @return boolean
     */
    public function isUnix(){
        return $this->scheme === 'unix' || $this->scheme === 'udg';
    }
    
    /**
     * This listener's protocol scheme.
     * @return string
     */
    public function getScheme(){
        return $this->scheme;
    }
    
    public function isSSL(){
        return $this->is_ssl;
    }
    
    public function isUDP(){
        return $this->scheme === 'udp' || $this->scheme === 'udg';
    }
    
    public function isUDG(){
        return $this->scheme === 'udg';
    }
    
    public function isTCP(){
        return $this->scheme === 'tcp';
    }
    
    public function getSocketName(){
        return $this->socketName;
    }
    
    
    /**
     * accept udp packet
     * @param resource $socket
     */
    public function acceptUdp($socket){
        $peer = null;
        $message = stream_socket_recvfrom($socket , $this->max_udp_packet_size, 0, $peer);
        
        //thundering herd EAGAIN (Resource temporarily unavailable)
        if(!$peer || $message === false || $message === ''){
            return false;
        }
        
        $local = stream_socket_get_name($socket, false);
        
        $event = new UdpMessageEvent(['message'=>$message, 'socket'=>$socket]);
        
        if($this->parser) {
            $message = call_user_func([$this->parser, 'decode'], $message, null);
        }
        
        $event->local = $local;
        $event->peer = $peer;
        
        $this->trigger(Server::ON_UDP_PACKET, $event);
    }
    
    /**
     * tcp connect established event
     * @param resource $socket
     */
    public function acceptTcp($main_socket) {
        $socket = @stream_socket_accept($main_socket, 0, $remote_address);
        
        //thundering herd EAGAIN (Resource temporarily unavailable)
        if (!$socket) {
            return;
        }
        
        $connection = new Connection($socket, $this);
        
        if($this->handler ){
            $this->handler->attach($connection);
        }
        
        $this->handler->owner = $this;
        
        $this->trigger(Server::ON_ACCEPT, new IOEvent(['context'=>$connection]));
        
        $connection->ready();
    }
    
    /**
     * start accept
     */
    public function startAccept() 
    {
        if(!$this->paused)  return false;
        
        $this->paused = false;
        
        if($this->handler) {
            $this->handler = Yii::createObject($this->handler);
        }
        
        if($this->parser) {
            $this->attachBehavior('parser', Yii::createObject($this->parser));
            $this->parser = $this->getBehavior('parser');
        }
        
        if(!$this->socket){
            Yii::error("$this->socket_name is not listened", 'beyod');
            return false;
        }
        
        if($this->isUDP()){
            Yii::$app->eventLooper->add($this->socket, EventLooper::EV_READ, [$this, 'acceptUdp']);
        }else{
            Yii::$app->eventLooper->add($this->socket, EventLooper::EV_READ, [$this, 'acceptTcp']);
            if( $this->is_ssl) {
                stream_socket_enable_crypto($this->socket, false);
            }
        }
        
        Yii::debug("$this->socket_name start accept", 'beyod');
        $this->keepaliveProbe();
        
        $this->trigger(Server::ON_START_ACCEPT);
    }
    
    public function getConnectionCount(){
        return count($this->connections);
    }
    
    /**
     * stop accept
     */
    public function stopAccept() {
        if($this->paused)  return false;
        $this->paused = true;
        if(!$this->socket){
            Yii::error("$this->socket_name listen failed", 'beyod');
            return false;
        }
        
        Yii::debug("$this->socket_name stop accept", 'beyod');
        Yii::$app->eventLooper->del($this->socket, EventLooper::EV_READ);
        
        $this->trigger(Server::ON_STOP_ACCEPT);
    }
    
    /**
     * keepalive probing
     */
    public function keepaliveProbe() {
        if($this->keepalive_interval <=0 || $this->keepalive_timeout <=0){
            return ;
        }
        
        $this->keepalive_timer = Yii::$app->eventLooper->addInterval($this->keepalive_interval*1000, function($timerId, $interval, $flag, $args=null){
            $now = microtime(true);
            Yii::debug("keepalive probing ...", 'beyod');
            foreach($this->connections as $id =>$conn){
                if($conn->request_at >0 && $conn->request_at < ($now - $this->keepalive_timeout)){
                    Yii::debug($conn." keepalive timed out,close it",'beyod');
                    $conn->close();
                }
            }
        });
    }
}