<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod\protocol\text;

use Yii;
use yii\base\Event;
use beyod\IOEvent;
use beyod\MessageEvent;
use beyod\CloseEvent;
use beyod\ErrorEvent;

class Handler extends \beyod\Handler
{
    public $tag = null;
    
    public function init()
    {
        $this->tag = "Beyod Text Echo Server 1.0.1"
            ."\r\nServer:\t".Yii::$app->server->server_token
            ."\r\nServer Start At:\t".date('Y-m-d H:i:s')
            ."\r\nGPID:\t".Yii::$app->server->getGPID()
            . "\r\n";
    }
    
    
    /**
     * @param  IOEvent $event
     * {@inheritDoc}
     * @see \beyod\Handler::onConnect()
     */
    public function onConnect(IOEvent $event)
    {
        $resp = $this->tag."your connection id ".$event->sender."\r\nPlease input message, quit to disconnect:\r\n";
        $event->sender->send($resp);
        
        foreach($event->sender->listenner->connections as $id => $conn){
            if($id == $event->sender->id || $conn->isClosed() ) continue;
            $conn->send($conn." connected");
        }
    }
    
    /**
     * @param  MessageEvent $event
     */
    public function onMessage(MessageEvent $event)
    {
        if($event->message == 'quit'){            
            foreach($event->sender->listenner->connections as $id => $conn){
                if($id == $event->sender->id || $conn->isClosed() ) continue;
                $conn->send($conn.' quit !');
            }
            
            return $event->sender->close('bye bye !');
        }
        
        
        foreach($event->sender->listenner->connections as $id => $conn){
            if($id == $event->sender->id || $conn->isClosed() ) continue;
            $conn->send($conn.": ".$event->message);
        }
        
        $event->sender->send("you said: ".$event->message);
    }
    
    public function onClose(CloseEvent $event){
        foreach($event->sender->listenner->connections as $id => $conn){
            if($id == $event->sender->id || $conn->isClosed() ) continue;
            $conn->send($conn." disconnected");
        }
    }
    
    public function onBadPacket(ErrorEvent $event)
    {
        $event->sender->send($event->errstr);
    }
}