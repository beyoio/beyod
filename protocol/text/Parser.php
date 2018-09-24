<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

 
namespace beyod\protocol\text;

use beyod\Connection;

/**
 * text protocol parser
 * @see http://www.beyo.io
 * @author zhang xu <zhangxugg@beyo.io>
 * @since 1.0
 */

class Parser extends \beyod\Parser 
{    
    public $delimiter = "\r\n";
    
    /**
     * 
     * {@inheritDoc}
     * @see \beyod\Parser::input()
     */
    public function input($buffer, $connection){
        
        $len = parent::input($buffer, $connection);
        
        $pos = strpos($buffer, $this->delimiter);
        if ($pos === false) {
            return 0;
        }
        return $pos + 1;
    }
    
    public function decode($buffer, $connection){
        return trim($buffer);
    }
    
    public function encode($buffer, $connection){
        return $buffer.$this->delimiter;
    }
}