<?php
/**
 * Epay回调定时任务
 * 每分钟执行：* * * * * php /www/wwwroot/vmq.okekrr.com/epay_callback_cron.php
 */

$logFile = __DIR__ . '/runtime/epay_cron.log';

// 从.env读取数据库配置
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
    $host = $env['DATABASE_HOSTNAME'] ?? 'localhost';
    $db   = $env['DATABASE_DATABASE'] ?? 'vmq';
    $user = $env['DATABASE_USERNAME'] ?? 'vmq';
    $pass = $env['DATABASE_PASSWORD'] ?? '';
} else {
    $host = 'localhost';
    $db   = 'vmq';
    $user = 'root';
    $pass = '';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " | DB FAIL: " . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}

// 添加callback_sent列（如果不存在）
try {
    $pdo->exec("ALTER TABLE pay_order ADD COLUMN callback_sent TINYINT DEFAULT 0");
} catch (Exception $e) {
    // 列已存在
}

// 获取key
$stmt = $pdo->prepare("SELECT vvalue FROM setting WHERE vkey = 'key' LIMIT 1");
$stmt->execute();
$key = $stmt->fetchColumn();
if (!$key) {
    exit(1);
}

// 查找已付款但未发送回调的epay订单
$stmt = $pdo->query("
    SELECT * FROM pay_order
    WHERE state = 1
    AND callback_sent = 0
    AND param LIKE '%epay%'
    AND notify_url != ''
    AND pay_date > 0
    ORDER BY id ASC
    LIMIT 20
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    exit(0);
}

file_put_contents($logFile, date('Y-m-d H:i:s') . " | found " . count($orders) . " pending callback(s)\n", FILE_APPEND);

foreach ($orders as $order) {
    $meta = @json_decode($order['param'], true);
    if (!is_array($meta) || empty($meta['epay'])) {
        $pdo->prepare("UPDATE pay_order SET callback_sent = 1 WHERE id = ?")->execute([$order['id']]);
        continue;
    }

    $type = $order['type'] == 1 ? 'wxpay' : 'alipay';
    $params = [
        'pid' => $meta['pid'],
        'type' => $type,
        'out_trade_no' => $order['pay_id'],
        'trade_no' => $order['order_id'],
        'name' => $meta['name'] ?? '',
        'money' => number_format(floatval($order['really_price']), 2, '.', ''),
        'trade_status' => 'TRADE_SUCCESS',
    ];

    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $sign = md5(implode('&', $pairs) . $key);
    $params['sign'] = $sign;
    $params['sign_type'] = 'MD5';

    $postData = http_build_query($params);
    $url = $order['notify_url'];

    $logMsg = date('Y-m-d H:i:s') . " | orderId={$order['order_id']} payId={$order['pay_id']}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $logMsg .= " | http=$httpCode response=" . trim($response);

    if (trim($response) === 'success') {
        $pdo->prepare("UPDATE pay_order SET callback_sent = 1 WHERE id = ?")->execute([$order['id']]);
        $logMsg .= " | SENT OK";
    } elseif (strpos($response, 'order status invalid') !== false) {
        $pdo->prepare("UPDATE pay_order SET callback_sent = 1 WHERE id = ?")->execute([$order['id']]);
        $logMsg .= " | ALREADY DONE";
    } else {
        $logMsg .= " | WILL RETRY";
    }

    file_put_contents($logFile, $logMsg . "\n", FILE_APPEND);
}
