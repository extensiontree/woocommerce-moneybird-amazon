<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly


class WC_MoneyBird_Amazon extends WC_Integration {

    function __construct() {
        $this->id                 = 'moneybird_amazon';
        $this->method_title       = 'Moneybird Amazon';
        $this->method_description = 'Overrule de instellingen van de Moneybird koppeling voor WooCommerce bestellingen die via Amazon zijn binnengekomen.';

        // Settings
        $this->init_settings();

        // Admin-specific actions
        if (is_admin() && current_user_can('edit_shop_orders')) {
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        }
    }

    /**
     * The form fields are initialised on first use instead of in the constructor, because the
     * WC_MoneyBird2 instance (which provides the workflow, document style and ledger account
     * options) is not available yet while WooCommerce is instantiating the integrations.
     */
    public function get_form_fields() {
        if (empty($this->form_fields)) {
            $this->init_form_fields();
        }

        return parent::get_form_fields();
    }

    /**
     * Return the WC_MoneyBird2 instance of the main plugin, or false if it is not available.
     */
    function get_moneybird_integration() {
        if (!function_exists('WCMB')) {
            return false;
        }

        return WCMB();
    }

    public function get_settings_url() {
        return admin_url('admin.php?page=wc-settings&tab=integration&section=' . $this->id);
    }

    function init_form_fields() {
        // Only init the form fields if we're on our own settings page to avoid site slowdown
        if (!isset($_GET['page']) || ($_GET['page'] != 'wc-settings')) {
            return;
        }
        if (!isset($_GET['tab']) || ($_GET['tab'] != 'integration')) {
            return;
        }
        if (!isset($_GET['section']) || ($_GET['section'] != $this->id)) {
            return;
        }

        $mb = $this->get_moneybird_integration();
        if (!$mb) {
            return;
        }

        // An empty value means: don't overrule the general setting of the main plugin
        $inherit = array('' => 'Algemene instelling gebruiken');
        $workflows = array_merge($inherit, $mb->get_workflows(false, 'invoice'));
        $document_styles = array_merge($inherit, $mb->get_document_styles());
        $ledger_accounts = array_merge($inherit, $mb->get_revenue_ledger_accounts());

        if (isset($mb->custom_field_placeholders)) {
            $placeholders = $mb->custom_field_placeholders;
        } else {
            $placeholders = '{{order_id}}, {{product_skus}}, {{first_product_name}}';
        }

        $this->form_fields = array(
            'invoice_enabled' => array(
                'title'             => 'Facturen voor Amazon bestellingen',
                'description'       => 'Maak Moneybird facturen voor Amazon bestellingen.',
                'type'              => 'select',
                'options'           => array('yes' => 'Ja', 'no' => 'Nee'),
                'default'           => 'yes'
            ),
            'invoice_reference' => array(
                'title'             => __('Reference on invoice', 'woocommerce_moneybird'),
                'description'       => __('Specify the content of the reference field on the invoice. Available placeholders:', 'woocommerce_moneybird') . ' ' . $placeholders . '. Laat leeg om de algemene instelling te gebruiken.',
                'type'              => 'text',
                'desc_tip'          => true,
                'default'           => isset($mb->settings['invoice_reference']) ? $mb->settings['invoice_reference'] : __('Order #{{order_id}}', 'woocommerce_moneybird'),
            ),
            'workflow_id' => array(
                'title'             => 'Workflow Amazon bestellingen',
                'type'              => 'select',
                'options'           => $workflows,
                'default'           => '',
            ),
            'document_style_id' => array(
                'title'             => 'Huisstijl Amazon bestellingen',
                'type'              => 'select',
                'options'           => $document_styles,
                'default'           => '',
            ),
            'revenue_ledger_account_id' => array(
                'title'             => 'Omzetcategorie Amazon bestellingen',
                'type'              => 'select',
                'description'       => 'Overrule omzetcategorie voor alle factuurregels voor Amazon bestellingen.',
                'options'           => $ledger_accounts,
                'default'           => '',
            ),
            'always_mark_paid' => array(
                'title'             => 'Altijd als betaald markeren',
                'label'             => 'Markeer facturen voor Amazon bestellingen altijd als betaald.',
                'type'              => 'checkbox',
            ),
            'never_send' => array(
                'title'             => 'Factuur niet verzenden',
                'label'             => 'Verstuur facturen voor Amazon bestellingen nooit naar de klant.',
                'type'              => 'checkbox',
            ),
        );
    }

    function admin_options() {
        if (!$this->get_moneybird_integration()) {
            echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
            echo '<div class="error inline"><p>';
            echo 'De Moneybird koppeling is niet beschikbaar. Installeer en activeer eerst de ';
            echo '<a target="_blank" href="https://extensiontree.com/woocommerce-moneybird-koppeling/">WooCommerce Moneybird API koppeling</a>.';
            echo '</p></div>';
            return;
        }

        parent::admin_options();
    }
}
