<?php
/**
 * @link http://www.beyod.net/
 * @copyright Copyright (c) 2018 beyod Software Team.
 * @license http://www.beyod.net/license/
 */


namespace beyod\protocol\http;

use Yii;
use beyod\ErrorEvent;
use beyod\MessageEvent;
use beyod\Connection;
use beyod\helpers\Formatter;
use beyod\Server;
use beyod\IOEvent;
use yii\helpers\FileHelper;

/**
 * http server handler.
 * 
 * @since 1.0
 */

class Handler extends \beyod\Handler
{
    /**
     * @var boolean whether enable http server.
     */
    public $enabled = true;
    
    public $default_content_type = "text/html";
    
    /**
     * @var string default charset for text mime types.
     */
    public $default_charset = 'utf-8';
    
    /**
     * @var string document root path
     */
    public $document_root;
    
    /**
     * @var boolean whether enable php engine for the requested php script.
     */
    public $php_engine = true;
    
    /**
     * @var bool whether enable directory listing
     */
    public $auto_index = YII_ENV_DEV;
    
    /**
     * @var array index pages
     */
    public $index = ['index.html', 'index.htm', 'index.php'];
    
    public $mime_file = __DIR__."/mimeTypes.php";
    
    /**
     * @var array callback for every location regexp match.
     * callback function's signature: function(MessaeEvent, Request $request, Response, $response, $path)
     * @example static::usersList
     */
    public $callback=[
        //'|^/user|' => 'usersList',
    ];
    
    /**
     * @var array Error codes and corresponding files
     */
    public $error_pages = [
        '40x' => __DIR__.'/40x.html',
        '400' => __DIR__.'/400.html',
        '50x' => __DIR__.'/50x.html'
    ];
    
    
    public $gzip = true;
    
    public $gzip_min_bytes = 4096;
 
    public $gzip_max_bytes = 1048576;
    
    public $gzip_level = 6;
    
    public $gzip_types = ['text/css', 'text/javascript', 'application/javascript', 'text/xml', 'text/plain'];
    
    /**
     * @var string static file key name for current connection.
     */
    protected $fileKey;
    
    /**
     * @var int Number of bytes read per time when serve static file.
     */
    public $read_buffer = 65536;
    
    public static function gmtdate($timestamp=0)
    {
        return $timestamp ? date('D, j M Y H:i:s', $timestamp).' GMT' : date('D, j M Y H:i:s').' GMT';
    }
    
    public function init()
    {
        parent::init();
        if($this->document_root) {
            $this->document_root = realpath($this->document_root);
        }
        
        if(!$this->fileKey) {
            $this->fileKey = uniqid('',true);
        }
    }
    
    public function __destruct()
    {
        
    }
    
    public function usersList(MessageEvent $event, $req, $res)
    {
        $user = ['name'=>'sendy', 'age'=>23, 'country'=>'GEM'];
        $res->body =\yii\helpers\Json::encode($user);
        
        //must return response object to by pass other process.
        
        return $res;
    }
    
    /**
     * send error response and close the connection.
     * 
     * @param Connection $connection
     * @param int $statusCode
     * @param string $body
     * @param string $reason
     */
    public function errorResponse($connection, $statusCode, $body=null,  $reason=null, array $headers=[])
    {
        $res= new Response($statusCode, $body);
        if(!$reason) {
            $res->statusReason = \yii\web\Response::$httpStatuses[$statusCode];
        }
        
        if($body === null){
            $res->body = \yii\web\Response::$httpStatuses[$statusCode];
            
            $errpage = $this->getErrorPageFile($res->statusCode);
            
            if($errpage) {
                ob_start();
                include $errpage;
                $res->body = ob_get_clean();
            }
        }
        
        foreach($headers as $name => $value){
            $res->headers->add($name, $value);
        }
        
        $res->headers->remove('Content-Length');
        $res->headers->set('Connection', 'Close');
        
        $connection->close($res);
    }
    
    public function beforeProcess(MessageEvent $event)
    {
        /** @var $event->message Request */
        $event->message->_SERVER['SERVER_ADDR'] = $event->sender->local;
        $event->message->_SERVER['REMOTE_ADDR'] = $event->sender->peer;
        $event->message->_SERVER['DOCUMENT_ROOT'] = $this->document_root;
        $event->message->_SERVER['REQUEST_SCHEME'] = $event->sender->listener->isSSL() ? 'https':'http';
    }
    
    /**
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    public function beforeSend($event, $req, $res, $path)
    {
        if($res->statusCode >=400){
            $res->headers->set('Content-Length', null);
            $res->headers->set('Connection', 'Close');
            return ;
        }
        
        $res->headers->set('Connection', 'keep-alive');
    }
    
    public function onMessage(MessageEvent $event)
    {
        /**
         * @var $req Request
         */
        $req = $event->message;
        $res = new Response();
        
        try{
            if(!$this->enabled) {
                throw new \Exception("http server is disabled", 403);
            }
            
            if($req->code > 0 ){
                throw new \Exception($req->getMessage(), $req->code);
            }
            
            $path = realpath($this->document_root.'/'.urldecode($req->fileName));
            
            $this->beforeProcess($event);
            
            if($this->processLocation($event, $req, $res, $path)){
                throw new \Exception(null, 0);
            }
            
            
            if(empty($this->document_root) || !is_dir($this->document_root)) {
                throw new \Exception("docuent_root is not configed", 404);
            }
            
            if(!$path || strpos($path, $this->document_root) !==0){
                throw new \Exception("Not found", 404);
            }
            
            if(is_dir($path)) {
                $this->processDir($event, $req, $res, $path);
                throw new \Exception('', 0);
            }
            
            $this->processFile($event, $req, $res, $path);
            
        }catch(\Exception $e){
            if($e->getCode() ){
                $res->statusCode = $e->getCode();
                $res->body = $e->getMessage();
            }
        }
        
        $this->afterProcess($event, $req, $res, $path);
        
        $this->beforeSend($event, $req, $res, $path);
        
        if($res->statusCode >= 400){
            return $event->sender->close($res);
        }
        
        return $event->sender->send($res);
    }
    
    /**
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    protected function afterProcess($event, $req, $res, $path)
    {
        if($res->statusCode >= 400) {
            $errpage = $this->getErrorPageFile($res->statusCode);
            if($errpage) {
                ob_start();
                @include $errpage;
                $res->body = ob_get_clean();
            }
        }
    }
    
    /**
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    public function processFile($event, $req, $res, $path)
    {
        $info = pathinfo($path);
        if(substr($info['filename'], 0, 1) == '.') {
            $res->statusCode = 403;
            $res->body = "Not Accessable";
            return ;
        }
        
        if(strtolower($info['extension']) === 'php') {            
            return $this->processPhpFile($event, $req, $res, $path);
        }
        
        return $this->processStaticFile($event, $req, $res, $path);
    }
    
    /**
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     * @param int $size
     * @return bool
     */
    public function processStaticFile($event, $req, $res, $path)
    {
        $res->mimeType = FileHelper::getMimeTypeByExtension($path, __DIR__.'/mimeTypes.php');
        if($res->mimeType === null){
            $res->mimeType = FileHelper::getMimeType($path);
        }
        
        if($this->default_charset && $res->charset===null && substr($res->mimeType, 0, 5) == 'text/') {
            $res->charset = $this->default_charset;
        }
        
        if(in_array($req->method, ['POST', 'PUT'])) {
            $res->statusCode = 405;
            return ;
        }
        
        $modified = filemtime($path);
        
        $res->headers->set('Last-Modified', static::gmtdate($modified));
        
        $res->headers->set('Accept-Ranges', 'bytes');
        
        if($req->headers->get('If-Modified-Since') === $res->headers->get('Last-Modified')){
            $res->statusCode = 304;
            return ;
        }
        
        $this->sendFile($event, $req, $res, $path);
    }
    
    /**
     * send static file response.
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    public function sendFile($event, $req, $res, $path)
    {
        clearstatcache();
        $filesize = sprintf("%u", filesize($path));
        
        $res->headers->set('Content-Length', $filesize);
        
        $res->headers->set('Accept-Ranges', 'bytes');
        
        if($req->method == 'HEAD') {
            return ;
        }
        
        if(in_array($res->mimeType, $this->gzip_types) && 
            $this->sendGzipContent($event, $req, $res, $path, $filesize)) {
            return ;
        }
        
        if($this->attachFile($event, $req, $res, $path, $filesize)){
            
            $data = [
                'req' => $req,
                'res' => $res,
                'path' =>$path,
                'filesize' => $filesize
            ];
            $event->sender->on(Server::ON_BUFFER_DRAIN, [$this, 'sendPartial'], $data);
        }
    }
    
    /**
     * send gzip file response.
     *
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    
    protected function sendGzipContent($event, $req, $res, $path, $filesize)
    {
        if(!$this->gzip || !$req->gzipSupport() || $filesize < $this->gzip_min_bytes || $filesize >$this->gzip_max_bytes) {
            return false;
        }
        
        $res->headers->set('Content-Encoding', 'gzip');
        $res->body = gzencode(file_get_contents($path), $this->gzip_level);
        $res->headers->set('Content-Length', strlen($res->body));
        return $res;
    }
    
    /**
     * attach file file to be readed for current request.
     * 
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     * @param int $size
     * @return bool
     */
    protected function attachFile($event, $req, $res, $path, $size)
    {        
        $range = null;
        $event->sender->setAttribute('accept-range', null);
        
        if($req->headers->has('Range')) {            
            list($range[0], $range[1]) = explode('-', str_ireplace('bytes=', '', $req->headers->get('Range')));
            if(!$range[0]) $range[0] = 0;
            if(!$range[1]) $range[1] = $size-1;
            
            if($range[1] <$range[0]) {
                $res->statusCode = 416;
                return false;
            }
        }
        
        
        $info = [
            'name' => $path,
            'range' => $range,
        ];
        
        $event->sender->addFileHandler($this->fileKey, fopen($path, 'r'));        
        
        if(!$event->sender->getFileHandler($this->fileKey)) {
            Yii::error("cannot open $path", 'beyod\http');
            throw new \Exception("Server internal error", 503);
        }
        
        if($range) {
            $event->sender->setAttribute('accept-range', $range);
            $res->headers->set('Content-Length', $range[1]-$range[0]+1);
            $res->headers->set('Content-Range', 'bytes '.($range[0]).'-'.($range[1]).'/'.$size);
            fseek($event->sender->getFileHandler($this->fileKey) , $range[0]);
            $res->statusCode = 206;
        }
        
        return true;
    }
    
    /**
     * send php file response.
     * 
     * @param MessageEvent $event
     * @param Request $request
     * @param Response $response
     * @param string $file
     */
    public function processPhpFile($event, $request, $response, $file)
    {
        if(!$this->php_engine){
            $response->statusCode = 403;
            $response->body = "php engine disabled";
            return ;
        }
        
        if(!$response->headers->has('Content-type')) {
            if($response->charset === null && $this->default_charset){
                $response->charset = $this->default_charset;
            }
            
            $response->mimeType = $this->default_content_type;
        }
        
        $_POST = $request->_POST;
        $_GET = $request->_GET;
        $_FILES = $request->_FILES;
        $_COOKIE = $request->_COOKIE;
        $_SERVER = $request->_SERVER;
        
        ob_start();        
        include $file;
        
        if($response->gzip && $request->gzipSupport()){
            $response->headers->set('Content-Encoding', 'gzip');
            $response->body = gzencode(ob_get_clean());
        }else{
            $response->body = ob_get_clean();
        }
        
        return ;
    }
    
    /**
     * process dir request.
     * 
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     */
    public function processDir($event, $req, $res, $path)
    {
        foreach($this->index as $name){
            $index_path = realpath($path.'/'.$name);
            if(is_file($index_path)) {
                return $this->sendFile($event, $req, $res, $index_path);
            }
        }
        
        if(!$this->auto_index){
            throw new \Exception("Directory browsing is Disabled", 403);
        }
        
        $this->dirList($path, $res);
        
        
    }
    
    /**
     * process location match and callback.
     * 
     * @see static::$callback
     * 
     * @param MessageEvent $event
     * @param Request $req
     * @param Response $res
     * @param string $path
     * 
     * @return Response|false  Returns Response indicating that the subsequent process is terminated
     * false returned indicating the continuation of the subsequent process
     */
    public function processLocation(MessageEvent $event, $req, $res, $path)
    {
        foreach($this->callback as $location => $callable) {
            if(!preg_match($location, $path)) continue;
            
            $callable = is_string($callable) ? [$this, $callable] : $callable;
            
            if(!is_callable($callable)) {
                return $this->errorResponse($event->sender, 503, "$location defined invalid callback");
            }
            
            return call_user_func($callable, $event, $req, $res, $path);
        }
        
        return false;
    }
    
    /**
     * find error page file by response status code.
     */
    protected function getErrorPageFile($statusCode)
    {
        $errorpage = null;
        if(isset($this->error_pages[$statusCode])) {
            $errorpage = $this->error_pages[$statusCode];
        }else if(isset($this->error_pages[substr($statusCode,0,2).'x'] )) {
            $errorpage = $this->error_pages[substr($statusCode,0,2).'x'];
        }
        
        if(!$errorpage || !is_file($errorpage)) return null;
        
        return $errorpage;
    }
    
    /**
     * send partial bytes of file to client.
     * 
     * @param IOEvent $event
     */
    public function sendPartial($event)
    {
        $range = $event->sender->getAttribute('accept-range');        
        $readSize = $this->read_buffer;
        $pos = ftell($event->sender->getFileHandler($this->fileKey));
        
        if($range){
            $readSize = min($range[1]-$pos+1, $readSize);
        }
        
        if($readSize <=0){
            return $this->sendPartialFinish($event);
        }
        
        $c = fread($event->sender->getFileHandler($this->fileKey), $readSize);
        
        if($range && $pos >= $range[1]) {
            return $this->sendPartialFinish($event);
        }
        
        if($c === false){
            \Yii::error("error while sending file ".$event->data['path'], 'beyod\http');
            return $event->sender->close();
        }
        
        if(feof($event->sender->getFileHandler($this->fileKey))){
            $this->sendPartialFinish($event);
        }
        
        $event->sender->send($c, true);
        
        //fflush($event->sender->getSocket());
    }
    
    protected function sendPartialFinish($event)
    {
        Yii::debug($event->sender . " send file finished", 'beyod\http');
        $event->sender->off(Server::ON_BUFFER_DRAIN, [$this, 'sendPartial']);
        $event->sender->closeFileHandler($this->fileKey);
    }
    
    
    
    /**
     * direcoty list
     * 
     * @param string $dir
     * @param Response $res
     */
    protected function dirList($dir, $res)
    {
        $list = [];
        $dirs = $files = [];
        
        $dh = opendir($dir);
        if(!$dh) return ;
        
        while(true)
        {
            $file = readdir($dh);
            if(!$file) break;
            
            if($file == '.' || $file == '..') continue;
            
            $path = $dir.'/'.$file;
            $isDir = is_dir($path);
            
            if($isDir) {
                $dirs[] = [
                    'isdir' => $isDir,
                    'path' => $file,
                    'name' => basename($path)
                ];
            }else{
                $files[] = [
                    'isdir' => $isDir,
                    'path' => $file,
                    'name' => basename($file),
                    'size' => \Yii::$app->formatter->asShortSize(sprintf("%u",filesize($path))) ,
                    'modified' => date('Y-m-d H:i:s', filemtime($path))
                ];
            }
        }
        
        $baseDir = str_replace([$this->document_root, '\\'], ['', '/'], $dir).'/';
        ob_start();
        include __DIR__.'/dirlist.html';
        $res->body = ob_get_clean();        
    }
    
    /**
     * send error response to client
     * {@inheritDoc}
     * @see \beyod\Handler::onBadPacket()
     */
    public function onBadPacket(ErrorEvent $event) {
        $this->errorResponse($event->sender, $event->code, $event->errstr);   
    }
}
