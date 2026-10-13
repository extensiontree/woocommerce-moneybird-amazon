<?php
/*
Plugin Name: Moneybird API integration [extra Amazon settings]
Plugin URI: https://extensiontree.com/woocommerce-moneybird-koppeling/
Version: 1.0.0
Author: ExtensionTree.com
Author URI: https://extensiontree.com
Description: Adds extra options to the Moneybird plugin for Amazon orders. Works with the Amazon integrations from Channable.
Requires at least: 3.8
Tested up to: 7.0
WC requires at least: 3.0
WC tested up to: 11.0
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://github.com/extensiontree/woocommerce-moneybird-amazon/',
    __FILE__,
    'woocommerce-moneybird-amazon'
);

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});


if (is_plugin_active('woocommerce-moneybird/woocommerce-moneybird.php')) {
    // Register a separate integration, so that the Amazon settings get their own settings page
    // next to the settings page of the Moneybird plugin itself.
    function insert_woocommerce_moneybird_amazon_integration($integrations)
    {
        if (class_exists('WC_Integration')) {
            $integrations[] = 'WC_MoneyBird_Amazon';
        }

        return $integrations;
    }

    add_filter('woocommerce_integrations', 'insert_woocommerce_moneybird_amazon_integration', 20);


    function woocommerce_moneybird_amazon_init()
    {
        require_once('class-wc-moneybird-amazon.php');
    }

    add_action('plugins_loaded', 'woocommerce_moneybird_amazon_init', 20);


    function woocommerce_moneybird_amazon_action_links($links)
    {
        $settings_url = admin_url('admin.php?page=wc-settings&tab=integration&section=moneybird_amazon');
        array_unshift($links, '<a href="' . $settings_url . '">' . __('Settings', 'woocommerce') . '</a>');

        return $links;
    }

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woocommerce_moneybird_amazon_action_links');
}


function wc_mb_amazon_get_settings()
{
    // Return the settings of the Amazon integration (empty array if unavailable)
    $woocommerce = WC();
    if ($woocommerce && isset($woocommerce->integrations->integrations['moneybird_amazon'])) {
        return $woocommerce->integrations->integrations['moneybird_amazon']->settings;
    }

    $settings = get_option('woocommerce_moneybird_amazon_settings');

    return is_array($settings) ? $settings : array();
}


function wc_mb_is_amazon_order($order)
{
    // Note: the payment method title is internal order data, so it should be read through the
    // getter instead of get_meta() (which triggers a doing_it_wrong notice and returns nothing
    // for orders that are stored in the HPOS tables). Refunds don't have the getter at all,
    // for those the parent order is checked below.
    $payment_method_titles = ['amazon'];

    if (is_callable(array($order, 'get_payment_method_title'))) {
        foreach ($payment_method_titles as $payment_method_title) {
            if (stripos($order->get_payment_method_title(), $payment_method_title) !== false) {
                return true;
            }
        }
    }

    // Check parent in case of refund
    $parent_order_id = $order->get_parent_id();
    if ($parent_order_id && ($parent_order = wc_get_order($parent_order_id))) {
        if (is_callable(array($parent_order, 'get_payment_method_title'))) {
            foreach ($payment_method_titles as $payment_method_title) {
                if (stripos($parent_order->get_payment_method_title(), $payment_method_title) !== false) {
                    return true;
                }
            }
        }
    }

    return false;
}


function wc_mb_amazon_modify_invoice($invoice, $order)
{
    if (!wc_mb_is_amazon_order($order)) {
        return $invoice;
    }

    $settings = wc_mb_amazon_get_settings();

    // Maybe suppress invoicing
    if (isset($settings['invoice_enabled']) && ($settings['invoice_enabled'] != 'yes')) {
        return array();
    }

    // Change workflow?
    if (isset($settings['workflow_id'])) {
        $workflow_id = $settings['workflow_id'];
        if (!empty($workflow_id)) {
            if ($workflow_id != 'auto') {
                $invoice['workflow_id'] = $workflow_id;
            } else {
                if (isset($invoice['workflow_id'])) {
                    unset($invoice['workflow_id']);
                }
            }
        }
    }

    // Change document style?
    if (isset($settings['document_style_id'])) {
        if (!empty($settings['document_style_id'])) {
            $invoice['document_style_id'] = $settings['document_style_id'];
        }
    }

    // Change revenue ledger account?
    if (!empty($settings['revenue_ledger_account_id'])) {
        if (isset($invoice['details_attributes']) && is_array($invoice['details_attributes'])) {
            for ($i = 0; $i < count($invoice['details_attributes']); $i++) {
                $invoice['details_attributes'][$i]['ledger_account_id'] = substr($settings['revenue_ledger_account_id'], 1);
            }
        }
    }

    return $invoice;
}

add_filter('woocommerce_moneybird_invoice', 'wc_mb_amazon_modify_invoice', 10, 2);
add_filter('woocommerce_moneybird_credit_invoice', 'wc_mb_amazon_modify_invoice', 10, 2);


function wc_mb_amazon_modify_register_payment($register_payment, $order)
{
    if (!wc_mb_is_amazon_order($order)) {
        return $register_payment;
    }

    $settings = wc_mb_amazon_get_settings();

    if (isset($settings['always_mark_paid'])) {
        if ($settings['always_mark_paid'] == 'yes') {
            $register_payment = true;
        }
    }

    return $register_payment;
}

add_filter('woocommerce_moneybird_register_payment', 'wc_mb_amazon_modify_register_payment', 10, 2);


function wc_mb_amazon_modify_order_is_paid($order_is_paid, $order)
{
    if (!wc_mb_is_amazon_order($order)) {
        return $order_is_paid;
    }

    $settings = wc_mb_amazon_get_settings();

    if (isset($settings['always_mark_paid'])) {
        if ($settings['always_mark_paid'] == 'yes') {
            $order_is_paid = true;
        }
    }

    return $order_is_paid;
}

add_filter('woocommerce_moneybird_is_order_paid', 'wc_mb_amazon_modify_order_is_paid', 10, 2);


function wc_mb_amazon_modify_sendmode($sendmode, $order)
{
    if (!wc_mb_is_amazon_order($order)) {
        return $sendmode;
    }

    $settings = wc_mb_amazon_get_settings();

    if (isset($settings['never_send'])) {
        if ($settings['never_send'] == 'yes') {
            if ($sendmode != 'draft') {
                $sendmode = 'Manual';
            }
        }
    }

    return $sendmode;
}

add_filter('woocommerce_moneybird_sendmode', 'wc_mb_amazon_modify_sendmode', 10, 2);


function wc_mb_amazon_modify_invoice_reference($reference, $order)
{
    if (!wc_mb_is_amazon_order($order)) {
        return $reference;
    }

    $settings = wc_mb_amazon_get_settings();

    if (!empty($settings['invoice_reference'])) {
        $reference = $settings['invoice_reference'];
    }

    return $reference;
}

add_filter('woocommerce_moneybird_reference', 'wc_mb_amazon_modify_invoice_reference', 10, 2);
