<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

 
namespace beyod\protocol\websocket;

use beyod\Connection;
use beyod\protocol\http\Request as HttpReqeust;
/**
 * WebSocket protocol parser.
 * @since 1.0
 */
 
class Parser extends \beyod\protocol\http\Parser
{
    /**
     * seckey attribute name for websocket connection, inherit from Handler::$secKey 
     * @var string
     */
    public $seckey;
    
    /**
     * parse http|websocket stream request packet
     * 
     * @see \beyod\protocol\http\Parser::input()
     */
    public function input($buffer, $connection)
    {
        if(empty($this->seckey)) {
            $this->seckey = $connection->listener->getHandler()->secKey;
        }
        
        if(!$connection->hasAttribute( $this->seckey )) {
            return $this->handshakeInput($buffer, $connection);
        }
        
        return $this->streamInput($buffer, $connection);
    }
    
    /**
     * http handshake process
     * @param string $buffer
     * @param Connection $connection
     * @throws \Exception
     * @return int
     */
    public function handshakeInput($buffer, $connection) 
    {
        $len = parent::input($buffer, $connection);
        
        if(!$len)  return 0;
        
        $req = new HttpReqeust();
        $req->loadRequest($buffer);
        
        if($req->headers->get('Connection') !== 'Upgrade'){
            throw new \Exception("Bad Connection", 412);
        }
        
        if($req->headers->get('Upgrade') !== 'websocket'){
            throw new \Exception("Bad Upgrade", 412);
        }
        
        $seckey = $req->headers->get('Sec-Websocket-Key');
        if(!$seckey ||  base64_encode(base64_decode($seckey)) !== $seckey){
            throw new \Exception("Bad Websocket-Key", 412);
        }
        
        return $len;
    }
    
    protected function streamInput($buffer, $connection)
    {
        $len = strlen($buffer);
        $req = new Request($buffer);
        if($req->fin !== 1) return 0;
        
        if($req->isCtlFrame()) return 2;
        $package_len = strlen($req);
        if($len >= $package_len)  return $package_len;
        return 0;
    }
    
    
    /**
     * decode websocket request for usage.
     * {@inheritDoc}
     * @return \beyod\protocol\http\Request|Request
     */
    public function decode($buffer, $connection)
    {        
        if(!$connection->hasAttribute($this->seckey)) {
            $req = new HttpReqeust();
            $req->loadRequest($buffer);
        }else{
            $req = new Request($buffer);
        }
        
        return $req;
    }
}
