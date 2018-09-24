<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\websocket;

/**
 * websocket stream response wrapper.
 * @since 1.0
 */


class Response extends Request
{
    public function __construct($body='', $opcode=null)
    {
        $this->fin = 1;
        $this->body = $body;
        $this->mask = 0;
        $this->opcode = $opcode ?: parent::OPCODE_TEXT;
        
        $len = strlen($this->body);
        
        if($len <= 125) {
            $this->payload_len = $len;
            $this->payload_ext_len = 0;
        }else if($len <=65535){
            $this->payload_len = 126;
            $this->payload_ext_len = $len;
        }else{
            $this->payload_len = 127;
            $this->payload_ext_len = $len;
        }        
    }
}
