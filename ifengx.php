<?php

// http://ctowl.top:8010/TVOD/ifengx.php?id=cn&phone=18105550939&pwd=a666999&playseek=20250311190000-20250311193000
// 建一个TVOD目录，把fhshow放目录下，外网用对应域名端口访问



// 观看Log写入
function logtxt($logdata,$id,$type)
{
  $date_str = date("Y-m-d H:i:s");

if ($type) {

 		 $log_contents=file_put_contents("log.txt","$date_str  ------  $logdata ------ $id ----- Replay\n", FILE_APPEND | LOCK_EX);
		} else {
		 		 $log_contents=file_put_contents("log.txt","$date_str  ------  $logdata ------ $id ----- Live\n", FILE_APPEND | LOCK_EX);

				}
}

// 统一 cURL 请求（支持 GET 和 POST）
function httpRequest($url, $headers = [], $postData = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// Base64 URL 解码
function base64_url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// JWT 验证（检查是否过期）
function validateJWT($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;

    $payload = json_decode(base64_url_decode($parts[1]), true);
    return isset($payload['exp']) && $payload['exp'] > (time() - 3600); // 过期时间至少 1 小时
}

// 获取 Token，避免重复登录
function getToken($prefix, $user, $pwd) {
    $tokenFile = "fengshows_token_$user.txt";

    if (file_exists($tokenFile)) {
        $token = file_get_contents($tokenFile);
        if (validateJWT($token)) return $token;
    }

    $headers = ["Content-Type: application/json"];
    $body = json_encode([
        "code" => $prefix,
        "keep_alive" => false,
        "password" => $pwd,
        "phone" => $user
    ]);

    $response = httpRequest("https://m.fengshows.com/api/v3/mp/user/login", $headers, $body);
    $json = json_decode($response, true);

    if (isset($json["data"]["token"])) {
        file_put_contents($tokenFile, $json["data"]["token"]);
        return $json["data"]["token"];
    } else {
        die("登录失败: " . ($json["message"] ?? "未知错误"));
    }
}

// 时间转换函数（YYYYMMDDhhmmss → 十六进制毫秒时间戳）
function dateToUTCHex($time) {
    $dt = DateTime::createFromFormat('YmdHis', $time, new DateTimeZone("Asia/Shanghai"));
    if (!$dt) return false;
    return dechex($dt->getTimestamp() * 1000); // 转换为毫秒
}

// 获取直播 URL
function getLiveUrl($id, $headers) {
    $qualities = ["fhd", "hd"];
    foreach ($qualities as $qa) {
        $url = "https://api.fengshows.cn/hub/live/auth-url?live_qa=$qa&live_id=$id";
        $response = httpRequest($url, $headers);
        $json = json_decode($response, true);
        if (!empty($json["data"]["live_url"])) return $json["data"]["live_url"];
    }
    return null;
}

// 获取回放 URL
function getReplayUrl($id, $playseek, $headers) {
    $times = explode("-", $playseek);
    if (count($times) != 2) die("回放时间格式错误");

    $starttime = dateToUTCHex($times[0]);
    $endtime = dateToUTCHex($times[1]);

    if (!$starttime || !$endtime) die("时间转换失败");

    $qualities = ["fhd", "hd"];
    foreach ($qualities as $qa) {
        $url = "https://m.fengshows.com/api/v3/hub/live/auth-url?live_id=$id&live_qa=$qa&play_type=replay&ps_time=$starttime&pe_time=$endtime";
        $response = httpRequest($url, $headers);
        $json = json_decode($response, true);
        if (!empty($json["data"]["live_url"])) return $json["data"]["live_url"];
    }
    return null;
}

// 用户配置（需修改）
$phonePrefix = "86";  // 大陆 "86"，香港 "852"，美国 "1"
// $phone = "18105550939"; //需修改
// $pwd = "a666999";    //需修改
$phone = $_GET['phone'];
$pwd = $_GET['pwd'];

// 频道映射
$channels = [
    "cn" => "f7f48462-9b13-485b-8101-7b54716411ec", // 凤凰中文
    "info" => "7c96b084-60e1-40a9-89c5-682b994fb680", // 凤凰资讯
    "hk" => "15e02d92-1698-416c-af2f-3e9a872b4d78",  // 凤凰深圳
];

// 获取 URL 参数
$id = $_GET['id'] ?? 'cn';
$playseek = $_GET['playseek'] ?? null;
$chid = $channels[$id] ?? $id; // 允许直接传入 ID

$token = getToken($phonePrefix, $phone, $pwd);

// 直播和回放的请求头
$headersPlay = [
    "User-Agent: okhttp/3.14.9",
    "Accept-Encoding: identity",
    "Connection: close",
    "Referer: dispatch.fengshows.cn"
];

$headersReplay = [
    "User-Agent: okhttp/3.14.9",
    "Accept-Encoding: identity",
    "Connection: Keep-Alive",
    "Range: bytes=0-"
];

$headers = $playseek ? $headersReplay : $headersPlay;
$headers[] = "Token: $token";
$headers[] = "Origin: https://m.fengshows.com";

// 获取直播或回放 URL
if ($playseek) {
    $url = getReplayUrl($chid, $playseek, $headers);
	$wlog=logtxt($phone, $id,$playseek);
} else {
    $url = getLiveUrl($chid, $headers);
	$wlog=logtxt($phone, $id,"");
}

// 直接跳转到播放地址
if ($url) {
    header("Location: $url");
    exit;
} else {
    die("获取直播/回放地址失败");
}
?> 