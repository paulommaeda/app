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
    public static function permission( $request = null ) {
        if ( ! is_user_logged_in() ) { return self::error( 'Entre na sua conta para continuar.', 401 ); }
        if ( ! pds_user_can_access_service( 'gastos' ) ) { return self::error( 'O serviço Meu Financeiro não está liberado para sua conta.', 403 ); }
        return $request ? PDS_Finance_Spaces::request_allowed( $request ) : ( PDS_Finance_Spaces::can( 'write' ) ? true : PDS_Finance_Spaces::error( 'Você não tem permissão para registrar neste financeiro.' ) );
    }

    public static function seed() {
        $uid = PDS_Finance_Spaces::id();
        global $wpdb; if ( ! $uid || (int) $wpdb->get_var( $wpdb->prepare( "SELECT categories_seeded FROM {$wpdb->prefix}pds_fin_spaces WHERE id=%d", $uid ) ) ) { return; }
        $failed = false;
        foreach ( array( 'Alimentação' => '#D68D53', 'Mercado' => '#71946C', 'Transporte' => '#5686A5', 'Moradia' => '#9C86AD', 'Contas e serviços' => '#568E8B', 'Saúde' => '#B87383', 'Educação' => '#B1A052', 'Lazer' => '#8E94C1', 'Compras' => '#C18F9C', 'Assinaturas' => '#658B79', 'Presentes e doações' => '#A49070', 'Outros' => '#84928C' ) as $name => $color ) {
            $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_expense_categories (space_id,user_id,created_by,updated_by,name,slug,color,active,created_at) VALUES (%d,%d,%d,%d,%s,%s,%s,1,%s)", $uid, PDS_Finance_Spaces::owner( $uid ), get_current_user_id(), get_current_user_id(), $name, sanitize_title( $name ), $color, current_time( 'mysql', true ) ) ); $failed = $failed || false === $inserted;
        }
        if ( ! $failed ) { $wpdb->update( $wpdb->prefix . 'pds_fin_spaces', array( 'categories_seeded' => 1 ), array( 'id' => $uid ) ); }
    }

    public static function category_list( $all = false ) {
        self::seed(); global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,color,active FROM {$wpdb->prefix}pds_expense_categories WHERE space_id=%d" . ( $all ? '' : ' AND active=1' ) . ' ORDER BY active DESC,name,id', PDS_Finance_Spaces::id() ), ARRAY_A );
        foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['active'] = (bool) $row['active']; } unset( $row );
        return $rows;
    }

    public static function categories() { return array( 'categories' => self::category_list( true ), 'payment_methods' => self::PAYMENTS, 'accounts' => PDS_Finance::accounts()['accounts'], 'account_types' => PDS_Finance_Account_Types::listing()['account_types'], 'shared_card_enabled' => PDS_Finance_Sharing::enabled(), 'people' => PDS_Finance_Sharing::list_people(), 'cards' => PDS_Finance::cards()['cards'] ); }

    public static function category( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND space_id=%d", $id, PDS_Finance_Spaces::id() ), ARRAY_A );
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
        $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expense_categories WHERE space_id=%d AND slug=%s AND id<>%d", PDS_Finance_Spaces::id(), $slug, $id ) );
        if ( $duplicate ) { return self::error( 'Você já tem uma categoria com esse nome. Se estiver arquivada, reative-a.', 409 ); }
        if ( ! $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_expense_categories WHERE space_id=%d", PDS_Finance_Spaces::id() ) ) >= 100 ) { return self::error( 'Você pode ter até 100 categorias.' ); }
        $data = array( 'name' => $name, 'slug' => $slug, 'color' => strtoupper( $color ), 'active' => null === $active ? ( $id ? (int) $old['active'] : 1 ) : (int) $active );
        $ok = $id ? PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_expense_categories', $data, array( 'id' => $id, 'space_id' => PDS_Finance_Spaces::id() ) ) : PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_expense_categories', $data + array( 'space_id' => PDS_Finance_Spaces::id(), 'created_at' => current_time( 'mysql', true ) ) );
        return false === $ok ? self::error( 'Não foi possível salvar a categoria. Confira se o nome já está em uso.', 409 ) : array( 'id' => $id ?: (int) $wpdb->insert_id );
    }

    public static function archive_category( WP_REST_Request $request ) {
        global $wpdb; $category = self::category( absint( $request['id'] ) ); if ( is_wp_error( $category ) ) { return $category; }
        $ok = PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_expense_categories', array( 'active' => 0 ), array( 'id' => $category['id'], 'space_id' => PDS_Finance_Spaces::id() ) );
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
        $row['person_id'] = $row['person_id'] ? (int) $row['person_id'] : null; $row['person_name'] = PDS_Finance_Splits::names( $row, (int) $row['space_id'] );
        $row['payable_id'] = $row['payable_id'] ? (int) $row['payable_id'] : null;
        $row['fuel_record_id'] = $row['fuel_record_id'] ? (int) $row['fuel_record_id'] : null;
        $row['amount'] = (float) $row['amount']; $row['category_active'] = (bool) $row['category_active']; $row = PDS_Finance_Spaces::attribution( $row ); unset( $row['space_id'], $row['user_id'], $row['external_ref'] ); return $row;
    }

    public static function get_entry( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT e.*,c.name AS category_name,c.color AS category_color,c.active AS category_active FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.space_id=e.space_id WHERE e.id=%d AND e.space_id=%d", $id, PDS_Finance_Spaces::id() ), ARRAY_A );
        if ( ! $row ) { return self::error( 'Gasto não encontrado ou sem acesso.', 404 ); } $result = self::cast( $row ); if ( $row['card_id'] && ( PDS_Finance_Sharing::enabled() || $row['person_id'] || $row['sharing_mode'] ) ) { $result['sharing_context'] = PDS_Finance_Splits::context( $row, PDS_Finance_Spaces::id() ); } return $result;
    }
    public static function entry( WP_REST_Request $request ) { return self::get_entry( absint( $request['id'] ) ); }

    public static function save_entry( WP_REST_Request $request, $external_ref = null, $options = array() ) {
        $allowed = self::permission(); if ( is_wp_error( $allowed ) ) { return $allowed; }
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $id = absint( $request->get_url_params()['id'] ?? 0 );
        if ( $external_ref && ( ! is_string( $external_ref ) || ! preg_match( '/^(telegram-expense|statement|payable|subscription):[a-f0-9]{64}$/D', $external_ref ) ) ) { return self::error( 'Origem de registro inválida.' ); }
        if ( $external_ref ) { $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE space_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $existing ) { return self::get_entry( $existing ); } }
        if ( $id && is_wp_error( $old = self::get_entry( $id ) ) ) { return $old; }
        $amount = self::amount( $request->get_param( 'amount' ) ); if ( is_wp_error( $amount ) ) { return $amount; }
        if ( $id && $old['fuel_record_id'] && ( abs( (float) $old['amount'] - (float) $amount ) > 0.000001 || $request->get_param( 'spent_on' ) !== $old['spent_on'] ) ) { return self::error( 'Altere o valor e a data desse gasto no registro original em Meu Combustível.', 409 ); }
        if ( $id && $old['payable_id'] && (int) ( $options['payable_correction'] ?? 0 ) !== (int) $old['payable_id'] && ( abs( (float) $old['amount'] - (float) $amount ) > 0.000001 || $request->get_param( 'spent_on' ) !== $old['spent_on'] ) ) { return self::error( 'Corrija a baixa dessa despesa em Contas a pagar.', 409 ); }
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
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND space_id=%d FOR UPDATE", $category_id, $uid ), ARRAY_A );
        if ( ! $locked || ( ! $locked['active'] && ( ! $id || $old['category_id'] !== (int) $category_id ) ) ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } return self::error( 'A categoria mudou. Atualize a página e escolha novamente.' ); }
        $source = $external_ref && strpos( $external_ref, 'statement:' ) === 0 ? 'statement' : ( $external_ref && strpos( $external_ref, 'payable:' ) === 0 ? 'payable' : ( $external_ref && strpos( $external_ref, 'subscription:' ) === 0 ? 'subscription' : ( $external_ref ? 'telegram' : 'manual' ) ) );
        $ok = $id ? PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_expenses', $data, array( 'id' => $id, 'space_id' => $uid ) ) : PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_expenses', $data + array( 'space_id' => $uid, 'created_at' => current_time( 'mysql', true ), 'external_ref' => $external_ref, 'source' => $source ) );
        $saved = $id ?: (int) $wpdb->insert_id;
        if ( false === $ok ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } if ( $external_ref ) { $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE space_id=%d AND external_ref=%s", $uid, $external_ref ) ); if ( $duplicate ) { return self::get_entry( $duplicate ); } } return self::error( 'Não foi possível salvar o gasto.', 500 ); }
        $split = PDS_Finance_Splits::persist( $saved, $uid, $finance ); if ( is_wp_error( $split ) ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } return $split; }
        $scheduled = PDS_Finance::save_schedule( $saved, $uid, $finance, $id ? $old : null, $amount, $date, $options ); if ( is_wp_error( $scheduled ) ) { if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); } return $scheduled; }
        if ( $owns_transaction ) { $wpdb->query( 'COMMIT' ); } $result = self::get_entry( $saved );
        if ( $owns_transaction && ! is_wp_error( $result ) ) { do_action( $id ? 'pds_expense_updated' : 'pds_expense_created', $result, get_current_user_id(), $uid ); }
        return $result;
    }

    public static function delete_entry( WP_REST_Request $request ) {
        global $wpdb; $row = self::get_entry( absint( $request['id'] ) ); if ( is_wp_error( $row ) ) { return $row; }
        if ( $row['payable_id'] ) { return self::error( 'Desfaça a baixa em Contas a pagar para remover a despesa correspondente.', 409 ); }
        if ( $row['fuel_record_id'] ) { return self::error( 'Exclua o abastecimento original em Meu Combustível para remover também esse gasto.', 409 ); }
        $wpdb->query( 'START TRANSACTION' ); $removed = PDS_Finance::remove_expense( $row['id'], PDS_Finance_Spaces::id() ); if ( is_wp_error( $removed ) ) { $wpdb->query( 'ROLLBACK' ); return $removed; }
        $ok = $wpdb->delete( $wpdb->prefix . 'pds_expenses', array( 'id' => $row['id'], 'space_id' => PDS_Finance_Spaces::id() ) );
        if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível excluir o gasto.', 500 ); } $wpdb->query( 'COMMIT' );
        do_action( 'pds_expense_deleted', $row, get_current_user_id(), PDS_Finance_Spaces::id() ); return array( 'success' => true );
    }

    private static function filters( WP_REST_Request $request ) {
        $from = $request->get_param( 'from' ) ?? current_time( 'Y-m-01' ); $to = $request->get_param( 'to' ) ?? current_time( 'Y-m-d' );
        if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || $from > $to || ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS > 730 ) { return self::error( 'Escolha um período válido de até dois anos, com início antes do fim.' ); }
        $category = $request->get_param( 'category_id' ) ?? ''; $q = $request->get_param( 'q' ) ?? ''; $payment = $request->get_param( 'payment_method' ) ?? '';
        if ( ( ! is_int( $category ) && ! is_string( $category ) ) || ( '' !== (string) $category && ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $category ) ) || ! is_string( $q ) || mb_strlen( $q ) > 120 || ! is_string( $payment ) || ! array_key_exists( $payment, self::PAYMENTS ) ) { return self::error( 'Confira os filtros do histórico.' ); }
        if ( $category && is_wp_error( $found = self::category( $category ) ) ) { return $found; }
        return array( 'from' => $from, 'to' => $to, 'category_id' => (int) $category, 'q' => trim( $q ), 'payment_method' => $payment );
    }

    private static function where( $f, $date_column = 'e.spent_on' ) {
        global $wpdb;
        $sql = $wpdb->prepare( 'e.space_id=%d AND ' . $date_column . ' BETWEEN %s AND %s', PDS_Finance_Spaces::id(), $f['from'], $f['to'] );
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
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*,c.name AS category_name,c.color AS category_color,c.active AS category_active FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.space_id=e.space_id WHERE $where ORDER BY e.spent_on DESC,e.id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
        return array( 'entries' => array_map( array( __CLASS__, 'cast' ), $rows ), 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per_page ) ), 'filters' => $f );
    }

    /** Dashboard values use real installment amounts and their invoice due dates. */
    private static function report_source( $f ) {
        global $wpdb; $p = $wpdb->prefix; $fields = 'e.id,e.space_id,e.created_by,e.updated_by,e.category_id,e.description,e.payment_method,e.merchant,e.notes,e.source,e.card_id,e.account_id,e.installments,e.installment_start,e.fuel_record_id,e.payable_id,e.person_id,e.sharing_mode,e.sharing_start';
        $direct_where = self::where( $f ); $card_where = self::where( $f, 'i.due_on' );
        $direct = "SELECT $fields,e.spent_on,e.spent_on AS purchase_on,e.amount,0 AS is_installment,NULL AS installment_no,NULL AS installment_total,NULL AS invoice_month FROM {$p}pds_expenses e WHERE $direct_where AND (e.payment_method<>'credit' OR e.card_id=0)";
        $credit = "SELECT $fields,i.due_on AS spent_on,e.spent_on AS purchase_on,s.amount,1 AS is_installment,s.installment_no,s.installment_total,i.invoice_month FROM {$p}pds_expenses e INNER JOIN {$p}pds_fin_installments s ON s.expense_id=e.id AND s.space_id=e.space_id AND s.card_id=e.card_id INNER JOIN {$p}pds_fin_invoices i ON i.id=s.invoice_id AND i.space_id=e.space_id AND i.card_id=s.card_id WHERE $card_where AND e.payment_method='credit' AND e.card_id>0";
        return "$direct UNION ALL $credit";
    }
    public static function report( WP_REST_Request $request ) {
        global $wpdb; self::seed(); $query = clone $request; if ( ! $query->has_param( 'to' ) ) { $query->set_param( 'to', current_time( 'Y-m-t' ) ); }
        $f = self::filters( $query ); if ( is_wp_error( $f ) ) { return $f; } $source = self::report_source( $f );
        if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) { return self::error( 'Não foi possível consultar os gastos do período.', 500 ); }
        $summary = $wpdb->get_row( "SELECT COALESCE(SUM(e.amount),0) AS spent,COUNT(*) AS count,COALESCE(MAX(e.amount),0) AS largest,COALESCE(SUM(CASE WHEN e.is_installment=1 THEN e.amount ELSE 0 END),0) AS card_spent FROM ($source) e", ARRAY_A );
        if ( $wpdb->last_error || ! $summary ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível totalizar as parcelas e os gastos.', 500 ); }
        $days = (int) ( ( strtotime( $f['to'] . ' UTC' ) - strtotime( $f['from'] . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
        $previous = $f;
        if ( substr( $f['from'], 8 ) === '01' && substr( $f['from'], 0, 7 ) === substr( $f['to'], 0, 7 ) && $f['to'] === gmdate( 'Y-m-t', strtotime( $f['from'] . ' UTC' ) ) ) {
            $previous['from'] = PDS_Finance::add_month( substr( $f['from'], 0, 7 ), -1 ) . '-01'; $previous['to'] = gmdate( 'Y-m-t', strtotime( $previous['from'] . ' UTC' ) );
        } else { $previous['to'] = gmdate( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) - DAY_IN_SECONDS ); $previous['from'] = gmdate( 'Y-m-d', strtotime( $f['from'] . ' UTC' ) - $days * DAY_IN_SECONDS ); }
        $previous_source = self::report_source( $previous ); $previous_total = (float) $wpdb->get_var( "SELECT COALESCE(SUM(e.amount),0) FROM ($previous_source) e" );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível comparar os períodos.', 500 ); }
        $summary = array( 'spent' => (float) $summary['spent'], 'count' => (int) $summary['count'], 'largest' => (float) $summary['largest'], 'card_spent' => (float) $summary['card_spent'], 'daily_average' => round( (float) $summary['spent'] / $days, 2 ), 'days' => $days, 'previous_total' => $previous_total, 'change_percent' => $previous_total > 0 ? round( ( (float) $summary['spent'] - $previous_total ) / $previous_total * 100, 1 ) : null, 'previous_from' => $previous['from'], 'previous_to' => $previous['to'] );
        $categories = $wpdb->get_results( "SELECT c.id,c.name,c.color,SUM(e.amount) AS total,COUNT(*) AS count FROM ($source) e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.space_id=e.space_id GROUP BY c.id,c.name,c.color ORDER BY total DESC,c.name", ARRAY_A );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível agrupar os gastos por categoria.', 500 ); }
        foreach ( $categories as &$c ) { $c['id'] = (int) $c['id']; $c['total'] = (float) $c['total']; $c['count'] = (int) $c['count']; $c['percent'] = $summary['spent'] > 0 ? round( $c['total'] / $summary['spent'] * 100, 1 ) : 0; } unset( $c );
        $monthly = $days > 62; $format = $monthly ? '%Y-%m' : '%Y-%m-%d';
        $trend = $wpdb->get_results( "SELECT DATE_FORMAT(e.spent_on,'$format') AS period,SUM(e.amount) AS total FROM ($source) e GROUP BY period ORDER BY period", ARRAY_A );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível calcular a evolução dos gastos.', 500 ); }
        foreach ( $trend as &$point ) { $point['total'] = (float) $point['total']; } unset( $point );
        $payments = $wpdb->get_results( "SELECT e.payment_method,SUM(e.amount) AS total,COUNT(*) AS count FROM ($source) e GROUP BY e.payment_method ORDER BY total DESC", ARRAY_A );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível agrupar as formas de pagamento.', 500 ); }
        foreach ( $payments as &$payment ) { $payment['name'] = self::PAYMENTS[ $payment['payment_method'] ] ?? 'Não informado'; $payment['total'] = (float) $payment['total']; $payment['count'] = (int) $payment['count']; } unset( $payment );
        $recent = $wpdb->get_results( "SELECT e.*,c.name AS category_name,c.color AS category_color,c.active AS category_active FROM ($source) e INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=e.category_id AND c.space_id=e.space_id ORDER BY e.spent_on DESC,e.id DESC,e.installment_no DESC LIMIT 5", ARRAY_A );
        if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível consultar os lançamentos do período.', 500 ); }
        $recent = array_map( static function ( $row ) { $row = self::cast( $row ); $row['is_installment'] = (bool) $row['is_installment']; foreach ( array( 'installment_no', 'installment_total' ) as $key ) { $row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ]; } return $row; }, $recent );
        if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível concluir o dashboard.', 500 ); }
        return array( 'basis' => 'invoice_installments', 'summary' => $summary, 'categories' => $categories, 'trend' => $trend, 'trend_unit' => $monthly ? 'month' : 'day', 'payments' => $payments, 'recent' => $recent, 'filters' => $f );
    }
}
