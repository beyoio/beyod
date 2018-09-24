<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */

 
namespace beyod\protocol\http;

use yii\base\BaseObject;
use yii\web\HeaderCollection;
use yii\web\CookieCollection;

/**
 * Request represents the http request received by the server.
 * @since 1.0
 * 
 * @property HeaderCollection $headers
 * @property CookieCollection $cookies
 * @property string $fileName
 * @property string|null $host
 * @property int $code error code
 */

class Request extends BaseObject
{
    public $_POST=[];
    
    public $_GET = [];
    
    public $_FILES = [];
    
    public $_REQUEST = [];
    
    public $_COOKIE = [];
    
    public $_SERVER = [];
    
    protected $_filename='/';
    
    /**
     * @var float request arrived timestamp
     */
    public $requestAt;
    
    public $version;
    
    public $method;
    
    public $uri;
    
    public $charset;
    
    public $queryString;
    
    
    /**
     * @var \yii\web\CookieCollection
     */
    protected $_cookies;
    
    /**
     * @var \yii\web\HeaderCollection
     */
    protected $_headers;
    
    protected $_contentType;
    
    protected $_host;
    
    protected $_charset;
    
    protected $_userAgent;

    protected $_code = 0;
    
    protected $_message;
    
    protected $_rawHeader;
    
    protected $_rawBody;
    
    protected $_gzip;
    
    private static $SERVER=[];
    
    /**
     * load reqeust buffer to this object
     * @param string $buffer
     */
    public function loadRequest($buffer)
    {
        $this->requestAt = microtime(true);
        
        
        list($this->_rawHeader, $this->_rawBody) = explode("\r\n\r\n", $buffer, 2);
        
        $headers = explode("\r\n", $this->_rawHeader, 2);
        
        list($this->method, $this->uri, $this->version) = explode(' ',$headers[0]);
        
        $this->loadHeaders();
        
        $this->loadBody();
        
        $this->_REQUEST = array_merge($this->_GET, $this->_POST);
        
        $this->loadServerVars();
        $this->loadGet();
        
    }
    
    public function loadGet()
    {
        $segs = parse_url($this->uri);
        if(isset($segs['query'])) {
            $this->queryString = $segs['query'];
            parse_str($this->queryString, $this->_GET);
        }
    }
    
    public function loadServerVars()
    {
        $this->_SERVER = [];
        $this->_SERVER['REQUEST_TIME'] = time();
        $this->_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $this->_SERVER['PHP_SELF'] = $this->_filename;
        $this->_SERVER['SCRIPT_NAME'] = $this->_filename;
        $this->_SERVER['REQUEST_URI'] = $this->uri;
        $this->_SERVER['SERVER_SOFTWARE'] = \Yii::$app->server->server_token;
        $this->_SERVER['SERVER_NAME'] = $this->host;
    }
    
    public function init()
    {
        if(empty(static::$SERVER)) {
            static::$SERVER = $_SERVER;
        }
    }
    
    public function gzipSupport()
    {
        return $this->_gzip;
    }
    
    protected function clearUploadFiles($files=[])
    {
        foreach($files as $name =>$file){
            $node = current($file);
            if(!is_array($node)) {
                isset($file['tmp_name']) && is_file($file['tmp_name']) && unlink($file['tmp_name']);
            }else{
                $this->clearUploadFiles($file);
            }
        }
    }
    
    public function __destruct()
    {
        $this->clearUploadFiles($this->_FILES);
    }
    
    
    public function getCode()
    {
        return $this->_code;
    }
    
    public function getMessage()
    {
        return $this->_message;
    }
    
    public function getContentType()
    {
        return $this->_contentType;
    }
    
    public function getRawBody()
    {
        return $this->_rawBody;   
    }
    
    public function getRawHeader()
    {
        return $this->_rawHeader;
    }
    
    
    public function getCharset()
    {
        return $this->_charset;
    }
    
    public function getUserAgent()
    {
        return $this->headers->get('User-Agent');
    }
    
    public function getHostName()
    {
        return $this->_host;
    }
    
    public function getHost()
    {
        return $this->getHostName();
    }
    
    public function getFileName()
    {
        return $this->_filename;
    }
    
    public function loadHeaders()
    {
        foreach(explode("\r\n", $this->_rawHeader) as $i => $line){
            if(!$i || !preg_match('|^[\w\-_]+:.+$|', $line))  {
                continue;
            }
        
            list($name, $value) = explode(':', $line, 2);
            $name = str_replace(' ','-', ucwords(str_replace('-', ' ', strtolower($name))));
            $value = ltrim($value);
            
            $this->headers->add($name, $value);
        }
        
        $this->_host = $this->headers->get('Host');
        
        $this->_filename = '/';
        
        $segs = parse_url($this->uri);
        
        if(isset($segs['path'])) {
            $this->_filename = $segs['path'];
        }
        
        $this->_gzip = strpos($this->headers->get('Accept-Encoding'), 'gzip') !== false;
        
        $this->loadCookies();
    }
    
    public function getCookies()
    {
        if(!$this->_cookies){
            $this->_cookies = new CookieCollection();
        }
        
        return $this->_cookies;
    }
    
    public function getHeaders()
    {
        if(!($this->_headers instanceof  \yii\web\HeaderCollection)){
            $this->_headers = new HeaderCollection();
        }
        
        return $this->_headers;
    }
    
    
    protected function loadCookies()
    {
        if(!$this->headers->has('Cookie')) return ;
        
        $cookies=null;
        
        
        parse_str(str_replace('; ', '&', $this->headers->get('Cookie')), $cookies);
        
        if(!$cookies) return ;
        
        foreach($cookies as $name => $value){
            $this->cookies->add(\Yii::createObject([
                'class' => 'yii\web\Cookie',
                'name' => $name,
                'value' => $value,
                'expire' => null,
            ]));
            
            $_COOKIE[$name] = $value;
            $this->_COOKIE[$name] = $value;
        }
    }
    
    
    public function loadBody() {
        if(!$this->_rawBody || !in_array($this->method, ['POST', 'PUT'])) return;
        
        $ct = $this->headers->get('Content-Type');
        if(preg_match('#(.+);\s+charset=\s*(\S+)#i', $ct, $matches)){
            $this->charset = $matches[2];
            $ct = trim($matches[1]);
        }
        
        $ct = strtolower($ct);
        if(strpos($ct, 'application/x-www-form-urlencoded') === 0){
            parse_str($this->_rawBody, $this->_POST);
        }else if(strpos($ct, 'multipart/form-data') === 0){
            $this->parseMultipart();
        }else if(strpos($ct, 'application/json') === 0){
            $this->_POST = json_decode($this->_rawBody, true);
            if($this->_POST === null){
                throw new \Exception("error json input ". json_last_error_msg(), 400);
            }
        }
    }
    
    public function parseMultipart()
    {
        $boundary = null;
        if(preg_match('|boundary=(.+)|', $this->headers->get('Content-Type'), $matches)){
            $boundary = $matches[1];
        }else{
            throw new \Exception("boundary requied", 412);
        }
        
        if(strpos($this->_rawBody, $boundary) === false ) {
            throw new \Exception("boundary body requied", 412);
        }
        
        $post = [];
        $files = [];
        
        $segments = preg_split("#--{$boundary}(--)*\r\n#i", $this->_rawBody);
        foreach($segments as $seg){
            if(!$seg) continue;
            if(preg_match("|Content-Disposition:\s*form-data;\s*name=\"(.+)\"; filename=\"(.+)\"\r\nContent-Type:\s*(.*)\r\n\r\n([\S\s]+)\r\n|i", $seg, $matches)){
                
                $error = 0;
                $tmp = null;
                if(strlen(strlen($matches[3])) <0){
                    $error = UPLOAD_ERR_NO_FILE;
                }else{
                    $tmp=tempnam(\Yii::getAlias('@runtime'), 'BEYOUP');
                    if(!file_put_contents($tmp, $matches[4])){
                        $error = UPLOAD_ERR_CANT_WRITE;
                        $tmp = null;
                    }
                }
                
                $files[$matches[1]] = [
                    'name' => $matches[2],
                    'type' => $matches[3],
                    'size' => filesize($tmp),
                    'tmp_name' => $tmp,
                    'error' => $error,
                ];
                
                
            }else if(preg_match("|Content-Disposition:\s+form-data;\s+name=\"(.+)\"\r\n\r\n(.*)|i", $seg, $matches)){
                $post[] = urlencode($matches[1]).'='.urlencode($matches[2]);
            }
        }
        
        parse_str(http_build_query($files), $this->_FILES);
        
        parse_str(implode('&', $post), $this->_POST);
        
    }
}
