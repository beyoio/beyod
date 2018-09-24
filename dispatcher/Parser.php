<?php
/**
 * @link http://www.beyo.io/
 * @copyright Copyright (c) 2017 beyod Software Team.
 * @license http://www.beyod.io/license/
 */

namespace beyod\dispatcher;


class Parser extends \beyod\protocol\frame\Parser
{
    public function decode($buffer, $connection=null)
    {
        return json_decode(substr($buffer,4), true);
    }
    
    /**
     *@param array|string $response
     * {@inheritDoc}
     * @see \beyod\protocol\frame\Parser::encode()
     */
    public function encode($response, $connection=null)
    {
        if(is_array($response)){            
            $response = json_encode($response, 320);
        }
        
        $len = 4 + strlen($response);
        return pack('N', $len) . $response;
    }
}