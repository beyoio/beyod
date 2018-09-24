<?php
namespace beyod\event;

use yii\base\Component;

abstract class EventLooper extends Component
{
    /**
     * file descriptor readable.
     */
    const EV_READ = 1;
    
    /**
     * file descriptor writable.
     */
    const EV_WRITE = 2;
    
    /**
     * signal received.
     */
    const EV_SIGNAL = 4;   
    
    /**
     * Infinited timer.
     */
    const EV_INTERVAL = 8;
    
    /**
     * Disposable Timer. 
     */
    
    const EV_TIMEOUT = 16;
    
    public $eventBase = null;
    
    protected $fdEvents = [];
    protected $signalEvents = [];
    protected $timerEvents = [];
    
    protected static $unusedIds = [];
    
    protected static $counterId = 0;
    
    public static function generateId()
    {
        if(static::$unusedIds) {
            return array_shift(static::$unusedIds);
        }
        
        return ++static::$counterId;
    }
    
    /**
     * Marking a ID can be reused.
     * @param int $id
     */
    public static function unsetId($id)
    {
        static::$unusedIds[$id] = $id;
    }
    
    /**
     * Add an event callback.
     * @param int $fd file descriptor| signal | time interval value(millisecond seconds).
     * @param int $flag callback event type flag, three flag supported:
     * EventLooper::EV_READ|EventLooper:EV_WRITE, the $fd param must be a file descriptor. 
     * EventLooper::EV_SIGNAL, the $fd param must be a signal number. @see http://php.net/manual/en/function.posix-kill.php
     * EventLooper::EV_INTERVAL|EV_TIMEOUT, the $fd param must be a timer interval value(millisecond seconds).
     * @param callable $callback It has the following signature:
     * ```php
     * function($fd, $flag, $args)
     * ```php
     * @param mixed $args
     * @return int|bool  timerid(when add timer callback) or true|false instructed the operate result.
     */
    abstract public function add($fd, $flag, callable $callback, $args = null );
    
    /**
     * 
     * @param resource $fd
     * @param int $flag self::EV_INTERVAL| static::EV_TIMEOUT
     * @param callable $callback, function($fd, $flag, $args)
     * @param mixed $args
     * @return boolean
     */
    public function addFdEvent($fd, $flag, $callback, $args=null)
    {
        return $this->add($fd, $flag, $callback,$args);
    }
    
    public function delFdEvent($fd, $flag)
    {
        return $this->del($fd, $flag);
    }
    
    /**
     * set signal callback
     * @param int $signal
     * @param callable $callback
     * @param mixed $args use defined args.
     * @return int
     * 
     * the callback's signature is: function($signal, static::EV_SIGNAL, $args)
     */
    
    public function addSignalEvent($signal, $callback, $args=null)
    {
        return $this->add($signal, static::EV_SIGNAL, $callback, $args);
    }
    
    /**
     * remove signal callback
     * @param int $signal
     * @return void
     */
    
    public function delSignal($signal)
    {
        return $this->del($signal, static::EV_SIGNAL);
    }
    
    /**
     * add an interval timer
     * @param int $millisecond
     * @param callable $callback
     * @param mixed $args
     * @return int the created timer's Id
     * 
     * the callback's signature is: function($timerId, $millisecond, $args)
     */
    public function addInterval($millisecond, $callback, $args=null)
    {
        return $this->add($millisecond, static::EV_INTERVAL, $callback, $args);
    }
    
    /**
     * remote the timer by id
     * @param int $timerId
     * @return bool
     */
    public function delInterval($timerId)
    {
        return $this->del($timerId, static::EV_INTERVAL);
    }
    
    /**
     * add an timeout timer
     * @param int $millisecond
     * @param callable $callback function($timerId, $millisecond, $args)
     * @param mixed $args
     * @return int the created timer's Id
     *
     * the callback's signature is: 
     */
    public function addTimeout($millisecond, $callback, $args=null)
    {
        return $this->add($millisecond, static::EV_TIMEOUT, $callback, $args);
    }
    
    /**
     * remote the timer by id
     * @param int $timerId
     * @return bool
     */
    public function delTimeout($timerId) {
        return $this->del($timerId, static::EV_TIMEOUT);
    }
    
    /**
     * Delete and event callback.
     * @param int $fd file descriptor| signal | timerid.
     * @param int $flag callback event type flag, see EventLooper:EV_XXX constants.
     */
    abstract public function del($fd, $flag);
    
    /**
     * start event loop. the process suspended for event arriving.
     */
    abstract public function loop();
    
    abstract public function destroy();
}