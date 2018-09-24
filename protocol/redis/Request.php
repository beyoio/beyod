<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

namespace beyod\protocol\redis;
use yii\base\BaseObject;

/**
 * Request represents the request will be sent to the redis server.
 * @see https://redis.io/topics/protocol
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 */

class Request extends BaseObject
{
    
    /**
     * construct request for redis server.
     * @param $command string the name of the command.
     * For a list of available commands and their parameters see http://redis.io/commands
     * @link http://redis.io/topics/protocol#error-reply
     * @param $params... array
     */
    public function __construct($command, $params=[])
    {
        $this->command = $command;
        $this->params = $params;
    }
    
    /**
     * @var string the name of the command.
     * For a list of available commands and their parameters see http://redis.io/commands
     */
    protected $command;
    
    /**
     * @var array The params array should contain the params separated by white space, e.g. to execute.
     * `SET mykey somevalue NX` call the following:
     *
     * ```php
     * $request->command = "SET";
     * $request->params = ['mykey', 'somevalue', 'NX'];
     */
    protected $params = [];
    
    /**
     * set the command of the request.
     * For a list of available commands and their parameters see http://redis.io/commands     
     * @param string $command
     * @return \beyod\protocol\redis\Request
     */
    public function setCommand($command)
    {
        $this->command = strtoupper($command);
        return $this;
    }
    
    /**
     * set the params of the request.
     * For a list of available commands and their parameters see http://redis.io/commands
     * @param array $params
     * The params array should contain the params separated by white space, e.g. to execute.
     * `SET mykey somevalue NX` call the following:
     *
     * ```php
     * $request->setCommand("GET")->setParams('key1', 'key2', 'key3');
     * $request->params = ['mykey', 'somevalue', 'NX'];
     * @return \beyod\protocol\redis\Request
     */
    public function setParams($params=null)
    {
        $this->params = func_get_args();
        return $this;
    }
    
    public function __toString() 
    {
        $params = array_merge(explode(' ', $this->command), $this->params);
        $command = '*' . count($params) . "\r\n";
        foreach ($params as $arg) {
            $command .= '$' . mb_strlen($arg, '8bit') . "\r\n" . $arg . "\r\n";
        }
        
        return $command;
    }
}