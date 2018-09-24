<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

class UdpMessageEvent extends MessageEvent
{
    /**
     * 
     * @var Resource the communication socket.
     */
    public $socket;
    /**
     *
     * @var string server's address and port
     */
    public $local;
    
    /**
     * 
     * @var string Remote host's address and port
     */
    public $peer;
    
}