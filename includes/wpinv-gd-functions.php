<?php
// MUST have WordPress.
if ( !defined( 'WPINC' ) ) {
    exit( 'Do NOT access this file directly: ' . basename( __FILE__ ) );
}

function wpinv_gd_active() {
    return (bool)defined( 'GEODIRECTORY_VERSION' );
}

function wpinv_pm_active() {
    return (bool)wpinv_gd_active() && (bool)defined( 'GEODIRPAYMENT_VERSION' );
}

function wpinv_is_gd_post_type( $post_type ) {
    global $gd_posttypes;
    
    $gd_posttypes = !empty( $gd_posttypes ) && is_array( $gd_posttypes ) ? $gd_posttypes : geodir_get_posttypes();
    
    if ( !empty( $post_type ) && !empty( $gd_posttypes ) && in_array( $post_type, $gd_posttypes ) ) {
        return true;
    }
    
    return false;
}

function wpinv_geodir_integration() {    
    if (!defined('GEODIRECTORY_VERSION')) {
        return;
    }
    
    if (!(defined( 'DOING_AJAX' ) && DOING_AJAX)) {
        // Add  fields for force upgrade
        if ( defined('INVOICE_TABLE') && !get_option('wpinv_gdp_column') ) {
            geodir_add_column_if_not_exist( INVOICE_TABLE, 'invoice_id', 'INT( 11 ) NOT NULL DEFAULT 0' );
            
            update_option('wpinv_gdp_column', '1');
        }
        // Merge price packages
        wpinv_merge_gd_packages_to_items();
    }
}
add_action( 'admin_init', 'wpinv_geodir_integration' );

function wpinv_get_gdp_package_type( $item_types ) {
    if ( wpinv_pm_active() ) {
        $item_types['package'] = __( 'Package', 'invoicing' );
    }
        
    return $item_types;
}
add_filter( 'wpinv_get_item_types', 'wpinv_get_gdp_package_type', 10, 1 );

function wpinv_update_package_item($package_id) {
    return wpinv_merge_gd_package_to_item($package_id, true);
}
add_action('geodir_after_save_package', 'wpinv_update_package_item', 10, 1);

function wpinv_merge_gd_packages_to_items( $force = false ) {    
    if ( $merged = get_option( 'wpinv_merge_gd_packages' ) && !$force ) {
        return true;
    }

    if(!function_exists('geodir_package_list_info')){
        return false;
    }
    
    $packages = geodir_package_list_info();
    
    foreach ( $packages as $key => $package ) {
        wpinv_merge_gd_package_to_item( $package->pid, $force, $package );
    }
    
    if ( !$merged ) {
        update_option( 'wpinv_merge_gd_packages', 1 );
    }
    
    return true;
}

function wpinv_get_gd_package_item($package_id, $create = false) {
    $item = wpinv_get_item_by('package_id', $package_id);
    
    if (!$create) {
        return $item;
    }
    
    return wpinv_merge_gd_package_to_item($package_id, true);
}

function wpinv_merge_gd_package_to_item($package_id, $force = false, $package = NULL) {
    if (empty($package_id)) {
        return false;
    }
    
    $item = wpinv_get_item_by('package_id', $package_id);

    if (!$force && !empty($item)) {
        return $item;
    }

    $package = empty($package) ? geodir_get_package_info_by_id($package_id, '') : $package;

    if ( empty($package) || !wpinv_is_gd_post_type( $package->post_type ) ) {
        return false;
    }
        
    $meta                       = array();
    $meta['type']               = 'package';
    $meta['package_id']         = $package_id;
    $meta['post_type']          = $package->post_type;
    $meta['cpt_singular_name']  = get_post_type_singular_label($package->post_type);
    $meta['cpt_name']           = get_post_type_plural_label($package->post_type);
    $meta['price']              = wpinv_format_amount( $package->amount, NULL, true );
    $meta['vat_rule']           = 'digital';
    $meta['vat_class']          = '_standard';
    
    if ( !empty( $package->sub_active ) ) {
        $sub_num_trial_days = absint( $package->sub_num_trial_days );
        
        $meta['is_recurring']       = 1;
        $meta['recurring_period']   = $package->sub_units;
        $meta['recurring_interval'] = absint( $package->sub_units_num );
        $meta['recurring_limit']    = absint( $package->sub_units_num_times );
        $meta['free_trial']         = $sub_num_trial_days > 0 ? 1 : 0;
        $meta['trial_period']       = $package->sub_num_trial_units;
        $meta['trial_interval']     = $sub_num_trial_days;
    } else {
        $meta['is_recurring']       = 0;
        $meta['recurring_period']   = '';
        $meta['recurring_interval'] = '';
        $meta['recurring_limit']    = '';
        $meta['free_trial']         = 0;
        $meta['trial_period']       = '';
        $meta['trial_interval']     = '';
    }
    
    $data  = array( 
        'post_title'    => $package->title,
        'post_excerpt'  => $package->title_desc,
        'post_status'   => $package->status == 1 ? 'publish' : 'pending',
        'meta'          => $meta
    );

    if (!empty($item)) {
        $item->update($data);
    } else {
        $item = new WPInv_Item();
        $item->create($data);
    }
    
    return $item;
}

function wpinv_gdp_to_wpi_gateway( $payment_method ) {
    switch( $payment_method ) {
        case 'prebanktransfer':
            $gateway = 'bank_transfer';
        break;
        default:
            $gateway = empty( $payment_method ) ? 'manual' : $payment_method;
        break;
    }
    
    return apply_filters( 'wpinv_gdp_to_wpi_gateway', $gateway, $payment_method );
}

function wpinv_gdp_to_wpi_gateway_title( $payment_method ) {
    $gateway = wpinv_gdp_to_wpi_gateway( $payment_method );
    
    $gateway_title = wpinv_get_gateway_checkout_label( $gateway );
    
    if ( $gateway == $gateway_title ) {
        $gateway_title = geodir_payment_method_title( $gateway );
    }
    
    return apply_filters( 'wpinv_gdp_to_wpi_gateway_title', $gateway_title, $payment_method );
}

function wpinv_print_checkout_errors() {
    global $wpi_session;
    wpinv_print_errors();
}
add_action( 'geodir_checkout_page_content', 'wpinv_print_checkout_errors', -10 );

function wpinv_cpt_save( $invoice_id, $update = false, $pre_status = NULL ) {
    global $wpi_nosave, $wpi_zero_tax, $wpi_gdp_inv_merge;
    
    $invoice_info = geodir_get_invoice( $invoice_id );
    
    $wpi_invoice_id  = !empty( $invoice_info->invoice_id ) ? $invoice_info->invoice_id : 0;
    
    if (!empty($invoice_info)) {
        $wpi_invoice = $wpi_invoice_id > 0 ? wpinv_get_invoice( $wpi_invoice_id ) : NULL;
        
        if ( !empty( $wpi_invoice ) ) { // update invoice
            $save = false;
            if ($invoice_info->coupon_code !== $wpi_invoice->discount_code || (float)$invoice_info->discount < (float)$wpi_invoice->discount || (float)$invoice_info->discount > (float)$wpi_invoice->discount) {
                $save = true;
                $wpi_invoice->set('discount_code', $invoice_info->coupon_code);
                $wpi_invoice->set('discount', $invoice_info->discount);
            }
            
            if ($invoice_info->paymentmethod !== $wpi_invoice->gateway) {
                $save = true;
                $gateway = !empty( $invoice_info->paymentmethod ) ? $invoice_info->paymentmethod : '';
                $gateway = wpinv_gdp_to_wpi_gateway( $gateway );
                $gateway_title = wpinv_gdp_to_wpi_gateway_title( $gateway );
                $wpi_invoice->set('gateway', $gateway );
                $wpi_invoice->set('gateway_title', $gateway_title );
            }
            
            if ( ( $status = wpinv_gdp_to_wpi_status( $invoice_info->status ) ) !== $wpi_invoice->status ) {
                $save = true;
                $wpi_invoice->set( 'status', $status );
            }
            
            if ($save) {
                $wpi_nosave = true;
                $wpi_invoice->recalculate_total();
                $wpi_invoice->save();
            }
            
            return $wpi_invoice;
        } else { // create invoice
            $user_info = get_userdata( $invoice_info->user_id );
            
            if ( !empty( $pre_status ) ) {
                $invoice_info->status = $pre_status;
            }
            $status = wpinv_gdp_to_wpi_status( $invoice_info->status );
            
            $wpi_zero_tax = false;
            
            if ( $wpi_gdp_inv_merge && in_array( $status, array( 'publish', 'wpi-processing', 'wpi-renewal' ) ) ) {
                $wpi_zero_tax = true;
            }
            
            $invoice_data                   = array();
            $invoice_data['invoice_id']     = $wpi_invoice_id;
            $invoice_data['status']         = $status;
            if ( $update ) {
                //$invoice_data['private_note']   = __( 'Invoice was updated.', 'invoicing' );
            } else {
                $invoice_data['private_note']   = wp_sprintf( __( 'Invoice was created with status %s.', 'invoicing' ), wpinv_status_nicename( $status ) );
            }
            $invoice_data['user_id']        = $invoice_info->user_id;
            $invoice_data['created_via']    = 'API';
            
            if ( !empty( $invoice_info->date ) ) {
                $invoice_data['created_date']   = $invoice_info->date;
            }
            
            $paymentmethod = !empty( $invoice_info->paymentmethod ) ? $invoice_info->paymentmethod : '';
            $paymentmethod = wpinv_gdp_to_wpi_gateway( $paymentmethod );
            $payment_method_title = wpinv_gdp_to_wpi_gateway_title( $paymentmethod );
            
            $invoice_data['payment_details'] = array( 
                'gateway'           => $paymentmethod, 
                'gateway_title'     => $payment_method_title,
                'currency'          => geodir_get_currency_type(),
                'paid'              => $status === 'publish' ? true : false
            );
            
            $user_address = wpinv_get_user_address( $invoice_info->user_id, false );
            
            $invoice_data['user_info'] = array( 
                'user_id'       => $invoice_info->user_id, 
                'first_name'    => $user_address['first_name'],
                'last_name'     => $user_address['last_name'],
                'email'         => $user_address['email'],
                'company'       => $user_address['company'],
                'vat_number'    => $user_address['vat_number'],
                'phone'         => $user_address['phone'],
                'address'       => $user_address['address'],
                'city'          => $user_address['city'],
                'country'       => $user_address['country'],
                'state'         => $user_address['state'],
                'zip'           => $user_address['zip'],
                'discount'      => $invoice_info->coupon_code,
            );
            
            $invoice_data['discount']       = $invoice_info->discount;
            $invoice_data['discount_code']  = $invoice_info->coupon_code;
            
            $post_item = wpinv_get_gd_package_item($invoice_info->package_id);
            
            if ( !empty( $post_item ) ) {
                $cart_details  = array();
                $cart_details[] = array(
                    'id'                => $post_item->ID,
                    'name'              => $post_item->get_name(),
                    'item_price'        => $post_item->get_price(),
                    'discount'          => $invoice_info->discount,
                    'tax'               => 0.00,
                    'meta'              => array( 
                                            'post_id'       => $invoice_info->post_id,
                                            'invoice_title' => $invoice_info->post_title
                                        ),
                );
                
                $invoice_data['cart_details']  = $cart_details;
            }

            $data = array( 'invoice' => $invoice_data );

            $wpinv_api = new WPInv_API();
            $data = $wpinv_api->insert_invoice( $data );
            
            if ( is_wp_error( $data ) ) {
                wpinv_error_log( 'WPInv_Invoice: ' . $data->get_error_message() );
            } else {
                if ( !empty( $data ) ) {
                    update_post_meta( $data->ID, '_wpinv_gdp_id', $invoice_id );

                    global $wpi_userID, $wpinv_ip_address_country;
                    
                    $checkout_session = wpinv_get_checkout_session();
                    
                    $data_session                   = array();
                    $data_session['invoice_id']     = $data->ID;
                    $data_session['cart_discounts'] = $data->get_discounts( true );
                    
                    wpinv_set_checkout_session( $data_session );
                    
                    $wpi_userID         = (int)$data->get_user_id();
                    $_POST['country']   = !empty($data->country) ? $data->country : wpinv_get_default_country();
                        
                    $data->country      = sanitize_text_field( $_POST['country'] );
                    $data->set( 'country', sanitize_text_field( $_POST['country'] ) );
                    
                    $wpinv_ip_address_country = $data->country;
                    
                    $data = $data->recalculate_totals(true);
                    
                    wpinv_set_checkout_session( $checkout_session );
                    
                    $update_data = array();
                    $update_data['tax_amount'] = $data->get_tax();
                    $update_data['paied_amount'] = $data->get_total();
                    $update_data['invoice_id'] = $data->ID;
                    
                    global $wpdb;
                    $wpdb->update( INVOICE_TABLE, $update_data, array( 'id' => $invoice_id ) );
                    
                    return $data;
                } else {
                    if ( $update ) {
                        wpinv_error_log( 'WPInv_Invoice: ' . __( 'Fail to update invoice.', 'invoicing' ) );
                    } else {
                        wpinv_error_log( 'WPInv_Invoice: ' . __( 'Fail to create invoice.', 'invoicing' ) );
                    }
                }
            }
        }
    }
    
    return false;
}
add_action('geodir_payment_invoice_created', 'wpinv_cpt_save', 11, 3);

function wpinv_cpt_update( $invoice_id ) {
    return wpinv_cpt_save( $invoice_id, true );
}
add_action('geodir_payment_invoice_updated', 'wpinv_cpt_update', 11, 1);

function wpinv_payment_status_changed( $invoice_id, $new_status, $old_status = 'pending', $subscription = false ) {
    $invoice_info = geodir_get_invoice( $invoice_id );
    if ( empty( $invoice_info ) ) {
        return false;
    }

    $invoice = !empty( $invoice_info->invoice_id ) ? wpinv_get_invoice( $invoice_info->invoice_id ) : NULL;
    if ( !empty( $invoice ) ) {
        $new_status = wpinv_gdp_to_wpi_status($new_status);
        $invoice    = wpinv_update_payment_status( $invoice->ID, $new_status );
    } else {
        $invoice = wpinv_cpt_save( $invoice_id );
    }
    
    return $invoice;
}
add_action( 'geodir_payment_invoice_status_changed', 'wpinv_payment_status_changed', 11, 4 );

function wpinv_transaction_details_note( $invoice_id, $html ) {
    $invoice_info = geodir_get_invoice( $invoice_id );
    if ( empty( $invoice_info ) ) {
        return false;
    }

    $wpi_invoice_id = !empty( $invoice_info->invoice_id ) ? $invoice_info->invoice_id : NULL;
    
    if ( !$wpi_invoice_id ) {
        $invoice = wpinv_cpt_save( $invoice_id, false, $old_status );
        
        if ( !empty( $invoice ) ) {
            $wpi_invoice_id = $invoice->ID;
        }
    }

    $invoice = wpinv_get_invoice( $wpi_invoice_id );
    
    if ( empty( $invoice ) ) {
        return false;
    }
    
    return $invoice->add_note( $html, true );
}
add_action( 'geodir_payment_invoice_transaction_details_changed', 'wpinv_transaction_details_note', 11, 2 );

function wpinv_gdp_to_wpi_status( $status ) {
    $inv_status = $status ? $status : 'pending';
    
    switch ( $status ) {
        case 'confirmed':
            $inv_status = 'publish';
        break;
    }
    
    return $inv_status;
}

function wpinv_wpi_to_gdp_status( $status ) {
    $inv_status = $status ? $status : 'pending';
    
    switch ( $status ) {
        case 'publish':
        case 'wpi-processing':
        case 'wpi-renewal':
            $inv_status = 'confirmed';
        break;
    }
    
    return $inv_status;
}

function wpinv_wpi_to_gdp_id( $invoice_id ) {
    global $wpdb;
    
    return $wpdb->get_var( $wpdb->prepare( "SELECT `id` FROM `" . INVOICE_TABLE . "` WHERE `invoice_id` = %d AND `invoice_id` > 0 ORDER BY id DESC LIMIT 1", array( (int)$invoice_id ) ) );
}

function wpinv_gdp_to_wpi_id( $invoice_id ) {
    $invoice = geodir_get_invoice( $invoice_id );    
    return ( empty( $invoice->invoice_id ) ? $invoice->invoice_id : false);
}

function wpinv_to_gdp_recalculate_total( $invoice, $wpi_nosave ) {
    global $wpdb;
    
    if ( !empty( $wpi_nosave ) ) {
        return;
    }
    
    $gdp_invoice_id = wpinv_wpi_to_gdp_id( $invoice->ID );
    
    if ( $gdp_invoice_id > 0 ) {
        $update_data = array();
        $update_data['tax_amount']      = $invoice->tax;
        $update_data['paied_amount']    = $invoice->total;
        $update_data['discount']        = $invoice->discount;
        $update_data['coupon_code']     = $invoice->discount_code;
        
        $wpdb->update( INVOICE_TABLE, $update_data, array( 'id' => $gdp_invoice_id ) );
    }
    
    return;
}
//add_action( 'wpinv_invoice_recalculate_total', 'wpinv_to_gdp_recalculate_total', 10, 2 );

function wpinv_gdp_to_wpi_invoice( $invoice_id ) {
    $invoice = geodir_get_invoice( $invoice_id );
    if ( empty( $invoice->invoice_id ) ) {
        return false;
    }
    
    return wpinv_get_invoice( $invoice->invoice_id );
}

function wpinv_payment_set_coupon_code( $status, $invoice_id, $coupon_code ) {
    $invoice = wpinv_gdp_to_wpi_invoice( $invoice_id );
    if ( empty( $invoice ) ) {
        return $status;
    }

    if ( $status === 1 || $status === 0 ) {
        if ( $status === 1 ) {
            $discount = geodir_get_discount_amount( $coupon_code, $invoice->get_subtotal() );
        } else {
            $discount = '';
            $coupon_code = '';
        }
        
        $invoice->set( 'discount', $discount );
        $invoice->set( 'discount_code', $coupon_code );
        $invoice->save();
        $invoice->recalculate_total();
    }
    
    return $status;
}
add_filter( 'geodir_payment_set_coupon_code', 'wpinv_payment_set_coupon_code', 10, 3 );

function wpinv_insert_invoice( $invoice_data = array() ) {
    if ( empty( $invoice_data ) ) {
        return false;
    }

    // default invoice args, note that status is checked for validity in wpinv_create_invoice()
    $default_args = array(
        'status'        => !empty( $invoice_data['status'] ) ? $invoice_data['status'] : 'pending',
        'user_note'     => !empty( $invoice_data['note'] ) ? $invoice_data['note'] : null,
        'invoice_id'    => !empty( $invoice_data['invoice_id'] ) ? (int)$invoice_data['invoice_id'] : 0,
        'user_id'       => !empty( $invoice_data['user_id'] ) ? (int)$invoice_data['user_id'] : get_current_user_id(),
    );

    $invoice = wpinv_create_invoice( $default_args, $invoice_data );
    if ( is_wp_error( $invoice ) ) {
        return $invoice;
    }

    $gateway = !empty( $invoice_data['gateway'] ) ? $invoice_data['gateway'] : '';
    $gateway = empty( $gateway ) && isset( $_POST['gateway'] ) ? $_POST['gateway'] : $gateway;
    
    if ( !empty( $gateway ) ) {
        $gateway = wpinv_gdp_to_wpi_gateway( $gateway );
        $invoice_data['payment_details']['gateway'] = $gateway;
        $invoice_data['payment_details']['gateway_title'] = wpinv_gdp_to_wpi_gateway_title( $gateway );
    }
    
    $user_info = array(
        'user_id'        => '',
        'first_name'     => '',
        'last_name'      => '',
        'email'          => '',
        'company'        => '',
        'phone'          => '',
        'address'        => '',
        'city'           => '',
        'country'        => wpinv_get_default_country(),
        'state'          => wpinv_get_default_state(),
        'zip'            => '',
        'vat_number'     => '',
        'vat_rate'       => '',
        'adddress_confirmed' => '',
        'discount'       => array(),
    );
    
    $user_info    = wp_parse_args( $invoice_data['user_info'], $user_info );
    
    $payment_details = array();
    if ( !empty( $invoice_data['payment_details'] ) ) {
        $payment_details = array(
            'gateway'           => 'manual',
            'gateway_title'     => __( 'Manual Payment', 'invoicing' ),
            'currency'          => geodir_get_currency_type(),
            'paid'              => false,
            'transaction_id'    => '',
        );
        $payment_details = wp_parse_args( $invoice_data['payment_details'], $payment_details );
    }
    $invoice->set( 'status', ( !empty( $invoice_data['status'] ) ? $invoice_data['status'] : 'pending' ) );
    if ( !empty( $payment_details ) ) {
        $invoice->set( 'currency', $payment_details['currency'] );
        $invoice->set( 'gateway', $payment_details['gateway'] );
        $invoice->set( 'gateway_title', $payment_details['gateway_title'] );
        $invoice->set( 'transaction_id', $payment_details['transaction_id'] );
    }

    $invoice->set( 'user_info', $user_info );
    ///$invoice->set( 'user_id', $user_info['user_id'] );
    ///$invoice->set( 'email', $user_info['email'] );
    $invoice->set( 'first_name', $user_info['first_name'] );
    $invoice->set( 'last_name', $user_info['last_name'] );
    $invoice->set( 'address', $user_info['address'] );
    $invoice->set( 'company', $user_info['company'] );
    $invoice->set( 'vat_number', $user_info['vat_number'] );
    $invoice->set( 'phone', $user_info['phone'] );
    $invoice->set( 'city', $user_info['city'] );
    $invoice->set( 'country', $user_info['country'] );
    $invoice->set( 'state', $user_info['state'] );
    $invoice->set( 'zip', $user_info['zip'] );
    $invoice->set( 'discounts', ( !empty( $user_info['discount'] ) ? $user_info['discount'] : array() ) );
    $invoice->set( 'ip', wpinv_get_ip() );
    if ( !empty( $invoice_data['invoice_key'] ) ) {
        $invoice->set( 'key', $invoice_data['invoice_key'] );
    }
    $invoice->set( 'mode', ( wpinv_is_test_mode() ? 'test' : 'live' ) );
    $invoice->set( 'parent_invoice', ( !empty( $invoice_data['parent'] ) ? absint( $invoice_data['parent'] ) : '' ) );
    
    // Add note
    if ( !empty( $invoice_data['user_note'] ) ) {
        $invoice->add_note( $invoice_data['user_note'], true );
    }
    
    if ( !empty( $invoice_data['private_note'] ) ) {
        $invoice->add_note( $invoice_data['private_note'] );
    }
    
    if ( is_array( $invoice_data['cart_details'] ) && !empty( $invoice_data['cart_details'] ) ) {
        foreach ( $invoice_data['cart_details'] as $key => $item ) {
            $item_id    = !empty( $item['id'] ) ? $item['id'] : 0;
            $quantity   = !empty( $item['quantity'] ) ? $item['quantity'] : 1;
            $name       = !empty( $item['name'] ) ? $item['name'] : '';
            $item_price = isset( $item['item_price'] ) ? $item['item_price'] : '';
            
            $post_item  = new WPInv_Item( $item_id );
            if ( !empty( $post_item ) ) {
                $name       = !empty( $name ) ? $name : $post_item->get_name();
                $item_price = $item_price !== '' ? $item_price : $post_item->get_price();
            } else {
                continue;
            }
            
            $args = array(
                'name'          => $name,
                'quantity'      => $quantity,
                'item_price'    => $item_price,
                'tax'           => !empty( $item['tax'] ) ? $item['tax'] : 0.00,
                'discount'      => isset( $item['discount'] ) ? $item['discount'] : 0,
                'meta'          => isset( $item['meta'] ) ? $item['meta'] : array(),
                'fees'          => isset( $item['fees'] ) ? $item['fees'] : array(),
            );

            $invoice->add_item( $item_id, $args );
        }
    }

    $invoice->increase_tax( wpinv_get_cart_fee_tax() );

    if ( isset( $invoice_data['post_date'] ) ) {
        $invoice->set( 'date', $invoice_data['post_date'] );
    }

    $number = wpinv_format_invoice_number( $invoice->ID );
    $invoice->set( 'number', $number );
    update_option( 'wpinv_last_invoice_number', $number );
    
    $invoice->save();
    
    do_action( 'wpinv_insert_invoice', $invoice->ID, $invoice_data );

    if ( ! empty( $invoice->ID ) ) {
        // payment method (and payment_complete() if `paid` == true)
        if ( !empty( $payment_details['paid'] ) ) {
            //$invoice->payment_complete( !empty( $payment_details['transaction_id'] ) ? $payment_details['transaction_id'] : $invoice->ID );
        }
            
        return $invoice;
    }

    // Return false if no invoice was inserted
    return false;
}

function wpinv_merge_gd_invoices() {
    if (!defined('GEODIRPAYMENT_VERSION')) {
        return;
    }
    ?>
    <tr>
        <td><?php _e( 'Merge Price Packages', 'invoicing' ); ?></td>
        <td><p><?php _e( 'Merge GeoDirectory Payment Manager price packages to the Invoicing items.', 'invoicing' ); ?></p></td>
        <td><input type="button" data-tool="merge_packages" class="button-primary wpinv-tool" value="<?php esc_attr_e( 'Run', 'invoicing' ); ?>"></td>
    </tr>
    <tr>
        <td><?php _e( 'Merge Invoices', 'invoicing' ); ?></td>
        <td><p><?php _e( 'Merge GeoDirectory Payment Manager invoices to the Invoicing.', 'invoicing' ); ?></p></td>
        <td><input type="button" data-tool="merge_invoices" class="button-primary wpinv-tool" value="<?php esc_attr_e( 'Run', 'invoicing' ); ?>"></td>
    </tr>
	<tr>
        <td><?php _e( 'Fix Taxes for Merged Invoices', 'invoicing' ); ?></td>
        <td><p><?php _e( 'Fix taxes for NON-PAID invoices which are merged before, from GeoDirectory Payment Manager invoices to Invoicing. This will recalculate taxes for non-paid merged invoices.', 'invoicing' ); ?></p></td>
        <td><input type="button" data-tool="merge_fix_taxes" class="button-primary wpinv-tool" value="<?php esc_attr_e( 'Run', 'invoicing' ); ?>"></td>
    </tr>
    <tr>
        <td><?php _e( 'Merge Coupons', 'invoicing' ); ?></td>
        <td><p><?php _e( 'Merge GeoDirectory Payment Manager coupons to the Invoicing.', 'invoicing' ); ?></p></td>
        <td><input type="button" data-tool="merge_coupons" class="button-primary wpinv-tool" value="<?php esc_attr_e( 'Run', 'invoicing' ); ?>"></td>
    </tr>
    <?php
}
add_action( 'wpinv_tools_row', 'wpinv_merge_gd_invoices', 10 );

function wpinv_tool_merge_packages() {
    $packages = geodir_package_list_info();
    
    $count = 0;
    
    if ( !empty( $packages ) ) {
        $success = true;
        
        foreach ( $packages as $key => $package ) {
            $item = wpinv_get_item_by('package_id', $package->pid);
            if ( !empty( $item ) ) {
                continue;
            }
            
            $merged = wpinv_merge_gd_package_to_item( $package->pid, false, $package );
            
            if ( !empty( $merged ) ) {
                wpinv_error_log( 'Package merge S : ' . $package->pid );
                $count++;
            } else {
                wpinv_error_log( 'Package merge F : ' . $package->pid );
            }
        }
        
        if ( $count > 0 ) {
            $message = sprintf( _n( 'Total <b>%d</b> price package is merged successfully.', 'Total <b>%d</b> price packages are merged successfully.', $count, 'invoicing' ), $count );
        } else {
            $message = __( 'No price packages merged.', 'invoicing' );
        }
    } else {
        $success = false;
        $message = __( 'No price packages found to merge!', 'invoicing' );
    }
    
    $response = array();
    $response['success'] = $success;
    $response['data']['message'] = $message;
    wp_send_json( $response );
}
add_action( 'wpinv_tool_merge_packages', 'wpinv_tool_merge_packages' );

function wpinv_tool_merge_invoices() {
    global $wpdb, $wpi_gdp_inv_merge, $wpi_tax_rates;
    
    $sql = "SELECT `gdi`.`id`, `gdi`.`date`, `gdi`.`date_updated` FROM `" . INVOICE_TABLE . "` AS gdi LEFT JOIN `" . $wpdb->posts . "` AS p ON `p`.`ID` = `gdi`.`invoice_id` AND `p`.`post_type` = 'wpi_invoice' WHERE `p`.`ID` IS NULL ORDER BY `gdi`.`id` ASC";
    $items = $wpdb->get_results( $sql );
    
    $count = 0;
    
    if ( !empty( $items ) ) {
        $success = true;
        $wpi_gdp_inv_merge = true;
        
        foreach ( $items as $item ) {
            $wpi_tax_rates = NULL;
            
            $wpdb->query( "UPDATE `" . INVOICE_TABLE . "` SET `invoice_id` = 0 WHERE id = '" . $item->id . "'" );
            
            $merged = wpinv_cpt_save( $item->id );
            
            if ( !empty( $merged ) && !empty( $merged->ID ) ) {
                $count++;
                
                //$wpdb->query( "UPDATE `" . INVOICE_TABLE . "` SET `invoice_id` = '" . $merged->ID . "' WHERE id = '" . $item->id . "'" );
                
                $post_date = !empty( $item->date ) && $item->date != '0000-00-00 00:00:00' ? $item->date : current_time( 'mysql' );
                $post_date_gmt = get_gmt_from_date( $post_date );
                $post_modified = !empty( $item->date_updated ) && $item->date_updated != '0000-00-00 00:00:00' ? $item->date_updated : $post_date;
                $post_modified_gmt = get_gmt_from_date( $post_modified );
                
                $wpdb->update( $wpdb->posts, array( 'post_date' => $post_date, 'post_date_gmt' => $post_date_gmt, 'post_modified' => $post_modified, 'post_modified_gmt' => $post_modified_gmt ), array( 'ID' => $merged->ID ) );
                
                if ( $merged->is_paid() ) {
                    update_post_meta( $merged->ID, '_wpinv_completed_date', $post_modified );
                }
                
                clean_post_cache( $merged->ID );
                
                wpinv_error_log( 'Invoice merge S : ' . $item->id . ' => ' . $merged->ID );
            } else {
                wpinv_error_log( 'Invoice merge F : ' . $item->id );
            }
        }
        
        $wpi_gdp_inv_merge = false;
        
        if ( $count > 0 ) {
            $message = sprintf( _n( 'Total <b>%d</b> invoice is merged successfully.', 'Total <b>%d</b> invoices are merged successfully.', $count, 'invoicing' ), $count );
        } else {
            $message = __( 'No invoices merged.', 'invoicing' );
        }
    } else {
        $success = false;
        $message = __( 'No invoices found to merge!', 'invoicing' );
    }
    
    $response = array();
    $response['success'] = $success;
    $response['data']['message'] = $message;
    wp_send_json( $response );
}
add_action( 'wpinv_tool_merge_invoices', 'wpinv_tool_merge_invoices' );

function wpinv_tool_merge_coupons() {
    global $wpdb;
    
    $sql = "SELECT * FROM `" . COUPON_TABLE . "` WHERE `coupon_code` IS NOT NULL AND `coupon_code` != '' ORDER BY `cid` ASC";
    $items = $wpdb->get_results( $sql );
    $count = 0;
    
    if ( !empty( $items ) ) {
        $success = true;
        
        foreach ( $items as $item ) {
            if ( wpinv_get_discount_by_code( $item->coupon_code ) ) {
                continue;
            }
            
            $args = array(
                'post_type'   => 'wpi_discount',
                'post_title'  => $item->coupon_code,
                'post_status' => !empty( $item->status ) ? 'publish' : 'pending'
            );

            $merged = wp_insert_post( $args );
            
            $item_id = $item->cid;
            
            if ( $merged ) {
                $meta = array(
                    'code'              => $item->coupon_code,
                    'type'              => $item->discount_type != 'per' ? 'flat' : 'percent',
                    'amount'            => (float)$item->discount_amount,
                    'max_uses'          => (int)$item->usage_limit,
                    'uses'              => (int)$item->usage_count,
                );
                wpinv_store_discount( $merged, $meta, get_post( $merged ) );
                
                $count++;
                
                wpinv_error_log( 'Coupon merge S : ' . $item_id . ' => ' . $merged );
            } else {
                wpinv_error_log( 'Coupon merge F : ' . $item_id );
            }
        }
        
        if ( $count > 0 ) {
            $message = sprintf( _n( 'Total <b>%d</b> coupon is merged successfully.', 'Total <b>%d</b> coupons are merged successfully.', $count, 'invoicing' ), $count );
        } else {
            $message = __( 'No coupons merged.', 'invoicing' );
        }
    } else {
        $success = false;
        $message = __( 'No coupons found to merge!', 'invoicing' );
    }
    
    $response = array();
    $response['success'] = $success;
    $response['data']['message'] = $message;
    wp_send_json( $response );
}
add_action( 'wpinv_tool_merge_coupons', 'wpinv_tool_merge_coupons' );

function wpinv_gdp_to_wpi_currency( $value, $option = '' ) {
    return wpinv_get_currency();
}
add_filter( 'pre_option_geodir_currency', 'wpinv_gdp_to_wpi_currency', 10, 2 );

function wpinv_gdp_to_wpi_currency_sign( $value, $option = '' ) {
    return wpinv_currency_symbol();
}
add_filter( 'pre_option_geodir_currencysym', 'wpinv_gdp_to_wpi_currency_sign', 10, 2 );

function wpinv_gdp_to_wpi_display_price( $price, $amount, $display = true , $decimal_sep, $thousand_sep ) {
    if ( !$display ) {
        $price = wpinv_format_amount( $amount, NULL, true );
    } else {
        $price = wpinv_price( wpinv_format_amount( $amount ) );
    }
    
    return $price;
}
add_filter( 'geodir_payment_price' , 'wpinv_gdp_to_wpi_display_price', 10000, 5 );

function wpinv_gdp_to_inv_checkout_redirect( $redirect_url ) {
    $invoice_id         = geodir_payment_cart_id();
    $invoice_info       = geodir_get_invoice( $invoice_id );
    $wpi_invoice        = !empty( $invoice_info->invoice_id ) ? wpinv_get_invoice( $invoice_info->invoice_id ) : NULL;
    
    if ( !( !empty( $wpi_invoice ) && !empty( $wpi_invoice->ID ) ) ) {
        $wpi_invoice_id = wpinv_cpt_save( $invoice_id );
        $wpi_invoice    = wpinv_get_invoice( $wpi_invoice_id );
    }
    
    if ( !empty( $wpi_invoice ) && !empty( $wpi_invoice->ID ) ) {
        
        // Clear cart
        geodir_payment_clear_cart();
    
        $redirect_url = $wpi_invoice->get_checkout_payment_url();
    }
    
    return $redirect_url;
}
add_filter( 'geodir_payment_checkout_redirect_url', 'wpinv_gdp_to_inv_checkout_redirect', 100, 1 );

function wpinv_gdp_dashboard_invoice_history_link( $dashboard_links ) {    
    if ( get_current_user_id() ) {        
        $dashboard_links .= '<li><i class="fa fa-shopping-cart"></i><a class="gd-invoice-link" href="' . esc_url( wpinv_get_history_page_uri() ) . '">' . __( 'My Invoice History', 'invoicing' ) . '</a></li>';
    }

    return $dashboard_links;
}
add_action( 'geodir_dashboard_links', 'wpinv_gdp_dashboard_invoice_history_link' );
remove_action( 'geodir_dashboard_links', 'geodir_payment_invoices_list_page_link' );

function wpinv_wpi_to_gdp_update_status( $invoice_id, $new_status, $old_status ) {
    if (!defined('GEODIRPAYMENT_VERSION')) {
        return false;
    }
    
    $invoice    = wpinv_get_invoice( $invoice_id );
    if ( empty( $invoice ) ) {
        return false;
    }
    
    remove_action( 'geodir_payment_invoice_status_changed', 'wpinv_payment_status_changed', 11, 4 );
    
    $invoice_id = wpinv_wpi_to_gdp_id( $invoice_id );
    $new_status = wpinv_wpi_to_gdp_status( $new_status );
    
    geodir_update_invoice_status( $invoice_id, $new_status, $invoice->is_recurring() );
}
add_action( 'wpinv_update_status', 'wpinv_wpi_to_gdp_update_status', 999, 3 );

function wpinv_gdp_to_wpi_delete_package( $gd_package_id ) {
    $item = wpinv_get_item_by( 'package_id', $gd_package_id );
    
    if ( !empty( $item ) ) {
        wpinv_remove_item( $item, true );
    }
}
add_action( 'geodir_payment_post_delete_package', 'wpinv_gdp_to_wpi_delete_package', 10, 1 ) ;

function wpinv_can_delete_package_item( $return, $post_id ) {
    if ( $return && function_exists( 'geodir_get_package_info_by_id' ) && get_post_meta( $post_id, '_wpinv_type', true ) == 'package' && $package_id = get_post_meta( $post_id, '_wpinv_package_id', true ) ) {
        $gd_package = geodir_get_package_info_by_id( $package_id, '' );
        
        if ( !empty( $gd_package ) ) {
            $return = false;
        }
    }

    return $return;
}
add_filter( 'wpinv_can_delete_item', 'wpinv_can_delete_package_item', 10, 2 );

function wpinv_package_item_classes( $classes, $class, $post_id ) {
    global $typenow;

    if ( $typenow == 'wpi_item' && in_array( 'wpi-gd-package', $classes ) ) {
        if ( wpinv_item_in_use( $post_id ) ) {
            $classes[] = 'wpi-inuse-pkg';
        } else if ( !( function_exists( 'geodir_get_package_info_by_id' ) && get_post_meta( $post_id, '_wpinv_type', true ) == 'package' && geodir_get_package_info_by_id( (int)get_post_meta( $post_id, '_wpinv_package_id', true ), '' ) ) ) {
            $classes[] = 'wpi-delete-pkg';
        }
    }

    return $classes;
}
add_filter( 'post_class', 'wpinv_package_item_classes', 10, 3 );

function wpinv_gdp_package_type_info( $post ) {
    if ( wpinv_pm_active() ) {
        ?><p class="wpi-m0"><?php _e( 'Package: GeoDirectory price packages items.', 'invoicing' );?></p>
        <?php
    }
}
add_action( 'wpinv_item_info_metabox_after', 'wpinv_gdp_package_type_info', 10, 1 ) ;

function wpinv_gdp_to_gdi_set_zero_tax( $is_taxable, $item_id, $country , $state ) {
    global $wpi_zero_tax;

    if ( $wpi_zero_tax ) {
        $is_taxable = false;
    }

    return $is_taxable;
}
add_action( 'wpinv_item_is_taxable', 'wpinv_gdp_to_gdi_set_zero_tax', 10, 4 ) ;

function wpinv_tool_merge_fix_taxes() {
    global $wpdb;
    
	$sql = "SELECT DISTINCT p.ID FROM `" . $wpdb->posts . "` AS p LEFT JOIN " . $wpdb->postmeta . " AS pm ON pm.post_id = p.ID WHERE p.post_type = 'wpi_item' AND pm.meta_key = '_wpinv_type' AND pm.meta_value = 'package'";
	$items = $wpdb->get_results( $sql );
	
	if ( !empty( $items ) ) {
		foreach ( $items as $item ) {
			if ( get_post_meta( $item->ID, '_wpinv_vat_class', true ) == '_exempt' ) {
				update_post_meta( $item->ID, '_wpinv_vat_class', '_standard' );
			}
		}
	}
		
    $sql = "SELECT `p`.`ID`, gdi.id AS gdp_id FROM `" . INVOICE_TABLE . "` AS gdi LEFT JOIN `" . $wpdb->posts . "` AS p ON `p`.`ID` = `gdi`.`invoice_id` AND `p`.`post_type` = 'wpi_invoice' WHERE `p`.`ID` IS NOT NULL AND p.post_status NOT IN( 'publish', 'wpi-processing', 'wpi-renewal' ) ORDER BY `gdi`.`id` ASC";
    $items = $wpdb->get_results( $sql );
	
	if ( !empty( $items ) ) {
		$success = false;
        $message = __( 'Taxes fixed for non-paid merged GD invoices.', 'invoicing' );
		
		global $wpi_userID, $wpinv_ip_address_country, $wpi_tax_rates;
		
		foreach ( $items as $item ) {
			$wpi_tax_rates = NULL;               
			$data = wpinv_get_invoice($item->ID);

			if ( empty( $data ) ) {
				continue;
			}
			
			$checkout_session = wpinv_get_checkout_session();
			
			$data_session                   = array();
			$data_session['invoice_id']     = $data->ID;
			$data_session['cart_discounts'] = $data->get_discounts( true );
			
			wpinv_set_checkout_session( $data_session );
			
			$wpi_userID         = (int)$data->get_user_id();
			$_POST['country']   = !empty($data->country) ? $data->country : wpinv_get_default_country();
				
			$data->country      = sanitize_text_field( $_POST['country'] );
			$data->set( 'country', sanitize_text_field( $_POST['country'] ) );
			
			$wpinv_ip_address_country = $data->country;
			
			$data->recalculate_totals(true);
			
			wpinv_set_checkout_session( $checkout_session );
			
			$update_data = array();
			$update_data['tax_amount'] = $data->get_tax();
			$update_data['paied_amount'] = $data->get_total();
			$update_data['invoice_id'] = $data->ID;
			
			$wpdb->update( INVOICE_TABLE, $update_data, array( 'id' => $item->gdp_id ) );
		}
	} else {
        $success = false;
        $message = __( 'No invoices found to fix taxes!', 'invoicing' );
    }
	
	$response = array();
    $response['success'] = $success;
    $response['data']['message'] = $message;
    wp_send_json( $response );
}
add_action( 'wpinv_tool_merge_fix_taxes', 'wpinv_tool_merge_fix_taxes' );
remove_action( 'geodir_before_detail_fields' , 'geodir_build_coupon', 2 );

function wpinv_wpi_to_gdp_handle_subscription_cancel( $invoice_id, $invoice ) {
    if ( wpinv_pm_active() && !empty( $invoice ) && $invoice->is_recurring() ) {
        if ( $invoice->is_renewal() ) {
            $invoice = $invoice->get_parent_payment();
        }
        
        if ( !empty( $invoice ) ) {
            wpinv_wpi_to_gdp_update_status( $invoice->ID, 'wpi-cancelled', $invoice->get_status() );
        }
    }
}
add_action( 'wpinv_subscription_cancelled', 'wpinv_wpi_to_gdp_handle_subscription_cancel', 10, 2 );