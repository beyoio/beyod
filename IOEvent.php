<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

/**
 * base class of network event
 *
 */
class IOEvent extends \yii\base\Event
{
    public function __construct($config =[])
    {
        if(($config instanceof Connection) || ($config instanceof StreamClient)) {
            $this->sender = $config;
            parent::__construct();
        }else{
            parent::__construct($config);
        }
    }
    /**
     * @var Connection|StreamClient the Listener or StreamClient of the Event
     */
    public $sender;
    
    /**
     * @var Connection|StreamClient|string|array extra payload
     */
    public $context ;
}