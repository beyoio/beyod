<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;


class ErrorEvent extends IOEvent
{
    const ERROR_CLOSED = 10;
    const ERROR_TIMEDOUT = 20;
    const ERROR_BAD_PACKET = 30;
    /**
     * Active shutter 
     * 0: by self
     * 1: by remote peer
     * @var int
     */
    public $code = 0;
    
    public $errstr = null;
    
    
}