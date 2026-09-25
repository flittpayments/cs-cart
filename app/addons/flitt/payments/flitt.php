<?php

use Tygh\Payments\Processors\Flitt;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

$flitt = new Flitt();

if (defined('PAYMENT_NOTIFICATION')) {

    /**
     * Sends a plain-text answer to the Flitt server callback and stops execution.
     * Flitt treats only HTTP 200 as a successfully delivered callback and does not follow redirects.
     */
    $fn_flitt_callback_answer = function ($code, $message) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    };

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !$body) {
        $body = $_POST;
    }

    $order_id = empty($_REQUEST['order_id']) ? 0 : (int) $_REQUEST['order_id'];
    $order_info = $order_id ? fn_get_order_info($order_id) : array();
    $processor_data = $order_info ? fn_flitt_get_order_processor_data($order_info) : false;
    $is_callback = ($mode == 'ok');

    if (!$processor_data) {
        if ($is_callback) {
            $fn_flitt_callback_answer(404, 'Order not found');
        }
        fn_set_notification('E', __('error'), __('flitt.order_not_found'));
        fn_redirect('checkout.cart');
    }

    $params = $processor_data['processor_params'];
    $is_hold = !empty($params['transaction_method']) && $params['transaction_method'] == 'hold';
    $success_status = $is_hold ? $params['status_hold'] : $params['paid_order_status'];

    // Every payment attempt has its own Flitt order ID; the response must belong to one of this order's attempts.
    $attempts = fn_flitt_get_attempts($order_info);
    $body_order_id = isset($body['order_id']) ? (string) $body['order_id'] : '';
    $attempt = isset($attempts[$body_order_id]) ? $attempts[$body_order_id] : null;

    $expected = array(
        'merchant' => $params['merchant_id'],
        'secretkey' => $params['password'],
        'order_id' => $attempt ? $body_order_id : fn_flitt_get_flitt_order_id($order_info),
    );
    if (!empty($attempt['amount'])) {
        $expected['amount'] = $attempt['amount'];
    }
    if (!empty($attempt['currency'])) {
        $expected['currency'] = $attempt['currency'];
    }

    $validation = $flitt->isPaymentValid($expected, $body);
    $current_statuses = fn_flitt_get_order_statuses($order_id);
    $flitt_status = isset($body['order_status']) ? (string) $body['order_status'] : '';

    // Target order status for the Flitt order status; null means "do not change".
    $status_to = null;
    if ($validation === true) {
        if ($flitt_status == 'approved') {
            $status_to = $success_status;
            // Funds already captured (hold -> paid) must not be moved back to the hold status.
            if ($is_hold && in_array($params['paid_order_status'], $current_statuses, true)) {
                $status_to = $params['paid_order_status'];
            }
        } elseif (in_array($flitt_status, array('declined', 'expired'), true)
            && !array_intersect($current_statuses, array($params['paid_order_status'], $params['status_hold']))
        ) {
            $status_to = 'F';
        }
    }

    if ($validation === true) {
        $payment_info = array(
            'order_status_flitt' => $flitt_status,
        );
        if ($flitt_status == 'approved') {
            // The approved attempt is the one to capture (Hold) and to show in the order details.
            $payment_info['order_id'] = $body_order_id;
            if (!empty($attempt['amount'])) {
                $payment_info['flitt_amount'] = $attempt['amount'];
                $payment_info['flitt_currency'] = $attempt['currency'];
            }
        }
        if (!empty($body['payment_id'])) {
            $payment_info['payment_id'] = $body['payment_id'];
        }
        if (!empty($body['response_description'])) {
            $payment_info['reason_text'] = $body['response_description'];
        }

        if ($status_to !== null) {
            $pp_response = array_merge($payment_info, array('order_status' => $status_to));

            // Handles the first transition (while the order still awaits the payment result).
            fn_finish_payment($order_id, $pp_response);

            // The order may have already been finished by the browser return or a previous callback.
            if (array_diff(fn_flitt_get_order_statuses($order_id), array($status_to))) {
                fn_update_order_payment_info($order_id, $payment_info);
                // $force_notification must be an array: hook handlers type-hint it (e.g. the Warehouses add-on),
                // so passing false causes a TypeError and an HTTP 500 response.
                fn_change_order_status($order_id, $status_to, '', array());
            }
        } else {
            fn_update_order_payment_info($order_id, $payment_info);
        }
    }

    if ($is_callback) {
        if ($validation !== true) {
            $fn_flitt_callback_answer(400, $validation);
        }
        $fn_flitt_callback_answer(200, 'OK');
    }

    // Browser return (mode=response)
    if ($validation !== true || $status_to === null) {
        // The final status will be set by the server callback.
        fn_set_notification('N', __('notice'), __('flitt.payment_processing'));
        // Hides CS-Cart's "Transaction was canceled by the customer" notice (see fn_flitt_set_notification_pre).
        Tygh\Registry::set('runtime.flitt_payment_pending', true);
    }

    fn_order_placement_routines('route', $order_id);
    exit;

} else {

    if (empty($processor_data) && !empty($order_info)) {
        $processor_data = fn_get_processor_data($order_info['payment_id']);
    }

    $payment_data = fn_flitt_build_checkout_request($order_info, $processor_data);

    $response = $flitt->generateFlittUrl($payment_data);
    fn_flitt_register_attempt($order_info['order_id'], $payment_data, array(
        'response_status' => $response['response_status'],
    ));

    if ($response['result'] == true) {
        fn_create_payment_form($response['url'], array(), 'Flitt', true, 'GET');
    } else {
        fn_update_order_payment_info($order_info['order_id'], array(
            'request_id' => $response['request_id'],
            'message' => $response['message'],
        ));
        fn_set_notification('E', __('error'), __('flitt.payment_creation_failed', array('[message]' => $response['message'])));
        fn_redirect('checkout.checkout');
    }
}
