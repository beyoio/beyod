<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod;

class MessageEvent extends IOEvent
{
    /**
     * @var string|mixed message decoded from Parser::decode
     */
    public $message;
}