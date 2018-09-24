<?php
/**
 * @link http://www.beyo.io/
 * @copyright Copyright (c) 2017 beyod Software Team.
 * @license http://www.beyo.io/license/
 */

namespace beyod\event;

use yii\base\BaseObject;

/**
 * wrapper of libevent
 * @since 1.0
 * @see http://pecl.php.net/package/event
 *
 */
class Event extends EventLooper
{
    /**
     * EventConfig define
     * @see \beyod\event\EventConfig
     * @link http://php.net/manual/en/class.eventconfig.php
     */
    
    public $event_config = [];
    
    public function init()
    {
        $this->eventBase = new \EventBase( (new EventConfig($this->event_config))->getConfig() );
    }
    
    /**
     * {@inheritDoc}
     * @see \beyod\event\EventLooper::add()
     */
    public function add($fd, $flag, callable $callback, $args = null )
    {
        switch ($flag) {
            case static::EV_READ:
            case static::EV_WRITE:
                $fd_key    = (int)$fd;
                $eventFlag = $flag === static::EV_READ ? \Event::READ : \Event::WRITE;
                $event = new \Event($this->eventBase, $fd, $eventFlag|\Event::PERSIST, $callback, $args);
                $event->add();
                $this->fdEvents[$fd_key][$flag] = $event;
                break;
            case static::EV_SIGNAL:
                $eventFlag = \Event::SIGNAL | \Event::PERSIST;
                $event = new \Event($this->eventBase, $fd, $eventFlag, $callback, $args);
                $event->add();
                $this->signalEvents[$fd] = $event;
                break;
            case static::EV_INTERVAL:
            case static::EV_TIMEOUT:
                $timerId = static::generateId();
                
                $interval = $fd;
                
                $eventFlag = $flag === static::EV_INTERVAL ? \Event::TIMEOUT|\Event::PERSIST : \Event::TIMEOUT;
                
                $this->timerEvents[$timerId] = new \Event(
                    $this->eventBase, 
                    -1, 
                    $eventFlag, 
                    [$this, 'timeoutCallback'], 
                    [$callback, $timerId, $interval, $flag, $args]
                    );
                
                $this->timerEvents[$timerId]->add($interval/1000);
                return $timerId;
                break;
        }
    }
    
    public function timeoutCallback($fd, $flag, $args)
    {
        list($callback, $timerId, $interval, $eventFlag, $arg) = $args;
        
        call_user_func($callback, $timerId, $interval, $eventFlag, $arg);
        if($eventFlag === static::EV_TIMEOUT){
            $this->del($timerId, static::EV_TIMEOUT);
        }
    }
    
    /**
     * {@inheritDoc}
     * @see \beyod\event\EventLooper::del()
     */
    
    public function del($fd, $flag)
    {
        switch ($flag) {
            case static::EV_READ:
            case static::EV_WRITE:
                $fd_key    = (int)$fd;
                if(isset($this->fdEvents[$fd_key][$flag])){
                    $this->fdEvents[$fd_key][$flag]->del();
                    $this->fdEvents[$fd_key][$flag]->free();
                    unset($this->fdEvents[$fd_key][$flag]);
                }
            case static::EV_SIGNAL:
                if(isset($this->signalEvents[$fd])) {
                    $this->signalEvents[$fd]->del();
                    $this->signalEvents[$fd]->free();
                    unset($this->signalEvents[$fd]);
                }
                break;
            case static::EV_INTERVAL:
            case static::EV_TIMEOUT:
                if(isset($this->timerEvents[$fd])) {
                    static::unsetId($fd);
                    $this->timerEvents[$fd]->del();
                    $this->timerEvents[$fd]->free();
                    unset($this->timerEvents[$fd]);
                }
                
                break;
                
        }
    }
    
    /**
     * {@inheritDoc}
     * @see \beyod\event\EventLooper::loop()
     */
    public function loop()
    {
        $this->eventBase->loop();
    }
    
    public function __destruct()
    {
        $this->destroy();
    }
    
    /**
     * {@inheritDoc}
     * @see \beyod\event\EventLooper::destroy()
     */
    
    public function destroy()
    {
        if($this->eventBase){
            $this->eventBase->exit();
            $this->eventBase->free();
            $this->eventBase = null;
        }
    }
}


class EventConfig extends BaseObject
{
    public $avoid_methd = '';
    public $require_features = null;
    public $dispatch_max_interval=null;
    public $dispatch_max_callbacks=null;
    public $dispatch_main_priority=null;
    
    public function getConfig()
    {
        $config = new \EventConfig();
        $this->avoid_methd && $config->avoidMethod($this->avoid_methd);
        $this->require_features !== null && $config->requireFeatures($this->require_features);
        if($this->dispatch_max_interval !== null && $this->dispatch_max_callbacks !==null && $this->dispatch_main_priority !==null){
            $config->setMaxDispatchInterval($this->dispatch_max_interval, $this->dispatch_max_callbacks, $this->dispatch_main_priority);
        }
        
        return $config;
    }
}