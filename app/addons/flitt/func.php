<?php

use Tygh\Payments\Processors\Flitt;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

function fn_flitt_install()
{
    fn_flitt_uninstall();
    $_data = array(
        'processor' => 'Flitt Payment Provider',
        'processor_script' => Flitt::PROCESSOR_SCRIPT,
        'processor_template' => 'views/orders/components/payments/cc_outside.tpl',
        'admin_template' => 'flitt.tpl',
        'callback' => 'N',
        'type' => 'P',
        'addon' => 'flitt'
    );
    db_query("INSERT INTO ?:payment_processors ?e", $_data);
}

function fn_flitt_uninstall()
{
    db_query("DELETE FROM ?:payment_processors WHERE processor_script = ?s", Flitt::PROCESSOR_SCRIPT);
}

/**
 * Returns processor data if the order is paid with Flitt, false otherwise.
 *
 * @param array $order_info
 *
 * @return array|false
 */
function fn_flitt_get_order_processor_data($order_info)
{
    if (empty($order_info['payment_id'])) {
        return false;
    }

    $processor_data = fn_get_processor_data($order_info['payment_id']);

    if (empty($processor_data['processor_script']) || $processor_data['processor_script'] !== Flitt::PROCESSOR_SCRIPT) {
        return false;
    }

    return $processor_data;
}

/**
 * Returns the Flitt order ID of the latest (or the approved) payment attempt.
 *
 * @param array $order_info
 *
 * @return string
 */
function fn_flitt_get_flitt_order_id($order_info)
{
    if (!empty($order_info['payment_info']['order_id'])) {
        return $order_info['payment_info']['order_id'];
    }
    if (!empty($order_info['payment_info']['flitt_order_id'])) {
        return $order_info['payment_info']['flitt_order_id'];
    }

    return $order_info['timestamp'] . '_' . $order_info['order_id'];
}

/**
 * Generates a new Flitt order ID for a payment attempt.
 * Flitt rejects a repeated order ID, so every attempt (checkout, repay, payment link) needs its own one.
 *
 * @param array $order_info
 *
 * @return string
 */
function fn_flitt_generate_flitt_order_id($order_info)
{
    $attempts = fn_flitt_get_attempts($order_info);

    $flitt_order_id = $order_info['order_id'] . '_' . TIME;
    for ($i = 2; isset($attempts[$flitt_order_id]); $i++) {
        $flitt_order_id = $order_info['order_id'] . '_' . TIME . '_' . $i;
    }

    return $flitt_order_id;
}

/**
 * Returns the order's payment attempts: [flitt_order_id => ['amount' => int, 'currency' => string]].
 * Orders created by module 1.0.x have a single attempt without the stored amount.
 *
 * @param array $order_info
 *
 * @return array
 */
function fn_flitt_get_attempts($order_info)
{
    $payment_info = isset($order_info['payment_info']) ? (array) $order_info['payment_info'] : array();

    $attempts = !empty($payment_info['flitt_attempts']) && is_array($payment_info['flitt_attempts'])
        ? $payment_info['flitt_attempts']
        : array();

    if (!$attempts) {
        $attempts[fn_flitt_get_flitt_order_id($order_info)] = array(
            'amount' => isset($payment_info['flitt_amount']) ? (int) $payment_info['flitt_amount'] : 0,
            'currency' => isset($payment_info['flitt_currency']) ? $payment_info['flitt_currency'] : '',
        );
    }

    return $attempts;
}

/**
 * Stores a new payment attempt in the order's payment info and makes it the current one.
 *
 * @param int   $order_id     Order ID
 * @param array $payment_data Signed Flitt request (order_id, amount, currency)
 * @param array $extra        Additional payment info to store
 */
function fn_flitt_register_attempt($order_id, $payment_data, $extra = array())
{
    $order_info = fn_get_order_info($order_id);
    $attempts = !empty($order_info['payment_info']['flitt_attempts']) ? (array) $order_info['payment_info']['flitt_attempts'] : array();
    $attempts[$payment_data['order_id']] = array(
        'amount' => (int) $payment_data['amount'],
        'currency' => $payment_data['currency'],
    );

    fn_update_order_payment_info($order_id, array_merge(array(
        'order_id' => $payment_data['order_id'],
        'flitt_amount' => (int) $payment_data['amount'],
        'flitt_currency' => $payment_data['currency'],
        'flitt_attempts' => $attempts,
    ), $extra));
}

/**
 * Hook handler: while a customer returns from Flitt before the payment is confirmed, replaces CS-Cart's
 * "Transaction was canceled by the customer" notice with the "payment is being processed" one.
 */
function fn_flitt_set_notification_pre(&$type, &$title, &$message, &$message_state, &$extra, &$init_message)
{
    if ($extra !== 'transaction_cancelled' || !Tygh\Registry::get('runtime.flitt_payment_pending')) {
        return;
    }

    // Same parameters as the notice set by the payment script, so both end up as a single notice.
    $type = 'N';
    $title = __('notice');
    $message = __('flitt.payment_processing');
    $message_state = '';
    $extra = '';
    $init_message = false;
}

/**
 * Returns current statuses of the order: statuses of its child orders for a parent order
 * (Multi-Vendor order split between vendors), or its own status otherwise.
 *
 * @param int $order_id
 *
 * @return string[]
 */
function fn_flitt_get_order_statuses($order_id)
{
    $statuses = db_get_fields('SELECT status FROM ?:orders WHERE parent_order_id = ?i', $order_id);
    if (!$statuses) {
        $statuses = db_get_fields('SELECT status FROM ?:orders WHERE order_id = ?i', $order_id);
    }

    return array_values(array_unique($statuses));
}

/**
 * Calculates the amount (in minor units) and currency to charge for the order.
 *
 * @param array $order_info
 * @param array $processor_params
 *
 * @return array [amount, currency]
 */
function fn_flitt_get_order_amount($order_info, $processor_params)
{
    $currency = CART_SECONDARY_CURRENCY;
    if (empty($processor_params['currency']) || $processor_params['currency'] == 'shop_cur') {
        $amount = fn_format_price_by_currency($order_info['total']);
    } else {
        $amount = fn_format_price($order_info['total'], $processor_params['currency']);
        $currency = $processor_params['currency'];
    }

    return array((int) round($amount * 100), $currency);
}

/**
 * Builds the signed request for creating a Flitt checkout URL.
 *
 * @param array $order_info
 * @param array $processor_data
 *
 * @return array
 */
function fn_flitt_build_checkout_request($order_info, $processor_data)
{
    $params = $processor_data['processor_params'];
    list($amount, $currency) = fn_flitt_get_order_amount($order_info, $params);

    $payment_data = array(
        'order_id' => fn_flitt_generate_flitt_order_id($order_info),
        'merchant_id' => $params['merchant_id'],
        'order_desc' => '#' . $order_info['order_id'],
        'amount' => $amount,
        'currency' => $currency,
        'response_url' => fn_url('payment_notification.response?payment=flitt&order_id=' . $order_info['order_id'], 'C', 'current'),
        'server_callback_url' => fn_url('payment_notification.ok?payment=flitt&order_id=' . $order_info['order_id'], 'C', 'current'),
        'lang' => isset($params['language']) ? $params['language'] : '',
        'sender_email' => $order_info['email'],
    );

    if (isset($params['transaction_method']) && $params['transaction_method'] == 'hold') {
        $payment_data['preauth'] = 'Y';
    }

    $payment_data['signature'] = (new Flitt())->getSignature($payment_data, $params['password']);

    return $payment_data;
}

/**
 * Hook handler: captures held funds when the order is moved from the "hold" status to the "paid" status.
 */
function fn_flitt_change_order_status(&$status_to, $status_from, &$order_info, $force_notification, $order_statuses, $place_order)
{
    $processor_data = fn_flitt_get_order_processor_data($order_info);
    if (!$processor_data) {
        return;
    }

    $params = $processor_data['processor_params'];

    if (empty($params['transaction_method']) || $params['transaction_method'] != 'hold'
        || empty($params['status_hold']) || $params['status_hold'] != $status_from
        || empty($params['paid_order_status']) || $status_to != $params['paid_order_status']
    ) {
        return;
    }

    if (!empty($order_info['payment_info']['flitt_amount']) && !empty($order_info['payment_info']['flitt_currency'])) {
        $amount = (int) $order_info['payment_info']['flitt_amount'];
        $currency = $order_info['payment_info']['flitt_currency'];
    } else {
        list($amount, $currency) = fn_flitt_get_order_amount($order_info, $params);
    }

    $flitt = new Flitt();
    $payment_data = array(
        'order_id' => fn_flitt_get_flitt_order_id($order_info),
        'currency' => $currency,
        'amount' => $amount,
        'merchant_id' => $params['merchant_id'],
    );
    $payment_data['signature'] = $flitt->getSignature($payment_data, $params['password']);

    $response = $flitt->generateFlittUrl($payment_data, true);

    if ($response['result']) {
        $capture_info = array(
            'capture_status' => $response['capture_status'],
            'response_status' => $response['response_status'],
        );
    } else {
        $status_to = 'F';
        $capture_info = array(
            'order_id' => $payment_data['order_id'],
            'request_id' => $response['request_id'],
            'response_status' => $response['response_status'],
            'message' => $response['message'],
        );
        fn_set_notification('E', __('error'), __('flitt.capture_failed', array('[message]' => $response['message'])));
    }

    fn_update_order_payment_info($order_info['order_id'], $capture_info);
    // fn_change_order_status() re-saves $order_info['payment_info'] for statuses that remove card data,
    // so keep it in sync to not lose the capture result.
    $order_info['payment_info'] = array_merge(
        isset($order_info['payment_info']) ? (array) $order_info['payment_info'] : array(),
        $capture_info
    );
}
