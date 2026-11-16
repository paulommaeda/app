<?php
defined( 'ABSPATH' ) || exit;

/** Monthly card services. Forecasts enter invoices; only a confirmed charge becomes an expense. */
final class PDS_Finance_Subscriptions {
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_finance_subscriptions', array( __CLASS__, 'cron' ) );
        if ( ! wp_next_scheduled( 'pds_finance_subscriptions' ) ) { wp_schedule_event( time() + 90, 'hourly', 'pds_finance_subscriptions' ); }
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-subscriptions', PDS_URL . 'assets/subscriptions.js', array( 'pds-finance' ), PDS_VERSION, true ); } );
        add_action( 'pds_expense_created', static function ( $entry, $actor, $space = 0 ) { self::adopt_expense( $entry, $space ?: PDS_Finance_Spaces::personal( $actor ) ); }, 10, 3 );
        add_action( 'pds_expense_updated', static function ( $entry, $actor, $space = 0 ) { self::adopt_expense( $entry, $space ?: PDS_Finance_Spaces::personal( $actor ) ); }, 10, 3 );
        add_action( 'pds_expense_deleted', static function ( $e, $actor, $space = 0 ) { global $wpdb; $uid = $space ?: PDS_Finance_Spaces::personal( $actor ); PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', array( 'status' => 'skipped', 'expense_id' => null ), array( 'expense_id' => $e['id'], 'space_id' => $uid ) ); }, 10, 3 );
    }
    public static function routes() {
        foreach ( array(
            '/finance/subscriptions' => array( 'GET' => 'listing', 'POST' => 'save' ),
            '/finance/subscriptions/suggestions' => array( 'GET' => 'suggestions' ),
            '/finance/subscriptions/source/(?P<id>\d+)' => array( 'GET' => 'source_request' ),
            '/finance/subscriptions/(?P<id>\d+)' => array( 'PUT' => 'save', 'DELETE' => 'remove' ),
            '/finance/subscription-charges/(?P<id>\d+)/confirm' => array( 'POST' => 'confirm_request' ),
            '/finance/subscription-charges/(?P<id>\d+)' => array( 'DELETE' => 'cancel_charge' ),
        ) as $path => $methods ) {
            $routes = array(); foreach ( $methods as $method => $callback ) { $routes[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ); } register_rest_route( 'pds/v1', $path, $routes );
        }
    }
    private static function id( $r ) { return absint( $r->get_url_params()['id'] ?? 0 ); }
    private static function error( $m, $s = 400 ) { return PDS_Finance::error( $m, $s ); }
    private static function positive( $v ) { return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $v ) ? (int) $v : 0; }
    public static function key( $text ) { return mb_substr( PDS_Finance_Import::normalized( preg_replace( '/\d{5,}/u', '', $text ) ), 0, 180 ); }
    private static function find( $id, $uid, $lock = false ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_subscriptions WHERE id=%d AND space_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id, $uid ), ARRAY_A ); return $row ?: self::error( 'Assinatura não encontrada ou sem acesso.', 404 );
    }
    /** Invoice following the latest actual closing, including custom closing dates. */
    public static function horizon( $card, $today = '' ) {
        $today = $today ?: current_time( 'Y-m-d' ); $month = substr( $today, 0, 7 ); $last = PDS_Finance::add_month( $month, -2 );
        for ( $i = -1; $i <= 2; $i++ ) { $m = PDS_Finance::add_month( $month, $i ); $cycle = PDS_Finance::billing_cycle( $m, $card ); if ( $cycle['closing_on'] <= $today ) { $last = $m; } }
        return PDS_Finance::add_month( $last, 1 );
    }
    public static function charge_date( $month, $day, $card ) {
        for ( $i = -2; $i <= 0; $i++ ) { $date = PDS_Finance::day( PDS_Finance::add_month( $month, $i ), $day ); if ( PDS_Finance::first_month( $date, $card ) === $month ) { return $date; } }
        return self::error( 'As datas deste cartão não permitem prever esse dia de cobrança. Confira o fechamento da fatura.' );
    }
    private static function source( $id, $uid ) {
        global $wpdb; $e = $wpdb->get_row( $wpdb->prepare( "SELECT e.*,i.invoice_month,i.id AS invoice_id FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_fin_installments p ON p.expense_id=e.id AND p.space_id=e.space_id INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=p.invoice_id AND i.space_id=e.space_id WHERE e.id=%d AND e.space_id=%d AND e.payment_method='credit' AND e.installments=1 AND p.installment_total=1", $id, $uid ), ARRAY_A );
        return $e ?: self::error( 'Escolha uma compra própria à vista no cartão. Parcelamentos não são assinaturas mensais.', 404 );
    }
    public static function source_request( WP_REST_Request $r ) {
        $e = self::source( self::id( $r ), PDS_Finance_Spaces::id() ); if ( is_wp_error( $e ) ) { return $e; } $card = PDS_Finance::find( 'cards', $e['card_id'] );
        return array( 'source_expense_id' => (int) $e['id'], 'name' => $e['description'], 'merchant' => $e['description'], 'card_id' => (int) $e['card_id'], 'category_id' => (int) $e['category_id'], 'amount' => (float) $e['amount'], 'billing_day' => (int) substr( $e['spent_on'], 8 ), 'first_invoice' => max( PDS_Finance::add_month( $e['invoice_month'], 1 ), self::horizon( $card ) ) );
    }
    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }
    public static function save( WP_REST_Request $r ) {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $id = self::id( $r ); $old = $id ? self::find( $id, $uid ) : array(); if ( is_wp_error( $old ) ) { return $old; }
        if ( $old && 'deleted' === $old['status'] ) { return self::error( 'Esta assinatura foi excluída.', 404 ); }
        $status = $r->get_param( 'status' ) ?? ( $old['status'] ?? 'active' ); if ( ! in_array( $status, array( 'active', 'paused', 'canceled' ), true ) ) { return self::error( 'Escolha assinatura ativa, pausada ou cancelada.' ); }
        $data = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) );
        foreach ( array( 'name' => 120, 'merchant' => 180, 'notes' => 500 ) as $field => $limit ) {
            $v = $r->get_param( $field ) ?? ( $old[ $field ] ?? ( 'merchant' === $field ? ( $r->get_param( 'name' ) ?? '' ) : '' ) );
            if ( ! is_string( $v ) ) { return self::error( 'Confira o nome, a descrição na fatura e as observações.' ); } $v = 'notes' === $field ? sanitize_textarea_field( $v ) : sanitize_text_field( trim( $v ) );
            if ( mb_strlen( $v ) > $limit || ( 'notes' !== $field && mb_strlen( $v ) < 2 ) ) { return self::error( 'Confira o tamanho do nome e da descrição na fatura.' ); } $data[ $field ] = $v;
        }
        $amount = PDS_Expenses::amount( $r->get_param( 'amount' ) ?? ( $old['amount'] ?? null ) ); if ( is_wp_error( $amount ) ) { return $amount; } $data['amount'] = $amount;
        $card_id = self::positive( $r->get_param( 'card_id' ) ?? ( $old['card_id'] ?? 0 ) ); $category_id = self::positive( $r->get_param( 'category_id' ) ?? ( $old['category_id'] ?? 0 ) );
        $card = PDS_Finance::find( 'cards', $card_id ); $category = PDS_Expenses::category( $category_id ); foreach ( array( $card, $category ) as $v ) { if ( is_wp_error( $v ) ) { return $v; } }
        if ( 'active' === $status && ( ! $card['active'] || ! $category['active'] ) ) { return self::error( 'Escolha cartão e categoria ativos para gerar as cobranças.' ); }
        $day = $r->get_param( 'billing_day' ) ?? ( $old['billing_day'] ?? null ); if ( ! self::positive( $day ) || (int) $day > 31 ) { return self::error( 'Informe o dia de cobrança de 1 a 31.' ); }
        $source_id = $r->get_param( 'source_expense_id' ); $source = null;
        if ( null !== $source_id && '' !== $source_id ) { if ( $id || ! self::positive( $source_id ) ) { return self::error( 'Compra de origem inválida.' ); } $source = self::source( (int) $source_id, $uid ); if ( is_wp_error( $source ) ) { return $source; } if ( (int) $source['card_id'] !== $card_id ) { return self::error( 'Use o mesmo cartão da compra identificada.' ); } }
        $first = $r->get_param( 'first_invoice' ) ?? ( $old['first_month'] ?? self::horizon( $card ) );
        if ( ! PDS_Finance::month( $first ) || ( ! $id && ( $first < self::horizon( $card ) || $first > PDS_Finance::add_month( current_time( 'Y-m' ), 24 ) ) ) || ( $source && $first <= $source['invoice_month'] ) ) { return self::error( 'Escolha a primeira fatura futura, após a compra de origem e em até 24 meses.' ); }
        if ( $old && $first !== $old['first_month'] ) { return self::error( 'A primeira fatura faz parte do histórico. Altere as próximas cobranças pelos campos de valor e dia.' ); }
        $data += array( 'card_id' => $card_id, 'category_id' => $category_id, 'billing_day' => (int) $day, 'match_key' => self::key( $data['merchant'] ), 'name_key' => hash( 'sha256', self::key( $data['name'] ) ), 'first_month' => $first );
        if ( ! $data['match_key'] || ! self::key( $data['name'] ) ) { return self::error( 'Inclua letras ou números no nome e na descrição do serviço.' ); }
        $wpdb->query( 'START TRANSACTION' );
        if ( $id ) { $locked = self::find( $id, $uid, true ); if ( is_wp_error( $locked ) || 'deleted' === $locked['status'] ) { return self::rollback( self::error( 'Assinatura alterada. Atualize a página.', 409 ) ); } $old = $locked; }
        $card_lock = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_cards WHERE id=%d AND space_id=%d FOR UPDATE", $card_id, $uid ), ARRAY_A );
        $cat_lock = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND space_id=%d FOR UPDATE", $category_id, $uid ) );
        if ( ! $card_lock || ( 'active' === $status && ( ! $card_lock['active'] || ! $cat_lock ) ) ) { return self::rollback( self::error( 'Cartão ou categoria indisponível. Atualize o cadastro.', 409 ) ); }
        $card = $card_lock;
        if ( $source ) { $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND space_id=%d FOR UPDATE", $source['id'], $uid ) ); $source = self::source( $source['id'], $uid ); if ( is_wp_error( $source ) || (int) $source['card_id'] !== $card_id ) { return self::rollback( self::error( 'A compra de origem mudou. Atualize e tente novamente.', 409 ) ); } }
        if ( ! $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_subscriptions WHERE space_id=%d AND status<>'deleted'", $uid ) ) >= 100 ) { return self::rollback( self::error( 'Você pode cadastrar até 100 assinaturas.' ) ); }
        if ( $id ) {
            $data['next_month'] = 'active' === $status && 'active' !== $old['status'] ? max( $first, self::horizon( $card ) ) : $old['next_month'];
            $ok = PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscriptions', $data, array( 'id' => $id, 'space_id' => $uid ) );
        } else { $data += array( 'space_id' => $uid, 'next_month' => $first, 'created_at' => current_time( 'mysql', true ) ); $ok = PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_fin_subscriptions', $data ); $id = (int) $wpdb->insert_id; }
        if ( false === $ok ) { return self::rollback( self::error( 'Não foi possível salvar. Já existe uma assinatura com esse nome nesse cartão?', 409 ) ); }
        if ( $source ) {
            $linked = $wpdb->get_row( $wpdb->prepare( "SELECT q.id,s.status FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_fin_subscriptions s ON s.id=q.subscription_id AND s.space_id=q.space_id WHERE q.expense_id=%d AND q.space_id=%d FOR UPDATE", $source['id'], $uid ), ARRAY_A );
            if ( $linked && 'deleted' !== $linked['status'] ) { return self::rollback( self::error( 'Essa compra já está ligada a uma assinatura.', 409 ) ); }
            $anchor = array( 'space_id' => $uid, 'subscription_id' => $id, 'invoice_id' => $source['invoice_id'], 'invoice_month' => $source['invoice_month'], 'charge_on' => $source['spent_on'], 'amount' => $source['amount'], 'description' => $source['description'], 'match_key' => self::key( $source['description'] ), 'category_id' => $source['category_id'], 'status' => 'confirmed', 'expense_id' => $source['id'], 'created_at' => current_time( 'mysql', true ) );
            $ok = $linked ? PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', $anchor, array( 'id' => $linked['id'], 'space_id' => $uid ) ) : PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_fin_subscription_charges', $anchor );
            if ( false === $ok ) { return self::rollback( self::error( 'Essa compra já está ligada a uma assinatura.', 409 ) ); }
        }
        $saved = self::refresh_open( $id, $uid, $data, $card ); if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); }
        $saved = self::materialize( $id, $uid, '', true, ! $old || ( 'active' === $status && 'active' !== $old['status'] ) ); if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar a assinatura.', 500 ) ); } return array( 'id' => $id, 'success' => true );
    }
    /** Changes affect only open, unconfirmed and unpaid projections. Historical invoices stay intact. */
    private static function refresh_open( $id, $uid, $s, $card ) {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.*,i.closing_on FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=q.invoice_id AND i.space_id=q.space_id WHERE q.subscription_id=%d AND q.space_id=%d AND q.status='planned' AND q.expense_id IS NULL AND i.closing_on>%s ORDER BY i.id FOR UPDATE", $id, $uid, current_time( 'Y-m-d' ) ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::error( 'Não foi possível conferir as próximas cobranças.', 500 ); }
        foreach ( $rows as $q ) {
            if ( self::paid( $q['invoice_id'] ) ) { continue; }
            $data = array( 'status' => 'active' === $s['status'] ? 'planned' : 'canceled' );
            if ( 'active' === $s['status'] ) { $date = self::charge_date( $q['invoice_month'], $s['billing_day'], $card ); if ( is_wp_error( $date ) ) { return $date; } $invoice = PDS_Finance::ensure_invoice( $card, $q['invoice_month'] ); if ( is_wp_error( $invoice ) ) { return $invoice; } $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d FOR UPDATE", $invoice ) ); if ( self::paid( $invoice ) && $invoice !== (int) $q['invoice_id'] ) { return self::error( 'A fatura de destino já tem pagamento registrado.', 409 ); } $data += array( 'amount' => $s['amount'], 'description' => $s['name'], 'category_id' => $s['category_id'], 'match_key' => $s['match_key'], 'charge_on' => $date, 'invoice_id' => $invoice ); }
            if ( false === PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', $data, array( 'id' => $q['id'], 'space_id' => $uid ) ) ) { return self::error( 'Não foi possível atualizar as previsões da assinatura.', 500 ); }
        } return true;
    }
    private static function paid( $invoice ) { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_bill_payments WHERE invoice_id=%d LIMIT 1", $invoice ) ); }
    public static function remove( WP_REST_Request $r ) {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $id = self::id( $r ); $wpdb->query( 'START TRANSACTION' ); $s = self::find( $id, $uid, true ); if ( is_wp_error( $s ) ) { return self::rollback( $s ); }
        $s['status'] = 'deleted'; $card = PDS_Finance::find( 'cards', $s['card_id'] ); $ok = self::refresh_open( $id, $uid, $s, $card ); if ( is_wp_error( $ok ) ) { return self::rollback( $ok ); }
        if ( false === PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscriptions', array( 'status' => 'deleted', 'name_key' => hash( 'sha256', 'deleted:' . $id ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'space_id' => $uid ) ) ) { return self::rollback( self::error( 'Não foi possível excluir a assinatura.', 500 ) ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar a exclusão.', 500 ) ); } return array( 'success' => true );
    }
    private static function actual( $s, $invoice, $uid ) {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.amount,e.description,e.spent_on,e.category_id,q.id AS reclaim_charge FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_fin_installments p ON p.expense_id=e.id AND p.space_id=e.space_id LEFT JOIN {$wpdb->prefix}pds_fin_subscription_charges q ON q.expense_id=e.id LEFT JOIN {$wpdb->prefix}pds_fin_subscriptions old_service ON old_service.id=q.subscription_id AND old_service.space_id=q.space_id WHERE p.invoice_id=%d AND e.space_id=%d AND p.installment_total=1 AND (q.id IS NULL OR old_service.status='deleted') LIMIT 2000", $invoice, $uid ), ARRAY_A );
        $matches = array_values( array_filter( $rows, static fn( $e ) => self::key( $e['description'] ) === $s['match_key'] ) ); return count( $matches ) === 1 ? $matches[0] : null;
    }
    public static function materialize( $id, $uid, $today = '', $inside = false, $first = false ) {
        global $wpdb; $today = $today ?: current_time( 'Y-m-d' ); if ( ! PDS_Expenses::valid_date( $today ) ) { return self::error( 'Data da geração inválida.' ); }
        if ( ! $inside ) { $wpdb->query( 'START TRANSACTION' ); } $s = self::find( $id, $uid, true );
        $fail = static function ( $e ) use ( $inside ) { return $inside ? $e : self::rollback( $e ); };
        if ( is_wp_error( $s ) ) { return $fail( $s ); } if ( 'active' !== $s['status'] ) { if ( ! $inside ) { $wpdb->query( 'COMMIT' ); } return array( 'created' => 0 ); }
        $card = PDS_Finance::find( 'cards', $s['card_id'], $uid ); if ( is_wp_error( $card ) || ! $card['active'] ) { return $fail( self::error( 'Cartão da assinatura arquivado. Edite a assinatura.', 409 ) ); }
        $cat = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}pds_expense_categories WHERE id=%d AND space_id=%d", $s['category_id'], $uid ) ); if ( ! $cat ) { return $fail( self::error( 'Categoria da assinatura arquivada. Edite a assinatura.', 409 ) ); }
        $cursor = $s['next_month']; $through = self::horizon( $card, $today ); if ( $first ) { $through = max( $through, $cursor ); } $created = 0;
        for ( $i = 0; $cursor <= $through && $i < 120; $i++ ) {
            $invoice = PDS_Finance::ensure_invoice( $card, $cursor ); if ( is_wp_error( $invoice ) ) { return $fail( $invoice ); } $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d AND space_id=%d FOR UPDATE", $invoice, $uid ) );
            $q = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE subscription_id=%d AND space_id=%d AND invoice_month=%s FOR UPDATE", $id, $uid, $cursor ), ARRAY_A );
            if ( ! $q || ( 'canceled' === $q['status'] && ! self::paid( $q['invoice_id'] ) ) ) {
                $date = self::charge_date( $cursor, $s['billing_day'], $card ); if ( is_wp_error( $date ) ) { return $fail( $date ); }
                $actual = self::actual( $s, $invoice, $uid ); if ( ! $actual && self::paid( $invoice ) ) { return $fail( self::error( 'Há pagamento na fatura da próxima cobrança. Confira e escolha uma primeira fatura posterior.', 409 ) ); }
                $data = array( 'invoice_id' => $invoice, 'invoice_month' => $cursor, 'charge_on' => $actual ? $actual['spent_on'] : $date, 'amount' => $actual ? $actual['amount'] : $s['amount'], 'description' => $actual ? $actual['description'] : $s['name'], 'match_key' => $s['match_key'], 'category_id' => $actual ? $actual['category_id'] : $s['category_id'], 'status' => $actual ? 'confirmed' : 'planned', 'expense_id' => $actual ? $actual['id'] : null );
                $target_id = $q['id'] ?? ( $actual['reclaim_charge'] ?? null ); $data['subscription_id'] = $id; $ok = $target_id ? PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', $data, array( 'id' => $target_id, 'space_id' => $uid ) ) : PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_fin_subscription_charges', $data + array( 'space_id' => $uid, 'created_at' => current_time( 'mysql', true ) ), null, (int) $s['created_by'] ); if ( false === $ok ) { return $fail( self::error( 'Não foi possível gerar a próxima cobrança.', 500 ) ); } $created++;
            } $cursor = PDS_Finance::add_month( $cursor, 1 );
        }
        if ( false === PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscriptions', array( 'next_month' => $cursor ), array( 'id' => $id, 'space_id' => $uid ) ) ) { return $fail( self::error( 'Não foi possível atualizar a próxima fatura.', 500 ) ); }
        if ( ! $inside && false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar as cobranças.', 500 ) ); } return array( 'created' => $created );
    }
    public static function sync_user( $uid ) {
        global $wpdb; if ( ! PDS_Finance_Spaces::service( $uid ) ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name FROM {$wpdb->prefix}pds_fin_subscriptions WHERE space_id=%d AND status='active' ORDER BY id LIMIT 100", $uid ), ARRAY_A ); $warnings = array();
        foreach ( $rows as $s ) { $result = self::materialize( $s['id'], $uid ); if ( is_wp_error( $result ) ) { $warnings[] = $s['name'] . ': ' . $result->get_error_message(); } } return $warnings;
    }
    public static function cron() {
        global $wpdb; $after = (int) get_option( 'pds_subscriptions_cursor', 0 ); $users = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT space_id FROM {$wpdb->prefix}pds_fin_subscriptions WHERE status='active' AND space_id>%d ORDER BY space_id LIMIT 50", $after ) );
        foreach ( $users as $uid ) { PDS_Finance_Spaces::within( (int) $uid, static fn() => self::sync_user( (int) $uid ) ); } update_option( 'pds_subscriptions_cursor', $users ? (int) end( $users ) : 0, false );
    }
    public static function listing() {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $warnings = self::sync_user( $uid ); $rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*,c.name AS card_name,c.active AS card_active,k.name AS category_name FROM {$wpdb->prefix}pds_fin_subscriptions s INNER JOIN {$wpdb->prefix}pds_fin_cards c ON c.id=s.card_id AND c.space_id=s.space_id INNER JOIN {$wpdb->prefix}pds_expense_categories k ON k.id=s.category_id AND k.space_id=s.space_id WHERE s.space_id=%d AND s.status<>'deleted' ORDER BY FIELD(s.status,'active','paused','canceled'),s.name,s.id LIMIT 100", $uid ), ARRAY_A ); $monthly = 0;
        foreach ( $rows as &$s ) {
            if ( 'active' === $s['status'] ) { $monthly += PDS_Finance::cents( $s['amount'] ); }
            foreach ( array( 'id', 'card_id', 'category_id', 'billing_day' ) as $key ) { $s[ $key ] = (int) $s[ $key ]; } $s['amount'] = (float) $s['amount']; $s['card_active'] = (bool) $s['card_active'];
            $next = $wpdb->get_row( $wpdb->prepare( "SELECT id,invoice_id,invoice_month,charge_on,amount FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE subscription_id=%d AND space_id=%d AND status='planned' AND expense_id IS NULL ORDER BY invoice_month DESC LIMIT 1", $s['id'], $uid ), ARRAY_A );
            if ( $next ) { $next['id'] = (int) $next['id']; $next['invoice_id'] = (int) $next['invoice_id']; $next['amount'] = (float) $next['amount']; } $s['next_charge'] = $next; $s = PDS_Finance_Spaces::attribution( $s ); unset( $s['space_id'], $s['user_id'], $s['name_key'], $s['match_key'] );
        } unset( $s ); return array( 'subscriptions' => $rows, 'monthly_total' => (float) ( $monthly / 100 ), 'warnings' => $warnings );
    }
    public static function forecast_total( $invoice ) { global $wpdb; return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE invoice_id=%d AND status='planned' AND expense_id IS NULL", $invoice ) ); }
    public static function adopt_expense( $entry, $uid ) {
        if ( empty( $entry['card_id'] ) || (int) $entry['installments'] !== 1 ) { return; }
        global $wpdb; $e = self::source( $entry['id'], $uid ); if ( is_wp_error( $e ) ) { return; }
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE expense_id=%d", $entry['id'] ) ) ) { return; }
        $choices = self::import_candidates( array( 'kind' => 'charge', 'total_installments' => 1, 'description' => $e['description'] ), (int) $e['card_id'], $e['invoice_month'] );
        if ( count( $choices ) !== 1 || $choices[0]['confirmed'] ) { return; } $id = (int) substr( $choices[0]['id'], strlen( 'subscription:' ) );
        $wpdb->query( 'START TRANSACTION' ); $q = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE id=%d AND space_id=%d FOR UPDATE", $id, $uid ), ARRAY_A );
        if ( ! $q || 'planned' !== $q['status'] || $q['expense_id'] ) { $wpdb->query( 'ROLLBACK' ); return; }
        $ok = PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', array( 'status' => 'confirmed', 'expense_id' => $entry['id'], 'amount' => $e['amount'], 'charge_on' => $e['spent_on'] ), array( 'id' => $id, 'space_id' => $uid ) ); $wpdb->query( false === $ok ? 'ROLLBACK' : 'COMMIT' );
    }
    public static function cancel_charge( WP_REST_Request $r ) {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $wpdb->query( 'START TRANSACTION' ); $q = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE id=%d AND space_id=%d FOR UPDATE", self::id( $r ), $uid ), ARRAY_A );
        if ( ! $q ) { return self::rollback( self::error( 'Cobrança não encontrada ou sem acesso.', 404 ) ); } $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d FOR UPDATE", $q['invoice_id'] ) );
        if ( 'confirmed' === $q['status'] || $q['expense_id'] || self::paid( $q['invoice_id'] ) ) { return self::rollback( self::error( 'A cobrança está conferida ou a fatura tem pagamento. Corrija o gasto ou o pagamento antes de remover.', 409 ) ); }
        if ( false === PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', array( 'status' => 'skipped' ), array( 'id' => $q['id'], 'space_id' => $uid ) ) ) { return self::rollback( self::error( 'Não foi possível remover a previsão.', 500 ) ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar a remoção.', 500 ) ); } return array( 'success' => true );
    }
    public static function card_forecasts( $card, $uid ) { global $wpdb; return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(q.amount),0) FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=q.invoice_id AND i.space_id=q.space_id WHERE i.card_id=%d AND q.space_id=%d AND q.status='planned' AND q.expense_id IS NULL", $card, $uid ) ); }
    public static function invoice_items( $invoice, $uid ) {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.*,c.name AS category_name FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_expense_categories c ON c.id=q.category_id AND c.space_id=q.space_id WHERE q.invoice_id=%d AND q.space_id=%d AND q.status='planned' AND q.expense_id IS NULL ORDER BY q.charge_on,q.id", $invoice, $uid ), ARRAY_A ); $out = array();
        foreach ( $rows as $q ) { $out[] = array( 'id' => 'subscription:' . $q['id'], 'expense_id' => 0, 'subscription_id' => (int) $q['subscription_id'], 'subscription_charge_id' => (int) $q['id'], 'description' => $q['description'], 'spent_on' => $q['charge_on'], 'amount' => (float) $q['amount'], 'category_name' => $q['category_name'], 'category_id' => (int) $q['category_id'], 'installment_no' => 1, 'installment_total' => 1, 'source' => 'subscription_forecast', 'confirmed' => false, 'is_subscription_forecast' => true, 'person_id' => null, 'person_name' => '', 'sharing_mode' => '' ); } return $out;
    }
    public static function may_replace( $charge_id, $invoice, $uid, $amount ) {
        global $wpdb; $q = $wpdb->get_row( $wpdb->prepare( "SELECT amount FROM {$wpdb->prefix}pds_fin_subscription_charges WHERE id=%d AND invoice_id=%d AND space_id=%d AND status='confirming' AND expense_id IS NULL", $charge_id, $invoice, $uid ), ARRAY_A ); return $q && PDS_Finance::cents( $q['amount'] ) === PDS_Finance::cents( $amount );
    }
    public static function confirm_request( WP_REST_Request $r ) { return self::confirm( self::id( $r ), $r->get_params() ); }
    public static function confirm( $charge_id, $input, $bank_ref = null, $expected_card = 0, $expected_month = '' ) {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $wpdb->query( 'START TRANSACTION' );
        $q = $wpdb->get_row( $wpdb->prepare( "SELECT q.*,i.card_id FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=q.invoice_id AND i.space_id=q.space_id WHERE q.id=%d AND q.space_id=%d FOR UPDATE", $charge_id, $uid ), ARRAY_A );
        if ( ! $q || ( $expected_card && (int) $q['card_id'] !== $expected_card ) || ( $expected_month && $q['invoice_month'] !== $expected_month ) ) { return self::rollback( self::error( 'Cobrança não encontrada nesta fatura ou sem acesso.', 404 ) ); }
        if ( 'confirmed' === $q['status'] ) { if ( $q['expense_id'] && ! $bank_ref ) { $wpdb->query( 'COMMIT' ); return array( 'id' => (int) $q['expense_id'], 'already_confirmed' => true ); } return self::rollback( self::error( 'Essa cobrança já foi conferida. Confira se a linha é repetida.', 409 ) ); }
        if ( 'planned' !== $q['status'] ) { return self::rollback( self::error( 'Essa previsão está cancelada.', 409 ) ); }
        $amount = PDS_Expenses::amount( $input['amount'] ?? $q['amount'] ); if ( is_wp_error( $amount ) ) { return self::rollback( $amount ); }
        if ( self::paid( $q['invoice_id'] ) && PDS_Finance::cents( $amount ) !== PDS_Finance::cents( $q['amount'] ) ) { return self::rollback( self::error( 'A fatura tem pagamento registrado. Corrija/remova o pagamento antes de alterar o valor da cobrança.', 409 ) ); }
        $date = $input['spent_on'] ?? $q['charge_on']; if ( ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return self::rollback( self::error( 'Informe a data real da cobrança, até hoje.' ) ); }
        if ( false === PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', array( 'status' => 'confirming', 'amount' => $amount ), array( 'id' => $q['id'], 'space_id' => $uid ) ) ) { return self::rollback( self::error( 'Não foi possível conferir a cobrança.', 500 ) ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( array( 'description' => $input['description'] ?? $q['description'], 'spent_on' => $date, 'amount' => $amount, 'category_id' => $input['category_id'] ?? $q['category_id'], 'payment_method' => 'credit', 'card_id' => $q['card_id'], 'installments' => 1, 'first_invoice' => $q['invoice_month'], 'notes' => 'Cobrança mensal de assinatura conferida na fatura ' . $q['invoice_month'] . '.' ) ); if ( array_key_exists( 'sharing', $input ) ) { $request->set_param( 'sharing', $input['sharing'] ); }
        $ref = $bank_ref ? 'statement:' . $bank_ref : 'subscription:' . hash( 'sha256', $uid . ':' . $q['id'] );
        $options = array( 'in_transaction' => true, 'subscription_charge_id' => (int) $q['id'] ); if ( $bank_ref ) { $options['bank_ref'] = $bank_ref; }
        $saved = PDS_Expenses::save_entry( $request, $ref, $options ); if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); }
        if ( ! $bank_ref ) { $ok = PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_installments', array( 'confirmed' => 1 ), array( 'expense_id' => $saved['id'], 'space_id' => $uid ) ); if ( false === $ok ) { return self::rollback( self::error( 'Não foi possível confirmar a parcela da assinatura.', 500 ) ); } }
        $ok = PDS_Finance_Spaces::update( $wpdb->prefix . 'pds_fin_subscription_charges', array( 'status' => 'confirmed', 'expense_id' => $saved['id'], 'amount' => $amount, 'charge_on' => $date ), array( 'id' => $q['id'], 'space_id' => $uid ) ); if ( false === $ok ) { return self::rollback( self::error( 'Não foi possível vincular a cobrança conferida.', 500 ) ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar a cobrança.', 500 ) ); } do_action( 'pds_expense_created', $saved, get_current_user_id(), $uid ); return array( 'id' => (int) $saved['id'], 'success' => true );
    }
    public static function import_candidates( $row, $card, $month ) {
        if ( 'charge' !== $row['kind'] || 1 !== (int) $row['total_installments'] ) { return array(); }
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $key = self::key( $row['description'] );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.id,q.amount,q.description,q.status,q.match_key,s.match_key AS service_key FROM {$wpdb->prefix}pds_fin_subscription_charges q INNER JOIN {$wpdb->prefix}pds_fin_subscriptions s ON s.id=q.subscription_id AND s.space_id=q.space_id INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=q.invoice_id AND i.space_id=q.space_id WHERE i.card_id=%d AND q.space_id=%d AND q.invoice_month=%s AND q.status IN ('planned','confirmed')", $card, $uid, $month ), ARRAY_A ); $out = array();
        foreach ( $rows as $q ) { if ( $key === $q['match_key'] || $key === $q['service_key'] ) { $out[] = array( 'id' => 'subscription:' . $q['id'], 'expense_id' => 0, 'amount' => (float) $q['amount'], 'confirmed' => 'confirmed' === $q['status'], 'description' => 'Assinatura · ' . $q['description'], 'subscription' => true ); } } return $out;
    }
    public static function hint( $description ) {
        $key = self::key( $description ); foreach ( array( 'netflix', 'spotify', 'primevideo', 'amazonprime', 'disneyplus', 'disney', 'youtube', 'icloud', 'googleone', 'applecombill', 'microsoft', 'adobe', 'canva', 'chatgpt', 'openai', 'paramount', 'deezer', 'globoplay', 'assinatura', 'subscription' ) as $name ) { if ( strpos( $key, $name ) !== false ) { return 'Nome compatível com serviço de assinatura. Confira a recorrência em Assinaturas.'; } } return '';
    }
    public static function suggestions() {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $since = PDS_Finance::add_month( current_time( 'Y-m' ), -12 );
        $known = $wpdb->get_results( $wpdb->prepare( "SELECT card_id,match_key FROM {$wpdb->prefix}pds_fin_subscriptions WHERE space_id=%d", $uid ), ARRAY_A ); $ignore = array(); foreach ( $known as $s ) { $ignore[ $s['card_id'] . ':' . $s['match_key'] ] = true; }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.description,e.amount,e.spent_on,e.card_id,i.invoice_month,c.name AS card_name FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_fin_installments p ON p.expense_id=e.id AND p.space_id=e.space_id INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=p.invoice_id AND i.space_id=p.space_id INNER JOIN {$wpdb->prefix}pds_fin_cards c ON c.id=e.card_id AND c.space_id=e.space_id WHERE e.space_id=%d AND e.installments=1 AND p.installment_total=1 AND i.invoice_month>=%s ORDER BY e.spent_on DESC,e.id DESC LIMIT 3000", $uid, $since ), ARRAY_A ); $groups = array();
        foreach ( $rows as $e ) { $key = $e['card_id'] . ':' . self::key( $e['description'] ); if ( isset( $ignore[ $key ] ) ) { continue; } if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'entry' => $e, 'months' => array() ); } $groups[ $key ]['months'][ $e['invoice_month'] ] = true; }
        $out = array(); foreach ( $groups as $g ) { $e = $g['entry']; $hint = self::hint( $e['description'] ); if ( ! $hint && count( $g['months'] ) < 2 ) { continue; } $out[] = array( 'expense_id' => (int) $e['id'], 'name' => $e['description'], 'card_name' => $e['card_name'], 'amount' => (float) $e['amount'], 'months_seen' => count( $g['months'] ), 'reason' => count( $g['months'] ) >= 2 ? 'Cobrança à vista identificada em ' . count( $g['months'] ) . ' faturas diferentes. Confira se é mensal.' : $hint ); if ( count( $out ) >= 50 ) { break; } } return array( 'suggestions' => $out );
    }
}
