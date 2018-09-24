<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\syslog;

use beyod\Connection;
/**
 * rsyslog(remote syslog server) protocol parser. because rsyslog server based on udp transport,
 * no needs to implements the input method.
 * @see http://www.beyo.io/document/protocol-rsyslog
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 */

class Parser extends \beyod\Parser
{
    public function input($buffer, $connection)
    {
        return $buffer;
    }
    
    /**
     * decode syslog message. 
     * The Priority value consists of one, two, or three decimal integers (ABNF DIGITS) using values of %d48 (for "0") through %d57 (for "9").
     * 
     * The Facilities and Severities of the messages are numerically coded
     * with decimal values.  Some of the operating system daemons and
     * processes have been assigned Facility values.  Processes and daemons
     * that have not been explicitly assigned a Facility may use any of the
     * "local use" facilities or they may use the "user-level" Facility.
     * Those Facilities that have been designated are shown in the following
     * table along with their numerical code values.
     
     * @param string $buffer
     * @param Connection $connection
     * @return Message
     */
    public function decode($buffer, $connection)
    {            
        return new Message($buffer);
    }
    
    /**
     * for rsyslog server, no need send response to the client, this is practically useless.
     * @param string $message
     * @param Connection $connection
     */
    public function encode($message, $connection) 
    {
        return strval($message);
    }
}