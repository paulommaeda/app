<?php
defined( 'ABSPATH' ) || exit;

/** Exact allocation of the existing installment ledger, including the owner's part. */
final class PDS_Finance_Splits {
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'pds/v1', '/finance/expenses/(?P<id>\d+)/sharing', array(
                array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'context_request' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
                array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_request' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
            ) );
        } );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-allocation', PDS_URL . 'assets/allocation.js', array( 'pds-finance' ), PDS_VERSION, true ); } );
    }
    private static function positive( $v ) { return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $v ) ? (int) $v : 0; }
    private static function rows( $id, $uid ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT person_id,weight FROM {$wpdb->prefix}pds_fin_splits WHERE expense_id=%d AND user_id=%d ORDER BY person_id", $id, $uid ), ARRAY_A ); }
    private static function schedule( $id, $uid ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT s.installment_no,s.installment_total,s.amount,i.due_on FROM {$wpdb->prefix}pds_fin_installments s INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=s.invoice_id AND i.user_id=s.user_id WHERE s.expense_id=%d AND s.user_id=%d ORDER BY s.installment_no", $id, $uid ), ARRAY_A ); }
    private static function cents( $v ) { return PDS_Finance::cents( $v ); }
    private static function gcd( $a, $b ) { while ( $b ) { $t = $a % $b; $a = $b; $b = $t; } return $a; }
    /** floor(a*b/d) and its remainder, without overflowing 64-bit integers. */
    public static function muldiv( $a, $b, $d ) {
        $q = 0; $r = 0; $fq = intdiv( $a, $d ); $fr = $a % $d;
        while ( $b > 0 ) {
            if ( $b % 2 ) { $q += $fq; $r += $fr; if ( $r >= $d ) { $q++; $r -= $d; } }
            $b = intdiv( $b, 2 ); if ( ! $b ) { break; }
            $fq *= 2; $fr *= 2; if ( $fr >= $d ) { $fq++; $fr -= $d; }
        }
        return array( $q, $r );
    }
    public static function apportion( $amount, $weights ) {
        $sum = array_sum( $weights ); $out = array_fill_keys( array_keys( $weights ), 0 ); if ( ! $sum ) { return $out; }
        $fractions = array(); $used = 0;
        foreach ( $weights as $key => $weight ) { list( $q, $r ) = self::muldiv( $amount, $weight, $sum ); $out[ $key ] = $q; $used += $q; $fractions[] = array( 'key' => $key, 'remainder' => $r ); }
        usort( $fractions, static fn( $a, $b ) => $b['remainder'] <=> $a['remainder'] ?: $a['key'] <=> $b['key'] );
        for ( $i = 0; $i < $amount - $used; $i++ ) { $out[ $fractions[ $i ]['key'] ]++; }
        return $out;
    }
    /** All row totals and all participant totals are conserved to the last cent. */
    public static function matrix( $parts, $weights ) {
        $remaining = array_sum( array_column( $parts, 'cents' ) ); $targets = self::apportion( $remaining, $weights ); $out = array();
        foreach ( $parts as $part ) { $allocation = self::apportion( $part['cents'], $targets ); $out[ $part['installment_no'] ] = $allocation; foreach ( $allocation as $key => $amount ) { $targets[ $key ] -= $amount; } $remaining -= $part['cents']; }
        return $out;
    }
    private static function previous_rows( $old, $uid ) {
        if ( ! empty( $old['sharing_mode'] ) ) { return self::rows( $old['id'], $uid ); }
        return empty( $old['person_id'] ) ? array() : array( array( 'person_id' => (int) $old['person_id'], 'weight' => 1 ) );
    }
    public static function prepare( $r, $old, $payment, $card_id, $uid, $finance ) {
        $old = $old ?: array(); $previous = self::previous_rows( $old, $uid );
        // Older clients do not know about split allocations: do not clear them implicitly.
        if ( ! $r->has_param( 'sharing' ) ) {
            if ( ! empty( $old['sharing_mode'] ) ) {
                if ( 'none' !== $old['sharing_mode'] && ( 'credit' !== $payment || ! $card_id ) ) { return PDS_Finance::error( 'Remova a divisão antes de trocar a forma de pagamento.' ); }
                return array( 'person_id' => $old['person_id'] ?: null, 'sharing_mode' => $old['sharing_mode'], 'sharing_start' => $old['sharing_start'], '_split_rows' => null );
            }
            $person = PDS_Finance_Sharing::prepare( $r, $old, $payment, $card_id, $uid );
            return is_wp_error( $person ) ? $person : array( 'person_id' => $person, 'sharing_mode' => '', 'sharing_start' => null, '_split_rows' => array() );
        }
        $input = $r->get_param( 'sharing' ); if ( is_string( $input ) && strlen( $input ) <= 12000 ) { $input = json_decode( $input, true ); }
        if ( ! is_array( $input ) || ! isset( $input['mode'] ) || ! in_array( $input['mode'], array( 'none', 'single', 'equal', 'amounts' ), true ) ) { return PDS_Finance::error( 'Confira o modo de divisão da compra.' ); }
        $mode = $input['mode']; $source_start = (int) $finance['installment_start']; $n = (int) $finance['installments'];
        $start = $input['start_installment'] ?? ( ( $old['sharing_start'] ?? null ) ?: $source_start );
        if ( 'none' === $mode && 'credit' !== $payment ) { $start = 1; }
        if ( ! self::positive( $start ) || (int) $start < $source_start || (int) $start > $n ) { return PDS_Finance::error( 'Confira a parcela inicial da divisão, entre ' . $source_start . ' e ' . $n . '.' ); } $start = (int) $start;
        if ( 'none' === $mode ) { return array( 'person_id' => null, 'sharing_mode' => 'none', 'sharing_start' => $start, '_split_rows' => array() ); }
        if ( 'credit' !== $payment || ! $card_id ) { return PDS_Finance::error( 'Divisões de cartão exigem um cartão de crédito cadastrado.' ); }
        $owner = $input['include_owner'] ?? false; if ( ! is_bool( $owner ) ) { return PDS_Finance::error( 'Confira a opção da sua parte na compra.' ); }
        $people = 'single' === $mode ? array( array( 'person_id' => $input['person_id'] ?? 0 ) ) : ( $input['people'] ?? null );
        if ( ! is_array( $people ) || array_keys( $people ) !== array_keys( array_values( $people ) ) || count( $people ) < 1 || count( $people ) > 20 ) { return PDS_Finance::error( 'Escolha de 1 a 20 pessoas para a divisão.' ); }
        if ( 'single' === $mode ) { $owner = false; }
        $schedule = $finance['_plan']['installments'] ?? array();
        if ( ! empty( $old['id'] ) && (float) $old['amount'] === (float) $r->get_param( 'amount' ) && (int) $old['card_id'] === $card_id && $old['spent_on'] === $r->get_param( 'spent_on' ) && $old['first_invoice'] === $finance['first_invoice'] && (int) $old['installments'] === $n ) { $schedule = self::schedule( $old['id'], $uid ); }
        $basis = 0; foreach ( $schedule as $part ) { if ( (int) $part['installment_no'] >= $start ) { $basis += self::cents( $part['amount'] ); } }
        if ( $basis < 1 ) { return PDS_Finance::error( 'Não há parcelas disponíveis para dividir.' ); }
        $weights = array(); $old_people = array_map( 'intval', array_column( $previous, 'person_id' ) ); $sum = 0;
        global $wpdb;
        foreach ( $people as $p ) {
            if ( ! is_array( $p ) || ! ( $id = self::positive( $p['person_id'] ?? 0 ) ) || isset( $weights[ $id ] ) ) { return PDS_Finance::error( 'Escolha pessoas diferentes, sem repetir nomes na divisão.' ); }
            $person = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_people WHERE id=%d AND user_id=%d", $id, $uid ), ARRAY_A );
            if ( ! $person ) { return PDS_Finance::error( 'Pessoa não encontrada ou sem acesso.', 404 ); }
            if ( ! $person['active'] && ! in_array( $id, $old_people, true ) ) { return PDS_Finance::error( 'Escolha pessoas ativas.', 409 ); }
            $value = 'amounts' === $mode ? self::cents( $p['amount'] ?? null ) : 1;
            if ( is_wp_error( $value ) || $value < 1 ) { return PDS_Finance::error( 'Informe um valor positivo para cada pessoa.' ); }
            $weights[ $id ] = $value; $sum += $value;
        }
        if ( 'amounts' === $mode ) {
            if ( $sum > $basis || ( ! $owner && $sum !== $basis ) ) { return PDS_Finance::error( 'A soma das pessoas deve corresponder ao valor das parcelas a dividir. Com sua parte marcada, o restante fica com você.' ); }
            if ( $owner ) { $weights[0] = $basis - $sum; }
            $gcd = 0; foreach ( $weights as $w ) { $gcd = self::gcd( $gcd, $w ); } foreach ( $weights as &$w ) { $w = intdiv( $w, max( 1, $gcd ) ); } unset( $w );
        } elseif ( $owner ) { $weights[0] = 1; }
        if ( 'amounts' !== $mode && $basis < count( $weights ) ) { return PDS_Finance::error( 'Cada participante deve ter pelo menos um centavo no total.' ); }
        ksort( $weights ); $saved = array(); foreach ( $weights as $id => $weight ) { $saved[] = array( 'person_id' => (int) $id, 'weight' => (int) $weight ); }
        if ( ! PDS_Finance_Sharing::enabled() ) {
            $old_weights = array(); foreach ( $previous as $p ) { $old_weights[ (int) $p['person_id'] ] = (int) $p['weight']; } ksort( $old_weights );
            if ( $weights !== $old_weights || $start !== (int) ( ( $old['sharing_start'] ?? null ) ?: $source_start ) || $mode !== ( $old['sharing_mode'] ?: 'single' ) ) { return PDS_Finance::error( 'Ative o compartilhamento nas configurações para definir uma nova divisão.', 403 ); }
        }
        $ids = array_keys( array_filter( $weights, static fn( $w, $id ) => $id > 0, ARRAY_FILTER_USE_BOTH ) );
        return array( 'person_id' => count( $ids ) === 1 ? $ids[0] : null, 'sharing_mode' => $mode, 'sharing_start' => $start, '_split_rows' => $saved );
    }
    public static function persist( $id, $uid, $finance ) {
        global $wpdb; if ( ! array_key_exists( '_split_rows', $finance ) || null === $finance['_split_rows'] ) { return true; }
        if ( false === $wpdb->delete( $wpdb->prefix . 'pds_fin_splits', array( 'expense_id' => $id, 'user_id' => $uid ) ) ) { return PDS_Finance::error( 'Não foi possível salvar a divisão.', 500 ); }
        foreach ( $finance['_split_rows'] as $row ) { if ( false === $wpdb->insert( $wpdb->prefix . 'pds_fin_splits', $row + array( 'expense_id' => $id, 'user_id' => $uid ) ) ) { return PDS_Finance::error( 'Não foi possível salvar todos os participantes.', 500 ); } }
        return true;
    }
    public static function names( $expense, $uid ) {
        $names = array(); foreach ( self::previous_rows( $expense, $uid ) as $p ) { $names[] = $p['person_id'] ? PDS_Finance_Sharing::name( $p['person_id'], $uid ) : 'Você'; } return implode( ' + ', array_filter( $names ) );
    }
    public static function context( $e, $uid, $start = null ) {
        $parts = self::schedule( $e['id'], $uid ); $first = (int) $e['installment_start']; $start = null === $start ? (int) ( $e['sharing_start'] ?: $first ) : (int) $start;
        $basis = 0; foreach ( $parts as &$p ) { $p['installment_no'] = (int) $p['installment_no']; $p['installment_total'] = (int) $p['installment_total']; $p['amount'] = (float) $p['amount']; if ( $p['installment_no'] >= $start ) { $basis += self::cents( $p['amount'] ); } } unset( $p );
        $mode = $e['sharing_mode'] ?: ( $e['person_id'] ? 'single' : 'none' ); $weights = array(); foreach ( self::previous_rows( $e, $uid ) as $p ) { $weights[ (int) $p['person_id'] ] = (int) $p['weight']; }
        $amounts = self::apportion( $basis, $weights ); $people = array(); foreach ( $weights as $id => $weight ) { if ( $id ) { $people[] = array( 'person_id' => $id, 'amount' => PDS_Finance::decimal( $amounts[ $id ] ), 'name' => PDS_Finance_Sharing::name( $id, $uid ) ); } }
        return array( 'expense_id' => (int) $e['id'], 'description' => $e['description'], 'source_start' => $first, 'start_installment' => $start, 'installment_total' => (int) $e['installments'], 'basis_amount' => (float) ( $basis / 100 ), 'schedule' => $parts, 'sharing' => array( 'mode' => $mode, 'include_owner' => array_key_exists( 0, $weights ), 'person_id' => count( $people ) === 1 ? $people[0]['person_id'] : null, 'people' => $people, 'start_installment' => $start ) );
    }
    public static function context_request( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); $e = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d", absint( $r->get_url_params()['id'] ?? 0 ), $uid ), ARRAY_A ); if ( ! $e ) { return PDS_Finance::error( 'Gasto não encontrado ou sem acesso.', 404 ); }
        $start = $r->get_param( 'start_installment' ); if ( null !== $start && ( ! self::positive( $start ) || (int) $start < (int) $e['installment_start'] || (int) $start > (int) $e['installments'] ) ) { return PDS_Finance::error( 'Parcela inicial inválida.' ); }
        return self::context( $e, $uid, $start );
    }
    public static function apply( $id, $uid, $sharing ) {
        global $wpdb; $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $id, $uid ), ARRAY_A ); if ( ! $old ) { return PDS_Finance::error( 'Gasto não encontrado ou sem acesso.', 404 ); }
        $r = new WP_REST_Request( 'PUT' ); $r->set_body_params( array_merge( $old, array( 'sharing' => $sharing ) ) );
        $finance = PDS_Finance::prepare( $r, $old, array(), $uid ); if ( is_wp_error( $finance ) ) { return $finance; }
        $data = array( 'person_id' => $finance['person_id'], 'sharing_mode' => $finance['sharing_mode'], 'sharing_start' => $finance['sharing_start'], 'updated_at' => current_time( 'mysql', true ) );
        if ( false === $wpdb->update( $wpdb->prefix . 'pds_expenses', $data, array( 'id' => $id, 'user_id' => $uid ) ) ) { return PDS_Finance::error( 'Não foi possível atualizar a divisão.', 500 ); }
        $saved = self::persist( $id, $uid, $finance ); return is_wp_error( $saved ) ? $saved : self::sync( $id, $uid );
    }
    public static function save_request( WP_REST_Request $r ) {
        global $wpdb; $uid = get_current_user_id(); if ( ! $r->has_param( 'sharing' ) ) { return PDS_Finance::error( 'Informe a divisão.' ); }
        $wpdb->query( 'START TRANSACTION' ); $id = absint( $r->get_url_params()['id'] ?? 0 ); $saved = self::apply( $id, $uid, $r->get_param( 'sharing' ) );
        if ( is_wp_error( $saved ) ) { $wpdb->query( 'ROLLBACK' ); return $saved; } if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível confirmar a divisão.', 500 ); }
        $entry = PDS_Expenses::get_entry( $id ); if ( ! is_wp_error( $entry ) ) { do_action( 'pds_expense_updated', $entry, $uid ); } return array( 'success' => true, 'id' => $id );
    }
    public static function sync( $id, $uid ) {
        global $wpdb; $e = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $id, $uid ), ARRAY_A ); if ( ! $e || $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir o gasto compartilhado.', 500 ); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_receipts WHERE shared_expense_id=%d AND user_id=%d ORDER BY shared_installment_no,person_id FOR UPDATE", $id, $uid ), ARRAY_A ); if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir os reembolsos.', 500 ); }
        $allocations = self::previous_rows( $e, $uid ); $start = (int) ( $e['sharing_start'] ?: 1 ); $schedule = self::schedule( $id, $uid ); $parts = array(); $future = array(); $weights = array(); $expected = array();
        foreach ( $schedule as $p ) { $p['cents'] = self::cents( $p['amount'] ); $parts[ (int) $p['installment_no'] ] = $p; if ( (int) $p['installment_no'] >= $start ) { $future[] = $p; } }
        foreach ( $allocations as $a ) {
            $pid = (int) $a['person_id']; if ( $pid ) {
                $person = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_people WHERE id=%d AND user_id=%d", $pid, $uid ), ARRAY_A ); $known = in_array( (string) $pid, array_column( $rows, 'person_id' ), true );
                if ( ! $person || ( ! $known && ( ! $person['active'] || ! PDS_Finance_Sharing::enabled() ) ) ) { return PDS_Finance::error( 'Ative o compartilhamento e escolha pessoas próprias e ativas.', 409 ); }
            } $weights[ $pid ] = (int) $a['weight'];
        }
        if ( $weights && ! $future ) { return PDS_Finance::error( 'Não há parcelas disponíveis para a divisão.', 409 ); }
        $matrix = self::matrix( $future, $weights );
        foreach ( $future as $p ) { foreach ( $matrix[ $p['installment_no'] ] as $pid => $cents ) { if ( $pid && $cents > 0 ) { $expected[ $pid . ':' . $p['installment_no'] ] = array( 'person_id' => $pid, 'part' => $p, 'cents' => $cents ); } } }
        // A new allocation can start at the imported/current installment; earlier agreements remain.
        $history = array(); foreach ( $rows as $row ) { if ( (int) $row['shared_installment_no'] < $start ) { $history[ (int) $row['shared_installment_no'] ][] = $row; } }
        foreach ( $history as $no => $group ) {
            if ( ! isset( $parts[ $no ] ) ) { continue; } $p = $parts[ $no ]; $old_weights = array(); $base = self::cents( $group[0]['shared_invoice_amount'] ?? $p['amount'] ); $used = 0;
            foreach ( $group as $row ) { $cents = self::cents( $row['shared_planned_amount'] ); $old_weights[ (int) $row['person_id'] ] = $cents; $used += $cents; } $old_weights[0] = max( 0, $base - $used );
            foreach ( self::apportion( $p['cents'], $old_weights ) as $pid => $cents ) { if ( $pid && $cents > 0 ) { $expected[ $pid . ':' . $no ] = array( 'person_id' => $pid, 'part' => $p, 'cents' => $cents ); } }
        }
        $existing = array(); foreach ( $rows as $row ) {
            $key = $row['person_id'] . ':' . $row['shared_installment_no']; $existing[ $key ] = $row; $next = $expected[ $key ] ?? null;
            if ( 'received' === $row['status'] && ( ! $next || self::cents( $row['shared_planned_amount'] ) !== $next['cents'] || $row['shared_planned_on'] !== $next['part']['due_on'] || (int) $row['shared_installment_total'] !== (int) $next['part']['installment_total'] || ( null !== $row['shared_invoice_amount'] && self::cents( $row['shared_invoice_amount'] ) !== $next['part']['cents'] ) ) ) { return PDS_Finance::error( 'Já há reembolso recebido nessa parcela. Reverta o recebimento ou aplique a divisão somente às próximas parcelas.', 409 ); }
        }
        $now = current_time( 'mysql', true );
        foreach ( $existing as $key => $row ) { if ( ! isset( $expected[ $key ] ) && false === $wpdb->delete( $wpdb->prefix . 'pds_fin_receipts', array( 'id' => $row['id'], 'user_id' => $uid ) ) ) { return PDS_Finance::error( 'Não foi possível remover previsões antigas.', 500 ); } }
        foreach ( $expected as $key => $next ) {
            $old = $existing[ $key ] ?? null; if ( $old && 'received' === $old['status'] ) { continue; } $p = $next['part'];
            $data = array( 'person_id' => $next['person_id'], 'shared_installment_total' => (int) $p['installment_total'], 'shared_planned_amount' => PDS_Finance::decimal( $next['cents'] ), 'shared_planned_on' => $p['due_on'], 'shared_invoice_amount' => $p['amount'], 'amount' => PDS_Finance::decimal( $next['cents'] ), 'received_on' => $p['due_on'], 'description' => mb_substr( 'Reembolso · ' . $e['description'], 0, 150 ) . ' · ' . $p['installment_no'] . '/' . $p['installment_total'], 'updated_at' => $now );
            $ok = $old ? $wpdb->update( $wpdb->prefix . 'pds_fin_receipts', $data, array( 'id' => $old['id'], 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_receipts', $data + array( 'user_id' => $uid, 'account_id' => 0, 'status' => 'pending', 'notes' => '', 'source' => 'shared_card', 'shared_expense_id' => $id, 'shared_installment_no' => (int) $p['installment_no'], 'created_at' => $now ) );
            if ( false === $ok ) { return PDS_Finance::error( 'Não foi possível gerar todos os reembolsos.', 500 ); }
        }
        return true;
    }
}
