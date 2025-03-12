<?php

define('DS', DIRECTORY_SEPARATOR);
define('NODE_DIR', __DIR__ . DS . 'nodes' . DS);

// 获取 Node ID，如果不存在则跳转到主页
$request_uri = $_SERVER['REQUEST_URI'];
$node_id = preg_match('/^\/mesh\/([a-z0-9]{8,32})$/', $request_uri, $matches) ? $matches[1] : null;

if (empty($node_id)) {
    http_response_code(302);
    header("Location: https://www.vsean.net/routeros-wireguard-mesh-sync/");
    exit;
}

// 获取节点提交信息
$identityName = filter_input(INPUT_POST, 'identity_name');
$wgListenPort = filter_input(INPUT_POST, 'wg_listen_port');
$wgPublicKey = filter_input(INPUT_POST, 'wg_public_key');
$remoteIP = $_SERVER['X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

$identityName = strtolower($identityName);

if (empty($identityName) || empty($wgListenPort) || empty($wgPublicKey)) {
    echo ":log error (\"Invalid request\")";
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $identityName)) {
    echo ":log error (\"Invalid identity name\")";
    exit;
}

if ($identityName === 'mikrotik' || $identityName === 'routeros') {
    echo ":log error (\"Identity name can't be default name\")";
    exit;
}

// 读取 node 信息
$node_path = NODE_DIR . $node_id . '.json';
if (file_exists($node_path)) {
    $orig_file = file_get_contents($node_path);
    $record = json_decode($orig_file, true);
} else {
    $orig_file = '';
    $record = [];
}

// 更新节点信息
$haveRecord = false;
foreach ($record as $key => $item) {
    if ($item['identity_name'] === $identityName) {
        $record[$key]['wg_public_key'] = $wgPublicKey;
        $record[$key]['remote_ip']     = $remoteIP;
        $record[$key]['listen_port']   = $wgListenPort;
        $haveRecord                    = true;
        break;
    }
}

if (!$haveRecord) {
    $record[] = [
        'identity_name' => $identityName,
        'wg_public_key' => $wgPublicKey,
        'remote_ip'     => $remoteIP,
        'listen_port'   => $wgListenPort,
    ];
}

$content = json_encode($record, JSON_PRETTY_PRINT);

if ($content !== $orig_file) {
    file_put_contents($node_path, $content);
}

// 生成脚本
$template = file_get_contents('template.rsc');

echo "/interface/wireguard/peers" . str_repeat(PHP_EOL, 2);

foreach ($record as $key =>$item) {
    if ($item['identity_name'] === $identityName) {
        continue;
    }

    $id =  $key + 1;

    $output = $template;
    $output = str_replace('$ID$', $id, $output);
    $output = str_replace('#NAME#', $item['identity_name'], $output);
    $output = str_replace('$PK$', $item['wg_public_key'], $output);
    $output = str_replace('$EA$', $item['remote_ip'], $output);
    $output = str_replace('$EP$', $item['listen_port'], $output);

    echo $output;
}
