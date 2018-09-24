<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod;

use yii\base\Behavior;
use yii\base\Object;
use beyod\helpers\Formatter;

/**
 * TCP/UDP packet parsing is implemented bey Parser component.
 *
 */

class Parser extends Behavior
{
    
    const ERROR_TOO_LARGE_PACKET = 1;
    const ERROR_BAD_PACKET = 2;
    
    /**
     * @var integer max packet size bytes limitation, 0 menas no limit.
     */
    public $max_packet_size = 8388608; //8MB
    
    
    /**
     * init is called after the parser is constructed.
     */
    
    public function init()
    {
        $this->max_packet_size = Formatter::getBytes($this->max_packet_size);
    }
    
    /**
     * Determines whether the current connection has received the complete packet, 
     * zero value indicates that a complete packet is not received yet, 
     * positive value indicates that a complete packet has been received and represents the number of bytes of the packet,
     * negative value indicates that the data is invalid or unrecognized
     *  
     * @param string $buffer
     * @param Connection|StreamClient $connection, for udp packet it is null
     * @return int
     */
    public function input($buffer, $connection)
    {
        $len = strlen($buffer);
        
        if ($this->max_packet_size >0 && $len >= $this->max_packet_size) {
            throw new \Exception($connection.' request packet size exceed max_packet_size ', Server::ERROR_LARGE_PACKET);
        }
        
        return $len;
    }
    
    /**
     * after a complete data packet is received, decode it for use
     * @param string $buffer
     * @param Connection|StreamClient $connection, for udp packet it is null
     * @return string|Object
     */
    public function decode($buffer, $connection)
    {
        return $buffer;
    }
    
    /**
     * Encode the data before sending it
     * @param string $buffer
     * @param Connection|StreamClient $connection, for udp packet it is null
     * @return string|Object
     */
    public function encode($buffer, $connection)
    {
        return $buffer;
    }   
}