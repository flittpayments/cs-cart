<?php

use Tygh\Payments\Processors\Flitt;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode == 'details') {

    $order_id = empty($_REQUEST['order_id']) ? 0 : (int) $_REQUEST['order_id'];

    $order_info = fn_get_order_info($order_id, false, true, true, false);
    $processor_data = $order_info ? fn_flitt_get_order_processor_data($order_info) : false;

    if (!$processor_data) {
        return array(CONTROLLER_STATUS_OK);
    }

    $response = array();

    if (!empty($_REQUEST['send']) && $order_info['status'] == 'O') {

        // A new payment attempt every time: Flitt payment pages expire, and Flitt rejects a repeated order ID.
        $payment_data = fn_flitt_build_checkout_request($order_info, $processor_data);

        $flitt = new Flitt();
        $response = $flitt->generateFlittUrl($payment_data);

        if ($response['result'] == true) {
            fn_flitt_register_attempt($order_info['order_id'], $payment_data, array('payment_link' => $response['url']));
            $url = $response['url'];
        } else {
            fn_update_order_payment_info($order_info['order_id'], array('error' => $response['message']));
            fn_set_notification('E', __('error'), __('flitt.payment_creation_failed', array('[message]' => $response['message'])));
            $url = false;
        }

        if ($url) {
            /** @var Tygh\Mailer\Mailer $mailer */
            $mailer = Tygh::$app['mailer'];

            $mailer->send(array(
                'to' => $order_info['email'],
                'from' => 'default_company_orders_department',
                'data' => array(
                    'payment_link' => $url,
                    'email_subj' => __('invoice') . ' #' . $order_info['order_id'],
                ),
                'tpl' => 'addons/flitt/send_payment_link.tpl',
                'is_html' => true,
                'company_id' => $order_info['company_id'],
            ), 'A');
        }

        return array(CONTROLLER_STATUS_REDIRECT, 'orders.details?order_id=' . $order_id);
    }

    if (!isset($order_info['payment_info']['payment_id'])) {
        Tygh::$app['view']->assign('sendLink', fn_url('orders.details?send=1&order_id=' . $order_id));
        Tygh::$app['view']->assign('error', $response);
    }
}
