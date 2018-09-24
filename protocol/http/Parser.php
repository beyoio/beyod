<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\http;

use beyod\Connection;
use beyod\helpers\Formatter;

/**
 * http protocol package decoder/encoder for server.
 * @see http://www.beyo.io/document/protocol/http
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 */
 
class Parser extends \beyod\Parser {
    
    /**
     * @var array allowed request method
     */
    public $methods = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'PATCH', 'CONNECT'];
    
    /**
     * @var integer max request header size(B KB MB)
     */
    public $max_header_size = 4096;
    
    public function init()
    {
        parent::init();
        $this->max_header_size = Formatter::getBytes($this->max_header_size);   
    }
    
    /**
     * parse http request package size
     * @param string $buffer  input buffer content
     * @param Connection $connection
     * @return int 0 means not enough package received, negative number means the request is invalid.
     *  positive number means the valid request package size.
     */
    
    public function input($buffer, $connection) {        
        $len = strlen($buffer);
        
        $method = substr($buffer, 0, strpos($buffer, ' '));
        if($len >= 8 && !in_array($method, $this->methods)) {
            throw new \Exception('Bad Request Method', 400);
        }
        
        
        if (!strpos($buffer, "\r\n\r\n")) {
            if ($len >= $this->max_header_size) {
                throw new \Exception('Too Large Request Header', 412);
            }
            return 0;
        }
        
        list($header,$body) = explode("\r\n\r\n", $buffer, 2);
        
        if (strlen($header) >= $this->max_header_size) {
            throw new \Exception('Too Large Request Header', 413);
        }
        
        return $this->getRequestSize($header, $body, $method, $connection);
    }
        
    protected function getRequestSize($rawHeader, $rawBody, $method, $connection) {
        
        if(!in_array($method, ['POST', 'PUT'])) {
            return strlen($rawHeader) + 4;
        }
        
        $length = 0;
        if(preg_match("/\r\nContent-Length:\s*(\d+)/i", $rawHeader, $matches)){
            $length = $matches[1];
        }
        
        if($length == 0) {
            throw new \Exception('Content Length required', 411);
        }
        
        if($length > ($this->max_packet_size - (strlen($rawHeader)+2)) ){
            throw new \Exception('Too Large Request Body', 413);
        }
        
        
        return $length + strlen($rawHeader) + 4;
    }
    
    
    public function decode($buffer, $connection) {
        $req = new Request();
        $req->loadRequest($buffer);
        return $req;
    }
}

