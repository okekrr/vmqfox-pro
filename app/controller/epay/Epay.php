<?php
namespace app\controller\epay;

use think\facade\Db;
use think\facade\Request;

class Epay
{
    private function log($msg)
    {
        file_put_contents('/www/wwwroot/vmq.okekrr.com/runtime/epay_debug.log', date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND);
    }

    private function getKey()
    {
        return Db::name("setting")->where("vkey", "key")->value('vvalue');
    }

    private function buildSignContent($params)
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if (in_array($k, ['sign', 'sign_type'])) continue;
            if ($v === '' || $v === null) continue;
            $filtered[$k] = $v;
        }
        ksort($filtered);
        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }

    private function verifySign($params, $key)
    {
        $sign = $params['sign'] ?? '';
        if (!$sign) return false;
        $signContent = $this->buildSignContent($params);
        $_sign = md5($signContent . $key);
        return $sign === $_sign;
    }

    private function epayTypeToVmq($type)
    {
        switch ($type) {
            case 'wxpay': return 1;
            case 'alipay': return 2;
            default: return null;
        }
    }

    public function mapi()
    {
        $params = Request::param();
        $key = $this->getKey();

        if (!$this->verifySign($params, $key)) {
            return json(['code' => -1, 'msg' => '签名错误']);
        }

        $jkstate = Db::name("setting")->where("vkey", "jkstate")->value('vvalue');
        if ($jkstate != "1") {
            return json(['code' => -1, 'msg' => '监控端状态异常']);
        }

        $vmqType = $this->epayTypeToVmq($params['type'] ?? '');
        if (!$vmqType) {
            return json(['code' => -1, 'msg' => '支付方式错误']);
        }

        $result = $this->createEpayOrder($params, $vmqType, $key);
        if (!$result) {
            return json(['code' => -1, 'msg' => '订单创建失败']);
        }

        $payUrl = Request::domain() . '/payPage/pay.html?orderId=' . $result['orderId'];

        return json([
            'code' => 1,
            'msg' => '成功',
            'trade_no' => $result['orderId'],
            'payurl' => $payUrl,
            'qrcode' => '',
            'urlscheme' => ''
        ]);
    }

    public function submit()
    {
        $params = Request::param();
        $key = $this->getKey();
        $this->log("SUBMIT called | money=" . ($params['money'] ?? '') . " | type=" . ($params['type'] ?? '') . " | out_trade_no=" . ($params['out_trade_no'] ?? ''));

        if (!$this->verifySign($params, $key)) {
            $this->log("FAIL: sign verify failed");
            return '签名错误';
        }

        $vmqType = $this->epayTypeToVmq($params['type'] ?? '');
        if (!$vmqType) {
            $this->log("FAIL: unsupported type");
            return '支付方式错误';
        }

        try {
            $result = $this->createEpayOrder($params, $vmqType, $key);
        } catch (\Exception $e) {
            $this->log("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return '订单创建异常: ' . $e->getMessage();
        }

        if (!$result) {
            $this->log("FAIL: createEpayOrder returned null");
            return '订单创建失败';
        }

        $this->log("SUCCESS: orderId=" . $result['orderId'] . " reallyPrice=" . $result['reallyPrice']);
        return redirect('/payPage/pay.html?orderId=' . $result['orderId']);
    }

    private function createEpayOrder($params, $vmqType, $key)
    {
        $this->log("createEpayOrder START");

        try {
            $indexController = new \app\controller\index\Index();
            $indexController->closeEndOrder();
        } catch (\Exception $e) {
            $this->log("WARN closeEndOrder: " . $e->getMessage());
        }

        $price = floatval($params['money'] ?? 0);
        if ($price <= 0) {
            $this->log("FAIL: price=$price");
            return null;
        }

        $outTradeNo = $params['out_trade_no'] ?? '';
        if (!$outTradeNo) {
            $this->log("FAIL: outTradeNo empty");
            return null;
        }

        $pid = $params['pid'] ?? '';
        $name = $params['name'] ?? '';
        $notifyUrl = $params['notify_url'] ?? '';
        $returnUrl = $params['return_url'] ?? '';

        $reallyPrice = bcmul("$price", "100");
        $this->log("bcmul: price=$price => reallyPrice=$reallyPrice");

        $payQf = Db::name("setting")->where("vkey", "payQf")->value('vvalue');
        $orderId = date("YmdHms") . rand(1, 9) . rand(1, 9) . rand(1, 9) . rand(1, 9);

        $ok = false;
        for ($i = 0; $i < 10; $i++) {
            $tmpPrice = $reallyPrice . "-" . $vmqType;
            try {
                $row = Db::execute("INSERT IGNORE INTO tmp_price (price,oid) VALUES ('" . $tmpPrice . "','" . $orderId . "')");
                $this->log("tmp_price i=$i tmpPrice=$tmpPrice row=$row");
                if ($row) {
                    $ok = true;
                    break;
                }
            } catch (\Exception $e) {
                $this->log("tmp_price i=$i EXCEPTION: " . $e->getMessage());
            }
            if ($payQf == 1) $reallyPrice++;
            else if ($payQf == 2) $reallyPrice--;
        }

        if (!$ok) {
            $this->log("FAIL: tmp_price all 10 attempts failed");
            return null;
        }

        $reallyPrice = bcdiv($reallyPrice, "100", 2);
        $this->log("bcdiv: reallyPrice=$reallyPrice");

        $payUrlKey = ($vmqType == 1) ? 'wxpay' : 'zfbpay';
        $payUrl = Db::name("setting")->where("vkey", $payUrlKey)->value('vvalue');
        if ($payUrl == "") {
            $this->log("FAIL: payUrl empty key=$payUrlKey");
            return null;
        }

        $existing = Db::name("pay_order")->where("pay_id", $outTradeNo)->find();
        if ($existing) {
            $this->log("FAIL: duplicate pay_id=$outTradeNo");
            return null;
        }

        $isAuto = 1;
        $matchedQr = Db::name("pay_qrcode")
            ->where("price", $reallyPrice)
            ->where("type", $vmqType)
            ->find();
        if ($matchedQr) {
            $payUrl = $matchedQr['pay_url'];
            $isAuto = 0;
        }

        $epayMeta = json_encode([
            'epay' => true,
            'pid' => $pid,
            'name' => $name,
        ], JSON_UNESCAPED_UNICODE);

        Db::name("pay_order")->insert([
            "order_id" => $orderId,
            "pay_id" => $outTradeNo,
            "type" => $vmqType,
            "price" => $price,
            "really_price" => $reallyPrice,
            "param" => $epayMeta,
            "notify_url" => $notifyUrl,
            "return_url" => $returnUrl,
            "pay_url" => $payUrl,
            "is_auto" => $isAuto,
            "state" => 0,
            "create_date" => time(),
            "close_date" => 0,
            "pay_date" => 0
        ]);

        $this->log("ORDER CREATED: orderId=$orderId reallyPrice=$reallyPrice notifyUrl=$notifyUrl");
        return ['orderId' => $orderId, 'reallyPrice' => $reallyPrice];
    }

    public static function buildEpayCallback($order, $key)
    {
        $meta = @json_decode($order['param'], true);
        if (!is_array($meta) || empty($meta['epay'])) return null;

        $type = $order['type'] == 1 ? 'wxpay' : 'alipay';
        $params = [
            'pid' => $meta['pid'],
            'type' => $type,
            'out_trade_no' => $order['pay_id'],
            'trade_no' => $order['order_id'],
            'name' => $meta['name'] ?? '',
            'money' => number_format($order['really_price'], 2, '.', ''),
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

        return $params;
    }
}
