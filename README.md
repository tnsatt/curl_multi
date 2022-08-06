# curl_multi
Multiple requests(downloads) using curl_multi PHP

# Request
```
$callback = new class extends CurlCallback{
    public function onStart($item, $curl)
    {
        $item->url="https://httpbin.org/get?index={$item->index}";
        echo "[start] ".$item->url.PHP_EOL;
    }
    public function onDone($item, $curl){
        echo "[done] ".trim($item->text).PHP_EOL;
    }
};
$curl = new CurlMulti([], 5, 10);
$curl->callback = $callback;
$curl->printProgress = true;
$curl->start();
```
Or using list
```
$list=[
    "https://httpbin.org/get?index=1",
    "https://httpbin.org/get?index=2",
    "https://httpbin.org/get?index=3",
    "https://httpbin.org/get?index=4",
    "https://httpbin.org/get?index=5",
];
$callback = new class extends CurlCallback{
    public function onStart($item, $curl)
    {
        echo "[start] ".$item->url.PHP_EOL;
    }
    public function onDone($item, $curl){
        echo "[done] ".trim($item->text).PHP_EOL;
    }
};
$curl = new CurlMulti($list, 3);
$curl->callback = $callback;
$curl->start();
```

# Download
```

$path = "C:\\test";
$callback = new class extends CurlCallback{
    public function onStart($item, $curl)
    {
        $item->url = "https://raw.githubusercontent.com/tnsatt/curl_multi/main/test_file.zip";
        echo "[start] {$item->url}".PHP_EOL;
    }
    public function onDone($item, $curl){
        echo "[done] {$item->url}".PHP_EOL;
    }
};
$curl = new CurlMultiDownload([], $path, 5, 10);
$curl->callback = $callback;
$curl->printProgress = true;
$curl->start();
```



