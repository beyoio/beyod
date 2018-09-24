<?php

namespace beyod\helpers;


class Formatter extends \yii\i18n\Formatter
{
    public static $byteMultiple = [
        'B' => 1,
        'K' => 1024*1024,
        'M' => 1024*1024,
        'G' => 1024*1024*1024,
        'T' => 1024*1024*1024*1024,
        'P' => 1024*1024*1024*1024*1024,
        'E' => 1024*1024*1024*1024*1024*1024
    ];
    public static function getBytes($value)
    {
        if(preg_match('#(\d+)\s*(B|M|G|T|P)#i', $value, $matches)) {
            $unit = strtoupper($matches[2]);
            if(isset(static::$byteMultiple[$unit])){
                return static::$byteMultiple[$unit]*$matches[1];
            }
            
            throw new \Exception("Unrecognized value $value");
        }else if(preg_match('|(\d+)|', $value, $matches)){
            return $matches[1];
        }
        
        throw new \Exception("Unrecognized value $value");
    }
}