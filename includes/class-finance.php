<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Finance {
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_style( 'pds-finance', PDS_URL . 'assets/finance.css', array( 'pds-expenses' ), PDS_VERSION ); wp_enqueue_script( 'pds-finance', PDS_URL . 'assets/finance.js', array( 'pds-expenses' ), PDS_VERSION, true ); } );
    }
    public static function routes() {
        foreach ( array(
            '/finance/accounts' => array( 'GET' => 'accounts', 'POST' => 'save_account' ), '/finance/accounts/(?P<id>\d+)' => array( 'PUT' => 'save_account', 'DELETE' => 'archive_account' ),
            '/finance/receipts' => array( 'GET' => 'receipts', 'POST' => 'save_receipt' ), '/finance/receipts/(?P<id>\d+)' => array( 'PUT' => 'save_receipt', 'DELETE' => 'delete_receipt' ),
            '/finance/cards' => array( 'GET' => 'cards', 'POST' => 'save_card' ), '/finance/cards/(?P<id>\d+)' => array( 'PUT' => 'save_card', 'DELETE' => 'archive_card' ),
            '/finance/plan' => array( 'POST' => 'plan_request' ), '/finance/invoices' => array( 'GET' => 'invoices' ), '/finance/invoices/(?P<id>\d+)' => array( 'GET' => 'invoice', 'PUT' => 'update_invoice' ),
            '/finance/invoices/(?P<id>\d+)/payments' => array( 'POST' => 'pay_invoice' ), '/finance/payments/(?P<id>\d+)' => array( 'DELETE' => 'delete_payment' ),
            '/finance/invoices/(?P<id>\d+)/adjustments' => array( 'POST' => 'save_adjustment' ), '/finance/adjustments/(?P<id>\d+)' => array( 'DELETE' => 'delete_adjustment' ), '/finance/overview' => array( 'GET' => 'overview' ),
        ) as $route => $methods ) { $list = array(); foreach ( $methods as $method => $callback ) { $list[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ); } register_rest_route( 'pds/v1', $route, $list ); }
    }
    public static function error( $m, $s = 400 ) { return PDS_Expenses::error( $m, $s ); }
    public static function find( $kind, $id, $uid = 0 ) {
        if ( ! in_array( $kind, array( 'accounts', 'cards', 'receipts', 'invoices', 'bill_payments', 'adjustments' ), true ) ) { return self::error( 'Recurso inválido.' ); }
        global $wpdb; $uid = $uid ?: get_current_user_id(); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_{$kind} WHERE id=%d AND user_id=%d", $id, $uid ), ARRAY_A );
        return $row ?: self::error( 'Registro financeiro não encontrado ou sem acesso.', 404 );
    }
    public static function cents( $value, $signed = false ) {
        if ( ( is_string( $value ) && substr( trim( $value ), 0, 1 ) === '-' ) || ( is_numeric( $value ) && (float) $value < 0 ) ) { if ( ! $signed ) { return self::error( 'Informe um valor positivo.' ); } $v = self::cents( is_string( $value ) ? substr( trim( $value ), 1 ) : -$value ); return is_wp_error( $v ) ? $v : -$v; }
        if ( in_array( $value, array( 0, 0.0, '0', '0.00', '0,00' ), true ) ) { return 0; }
        $amount = PDS_Expenses::amount( $value ); if ( is_wp_error( $amount ) ) { return $amount; } $a = explode( '.', $amount ); return (int) $a[0] * 100 + (int) $a[1];
    }
    public static function decimal( $cents ) { return ( $cents < 0 ? '-' : '' ) . intdiv( abs( $cents ), 100 ) . '.' . str_pad( (string) ( abs( $cents ) % 100 ), 2, '0', STR_PAD_LEFT ); }
    public static function month( $v ) { return is_string( $v ) && preg_match( '/^(19|2[0-9])[0-9]{2}-(0[1-9]|1[0-2])$/D', $v ); }
    public static function add_month( $month, $n ) { return ( new DateTimeImmutable( $month . '-01', new DateTimeZone( 'UTC' ) ) )->modify( ( $n >= 0 ? '+' : '' ) . $n . ' months' )->format( 'Y-m' ); }
    public static function day( $month, $day ) { $first = new DateTimeImmutable( $month . '-01', new DateTimeZone( 'UTC' ) ); return $month . '-' . str_pad( (string) min( (int) $day, (int) $first->format( 't' ) ), 2, '0', STR_PAD_LEFT ); }
    public static function cycle( $month, $card ) {
        $due = self::day( $month, $card['due_day'] ); $close = self::day( $month, $card['closing_day'] ); if ( (int) $card['closing_day'] >= (int) $card['due_day'] ) { $close = self::day( self::add_month( $month, -1 ), $card['closing_day'] ); }
        return array( 'invoice_month' => $month, 'closing_on' => $close, 'due_on' => $due );
    }
    public static function billing_cycle( $month, $card ) {
        global $wpdb; if ( ! empty( $card['id'] ) && ! empty( $card['user_id'] ) ) { $stored = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_month,closing_on,due_on FROM {$wpdb->prefix}pds_fin_invoices WHERE card_id=%d AND user_id=%d AND invoice_month=%s", $card['id'], $card['user_id'], $month ), ARRAY_A ); if ( $stored ) { return $stored; } } return self::cycle( $month, $card );
    }
    public static function first_month( $date, $card ) {
        $month = substr( $date, 0, 7 );
        for ( $i = 0; $i < 3; $i++ ) { $candidate = self::add_month( $month, $i ); $cycle = self::billing_cycle( $candidate, $card ); if ( $date < $cycle['closing_on'] || ( $date === $cycle['closing_on'] && ! $card['on_close_next'] ) ) { return $candidate; } }
        return self::add_month( $month, 3 );
    }
    private static function text( $request, $key, $max, $required = false ) { $v = $request->get_param( $key ) ?? ''; if ( ! is_string( $v ) ) { return self::error( 'Confira o campo ' . $key . '.' ); } $v = sanitize_text_field( trim( $v ) ); return mb_strlen( $v ) > $max || ( $required && mb_strlen( $v ) < 2 ) ? self::error( 'Confira o tamanho do campo ' . $key . '.' ) : $v; }
    private static function id( $r ) { return absint( $r->get_url_params()['id'] ?? 0 ); }
    private static function active( $r, $old ) { $v = $r->get_param( 'active' ); return null === $v ? (int) ( $old['active'] ?? 1 ) : ( is_bool( $v ) ? (int) $v : self::error( 'Informe um estado válido.' ) ); }
    private static function limit( $table ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_{$table} WHERE user_id=%d", get_current_user_id() ) ) < 100; }
    public static function accounts() {
        global $wpdb; $uid = get_current_user_id(); PDS_Finance_Account_Types::seed();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.*,t.name AS kind_name,t.active AS kind_active FROM {$wpdb->prefix}pds_fin_accounts a LEFT JOIN {$wpdb->prefix}pds_fin_account_types t ON t.user_id=a.user_id AND t.code=a.kind WHERE a.user_id=%d ORDER BY a.active DESC,a.name,a.id", $uid ), ARRAY_A );
        foreach ( $rows as &$a ) { $a['id'] = (int) $a['id']; $a['active'] = (bool) $a['active']; $a['opening_balance'] = (float) $a['opening_balance']; $a['balance'] = self::account_balance( $a ); unset( $a['user_id'] ); } unset( $a ); return array( 'accounts' => $rows );
    }
    public static function account_balance( $a, $as_of = '' ) {
        global $wpdb; $as_of = $as_of ?: current_time( 'Y-m-d' ); $uid = (int) ( $a['user_id'] ?? get_current_user_id() ); if ( $as_of < $a['opening_on'] ) { return null; }
        $income = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_receipts WHERE user_id=%d AND account_id=%d AND status='received' AND received_on BETWEEN %s AND %s", $uid, $a['id'], $a['opening_on'], $as_of ) );
        $out = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_expenses WHERE user_id=%d AND account_id=%d AND card_id=0 AND payment_method<>'credit' AND spent_on BETWEEN %s AND %s", $uid, $a['id'], $a['opening_on'], $as_of ) );
        $paid = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_bill_payments WHERE user_id=%d AND account_id=%d AND paid_on BETWEEN %s AND %s", $uid, $a['id'], $a['opening_on'], $as_of ) );
        $transfers = PDS_Finance_Transfers::balance_delta( $a, $as_of );
        return round( (float) $a['opening_balance'] + (float) $income - (float) $out - (float) $paid + $transfers, 2 );
    }
    public static function save_account( WP_REST_Request $r ) {
        global $wpdb; $id = self::id( $r ); $old = $id ? self::find( 'accounts', $id ) : array(); if ( is_wp_error( $old ) ) { return $old; } PDS_Finance_Account_Types::seed();
        $name = self::text( $r, 'name', 80, true ); $opening = self::cents( $r->get_param( 'opening_balance' ) ?? '0', true ); $date = $r->get_param( 'opening_on' ); $kind = $r->get_param( 'kind' ) ?? 'checking'; $active = self::active( $r, $old );
        foreach ( array( $name, $opening, $active ) as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
        if ( ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return self::error( 'Confira a data do saldo inicial.' ); }
        if ( ! $id && ! self::limit( 'accounts' ) ) { return self::error( 'Limite de 100 contas por usuário.' ); }
        $data = array( 'name' => $name, 'opening_balance' => self::decimal( $opening ), 'opening_on' => $date, 'kind' => $kind, 'active' => $active );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'Não foi possível iniciar o cadastro da conta.', 500 ); }
        if ( $id ) {
            $locked = $wpdb->get_row( $wpdb->prepare( "SELECT id,kind FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $id, get_current_user_id() ), ARRAY_A );
            if ( ! $locked || $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Conta não encontrada ou em atualização.', 409 ); }
        }
        $type_check = PDS_Finance_Account_Types::validate( $kind, $id ? $locked['kind'] : '' );
        if ( is_wp_error( $type_check ) ) { $wpdb->query( 'ROLLBACK' ); return $type_check; }
        if ( $id ) {
            $check = PDS_Finance_Transfers::check_account_date( $id, get_current_user_id(), $date );
            if ( is_wp_error( $check ) ) { $wpdb->query( 'ROLLBACK' ); return $check; }
            $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_accounts', $data, array( 'id' => $id, 'user_id' => get_current_user_id() ) );
            if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível salvar a conta.', 500 ); }
        } else {
            $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_accounts', $data + array( 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
        }
        $saved_id = $id ?: (int) $wpdb->insert_id;
        if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível salvar a conta.', 500 ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível confirmar o cadastro da conta.', 500 ); }
        return array( 'id' => $saved_id );
    }
    public static function archive_account( WP_REST_Request $r ) { return self::archive( 'accounts', self::id( $r ) ); }
    public static function archive_card( WP_REST_Request $r ) { return self::archive( 'cards', self::id( $r ) ); }
    private static function archive( $kind, $id ) { global $wpdb; $row = self::find( $kind, $id ); if ( is_wp_error( $row ) ) { return $row; } return false === $wpdb->update( $wpdb->prefix . 'pds_fin_' . $kind, array( 'active' => 0 ), array( 'id' => $id, 'user_id' => get_current_user_id() ) ) ? self::error( 'Não foi possível arquivar.', 500 ) : array( 'success' => true ); }
    public static function cards() {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_cards WHERE user_id=%d ORDER BY active DESC,name,id", get_current_user_id() ), ARRAY_A );
        foreach ( $rows as &$c ) { $stats = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(s.amount),0) AS amount FROM {$wpdb->prefix}pds_fin_installments s WHERE s.card_id=%d AND s.user_id=%d", $c['id'], get_current_user_id() ), ARRAY_A );
            $adjust = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(a.amount),0) FROM {$wpdb->prefix}pds_fin_adjustments a INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=a.invoice_id WHERE i.card_id=%d AND a.user_id=%d", $c['id'], get_current_user_id() ) );
            $paid = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(p.amount),0) FROM {$wpdb->prefix}pds_fin_bill_payments p INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=p.invoice_id WHERE i.card_id=%d AND p.user_id=%d", $c['id'], get_current_user_id() ) );
            $c['id'] = (int) $c['id']; $c['active'] = (bool) $c['active']; $c['on_close_next'] = (bool) $c['on_close_next']; $c['closing_day'] = (int) $c['closing_day']; $c['due_day'] = (int) $c['due_day']; $c['credit_limit'] = (float) $c['credit_limit']; $c['committed'] = round( max( 0, (float) $stats['amount'] + $adjust - $paid ), 2 ); $c['available'] = $c['credit_limit'] > 0 ? round( $c['credit_limit'] - $c['committed'], 2 ) : null; unset( $c['user_id'] );
        } unset( $c ); return array( 'cards' => $rows );
    }
    public static function save_card( WP_REST_Request $r ) {
        global $wpdb; $id = self::id( $r ); $old = $id ? self::find( 'cards', $id ) : array(); if ( is_wp_error( $old ) ) { return $old; }
        $name = self::text( $r, 'name', 80, true ); $last = self::text( $r, 'last_four', 4 ); $limit = self::cents( $r->get_param( 'credit_limit' ) ?? '0' ); $active = self::active( $r, $old ); $on_close = $r->get_param( 'on_close_next' ) ?? true;
        foreach ( array( $name, $last, $limit, $active ) as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
        $closing = $r->get_param( 'closing_day' ); $due = $r->get_param( 'due_day' );
        if ( ( $last && ! preg_match( '/^[0-9]{4}$/D', $last ) ) || ! is_bool( $on_close ) || ! is_scalar( $closing ) || ! is_scalar( $due ) || ! preg_match( '/^[0-9]{1,2}$/D', (string) $closing ) || ! preg_match( '/^[0-9]{1,2}$/D', (string) $due ) || min( (int) $closing, (int) $due ) < 1 || max( (int) $closing, (int) $due ) > 31 ) { return self::error( 'Use dias de 1 a 31 e, se informar, apenas os quatro últimos dígitos do cartão.' ); }
        if ( ! $id && ! self::limit( 'cards' ) ) { return self::error( 'Limite de 100 cartões por usuário.' ); }
        $data = array( 'name' => $name, 'last_four' => $last, 'credit_limit' => self::decimal( $limit ), 'closing_day' => (int) $closing, 'due_day' => (int) $due, 'on_close_next' => (int) $on_close, 'active' => $active );
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_fin_cards', $data, array( 'id' => $id, 'user_id' => get_current_user_id() ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_cards', $data + array( 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
        return false === $ok ? self::error( 'Não foi possível salvar o cartão.', 500 ) : array( 'id' => $id ?: (int) $wpdb->insert_id );
    }
    public static function receipts( WP_REST_Request $r ) {
        global $wpdb; $from = $r->get_param( 'from' ) ?? current_time( 'Y-01-01' ); $to = $r->get_param( 'to' ) ?? current_time( 'Y-12-31' ); if ( ! PDS_Expenses::valid_date( $from ) || ! PDS_Expenses::valid_date( $to ) || $from > $to ) { return self::error( 'Confira o período dos recebimentos.' ); }
        PDS_Finance_Planning::sync_user( get_current_user_id(), substr( $to, 0, 7 ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.*,a.name AS account_name FROM {$wpdb->prefix}pds_fin_receipts r INNER JOIN {$wpdb->prefix}pds_fin_accounts a ON a.id=r.account_id AND a.user_id=r.user_id WHERE r.user_id=%d AND r.received_on BETWEEN %s AND %s ORDER BY r.received_on DESC,r.id DESC LIMIT 1000", get_current_user_id(), $from, $to ), ARRAY_A );
        foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['account_id'] = (int) $row['account_id']; $row['recurring_id'] = $row['recurring_id'] ? (int) $row['recurring_id'] : null; $row['amount'] = (float) $row['amount']; unset( $row['user_id'], $row['external_ref'] ); } unset( $row ); return array( 'receipts' => $rows );
    }
    public static function save_receipt( WP_REST_Request $r, $external_ref = null ) {
        $allowed = PDS_Expenses::permission(); if ( is_wp_error( $allowed ) ) { return $allowed; }
        global $wpdb; $uid = get_current_user_id();
        if ( null !== $external_ref ) {
            if ( ! is_string( $external_ref ) || ! preg_match( '/^telegram-receipt:[a-f0-9]{64}$/D', $external_ref ) ) { return self::error( 'Origem de recebimento inválida.' ); }
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_receipts WHERE user_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $existing ) { return array( 'id' => (int) $existing, 'already_saved' => true ); }
        }
        $id = self::id( $r ); $old = $id ? self::find( 'receipts', $id ) : array(); if ( is_wp_error( $old ) ) { return $old; }
        $description = self::text( $r, 'description', 180, true ); $notes = self::text( $r, 'notes', 500 ); $amount = PDS_Expenses::amount( $r->get_param( 'amount' ) ); foreach ( array( $description, $notes, $amount ) as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
        $account_id = $r->get_param( 'account_id' ); if ( ( ! is_int( $account_id ) && ! is_string( $account_id ) ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $account_id ) ) { return self::error( 'Escolha uma conta.' ); } $account = self::find( 'accounts', absint( $account_id ) ); if ( is_wp_error( $account ) ) { return $account; }
        if ( ! $account['active'] && ( ! $id || (int) $old['account_id'] !== (int) $account_id ) ) { return self::error( 'Escolha uma conta ativa.' ); }
        $date = $r->get_param( 'received_on' ); $status = $r->get_param( 'status' ) ?? 'received'; if ( ! PDS_Expenses::valid_date( $date ) || ! in_array( $status, array( 'received', 'pending', 'canceled' ), true ) || ( 'received' === $status && $date > current_time( 'Y-m-d' ) ) ) { return self::error( 'Recebimentos futuros devem ficar como A receber. Confira a data.' ); }
        $data = array( 'account_id' => (int) $account_id, 'description' => $description, 'notes' => $notes, 'amount' => $amount, 'received_on' => $date, 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
        $wpdb->query( 'START TRANSACTION' );
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $account_id, $uid ), ARRAY_A );
        if ( ! $locked || ( ! $locked['active'] && ( ! $id || (int) $old['account_id'] !== (int) $account_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'A conta mudou. Escolha uma conta ativa e tente novamente.' ); }
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_fin_receipts', $data, array( 'id' => $id, 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_receipts', $data + array( 'user_id' => $uid, 'created_at' => current_time( 'mysql', true ), 'source' => $external_ref ? 'telegram' : 'manual', 'external_ref' => $external_ref ) );
        $saved = $id ?: (int) $wpdb->insert_id;
        if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); if ( $external_ref ) { $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_receipts WHERE user_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $duplicate ) { return array( 'id' => (int) $duplicate, 'already_saved' => true ); } } return self::error( 'Não foi possível salvar o recebimento.', 500 ); }
        $wpdb->query( 'COMMIT' ); return array( 'id' => $saved );
    }
    public static function delete_receipt( WP_REST_Request $r ) { global $wpdb; $row = self::find( 'receipts', self::id( $r ) ); if ( is_wp_error( $row ) ) { return $row; } $where = array( 'id' => $row['id'], 'user_id' => get_current_user_id() ); $ok = ! empty( $row['recurring_id'] ) && 'received' !== $row['status'] ? $wpdb->update( $wpdb->prefix . 'pds_fin_receipts', array( 'status' => 'canceled' ), $where ) : $wpdb->delete( $wpdb->prefix . 'pds_fin_receipts', $where ); return false === $ok ? self::error( 'Não foi possível excluir.', 500 ) : array( 'success' => true ); }
    public static function plan( $card, $date, $cents, $count, $first = '', $start = 1 ) {
        if ( ! PDS_Expenses::valid_date( $date ) || $cents < 1 || $count < 1 || $count > 120 || $start < 1 || $start > $count || $cents < $count - $start + 1 ) { return self::error( 'Confira a compra: 1 a 120 parcelas, com pelo menos um centavo por parcela.' ); }
        if ( $first && ! self::month( $first ) ) { return self::error( 'Use AAAA-MM para a fatura inicial.' ); } $month = $first ?: self::first_month( $date, $card ); if ( ! self::month( self::add_month( $month, $count - $start ) ) ) { return self::error( 'O parcelamento ultrapassa o período permitido.' ); } $remaining = $count - $start + 1; $base = intdiv( $cents, $remaining ); $remainder = $cents % $remaining; $rows = array();
        for ( $i = 0; $i < $remaining; $i++ ) { $rows[] = self::billing_cycle( self::add_month( $month, $i ), $card ) + array( 'installment_no' => $start + $i, 'installment_total' => $count, 'amount' => ( $base + ( $i < $remainder ? 1 : 0 ) ) / 100 ); }
        return array( 'first_invoice' => $month, 'installments' => $rows, 'total' => $cents / 100 );
    }
    public static function plan_request( WP_REST_Request $r ) {
        $value = $r->get_param( 'card_id' ); $card = self::find( 'cards', is_scalar( $value ) ? absint( $value ) : 0 ); if ( is_wp_error( $card ) ) { return $card; }
        $cents = self::cents( $r->get_param( 'amount' ) ); if ( is_wp_error( $cents ) ) { return $cents; }
        $n = $r->get_param( 'installments' ) ?? 1; if ( ! is_scalar( $n ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $n ) ) { return self::error( 'Informe o número de parcelas.' ); }
        $start = 1; $expense_id = $r->get_param( 'expense_id' ); if ( $expense_id ) { if ( ! is_scalar( $expense_id ) ) { return self::error( 'Compra inválida.' ); } $old = PDS_Expenses::get_entry( absint( $expense_id ) ); if ( is_wp_error( $old ) ) { return $old; } $start = $old['installment_start']; }
        return self::plan( $card, $r->get_param( 'spent_on' ), $cents, (int) $n, is_string( $r->get_param( 'first_invoice' ) ) ? $r->get_param( 'first_invoice' ) : '', $start );
    }
    public static function prepare( WP_REST_Request $r, $old = null, $options = array(), $uid = 0 ) {
        $uid = $uid ?: get_current_user_id(); $payment = $r->get_param( 'payment_method' ) ?? ( $old['payment_method'] ?? '' );
        $card_id = $r->get_param( 'card_id' ) ?? ( $old['card_id'] ?? 0 ); $account_id = $r->get_param( 'account_id' ) ?? ( $old['account_id'] ?? 0 ); $n = $r->get_param( 'installments' ) ?? ( $old['installments'] ?? 1 );
        foreach ( array( $card_id, $account_id, $n ) as $v ) { if ( ! is_scalar( $v ) || is_bool( $v ) || ! preg_match( '/^[0-9]{0,18}$/D', (string) $v ) ) { return self::error( 'Confira conta, cartão e número de parcelas.' ); } }
        $card_id = absint( $card_id ); $account_id = absint( $account_id ); $n = (int) $n;
        if ( 'credit' === $payment && ( $n < 1 || $n > 120 ) ) { return self::error( 'Informe de 1 a 120 parcelas.' ); }
        if ( 'credit' !== $payment ) { $card_id = 0; $n = 1; }
        if ( 'credit' === $payment && ! $card_id && ! ( $old && 'credit' === $old['payment_method'] && ! $old['card_id'] && $n === 1 ) ) { return self::error( 'Escolha o cartão de crédito para gerar a fatura.' ); }
        $result = array( 'account_id' => 0, 'card_id' => 0, 'installments' => 1, 'installment_start' => 1, 'first_invoice' => '', 'installment_value' => null );
        if ( $card_id ) { $card = self::find( 'cards', $card_id, $uid ); if ( is_wp_error( $card ) ) { return $card; } if ( ! $card['active'] && ( ! $old || (int) $old['card_id'] !== $card_id ) ) { return self::error( 'Escolha um cartão ativo.' ); }
            $cents = self::cents( $r->get_param( 'amount' ) ); if ( is_wp_error( $cents ) ) { return $cents; } $date = $r->get_param( 'spent_on' ); $first = $r->get_param( 'first_invoice' ); if ( null !== $first && ! is_string( $first ) ) { return self::error( 'Confira a fatura inicial.' ); }
            if ( ! $first && $old && (int) $old['card_id'] === $card_id && $old['spent_on'] === $date ) { $first = $old['first_invoice']; }
            $start = (int) ( $options['installment_start'] ?? ( $old['installment_start'] ?? 1 ) ); $plan = self::plan( $card, $date, $cents, $n, $first ?: '', $start ); if ( is_wp_error( $plan ) ) { return $plan; }
            $result = array_replace( $result, array( 'card_id' => $card_id, 'installments' => $n, 'installment_start' => $start, 'first_invoice' => $plan['first_invoice'], 'installment_value' => $options['installment_value'] ?? ( $old['installment_value'] ?? null ), '_plan' => $plan, '_card' => $card ) );
        } elseif ( $account_id ) { $account = self::find( 'accounts', $account_id, $uid ); if ( is_wp_error( $account ) ) { return $account; } if ( ! $account['active'] && ( ! $old || (int) $old['account_id'] !== $account_id ) ) { return self::error( 'Escolha uma conta ativa.' ); } $result['account_id'] = $account_id; }
        return $result;
    }
    public static function ensure_invoice( $card, $month ) {
        global $wpdb; $cycle = self::cycle( $month, $card );
        $ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_fin_invoices (user_id,card_id,invoice_month,closing_on,due_on) VALUES (%d,%d,%s,%s,%s)", $card['user_id'], $card['id'], $month, $cycle['closing_on'], $cycle['due_on'] ) );
        if ( false === $ok ) { return self::error( 'Não foi possível gerar a fatura.', 500 ); }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE card_id=%d AND user_id=%d AND invoice_month=%s", $card['id'], $card['user_id'], $month ) );
    }
    public static function paid_expense( $id ) { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT s.id FROM {$wpdb->prefix}pds_fin_installments s INNER JOIN {$wpdb->prefix}pds_fin_bill_payments p ON p.invoice_id=s.invoice_id WHERE s.expense_id=%d LIMIT 1", $id ) ); }
    public static function save_schedule( $id, $uid, $finance, $old, $amount, $date, $options = array() ) {
        global $wpdb;
        $same = $old && (int) $old['card_id'] === (int) $finance['card_id'] && (int) $old['installments'] === (int) $finance['installments'] && (int) $old['installment_start'] === (int) $finance['installment_start'] && $old['first_invoice'] === $finance['first_invoice'] && abs( (float) $old['amount'] - (float) $amount ) < 0.000001 && $old['spent_on'] === $date;
        if ( $same ) { return true; }
        if ( ! self::lock_expense_invoices( $id ) ) { return self::error( 'A fatura está sendo atualizada. Tente novamente.', 409 ); }
        if ( self::paid_expense( $id ) ) { return self::error( 'Essa compra pertence a uma fatura com pagamento registrado. Remova/corrija o pagamento antes de alterar valor, cartão ou parcelamento.', 409 ); }
        if ( false === $wpdb->delete( $wpdb->prefix . 'pds_fin_installments', array( 'expense_id' => $id, 'user_id' => $uid ) ) ) { return self::error( 'Não foi possível atualizar as parcelas.', 500 ); }
        if ( ! $finance['card_id'] ) { return true; }
        foreach ( $finance['_plan']['installments'] as $part ) {
            $invoice = self::ensure_invoice( $finance['_card'], $part['invoice_month'] ); if ( is_wp_error( $invoice ) ) { return $invoice; }
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d FOR UPDATE", $invoice ) );
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_bill_payments WHERE invoice_id=%d LIMIT 1", $invoice ) ) ) { return self::error( 'A fatura de destino já tem pagamento registrado. Confira a fatura inicial.', 409 ); }
            $is_imported = isset( $options['bank_ref'] ) && (int) $part['installment_no'] === (int) $finance['installment_start'];
            $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_installments', array( 'user_id' => $uid, 'expense_id' => $id, 'card_id' => $finance['card_id'], 'invoice_id' => $invoice, 'installment_no' => $part['installment_no'], 'installment_total' => $part['installment_total'], 'amount' => number_format( $part['amount'], 2, '.', '' ), 'confirmed' => $is_imported ? 1 : 0, 'bank_ref' => $is_imported ? $options['bank_ref'] : null ) ); if ( false === $ok ) { return self::error( 'Não foi possível gerar todas as parcelas.', 500 ); }
        }
        return true;
    }
    private static function lock_expense_invoices( $id ) { global $wpdb; $wpdb->get_col( $wpdb->prepare( "SELECT i.id FROM {$wpdb->prefix}pds_fin_invoices i INNER JOIN {$wpdb->prefix}pds_fin_installments s ON s.invoice_id=i.id WHERE s.expense_id=%d ORDER BY i.id FOR UPDATE", $id ) ); return ! $wpdb->last_error; }
    public static function remove_expense( $id, $uid ) { global $wpdb; if ( ! self::lock_expense_invoices( $id ) ) { return self::error( 'A fatura está sendo atualizada. Tente novamente.', 409 ); } if ( self::paid_expense( $id ) ) { return self::error( 'Há pagamento de fatura ligado a essa compra. Corrija/remova o pagamento antes de excluir a compra.', 409 ); } return false === $wpdb->delete( $wpdb->prefix . 'pds_fin_installments', array( 'expense_id' => $id, 'user_id' => $uid ) ) ? self::error( 'Não foi possível excluir as parcelas.', 500 ) : true; }
    public static function invoice_totals( $id ) {
        global $wpdb; $total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_installments WHERE invoice_id=%d", $id ) ); $adjust = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_adjustments WHERE invoice_id=%d", $id ) ); $paid = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_bill_payments WHERE invoice_id=%d", $id ) );
        return array( 'total' => round( $total + $adjust, 2 ), 'paid' => round( $paid, 2 ), 'outstanding' => round( max( 0, $total + $adjust - $paid ), 2 ), 'credit_balance' => round( max( 0, $paid - $total - $adjust ), 2 ) );
    }
    public static function invoices( WP_REST_Request $r ) {
        global $wpdb; $month = $r->get_param( 'month' ) ?? current_time( 'Y-m' ); if ( ! self::month( $month ) ) { return self::error( 'Escolha o mês da fatura.' ); }
        PDS_Finance_Planning::ensure_card_months( get_current_user_id(), $month, $month );
        $card = $r->get_param( 'card_id' ) ?? ''; if ( ! is_scalar( $card ) ) { return self::error( 'Cartão inválido.' ); } if ( $card && is_wp_error( $found = self::find( 'cards', absint( $card ) ) ) ) { return $found; }
        $where = $wpdb->prepare( 'i.user_id=%d AND i.invoice_month=%s', get_current_user_id(), $month ); if ( $card ) { $where .= $wpdb->prepare( ' AND i.card_id=%d', $card ); }
        $rows = $wpdb->get_results( "SELECT i.*,c.name AS card_name,c.last_four FROM {$wpdb->prefix}pds_fin_invoices i INNER JOIN {$wpdb->prefix}pds_fin_cards c ON c.id=i.card_id AND c.user_id=i.user_id WHERE $where ORDER BY i.due_on,c.name", ARRAY_A );
        foreach ( $rows as &$row ) { $row = self::cast_invoice( $row ); } unset( $row ); return array( 'invoices' => $rows, 'month' => $month );
    }
    private static function cast_invoice( $row ) { $row['id'] = (int) $row['id']; $row['card_id'] = (int) $row['card_id']; unset( $row['user_id'] ); $row = array_merge( $row, self::invoice_totals( $row['id'] ) ); $row['status'] = $row['total'] == 0 && $row['paid'] == 0 ? 'empty' : ( $row['outstanding'] <= 0 ? 'paid' : ( $row['due_on'] < current_time( 'Y-m-d' ) ? 'overdue' : ( $row['closing_on'] <= current_time( 'Y-m-d' ) ? 'closed' : 'open' ) ) ); return $row; }
    public static function invoice( WP_REST_Request $r ) {
        global $wpdb; $invoice = self::find( 'invoices', self::id( $r ) ); if ( is_wp_error( $invoice ) ) { return $invoice; } $card = self::find( 'cards', $invoice['card_id'] ); $invoice['card_name'] = $card['name']; $invoice = self::cast_invoice( $invoice );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,e.description,e.spent_on,e.source,e.fuel_record_id,c.name AS category_name FROM {$wpdb->prefix}pds_fin_installments s INNER JOIN {$wpdb->prefix}pds_expenses e ON e.id=s.expense_id AND e.user_id=s.user_id INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.user_id=e.user_id WHERE s.invoice_id=%d AND s.user_id=%d ORDER BY e.spent_on,e.id,s.installment_no LIMIT 2000", $invoice['id'], get_current_user_id() ), ARRAY_A );
        foreach ( $items as &$item ) { foreach ( array( 'id', 'expense_id', 'installment_no', 'installment_total' ) as $key ) { $item[ $key ] = (int) $item[ $key ]; } $item['amount'] = (float) $item['amount']; $item['confirmed'] = (bool) $item['confirmed']; unset( $item['user_id'], $item['bank_ref'] ); } unset( $item );
        $payments = $wpdb->get_results( $wpdb->prepare( "SELECT p.id,p.amount,p.paid_on,p.account_id,a.name AS account_name FROM {$wpdb->prefix}pds_fin_bill_payments p INNER JOIN {$wpdb->prefix}pds_fin_accounts a ON a.id=p.account_id AND a.user_id=p.user_id WHERE p.invoice_id=%d AND p.user_id=%d ORDER BY p.paid_on,p.id", $invoice['id'], get_current_user_id() ), ARRAY_A );
        $adjustments = $wpdb->get_results( $wpdb->prepare( "SELECT id,description,amount FROM {$wpdb->prefix}pds_fin_adjustments WHERE invoice_id=%d AND user_id=%d ORDER BY id", $invoice['id'], get_current_user_id() ), ARRAY_A );
        return array( 'invoice' => $invoice, 'items' => $items, 'payments' => $payments, 'adjustments' => $adjustments );
    }
    public static function update_invoice( WP_REST_Request $r ) {
        global $wpdb; $invoice = self::find( 'invoices', self::id( $r ) ); if ( is_wp_error( $invoice ) ) { return $invoice; } $due = $r->get_param( 'due_on' ); $close = $r->get_param( 'closing_on' ); if ( ! PDS_Expenses::valid_date( $due ) || ! PDS_Expenses::valid_date( $close ) || substr( $due, 0, 7 ) !== $invoice['invoice_month'] || $close > $due || $close < self::add_month( $invoice['invoice_month'], -1 ) . '-01' ) { return self::error( 'O vencimento deve permanecer no mês da fatura, a partir do fechamento. Confira o mês do fechamento (atual ou anterior).' ); }
        return false === $wpdb->update( $wpdb->prefix . 'pds_fin_invoices', array( 'due_on' => $due, 'closing_on' => $close ), array( 'id' => $invoice['id'], 'user_id' => get_current_user_id() ) ) ? self::error( 'Não foi possível atualizar a fatura.', 500 ) : array( 'success' => true );
    }
    public static function pay_invoice( WP_REST_Request $r ) {
        global $wpdb; $invoice = self::find( 'invoices', self::id( $r ) ); if ( is_wp_error( $invoice ) ) { return $invoice; } $account = self::find( 'accounts', is_scalar( $r->get_param( 'account_id' ) ) ? absint( $r->get_param( 'account_id' ) ) : 0 ); if ( is_wp_error( $account ) ) { return $account; } if ( ! $account['active'] ) { return self::error( 'Escolha uma conta ativa para o pagamento.' ); }
        $amount = PDS_Expenses::amount( $r->get_param( 'amount' ) ); if ( is_wp_error( $amount ) ) { return $amount; } $date = $r->get_param( 'paid_on' ); if ( ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return self::error( 'Informe a data do pagamento realizado, até hoje.' ); }
        $wpdb->query( 'START TRANSACTION' ); $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d FOR UPDATE", $invoice['id'] ) ); $totals = self::invoice_totals( $invoice['id'] );
        if ( (float) $amount - $totals['outstanding'] > 0.000001 ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'O pagamento ultrapassa o saldo em aberto da fatura. Atualize e confira os lançamentos.' ); }
        $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_bill_payments', array( 'user_id' => get_current_user_id(), 'invoice_id' => $invoice['id'], 'account_id' => $account['id'], 'amount' => $amount, 'paid_on' => $date, 'created_at' => current_time( 'mysql', true ) ) ); $id = (int) $wpdb->insert_id; if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível registrar o pagamento.', 500 ); } $wpdb->query( 'COMMIT' ); return array( 'id' => $id );
    }
    public static function delete_payment( WP_REST_Request $r ) { global $wpdb; $row = self::find( 'bill_payments', self::id( $r ) ); if ( is_wp_error( $row ) ) { return $row; } return false === $wpdb->delete( $wpdb->prefix . 'pds_fin_bill_payments', array( 'id' => $row['id'], 'user_id' => get_current_user_id() ) ) ? self::error( 'Não foi possível remover o pagamento.', 500 ) : array( 'success' => true ); }
    public static function save_adjustment( WP_REST_Request $r ) {
        global $wpdb; $invoice = self::find( 'invoices', self::id( $r ) ); if ( is_wp_error( $invoice ) ) { return $invoice; } $amount = self::cents( $r->get_param( 'amount' ), true ); $description = self::text( $r, 'description', 180, true ); if ( is_wp_error( $amount ) ) { return $amount; } if ( is_wp_error( $description ) ) { return $description; } if ( ! $amount ) { return self::error( 'O ajuste precisa ter valor diferente de zero.' ); }
        $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_adjustments', array( 'user_id' => get_current_user_id(), 'invoice_id' => $invoice['id'], 'description' => $description, 'amount' => self::decimal( $amount ), 'created_at' => current_time( 'mysql', true ) ) ); return false === $ok ? self::error( 'Não foi possível salvar o ajuste.', 500 ) : array( 'id' => (int) $wpdb->insert_id );
    }
    public static function delete_adjustment( WP_REST_Request $r ) { global $wpdb; $row = self::find( 'adjustments', self::id( $r ) ); if ( is_wp_error( $row ) ) { return $row; } return false === $wpdb->delete( $wpdb->prefix . 'pds_fin_adjustments', array( 'id' => $row['id'], 'user_id' => get_current_user_id() ) ) ? self::error( 'Não foi possível excluir o ajuste.', 500 ) : array( 'success' => true ); }
    public static function overview( WP_REST_Request $r ) {
        global $wpdb; $from = $r->get_param( 'from' ) ?? current_time( 'Y-m-01' ); $to = $r->get_param( 'to' ) ?? current_time( 'Y-m-t' ); if ( ! PDS_Expenses::valid_date( $from ) || ! PDS_Expenses::valid_date( $to ) || $from > $to || strtotime( $to ) - strtotime( $from ) > 731 * DAY_IN_SECONDS ) { return self::error( 'Confira o período, de até dois anos.' ); } $uid = get_current_user_id();
        $planning_warnings = PDS_Finance_Planning::sync_user( $uid, substr( $to, 0, 7 ) ); PDS_Finance_Planning::ensure_card_months( $uid, substr( $from, 0, 7 ), substr( $to, 0, 7 ) ); $bills = PDS_Finance_Planning::pending_totals( $uid, $from, $to );
        $receipts = $wpdb->get_results( $wpdb->prepare( "SELECT status,SUM(amount) AS amount FROM {$wpdb->prefix}pds_fin_receipts WHERE user_id=%d AND received_on BETWEEN %s AND %s GROUP BY status", $uid, $from, $to ), ARRAY_A ); $income = array( 'received' => 0.0, 'pending' => 0.0 ); foreach ( $receipts as $row ) { $income[ $row['status'] ] = (float) $row['amount']; }
        $direct = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_expenses WHERE user_id=%d AND card_id=0 AND payment_method<>'credit' AND spent_on BETWEEN %s AND %s", $uid, $from, $to ) ); $payments = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_bill_payments WHERE user_id=%d AND paid_on BETWEEN %s AND %s", $uid, $from, $to ) );
        $invoices = $wpdb->get_results( $wpdb->prepare( "SELECT i.*,c.name AS card_name FROM {$wpdb->prefix}pds_fin_invoices i INNER JOIN {$wpdb->prefix}pds_fin_cards c ON c.id=i.card_id AND c.user_id=i.user_id WHERE i.user_id=%d AND i.due_on BETWEEN %s AND %s ORDER BY i.due_on,c.name", $uid, $from, $to ), ARRAY_A ); $open = 0.0; foreach ( $invoices as &$invoice ) { $invoice = self::cast_invoice( $invoice ); $open += $invoice['outstanding']; } unset( $invoice );
        return array( 'from' => $from, 'to' => $to, 'received' => $income['received'], 'pending_income' => $income['pending'], 'direct_expenses' => $direct, 'invoice_payments' => $payments, 'cash_result' => round( $income['received'] - $direct - $payments, 2 ), 'open_invoices' => round( $open, 2 ), 'pending_payables' => $bills['amount'], 'unknown_payables' => $bills['unknown_count'], 'planning_warnings' => $planning_warnings, 'projected_result' => round( $income['received'] + $income['pending'] - $direct - $payments - $open - $bills['amount'], 2 ), 'accounts' => self::accounts()['accounts'], 'invoices' => $invoices );
    }
}
