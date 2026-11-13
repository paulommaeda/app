<?php
defined( 'ABSPATH' ) || exit;

/** Mirrors the amount actually paid by the author, without exposing private payment details to vehicle shares. */
final class PDS_Finance_Fuel {
    public static function boot() {
        add_action( 'pds_fuel_record_created', static function ( $record ) {
            global $wpdb;
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE fuel_record_id=%d", $record['id'] ) ) ) { return; }
            $vehicle = array( 'owner_user_id' => $record['owner_user_id'], 'name' => $record['vehicle_name'] );
            $wpdb->query( 'START TRANSACTION' ); $result = self::sync( $record, $vehicle ); $wpdb->query( is_wp_error( $result ) ? 'ROLLBACK' : 'COMMIT' );
        } );
    }
    public static function sync( $record, $vehicle, $request = null ) {
        global $wpdb; $uid = (int) ( $record['created_by'] ?: $vehicle['owner_user_id'] ); if ( ! get_userdata( $uid ) ) { return true; }
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expenses WHERE fuel_record_id=%d FOR UPDATE", $record['id'] ), ARRAY_A );
        if ( $old ) { $uid = (int) $old['user_id']; }
        $category = $old ? (int) $old['category_id'] : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expense_categories WHERE user_id=%d AND slug='combustivel'", $uid ) );
        if ( ! $category ) {
            $ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_expense_categories (user_id,name,slug,color,active,created_at) VALUES (%d,'Combustível','combustivel','#5686A5',1,%s)", $uid, current_time( 'mysql', true ) ) );
            if ( false === $ok ) { return PDS_Finance::error( 'Não foi possível preparar a categoria do abastecimento.', 500 ); }
            $category = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expense_categories WHERE user_id=%d AND slug='combustivel'", $uid ) );
        }
        $payment = array();
        if ( $request ) { foreach ( array( 'payment_method', 'account_id', 'card_id', 'installments', 'person_id', 'first_invoice', 'person_id' ) as $key ) { if ( $request->has_param( $key ) ) { $payment[ $key ] = $request->get_param( $key ); } } }
        if ( $payment && ( $uid !== get_current_user_id() || ! pds_user_can_access_service( 'gastos', $uid ) ) ) { return PDS_Finance::error( 'Somente quem registrou pode alterar o pagamento particular desse abastecimento.', 403 ); }
        $pay = $payment['payment_method'] ?? ( $old['payment_method'] ?? '' ); if ( ! is_string( $pay ) || ! array_key_exists( $pay, PDS_Expenses::PAYMENTS ) ) { return PDS_Finance::error( 'Confira a forma de pagamento do abastecimento.' ); }
        $r = new WP_REST_Request( 'POST' ); $r->set_body_params( array_merge( $payment, array( 'payment_method' => $pay, 'amount' => $record['paid'], 'spent_on' => $record['filled_on'] ) ) );
        $finance = PDS_Finance::prepare( $r, $old, array(), $uid ); if ( is_wp_error( $finance ) ) { return $finance; }
        $data = array( 'user_id' => $uid, 'category_id' => $category, 'spent_on' => $record['filled_on'], 'description' => mb_substr( 'Abastecimento — ' . sanitize_text_field( $vehicle['name'] ) . ' — ' . ( array( 'G' => 'Gasolina', 'A' => 'Etanol', 'D' => 'Diesel' )[ $record['fuel_type'] ] ?? 'Combustível' ), 0, 180 ), 'amount' => number_format( (float) $record['paid'], 2, '.', '' ), 'payment_method' => $pay, 'merchant' => $old['merchant'] ?? '', 'notes' => $old['notes'] ?? ( $record['notes'] ?? '' ), 'source' => 'fuel', 'fuel_record_id' => $record['id'], 'updated_at' => current_time( 'mysql', true ) );
        foreach ( $finance as $key => $value ) { if ( substr( $key, 0, 1 ) !== '_' ) { $data[ $key ] = $value; } }
        $ok = $old ? $wpdb->update( $wpdb->prefix . 'pds_expenses', $data, array( 'id' => $old['id'], 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_expenses', $data + array( 'created_at' => current_time( 'mysql', true ) ) ); $id = $old ? (int) $old['id'] : (int) $wpdb->insert_id;
        if ( false === $ok ) { return PDS_Finance::error( 'Não foi possível integrar o abastecimento aos gastos.', 500 ); }
        return PDS_Finance::save_schedule( $id, $uid, $finance, $old, $data['amount'], $data['spent_on'] );
    }
    public static function remove( $record_id ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT id,user_id FROM {$wpdb->prefix}pds_expenses WHERE fuel_record_id=%d FOR UPDATE", $record_id ), ARRAY_A ); if ( ! $row ) { return true; }
        $ok = PDS_Finance::remove_expense( $row['id'], $row['user_id'] ); if ( is_wp_error( $ok ) ) { return $ok; }
        return false === $wpdb->delete( $wpdb->prefix . 'pds_expenses', array( 'id' => $row['id'], 'user_id' => $row['user_id'] ) ) ? PDS_Finance::error( 'Não foi possível remover o gasto do abastecimento.', 500 ) : true;
    }
    public static function details( $record_id ) {
        global $wpdb; if ( ! pds_user_can_access_service( 'gastos' ) ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT payment_method,account_id,card_id,installments,first_invoice,person_id FROM {$wpdb->prefix}pds_expenses WHERE fuel_record_id=%d AND user_id=%d", $record_id, get_current_user_id() ), ARRAY_A );
        if ( $row ) { foreach ( array( 'account_id', 'card_id', 'installments', 'person_id' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; } } return $row;
    }
}
