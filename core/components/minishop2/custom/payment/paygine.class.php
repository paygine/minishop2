<?php
if (!class_exists('msPaymentInterface')) {
	require_once dirname(dirname(dirname(__FILE__))) . '/model/minishop2/mspaymenthandler.class.php';
}

class Paygine extends msPaymentHandler implements msPaymentInterface {
	function __construct(xPDOObject $object, $config = array()) {
		$this->modx = & $object->xpdo;
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

		return $this->success('', array('redirect' =>  $paygineLink));
	}

	private function getURL($path) {
		if ($this->modx->getOption('setting_ms2_payment_paygine_test_mode')) {
			return 'https://test.paygine.com' . $path;
		} else {
			return 'https://pay.paygine.com' . $path;
		}
	}

	public function getPaygineLink(msOrder $order) {
		$sector = $this->modx->getOption('setting_ms2_payment_paygine_sector_id');
		$id = $order->get('id');
		$sum = $order->get('cost');
		$amount = round($sum * 100);
		$registerUrl = $this->getURL('/webapi/Register');
		$password = $this->modx->getOption('setting_ms2_payment_paygine_password');
		$desc = $this->modx->getOption('setting_ms2_payment_paygine_desc', null, 'Оплата заказа').' '.$order->get('id');
		$email = $order->getOne('UserProfile')->get('email');
		$tax = $this->modx->getOption('setting_ms2_payment_paygine_tax');

		switch ($this->modx->getOption('setting_ms2_payment_paygine_currency', null, 'руб')) {
			case 'евро':
				$currency = '978';
				break;
			case 'доллар':
				$currency = '840';
				break;
			default: // RUB
				$currency = '643';
				break;
		}

		switch($this->modx->getOption('setting_ms2_payment_paygine_type')) {
			case '2':
				$paymentPath = '/webapi/Authorize';
				break;
			case '3':
				$paymentPath = '/webapi/custom/svkb/PurchaseWithInstallment';
				break;
			case '4':
				$paymentPath = '/webapi/custom/svkb/AuthorizeWithInstallment';
				break;
			case '5':
				$paymentPath = '/webapi/PurchaseSBP';
				break;
			default:
				$paymentPath = '/webapi/Purchase';
		}

		$signature = base64_encode(md5($sector . $amount . $currency . $password));
		$fiscalPositions = '';
		$fiscalAmount = 0;
		$shopCart = [];
		$scKey = 0;
		$arItems = [];
		$arProducts = $order->getMany('Products');

		foreach ($arProducts as $product) {
			$arItems[] = [
				'name' => $product->get('name'),
				'count' => $product->get('count'),
				'price' => $product->get('price')
			];
		}

		if ($arItems) {
			foreach ($arItems as $item) {
				$elementName = $item['name'];
				$elementQuantity = intval($item['count']);
				$elementPrice = intval(round($item['price'] * 100));

				if(strpos($elementName, ';') !== false) {
					$elementName = str_replace(';', '', $elementName);
				}

				$fiscalPositions .= $elementQuantity . ';';
				$fiscalPositions .= $elementPrice . ';';
				$fiscalPositions .= $tax . ';';
				$fiscalPositions .= $elementName . '|';
				$fiscalAmount += $elementQuantity * $elementPrice;

				$shopCart[$scKey]['name'] = $elementName;
				$shopCart[$scKey]['quantityGoods'] = $elementQuantity;
				$shopCart[$scKey]['goodCost'] = round($item['price'] * $shopCart[$scKey]['quantityGoods'], 2);

				$scKey++;
			}

			if ($order->get('delivery_cost') > 0) {
				$fiscalPositions .= '1;';
				$deliveryPrice = intval(round($order->get('delivery_cost') * 100));
				$fiscalPositions .= $deliveryPrice . ';';
				$fiscalPositions .= $tax . ';';
				$fiscalPositions .= 'Доставка' . '|';
				$fiscalAmount += $deliveryPrice;

				$shopCart[$scKey]['quantityGoods'] = 1;
				$shopCart[$scKey]['goodCost'] = round($order->get('delivery_cost'), 2);
				$shopCart[$scKey]['name'] = 'Доставка';
			}

			$amountDiff = abs($amount - $fiscalAmount);

			if ($amountDiff) {
				$fiscalPositions .= '1;' . $amountDiff . ';6;Скидка;14|';
				$shop_cart = [];
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
			'failurl' => $this->config['result_url']
		);

		if($shopCart && ($this->modx->getOption('setting_ms2_payment_paygine_type') == 3 || $this->modx->getOption('setting_ms2_payment_paygine_type') == 4)) {
			$data = array_merge($data, ['shop_cart' => base64_encode(json_encode($shopCart, JSON_UNESCAPED_UNICODE))]);
		}

		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
			)
		);

		$context  = stream_context_create($options);
		$paygineId = file_get_contents($registerUrl, false, $context);
		$signature = base64_encode(md5($sector . $paygineId . $password));

		$link = $this->getURL($paymentPath . '?sector=' .$sector . '&id=' . $paygineId . '&signature=' . $signature);

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
		$url = $this->getURL('/webapi/Operation');
		$signature = base64_encode(md5($sectorId . $orderId . $operaionId  . $password));

		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query(array(
					'sector' => $sectorId,
					'id' => $orderId,
					'operation' => $operaionId,
					'signature' => $signature
				))
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
			$miniShop2->changeOrderStatus($order->get('id'), 2); // Setting status "Paid"

			return true;
		} else {
			if ($this->modx->getOption('setting_ms2_payment_paygine_cancel_order', null, false)){
				$miniShop2->changeOrderStatus($order->get('id'), 4); // Setting status "Cancelled"
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