<?php
if (!class_exists('msPaymentInterface')) {
    require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class Paygine extends msPaymentHandler implements msPaymentInterface {
    var $resultUrl = '';

    function __construct(xPDOObject $object, $config = array()) {
        $this->modx = & $object->xpdo;

        //assets_url already include base_url
        $hostUrl = $this->modx->getOption('url_scheme') . $this->modx->getOption('http_host');
        $assetsUrl = $this->modx->getOption('minishop2.assets_url', $config, $this->modx->getOption('assets_url').'components/minishop2/');
        $resultScript = 'paygine.php';
        $resultUrl = $hostUrl . $assetsUrl . 'payment/' . $resultScript;

        $this->config = array_merge(array(
            'result_url' => $resultUrl,
            'result_script' => $resultScript,
            'json_response' => false
        ), $config);

        $this->config['submit_fields'] = array_map('trim', explode(',', $this->config['submit_fields']));

        $this->modx->lexicon->load('minishop2:paygine');
    }
    /* @inheritdoc} */
    public function send(msOrder $order) {
        $paygineLink = $this->getPaygineLink($order);
        // return $this->success('', array('redirect' =>  ''));
        return $this->success('', array('redirect' =>  $paygineLink));
    }

    public function getPaymentLink(msOrder $order) {
        return $this->getPaygineLink($order);
    }

    public function getPaygineLink(msOrder $order) {

        $testmode = $this->modx->getOption('setting_ms2_payment_paygine_test_mode');
        if ($testmode){
            $paygine_url ='https://test.paygine.com';
        } else {
            $paygine_url = 'https://pay.paygine.com';
        }
        $url = $paygine_url.'/webapi/Register';
        $id = $order->get('id');
        $sector=$this->modx->getOption('setting_ms2_payment_paygine_sector_id');
        $sum = $order->get('cost');
        $amount = round($sum * 100);
        $currencyName=$this->modx->getOption('setting_ms2_payment_paygine_currency', null, 'руб');
        if ($currencyName==='руб'){
            $currency=643;
        } else if ($currencyName==='доллар'){
            $currency=840;
        } else if ($currencyName==='евро'){
            $currency=978;
        } else {
            throw new Exception($this->getOption('setting_ms2_payment_paygine_wrong_currency'));
        }
        $password=$this->modx->getOption('setting_ms2_payment_paygine_password');
        $desc=$this->modx->getOption('setting_ms2_payment_paygine_desc', null, 'Оплата заказа').' '.$order->get('id');
        $email = $order->getOne('UserProfile')->get('email');
        $signature  = base64_encode(md5($sector . $amount . $currency . $password));
        $fiscalPositions = '';
        $fiscalAmount = 0;
        $TAX = 6;

        $arrfp = [];
        $products = $order->getMany('Products');
        foreach ($products as $product) {
            $arrfp[] = ['name' => $product->get('name'), 'count' => $product->get('count'), 'price' => $product->get('price')];
        }

        if ($arrfp) {
            foreach ($arrfp as $item) {
                $fiscalPositions.=$item['count'].';';
                $elementPrice = $item['price'];
                $elementPrice = $elementPrice * 100;
                $fiscalPositions.=$elementPrice.';';
                $fiscalPositions.=$TAX.';';
                $fiscalPositions.=$item['name'].'|';

                $fiscalAmount += $item['count'] * $elementPrice;
            }
            if ($order->shipping_method->price > 0) {
                $fiscalPositions.='1;';
                $fiscalPositions.=($order->shipping_method->price*100).';';
                $fiscalPositions.=$TAX.';';
                $fiscalPositions.=$this->modx->getOption('setting_ms2_payment_paygine_delivery', null, 'Доставка').'|';

                $fiscalAmount += $order->shipping_method->price*100;
            }
            $amountDiff = $amount - $fiscalAmount;
            if ($amountDiff > 0) {
                $fiscalPositions.='1;'.$amountDiff.';6;'.$this->modx->getOption('setting_ms2_payment_paygine_delivery', null, 'Доставка').'|';
            } else if ($amountDiff < 0){
                $fiscalPositions.='1;'.$amountDiff.';6;'.$this->modx->getOption('setting_ms2_payment_paygine_sale', null, 'Скидка').';14|';
            }
            $fiscalPositions = substr($fiscalPositions, 0, -1);
        }
        $data = array(
            'sector' => $sector,
            'reference' => $id,
            'fiscal_positions' => $fiscalPositions,
            'amount' => $amount,
            'description' => $desc,
            'email' => $email,
            'currency' => $currency,
            'mode' => 1,
            'signature' => $signature,
            'url' => $this->config['result_url'],
            'failurl' => $this->config['result_url'],

        );
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ),
        );

        $context  = stream_context_create($options);
        $paygine_id = file_get_contents($url, false, $context);
        if (intval($paygine_id) == 0) {
            //throw new Exception('error register order');
        }
        $signature = base64_encode(md5($sector . $paygine_id . $password));
        $link  = $paygine_url
            . '/webapi/Purchase'
            . '?sector=' .$sector
            . '&id=' . $paygine_id
            . '&signature=' . $signature;

        // die('<pre>' . print_r([$data, $link], true));
        return $link;
    }
    /* @inheritdoc} */
    public function receive(msOrder $order, $params = array()) {

        /* @var miniShop2 $miniShop2 */
        $miniShop2 = $this->modx->getService('miniShop2');

        $operaionId =  $params['operation'];
        $orderId = $params['id'];
        $sectorId = $this->modx->getOption('setting_ms2_payment_paygine_sector_id');
        $password = $this->modx->getOption('setting_ms2_payment_paygine_password');

        $signature = base64_encode(md5($sectorId . $orderId . $operaionId  . $password));

        $testmode = $this->modx->getOption('setting_ms2_payment_paygine_test_mode');
        if ($testmode){
            $paygine_url ='https://test.paygine.com';
        } else {
            $paygine_url = 'https://pay.paygine.com';
        }
        $url = $paygine_url . '/webapi/Operation';

        $context = stream_context_create(array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query(array(
                    'sector' => $sectorId,
                    'id' => $orderId,
                    'operation' => $operaionId,
                    'signature' => $signature
                )),
            )
        ));

        $xml = file_get_contents($url, false, $context);
        if (!$xml)
            throw new Exception($this->modx->getOption('setting_ms2_payment_paygine_empty_data'));
        $xml = simplexml_load_string($xml);
        if (!$xml)
            throw new Exception($this->modx->getOption('setting_ms2_payment_paygine_nonvalid_xml'));
        $response = json_decode(json_encode($xml), true);
        if (!$response)
            throw new Exception($this->modx->getOption('setting_ms2_payment_paygine_nonvalid_xml'));

        $tmp_response = (array)$response;
        unset($tmp_response["signature"]);
        $signature = base64_encode(md5(implode('', $tmp_response) . $password));
        if ($signature !== $response['signature'])
            throw new Exception($this->modx->getOption('setting_ms2_payment_paygine_invalid_signature'));

        if (($response['type'] == 'PURCHASE' || $response['type'] == 'PURCHASE_BY_QR' || $response['type'] == 'AUTHORIZE') && $response['state'] == 'APPROVED'){
            @$this->modx->context->key = 'mgr';
            $miniShop2->changeOrderStatus($order->get('id'), 2); // Setting status "paid"
            return true;
        } else {
            if ($this->modx->getOption('setting_ms2_payment_paygine_cancel_order', null, false)){
                $miniShop2->changeOrderStatus($order->get('id'), 4); // Setting status "cancelled"
            }
            return false;
        }
    }
    /**
     * Process error
     *
     * @param string $text Text to log
     * @param array $params Request parameters
     * @return bool
     */
    public function paymentError($text, $params = array()) {
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, '[miniShop2:Paygine] ' . $text . ' Request: ' . print_r($params, true));
        return $this->buildResponse('error', $this->config['result_script'], $text);
    }
}