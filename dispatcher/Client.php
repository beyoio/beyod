<?php
/**
 * @link http://www.beyo.io/
 * @copyright Copyright (c) 2017 Beyod Software Team.
 * @license http://www.beyo.io/license/
 */


namespace beyod\dispatcher;

use beyod\StreamClient;
use beyod\Server;
use beyod\IOEvent;

/**
 * dispatcher client implement.
 *
 */

class Client extends StreamClient
{
    /**
     * reconnect after 3 seconds when disconnected by peer or connect failed.
     * 
     * @inheritdoc
     * @see StreamClient::$reconnect_interval
     */
    public $reconnect_interval = 3;
    
    /**
     * @var array Automatically subscribed channels when connected
     */
    
    public $default_channel = [];
    
    public $parser = 'beyod\dispatcher\Parser';
    
    
    protected function defaultSub()
    {
        if(!$this->default_channel) return ;
        
        $this->on(Server::ON_CONNECT, function(IOEvent $event){
            $this->sub($this->default_channel);
        });
    }
    
    protected function getSequence()
    {
        return uniqid(\Yii::$app->server->getGPID().'-', true);
    }
    
    public function afterConnected()
    {
        $this->defaultSub();
    }
    
    /**
     * set a hash value by key
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        return $this->send(['command'=>'set', 'key'=>$key, 'value'=>$value, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * get a hash value by key
     * @param string $key
     */
    public function get($key)
    {
        return $this->send(['command'=>'get', 'key'=>$key, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * delete a hash value by key
     * @param string $key
     */    
    public function delete($key)
    {
        return $this->send(['command'=>'delete', 'key'=>$key, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * push data to the specified queue
     * @param string $key queue name
     * @param mixed $value data
     * @param int $priority 
     * @see http://php.net/manual/en/splpriorityqueue.insert.php
     */
    public function qpush($key, $value)
    {
        return $this->send(['command'=>'qpush', 'key'=>$key,'value'=>$value, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Extract data from the queue
     * @param string $key queue name
     * @param string $block whether enable blocking mode. In blocking mode, the server returns only if there is data in the queue
     * @return void|boolean|number
     */
    public function qpop($key, $block=false){
        return $this->send(['command'=>'qpop', 'key'=>$key, 'block'=>$block, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Queue block pop,  Alias of qpop($key, true)
     * @param string $key
     */
    public function qbpop($key)
    {
        return $this->pop($key,true);
    }
    
    /**
     * Clear specified queue
     * @param string $key queue name
     */
    public function qdelete($key)
    {
        return $this->send(['command'=>'qdelete', 'key'=>$key, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Clear all queue
     * @param string $key queue name
     */
    public function qdeleteAll($key)
    {
        return $this->send(['command'=>'qdeleteall', 'key'=>$key, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Subscribe to multiple channels
     * @param array|string $keys The channel names to subscribe to
     */
    public function subscribe($keys)
    {
        return $this->send(['command'=>'subscribe', 'key'=>$keys, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Unsubscribe channels
     * @param array|string $keys The channel names to cancel
     */
    public function unsubscribe($keys)
    {
        return $this->send(['command'=>'unsubscribe', 'key'=>$keys, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * publish message to multiple channels
     * @param string|array $key
     * @param mixed $value
     */
    public function publish($key, $value)
    {
        return $this->send(['command'=>'publish', 'key'=>$keys, 'value'=>$value, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * Broadcast to all channels
     * @param mixed $value
     */
    public function broadcat($value)
    {
        return $this->send(['command'=>'broadcat','value'=>$value, 'seq'=>$this->getSequence()]);
    }
    
    /**
     * List subscription clients for each channel
     */
    public function channels()
    {
        $this->send(['command' => 'channels', 'seq'=>$this->getSequence()]);
    }
}