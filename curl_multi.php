<?php
include_once(__DIR__."/req.php");
class CurlCallback
{
    public function onStart($item, $curl)
    {
    }
    public function onDone($item, $curl)
    {
    }
}
class RequestItem
{
    public $url = null;
    public $data = null;
    public $upload_file = null;
    public $headers = [];
    public $text = null;
    public $success = false;
    public $done = false;
    public $size = 0;
    public $ch = null;
    public $index = -1;
    public $name = null;
    public $request_headers=null;
    public $input = [];
    public $timeout = 300;
    public $code = 0;
    public $info = null;
    
    public function __construct($url = null)
    {
        $this->url = $url;
    }
    public function json(){
        if($this->text===null) return null;
        return json_decode($this->text, true);
    }
    public function readHeader($ch, $line)
    {
        $str = trim($line);
        if($str==="") return strlen($line);
        $this->headers[] = $str;
        return strlen($line);
    }
}
class DownloadItem extends RequestItem
{
    public $path = null;
    public $temp = null;
    public $fp = null;
    public $is_temp = false;

    public function __construct($url = null)
    {
        $this->url = $url;
    }
}
class CurlMultiDownload extends CurlMulti
{
    public $tempDir;
    public $path = null;
    public $isTemp = false;
    public $overwrite = false;
    public function __construct($list, $path, $thread = 3, $max = 0, $overwrite=false)
    {
        parent::__construct($list, $thread, $max);
        if (!$path) throw new Exception("Undefined Download Directory");
        $this->path = $this->format_path($path, "/", true);
        if (!file_exists($this->path)) {
            @mkdir($this->path, 0777, true);
        }
        $this->tempDir = sys_get_temp_dir() . "/curlmultidownload/";
        if (!file_exists($this->tempDir)) {
            @mkdir($this->tempDir, 0777, true);
        }
        $this->overwrite = $overwrite;
    }
    public function start()
    {
        parent::start();
    }
    public function loop()
    {
        $index = $this->index;
        $this->index++;
        $this->connect++;
        $this->count++;
        if ($this->auto) {
            $item = new DownloadItem();
        } else {
            $item = $this->list[$index];
            if (is_string($item)) $item = new DownloadItem($item);
        }
        $item->index = $index;
        $res = $this->callback->onStart($item, $this);
        if ($res === false) {
            $this->connect--;
            $this->stop = true;
            return false;
        }
        if ($this->auto) $this->total++;
        $this->list[$index] = $item;
        if ($item->path) $item->path = $this->format_path($item->path, "/", true);
        if ($this->isTemp) {
            $item->temp = $this->check_file($this->tempDir . "/" . uniqid(rand(), true));
            $item->is_temp = true;
        } else {
            if ($item->path) {
                $path = $item->path;
                $item->is_temp = false;
            } else {
                $item->is_temp = true;
                $path = $this->tempDir . "/" . parseFilename($item->url).".part";
            }
            $dir = dirname($path);
            $item->name = basename($path);
            if (!file_exists($dir)) @mkdir($dir, 0777, true);
            $item->temp = $this->overwrite && !$item->is_temp?$path:$this->check_file($path);
        }
        $item->fp = fopen($item->temp, 'w+');
        $item->ch = $this->curl_download_handle($item->url . "#" . $index, $item->fp, $item->data, 
        $item->upload_file, $item->request_headers, $item->timeout, $item->input, $item);
        curl_multi_add_handle($this->mh, $item->ch);
        return true;
    }
    public function handle($item, $info)
    {
        if ($item->fp) fclose($item->fp);
        curl_close($item->ch);
        $item->code = $info['http_code'];
        $item->info = $info;
        if (file_exists($item->temp)) {
            $content_length = curl_getinfo($item->ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $size = filesize($item->temp);
            $d = $content_length >= 0;
            if (($d && $size == $content_length) || (!$d && $size > 0)) {
                $item->size = $size;
                if ($item->is_temp) {
                    if (!$item->path) {
                        $item->headers = headers_decode($item->headers);
                        $name = getFilename($item->headers, $item->url);
                        $item->path = $this->path . "/" . $name;
                    }
                    if(!$this->overwrite) $item->path = $this->check_file($item->path);
                    $dir = dirname($item->path);
                    if (!file_exists($dir)) @mkdir($dir, 0777, true);
                }
                if (!$item->is_temp) {
                    $item->path = $item->temp;
                    $this->success++;
                    $item->success = true;
                } else if (rename($item->temp, $item->path)) {
                    $this->success++;
                    $item->success = true;
                } else {
                    $this->fail++;
                    $item->success = false;
                    @unlink($item->temp);
                }
            } else {
                $this->fail++;
                $item->success = false;
                @unlink($item->temp);
            }
        } else {
            $this->fail++;
            $item->success = false;
        }
        $this->done++;
        $item->done = true;
        $this->callback->onDone($item, $this);
    }
    function format_filename($name, $full = false)
    {
        if ($full) {
            return trim(preg_replace('/([^\\x20-~]+)|([\\/:?\"<>|\s\%\_\r\n]+)/', '_', $name));
        }
        $name = preg_replace('/([\\/:*?\"<>|]+)/', '', $name);
        $name = preg_replace('/[\\s\\r\\n]+/', ' ', $name);
        $name = trim($name);
        return $name;
    }
    public function status()
    {
        if ($this->printMode == 0 || !$this->printProgress) return;
        if ($this->end) {
            // $this->err("                                                                       \r");
            $this->err("[completed] " . gmdate('H:i:s') . " Downloaded " . $this->success . "/"
                . $this->done . "/" . $this->total . " Success ("
                . $this->format_time(time() - $this->start) . ")                          " . PHP_EOL);
            return;
        }
        $this->err("[download] " . gmdate('H:i:s') . " " . $this->connect
            . " Files (" . $this->success . "/" . $this->done . "/" . $this->total . ") "
            . $this->format_time(time() - $this->start) . "           \r");
    }
    function curl_download_handle($url, $fp, $data = null, $file=null, $headers=[], $timeout = 300, $input=[], $item=null)
    {
        $ch = $this->curl_handle($url, $data, $file, $headers, $timeout, $input, $item);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        return $ch;
    }
}
class CurlMulti
{
    public const USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36";
    public $maxConnect = 50;
    public $maxSize = 100 * 1024 * 1024;
    public $total_size = 0;
    public $size = 0;
    public $count = 0;
    public $connect = 0;
    public $success = 0;
    public $fail = 0;
    public $total = 0;
    public $done = 0;
    public $total_file = 0;
    public $index = 0;
    public $start = 0;
    public $max = 0;
    public $list = [];
    public $auto = false;
    public $mh = null;
    public $stop = false;
    public $end = false;
    public $callback = null;
    public $printMode = 1;
    public $printProgress = false;
    public $debug = false;

    public function __construct($list, $thread = 3, $max = 0)
    {
        $this->list = $list;
        $this->maxConnect = $thread;
        $this->max = $max;
        $this->mh = curl_multi_init();
        $this->auto = $this->list == null || count($this->list) == 0;
    }
    public function start()
    {
        $this->total = count($this->list);
        $this->start = time();
        $this->reloop();
        $this->main();
    }
    public function reloop()
    {
        if ($this->stop) return;
        if ($this->connect >= $this->maxConnect) return;
        if ($this->auto) {
            if ($this->max > 0 && $this->index >= $this->max) {
                $this->stop = true;
                return;
            }
            $max = $this->maxConnect - $this->connect;
            if ($this->max > 0) {
                if ($max > $this->max - $this->index) $max = $this->max - $this->index;
            }
        } else {
            if ($this->index >= $this->total) {
                $this->stop = true;
                return;
            }
            $max = $this->total - $this->index;
            if ($max > $this->maxConnect - $this->connect) $max = $this->maxConnect - $this->connect;
        }
        for ($i = 0; $i < $max; $i++) {
            if ($this->stop) break;
            if ($this->loop() === false) break;
        }
    }
    public function loop()
    {
        $index = $this->index;
        $this->index++;
        $this->connect++;
        $this->count++;
        if ($this->auto) {
            $item = new RequestItem();
        } else {
            $item = $this->list[$index];
            if (is_string($item)) $item = new RequestItem($item);
        }
        $item->index = $index;
        $res = $this->callback->onStart($item, $this);
        if ($res === false) {
            $this->connect--;
            $this->stop = true;
            return false;
        }
        if ($this->auto) $this->total++;
        $this->list[$index] = $item;
        $item->ch = $this->curl_handle($item->url . "#" . $index, $item->data, $item->upload_file, 
        $item->request_headers, $item->timeout, $item->input, $item);
        curl_multi_add_handle($this->mh, $item->ch);
        return true;
    }
    public function main()
    {
        $running = 0;
        do {
            curl_multi_exec($this->mh, $running);
            $this->connect = $running;
            if ($running) {
                curl_multi_select($this->mh);
            }
            $this->reloop();
            $running = $this->connect;
            while ($done = curl_multi_info_read($this->mh)) {
                $info = curl_getinfo($done['handle']);
                curl_multi_remove_handle($this->mh, $done['handle']);
                $s = explode("#", $info['url']);
                $i = intval($s[count($s) - 1]);
                $item = $this->list[$i];
                if (!$item) continue;
                $this->handle($item, $info);
                $this->list[$i] = null;
            }
            $this->status();
        } while ($running > 0);
        foreach ($this->list as $index => $item) {
            if (!$item) continue;
            $info = curl_getinfo($item->ch);
            curl_multi_remove_handle($this->mh, $item->ch);
            $this->handle($item, $info);
        }
        curl_multi_close($this->mh);
        $this->end = true;
        $this->status();
    }
    public function handle($item, $info)
    {
        $item->text = curl_multi_getcontent($item->ch);
        curl_close($item->ch);
        $item->code = $info['http_code'];
        $item->info = $info;
        if ($info['http_code'] == 200) {
            $this->success++;
            $item->success = true;
        } else {
            $this->fail++;
            $item->success = false;
        }
        $this->done++;
        $item->done = true;
        $this->callback->onDone($item, $this);
    }
    public function status()
    {
        if ($this->printMode == 0 || !$this->printProgress) return;
        if ($this->end) {
            // $this->err("                                                                       \r");
            $this->err("[completed] " . gmdate('H:i:s') . " Requested " . $this->success . "/"
                . $this->done . "/" . $this->total . " Success ("
                . $this->format_time(time() - $this->start) . ")                          " . PHP_EOL);
            return;
        }
        $this->err("[request] " . gmdate('H:i:s') . " " . $this->connect
            . " Files (" . $this->success . "/" . $this->done . "/" . $this->total . ") "
            . $this->format_time(time() - $this->start) . "           \r");
    }
    function curl_handle($url, $data = null, $file=null, $headers=[], $timeout = 300, $input=[], $item=null)
    {
        $ch = curl_init(str_replace(" ", "%20", $url));
        if ($headers) {
            $headers = headers_encode($headers);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if($file){
            if(!$data) $data=[];
            if (function_exists('curl_file_create')) {
                $cFile = curl_file_create($file);
            } else {
                $cFile = '@' . realpath($file);
            }
            $data['file'] = $cFile;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }else if ($data) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                is_array($data) ? http_build_query($data) : $data
            );
        }
        $this->curl_setbase($ch, $timeout, $input);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$item, 'readHeader']);
        return $ch;
    }
    function format_path($path, $sep = DIRECTORY_SEPARATOR, $trim = false)
    {
        $path = preg_replace('/[\/\\\\]+/', $sep, $path);
        if ($trim) $path = trim($path, $sep);
        return $path;
    }
    function check_file($path, $base = '', $prefix = " (", $suffix = ")", $slash = "/")
    {
        if ($base == null) {
            $base = "";
        } else if ($base !== "") {
            $base = $this->format_path($base, $slash) . $slash;
        }
        $path = $this->format_path($path, $slash);
        $path = rtrim($path, $slash);
        $file = $base . $path;
        $exists = file_exists($file);
        if (!$exists && $path !== "") {
            return $path;
        }
        $p = strrpos($path, $slash);
        if ($p === false) {
            $dir = "";
            $name = $path;
        } else {
            $dir = substr($path, 0, $p + 1);
            $name = substr($path, $p + 1);
        }
        $p = strrpos($name, ".");
        if ($p === false) {
            $ext = "";
        } else {
            $ext = substr($name, $p);
            if ($ext === ".") $ext = "";
            $name = substr($name, 0, $p);
        }
        $newName = null;
        $count = 1;
        if ($exists || ($path !== "" && file_exists($base . $dir))) $list = scandir($base . $dir);
        else $list = [];
        do {
            $newName = trim($name . $prefix . $count . $suffix . $ext);
            $count++;
        } while (in_array($newName, $list));
        return $dir . $newName;
    }
    function curl_setbase(&$ch, $timeout = 30, $input = [])
    {
        if (!$input) {
            $input = [];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($timeout) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        if (isset($input['proxy'])) {
            if (!isset($input['auth'])) {
                $input['auth'] = null;
            }
            if (!isset($input['proto'])) {
                $input['proto'] = 'http';
            }
            $this->curl_setproxy($ch, $input['proxy'], $input['auth'], $input['proto']);
        }
        if (isset($input['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, $input['referer']);
        }
        if (isset($input['useragent'])) {
            curl_setopt($ch, CURLOPT_USERAGENT, $input['useragent']);
        } else {
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        }
        if (isset($input['opt'])) {
            foreach ($input['opt'] as $k => $i) {
                curl_setopt($ch, $k, $i);
            }
        }
    }
    function curl_setproxy(&$ch, $proxy = null, $auth = null, $type = 'HTTP')
    {
        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if (!$type) {
                $type = "HTTP";
            }
            $type = strtoupper($type);
            if ($type == "SOCKS5") {
                $type = CURLPROXY_SOCKS5;
            } else {
                $type = CURLPROXY_HTTP;
            }
            curl_setopt($ch, CURLOPT_PROXYTYPE, $type);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, 'CURL_HTTP_VERSION_1_1');
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);
            if (!empty($auth)) {
                $auth = preg_replace('/((\r)?\n)+/', ':', $auth);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
            }
        }
    }
    function err($text){
        if (defined('STDERR')) {
            fwrite(STDERR, $text);
        } else {
            echo $text;
        }
    }
    function format_time($num)
    {
        if ($num < 0) {
            $a = true;
        } else {
            $a = false;
        }
        $num = abs($num);
        if ($num < 60) {
            $num = floor($num) . 's';
        } else if ($num < 3600) {
            $num = floor($num / 60) . 'm';
        } else if ($num < 86400) {
            $num = floor($num / 60 / 60) . 'h';
        } else {
            $num = floor($num / 60 / 60 / 24) . 'd';
        }
        if ($a) {
            return '-' . $num;
        }
        return $num;
    }
    function deb($text){
        if($this->debug){
            $this->err($text);
        }
    }
}
