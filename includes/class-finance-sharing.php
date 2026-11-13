<?php
defined( 'ABSPATH' ) || exit;

/** Optional card reimbursements: the invoice stays whole and forecasts share its installments. */
final class PDS_Finance_Sharing {
    private static $names = array();
    public static function enabled() { return ! empty( get_option( 'pds_settings', array() )['shared_card'] ); }
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            foreach ( array( '/finance/people' => array( 'GET' => 'people', 'POST' => 'save_person' ), '/finance/people/(?P<id>\d+)' => array( 'PUT' => 'save_person', 'DELETE' => 'archive_person' ) ) as $path => $methods ) {
                $endpoints = array(); foreach ( $methods as $method => $callback ) { $endpoints[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( __CLASS__, 'permission' ) ); } register_rest_route( 'pds/v1', $path, $endpoints );
            }
        } );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-sharing', PDS_URL . 'assets/sharing.js', array( 'pds-finance' ), PDS_VERSION, true ); } );
    }
    public static function permission() { $ok = PDS_Expenses::permission(); return is_wp_error( $ok ) ? $ok : ( self::enabled() ? true : PDS_Finance::error( 'O compartilhamento de cartão está desativado nas configurações do portal.', 403 ) ); }
    private static function id( $v, $zero = false ) { return ( is_int( $v ) || is_string( $v ) ) && preg_match( $zero ? '/^(0|[1-9][0-9]{0,17})$/D' : '/^[1-9][0-9]{0,17}$/D', (string) $v ) ? (int) $v : -1; }
    private static function person( $id, $uid ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_people WHERE id=%d AND user_id=%d", $id, $uid ), ARRAY_A ); }
    public static function list_people() {
        global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,active FROM {$wpdb->prefix}pds_fin_people WHERE user_id=%d ORDER BY active DESC,name,id", get_current_user_id() ), ARRAY_A );
        foreach ( $rows as &$r ) { $r['id'] = (int) $r['id']; $r['active'] = (bool) $r['active']; } unset( $r ); return $rows;
    }
    public static function people() { return array( 'people' => self::list_people() ); }
    public static function save_person( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $id = absint( $r->get_url_params()['id'] ?? 0 ); $old = $id ? self::person( $id, $uid ) : array();
        if ( $id && ! $old ) { return PDS_Finance::error( 'Pessoa não encontrada ou sem acesso.', 404 ); }
        $name = $r->get_param( 'name' ) ?? ( $old['name'] ?? '' ); $active = $r->get_param( 'active' );
        if ( ! is_string( $name ) || ( null !== $active && ! is_bool( $active ) ) ) { return PDS_Finance::error( 'Informe um nome e uma situação válidos.' ); }
        $name = sanitize_text_field( trim( $name ) ); if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) { return PDS_Finance::error( 'Use um nome de 2 a 80 caracteres.' ); }
        $slug = substr( sanitize_title( $name ), 0, 170 ); if ( ! $slug ) { $slug = hash( 'sha256', $name ); }
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_people WHERE user_id=%d AND slug=%s AND id<>%d", $uid, $slug, $id ) ) ) { return PDS_Finance::error( 'Essa pessoa já está cadastrada. Se estiver arquivada, reative-a.', 409 ); }
        if ( ! $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_people WHERE user_id=%d", $uid ) ) >= 200 ) { return PDS_Finance::error( 'Limite de 200 pessoas por usuário.' ); }
        $data = array( 'name' => $name, 'slug' => $slug, 'active' => null === $active ? (int) ( $old['active'] ?? 1 ) : (int) $active );
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_fin_people', $data, array( 'id' => $id, 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_people', $data + array( 'user_id' => $uid, 'created_at' => current_time( 'mysql', true ) ) );
        unset( self::$names[ $uid ] ); return false === $ok ? PDS_Finance::error( 'Não foi possível salvar a pessoa.', 409 ) : array( 'id' => $id ?: (int) $wpdb->insert_id );
    }
    public static function archive_person( WP_REST_Request $r ) { global $wpdb; $id = absint( $r->get_url_params()['id'] ?? 0 ); if ( ! self::person( $id, get_current_user_id() ) ) { return PDS_Finance::error( 'Pessoa não encontrada ou sem acesso.', 404 ); } return false === $wpdb->update( $wpdb->prefix . 'pds_fin_people', array( 'active' => 0 ), array( 'id' => $id, 'user_id' => get_current_user_id() ) ) ? PDS_Finance::error( 'Não foi possível arquivar a pessoa.', 500 ) : array( 'success' => true ); }
    public static function name( $id, $uid ) { global $wpdb; if ( ! $id ) { return ''; } if ( ! isset( self::$names[ $uid ] ) ) { self::$names[ $uid ] = $wpdb->get_results( $wpdb->prepare( "SELECT id,name FROM {$wpdb->prefix}pds_fin_people WHERE user_id=%d", $uid ), OBJECT_K ); } return self::$names[ $uid ][ $id ]->name ?? ''; }
    public static function prepare( $r, $old, $payment, $card_id, $uid ) {
        $previous = (int) ( $old['person_id'] ?? 0 ); $value = $r->get_param( 'person_id' ); $id = null === $value ? $previous : self::id( $value, true );
        if ( $id < 0 ) { return PDS_Finance::error( 'Escolha uma pessoa cadastrada.' ); }
        if ( ! $id ) { return null; }
        if ( ! self::enabled() && $id !== $previous ) { return PDS_Finance::error( 'Ative o compartilhamento de cartão nas configurações do WordPress.', 403 ); }
        if ( 'credit' !== $payment || ! $card_id ) { return PDS_Finance::error( 'O gasto de outra pessoa deve usar um cartão de crédito cadastrado.' ); }
        $person = self::person( $id, $uid ); if ( ! $person ) { return PDS_Finance::error( 'Pessoa não encontrada ou sem acesso.', 404 ); }
        return ! $person['active'] && $id !== $previous ? PDS_Finance::error( 'Escolha uma pessoa ativa.', 409 ) : $id;
    }
    /** The caller owns the expense transaction and its row lock. */
    public static function sync( $expense_id, $uid ) {
        global $wpdb;
        $expense = $wpdb->get_row( $wpdb->prepare( "SELECT id,person_id,description,card_id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $expense_id, $uid ), ARRAY_A );
        if ( ! $expense || $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir o gasto compartilhado.', 500 ); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_receipts WHERE shared_expense_id=%d AND user_id=%d ORDER BY shared_installment_no FOR UPDATE", $expense_id, $uid ), ARRAY_A );
        if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir os recebimentos do cartão.', 500 ); }
        $parts = array();
        if ( $expense['person_id'] ) {
            $person = self::person( $expense['person_id'], $uid ); if ( ! $person ) { return PDS_Finance::error( 'A pessoa desse gasto não foi encontrada.', 409 ); }
            if ( ! $rows && ( ! self::enabled() || ! $person['active'] ) ) { return PDS_Finance::error( 'Ative o compartilhamento e escolha uma pessoa ativa.', 409 ); }
            $schedule = $wpdb->get_results( $wpdb->prepare( "SELECT s.installment_no,s.installment_total,s.amount,i.due_on FROM {$wpdb->prefix}pds_fin_installments s INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=s.invoice_id AND i.user_id=s.user_id WHERE s.expense_id=%d AND s.user_id=%d ORDER BY s.installment_no", $expense_id, $uid ), ARRAY_A );
            if ( ! $schedule || $wpdb->last_error ) { return PDS_Finance::error( 'O gasto compartilhado precisa de parcelas na fatura.', 409 ); }
            foreach ( $schedule as $part ) { $parts[ (int) $part['installment_no'] ] = $part; }
        }
        foreach ( $rows as $row ) {
            $part = $parts[ (int) $row['shared_installment_no'] ] ?? null;
            if ( 'received' === $row['status'] && ( ! $part || (int) $row['person_id'] !== (int) $expense['person_id'] || (string) $row['shared_planned_amount'] !== (string) $part['amount'] || $row['shared_planned_on'] !== $part['due_on'] || (int) $row['shared_installment_total'] !== (int) $part['installment_total'] ) ) { return PDS_Finance::error( 'Já há reembolso recebido para esta compra. Reverta o recebimento antes de alterar pessoa, parcelas, valores ou vencimentos.', 409 ); }
        }
        $existing = array_column( $rows, null, 'shared_installment_no' ); $now = current_time( 'mysql', true );
        foreach ( $rows as $row ) { if ( ! isset( $parts[ (int) $row['shared_installment_no'] ] ) && false === $wpdb->delete( $wpdb->prefix . 'pds_fin_receipts', array( 'id' => $row['id'], 'user_id' => $uid ) ) ) { return PDS_Finance::error( 'Não foi possível atualizar os reembolsos.', 500 ); } }
        foreach ( $parts as $no => $part ) {
            $old = $existing[ $no ] ?? null; if ( $old && 'received' === $old['status'] ) { continue; }
            $data = array( 'person_id' => (int) $expense['person_id'], 'shared_installment_total' => (int) $part['installment_total'], 'shared_planned_amount' => $part['amount'], 'shared_planned_on' => $part['due_on'], 'amount' => $part['amount'], 'received_on' => $part['due_on'], 'description' => mb_substr( 'Reembolso · ' . $expense['description'], 0, 150 ) . ' · ' . $no . '/' . $part['installment_total'], 'updated_at' => $now );
            $ok = $old ? $wpdb->update( $wpdb->prefix . 'pds_fin_receipts', $data, array( 'id' => $old['id'], 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_receipts', $data + array( 'user_id' => $uid, 'account_id' => 0, 'status' => 'pending', 'notes' => '', 'source' => 'shared_card', 'shared_expense_id' => $expense_id, 'shared_installment_no' => $no, 'created_at' => $now ) );
            if ( false === $ok ) { return PDS_Finance::error( 'Não foi possível gerar todos os recebimentos. Tente novamente.', 500 ); }
        }
        return true;
    }
    public static function detach( $expense_id, $uid ) {
        global $wpdb; $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $expense_id, $uid ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,status FROM {$wpdb->prefix}pds_fin_receipts WHERE shared_expense_id=%d AND user_id=%d FOR UPDATE", $expense_id, $uid ), ARRAY_A );
        if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir os reembolsos.', 500 ); }
        foreach ( $rows as $row ) { if ( 'received' === $row['status'] ) { return PDS_Finance::error( 'Reverta os reembolsos recebidos antes de excluir esta compra.', 409 ); } }
        return false === $wpdb->delete( $wpdb->prefix . 'pds_fin_receipts', array( 'shared_expense_id' => $expense_id, 'user_id' => $uid ) ) ? PDS_Finance::error( 'Não foi possível remover as previsões da compra.', 500 ) : true;
    }
    private static function fail( $message, $status = 500 ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( $message, $status ); }
    public static function save_receipt( $r, $old ) {
        global $wpdb; $uid = get_current_user_id();
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar o recebimento.', 500 ); }
        $expense = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $old['shared_expense_id'], $uid ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_receipts WHERE id=%d AND user_id=%d FOR UPDATE", $old['id'], $uid ), ARRAY_A );
        if ( ! $expense || ! $row || $wpdb->last_error || (int) $row['shared_expense_id'] !== (int) $expense ) { return self::fail( 'O reembolso mudou. Atualize a página e confira.', 409 ); }
        $status = $r->get_param( 'status' ) ?? $row['status']; if ( ! in_array( $status, array( 'pending', 'received', 'canceled' ), true ) ) { return self::fail( 'Confira a situação do recebimento.', 400 ); }
        $amount = 'received' === $status ? PDS_Expenses::amount( $r->get_param( 'amount' ) ) : $row['shared_planned_amount']; $date = 'received' === $status ? $r->get_param( 'received_on' ) : $row['shared_planned_on'];
        if ( is_wp_error( $amount ) ) { $wpdb->query( 'ROLLBACK' ); return $amount; }
        if ( ! PDS_Expenses::valid_date( $date ) || ( 'received' === $status && $date > current_time( 'Y-m-d' ) ) ) { return self::fail( 'Informe a data real, até hoje. Previsões futuras ficam como A receber.', 400 ); }
        $notes = $r->get_param( 'notes' ) ?? $row['notes']; if ( ! is_string( $notes ) || mb_strlen( $notes ) > 500 ) { return self::fail( 'Use até 500 caracteres nas observações.', 400 ); }
        $value = $r->get_param( 'account_id' ) ?? $row['account_id']; if ( '' === $value ) { $value = 0; } $account = self::id( $value, true );
        if ( $account < 0 || ( ! $account && 'received' === $status ) ) { return self::fail( 'Escolha a conta em que você recebeu o reembolso.', 400 ); }
        if ( $account ) { $bank = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $account, $uid ), ARRAY_A ); if ( ! $bank || $wpdb->last_error ) { return self::fail( 'Conta não encontrada ou sem acesso.', 404 ); } if ( ! $bank['active'] && (int) $row['account_id'] !== $account ) { return self::fail( 'Escolha uma conta ativa.', 409 ); } }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_receipts', array( 'account_id' => $account, 'amount' => $amount, 'received_on' => $date, 'status' => $status, 'notes' => sanitize_text_field( $notes ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'], 'user_id' => $uid ) );
        if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) { return self::fail( 'Não foi possível confirmar o reembolso.' ); }
        return array( 'id' => (int) $row['id'] );
    }
    public static function cancel_receipt( $old ) { $r = new WP_REST_Request( 'PUT' ); $r->set_body_params( array( 'status' => 'canceled' ) ); return self::save_receipt( $r, $old ); }
    public static function update_invoice( $invoice, $due, $close ) {
        global $wpdb; $uid = get_current_user_id();
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar a atualização da fatura.', 500 ); }
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.id FROM {$wpdb->prefix}pds_expenses e INNER JOIN {$wpdb->prefix}pds_fin_installments s ON s.expense_id=e.id AND s.user_id=e.user_id WHERE s.invoice_id=%d AND e.user_id=%d ORDER BY e.id FOR UPDATE", $invoice['id'], $uid ) );
        if ( $wpdb->last_error ) { return self::fail( 'Os gastos estão sendo atualizados. Tente novamente.', 409 ); }
        $locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d AND user_id=%d FOR UPDATE", $invoice['id'], $uid ) ); if ( ! $locked || $wpdb->last_error ) { return self::fail( 'A fatura mudou. Atualize e tente novamente.', 409 ); }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_invoices', array( 'due_on' => $due, 'closing_on' => $close ), array( 'id' => $invoice['id'], 'user_id' => $uid ) ) ) { return self::fail( 'Não foi possível atualizar a fatura.' ); }
        foreach ( array_unique( $ids ) as $id ) { $synced = self::sync( $id, $uid ); if ( is_wp_error( $synced ) ) { $wpdb->query( 'ROLLBACK' ); return $synced; } }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::fail( 'Não foi possível confirmar as novas datas.' ); }
        return array( 'success' => true );
    }
}
