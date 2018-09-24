<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

 
namespace beyod\protocol\redis;
use yii\base\BaseObject;

/**
 * Response represents the response from redis server.
 * @since 1.0
 */

class Response extends BaseObject
{
    const TYPE_STRING = '+';
    const TYPE_ERROR = '-';
    const TYPE_INTEGER = ':';
    const TYPE_BULKSTRING = '$';
    const TYPE_NULL = '-1';
    const TYPE_ARRAY = '*';
    
    public static $data_types = ['+','-',':','$', '*'];
    
    protected $type;
        
    protected $data;
    
    protected $error;
    
    protected $len = 0;
    
    protected $rawData;
    
    public function __construct($buffer = '')
    {
        if($buffer){
            list($this->type, $this->len, $this->rawData) = static::parse($buffer);
            switch ($this->type) {
                case '+':
                case '-':
                case '$':
                    if($this->type == '-'){
                        $this->error = $this->rawData;
                    }else{
                        $this->data = (string)$this->rawData;
                    }
                    
                    break;
                case ':':
                    $this->data = (int)$this->rawData;
                    break;
                case '-1':
                    $this->data = null;
                    break;
                case '*':
                    $this->data = (array)$this->rawData;
                    break;
                default:
                    \Yii::error("unkown response type $this->type", 'beyod\redis');
                    break;
            }
        }
    }
    
    public static function parse($buffer) 
    {
        $type = substr($buffer, 0, 1);
        if(!in_array($type, static::$data_types)) {
            throw new \Exception("unkown response type $type");
        }
        
        $pos = strpos($buffer, "\r\n");
        
        $line = substr($buffer, 1 , $pos-1);
        if($line === '-1') {
            return [$type, 5, null];
        }
        
        $data = null;
        switch($type) {
            case '+': // Status reply
            case '-':
            case ':':
                $data = $line;
                $len = $pos+2;
                break;
            case '$': //Bulk Strings
                $length = intval($line);
                $data = substr($buffer, strlen($line)+3, $length);
                $len = strlen($data) + strlen($line)+5;
                break;
            case '*':// Multi-bulk replies
                $count = (int)$line;
                $data = [];
                $len = strlen($line)+3;
                for ($i = 0; $i < $count; $i++) {
                    list($t1, $len1, $data1) = static::parse(substr($buffer, strlen($line)+3));
                    $buffer = substr($buffer, $len1);
                    $data[] = $data1;
                }
                
                break;
                
            default:
                throw new \Exception('Received illegal data from redis: ' . $line);
        }
        
        return [$type, $len, $data];
    }
    
    public function isError() 
    {
        return $this->type == static::TYPE_ERROR;
    }
    
    public function isString()
    {
        return $this->type == static::TYPE_STRING;
    }
    
    public function isBulkString()
    {
        return $this->type == static::TYPE_BULKSTRING;
    }
    
    public function isNull()
    {
        return $this->type == static::TYPE_NULL;
    }
    
    public function isArray()
    {
        return $this->type = static::TYPE_ARRAY;
    }
    
    public function getData() 
    {
        return $this->data;
    }
    
    public function getType()
    {
        return $this->type;
    }
    
    public function getRawData()
    {
        return $this->rawData;
    }
    
    public function getError()
    {
        return $this->error;
    }
}