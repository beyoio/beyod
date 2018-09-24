<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

use yii\base\Component;

/**
 * base tcp connection for server or client
 */

class BaseConnection extends Component
{
    const STATUS_ESTABLISHED = 2;
    const STATUS_CLOSED = 8;
    
    protected $attributes = [];
    
    /**
     * The connection's ID
     * @var int $id
     */
    protected $id;
    
    /**
     * @var string  remote host's address and port
     */
    public $peer;
    
    
    /**
     * @var string local host address and port
     */
    public $local;
    
    /**
     * @var resource The communication socket of the connection.
     */
    protected $socket;
    
    protected $status;
    
    protected $recvBuffer ='';
    protected $sendBuffer ='';
    protected $isPaused=false;
    
    /**
     * 
     * @var Connection|StreamClient pipe connection
     */
    protected $_pipe;
    
    protected $fileHandlers=[];
    
    protected $timers=[];
    
    public function pipe($target)
    {
        $this->_pipe = $target;
        $target->_pipe = $this;
    }
    
    public function unpipe()
    {
        if($this->_pipe && $this->_pipe->_pipe) $this->_pipe->_pipe = null;
        if($this->_pipe) $this->_pipe=null;
    }
    
    /**
     * Marking a ID can be reused.
     * @param int $id
     */
    public static function unsetId($id)
    {
        static::$unusedIds[$id] = $id;
    }
    
    public function attr($name, $value=null){
        $args = func_get_args();
        if(count($args) === 1) {
            return $this->getAttribute($args[0]);
        }else if(count($args) >= 2) {
            return $this->setAttribute($args[0], $args[1]);
        }
    }
    
    public function setAttribute($name, $value=null) {
        if($value === null){
            unset($this->attributes[$name]);
        }else{
            $this->attributes[$name] = $value;
        }
    }
    
    public function getAttribute($name, $default = null){
        return isset($this->attributes[$name]) ? $this->attributes[$name]: $default;
    }
    
    public function hasAttribute($name)
    {
        return isset($this->attributes[$name]);
    }
    
    public function getSocket() {
        return $this->socket;
    }
    
    public function getIsClosed() {
        return !is_resource($this->socket) || !$this->socket ||
        $this->status != static::STATUS_ESTABLISHED;
    }
    
    public function getIsEstablished()
    {
        return $this->status === static::STATUS_ESTABLISHED && $this->socket;
    }
    
    public function __toString(){
        return "$this->id $this->peer $this->local";
    }
}