<?php

namespace Tygh\Payments\Processors;

class Flitt
{
    const CHECKOUT_URL = 'https://pay.flitt.com/api/checkout/url/';
    const CAPTURE_URL = 'https://pay.flitt.com/api/capture/order_id';

    const PROCESSOR_SCRIPT = 'flitt.php';

    public function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $password;
        foreach ($data as $k => $v) {
            $str .= '|' . $v;
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    /**
     * Sends a request to the Flitt API (checkout URL creation or capture).
     *
     * @param array $payment_data Request parameters (signature included)
     * @param bool  $capture      Whether to call the capture endpoint
     *
     * @return array
     */
    public function generateFlittUrl($payment_data, $capture = false)
    {
        $url = $capture ? self::CAPTURE_URL : self::CHECKOUT_URL;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('request' => $payment_data)));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curl_error !== '') {
            return $this->errorResponse('Flitt API connection error: ' . $curl_error);
        }

        $result = json_decode($raw);
        if ($http_code !== 200 || !isset($result->response)) {
            return $this->errorResponse('Flitt API returned an unexpected response (HTTP ' . $http_code . ')');
        }

        $data = $result->response;
        $response_status = isset($data->response_status) ? $data->response_status : '';

        if ($response_status !== 'success') {
            return $this->errorResponse(
                isset($data->error_message) ? $data->error_message : 'Unknown Flitt API error',
                isset($data->request_id) ? $data->request_id : '',
                $response_status ?: 'failure'
            );
        }

        return array(
            'result' => true,
            'url' => isset($data->checkout_url) ? $data->checkout_url : '',
            'response_status' => $response_status,
            'capture_status' => isset($data->capture_status) ? $data->capture_status : '',
        );
    }

    /**
     * Validates a Flitt response (server callback or browser return).
     *
     * @param array $settings Keys: merchant, secretkey and optionally order_id, amount, currency
     *                        that the response must match
     * @param array $response Response parameters
     *
     * @return true|string True when valid, error code otherwise
     */
    public function isPaymentValid($settings, $response)
    {
        if (!is_array($response) || empty($response['signature'])) {
            return 'Flitt_error_signature';
        }

        if (!isset($response['merchant_id']) || (string) $settings['merchant'] !== (string) $response['merchant_id']) {
            return 'Flitt_error_merchant';
        }

        $responseSignature = (string) $response['signature'];
        unset($response['response_signature_string'], $response['signature']);

        $signature = $this->getSignature($response, $settings['secretkey']);

        if (!hash_equals($signature, strtolower($responseSignature))) {
            return 'Flitt_error_signature';
        }

        if (!empty($settings['order_id'])
            && (!isset($response['order_id']) || (string) $settings['order_id'] !== (string) $response['order_id'])
        ) {
            return 'Flitt_error_order_id';
        }

        if (!empty($settings['amount'])
            && (!isset($response['amount']) || (int) $settings['amount'] !== (int) $response['amount'])
        ) {
            return 'Flitt_error_amount';
        }

        if (!empty($settings['currency'])
            && (!isset($response['currency']) || (string) $settings['currency'] !== (string) $response['currency'])
        ) {
            return 'Flitt_error_currency';
        }

        return true;
    }

    private function errorResponse($message, $request_id = '', $response_status = 'failure')
    {
        return array(
            'result' => false,
            'message' => $message,
            'response_status' => $response_status,
            'request_id' => $request_id,
        );
    }
}
