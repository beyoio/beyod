<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\websocket;

use yii\base\BaseObject;

/**
 * websocket binary stream request wrapper.
 * @since 1.0
 */
 

class Request extends BaseObject
{
    const OPCODE_MIDDLE = 0x0;
    const OPCODE_TEXT = 0x1;
    const OPCODE_BINARY = 0x2;
    const OPCODE_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xA;
    
    public $fin = 0;
    public $opcode = -1;
    public $payload_len = 0;
    public $payload_ext_len = 0;
    public $mask = 0;
    public $mask_key='';
    
    /**
     * @var string request data payload. only $opcode==0x1 || $opcode==0x2
     */
    public $body='';
    
    /**
     * create request from input buffer string
     * @param string $buffer
     */
    public function __construct($buffer=null)
    {
        if($buffer) $this->load($buffer);
    }
    
    /**
     * load request from input buffer string
     * @param string $buffer
     */
    public function load($buffer){
        $this->fin = ord($buffer[0]) >> 7;
        $this->opcode = ord($buffer[0]) & (pow(2,4)-1);
        $this->mask = ord($buffer[1]) >> 7;
        if($this->isCtlFrame()) return ;
        
        $this->payload_len = ord($buffer[1]) & 127;
        
        $ext_bytes = 0;
        if($this->payload_len < 126) {
            $this->payload_ext_len = 0;
        }elseif($this->payload_len === 126 ) {
            $this->payload_ext_len = unpack('n', substr($buffer,2,2));
            $ext_bytes=2;
        }else if($this->payload_len === 127) {
            $this->payload_ext_len = unpack('J', substr($buffer,2,8));
            $ext_bytes=8;
        }        
        
        if($this->mask) {
            $this->mask_key = substr($buffer, $ext_bytes+2, 4);
            $data = substr($buffer, $ext_bytes+6);
            for ($index = 0; $index < strlen($data); $index++) {
                $this->body .= $data[$index] ^ $this->mask_key[$index % 4];
            }
            
        }else{
            $this->mask_key = '';
            $this->body = substr($buffer, $ext_bytes+2);
        }
    }
    
    
    public function isCtlFrame() 
    {
        return $this->isCloseFrame() || $this->isPingFrame() || $this->isPongFrame();
    }
    
    public function isCloseFrame() 
    {
        return $this->opcode === self::OPCODE_CLOSE;
    }
    
    public function isPingFrame() {
        return $this->opcode === self::OPCODE_PING;
    }
    
    public function isPongFrame() 
    {
        return $this->opcode === self::OPCODE_PONG;
    }
    
    public function __toString()
    {
        $str = chr( ($this->fin << 7) + $this->opcode) . chr(($this->mask <<7) + $this->payload_len) ;
        if($this->isCtlFrame() || !$this->payload_len) return $str;
        
        if($this->payload_len === 126) {
            $str .= pack('n', $this->payload_ext_len);
        }else if($this->payload_len === 127) {
            $str .= pack('J',  $this->payload_ext_len);
        }
        
        if($this->mask) {
            $str .= $this->mask_key;
        }
        
        $str .= $this->body;
        
        return $str;
    }
}
