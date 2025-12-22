<?php
/*
Plugin Name: Payme
Plugin URI:  https://business.payme.uz
Description: Payme Checkout Plugin for WooCommerce
Version: 1.5.0
Author: support@paycom.uz
Text Domain: payme
Requires Plugins:  woocommerce
WC requires at least: 6.0
WC tested up to:      9.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'getallheaders' ) ) {
    function getallheaders() {
        $headers = array(  );

        foreach ( $_SERVER as $name => $value ) {
            if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
                $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
            }
        }

        return $headers;
    }
}

function woocommerce_payme() {
    load_plugin_textdomain( 'payme', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

    // Do nothing, if WooCommerce is not available
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    // Do not re-declare class
    if ( class_exists( 'WC_PAYME' ) ) {
        return;
    }

    class WC_PAYME extends WC_Payment_Gateway {
        protected $merchant_id;
        protected $merchant_key;
        protected $checkout_url;
        protected $return_url;

        public function __construct() {
            $plugin_dir        = plugin_dir_url( __FILE__ );
            $this->id          = 'payme';
            $this->title       = 'Payme';
            $this->description = __( 'Payment system Payme', 'payme' );
            $this->icon        = apply_filters( 'woocommerce_payme_icon', '' . $plugin_dir . 'payme.png' );
            $this->has_fields  = false;

            $this->init_form_fields();
            $this->init_settings();

            // Populate options from the saved settings
            $this->merchant_id  = $this->get_option( 'merchant_id' );
            $this->merchant_key = $this->get_option( 'merchant_key' );
            $this->checkout_url = $this->get_option( 'checkout_url' );
            $this->return_url   = $this->get_option( 'return_url' );

            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_wc_' . $this->id, array( $this, 'callback' ) );
        }

        public function showMessage( $content ) {
            return '
            <h1>' . $this->msg[ 'title' ] . '</h1>
            <div class="box ' . $this->msg[ 'class' ] . '-box">' . $this->msg[ 'message' ] . '</div>
            ';
        }

        public function showTitle( $title ) {
            return false;
        }

        public function admin_options() {
            ?>
            <h3><?php _e( 'Payme', 'payme' ); ?></h3>

            <p><?php _e( 'Configure checkout settings', 'payme' ); ?></p>

            <p>
                <strong><?php _e( 'Your Web Cash Endpoint URL to handle requests is:', 'payme' ); ?></strong>
                <em><?php echo site_url( '/?wc-api=wc_payme' );?></em>
            </p>

            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled'      => array(
                    'title'   => __( 'Enable/Disable', 'payme' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enabled', 'payme' ),
                    'default' => 'yes',
                    ),
                'merchant_id'  => array(
                    'title'       => __( 'Merchant ID', 'payme' ),
                    'type'        => 'text',
                    'description' => __( 'Obtain and set Merchant ID from the Paycom Merchant Cabinet', 'payme' ),
                    'default'     => '',
                    ),
                'merchant_key' => array(
                    'title'       => __( 'KEY', 'payme' ),
                    'type'        => 'text',
                    'description' => __( 'Obtain and set KEY from the Paycom Merchant Cabinet', 'payme' ),
                    'default'     => '',
                    ),
                'checkout_url' => array(
                    'title'       => __( 'Checkout URL', 'payme' ),
                    'type'        => 'text',
                    'description' => __( 'Set Paycom Checkout URL to submit a payment', 'payme' ),
                    'default'     => 'https://checkout.paycom.uz',
                    ),
                'return_url'   => array(
                    'title'       => __( 'Return URL', 'payme' ),
                    'type'        => 'text',
                    'description' => __( 'Set Paycom return URL', 'payme' ),
                    'default'     => site_url( '/cart/?payme_success=1' ),
                    ),
                );
        }

        public function generate_form( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! ( $order instanceof WC_Order ) ) {
                return '';
            }

            $amount_tiyin = (int) round( (float) $order->get_total() * 100 );
            $amount_tiyin = number_format( $amount_tiyin, 0, '.', '' );

            $lang_codes = array( 'ru_RU' => 'ru', 'en_US' => 'en', 'uz_UZ' => 'uz' );
            $lang       = isset( $lang_codes[ get_locale() ] ) ? $lang_codes[ get_locale() ] : 'ru';

            $label_pay    = __( 'Pay', 'payme' );
            $label_cancel = __( 'Cancel payment and return back', 'payme' );

            $callback_url = add_query_arg(
                array(
                    'payme_success' => 1,
                    'order_id'      => $order->get_id(),
                    'transaction'   => ':transaction',
                    ),
                site_url( '/cart/' )
            );

            $description_value = sprintf( __( 'Payment for Order #%1$s', 'payme' ), $order->get_id() );

            $detail = array(
                'receipt_type' => 0,
                'items'        => array(  ),
                );

            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                    continue;
                }

                $product = $item->get_product();

                if ( ! $product ) {
                    continue;
                }

                $qty = (float) $item->get_quantity();

                if ( $qty <= 0 ) {
                    continue;
                }

                $line_total = (float) $item->get_total();

                $unit_price_tiyin = (int) round( ( $line_total / $qty ) * 100 );

                $detail[ 'items' ][  ] = array(
                    'title'        => $item->get_name(),
                    'price'        => $unit_price_tiyin,
                    'count'        => $qty,
                    'code'         => $product->get_id(),
                    'vat_percent'  => 0,
                    );
            }

            $detail_json  = wp_json_encode( $detail, JSON_UNESCAPED_UNICODE );
            $detail_value = base64_encode( (string) $detail_json );
            $detail_field = '<input type="hidden" name="detail" value="' . esc_attr( $detail_value ) . '">';

            $form = '<form action="' . esc_url( $this->checkout_url ) . '" method="POST" id="payme_form">';
            $form .= '<input type="hidden" name="merchant" value="' . esc_attr( $this->merchant_id ) . '">';
            $form .= '<input type="hidden" name="amount" value="' . esc_attr( $amount_tiyin ) . '">';
            $form .= '<input type="hidden" name="account[order_id]" value="' . esc_attr( $order->get_id() ) . '">';
            $form .= '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
            $form .= '<input type="hidden" name="callback" value="' . esc_url( $callback_url ) . '">';
            $form .= '<input type="hidden" name="callback_timeout" value="15000">';
            $form .= '<input type="hidden" name="description[' . esc_attr( $lang ) . ']" value="' . esc_attr( $description_value ) . '">';
            $form .= $detail_field;
            $form .= '<input type="submit" class="button alt" id="submit_payme_form" value="' . esc_attr( $label_pay ) . '">';
            $form .= '<a class="button cancel" style="margin-left: 15px;" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html( $label_cancel ) . '</a>';
            $form .= '</form>';

            return $form;
        }

        public function process_payment( $order_id ) {
            $order = new WC_Order( $order_id );

            return array(
                'result'   => 'success',
                'redirect' => add_query_arg(
                    'order_pay',
                    $order->get_id(),
                    add_query_arg( 'key', $order->get_order_key(), $order->get_checkout_payment_url( true ) )
                ),
                );
        }

        public function receipt_page( $order_id ) {
            echo '<p>' . __( 'Thank you for your order, press "Pay" button to continue.', 'payme' ) . '</p>';
            echo $this->generate_form( $order_id );
        }

        public function callback() {

            if ( ! $this->is_allowed_ip() ) {
                $this->respond( $this->error_authorization( array( 'id' => null ) ) );
            }

            // Parse payload
            $payload = json_decode( file_get_contents( 'php://input' ), true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $this->respond( $this->error_invalid_json() );
            }

            // Authorize client
            $headers             = getallheaders();
            $v                   = html_entity_decode( $this->merchant_key );
            $encoded_credentials = base64_encode( "Paycom:" . $v );

            if (
                ! $headers ||
                ! isset( $headers[ 'Authorization' ] ) ||
                ! preg_match( '/^\s*Basic\s+(\S+)\s*$/i', $headers[ 'Authorization' ], $matches ) ||
                $matches[ 1 ] != $encoded_credentials
            ) {
                $this->respond( $this->error_authorization( $payload ) );
            }

            $response = method_exists( $this, $payload[ 'method' ] )
                ? $this->{$payload[ 'method' ]}
            ( $payload )
                : $this->error_unknown_method( $payload );

            $this->respond( $response );
        }

        private function is_allowed_ip() {
            $allowed_ips = array(
                '185.234.113.1',
                '185.234.113.2',
                '185.234.113.3',
                '185.234.113.4',
                '185.234.113.5',
                '185.234.113.6',
                '185.234.113.7',
                '185.234.113.8',
                '185.234.113.9',
                '185.234.113.10',
                '185.234.113.11',
                '185.234.113.12',
                '185.234.113.13',
                '185.234.113.14',
                '185.234.113.15',
                );

            $remote_ip = $_SERVER[ 'REMOTE_ADDR' ] ?? '';

            if ( ! empty( $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] ) ) {
                $forwarded_ip = trim( explode( ',', $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] )[ 0 ] );

                if ( in_array( $forwarded_ip, $allowed_ips, true ) ) {
                    return true;
                }
            }

            return in_array( $remote_ip, $allowed_ips, true );
        }

        private function respond( $response ) {

            if ( ! headers_sent() ) {
                header( 'Content-Type: application/json; charset=UTF-8' );
            }

            echo json_encode( $response );
            die();
        }

        private function get_order( array $payload ) {
            try {
                $account = $payload[ 'params' ][ 'account' ] ?? array(  );

                // The order_id can be configured as the primary required parameter in the Payme Merchant Cabinet under Cashboxes → Settings → Payment Details.
                if ( isset( $account[ 'order_id' ] ) && '' !== $account[ 'order_id' ] ) {
                    return new WC_Order( $account[ 'order_id' ] );
                }

                $this->respond( $this->error_order_id( $payload ) );
            } catch ( Exception $ex ) {
                $this->respond( $this->error_order_id( $payload ) );
            }
        }

        private function get_order_by_transaction( $payload ) {
            global $wpdb;

            try {
                $prepared_sql = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_value = '%s' AND meta_key = '_payme_transaction_id'", $payload[ 'params' ][ 'id' ] );
                $order_id     = $wpdb->get_var( $prepared_sql );
                return new WC_Order( $order_id );
            } catch ( Exception $ex ) {
                $this->respond( $this->error_transaction( $payload ) );
            }
        }

        private function amount_to_coin( $amount ) {
            return 100 * number_format( $amount, 2, '.', '' );
        }

        private function current_timestamp() {
            return round( microtime( true ) * 1000 );
        }

        private function get_create_time( WC_Order $order ) {
            return (double) get_post_meta( $order->get_id(), '_payme_create_time', true );
        }

        private function get_perform_time( WC_Order $order ) {
            return (double) get_post_meta( $order->get_id(), '_payme_perform_time', true );
        }

        private function get_cancel_time( WC_Order $order ) {
            return (double) get_post_meta( $order->get_id(), '_payme_cancel_time', true );
        }

        private function get_transaction_id( WC_Order $order ) {
            return (string) get_post_meta( $order->get_id(), '_payme_transaction_id', true );
        }

        private function get_cencel_reason( WC_Order $order ) {
            $b_v = (int) get_post_meta( $order->get_id(), '_cancel_reason', true );

            if ( $b_v ) {
                return $b_v;
            } else {
                return null;
            }
        }

        private function CheckPerformTransaction( $payload ) {
            $order  = $this->get_order( $payload );
            $amount = $this->amount_to_coin( $order->get_total() );

            if ( $amount != $payload[ 'params' ][ 'amount' ] ) {
                $response = $this->error_amount( $payload );
            } else {
                $response = array(
                    'id'     => $payload[ 'id' ],
                    'result' => array(
                        'allow' => true,
                        ),
                    'error'  => null,
                    );
            }

            return $response;
        }

        private function CreateTransaction( $payload ) {
            $order  = $this->get_order( $payload );
            $amount = $this->amount_to_coin( $order->get_total() );

            if ( $amount != $payload[ 'params' ][ 'amount' ] ) {
                $response = $this->error_amount( $payload );
            } else {
                $create_time          = $this->current_timestamp();
                $transaction_id       = $payload[ 'params' ][ 'id' ];
                $saved_transaction_id = $this->get_transaction_id( $order );

                if ( $order->get_status() == "pending" ) {
                    // handle new transaction
                    // Save time and transaction id
                    add_post_meta( $order->get_id(), '_payme_create_time', $create_time, true );
                    add_post_meta( $order->get_id(), '_payme_transaction_id', $transaction_id, true );

                    // Change order's status to Processing
                    $order->update_status( 'processing' );

                    $response = array(
                        "id"     => $payload[ 'id' ],
                        "result" => array(
                            "create_time" => $this->get_create_time( $order ),
                            "transaction" => "000" . $order->get_id(),
                            "state"       => 1,
                            ),
                        );
                } elseif ( $order->get_status() == "processing" && $transaction_id == $saved_transaction_id ) { // handle existing transaction
                    $response = array(
                        "id"     => $payload[ 'id' ],
                        "result" => array(
                            "create_time" => $this->get_create_time( $order ),
                            "transaction" => "000" . $order->get_id(),
                            "state"       => 1,
                            ),
                        );
                } elseif ( $order->get_status() == "processing" && $transaction_id !== $saved_transaction_id ) { // handle new transaction with the same order
                    $response = $this->error_has_another_transaction( $payload );
                } else {
                    $response = $this->error_unknown( $payload );
                }
            }

            return $response;
        }

        private function PerformTransaction( $payload ) {
            $perform_time = $this->current_timestamp();
            $order        = $this->get_order_by_transaction( $payload );

            if ( $order->get_status() == "processing" ) {
                // handle new Perform request
                // Save perform time
                add_post_meta( $order->get_id(), '_payme_perform_time', $perform_time, true );

                $response = array(
                    "id"     => $payload[ 'id' ],
                    "result" => array(
                        "transaction"  => "000" . $order->get_id(),
                        "perform_time" => $this->get_perform_time( $order ),
                        "state"        => 2,
                        ),
                    );

                // Mark order as completed
                $order->update_status( 'completed' );
                $order->payment_complete( $payload[ 'params' ][ 'id' ] );
            } elseif ( $order->get_status() == "completed" ) { // handle existing Perform request
                $response = array(
                    "id"     => $payload[ 'id' ],
                    "result" => array(
                        "transaction"  => "000" . $order->get_id(),
                        "perform_time" => $this->get_perform_time( $order ),
                        "state"        => 2,
                        ),
                    );
            } elseif ( $order->get_status() == "cancelled" || $order->get_status() == "refunded" ) { // handle cancelled order
                $response = $this->error_cancelled_transaction( $payload );
            } else {
                $response = $this->error_unknown( $payload );
            }

            return $response;
        }

        private function CheckTransaction( $payload ) {
            $transaction_id = $payload[ 'params' ][ 'id' ];
            $order          = $this->get_order_by_transaction( $payload );

            // Get transaction id from the order
            $saved_transaction_id = $this->get_transaction_id( $order );

            $response = array(
                "id"     => $payload[ 'id' ],
                "result" => array(
                    "create_time"  => $this->get_create_time( $order ),
                    "perform_time" => ( is_null( $this->get_perform_time( $order ) ) ? 0 : $this->get_perform_time( $order ) ),
                    "cancel_time"  => ( is_null( $this->get_cancel_time( $order ) ) ? 0 : $this->get_cancel_time( $order ) ),
                    "transaction"  => "000" . $order->get_id(),
                    "state"        => null,
                    "reason"       => ( is_null( $this->get_cencel_reason( $order ) ) ? null : $this->get_cencel_reason( $order ) ),
                    ),
                "error"  => null,
                );

            if ( $transaction_id == $saved_transaction_id ) {
                switch ( $order->get_status() ) {
                    case 'processing':
                        $response[ 'result' ][ 'state' ] = 1;
                        break;
                    case 'completed':
                        $response[ 'result' ][ 'state' ] = 2;
                        break;
                    case 'cancelled':
                        $response[ 'result' ][ 'state' ] = -1;
                        break;
                    case 'refunded':
                        $response[ 'result' ][ 'state' ] = -2;
                        break;

                    default: $response = $this->error_transaction( $payload );
                        break;
                }
            } else {
                $response = $this->error_transaction( $payload );
            }

            return $response;
        }

        private function CancelTransaction( $payload ) {
            $order = $this->get_order_by_transaction( $payload );

            $transaction_id       = $payload[ 'params' ][ 'id' ];
            $saved_transaction_id = $this->get_transaction_id( $order );

            if ( $transaction_id == $saved_transaction_id ) {
                $cancel_time = $this->current_timestamp();

                $response = array(
                    "id"     => $payload[ 'id' ],
                    "result" => array(
                        "transaction" => "000" . $order->get_id(),
                        "cancel_time" => $cancel_time,
                        "state"       => null,
                        ),
                    );

                switch ( $order->get_status() ) {
                    case 'pending':
                    case 'processing':
                        add_post_meta( $order->get_id(), '_payme_cancel_time', $cancel_time, true );
                        $order->update_status( 'cancelled' );
                        $response[ 'result' ][ 'state' ] = -1;
                        update_post_meta( $order->get_id(), '_cancel_reason', $payload[ 'params' ][ 'reason' ] );
                        break;
                    case 'completed':
                        add_post_meta( $order->get_id(), '_payme_cancel_time', $cancel_time, true );
                        $order->update_status( 'refunded' );
                        $response[ 'result' ][ 'state' ] = -2;
                        update_post_meta( $order->get_id(), '_cancel_reason', $payload[ 'params' ][ 'reason' ] );
                        break;
                    case 'cancelled':
                        $response[ 'result' ][ 'cancel_time' ] = $this->get_cancel_time( $order );
                        $response[ 'result' ][ 'state' ]       = -1;
                        break;
                    case 'refunded':
                        $response[ 'result' ][ 'cancel_time' ] = $this->get_cancel_time( $order );
                        $response[ 'result' ][ 'state' ]       = -2;
                        break;
                    default:
                        $response = $this->error_cancel( $payload );
                        break;
                }
            } else {
                $response = $this->error_transaction( $payload );
            }

            return $response;
        }

        private function ChangePassword( $payload ) {

            if ( $payload[ 'params' ][ 'password' ] != $this->merchant_key ) {
                $woo_options = get_option( 'woocommerce_payme_settings' );

                if ( ! $woo_options ) { // No options found
                    return $this->error_password( $payload );
                }

                // Save new password
                $woo_options[ 'merchant_key' ] = $payload[ 'params' ][ 'password' ];
                $is_success                    = update_option( 'woocommerce_payme_settings', $woo_options );

                if ( ! $is_success ) { // Couldn't save new password
                    return $this->error_password( $payload );
                }

                return array(
                    "id"     => $payload[ 'id' ],
                    "result" => array( "success" => true ),
                    "error"  => null,
                    );
            }

            // Same password or something wrong
            return $this->error_password( $payload );
        }

        private function GetStatement( $payload ) {
            $from = isset( $payload[ 'params' ][ 'from' ] ) ? (int) $payload[ 'params' ][ 'from' ] : null;
            $to   = isset( $payload[ 'params' ][ 'to' ] ) ? (int) $payload[ 'params' ][ 'to' ] : null;

            if ( is_null( $from ) || is_null( $to ) || $from <= 0 || $to <= 0 ) {
                return $this->error_unknown( $payload );
            }

            if ( $from > $to ) {
                $tmp  = $from;
                $from = $to;
                $to   = $tmp;
            }

            $orders = wc_get_orders( array(
                'limit'      => -1,
                'orderby'    => 'meta_value_num',
                'order'      => 'ASC',
                'meta_key'   => '_payme_create_time',
                'status'     => array( 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed' ),
                'meta_query' => array(
                    array(
                        'key'     => '_payme_create_time',
                        'value'   => array( $from, $to ),
                        'compare' => 'BETWEEN',
                        'type'    => 'NUMERIC',
                        ),
                    ),
                ) );

            $transactions = array(  );

            foreach ( $orders as $order ) {
                if ( ! ( $order instanceof WC_Order ) ) {
                    continue;
                }

                $create_time = (int) $this->get_create_time( $order );

                if ( $create_time < $from || $create_time > $to ) {
                    continue;
                }

                $status = $order->get_status();

                switch ( $status ) {
                    case 'processing':
                        $state = 1;
                        break;
                    case 'completed':
                        $state = 2;
                        break;
                    case 'cancelled':
                        $state = -1;
                        break;
                    case 'refunded':
                        $state = -2;
                        break;
                    default:
                        $state = 1;
                        break;
                }

                $transactions[  ] = array(
                    'id'           => $this->get_transaction_id( $order ),
                    'time'         => $create_time,
                    'amount'       => (int) $this->amount_to_coin( $order->get_total() ),
                    'account'      => array(
                        'order_id' => (string) $order->get_id(),
                        ),
                    'create_time'  => $create_time,
                    'perform_time' => (int) ( is_null( $this->get_perform_time( $order ) ) ? 0 : $this->get_perform_time( $order ) ),
                    'cancel_time'  => (int) ( is_null( $this->get_cancel_time( $order ) ) ? 0 : $this->get_cancel_time( $order ) ),
                    'transaction'  => "000" . $order->get_id(),
                    'state'        => $state,
                    'reason'       => $this->get_cencel_reason( $order ),
                    'receivers'    => array(  ),
                    );
            }

            return array(
                'id'     => $payload[ 'id' ],
                'result' => array(
                    'transactions' => $transactions,
                    ),
                'error'  => null,
                );
        }

        private function error_password( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -32400,
                    "message" => array(
                        "ru" => 'Не удалось изменить пароль',
                        "uz" => "Parolni o'zgartirib bo'lmadi",
                        "en" => 'Could not change the password',
                        ),
                    "data"    => "password",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_invalid_json() {
            $response = array(
                "error"  => array(
                    "code"    => -32700,
                    "message" => array(
                        "ru" => 'Не удалось разобрать JSON',
                        "uz" => "JSON ma'lumotlarini o'qib bo'lmadi",
                        "en" => 'Could not parse JSON',
                        ),
                    "data"    => null,
                    ),
                "result" => null,
                "id"     => 0,
                );

            return $response;
        }

        private function error_order_id( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31099,
                    "message" => array(
                        "ru" => 'Заказ не найден',
                        "uz" => 'Buyurtma topilmadi',
                        "en" => 'Order not found',
                        ),
                    "data"    => "order",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_has_another_transaction( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31099,
                    "message" => array(
                        "ru" => 'По этому заказу уже выполняется другая транзакция',
                        "uz" => 'Ushbu buyurtma uchun boshqa tranzaksiya allaqachon bajarilmoqda',
                        "en" => 'Another transaction for this order is already in progress',
                        ),
                    "data"    => "order",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_amount( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31001,
                    "message" => array(
                        "ru" => 'Неверная сумма заказа',
                        "uz" => "Buyurtma summasi noto'g'ri",
                        "en" => 'Incorrect order amount',
                        ),
                    "data"    => "amount",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_unknown( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31008,
                    "message" => array(
                        "ru" => 'Неизвестная ошибка',
                        "uz" => "Noma'lum xatolik",
                        "en" => 'Unknown error',
                        ),
                    "data"    => null,
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_unknown_method( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -32601,
                    "message" => array(
                        "ru" => 'Неизвестный метод',
                        "uz" => "Noma'lum metod",
                        "en" => 'Unknown method',
                        ),
                    "data"    => $payload[ 'method' ],
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_transaction( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31003,
                    "message" => array(
                        "ru" => 'Неверный номер транзакции',
                        "uz" => "Tranzaksiya raqami noto'g'ri",
                        "en" => 'Invalid transaction ID',
                        ),
                    "data"    => "id",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_cancelled_transaction( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31008,
                    "message" => array(
                        "ru" => 'Транзакция отменена или возвращена',
                        "uz" => 'Tranzaksiya bekor qilingan yoki qaytarilgan',
                        "en" => 'Transaction was cancelled or refunded',
                        ),
                    "data"    => "order",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_cancel( $payload ) {
            $response = array(
                "error"  => array(
                    "code"    => -31007,
                    "message" => array(
                        "ru" => 'Отмена невозможна: заказ уже выполнен',
                        "uz" => "Bekor qilib bo'lmaydi: buyurtma allaqachon yakunlangan",
                        "en" => 'Cancellation is not possible: the order is already completed',
                        ),
                    "data"    => "order",
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }

        private function error_authorization( $payload ) {
            $response = array(
                "error"  =>
                array(
                    "code"    => -32504,
                    "message" => array(
                        "ru" => 'Ошибка авторизации',
                        "uz" => "Avtorizatsiya xatosi",
                        "en" => 'Authorization error',
                        ),
                    "data"    => null,
                    ),
                "result" => null,
                "id"     => $payload[ 'id' ],
                );

            return $response;
        }
    }
}

add_action( 'plugins_loaded', 'woocommerce_payme', 0 );

function add_payme_gateway( $methods ) {
    $methods[  ] = 'WC_PAYME';
    return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_payme_gateway' );


function payme_clear_order_meta_on_payment_pending( $order_id, $old_status, $new_status, $order ) {
    if ( ! $order_id || ! ( $order instanceof WC_Order ) ) {
        return;
    }

    $normalized_new_status = (string) $new_status;

    if ( str_starts_with( $normalized_new_status, 'wc-' ) ) {
        $normalized_new_status = substr( $normalized_new_status, 3 );
    }

    if ( 'pending' !== $normalized_new_status ) {
        return;
    }

    $meta_keys = array(
        '_payme_create_time',
        '_payme_transaction_id',
        '_payme_perform_time',
        '_payme_cancel_time',
        '_cancel_reason',
    );

    foreach ( $meta_keys as $meta_key ) {
        delete_post_meta( $order_id, $meta_key );
    }
}

add_action( 'woocommerce_order_status_changed', 'payme_clear_order_meta_on_payment_pending', 10, 4 );

function payme_success_query_vars( $query_vars ) {
    $query_vars[  ] = 'payme_success';
    $query_vars[  ] = 'order_id';
    return $query_vars;
}

add_filter( 'query_vars', 'payme_success_query_vars' );
            
function payme_success_parse_request( &$wp ) {
    if ( array_key_exists( 'payme_success', $wp->query_vars ) ) {
        $order = new WC_Order( $wp->query_vars[ 'order_id' ] );
        $a     = new WC_PAYME();
        add_action( 'the_title', array( $a, 'showTitle' ) );
        add_action( 'the_content', array( $a, 'showMessage' ) );

        if ( 1 == $wp->query_vars[ 'payme_success' ] ) {
            if ( $order->get_status() == "pending" ) {
                wp_redirect( $order->get_cancel_order_url() );
            } else {
                $a->msg[ 'title' ]   = __( 'Payment successfully paid', 'payme' );
                $a->msg[ 'message' ] = __( 'Thank you for your purchase!', 'payme' );
                $a->msg[ 'class' ]   = 'woocommerce_message woocommerce_message_info';
                WC()->cart->empty_cart();
            }
        } else {
            $a->msg[ 'title' ]   = __( 'Payment not paid', 'payme' );
            $a->msg[ 'message' ] = __( 'An error occurred during payment. Try again or contact your administrator.', 'payme' );
            $a->msg[ 'class' ]   = 'woocommerce_message woocommerce_message_info';
        }
    }

    return;
}

add_action( 'parse_request', 'payme_success_parse_request' );
