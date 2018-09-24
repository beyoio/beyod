<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\syslog;

/**
 * syslog message format definition.
 * @since 1.0
 * @link http://www.ietf.org/rfc/rfc3164.txt
 */

class Message 
{
    /**
     * @var int facility of the log message.
     * @link http://php.net/manual/en/function.openlog.php facility
     *        0             kernel messages
              1             user-level messages
              2             mail system
              3             system daemons
              4             security/authorization messages
              5             messages generated internally by syslogd
              6             line printer subsystem
              7             network news subsystem
              8             UUCP subsystem
              9             clock daemon
             10             security/authorization messages
             11             FTP daemon
             12             NTP subsystem
             13             log audit
             14             log alert
             15             clock daemon (note 2)
             16             local use 0  (local0)
             17             local use 1  (local1)
             18             local use 2  (local2)
             19             local use 3  (local3)
             20             local use 4  (local4)
             21             local use 5  (local5)
             22             local use 6  (local6)
             23             local use 7  (local7)
     */
    public $facility;
    
    /**
     * @var int severity of the message. 
     * @link http://php.net/manual/en/function.syslog.php
     *        0       Emergency: system is unusable
              1       Alert: action must be taken immediately
              2       Critical: critical conditions
              3       Error: error conditions
              4       Warning: warning conditions
              5       Notice: normal but significant condition
              6       Informational: informational messages
              7       Debug: debug-level messages
     */
    public $severity;
    
    /**
     * @var string datetime of the message.
     */
    public $time;
    
    /**
     * @var string origin host name of the message.
     */
    public $host;
    
    /**
     * @var string content of the message.
     */
    public $content;
    
    public static $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    
    public function __construct($buffer)
    {
        $buffer && $this->import($buffer);
    }
    
    public function import($buffer) 
    {
        $pattern = '/^<(\d+?)>(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+?(\d+?)\s+?([:\d]+?)\s+?([\w]+)\s+?([\s\S]+)/i';
        if(!preg_match($pattern, $buffer, $matches)) {
            trigger_error("invalid format $buffer", E_USER_WARNING);
            return false;
        }
        
        $matches[2] = ucfirst(strtolower($matches[2]));
        
        $month = array_search($matches[2], static::$months)+1;
        $day = $matches[3];
        $time = explode(':', $matches[4]);
        
        $this->severity = $matches[1]%8;
        $this->facility = ($matches[1]-$this->severity)/8;
        $this->time = date('Y-m-d H:i:s', mktime($time[0], $time[1], $time[2], $month, $day));
        $this->host = $matches[5];
        $this->content = $matches[6];
    }
    
    public function __toString() 
    {
        $pri = $this->facility*8+$this->severity;
        $date = date('M j H:i:s', $this->time ?  strtotime($this->time) : time() );
        return "<$pri>$date $this->host $this->content";
    }
}