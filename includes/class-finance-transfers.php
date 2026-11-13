<?php
defined( 'ABSPATH' ) || exit;

/** Internal movements are one atomic record, never an expense or a receipt. */
final class PDS_Finance_Transfers {
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_enqueue_assets', static function () {
            wp_enqueue_script( 'pds-transfers', PDS_URL . 'assets/transfers.js', array( 'pds-finance' ), PDS_VERSION, true );
        } );
    }

    public static function routes() {
        register_rest_route( 'pds/v1', '/finance/transfers', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'listing' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
        ) );
        register_rest_route( 'pds/v1', '/finance/transfers/(?P<id>\d+)/reverse', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'reverse' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ),
        ) );
    }

    private static function positive_id( $value ) {
        return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $value ) ? (int) $value : 0;
    }

    private static function rollback( $message, $status = 500 ) {
        global $wpdb; $wpdb->query( 'ROLLBACK' );
        return PDS_Finance::error( $message, $status );
    }

    private static function same( $row, $data ) {
        foreach ( array( 'from_account_id', 'to_account_id', 'amount', 'transferred_on', 'notes' ) as $key ) {
            if ( (string) $row[ $key ] !== (string) $data[ $key ] ) { return false; }
        }
        return true;
    }

    public static function save( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id();
        $from = self::positive_id( $r->get_param( 'from_account_id' ) );
        $to = self::positive_id( $r->get_param( 'to_account_id' ) );
        $cents = PDS_Finance::cents( $r->get_param( 'amount' ) );
        if ( is_wp_error( $cents ) ) { return $cents; }
        $date = $r->get_param( 'transferred_on' ); $notes = $r->get_param( 'notes' ) ?? ''; $key = $r->get_param( 'request_key' );
        if ( ! $from || ! $to || $from === $to ) { return PDS_Finance::error( 'Escolha duas contas diferentes: origem e destino.' ); }
        if ( $cents <= 0 ) { return PDS_Finance::error( 'Informe um valor de transferência maior que zero.' ); }
        if ( ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return PDS_Finance::error( 'Informe a data de uma transferência já realizada, até hoje.' ); }
        if ( ! is_string( $notes ) || mb_strlen( $notes ) > 500 ) { return PDS_Finance::error( 'Use até 500 caracteres nas observações.' ); }
        if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key ) ) { return PDS_Finance::error( 'Identificador de transferência inválido. Abra o formulário novamente.' ); }
        $data = array( 'user_id' => $uid, 'from_account_id' => $from, 'to_account_id' => $to, 'amount' => PDS_Finance::decimal( $cents ), 'transferred_on' => $date, 'notes' => sanitize_text_field( trim( $notes ) ) );
        $request_key = hash( 'sha256', $uid . '|' . $key );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar a transferência.', 500 ); }
        // Lock in ID order: the account state and initial dates cannot change mid-transfer.
        $accounts = $wpdb->get_results( $wpdb->prepare( "SELECT id,active,opening_on FROM {$wpdb->prefix}pds_fin_accounts WHERE user_id=%d AND id IN (%d,%d) ORDER BY id FOR UPDATE", $uid, $from, $to ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::rollback( 'As contas estão sendo atualizadas. Tente novamente.', 409 ); }
        if ( count( $accounts ) !== 2 ) { return self::rollback( 'Conta não encontrada ou sem acesso.', 404 ); }
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_transfers WHERE request_key=%s AND user_id=%d FOR UPDATE", $request_key, $uid ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::rollback( 'Não foi possível conferir a transferência.', 500 ); }
        if ( $existing ) {
            if ( ! self::same( $existing, $data ) ) { return self::rollback( 'Esse identificador já foi usado em outra transferência. Abra um novo formulário.', 409 ); }
            if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( 'Não foi possível confirmar a transferência.', 500 ); }
            return array( 'id' => (int) $existing['id'], 'status' => $existing['status'], 'duplicate' => true );
        }
        foreach ( $accounts as $a ) {
            if ( ! $a['active'] ) { return self::rollback( 'Use contas ativas para registrar uma transferência.', 409 ); }
            if ( $date < $a['opening_on'] ) { return self::rollback( 'A data deve ser igual ou posterior à data do saldo inicial das duas contas.' , 400 ); }
        }
        $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_transfers', $data + array( 'status' => 'completed', 'request_key' => $request_key, 'created_at' => current_time( 'mysql', true ) ) );
        if ( false === $ok ) { return self::rollback( 'Não foi possível registrar a transferência. Nenhum saldo foi alterado.', 500 ); }
        $id = (int) $wpdb->insert_id;
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( 'Não foi possível confirmar a transferência. Tente novamente.', 500 ); }
        return array( 'id' => $id, 'status' => 'completed', 'duplicate' => false );
    }

    public static function reverse( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $id = self::positive_id( $r->get_url_params()['id'] ?? 0 );
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar o estorno.', 500 ); }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_transfers WHERE id=%d AND user_id=%d FOR UPDATE", $id, $uid ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::rollback( 'Não foi possível conferir o registro.', 500 ); }
        if ( ! $row ) { return self::rollback( 'Transferência não encontrada ou sem acesso.', 404 ); }
        if ( $row['status'] !== 'reversed' ) {
            $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_transfers', array( 'status' => 'reversed', 'reversed_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'user_id' => $uid ) );
            if ( false === $ok ) { return self::rollback( 'Não foi possível estornar o registro.', 500 ); }
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( 'Não foi possível confirmar o estorno.', 500 ); }
        return array( 'id' => $id, 'status' => 'reversed', 'success' => true );
    }

    /** Added to each account balance, while financial income/expense totals stay unchanged. */
    public static function balance_delta( $account, $as_of ) {
        global $wpdb; $uid = (int) ( $account['user_id'] ?? get_current_user_id() );
        return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN to_account_id=%d THEN amount ELSE -amount END),0) FROM {$wpdb->prefix}pds_fin_transfers WHERE user_id=%d AND (from_account_id=%d OR to_account_id=%d) AND status='completed' AND transferred_on BETWEEN %s AND %s", $account['id'], $uid, $account['id'], $account['id'], $account['opening_on'], $as_of ) );
    }

    public static function check_account_date( $id, $uid, $date ) {
        global $wpdb;
        $first = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(transferred_on) FROM {$wpdb->prefix}pds_fin_transfers WHERE user_id=%d AND (from_account_id=%d OR to_account_id=%d) AND status='completed'", $uid, $id, $id ) );
        if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir as transferências dessa conta.', 500 ); }
        return $first && $date > $first ? PDS_Finance::error( 'Essa conta tem transferência anterior à nova data inicial. Mantenha a data inicial até ' . $first . ' ou estorne o registro antes.', 409 ) : true;
    }

    public static function listing( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id();
        $month = $r->get_param( 'month' ) ?? current_time( 'Y-m' ); $status = $r->get_param( 'status' ) ?? 'all';
        $account = $r->get_param( 'account_id' ); $page = $r->get_param( 'page' ) ?? 1;
        if ( ! PDS_Finance::month( $month ) || ! in_array( $status, array( 'all', 'completed', 'reversed' ), true ) || ! self::positive_id( $page ) || (int) $page > 1000000 ) { return PDS_Finance::error( 'Confira o mês, a situação e a página do histórico.' ); }
        $where = $wpdb->prepare( 't.user_id=%d AND t.transferred_on BETWEEN %s AND %s', $uid, $month . '-01', PDS_Finance::day( $month, 31 ) );
        if ( null !== $account && '' !== $account ) {
            $aid = self::positive_id( $account ); $found = $aid ? PDS_Finance::find( 'accounts', $aid, $uid ) : PDS_Finance::error( 'Conta inválida.' );
            if ( is_wp_error( $found ) ) { return $found; }
            $where .= $wpdb->prepare( ' AND (t.from_account_id=%d OR t.to_account_id=%d)', $aid, $aid );
        }
        if ( 'all' !== $status ) { $where .= $wpdb->prepare( ' AND t.status=%s', $status ); }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_transfers t WHERE $where" );
        $page = (int) $page; $offset = ( $page - 1 ) * 50;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id,t.from_account_id,t.to_account_id,t.amount,t.transferred_on,t.notes,t.status,t.created_at,t.reversed_at,a.name AS from_account_name,b.name AS to_account_name FROM {$wpdb->prefix}pds_fin_transfers t INNER JOIN {$wpdb->prefix}pds_fin_accounts a ON a.id=t.from_account_id AND a.user_id=t.user_id INNER JOIN {$wpdb->prefix}pds_fin_accounts b ON b.id=t.to_account_id AND b.user_id=t.user_id WHERE $where ORDER BY t.transferred_on DESC,t.id DESC LIMIT 50 OFFSET %d", $offset ), ARRAY_A );
        if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível consultar as transferências.', 500 ); }
        foreach ( $rows as &$row ) { foreach ( array( 'id', 'from_account_id', 'to_account_id' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; } $row['amount'] = (float) $row['amount']; } unset( $row );
        return array( 'transfers' => $rows, 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / 50 ) ) );
    }
}
