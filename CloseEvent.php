<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod;

/**
 * @property string $closer closer of the connection text description.
 *
 */
class CloseEvent extends IOEvent
{
    const BY_SELF = 0;
    const BY_PEER = 1;
    /**
     * Active shutter 
     * 0: by self
     * 1: by remote peer
     * @var int
     */
    public $by = self::BY_SELF;
    
    public function getCloser() {
        return $this->by == static::BY_SELF ? 'self': 'peer';
    }
}