<?php
 
if (!function_exists('curl_init')) {
    println('need Curl module. please install it!', true);
}

set_time_limit(30);
 
$options = getopt('u:o:b:rh');
if (isset($options['h'])) {
    $help = <<<EOT
-h 帮助
-u m3u8的URL
-o 输出文件，默认：out.mp4
-b 每批网络请求的数量，默认:10
-r 保留下载的临时文件
EOT;
}
 
if (!isset($options['u'])) {
    println('no .m3u8 file url', true);
}
 
$u = $options['u'];
$o = isset($options['o']) ? $options['o'] : 'out.mp4';
$b = isset($options['b']) ? $options['b'] : 10;
$r = isset($options['r']);
 
$url_blocks = array();
$dir = dirname(__FILE__) . '/tmp/' . md5($u);
$ts_dir = $dir . '/ts';
$url_file = $dir . '/url.txt';
$response_file = $dir . '/response.txt';
$list_file = $dir . '/url_list.txt';
$pos_file = $dir . '/pos.txt';
 
$block_index = $index = 0;
if (file_exists($list_file)) {
    println('load index file...');
    $url_blocks = require($list_file);
 
    if (is_file($pos_file)) {
        $pos = file_get_contents($pos_file);
        list($block_index, $index) = explode(':', $pos);
    }
} else {
    println('read index file...');
 
    if (!file_exists($ts_dir)) {
        mkdir($ts_dir, 0775, true);
    }
 
    file_put_contents($url_file, $u);
    $response = $header = $body = '';
    if (httpGet($u, $response, $header, $body)) {
        file_put_contents($response_file, $response);
 
        $files = trim(preg_replace('/^#.+[\r\n]+/m', '', $body));
        $urls = preg_split("/[\r\n]+/", $files);
 
        $block = array();
        foreach ($urls as $key => $item) {
            $block[] = fixUrl($u, $item);
            if (($key + 1) % $b == 0) {
                $url_blocks[] = $block;
                $block = array();
            }
        }
        $url_blocks[] = $block;
 
        file_put_contents($list_file, '<?php return ' . var_export($url_blocks, true) . ';');
    }
}
 
//print_r($url_blocks);
//exit;
 
 
if ($url_blocks) {
    println('download, please wait...');
 
    $max = count($url_blocks);
    for ($i = $block_index; $i < $max; $i++) {
        $contents = httpMultiGet($url_blocks[$i]);
        if ($contents) {
            foreach ($contents as $content) {
                file_put_contents($ts_dir . '/' . $index . '.ts', $content);
                file_put_contents($pos_file, $i . ':' . $index);
                $index++;
            }
        } else {
            $i--; // 有请求失败的，重试
        }
        progress($max, $i + 1);
    }
 
    println('merge file...');
    $fp = fopen($o, 'wb');
    if ($fp) {
        for ($i = 0; $i < $index; $i++) {
            $content = file_get_contents($ts_dir . '/' . $i . '.ts');
            fwrite($fp, $content);
            progress($index, $i + 1);
        }
        fclose($fp);
    }
 
    if (!$r) {
        println('clean tmp file...');
        for ($i = 0; $i < $index; $i++) {
            //unlink($ts_dir . '/' . $i . '.ts');
        }
        unlink($pos_file);
        unlink($response_file);
    }
 
    println('done.', true);
} else {
    println('get index file fail.', true);
}
 
// ===============================
// functions
// ===============================
 
function println($string, $exit = false)
{
    echo $string . "\r\n";
    if ($exit) {
        exit();
    }
}
 
function progress($max, $current, $message = '')
{
    $p = 0;
    if ($current) {
        $p = round($current / $max * 100, 2);
    }
    printf("progress: [%-50s] %d%% {$message}\r", str_repeat('#', ceil($p / 2)), $p);
    if ($current == $max) {
        echo "\n";
    }
}
 
function fixUrl($current, $target)
{
    $target = str_replace('\\', '/', $target);
    if (false === strpos($target, '://')) {
        $current = str_replace('\\', '/', $current);
 
        $url_parts = parse_url($current);
        $host = $url_parts['scheme'] . '://' . $url_parts['host'] . (isset($url_parts['port']) ? ':' . $url_parts['port'] : '') . '/';
 
        if (0 === strpos($target, '//')) {
            return $url_parts['scheme'] . ':' . $target;
        } else {
            if (0 === strpos($target, '/')) {
                $url_path = '';
            } else {
                if (isset($url_parts['path'])) {
                    $url_path = $url_parts['path'];
                    if (false !== strpos($url_path, '.')) {
                        $url_path = (false !== ($pos = strrpos($url_path, '/'))) ? substr($url_path, 0, $pos + 1) : $url_path;
                    }
                }
            }
 
            $parts = explode('/', $url_path . $target);
            $arcv = array();
            foreach ($parts as $value) {
                if ($value !== '' && $value != '.') {
                    if ($value == '..') {
                        array_pop($arcv);
                    } else {
                        $arcv[] = $value;
                    }
                }
            }
 
            return $host . implode('/', $arcv);
        }
    }
    return $target;
}
 
function mkCurlHandler($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, true);  //表示需要response header
    curl_setopt($curl, CURLOPT_NOBODY, false);
    curl_setopt($curl, CURLOPT_AUTOREFERER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64; rv:71.0) Gecko/20100101 Firefox/71.0');
    curl_setopt($curl, CURLOPT_TIMEOUT, 120); // 时间长一些防止超时
    curl_setopt($curl, CURLOPT_REFERER, $url);
    curl_setopt($curl, CURLOPT_URL, $url);
    return $curl;
}
 
function httpGet($url, &$response, &$response_header, &$response_body)
{
    $curl = mkCurlHandler($url);
    $response = curl_exec($curl);
    $info = curl_getinfo($curl);
 
    $status = false;
    if ($info['http_code'] == 200) {
        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) > 1) {
            list($response_header, $response_body) = $parts;
            $status = true;
        }
    }
 
    curl_close($curl);
    return $status;
}
 
function httpMultiGet($urls)
{
    $bodys = array();
    $handlers = array();
    $mh = curl_multi_init();
    foreach ($urls as $i => $url) {
        $handler = mkCurlHandler($url);
        curl_multi_add_handle($mh, $handler);
        $handlers[$i] = $handler;
    }
 
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
 
    while ($active && $mrc == CURLM_OK) {  //直到出错或者全部读写完毕  
        if (curl_multi_select($mh) != -1) { // 防止CPU过高
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }
 
    foreach ($handlers as $i => $handler) {
        //获取当前解析的cURL的相关传输信息
        //$info = curl_multi_info_read($mh);
        $heards = curl_getinfo($handler);
        $response = curl_multi_getcontent($handler);
        if ($heards['http_code'] == 200) {
            $parts = explode("\r\n\r\n", $response, 2);
            if (count($parts) > 1) {
                list($response_header, $response_body) = $parts;
                $bodys[] = $response_body;
                if ($response_body == '') {
                    return array();
                }
            } else {
                return array();
            }
        }
 
        curl_multi_remove_handle($mh, $handler);
        curl_close($handler);
    }
 
    curl_multi_close($mh);
    return $bodys;
}
 