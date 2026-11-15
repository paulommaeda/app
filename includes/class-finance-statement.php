<?php
defined( 'ABSPATH' ) || exit;

/** An account statement is a view of the original movements, never a second financial ledger. */
final class PDS_Finance_Statement {
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'pds/v1', '/finance/accounts/(?P<account_id>\d+)/statement', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'listing' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ) );
            $methods = array(); foreach ( array( 'GET' => 'detail', 'PUT' => 'save', 'DELETE' => 'remove' ) as $method => $callback ) { $methods[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ); }
            register_rest_route( 'pds/v1', '/finance/accounts/(?P<account_id>\d+)/statement/(?P<kind>opening|receipt|expense|payment|transfer)/(?P<id>\d+)', $methods );
        } );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-statement', PDS_URL . 'assets/statement.js', array( 'pds-finance' ), PDS_VERSION, true ); } );
    }
    private static function error( $m, $status = 400 ) { return PDS_Finance::error( $m, $status ); }
    private static function positive( $v ) { return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $v ) ? (int) $v : 0; }
    private static function account( $r ) { return PDS_Finance::find( 'accounts', absint( $r->get_url_params()['account_id'] ?? 0 ) ); }
    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }
    private static function cents( $decimal ) {
        $s = (string) ( $decimal ?? '0' ); $negative = isset( $s[0] ) && '-' === $s[0]; $parts = explode( '.', ltrim( $s, '-' ) ); $value = (int) $parts[0] * 100 + (int) substr( str_pad( $parts[1] ?? '', 2, '0' ), 0, 2 ); return $negative ? -$value : $value;
    }
    private static function query( $a ) {
        global $wpdb; $p = $wpdb->prefix; $uid = get_current_user_id(); $id = (int) $a['id']; $start = $a['opening_on'];
        $queries = array(
            $wpdb->prepare( "SELECT 'opening' AS kind,a.id,a.opening_on AS occurred_on,'Saldo inicial' AS description,a.opening_balance AS delta,0 AS sort_order,a.created_at,'' AS source,'' AS notes FROM {$p}pds_fin_accounts a WHERE a.id=%d AND a.user_id=%d AND a.opening_balance<>0", $id, $uid ),
            $wpdb->prepare( "SELECT 'receipt',r.id,r.received_on,r.description,r.amount,10,r.created_at,r.source,r.notes FROM {$p}pds_fin_receipts r WHERE r.user_id=%d AND r.account_id=%d AND r.status='received' AND r.received_on>=%s", $uid, $id, $start ),
            $wpdb->prepare( "SELECT 'expense',e.id,e.spent_on,e.description,-e.amount,10,e.created_at,e.source,e.notes FROM {$p}pds_expenses e WHERE e.user_id=%d AND e.account_id=%d AND e.card_id=0 AND e.payment_method<>'credit' AND e.spent_on>=%s", $uid, $id, $start ),
            $wpdb->prepare( "SELECT 'payment',b.id,b.paid_on,CONCAT('Pagamento da fatura · ',c.name,' · ',i.invoice_month),-b.amount,10,b.created_at,'invoice','' FROM {$p}pds_fin_bill_payments b INNER JOIN {$p}pds_fin_invoices i ON i.id=b.invoice_id AND i.user_id=b.user_id INNER JOIN {$p}pds_fin_cards c ON c.id=i.card_id AND c.user_id=b.user_id WHERE b.user_id=%d AND b.account_id=%d AND b.paid_on>=%s", $uid, $id, $start ),
            $wpdb->prepare( "SELECT 'transfer',t.id,t.transferred_on,CASE WHEN t.to_account_id=%d THEN CONCAT('Transferência de ',f.name) ELSE CONCAT('Transferência para ',d.name) END,CASE WHEN t.to_account_id=%d THEN t.amount ELSE -t.amount END,10,t.created_at,'transfer',t.notes FROM {$p}pds_fin_transfers t INNER JOIN {$p}pds_fin_accounts f ON f.id=t.from_account_id AND f.user_id=t.user_id INNER JOIN {$p}pds_fin_accounts d ON d.id=t.to_account_id AND d.user_id=t.user_id WHERE t.user_id=%d AND (t.from_account_id=%d OR t.to_account_id=%d) AND t.status='completed' AND t.transferred_on>=%s", $id, $id, $uid, $id, $id, $start ),
        ); return implode( ' UNION ALL ', $queries );
    }
    public static function listing( WP_REST_Request $r ) {
        global $wpdb; $account = self::account( $r ); if ( is_wp_error( $account ) ) { return $account; }
        $from = $r->get_param( 'from' ) ?? current_time( 'Y-m-01' ); $to = $r->get_param( 'to' ) ?? current_time( 'Y-m-d' ); $page = $r->get_param( 'page' ) ?? 1; $size = $r->get_param( 'per_page' ) ?? 50;
        if ( ! PDS_Expenses::valid_date( $from ) || ! PDS_Expenses::valid_date( $to ) || $from > $to || strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) > 731 * DAY_IN_SECONDS || ! self::positive( $page ) || (int) $page > 1000000 || ! self::positive( $size ) || (int) $size > 100 ) { return self::error( 'Confira conta, período de até dois anos e paginação do extrato.' ); }
        if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) { return self::error( 'Não foi possível consultar o extrato.', 500 ); }
        $account = self::account( $r ); if ( is_wp_error( $account ) ) { return self::rollback( $account ); } $union = self::query( $account );
        $where = $wpdb->prepare( 'occurred_on BETWEEN %s AND %s', $from, $to ); $order = 'occurred_on,sort_order,created_at,kind,id';
        $totals = $wpdb->get_row( "SELECT COUNT(*) AS count,COALESCE(SUM(CASE WHEN kind<>'opening' AND delta>0 THEN delta ELSE 0 END),0) AS credits,COALESCE(SUM(CASE WHEN kind<>'opening' AND delta<0 THEN -delta ELSE 0 END),0) AS debits,COALESCE(SUM(CASE WHEN kind='opening' THEN delta ELSE 0 END),0) AS initial FROM ($union) m WHERE $where", ARRAY_A );
        if ( $wpdb->last_error || ! $totals ) { return self::rollback( self::error( 'Não foi possível totalizar os movimentos.', 500 ) ); }
        $before = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM ($union) m WHERE occurred_on<%s", $from ) ); if ( $wpdb->last_error ) { return self::rollback( self::error( 'Não foi possível calcular o saldo anterior.', 500 ) ); }
        $size = (int) $size; $pages = max( 1, (int) ceil( $totals['count'] / $size ) ); $page = min( (int) $page, $pages ); $offset = ( $page - 1 ) * $size; $carry = 0;
        if ( $offset ) { $carry = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM (SELECT delta FROM ($union) m WHERE $where ORDER BY $order LIMIT %d) previous_rows", $offset ) ); if ( $wpdb->last_error ) { return self::rollback( self::error( 'Não foi possível calcular o saldo da página.', 500 ) ); } }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM ($union) m WHERE $where ORDER BY $order LIMIT %d OFFSET %d", $size, $offset ), ARRAY_A ); if ( $wpdb->last_error ) { return self::rollback( self::error( 'Não foi possível listar os movimentos.', 500 ) ); }
        $balance = self::cents( $before ) + self::cents( $carry ); foreach ( $rows as &$row ) { $balance += self::cents( $row['delta'] ); $row['id'] = (int) $row['id']; $row['amount'] = (float) abs( self::cents( $row['delta'] ) / 100 ); $row['signed_amount'] = (float) ( self::cents( $row['delta'] ) / 100 ); $row['balance'] = (float) ( $balance / 100 ); $row['key'] = $row['kind'] . ':' . $row['id']; $row['date'] = $row['occurred_on']; unset( $row['delta'], $row['sort_order'], $row['created_at'], $row['occurred_on'] ); } unset( $row );
        $closing = self::cents( $before ) + self::cents( $totals['credits'] ) - self::cents( $totals['debits'] ) + self::cents( $totals['initial'] );
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível concluir a consulta.', 500 ) ); }
        return array( 'account' => array( 'id' => (int) $account['id'], 'name' => $account['name'], 'active' => (bool) $account['active'], 'opening_on' => $account['opening_on'] ), 'from' => $from, 'to' => $to, 'movements' => $rows, 'balance_before' => (float) ( self::cents( $before ) / 100 ), 'credits' => (float) ( self::cents( $totals['credits'] ) / 100 ), 'debits' => (float) ( self::cents( $totals['debits'] ) / 100 ), 'opening_in_period' => (float) ( self::cents( $totals['initial'] ) / 100 ), 'balance_after' => (float) ( $closing / 100 ), 'total' => (int) $totals['count'], 'page' => $page, 'pages' => $pages, 'per_page' => $size );
    }
    private static function movement( $r ) {
        global $wpdb; $account = self::account( $r ); if ( is_wp_error( $account ) ) { return $account; } $params = $r->get_url_params(); $id = absint( $params['id'] ?? 0 ); $kind = $params['kind'] ?? ''; $uid = get_current_user_id(); $row = null; $date = null;
        if ( 'opening' === $kind && $id === (int) $account['id'] ) { $row = $account; $date = $row['opening_on']; }
        if ( 'receipt' === $kind ) { $row = PDS_Finance::find( 'receipts', $id ); if ( ! is_wp_error( $row ) && 'received' === $row['status'] && (int) $row['account_id'] === (int) $account['id'] ) { $date = $row['received_on']; } }
        if ( 'expense' === $kind ) { $row = PDS_Expenses::get_entry( $id ); if ( ! is_wp_error( $row ) && ! $row['card_id'] && 'credit' !== $row['payment_method'] && (int) $row['account_id'] === (int) $account['id'] ) { $date = $row['spent_on']; } }
        if ( 'payment' === $kind ) { $row = PDS_Finance::find( 'bill_payments', $id ); if ( ! is_wp_error( $row ) && (int) $row['account_id'] === (int) $account['id'] ) { $date = $row['paid_on']; } }
        if ( 'transfer' === $kind ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_transfers WHERE id=%d AND user_id=%d AND status='completed' AND (from_account_id=%d OR to_account_id=%d)", $id, $uid, $account['id'], $account['id'] ), ARRAY_A ); if ( $row ) { $date = $row['transferred_on']; } }
        if ( ! $date || $date < $account['opening_on'] ) { return self::error( 'Movimento não encontrado neste extrato. Atualize e confira a conta.', 404 ); }
        return array( 'kind' => $kind, 'id' => $id, 'account' => $account, 'row' => $row );
    }
    private static function fuel( $row ) {
        global $wpdb; $permission = PDS_Fuel::permission(); if ( is_wp_error( $permission ) ) { return $permission; }
        $fuel = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fuel_records WHERE id=%d AND created_by=%d", $row['fuel_record_id'], get_current_user_id() ), ARRAY_A ); if ( ! $fuel ) { return self::error( 'Abastecimento de origem não encontrado ou sem acesso.', 404 ); }
        $vehicle = PDS_Fuel::vehicle( $fuel['vehicle_id'] ); if ( is_wp_error( $vehicle ) ) { return $vehicle; } return $fuel;
    }
    public static function detail( WP_REST_Request $r ) {
        $m = self::movement( $r ); if ( is_wp_error( $m ) ) { return $m; } $row = $m['row']; $editor = $m['kind']; $note = ''; $message = 'Excluir este movimento? O saldo da conta será recalculado.';
        $data = array( 'account_id' => (int) $m['account']['id'], 'date' => $row['spent_on'] ?? $row['received_on'] ?? $row['paid_on'] ?? $row['transferred_on'] ?? $row['opening_on'], 'amount' => (float) ( $row['amount'] ?? $row['opening_balance'] ), 'description' => $row['description'] ?? '', 'notes' => $row['notes'] ?? '' );
        if ( 'opening' === $editor ) { $data['description'] = 'Saldo inicial'; $note = 'Ajusta o saldo inicial da conta. Os movimentos posteriores permanecem no histórico.'; $message = 'Zerar o saldo inicial desta conta? A conta e seus demais movimentos serão preservados.'; }
        if ( 'receipt' === $editor ) { if ( ! empty( $row['shared_expense_id'] ) ) { $data['description_readonly'] = true; $note = 'Corrige o reembolso já recebido, preservando o vínculo com a compra e a pessoa.'; $message = 'Cancelar este reembolso recebido e retirar sua entrada do saldo? A previsão ficará cancelada e não será recriada.'; } elseif ( ! empty( $row['recurring_id'] ) ) { $note = 'A edição vale apenas para este recebimento. A recorrência mantém as demais ocorrências.'; $message = 'Cancelar este recebimento e retirar sua entrada do saldo? As demais ocorrências da recorrência continuam.'; } }
        if ( 'expense' === $editor ) {
            $data['category_id'] = $row['category_id']; $data['payment_method'] = $row['payment_method'];
            if ( $row['payable_id'] ) { $editor = 'payable'; $note = 'Corrige o pagamento realizado. O vencimento original e as demais ocorrências da conta a pagar permanecem.'; $message = 'Excluir este pagamento e reabrir a conta a pagar como pendente? O saldo da conta será restaurado.'; }
            if ( $row['fuel_record_id'] ) { $fuel = self::fuel( $row ); if ( is_wp_error( $fuel ) ) { return $fuel; } $editor = 'fuel'; $data['fuel'] = array_intersect_key( $fuel, array_flip( array( 'odometer', 'price', 'liters', 'fuel_type', 'full_tank' ) ) ); $note = 'Altera também a data, o valor e os dados do abastecimento original. A sequência da quilometragem será conferida.'; $message = 'Excluir o abastecimento original e seu gasto correspondente? O saldo e o histórico de combustível serão atualizados.'; }
        }
        if ( 'payment' === $editor ) { $invoice = PDS_Finance::find( 'invoices', $row['invoice_id'] ); if ( is_wp_error( $invoice ) ) { return $invoice; } $card = PDS_Finance::find( 'cards', $invoice['card_id'] ); $data['description'] = 'Fatura · ' . $card['name'] . ' · ' . $invoice['invoice_month']; $note = 'Corrige este pagamento da fatura. As compras e os demais pagamentos permanecem.'; $message = 'Excluir este pagamento da fatura? O valor volta ao saldo da conta e ao saldo em aberto da fatura.'; }
        if ( 'transfer' === $editor ) { $data['from_account_id'] = (int) $row['from_account_id']; $data['to_account_id'] = (int) $row['to_account_id']; $data['description'] = 'Transferência entre contas'; $note = 'A edição será aplicada à origem e ao destino da mesma transferência, sem criar gastos ou recebimentos.'; $message = 'Excluir este movimento estornando a transferência nas duas contas? O registro continuará como estornado no histórico de transferências.'; }
        return array( 'kind' => $m['kind'], 'id' => $m['id'], 'editor' => $editor, 'data' => $data, 'edit_note' => $note, 'delete_message' => $message );
    }
    private static function request( $method, $id, $data = array(), $key = 'id' ) { $r = new WP_REST_Request( $method ); $r->set_url_params( array( $key => $id ) ); $r->set_body_params( $data ); return $r; }
    private static function bank( $id, $date, $old_id ) {
        if ( ! self::positive( $id ) ) { return self::error( 'Escolha a conta do movimento.' ); } $a = PDS_Finance::find( 'accounts', (int) $id ); if ( is_wp_error( $a ) ) { return $a; }
        if ( ! $a['active'] && (int) $a['id'] !== (int) $old_id ) { return self::error( 'Escolha uma conta ativa.', 409 ); } if ( $date < $a['opening_on'] ) { return self::error( 'A data do movimento deve ser igual ou posterior ao saldo inicial da conta.' ); } return $a;
    }
    public static function save( WP_REST_Request $r ) {
        $m = self::movement( $r ); if ( is_wp_error( $m ) ) { return $m; } $row = $m['row']; $kind = $m['kind']; $data = $r->get_json_params(); if ( ! is_array( $data ) ) { $data = $r->get_body_params(); }
        $date = $r->get_param( 'date' ); if ( ! PDS_Expenses::valid_date( $date ) || $date > current_time( 'Y-m-d' ) ) { return self::error( 'Informe a data real do movimento, até hoje.' ); }
        if ( 'opening' === $kind ) { $body = array_intersect_key( $row, array_flip( array( 'name', 'kind', 'active', 'opening_balance', 'opening_on' ) ) ); $body['active'] = (bool) $row['active']; $body['opening_balance'] = $r->get_param( 'amount' ); $body['opening_on'] = $date; return PDS_Finance::save_account( self::request( 'PUT', $row['id'], $body ) ); }
        if ( 'transfer' === $kind ) { $body = array_intersect_key( $data, array_flip( array( 'from_account_id', 'to_account_id', 'amount', 'notes' ) ) ); $body['transferred_on'] = $date; return PDS_Finance_Transfers::edit( self::request( 'PUT', $row['id'], $body ) ); }
        $bank = self::bank( $r->get_param( 'account_id' ) ?? $row['account_id'], $date, $row['account_id'] ); if ( is_wp_error( $bank ) ) { return $bank; }
        if ( 'payment' === $kind ) { return self::payment( $row, $bank, $r->get_param( 'amount' ), $date ); }
        if ( 'receipt' === $kind ) {
            $body = array_intersect_key( $row, array_flip( array( 'description', 'amount', 'notes' ) ) ); foreach ( array( 'description', 'amount', 'notes' ) as $key ) { if ( $r->has_param( $key ) ) { $body[ $key ] = $r->get_param( $key ); } } $body += array( 'account_id' => $bank['id'], 'received_on' => $date, 'status' => 'received' ); return PDS_Finance::save_receipt( self::request( 'PUT', $row['id'], $body ) );
        }
        $method = $r->get_param( 'payment_method' ) ?? $row['payment_method']; if ( ! is_string( $method ) || ! array_key_exists( $method, PDS_Expenses::PAYMENTS ) || 'credit' === $method ) { return self::error( 'Escolha a forma do pagamento realizado pela conta. Compras no crédito são gerenciadas nas faturas.' ); }
        if ( $row['fuel_record_id'] ) { return self::save_fuel( $row, $r, $bank, $date, $method ); }
        $body = array_intersect_key( $row, array_flip( array( 'description', 'amount', 'notes', 'merchant', 'category_id', 'payment_method' ) ) ); foreach ( array( 'description', 'amount', 'notes', 'merchant', 'category_id' ) as $key ) { if ( $r->has_param( $key ) ) { $body[ $key ] = $r->get_param( $key ); } } $body['payment_method'] = $method; $body['account_id'] = $bank['id']; $body['spent_on'] = $date;
        if ( $row['payable_id'] ) { return self::payable( $row, $body ); } return PDS_Expenses::save_entry( self::request( 'PUT', $row['id'], $body ) );
    }
    private static function payable( $row, $body ) {
        global $wpdb; $uid = get_current_user_id(); $wpdb->query( 'START TRANSACTION' ); $bill = PDS_Finance_Planning::find( 'payables', $row['payable_id'], $uid, true );
        if ( is_wp_error( $bill ) || 'paid' !== $bill['status'] || (int) $bill['expense_id'] !== (int) $row['id'] ) { return self::rollback( self::error( 'O pagamento desta conta mudou. Atualize o extrato.', 409 ) ); }
        $expense = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d AND payable_id=%d FOR UPDATE", $row['id'], $uid, $bill['id'] ), ARRAY_A ); if ( ! $expense ) { return self::rollback( self::error( 'Pagamento não encontrado.', 404 ) ); }
        $saved = PDS_Expenses::save_entry( self::request( 'PUT', $row['id'], $body ), null, array( 'in_transaction' => true, 'payable_correction' => (int) $bill['id'] ) ); if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_payables', array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $bill['id'], 'user_id' => $uid ) ) || false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível confirmar a correção do pagamento.', 500 ) ); }
        do_action( 'pds_expense_updated', $saved, $uid ); return $saved;
    }
    private static function payment( $old, $bank, $value, $date ) {
        global $wpdb; $uid = get_current_user_id(); $cents = PDS_Finance::cents( $value ); if ( is_wp_error( $cents ) || $cents <= 0 ) { return is_wp_error( $cents ) ? $cents : self::error( 'Informe um valor positivo.' ); }
        $wpdb->query( 'START TRANSACTION' ); $invoice = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d AND user_id=%d FOR UPDATE", $old['invoice_id'], $uid ) ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_bill_payments WHERE id=%d AND user_id=%d FOR UPDATE", $old['id'], $uid ), ARRAY_A );
        if ( ! $invoice || ! $row || (int) $row['invoice_id'] !== (int) $invoice ) { return self::rollback( self::error( 'Pagamento alterado. Atualize e confira.', 409 ) ); }
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT id,active,opening_on FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND user_id=%d FOR UPDATE", $bank['id'], $uid ), ARRAY_A ); if ( ! $locked || ( ! $locked['active'] && (int) $row['account_id'] !== (int) $locked['id'] ) || $date < $locked['opening_on'] ) { return self::rollback( self::error( 'Confira a conta e a data do pagamento.', 409 ) ); }
        $totals = PDS_Finance::invoice_totals( $invoice ); $other = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}pds_fin_bill_payments WHERE invoice_id=%d AND user_id=%d AND id<>%d", $invoice, $uid, $row['id'] ) );
        if ( $cents !== self::cents( $row['amount'] ) && $cents > max( 0, (int) round( $totals['total'] * 100 ) - self::cents( $other ) ) ) { return self::rollback( self::error( 'O pagamento ultrapassa o saldo da fatura após considerar os demais pagamentos.', 409 ) ); }
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_fin_bill_payments', array( 'account_id' => $bank['id'], 'amount' => PDS_Finance::decimal( $cents ), 'paid_on' => $date ), array( 'id' => $row['id'], 'user_id' => $uid ) ) || false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( self::error( 'Não foi possível corrigir o pagamento da fatura.', 500 ) ); } return array( 'id' => (int) $row['id'], 'success' => true );
    }
    private static function fuel_number( $v ) { if ( is_string( $v ) && strpos( $v, ',' ) !== false ) { if ( ! preg_match( '/^(?:\d{1,3}(?:\.\d{3})+|\d+),\d{1,3}$/D', $v ) ) { return self::error( 'Confira quilometragem, litros e preço do abastecimento.' ); } return str_replace( array( '.', ',' ), array( '', '.' ), $v ); } return $v; }
    private static function save_fuel( $row, $r, $bank, $date, $method ) {
        $fuel = self::fuel( $row ); if ( is_wp_error( $fuel ) ) { return $fuel; } $amount = PDS_Expenses::amount( $r->get_param( 'amount' ) ); if ( is_wp_error( $amount ) ) { return $amount; }
        $body = array_intersect_key( $fuel, array_flip( array( 'odometer', 'price', 'liters', 'fuel_type', 'full_tank', 'notes' ) ) ); foreach ( array( 'odometer', 'price', 'liters', 'fuel_type', 'full_tank' ) as $key ) { if ( $r->has_param( $key ) ) { $body[ $key ] = $r->get_param( $key ); } }
        foreach ( array( 'odometer', 'price', 'liters' ) as $key ) { $body[ $key ] = self::fuel_number( $body[ $key ] ); if ( is_wp_error( $body[ $key ] ) ) { return $body[ $key ]; } }
        $body += array( 'paid' => $amount, 'filled_on' => $date, 'payment_method' => $method, 'account_id' => $bank['id'] ); return PDS_Fuel::save_record( self::request( 'PUT', $fuel['id'], $body, 'record_id' ) );
    }
    public static function remove( WP_REST_Request $r ) {
        $m = self::movement( $r ); if ( is_wp_error( $m ) ) { return $m; } $row = $m['row']; $request = self::request( 'DELETE', $m['id'] );
        if ( 'opening' === $m['kind'] ) { $body = array_intersect_key( $row, array_flip( array( 'name', 'kind', 'active', 'opening_on' ) ) ); $body['active'] = (bool) $row['active']; $body['opening_balance'] = '0'; return PDS_Finance::save_account( self::request( 'PUT', $row['id'], $body ) ); }
        if ( 'transfer' === $m['kind'] ) { return PDS_Finance_Transfers::reverse( $request ); }
        if ( 'payment' === $m['kind'] ) { return PDS_Finance::delete_payment( $request ); }
        if ( 'receipt' === $m['kind'] ) { if ( ! empty( $row['recurring_id'] ) && empty( $row['shared_expense_id'] ) ) { $body = array_intersect_key( $row, array_flip( array( 'description', 'amount', 'account_id', 'received_on', 'notes' ) ) ); $body['status'] = 'canceled'; return PDS_Finance::save_receipt( self::request( 'PUT', $row['id'], $body ) ); } return PDS_Finance::delete_receipt( $request ); }
        if ( $row['fuel_record_id'] ) { $fuel = self::fuel( $row ); if ( is_wp_error( $fuel ) ) { return $fuel; } return PDS_Fuel::delete_record( self::request( 'DELETE', $fuel['id'], array(), 'record_id' ) ); }
        if ( $row['payable_id'] ) { return PDS_Finance_Planning::reopen( self::request( 'POST', $row['payable_id'] ) ); } return PDS_Expenses::delete_entry( $request );
    }
    /** Re-basing an existing account must not silently hide a movement already included in its balance. */
    public static function check_opening_date( $old, $date ) {
        if ( $date <= $old['opening_on'] ) { return true; } global $wpdb; $union = self::query( $old ); $first = $wpdb->get_var( "SELECT MIN(occurred_on) FROM ($union) m WHERE kind<>'opening'" ); if ( $wpdb->last_error ) { return self::error( 'Não foi possível conferir os movimentos da conta.', 500 ); }
        return $first && $date > $first ? self::error( 'Há movimento a partir de ' . $first . '. A data inicial deve ficar até esse dia; corrija o movimento antes de avançá-la.', 409 ) : true;
    }
}
