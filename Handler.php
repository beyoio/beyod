<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

use yii\base\Event;
use beyod\CloseEvent;
use beyod\ErrorEvent;
use beyod\MessageEvent;
use yii\base\Behavior;

/**
 * handler of network server IO event
 * @since 1.0.0
 *
 */

class Handler extends Behavior
{    
    /**
     * @var Listener the listener of the handler
     */
    public $owner;
    
    /**
     * Events that need to be subscribed with this handler.
     * {@inheritDoc}
     * @see \yii\base\Behavior::events()
     */
    public function events()
    {
        return [
            Server::ON_CONNECT => 'onConnect',
            Server::ON_MESSAGE => 'onMessage',
            Server::ON_CLOSE => 'onClose',
            Server::ON_ERROR => 'onError',
            Server::ON_BAD_PACKET => 'onBadPacket',
            Server::ON_BUFFER_FULL => 'onBufferFull',
            Server::ON_BUFFER_DRAIN => 'onBufferDrain',
            Server::ON_SSL_HANDSHAKED => 'onSSLHandShaked',
            Server::ON_UDP_PACKET => 'onUdpPacket',
            Server::ON_START_ACCEPT => 'onStartAccept',
            Server::ON_STOP_ACCEPT => 'onStopAccept',
        ];
    }
    
    
    /**
     * handler is created before the listener start accept.
     */
    public function init(){}
    
    /**
     * send message to udp client
     * @param UdpMessageEvent $event
     * @param mixed $message
     */
    public function sendto(UdpMessageEvent $event, $message, $flag=0, $raw=false)
    {
        if(($event->sender instanceof Connection) && $event->sender->listener->parser){
            $message = call_user_func([$event->sender->listener->parser, 'encode'], $message, null);
        }
        
        stream_socket_sendto($event->socket, (string)$message, $flag, $event->peer);
    }
    
    /**
     * Ready to receive a client connection
     * @param Event $event
     */
    public function onStartAccept(Event $event){}
    
    /**
     * When the receiving client connection is stopped
     * @param Event $event
     */
    
    public function onStopAccept(Event $event){}
    
    /**
     * When a udp packet is received
     * @param UdpMessageEvent $event
     */
    public function onUdpPacket(UdpMessageEvent $event){}
    
    /**
     * when a tcp packet is received:
     * ```php
     * $connection = $event->sender;
     * $message= $event->message;
     * $connection->send('hello, your request '.$message);
     * ```
     * @param MessageEvent $event
     */
    public function onMessage(MessageEvent $event){}
    
    /**
     * callback for a connection established.
     * ```php
     * $event->sender->send("hi, provide login: ");
     * ```
     * @param IOEvent $event
     */
    public function onConnect(IOEvent $event){}
    
    /**
     * callback for a connection is closed
     * ```php
     * $event->by === CloseEvent::BY_SELF; //closed by self
     * $event->by === CloseEvent::BY_PEER; //closed by remote
     * ```
     * @param CloseEvent $event
     */
    public function onClose(CloseEvent $event){}
    
    /**
     * callback for a read/write error
     * ```php
     * $event->code; //error code
     * $event->errstr; //error messag
     * $event->sender; //current connection
     * ```
     * @param ErrorEvent $event
     */
    
    public function onError(ErrorEvent $event){
        \Yii::error($event->sender." ".$event->errstr." ".$event->code, 'beyod');
    }
    
    /**
     * callback for a invalid packet received.
     * ```php
     * $event->code; //error code
     * $event->errstr; //error messag
     * $event->sender; //current connection
     * ```
     * @param ErrorEvent $event
     */
    
    public function onBadPacket(ErrorEvent $event){
        \Yii::warning($event->sender.' '.$event->code.' '.$event->errstr, 'beyod');
        $this->sendErrorResponse($event);
    }
    
    /**
     * callback for the connection's sendbuffer is fulled.
     * ```php
     * $event->code; //error code
     * $event->errstr; //error messag
     * $event->sender; //current connection
     * ```
     * @param IOEvent $event
     */
    public function onBufferFull(IOEvent $event){}
    
    /**
     * callback for the connection's sendbuffer is empty.
     * ```php
     * $event->code; //error code
     * $event->errstr; //error messag
     * $event->sender; //current connection
     * ```
     * @param IOEvent $event
     */
    public function onBufferDrain(IOEvent $event){
        
    }
    
    /**
     * callback for the ssl handshake completed.
     * @param IOEvent $event
     */
    public function onSSLHandshaked(IOEvent $event){}
    
    public function sendErrorResponse(ErrorEvent $event)
    {
        
    }
}