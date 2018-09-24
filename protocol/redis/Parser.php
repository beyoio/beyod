<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod\protocol\redis;

use Yii;
use beyod\Connection;

/**
 * async redis client protocol parser.
 * 
 * @since 1.0
 */

class Parser extends \beyod\Parser 
{
            
    public function input($buffer, $connection)
    {
        if(strlen($buffer) <=3) {
            return 0;
        }
        $pos = strpos($buffer, "\r\n");
        
        if(!$pos) {
            return 0;
        }
        
        $len = strlen($buffer);        
        
        $line = substr($buffer, 1 , $pos-1);
        $type = substr($buffer, 0, 1);
        
        if(!in_array($type, Response::$data_types )) {
            Yii::error("error response type $type",'beyod');
            return -1;
        }
        
        if($line === '-1') return 5; //null        
        
        switch($type) {
            case '+': //Simple Strings
            case '-': //Errors
            case ':'; //Integers
                return $pos+2;
            break;
            
            case '$': //Bulk Strings                
                if($line === '0') {
                    return $len >=6 ? $pos+4 : 0;
                }
                
                $bytes = (int)$line;
                if(($bytes + strlen($line)+5) > $len){
                    return 0;
                }else{
                    return $bytes + strlen($line)+5;
                }  
             break;
             
            case '*': //Arrays
                if($line === '0'){ //empty array
                    return 3;
                }
                
                $count = (int)$line;
                
                $bytes = strlen($line)+3;
                $buffer = substr($buffer, $bytes);
                $elements = 0;
                while($buffer) {
                    $len = static::input($buffer, $connection);
                    if($len <=0 ) return 0;
                    if($len >0){
                        $buffer = substr($buffer, $len);
                        $elements++;
                        $bytes += $len;
                    }
                    
                    if($elements >= $count) {
                        return $bytes;
                    }
                }
                
                return 0;
                break;
        }
    }
    
    public function decode($buffer, $connection)
    {
        return new Response($buffer);
    }
}