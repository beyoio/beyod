<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\websocket;

use beyod\Connection;
use beyod\MessageEvent;
use Yii;
use beyod\ErrorEvent;
use beyod\protocol\http\Request as HttpRequest;
use beyod\protocol\http\Response as HttpResponse;


/**
 * websocket request handler class.
 * @see http://www.beyo.io/document/class/protocol-websocket
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 */

class Handler extends \beyod\Handler
{
    public $secKey = 'ws-seckKey';
    
    public $magic_code = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    public $headers = [
        'Connection' => 'Upgrade',
        'Upgrade'   => 'WebSocket',
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Headers' => 'content-type'
    ];
    
    
    /**
     * Websocket Request callback
     * @param MessageEvent $event
     * 
     * @see Parser::decode
     */
    public function onMessage(MessageEvent $event){
        if($event->message instanceof HttpRequest){
            return $this->processHandshake($event);
        }
        
        if(!($event->message instanceof Request)){
            Yii::error("message for ".get_class($this).'::onMessage must be StreamRequest');
            return ;
        }
        
        return $this->processStream($event);
    }
    
    
    public function processHandshake(MessageEvent $event)
    {
        /** @var \beyod\protocol\http\Request $message */
        $message = $event->message;
        
        $resp = new HttpResponse(101);
        foreach($this->headers as $name => $value){
            $resp->headers->set($name, $value);
        }
        
        $magicValue = base64_encode(sha1($event->message->headers->get('Sec-Websocket-Key').$this->magic_code,true));
        $resp->headers->set('Sec-Websocket-Accept', $magicValue);
        
        $event->sender->setAttribute($this->secKey, $magicValue);
        
        $event->sender->send($resp);
        
        $this->onHandshaked($event);
    }
    
    public function onHandshaked($event)
    {
        
    }
    
    /**
     * process websocket stream request
     * 
     * @param MessageEvent $event
     */
    
    public function processStream($event)
    {
        /**
         * @var Request $event->message
         */
        
        if($event->message->isCtlFrame()) {
            if($event->message->isCloseFrame()) {
                return $this->processClose($event);
            }else if($event->message->isPingFrame()){
                return $this->processPing($event);
            }else if($event->message->isPongFrame()){
                return $this->processPong($event);
            }
            
        }else{
            return $this->processData($event);
        }
    }
    
    /**
     * process close request
     * @param MessageEvent $event
     */
    public function processClose($event)
    {
        $response = new Response();
        $response->opcode = Request::OPCODE_CLOSE;
        return $event->sender->close($response);
    }
    
    /**
     * process ping request, send pong response to client.
     * @param MessageEvent $event
     */
    public function processPing($event)
    {
        $res = new Response();
        $res->fin = 1;
        $res->opcode = Request::OPCODE_PONG;
        $res->mask=0;
        $res->payload_len=0;
        $event->sender->send($res);
    }
    
    /**
     * process pong request
     * @param MessageEvent $event
     */
    public function processPong($event)
    {
        
    }
    
    /**
     * process binary data stream request, Override this method to implement business processes.
     * 
     * @tutorial  $event->message Request
     * @tutorial  $event->message->body string
     * @tutorial  $event->message->opcode int
     * 
     * @param MessageEvent $event
     */
    public function processData($event)
    {
        
    }
    
    public function sendErrorResponse(ErrorEvent $event)
    {
        if($event->sender->hasAttribute($this->secKey)) {
            
        }else{
            parent::sendErrorResponse($event);
        }
    }
}