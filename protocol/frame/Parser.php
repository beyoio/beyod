<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

 
namespace beyod\protocol\frame;

use beyod\Connection;

/**
 * Frame-based parser.
 * @since 1.0
 */
class Parser extends \beyod\Parser 
{
    /**
     * 
     * {@inheritDoc}
     * @see \beyod\Parser::input()
     */
    public function input($buffer, $connection) 
    {
        //check packet size limit.
        $len = parent::input($buffer, $connection);
        
        if($len <=4 ) return $len;
        
        $data = unpack('Nlen', substr($buffer, 0, 4));
        if($len < $data['len']) {
            return 0;
        }
        
        return $data['len'];
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \beyod\Parser::decode()
     */
    public function decode($buffer, $connection) 
    {
        return substr($buffer, 4);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \beyod\Parser::encode()
     */
    public function encode($buffer, $connection) 
    {
        $len = 4 + strlen($buffer);
        return pack('N', $len) . $buffer;
    }
}
