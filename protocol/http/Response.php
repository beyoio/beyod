<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\http;


use yii\web\CookieCollection;
use yii\base\BaseObject;
use yii\web\HeaderCollection;

/**
 * Response represents the http response sending from server or received by the client.
 * @see http://www.beyo.io/document/class/protocol-http
 * @author zhang xu <zhangxu@beyo.io>
 * @since 1.0
 * 
 * @property HeaderCollection $headers
 * @property CookieCollection $cookies
 */

class Response extends BaseObject
{
    
    public $version = 'HTTP/1.1';
    public $statusCode = 200;
    public $statusReason = null;
    
    public $body='';
    
    public $mimeType = 'text/html';
    
    public $charset;
    
    public $gzip;
    
    /**
     * @var CookieCollection the cookies that will be send.
     */
    protected $_cookies = [];
    
    
    protected $_headers;
    
    
    
    public function init()
    {
        parent::init();
    }
    
    public function getHeaders()
    {
        if(!($this->_headers instanceof  \yii\web\HeaderCollection)){
            $this->_headers = new HeaderCollection();
        }
        
        return $this->_headers;
    }
    
    public function getCookies()
    {
        if(!($this->_cookies instanceof  \yii\web\CookieCollection)){
            $this->_cookies = new CookieCollection();
        }
        
        return $this->_cookies;
    }
    
    /**
     * construct a response that will be send to the client.
     * @param string $body
     * @param number $code
     * @param array $headers
     */
    public function __construct($code = 200, $body=null, array $headers = []){
        $this->body = $body;
        $this->statusCode = $code;
        
        foreach($headers as $name => $value)
        {
            $this->headers->add($name, $value);
        }
    }
    
    public function prepare()
    {
        $this->headers->set('Server', \Yii::$app->server->server_token);
        $timeZone = date_default_timezone_get();
        
        date_default_timezone_set('UTC'); 
        $this->headers->set('Date', Handler::gmtdate());
        
        date_default_timezone_set($timeZone);
        
        $this->attachCookies();
        
        if(empty($this->statusReason)){
            $this->statusReason = \yii\web\Response::$httpStatuses[$this->statusCode];
        }
        
        if($this->mimeType) {
            $this->headers->set('Content-Type', $this->mimeType);
            if($this->charset) {
                $this->headers->set('Content-Type', $this->mimeType.'; charset='.$this->charset);
            }
        }
    }
    
    public function __toString()
    {
        $this->prepare();
        $resp[] = $this->version.' '. $this->statusCode. ' ' .$this->statusReason;
        
        foreach($this->getHeaders() as $name => $values){
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach($values as $value){
                if($value === false) continue;
                $resp[] ="$name: ".$value;
            }
        }
        
        if($this->headers->get('Content-Length') === null){
            $resp[] ="Content-Length: ".strlen($this->body);
        }
        
        $resp[] = "\r\n".$this->body;
        
        return implode("\r\n", $resp);
    }
    
    protected function attachCookies()
    {
        $this->headers->remove('Set-Cookie');
        
        foreach($this->getCookies() as $cookie){
            /**
             * @var $cookie \yii\web\Cookie
             */
            $value = $cookie;
            $line = $cookie->name."=".urlencode($cookie->value);
            if($cookie->expire) {
                $line .="; Expires=".gmdate('Y-m-d H:i:s', $cookie->expire);
            }
            
            if($cookie->domain) {
                $line .="; domain=".$cookie->domain;
            }
            
            if($cookie->secure){
                $line .="; Secure";
            }
            
            if($cookie->httpOnly){
                $line .= "; HttpOnly";
            }
            
            $this->headers->add('Set-Cookie', $line);
        }
    }
}
