<?php
defined( 'ABSPATH' ) || exit;

/** Forecasts are separate from cash movements. Only an explicit settlement creates an expense. */
final class PDS_Finance_Planning {
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_finance_planning', array( __CLASS__, 'cron' ) );
        if ( ! wp_next_scheduled( 'pds_finance_planning' ) ) { wp_schedule_event( time() + 60, 'hourly', 'pds_finance_planning' ); }
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-planning', PDS_URL . 'assets/planning.js', array( 'pds-finance' ), PDS_VERSION, true ); } );
    }
    public static function routes() {
        foreach ( array(
            '/finance/payables' => array( 'GET' => 'payables', 'POST' => 'save_payable' ),
            '/finance/payables/(?P<id>\d+)' => array( 'GET' => 'payable', 'PUT' => 'save_payable', 'DELETE' => 'delete_payable' ),
            '/finance/payables/(?P<id>\d+)/pay' => array( 'POST' => 'pay' ),
            '/finance/payables/(?P<id>\d+)/reopen' => array( 'POST' => 'reopen' ),
            '/finance/recurring' => array( 'GET' => 'recurring' ),
            '/finance/recurring/(?P<id>\d+)' => array( 'PUT' => 'edit_recurring', 'DELETE' => 'end_recurring' ),
            '/finance/receipts/plan' => array( 'POST' => 'save_receipt_plan' ),
        ) as $path => $methods ) {
            $routes = array(); foreach ( $methods as $method => $callback ) { $routes[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ); }
            register_rest_route( 'pds/v1', $path, $routes );
        }
    }
    private static function id( $r ) { return absint( $r->get_url_params()['id'] ?? 0 ); }
    public static function find( $table, $id, $uid = 0, $lock = false ) {
        global $wpdb; if ( ! in_array( $table, array( 'payables', 'recurring' ), true ) ) { return PDS_Finance::error( 'Previsão inválida.' ); }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_{$table} WHERE id=%d AND user_id=%d" . ( 'payables' === $table ? " AND status<>'deleted'" : '' ) . ( $lock ? ' FOR UPDATE' : '' ), $id, $uid ?: get_current_user_id() ), ARRAY_A );
        return $row ?: PDS_Finance::error( 'Previsão não encontrada ou sem acesso.', 404 );
    }
    public static function positive_id( $v, $optional = false ) {
        if ( $optional && in_array( $v, array( null, '', 0, '0' ), true ) ) { return 0; }
        return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $v ) ? (int) $v : PDS_Finance::error( 'Escolha uma conta ou categoria válida.' );
    }
    private static function text( $r, $key, $max, $required = false ) {
        $v = $r->get_param( $key ) ?? ''; if ( ! is_string( $v ) ) { return PDS_Finance::error( 'Confira o campo ' . $key . '.' ); }
        $v = 'notes' === $key ? sanitize_textarea_field( $v ) : sanitize_text_field( trim( $v ) );
        return mb_strlen( $v ) > $max || ( $required && mb_strlen( $v ) < 2 ) ? PDS_Finance::error( 'Confira a descrição e as observações da previsão.' ) : $v;
    }
    public static function recurrence( $r, $date ) {
        $mode = $r->get_param( 'recurrence' ) ?? 'once';
        if ( ! in_array( $mode, array( 'once', 'monthly' ), true ) ) { return PDS_Finance::error( 'Escolha lançamento único ou recorrência mensal.' ); }
        $n = $r->get_param( 'occurrences' ); $day = $r->get_param( 'billing_day' );
        if ( null === $day || '' === $day ) { $day = (int) substr( $date, 8, 2 ); }
        if ( ( ! is_int( $day ) && ! is_string( $day ) ) || ! preg_match( '/^[0-9]{1,2}$/D', (string) $day ) || (int) $day < 1 || (int) $day > 31 ) { return PDS_Finance::error( 'Informe um dia de 1 a 31.' ); }
        if ( null === $n || '' === $n ) { $n = null; } elseif ( ( ! is_int( $n ) && ! is_string( $n ) ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $n ) || (int) $n < 1 || (int) $n > 120 ) { return PDS_Finance::error( 'Informe de 1 a 120 ocorrências, ou deixe vazio para continuar mensalmente.' ); } else { $n = (int) $n; }
        if ( 'once' === $mode ) { $n = 1; }
        $month = substr( $date, 0, 7 );
        if ( 'monthly' === $mode && ( $month < PDS_Finance::add_month( current_time( 'Y-m' ), -12 ) || $month > PDS_Finance::add_month( current_time( 'Y-m' ), 24 ) ) ) { return PDS_Finance::error( 'Inicie a recorrência entre os últimos 12 e os próximos 24 meses.' ); }
        $end = $n ? PDS_Finance::add_month( $month, $n - 1 ) : null;
        if ( $end && ! PDS_Finance::month( $end ) ) { return PDS_Finance::error( 'O término da recorrência ultrapassa o período permitido.' ); }
        return array( 'mode' => $mode, 'day' => (int) $day, 'count' => $n, 'end' => $end );
    }
    private static function clean( $r, $kind, $old = null ) {
        $description = self::text( $r, 'description', 180, true ); $notes = self::text( $r, 'notes', 500 );
        $date = $r->get_param( 'receipt' === $kind ? 'received_on' : 'due_on' );
        $raw = $r->get_param( 'amount' ); $amount = 'payable' === $kind && in_array( $raw, array( null, '' ), true ) ? null : PDS_Expenses::amount( $raw );
        foreach ( array( $description, $notes, $amount ) as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
        if ( ! PDS_Expenses::valid_date( $date ) || ! PDS_Finance::month( substr( $date, 0, 7 ) ) ) { return PDS_Finance::error( 'Informe uma data válida para o vencimento ou recebimento previsto.' ); }
        $account_id = self::positive_id( $r->get_param( 'account_id' ), 'payable' === $kind ); if ( is_wp_error( $account_id ) ) { return $account_id; }
        if ( $account_id ) { $account = PDS_Finance::find( 'accounts', $account_id ); if ( is_wp_error( $account ) ) { return $account; } if ( ! $account['active'] && (int) ( $old['account_id'] ?? 0 ) !== $account_id ) { return PDS_Finance::error( 'Escolha uma conta ativa.' ); } }
        $category_id = 0;
        if ( 'payable' === $kind ) {
            $category_id = self::positive_id( $r->get_param( 'category_id' ), true ); if ( is_wp_error( $category_id ) ) { return $category_id; }
            if ( ! $category_id ) { foreach ( PDS_Expenses::category_list() as $c ) { if ( in_array( $c['name'], array( 'Contas e serviços', 'Outros' ), true ) ) { $category_id = $c['id']; if ( 'Contas e serviços' === $c['name'] ) { break; } } } }
            $category = PDS_Expenses::category( $category_id ); if ( is_wp_error( $category ) ) { return $category; } if ( ! $category['active'] && (int) ( $old['category_id'] ?? 0 ) !== $category_id ) { return PDS_Finance::error( 'Escolha uma categoria ativa.' ); }
        }
        $repeat = self::recurrence( $r, $date ); if ( is_wp_error( $repeat ) ) { return $repeat; }
        return array( 'description' => $description, 'notes' => $notes, 'amount' => $amount, 'due_on' => $date, 'account_id' => $account_id, 'category_id' => $category_id, '_repeat' => $repeat );
    }
    private static function reference( $ref, $kind ) {
        return null === $ref || ( is_string( $ref ) && preg_match( '/^telegram-' . ( 'receipt' === $kind ? 'receipt' : 'payable' ) . ':[a-f0-9]{64}$/D', $ref ) );
    }
    private static function existing( $kind, $ref ) {
        global $wpdb; if ( ! $ref ) { return null; } $uid = get_current_user_id(); $table = 'receipt' === $kind ? 'receipts' : 'payables';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id,recurring_id FROM {$wpdb->prefix}pds_fin_{$table} WHERE user_id=%d AND external_ref=%s", $uid, $ref ), ARRAY_A );
        if ( $row ) { return array( 'id' => (int) $row['id'], 'recurring_id' => $row['recurring_id'] ? (int) $row['recurring_id'] : null, 'already_saved' => true ); }
        $series = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_recurring WHERE user_id=%d AND kind=%s AND external_ref=%s", $uid, $kind, $ref ) );
        if ( $series ) { $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_{$table} WHERE recurring_id=%d AND user_id=%d ORDER BY occurrence_month,id LIMIT 1", $series, $uid ) ); return array( 'id' => (int) $id, 'recurring_id' => (int) $series, 'already_saved' => true ); }
        return null;
    }
    public static function save_payable( WP_REST_Request $r, $external_ref = null ) {
        $allowed = PDS_Expenses::permission(); if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( ! self::reference( $external_ref, 'payable' ) ) { return PDS_Finance::error( 'Origem da conta inválida.' ); }
        if ( $saved = self::existing( 'payable', $external_ref ) ) { return $saved; }
        global $wpdb; $uid = get_current_user_id(); $id = self::id( $r );
        $old = $id ? self::find( 'payables', $id ) : null; if ( is_wp_error( $old ) ) { return $old; }
        $scope = $r->get_param( 'scope' ) ?? 'single'; if ( ! in_array( $scope, array( 'single', 'following' ), true ) || ( ! $id && 'single' !== $scope ) ) { return PDS_Finance::error( 'Escolha somente esta conta ou esta e as próximas.' ); }
        if ( $old && 'following' === $scope ) {
            if ( ! $old['recurring_id'] || 'pending' !== $old['status'] ) { return PDS_Finance::error( 'A edição das próximas contas deve partir de uma ocorrência recorrente pendente.', 409 ); }
            $series_request = new WP_REST_Request( 'PUT' ); $series_request->set_url_params( array( 'id' => (int) $old['recurring_id'] ) );
            $params = $r->get_params(); unset( $params['id'] ); $params['from_payable_id'] = $id;
            if ( ! PDS_Expenses::valid_date( $params['due_on'] ?? '' ) || substr( $params['due_on'], 0, 7 ) !== $old['occurrence_month'] ) { return PDS_Finance::error( 'Para mudar o mês de vencimento, escolha Somente esta conta. Nas próximas, ajuste o dia dentro do mês da ocorrência.' ); }
            $series = self::find( 'recurring', $old['recurring_id'] ); if ( is_wp_error( $series ) ) { return $series; }
            // Keeping a February/short-month date must not replace an anchored day 31 with day 28/30.
            $params['billing_day'] = $params['billing_day'] ?? ( $params['due_on'] === $old['due_on'] ? (int) $series['due_day'] : (int) substr( $params['due_on'], 8, 2 ) ); $series_request->set_body_params( $params ); return self::edit_recurring( $series_request );
        }
        if ( $old && 'paid' === $old['status'] && ( ! $r->has_param( 'actual_amount' ) || ! $r->has_param( 'paid_on' ) ) ) { return PDS_Finance::error( 'Para editar a conta paga, confira também o valor e a data do pagamento realizado.', 409 ); }
        $clean = self::clean( $r, 'payable', $old ); if ( is_wp_error( $clean ) ) { return $clean; }
        if ( ! $id && 'monthly' === $clean['_repeat']['mode'] ) { return self::create_recurring( 'payable', $clean, $external_ref ); }
        unset( $clean['_repeat'] ); $clean['updated_at'] = current_time( 'mysql', true );
        if ( $id ) {
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível editar a conta.', 500 ); }
            $locked = self::find( 'payables', $id, $uid, true );
            if ( is_wp_error( $locked ) || $locked['status'] !== $old['status'] || $locked['expense_id'] !== $old['expense_id'] ) { return self::rollback( PDS_Finance::error( 'Esta conta mudou. Atualize a lista e tente novamente.', 409 ) ); }
            $saved = null;
            if ( 'paid' === $locked['status'] ) { $saved = self::correct_payment( $r, $locked, $clean ); if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); } }
            if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', $clean, array( 'id' => $id, 'user_id' => $uid ) ) || false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível salvar a conta.', 500 ) ); }
            if ( $saved ) { do_action( 'pds_expense_updated', $saved, $uid ); }
            return array( 'id' => $id, 'recurring_id' => $old['recurring_id'] ? (int) $old['recurring_id'] : null, 'expense_id' => $saved['id'] ?? null );
        }
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_fin_payables', $clean, array( 'id' => $id, 'user_id' => $uid, 'status' => $old['status'] ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_payables', $clean + array( 'user_id' => $uid, 'status' => 'pending', 'source' => $external_ref ? 'telegram' : 'manual', 'external_ref' => $external_ref, 'created_at' => current_time( 'mysql', true ) ) );
        if ( false === $ok ) { return self::existing( 'payable', $external_ref ) ?: PDS_Finance::error( 'Não foi possível cadastrar a conta.', 500 ); }
        return array( 'id' => $id ?: (int) $wpdb->insert_id, 'recurring_id' => $old['recurring_id'] ?? null );
    }
    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }
    /** Updates the existing settlement inside the payable transaction, preserving its identity. */
    private static function correct_payment( $r, $bill, $clean ) {
        global $wpdb; $uid = get_current_user_id();
        $locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d AND payable_id=%d FOR UPDATE", $bill['expense_id'], $uid, $bill['id'] ) );
        if ( ! $locked ) { return PDS_Finance::error( 'O pagamento desta conta não foi encontrado. Atualize a lista.', 409 ); }
        $old = PDS_Expenses::get_entry( $locked ); if ( is_wp_error( $old ) ) { return $old; }
        $method = $r->get_param( 'payment_method' ) ?? $old['payment_method']; $paid_on = $r->get_param( 'paid_on' );
        if ( ! is_string( $method ) || '' === $method || ! isset( PDS_Expenses::PAYMENTS[ $method ] ) || ! PDS_Expenses::valid_date( $paid_on ) || $paid_on > current_time( 'Y-m-d' ) ) { return PDS_Finance::error( 'Confira a forma e a data real do pagamento, até hoje.' ); }
        $account_id = 0;
        if ( 'credit' !== $method ) {
            $account_id = self::positive_id( $r->get_param( 'payment_account_id' ) ?? $old['account_id'] ); if ( is_wp_error( $account_id ) ) { return $account_id; }
            $bank = $wpdb->get_row( $wpdb->prepare( "SELECT id,active,opening_on FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $account_id, $uid ), ARRAY_A );
            if ( ! $bank ) { return PDS_Finance::error( 'Conta do pagamento não encontrada ou sem acesso.', 404 ); }
            if ( ( ! $bank['active'] && (int) $old['account_id'] !== $account_id ) || $paid_on < $bank['opening_on'] ) { return PDS_Finance::error( 'Confira a conta do pagamento e sua data de saldo inicial.' ); }
        }
        $body = array( 'description' => $clean['description'], 'notes' => $clean['notes'], 'merchant' => $old['merchant'], 'amount' => $r->get_param( 'actual_amount' ), 'spent_on' => $paid_on, 'category_id' => $r->get_param( 'payment_category_id' ) ?? $old['category_id'], 'payment_method' => $method, 'account_id' => $account_id, 'card_id' => 'credit' === $method ? ( $r->get_param( 'payment_card_id' ) ?? $old['card_id'] ) : 0, 'installments' => 1 );
        $request = new WP_REST_Request( 'PUT' ); $request->set_url_params( array( 'id' => $old['id'] ) ); $request->set_body_params( $body );
        return PDS_Expenses::save_entry( $request, null, array( 'in_transaction' => true, 'payable_correction' => (int) $bill['id'] ) );
    }
    public static function save_receipt_plan( WP_REST_Request $r, $external_ref = null ) {
        $allowed = PDS_Expenses::permission(); if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( ! self::reference( $external_ref, 'receipt' ) ) { return PDS_Finance::error( 'Origem do recebimento inválida.' ); }
        if ( $saved = self::existing( 'receipt', $external_ref ) ) { return $saved; }
        $clean = self::clean( $r, 'receipt' ); if ( is_wp_error( $clean ) ) { return $clean; }
        if ( 'monthly' !== $clean['_repeat']['mode'] ) { $r->set_param( 'status', 'pending' ); return PDS_Finance::save_receipt( $r, $external_ref ); }
        return self::create_recurring( 'receipt', $clean, $external_ref );
    }
    private static function create_recurring( $kind, $clean, $ref ) {
        global $wpdb; $uid = get_current_user_id();
        if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_recurring WHERE user_id=%d AND active=1", $uid ) ) >= 100 ) { return PDS_Finance::error( 'Você pode manter até 100 recorrências ativas.' ); }
        $repeat = $clean['_repeat']; $first = $clean['due_on']; $month = substr( $first, 0, 7 ); unset( $clean['_repeat'], $clean['due_on'] );
        $data = $clean + array( 'user_id' => $uid, 'kind' => $kind, 'first_due' => $first, 'due_day' => $repeat['day'], 'next_month' => $month, 'end_month' => $repeat['end'], 'active' => 1, 'source' => $ref ? 'telegram' : 'manual', 'external_ref' => $ref, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) );
        $wpdb->query( 'START TRANSACTION' );
        if ( false === $wpdb->insert( $wpdb->prefix . 'pds_fin_recurring', $data ) ) { $wpdb->query( 'ROLLBACK' ); return self::existing( $kind, $ref ) ?: PDS_Finance::error( 'Não foi possível cadastrar a recorrência.', 500 ); }
        $sid = (int) $wpdb->insert_id; $through = $repeat['end'] ?: PDS_Finance::add_month( max( $month, current_time( 'Y-m' ) ), 11 );
        $result = self::materialize( $sid, $uid, $through, true );
        if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); return $result; }
        $table = 'receipt' === $kind ? 'receipts' : 'payables';
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_{$table} WHERE recurring_id=%d AND user_id=%d ORDER BY occurrence_month,id LIMIT 1", $sid, $uid ) );
        if ( ! $id ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível gerar a primeira previsão.', 500 ); }
        $wpdb->query( 'COMMIT' ); return array( 'id' => $id, 'recurring_id' => $sid, 'created' => $result['created'] );
    }
    public static function materialize( $sid, $uid, $through, $inside = false ) {
        global $wpdb; if ( ! PDS_Finance::month( $through ) ) { return PDS_Finance::error( 'Período da previsão inválido.' ); }
        if ( ! $inside ) { $wpdb->query( 'START TRANSACTION' ); }
        $s = self::find( 'recurring', $sid, $uid, true );
        if ( is_wp_error( $s ) ) { if ( ! $inside ) { $wpdb->query( 'ROLLBACK' ); } return $s; }
        $created = 0; $cursor = $s['next_month'];
        if ( $s['active'] ) {
            if ( $s['account_id'] ) { $account = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $s['account_id'], $uid ), ARRAY_A ); if ( ! $account || ! $account['active'] ) { if ( ! $inside ) { $wpdb->query( 'ROLLBACK' ); } return PDS_Finance::error( 'A conta da recorrência está arquivada ou indisponível. Edite a recorrência.' ); } }
            $limit = $s['end_month'] ? min( $through, $s['end_month'] ) : $through;
            for ( $i = 0; $cursor <= $limit && $i < 240; $i++ ) {
                $date = $cursor === substr( $s['first_due'], 0, 7 ) ? $s['first_due'] : PDS_Finance::day( $cursor, $s['due_day'] );
                $data = array( 'user_id' => $uid, 'recurring_id' => $sid, 'occurrence_month' => $cursor, 'description' => $s['description'], 'amount' => $s['amount'], 'notes' => $s['notes'], 'account_id' => $s['account_id'], 'status' => 'pending', 'source' => $s['source'], 'external_ref' => $cursor === substr( $s['first_due'], 0, 7 ) ? $s['external_ref'] : null, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) );
                $table = 'receipt' === $s['kind'] ? 'receipts' : 'payables';
                if ( 'receipt' === $s['kind'] ) { $data['received_on'] = $date; } else { $data['due_on'] = $date; $data['category_id'] = $s['category_id']; }
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_{$table} WHERE user_id=%d AND recurring_id=%d AND occurrence_month=%s", $uid, $sid, $cursor ) );
                if ( ! $existing ) {
                    if ( false === $wpdb->insert( $wpdb->prefix . 'pds_fin_' . $table, $data ) ) { if ( ! $inside ) { $wpdb->query( 'ROLLBACK' ); } return PDS_Finance::error( 'Não foi possível gerar a previsão mensal.', 500 ); }
                    $created++;
                }
                $cursor = PDS_Finance::add_month( $cursor, 1 );
            }
            if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_recurring', array( 'next_month' => $cursor ), array( 'id' => $sid, 'user_id' => $uid ) ) ) { if ( ! $inside ) { $wpdb->query( 'ROLLBACK' ); } return PDS_Finance::error( 'Não foi possível atualizar a recorrência.', 500 ); }
        }
        if ( ! $inside ) { $wpdb->query( 'COMMIT' ); } return array( 'created' => $created );
    }
    public static function sync_user( $uid, $through = '' ) {
        global $wpdb; $through = max( current_time( 'Y-m' ), $through && PDS_Finance::month( $through ) ? $through : current_time( 'Y-m' ) );
        $through = min( PDS_Finance::add_month( current_time( 'Y-m' ), 119 ), max( $through, PDS_Finance::add_month( current_time( 'Y-m' ), 11 ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,description FROM {$wpdb->prefix}pds_fin_recurring WHERE user_id=%d AND active=1 AND next_month<=%s AND (end_month IS NULL OR next_month<=end_month) ORDER BY id LIMIT 100", $uid, $through ), ARRAY_A );
        $warnings = array(); foreach ( $rows as $s ) { $result = self::materialize( $s['id'], $uid, $through ); if ( is_wp_error( $result ) ) { $warnings[] = $s['description'] . ': ' . $result->get_error_message(); } }
        return $warnings;
    }
    public static function cron() {
        global $wpdb; $after = (int) get_option( 'pds_finance_planning_cursor', 0 );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->prefix}pds_fin_recurring WHERE active=1 AND user_id>%d ORDER BY user_id LIMIT 100", $after ) );
        if ( ! $ids ) { update_option( 'pds_finance_planning_cursor', 0, false ); return; }
        foreach ( $ids as $uid ) { if ( pds_user_can_access_service( 'gastos', $uid ) ) { self::sync_user( (int) $uid ); } }
        update_option( 'pds_finance_planning_cursor', (int) end( $ids ), false );
    }
    public static function ensure_card_months( $uid, $from, $to ) {
        global $wpdb; if ( ! PDS_Finance::month( $from ) || ! PDS_Finance::month( $to ) || $from > $to || $to > PDS_Finance::add_month( $from, 24 ) ) { return; }
        $cards = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_cards WHERE user_id=%d AND active=1 ORDER BY id", $uid ), ARRAY_A );
        foreach ( $cards as $card ) {
            $created = wp_date( 'Y-m', strtotime( $card['created_at'] . ' UTC' ), wp_timezone() ); $month = max( $from, $created );
            while ( $month <= $to ) { PDS_Finance::ensure_invoice( $card, $month ); $month = PDS_Finance::add_month( $month, 1 ); }
        }
    }
    public static function payable( WP_REST_Request $r ) { return self::get_payable( self::id( $r ) ); }
    public static function get_payable( $id ) {
        global $wpdb; $row = self::find( 'payables', $id ); if ( is_wp_error( $row ) ) { return $row; }
        foreach ( array( 'id', 'category_id', 'account_id' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; }
        foreach ( array( 'recurring_id', 'expense_id' ) as $key ) { $row[ $key ] = $row[ $key ] ? (int) $row[ $key ] : null; }
        $row['amount'] = null === $row['amount'] ? null : (float) $row['amount']; $row['kind'] = 'payable';
        $row['state'] = 'pending' === $row['status'] && $row['due_on'] < current_time( 'Y-m-d' ) ? 'overdue' : $row['status'];
        $row['actual_amount'] = null; $row['paid_on'] = null; $row['payment'] = null;
        if ( $row['expense_id'] ) { $e = $wpdb->get_row( $wpdb->prepare( "SELECT id,amount,spent_on,category_id,account_id,card_id,payment_method FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d AND payable_id=%d", $row['expense_id'], get_current_user_id(), $row['id'] ), ARRAY_A ); if ( $e ) { $row['actual_amount'] = (float) $e['amount']; $row['paid_on'] = $e['spent_on']; foreach ( array( 'id', 'category_id', 'account_id', 'card_id' ) as $key ) { $e[ $key ] = (int) $e[ $key ]; } $e['amount'] = (float) $e['amount']; $row['payment'] = $e; } }
        unset( $row['user_id'], $row['external_ref'] ); return $row;
    }
    public static function payables( WP_REST_Request $r ) {
        global $wpdb; $month = $r->get_param( 'month' ) ?? current_time( 'Y-m' ); $filter = $r->get_param( 'status' ) ?? 'all';
        if ( ! PDS_Finance::month( $month ) || ! in_array( $filter, array( 'all', 'pending', 'paid', 'canceled' ), true ) ) { return PDS_Finance::error( 'Confira o mês e a situação das contas.' ); }
        $warnings = self::sync_user( get_current_user_id(), $month ); self::ensure_card_months( get_current_user_id(), $month, $month );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_payables WHERE user_id=%d AND status<>'deleted' AND due_on BETWEEN %s AND %s ORDER BY due_on,id LIMIT 1001", get_current_user_id(), $month . '-01', PDS_Finance::day( $month, 31 ) ) );
        if ( count( $ids ) > 1000 ) { return PDS_Finance::error( 'Há mais de 1.000 contas neste mês. Consulte os registros pelo banco administrativo antes de continuar.' ); }
        $rows = array_values( array_filter( array_map( array( __CLASS__, 'get_payable' ), $ids ), static fn( $row ) => ! is_wp_error( $row ) ) );
        $ir = new WP_REST_Request( 'GET' ); $ir->set_query_params( array( 'month' => $month ) ); $invoices = PDS_Finance::invoices( $ir );
        if ( ! is_wp_error( $invoices ) ) { foreach ( $invoices['invoices'] as $i ) { $rows[] = array( 'id' => $i['id'], 'kind' => 'invoice', 'description' => 'Fatura · ' . $i['card_name'], 'amount' => $i['total'], 'due_on' => $i['due_on'], 'status' => $i['total'] == 0 && $i['paid'] == 0 ? 'empty' : ( $i['outstanding'] > 0 ? 'pending' : 'paid' ), 'state' => $i['total'] == 0 && $i['paid'] == 0 ? 'empty' : ( $i['outstanding'] > 0 && $i['due_on'] < current_time( 'Y-m-d' ) ? 'overdue' : ( $i['outstanding'] > 0 ? ( $i['paid'] > 0 ? 'partial' : 'pending' ) : 'paid' ) ), 'paid_amount' => $i['paid'], 'outstanding' => $i['outstanding'], 'recurring_id' => null, 'card_id' => $i['card_id'] ); } }
        usort( $rows, static fn( $a, $b ) => strcmp( $a['due_on'], $b['due_on'] ) ?: strcmp( $a['description'], $b['description'] ) );
        $pending = 0; $paid = 0; $unknown = 0;
        foreach ( $rows as $row ) { if ( 'pending' === $row['status'] ) { if ( null === $row['amount'] ) { $unknown++; } else { $pending += 'invoice' === $row['kind'] ? $row['outstanding'] : $row['amount']; } } if ( 'invoice' === $row['kind'] ) { $paid += $row['paid_amount']; } elseif ( 'paid' === $row['status'] ) { $paid += $row['actual_amount'] ?? 0; } }
        if ( 'all' !== $filter ) { $rows = array_values( array_filter( $rows, static fn( $row ) => $row['status'] === $filter && 'empty' !== $row['state'] ) ); }
        return array( 'month' => $month, 'items' => $rows, 'pending_total' => round( $pending, 2 ), 'paid_total' => round( $paid, 2 ), 'unknown_count' => $unknown, 'warnings' => $warnings );
    }
    public static function pay( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $wpdb->query( 'START TRANSACTION' ); $bill = self::find( 'payables', self::id( $r ), $uid, true );
        if ( is_wp_error( $bill ) ) { $wpdb->query( 'ROLLBACK' ); return $bill; }
        if ( 'paid' === $bill['status'] ) { $wpdb->query( 'COMMIT' ); return array( 'id' => (int) $bill['id'], 'expense_id' => (int) $bill['expense_id'], 'already_paid' => true ); }
        if ( 'pending' !== $bill['status'] ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Reabra a previsão cancelada antes de registrar um pagamento.', 409 ); }
        $method = $r->get_param( 'payment_method' ); $account = $r->get_param( 'account_id' ) ?? $bill['account_id'];
        if ( ! is_string( $method ) || ! isset( PDS_Expenses::PAYMENTS[ $method ] ) || '' === $method || ( 'credit' !== $method && ( is_wp_error( self::positive_id( $account ) ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Informe a forma de pagamento e a conta de saída (ou o cartão de crédito).' ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( array(
            'description' => $bill['description'], 'amount' => $r->get_param( 'amount' ) ?? $bill['amount'], 'spent_on' => $r->get_param( 'paid_on' ),
            'category_id' => $r->get_param( 'category_id' ) ?? $bill['category_id'], 'payment_method' => $method,
            'account_id' => 'credit' === $method ? 0 : $account, 'card_id' => $r->get_param( 'card_id' ) ?? 0,
            'installments' => 1, 'notes' => $bill['notes'],
        ) );
        $saved = PDS_Expenses::save_entry( $request, 'payable:' . hash( 'sha256', $uid . '|' . $bill['id'] ), array( 'payable_id' => $bill['id'], 'in_transaction' => true ) );
        if ( is_wp_error( $saved ) || (int) ( $saved['payable_id'] ?? 0 ) !== (int) $bill['id'] ) { $wpdb->query( 'ROLLBACK' ); return is_wp_error( $saved ) ? $saved : PDS_Finance::error( 'O pagamento não corresponde a esta previsão.', 409 ); }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'status' => 'paid', 'expense_id' => $saved['id'], 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $bill['id'], 'user_id' => $uid ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível concluir a baixa.', 500 ); }
        $wpdb->query( 'COMMIT' ); do_action( 'pds_expense_created', $saved, $uid ); return array( 'id' => (int) $bill['id'], 'expense_id' => $saved['id'] );
    }
    /** Legacy DELETE cancels; force=true explicitly removes a bill from the portal. */
    public static function delete_payable( WP_REST_Request $r ) {
        $force = $r->get_param( 'force' );
        if ( null === $force || in_array( $force, array( false, 'false', 0, '0' ), true ) ) { return self::cancel_payable( $r ); }
        if ( ! in_array( $force, array( true, 'true', 1, '1' ), true ) ) { return PDS_Finance::error( 'Confira a confirmação de exclusão.' ); }
        $scope = $r->get_param( 'scope' ) ?? 'single'; if ( ! in_array( $scope, array( 'single', 'following' ), true ) ) { return PDS_Finance::error( 'Escolha somente esta conta ou esta e as próximas.' ); }
        global $wpdb; $uid = get_current_user_id(); $old = self::find( 'payables', self::id( $r ) ); if ( is_wp_error( $old ) ) { return $old; }
        if ( 'following' === $scope && ( ! $old['recurring_id'] || 'paid' === $old['status'] ) ) { return PDS_Finance::error( 'A exclusão das próximas deve partir de uma conta recorrente ainda não paga.', 409 ); }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível excluir a conta.', 500 ); }
        $series = $old['recurring_id'] ? self::find( 'recurring', $old['recurring_id'], $uid, true ) : null;
        if ( is_wp_error( $series ) ) { return self::rollback( $series ); }
        $bill = self::find( 'payables', $old['id'], $uid, true );
        if ( is_wp_error( $bill ) || $bill['status'] !== $old['status'] || $bill['expense_id'] !== $old['expense_id'] || $bill['recurring_id'] !== $old['recurring_id'] ) { return self::rollback( PDS_Finance::error( 'Esta conta mudou. Atualize e confira antes de excluir.', 409 ) ); }
        $entry = null; $now = current_time( 'mysql', true );
        if ( 'following' === $scope ) {
            if ( ! $series || 'payable' !== $series['kind'] || ! PDS_Finance::month( $bill['occurrence_month'] ) ) { return self::rollback( PDS_Finance::error( 'Recorrência da conta inválida.', 409 ) ); }
            // Paid occurrences remain real movements. Deleted occurrences retain their unique month as a tombstone.
            $deleted = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_fin_payables SET status='deleted',updated_at=%s WHERE user_id=%d AND recurring_id=%d AND occurrence_month>=%s AND status IN ('pending','canceled') AND expense_id IS NULL", $now, $uid, $series['id'], $bill['occurrence_month'] ) );
            $end = PDS_Finance::add_month( $bill['occurrence_month'], -1 ); if ( $series['end_month'] ) { $end = min( $end, $series['end_month'] ); }
            $stopped = $wpdb->update( $wpdb->prefix . 'pds_fin_recurring', array( 'active' => 0, 'end_month' => $end, 'updated_at' => $now ), array( 'id' => $series['id'], 'user_id' => $uid ) );
            if ( false === $deleted || false === $stopped ) { return self::rollback( PDS_Finance::error( 'Não foi possível excluir as próximas contas.', 500 ) ); }
        } else {
            if ( $bill['expense_id'] ) {
                $locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d AND payable_id=%d FOR UPDATE", $bill['expense_id'], $uid, $bill['id'] ) );
                if ( ! $locked ) { return self::rollback( PDS_Finance::error( 'Pagamento da conta não encontrado. Atualize a lista.', 409 ) ); }
                $entry = PDS_Expenses::get_entry( $locked ); if ( is_wp_error( $entry ) ) { return self::rollback( $entry ); }
                $removed = PDS_Finance::remove_expense( $entry['id'], $uid ); if ( is_wp_error( $removed ) ) { return self::rollback( $removed ); }
                if ( 1 !== $wpdb->delete( $wpdb->prefix . 'pds_expenses', array( 'id' => $entry['id'], 'user_id' => $uid, 'payable_id' => $bill['id'] ) ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível remover o pagamento.', 500 ) ); }
            }
            $deleted = $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'status' => 'deleted', 'expense_id' => null, 'updated_at' => $now ), array( 'id' => $bill['id'], 'user_id' => $uid ) );
            if ( 1 !== $deleted ) { return self::rollback( PDS_Finance::error( 'Não foi possível excluir a conta.', 500 ) ); }
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível confirmar a exclusão.', 500 ) ); }
        if ( $entry ) { do_action( 'pds_expense_deleted', $entry, $uid ); }
        return array( 'success' => true, 'deleted' => (int) $deleted, 'scope' => $scope );
    }
    public static function cancel_payable( WP_REST_Request $r ) {
        global $wpdb; $bill = self::find( 'payables', self::id( $r ) ); if ( is_wp_error( $bill ) ) { return $bill; }
        if ( 'paid' === $bill['status'] ) { return PDS_Finance::error( 'Desfaça a baixa antes de cancelar essa conta.', 409 ); }
        return false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'status' => 'canceled' ), array( 'id' => $bill['id'], 'user_id' => get_current_user_id(), 'expense_id' => null ) ) ? PDS_Finance::error( 'Não foi possível cancelar.', 500 ) : array( 'success' => true );
    }
    public static function reopen( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $wpdb->query( 'START TRANSACTION' ); $bill = self::find( 'payables', self::id( $r ), $uid, true );
        if ( is_wp_error( $bill ) ) { $wpdb->query( 'ROLLBACK' ); return $bill; }
        $entry = null;
        if ( $bill['expense_id'] ) {
            $entry = PDS_Expenses::get_entry( $bill['expense_id'] ); if ( is_wp_error( $entry ) ) { $wpdb->query( 'ROLLBACK' ); return $entry; }
            $removed = PDS_Finance::remove_expense( $entry['id'], $uid ); if ( is_wp_error( $removed ) ) { $wpdb->query( 'ROLLBACK' ); return $removed; }
            if ( false === $wpdb->delete( $wpdb->prefix . 'pds_expenses', array( 'id' => $entry['id'], 'user_id' => $uid, 'payable_id' => $bill['id'] ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível desfazer a despesa.', 500 ); }
        }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'status' => 'pending', 'expense_id' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $bill['id'], 'user_id' => $uid ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível reabrir a conta.', 500 ); }
        $wpdb->query( 'COMMIT' ); if ( $entry ) { do_action( 'pds_expense_deleted', $entry, $uid ); } return array( 'success' => true );
    }
    public static function pending_totals( $uid, $from, $to ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) AS amount,COUNT(*) AS count,SUM(amount IS NULL) AS unknown_count FROM {$wpdb->prefix}pds_fin_payables WHERE user_id=%d AND status='pending' AND due_on BETWEEN %s AND %s", $uid, $from, $to ), ARRAY_A );
        return array( 'amount' => (float) $row['amount'], 'count' => (int) $row['count'], 'unknown_count' => (int) $row['unknown_count'] );
    }
    public static function recurring() {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_recurring WHERE user_id=%d ORDER BY active DESC,id DESC LIMIT 500", get_current_user_id() ), ARRAY_A );
        foreach ( $rows as &$row ) { foreach ( array( 'id', 'due_day', 'category_id', 'account_id' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; } $row['active'] = (bool) $row['active']; $row['amount'] = null === $row['amount'] ? null : (float) $row['amount']; unset( $row['user_id'], $row['external_ref'] ); } unset( $row ); return array( 'recurring' => $rows );
    }
    public static function edit_recurring( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $s = self::find( 'recurring', self::id( $r ) ); if ( is_wp_error( $s ) ) { return $s; }
        if ( ! $s['active'] ) { return PDS_Finance::error( 'Esta recorrência foi encerrada. Cadastre uma nova para retomar os próximos meses.', 409 ); }
        $anchor = null; $anchor_id = $r->get_param( 'from_payable_id' );
        if ( null !== $anchor_id ) { $anchor_id = self::positive_id( $anchor_id ); if ( is_wp_error( $anchor_id ) ) { return $anchor_id; } $anchor = self::find( 'payables', $anchor_id ); if ( is_wp_error( $anchor ) ) { return $anchor; } if ( 'payable' !== $s['kind'] || (int) $anchor['recurring_id'] !== (int) $s['id'] || 'pending' !== $anchor['status'] ) { return PDS_Finance::error( 'Escolha uma conta pendente desta recorrência.', 409 ); } }
        $month = $anchor ? $anchor['occurrence_month'] : ( $r->get_param( 'from_month' ) ?? current_time( 'Y-m' ) );
        if ( ! PDS_Finance::month( $month ) || ( ! $anchor && $month !== current_time( 'Y-m' ) ) ) { return PDS_Finance::error( 'As alterações da recorrência valem a partir deste mês ou da conta escolhida.' ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( $r->get_params() + array( 'description' => $s['description'], 'notes' => $s['notes'], 'amount' => $s['amount'], 'account_id' => $s['account_id'], 'category_id' => $s['category_id'], 'due_on' => $s['first_due'], 'received_on' => $s['first_due'], 'billing_day' => $s['due_day'], 'recurrence' => 'once' ) );
        $clean = self::clean( $request, $s['kind'] ); if ( is_wp_error( $clean ) ) { return $clean; } $day = $clean['_repeat']['day']; unset( $clean['_repeat'], $clean['due_on'] );
        $wpdb->query( 'START TRANSACTION' ); $locked = self::find( 'recurring', $s['id'], $uid, true );
        if ( is_wp_error( $locked ) || ! $locked['active'] ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'A recorrência mudou. Atualize e tente novamente.', 409 ); }
        if ( $anchor ) { $locked_anchor = self::find( 'payables', $anchor['id'], $uid, true ); if ( is_wp_error( $locked_anchor ) || 'pending' !== $locked_anchor['status'] || (int) $locked_anchor['recurring_id'] !== (int) $s['id'] || $locked_anchor['occurrence_month'] !== $month ) { return self::rollback( PDS_Finance::error( 'A ocorrência mudou. Atualize a lista.', 409 ) ); } }
        // Only pending instances in the chosen month onward change; realized movements remain intact.
        $table = 'receipt' === $s['kind'] ? 'receipts' : 'payables'; $date_key = 'receipt' === $s['kind'] ? 'received_on' : 'due_on';
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT id,occurrence_month FROM {$wpdb->prefix}pds_fin_{$table} WHERE recurring_id=%d AND user_id=%d AND status='pending' AND occurrence_month>=%s FOR UPDATE", $s['id'], $uid, $month ), ARRAY_A );
        foreach ( $items as $item ) { $data = $clean; if ( 'receipt' === $s['kind'] ) { unset( $data['category_id'] ); } $data[ $date_key ] = PDS_Finance::day( $item['occurrence_month'], $day ); $data['updated_at'] = current_time( 'mysql', true ); if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_' . $table, $data, array( 'id' => $item['id'], 'user_id' => $uid ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível atualizar as previsões.', 500 ); } }
        $definition = $clean + array( 'due_day' => $day, 'updated_at' => current_time( 'mysql', true ) ); foreach ( $items as $item ) { if ( $item['occurrence_month'] === substr( $s['first_due'], 0, 7 ) ) { $definition['first_due'] = PDS_Finance::day( $item['occurrence_month'], $day ); } }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_recurring', $definition, array( 'id' => $s['id'], 'user_id' => $uid ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível atualizar a recorrência.', 500 ); }
        $wpdb->query( 'COMMIT' ); return array( 'success' => true, 'updated' => count( $items ) );
    }
    public static function end_recurring( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $s = self::find( 'recurring', self::id( $r ) ); if ( is_wp_error( $s ) ) { return $s; }
        $month = $r->get_param( 'from_month' ) ?? current_time( 'Y-m' ); if ( ! PDS_Finance::month( $month ) || $month !== current_time( 'Y-m' ) ) { return PDS_Finance::error( 'Encerre a partir deste mês. Pagamentos e meses anteriores são preservados.' ); }
        $wpdb->query( 'START TRANSACTION' ); $locked = self::find( 'recurring', $s['id'], $uid, true ); if ( is_wp_error( $locked ) ) { $wpdb->query( 'ROLLBACK' ); return $locked; }
        $table = 'receipt' === $s['kind'] ? 'receipts' : 'payables';
        $ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_fin_{$table} SET status='canceled',updated_at=%s WHERE recurring_id=%d AND user_id=%d AND status='pending' AND occurrence_month>=%s", current_time( 'mysql', true ), $s['id'], $uid, $month ) );
        $end = PDS_Finance::add_month( $month, -1 );
        $ok2 = $wpdb->update( $wpdb->prefix . 'pds_fin_recurring', array( 'active' => 0, 'end_month' => $end, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $s['id'], 'user_id' => $uid ) );
        if ( false === $ok || false === $ok2 ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível encerrar a recorrência.', 500 ); }
        $wpdb->query( 'COMMIT' ); return array( 'success' => true, 'canceled' => $ok );
    }
}
