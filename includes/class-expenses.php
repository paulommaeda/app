<?php
defined( 'ABSPATH' ) || exit;

/** Private personal expenses. Every query is scoped to the current WordPress account. */
final class PDS_Expenses {
    const PAYMENTS = array( '' => 'Não informado', 'pix' => 'Pix', 'cash' => 'Dinheiro', 'debit' => 'Cartão de débito', 'credit' => 'Cartão de crédito', 'transfer' => 'Transferência', 'other' => 'Outro' );

    public static function boot() {
        add_action( 'pds_register_services', static function () {
            pds_register_service( 'gastos', array( 'title' => 'Meu Financeiro', 'description' => 'Gastos, recebimentos, contas e faturas dos seus cartões.', 'icon' => 'wallet', 'order' => 20, 'render_callback' => static function () { include PDS_DIR . 'templates/expenses.php'; } ) );
        } );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_style( 'pds-expenses', PDS_URL . 'assets/expenses.css', array( 'pds-portal' ), PDS_VERSION ); wp_enqueue_script( 'pds-expenses', PDS_URL . 'assets/expenses.js', array( 'pds-portal' ), PDS_VERSION, true ); } );
    }

    public static function routes() {
        foreach ( array( '/expenses/categories' => array( 'GET' => 'categories', 'POST' => 'save_category' ), '/expenses/categories/(?P<id>\d+)' => array( 'PUT' => 'save_category', 'DELETE' => 'archive_category' ), '/expenses/entries' => array( 'GET' => 'entries', 'POST' => 'save_entry' ), '/expenses/entries/(?P<id>\d+)' => array( 'GET' => 'entry', 'PUT' => 'save_entry', 'DELETE' => 'delete_entry' ), '/expenses/report' => array( 'GET' => 'report' ) ) as $route => $methods ) {
            $endpoints = array();
            foreach ( $methods as $method => $callback ) { $endpoints[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( __CLASS__, 'permission' ) ); }
            register_rest_route( 'pds/v1', $route, $endpoints );
        }
    }

    public static function error( $message, $status = 400 ) { return new WP_Error( 'pds_expenses', $message, array( 'status' => $status ) ); }
    public static function permission() {
        if ( ! is_user_logged_in() ) { return self::error( 'Entre na sua conta para continuar.', 401 ); }
        return pds_user_can_access_service( 'gastos' ) ? true : self::error( 'O serviço Meus Gastos não está liberado para sua conta.', 403 );
    }

    public static function seed() {
        $uid = get_current_user_id();
        if ( ! $uid || get_user_meta( $uid, 'pds_expenses_seeded', true ) ) { return; }
        global $wpdb;
        $failed = false;
        foreach ( array( 'Alimentação' => '#D68D53', 'Mercado' => '#71946C', 'Transporte' => '#5686A5', 'Moradia' => '#9C86AD', 'Contas e serviços' => '#568E8B', 'Saúde' => '#B87383', 'Educação' => '#B1A052', 'Lazer' => '#8E94C1', 'Compras' => '#C18F9C', 'Assinaturas' => '#658B79', 'Presentes e doações' => '#A49070', 'Outros' => '#84928C' ) as $name => $color ) {
            $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_expense_categories (user_id,name,slug,color,active,created_at) VALUES (%d,%s,%s,%s,1,%s)", $uid, $name, sanitize_title( $name ), $color, current_time( 'mysql', true ) ) ); $failed = $failed || false === $inserted;
        }
        if ( ! $failed ) { update_user_meta( $uid, 'pds_expenses_seeded', 1 ); }
    }

    public static function category_list( $all = false ) {
        self::seed(); global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,color,active FROM {$wpdb->prefix}pds_expense_categories WHERE user_id=%d" . ( $all ? '' : ' AND active=1' ) . ' ORDER BY active DESC,name,id', get_current_user_id() ), ARRAY_A );
        foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['active'] = (bool) $row['active']; } unset( $row );
        return $rows;
    }

    public static function categories() { return array( 'categories' => self::category_list( true ), 'payment_methods' => self::PAYMENTS, 'accounts' => PDS_Finance::accounts()['accounts'], 'cards' => PDS_Finance::cards()['cards'] ); }

    public static function category( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND user_id=%d", $id, get_current_user_id() ), ARRAY_A );
        return $row ?: self::error( 'Categoria não encontrada ou sem acesso.', 404 );
    }

    public static function save_category( WP_REST_Request $request ) {
        global $wpdb; self::seed(); $id = absint( $request->get_url_params()['id'] ?? 0 );
        if ( $id && is_wp_error( $old = self::category( $id ) ) ) { return $old; }
        $name = $request->get_param( 'name' ); $color = $request->get_param( 'color' ); $active = $request->get_param( 'active' );
        if ( ! is_string( $name ) || ! is_string( $color ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/D', $color ) || ( null !== $active && ! is_bool( $active ) ) ) { return self::error( 'Informe o nome, uma cor válida e o estado da categoria.' ); }
        $name = sanitize_text_field( trim( $name ) );
        if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 ) { return self::error( 'Use um nome de categoria com 2 a 60 caracteres.' ); }
        $slug = substr( sanitize_title( $name ), 0, 170 ); if ( ! $slug ) { $slug = hash( 'sha256', $name ); }
        $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expense_categories WHERE user_id=%d AND slug=%s AND id<>%d", get_current_user_id(), $slug, $id ) );
        if ( $duplicate ) { return self::error( 'Você já tem uma categoria com esse nome. Se estiver arquivada, reative-a.', 409 ); }
        if ( ! $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_expense_categories WHERE user_id=%d", get_current_user_id() ) ) >= 100 ) { return self::error( 'Você pode ter até 100 categorias.' ); }
        $data = array( 'name' => $name, 'slug' => $slug, 'color' => strtoupper( $color ), 'active' => null === $active ? ( $id ? (int) $old['active'] : 1 ) : (int) $active );
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_expense_categories', $data, array( 'id' => $id, 'user_id' => get_current_user_id() ) ) : $wpdb->insert( $wpdb->prefix . 'pds_expense_categories', $data + array( 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
        return false === $ok ? self::error( 'Não foi possível salvar a categoria. Confira se o nome já está em uso.', 409 ) : array( 'id' => $id ?: (int) $wpdb->insert_id );
    }

    public static function archive_category( WP_REST_Request $request ) {
        global $wpdb; $category = self::category( absint( $request['id'] ) ); if ( is_wp_error( $category ) ) { return $category; }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_expense_categories', array( 'active' => 0 ), array( 'id' => $category['id'], 'user_id' => get_current_user_id() ) );
        return false === $ok ? self::error( 'Não foi possível arquivar a categoria.', 500 ) : array( 'success' => true );
    }

    public static function valid_date( $value ) { return is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && $value >= '1900-01-01'; }

    public static function amount( $value ) {
        if ( is_int( $value ) || is_float( $value ) ) {
            if ( ! is_finite( (float) $value ) || $value < 0.01 || $value > 9999999999.99 || abs( round( $value, 2 ) - $value ) > 0.000001 ) { return self::error( 'Informe um valor positivo em reais, com até duas casas decimais.' ); }
            return number_format( $value, 2, '.', '' );
        }
        if ( ! is_string( $value ) ) { return self::error( 'Informe o valor do gasto.' ); }
        $value = trim( $value );
        if ( strpos( $value, ',' ) !== false ) {
            if ( ! preg_match( '/^(?:\d{1,3}(?:\.\d{3})+|\d+),\d{1,2}$/D', $value ) ) { return self::error( 'Use um valor como 35,90 ou 1.250,00.' ); }
            $value = str_replace( array( '.', ',' ), array( '', '.' ), $value );
        }
        if ( ! preg_match( '/^\d{1,10}(?:\.\d{1,2})?$/D', $value ) || (float) $value < 0.01 || (float) $value > 9999999999.99 ) { return self::error( 'Informe um valor positivo em reais, com até duas casas decimais.' ); }
        $pieces = explode( '.', $value ); return (string) (int) $pieces[0] . '.' . str_pad( $pieces[1] ?? '', 2, '0' );
    }

    private static function cast( $row ) {
        foreach ( array( 'id', 'category_id' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; }
        foreach ( array( 'account_id', 'card_id', 'installments', 'installment_start' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; }
        $row['payable_id'] = $row['payable_id'] ? (int) $row['payable_id'] : null;
        $row['fuel_record_id'] = $row['fuel_record_id'] ? (int) $row['fuel_record_id'] : null;
        $row['amount'] = (float) $row['amount']; $row['category_active'] = (bool) $row['category_active']; unset( $row['user_id'], $row['external_ref'] ); return $row;
    }

    public static function get_entry( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT e.*,c.name AS category_name,c.color AS category_color,c.active AS category_active FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.user_id=e.user_id WHERE e.id=%d AND e.user_id=%d", $id, get_current_user_id() ), ARRAY_A );
        return $row ? self::cast( $row ) : self::error( 'Gasto não encontrado ou sem acesso.', 404 );
    }
    public static function entry( WP_REST_Request $request ) { return self::get_entry( absint( $request['id'] ) ); }

    public static function save_entry( WP_REST_Request $request, $external_ref = null, $options = array() ) {
        $allowed = self::permission(); if ( is_wp_error( $allowed ) ) { return $allowed; }
        global $wpdb; $uid = get_current_user_id(); $id = absint( $request->get_url_params()['id'] ?? 0 );
        if ( $external_ref && ( ! is_string( $external_ref ) || ! preg_match( '/^(telegram-expense|statement|payable):[a-f0-9]{64}$/D', $external_ref ) ) ) { return self::error( 'Origem de registro inválida.' ); }
        if ( $external_ref ) { $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE user_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $existing ) { return self::get_entry( $existing ); } }
        if ( $id && is_wp_error( $old = self::get_entry( $id ) ) ) { return $old; }
        $amount = self::amount( $request->get_param( 'amount' ) ); if ( is_wp_error( $amount ) ) { return $amount; }
        if ( $id && $old['fuel_record_id'] && ( abs( (float) $old['amount'] - (float) $amount ) > 0.000001 || $request->get_param( 'spent_on' ) !== $old['spent_on'] ) ) { return self::error( 'Altere o valor e a data desse gasto no registro original em Meu Combustível.', 409 ); }
        if ( $id && $old['payable_id'] && ( abs( (float) $old['amount'] - (float) $amount ) > 0.000001 || $request->get_param( 'spent_on' ) !== $old['spent_on'] ) ) { return self::error( 'Corrija a baixa dessa despesa em Contas a pagar.', 409 ); }
        $date = $request->get_param( 'spent_on' ); $description = $request->get_param( 'description' ); $payment = $request->get_param( 'payment_method' ) ?? ''; $merchant = $request->get_param( 'merchant' ) ?? ''; $notes = $request->get_param( 'notes' ) ?? '';
        if ( ! self::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return self::error( 'Informe uma data válida, até hoje, para o gasto realizado.' ); }
        if ( ! is_string( $description ) || ! is_string( $merchant ) || ! is_string( $notes ) || ! is_string( $payment ) || ! array_key_exists( $payment, self::PAYMENTS ) ) { return self::error( 'Confira a descrição, a forma de pagamento e as observações.' ); }
        $description = sanitize_text_field( trim( $description ) ); $merchant = sanitize_text_field( trim( $merchant ) ); $notes = sanitize_textarea_field( $notes );
        if ( mb_strlen( $description ) < 2 || mb_strlen( $description ) > 180 || mb_strlen( $merchant ) > 120 || mb_strlen( $notes ) > 500 ) { return self::error( 'Use descrição de 2 a 180 caracteres, estabelecimento de até 120 e observação de até 500.' ); }
        $category_id = $request->get_param( 'category_id' );
        if ( ( ! is_int( $category_id ) && ! is_string( $category_id ) ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $category_id ) ) { return self::error( 'Escolha a categoria do gasto.' ); }
        $category = self::category( $category_id ); if ( is_wp_error( $category ) ) { return $category; }
        if ( ! $category['active'] && ( ! $id || $old['category_id'] !== (int) $category_id ) ) { return self::error( 'Escolha uma categoria ativa.' ); }
        $data = array( 'category_id' => (int) $category_id, 'spent_on' => $date, 'description' => $description, 'amount' => $amount, 'payment_method' => $payment, 'merchant' => $merchant, 'notes' => $notes, 'updated_at' => current_time( 'mysql', true ) );
        if ( ! $id && isset( $options['payable_id'] ) ) { $data['payable_id'] = (int) $options['payable_id']; }
        $finance = PDS_Finance::prepare( $request, $id ? $old : null, $options ); if ( is_wp_error( $finance ) ) { return $finance; }
        foreach ( $finance as $key => $value ) { if ( substr( $key, 0, 1 ) !== '_' ) { $data[ $key ] = $value; } }
        $owns_transaction = empty( $options['in_transaction'] );
        if ( $owns_transaction ) { $wpdb->query( 'START TRANSACTION' ); }
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND user_id=%d FOR UPDATE", $category_id, $uid ), ARRAY_A );
        if ( ! $locked || ( ! $locked['active'] && ( ! $id || $old['category_id'] !== (int) $category_id ) ) ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } return self::error( 'A categoria mudou. Atualize a página e escolha novamente.' ); }
        $source = $external_ref && strpos( $external_ref, 'statement:' ) === 0 ? 'statement' : ( $external_ref && strpos( $external_ref, 'payable:' ) === 0 ? 'payable' : ( $external_ref ? 'telegram' : 'manual' ) );
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_expenses', $data, array( 'id' => $id, 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_expenses', $data + array( 'user_id' => $uid, 'created_at' => current_time( 'mysql', true ), 'external_ref' => $external_ref, 'source' => $source ) );
        $saved = $id ?: (int) $wpdb->insert_id;
        if ( false === $ok ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } if ( $external_ref ) { $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE user_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $duplicate ) { return self::get_entry( $duplicate ); } } return self::error( 'Não foi possível salvar o gasto.', 500 ); }
        $scheduled = PDS_Finance::save_schedule( $saved, $uid, $finance, $id ? $old : null, $amount, $date, $options ); if ( is_wp_error( $scheduled ) ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } return $scheduled; }
        if ( $owns_transaction ) { $wpdb->query( 'COMMIT' ); } $result = self::get_entry( $saved );
        if ( $owns_transaction && ! is_wp_error( $result ) ) { do_action( $id ? 'pds_expense_updated' : 'pds_expense_created', $result, $uid ); }
        return $result;
    }

    public static function delete_entry( WP_REST_Request $request ) {
        global $wpdb; $row = self::get_entry( absint( $request['id'] ) ); if ( is_wp_error( $row ) ) { return $row; }
        if ( $row['payable_id'] ) { return self::error( 'Desfaça a baixa em Contas a pagar para remover a despesa correspondente.', 409 ); }
        if ( $row['fuel_record_id'] ) { return self::error( 'Exclua o abastecimento original em Meu Combustível para remover também esse gasto.', 409 ); }
        $wpdb->query( 'START TRANSACTION' ); $removed = PDS_Finance::remove_expense( $row['id'], get_current_user_id() ); if ( is_wp_error( $removed ) ) { $wpdb->query( 'ROLLBACK' ); return $removed; }
        $ok = $wpdb->delete( $wpdb->prefix . 'pds_expenses', array( 'id' => $row['id'], 'user_id' => get_current_user_id() ) );
        if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível excluir o gasto.', 500 ); } $wpdb->query( 'COMMIT' );
        do_action( 'pds_expense_deleted', $row, get_current_user_id() ); return array( 'success' => true );
    }

    private static function filters( WP_REST_Request $request ) {
        $from = $request->get_param( 'from' ) ?? current_time( 'Y-m-01' ); $to = $request->get_param( 'to' ) ?? current_time( 'Y-m-d' );
        if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || $from > $to || ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS > 730 ) { return self::error( 'Escolha um período válido de até dois anos, com início antes do fim.' ); }
        $category = $request->get_param( 'category_id' ) ?? ''; $q = $request->get_param( 'q' ) ?? ''; $payment = $request->get_param( 'payment_method' ) ?? '';
        if ( ( ! is_int( $category ) && ! is_string( $category ) ) || ( '' !== (string) $category && ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $category ) ) || ! is_string( $q ) || mb_strlen( $q ) > 120 || ! is_string( $payment ) || ! array_key_exists( $payment, self::PAYMENTS ) ) { return self::error( 'Confira os filtros do histórico.' ); }
        if ( $category && is_wp_error( $found = self::category( $category ) ) ) { return $found; }
        return array( 'from' => $from, 'to' => $to, 'category_id' => (int) $category, 'q' => trim( $q ), 'payment_method' => $payment );
    }

    private static function where( $f ) {
        global $wpdb;
        $sql = $wpdb->prepare( 'e.user_id=%d AND e.spent_on BETWEEN %s AND %s', get_current_user_id(), $f['from'], $f['to'] );
        if ( $f['category_id'] ) { $sql .= $wpdb->prepare( ' AND e.category_id=%d', $f['category_id'] ); }
        if ( $f['payment_method'] ) { $sql .= $wpdb->prepare( ' AND e.payment_method=%s', $f['payment_method'] ); }
        if ( $f['q'] ) { $like = '%' . $wpdb->esc_like( $f['q'] ) . '%'; $sql .= $wpdb->prepare( ' AND (e.description LIKE %s OR e.merchant LIKE %s OR e.notes LIKE %s)', $like, $like, $like ); }
        return $sql;
    }

    public static function entries( WP_REST_Request $request ) {
        global $wpdb; $f = self::filters( $request ); if ( is_wp_error( $f ) ) { return $f; }
        $per_page = $request->get_param( 'per_page' ) ?? 20; $page = $request->get_param( 'page' ) ?? 1;
        if ( ! is_scalar( $per_page ) || ! is_scalar( $page ) || ! preg_match( '/^[1-9][0-9]{0,5}$/D', (string) $page ) || ! preg_match( '/^[1-9][0-9]{0,2}$/D', (string) $per_page ) ) { return self::error( 'Página inválida.' ); }
        $per_page = min( 100, (int) $per_page ); $page = (int) $page; $where = self::where( $f );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_expenses e WHERE $where" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*,c.name AS category_name,c.color AS category_color,c.active AS category_active FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.user_id=e.user_id WHERE $where ORDER BY e.spent_on DESC,e.id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
        return array( 'entries' => array_map( array( __CLASS__, 'cast' ), $rows ), 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ), 'filters' => $f );
    }

    public static function report( WP_REST_Request $request ) {
        global $wpdb; self::seed(); $f = self::filters( $request ); if ( is_wp_error( $f ) ) { return $f; } $where = self::where( $f );
        $summary = $wpdb->get_row( "SELECT COALESCE(SUM(e.amount),0) AS spent,COUNT(*) AS count,COALESCE(MAX(e.amount),0) AS largest FROM {$wpdb->prefix}pds_expenses e WHERE $where", ARRAY_A );
        $days = (int) ( ( strtotime( $f['to'] . ' UTC' ) - strtotime( $f['from'] . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
        $previous = $f; $previous['to'] = gmdate( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) - DAY_IN_SECONDS ); $previous['from'] = gmdate( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) - $days * DAY_IN_SECONDS );
        $previous_where = self::where( $previous ); $previous_total = (float) $wpdb->get_var( "SELECT COALESCE(SUM(e.amount),0) FROM {$wpdb->prefix}pds_expenses e WHERE $previous_where" );
        $summary = array( 'spent' => (float) $summary['spent'], 'count' => (int) $summary['count'], 'largest' => (float) $summary['largest'], 'daily_average' => round( (float) $summary['spent'] / $days, 2 ), 'days' => $days, 'previous_total' => $previous_total, 'change_percent' => $previous_total > 0 ? round( ( (float) $summary['spent'] - $previous_total ) / $previous_total * 100, 1 ) : null, 'previous_from' => $previous['from'], 'previous_to' => $previous['to'] );
        $categories = $wpdb->get_results( "SELECT c.id,c.name,c.color,SUM(e.amount) AS total,COUNT(*) AS count FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.user_id=e.user_id WHERE $where GROUP BY c.id,c.name,c.color ORDER BY total DESC,c.name", ARRAY_A );
        foreach ( $categories as &$c ) { $c['id'] = (int) $c['id']; $c['total'] = (float) $c['total']; $c['count'] = (int) $c['count']; $c['percent'] = $summary['spent'] > 0 ? round( $c['total'] / $summary['spent'] * 100, 1 ) : 0; } unset( $c );
        $monthly = $days > 62; $format = $monthly ? '%Y-%m' : '%Y-%m-%d';
        $trend = $wpdb->get_results( "SELECT DATE_FORMAT(e.spent_on,'$format') AS period,SUM(e.amount) AS total FROM {$wpdb->prefix}pds_expenses e WHERE $where GROUP BY period ORDER BY period", ARRAY_A );
        foreach ( $trend as &$point ) { $point['total'] = (float) $point['total']; } unset( $point );
        $payments = $wpdb->get_results( "SELECT e.payment_method,SUM(e.amount) AS total,COUNT(*) AS count FROM {$wpdb->prefix}pds_expenses e WHERE $where GROUP BY e.payment_method ORDER BY total DESC", ARRAY_A );
        foreach ( $payments as &$payment ) { $payment['name'] = self::PAYMENTS[ $payment['payment_method'] ] ?? 'Não informado'; $payment['total'] = (float) $payment['total']; $payment['count'] = (int) $payment['count']; } unset( $payment );
        return array( 'summary' => $summary, 'categories' => $categories, 'trend' => $trend, 'trend_unit' => $monthly ? 'month' : 'day', 'payments' => $payments, 'filters' => $f );
    }
}
