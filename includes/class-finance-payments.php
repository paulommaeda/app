<?php
defined( 'ABSPATH' ) || exit;

/** Date-only corrections retain the original financial identities and amounts. */
final class PDS_Finance_Payments {
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'pds/v1', '/finance/payments/(?P<id>\d+)', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'invoice_date' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ) );
            register_rest_route( 'pds/v1', '/finance/payables/(?P<id>\d+)/payment-date', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'payable_date' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ) );
        } );
    }

    private static function date( $r ) {
        $date = $r->get_param( 'paid_on' );
        return ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ? PDS_Finance::error( 'Informe a data em que o pagamento foi feito, até hoje.' ) : $date;
    }

    private static function fail( $message, $status = 500 ) {
        global $wpdb; $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( $message, $status );
    }

    public static function invoice_date( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $date = self::date( $r ); if ( is_wp_error( $date ) ) { return $date; }
        $old = PDS_Finance::find( 'bill_payments', absint( $r->get_url_params()['id'] ?? 0 ) ); if ( is_wp_error( $old ) ) { return $old; }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar a correção.', 500 ); }
        $invoice = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d AND user_id=%d FOR UPDATE", $old['invoice_id'], $uid ) );
        if ( ! $invoice || $wpdb->last_error ) { return self::fail( 'Fatura não encontrada ou em atualização.', 409 ); }
        $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_bill_payments WHERE id=%d AND user_id=%d FOR UPDATE", $old['id'], $uid ), ARRAY_A );
        if ( ! $payment || $wpdb->last_error || (int) $payment['invoice_id'] !== (int) $invoice ) { return self::fail( 'Pagamento não encontrado ou em atualização.', 409 ); }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_bill_payments', array( 'paid_on' => $date ), array( 'id' => $payment['id'], 'user_id' => $uid ) );
        if ( false === $ok ) { return self::fail( 'Não foi possível corrigir a data do pagamento.' ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::fail( 'Não foi possível confirmar a correção da data.' ); }
        return array( 'id' => (int) $payment['id'], 'paid_on' => $date );
    }

    public static function payable_date( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $date = self::date( $r ); if ( is_wp_error( $date ) ) { return $date; }
        $id = absint( $r->get_url_params()['id'] ?? 0 );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar a correção.', 500 ); }
        $bill = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_payables WHERE id=%d AND user_id=%d FOR UPDATE", $id, $uid ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::fail( 'Não foi possível conferir a conta.' ); }
        if ( ! $bill ) { return self::fail( 'Conta não encontrada ou sem acesso.', 404 ); }
        if ( 'paid' !== $bill['status'] || ! $bill['expense_id'] ) { return self::fail( 'Registre o pagamento antes de corrigir a data da baixa.', 409 ); }
        $expense = $wpdb->get_row( $wpdb->prepare( "SELECT id,payable_id,spent_on FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $bill['expense_id'], $uid ), ARRAY_A );
        if ( ! $expense || $wpdb->last_error || (int) $expense['payable_id'] !== $id ) { return self::fail( 'Não foi possível conferir o gasto desta baixa.', 409 ); }
        // Date-only: the amount, bank/card and confirmed invoice allocation remain intact.
        $now = current_time( 'mysql', true );
        $ok = $wpdb->update( $wpdb->prefix . 'pds_expenses', array( 'spent_on' => $date, 'updated_at' => $now ), array( 'id' => $expense['id'], 'user_id' => $uid, 'payable_id' => $id ) );
        if ( false === $ok || false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'updated_at' => $now ), array( 'id' => $id, 'user_id' => $uid ) ) ) { return self::fail( 'Não foi possível corrigir a data da baixa.' ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::fail( 'Não foi possível confirmar a correção da baixa.' ); }
        $entry = PDS_Expenses::get_entry( $expense['id'] ); if ( ! is_wp_error( $entry ) ) { do_action( 'pds_expense_updated', $entry, $uid ); }
        return array( 'id' => $id, 'expense_id' => (int) $expense['id'], 'paid_on' => $date );
    }
}
