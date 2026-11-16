<?php
defined( 'ABSPATH' ) || exit;

/** Telegram settles one owned forecast through the existing ledgers, never a duplicate entry. */
final class PDS_Telegram_Settlements {
    public static function fields() {
        $fields = array( 'kind' => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'payable', 'receipt', 'invoice', null ) ) );
        foreach ( array( 'description', 'paid_on', 'expected_on', 'reference_month', 'account_hint', 'card_hint', 'notes' ) as $key ) { $fields[ $key ] = array( 'type' => array( 'string', 'null' ) ); }
        $fields['amount'] = array( 'type' => array( 'number', 'null' ) );
        $fields['payment_method'] = array( 'type' => array( 'string', 'null' ), 'enum' => array( 'pix', 'cash', 'debit', 'credit', 'transfer', 'other', null ) );
        $fields['uncertain_fields'] = array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( $fields ) ) ); return $fields;
    }
    public static function instructions() {
        return ' BAIXA DE PREVISÃO: esta regra tem prioridade sobre cadastrar novo gasto/recebimento quando a pessoa informa que realizou uma previsão existente. Use intent settlement e somente settlement (expense/receipt/payable null, abastecimento null e uncertain_fields vazio) para dar baixa, quitar, pagar uma conta prevista, receber um valor previsto ou pagar fatura de cartão. Exemplos: dei baixa na luz de novembro, paguei o aluguel previsto de 950 ontem no Pix pela C6 PJ, recebi o salário previsto de novembro na C6 PJ, recebi da Isabela o reembolso do cartão, paguei 300 da fatura C6 de novembro. kind=payable para conta paga; receipt para recebimento de previsão ou reembolso recebido; invoice para pagamento de fatura. Não use invoice para uma compra feita no cartão. description identifica a previsão, nunca uma instrução ou um ID. amount é somente o valor efetivamente realizado, não deduza a partir de uma previsão ou saldo. paid_on é a data REAL em AAAA-MM-DD, resolvendo hoje/ontem pela referência; null se não dita. expected_on é o vencimento/data originalmente prevista somente se explicitamente dita; reference_month é a competência/fatura dita em AAAA-MM, sem confundir com a data real. account_hint é a conta bancária da saída/entrada dita; card_hint é o nome/final literal do cartão dito. Não invente conta, cartão, ID, valor, categoria, parcela ou data. Pagamento de conta no crédito é à vista; se mencionar parcelar a baixa, use unclear e solicite lançamento separado pelo portal. Nos outros intents, settlement=null. Para active_service=baixas, complementos como na C6 PJ, paguei ontem, 195 reais, foi no Pix ou novembro pertencem a settlement, somente com os novos campos. Nunca interprete vou pagar/vou receber como baixa; são previsões ainda pendentes. Um gasto realizado comum continua expense e uma entrada comum continua receipt; o servidor verifica se há previsão correspondente. Não some várias baixas nem misture serviços numa mensagem: use unclear. Transferências próprias e resgates de investimento são other, não são baixa de gasto ou recebimento. Qualquer número/moeda/data/conta incerto fica null e marcado em settlement.uncertain_fields.';
    }
    public static function validate_extraction( $result ) {
        $s = $result['settlement'] ?? null; $fail = static fn() => new WP_Error( 'pds_tg_extract', 'Não consegui separar os dados da baixa. Informe uma conta ou recebimento previsto por mensagem.' );
        if ( null === $s ) { return 'settlement' === $result['intent'] ? $fail() : true; }
        $fields = self::fields();
        if ( 'settlement' !== $result['intent'] || ! is_array( $s ) || array_diff( array_keys( $fields ), array_keys( $s ) ) || array_diff( array_keys( $s ), array_keys( $fields ) ) || ! is_array( $s['uncertain_fields'] ) ) { return $fail(); }
        foreach ( array( 'expense', 'receipt', 'payable', 'vehicle_hint', 'filled_on', 'odometer', 'fuel_type', 'price', 'liters', 'paid', 'full_tank', 'no_discount', 'notes' ) as $key ) { if ( null !== ( $result[ $key ] ?? null ) ) { return $fail(); } }
        if ( ! empty( $result['uncertain_fields'] ) || ! in_array( $s['kind'], $fields['kind']['enum'], true ) || ! in_array( $s['payment_method'], $fields['payment_method']['enum'], true ) ) { return $fail(); }
        foreach ( array( 'description', 'paid_on', 'expected_on', 'reference_month', 'account_hint', 'card_hint', 'notes' ) as $key ) { if ( null !== $s[ $key ] && ( ! is_string( $s[ $key ] ) || mb_strlen( $s[ $key ] ) > ( 'notes' === $key ? 500 : 180 ) ) ) { return $fail(); } }
        if ( null !== $s['amount'] && ( ( ! is_int( $s['amount'] ) && ! is_float( $s['amount'] ) ) || ! is_finite( (float) $s['amount'] ) ) ) { return $fail(); }
        foreach ( $s['uncertain_fields'] as $key ) { if ( ! is_string( $key ) || ! in_array( $key, $fields['uncertain_fields']['items']['enum'], true ) ) { return $fail(); } } return true;
    }
    public static function draft( $uid, $telegram ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_settlement_drafts WHERE user_id=%d AND telegram_id=%s AND expires_at>%d", $uid, $telegram, time() ), ARRAY_A );
        if ( ! $row || ( (int) $row['finance_space_id'] ?: PDS_Finance_Spaces::personal( $uid ) ) !== PDS_Finance_Spaces::id() ) { return null; } $row['data'] = json_decode( $row['data'], true ); return is_array( $row['data'] ) ? $row : null;
    }
    private static function write( $draft ) {
        global $wpdb; return false !== $wpdb->replace( $wpdb->prefix . 'pds_tg_settlement_drafts', array( 'user_id' => get_current_user_id(), 'finance_space_id' => PDS_Finance_Spaces::id(), 'telegram_id' => $draft['telegram_id'], 'source_ref' => $draft['source_ref'], 'token' => $draft['token'], 'data' => wp_json_encode( $draft['data'] ), 'expires_at' => time() + 2 * HOUR_IN_SECONDS ) );
    }
    private static function clear() { global $wpdb; $wpdb->delete( $wpdb->prefix . 'pds_tg_settlement_drafts', array( 'user_id' => get_current_user_id() ) ); }
    private static function live( $job ) {
        $uid = get_current_user_id(); wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' ); $link = PDS_Telegram::link( $uid ); $config = PDS_Telegram_Client::settings();
        return $link && $link['telegram_id'] === $job['telegram_id'] && $config['enabled'] && $config['bot_id'] === ( $job['bot_id'] ?? '' ) && $link['consent_provider'] === $config['ai_provider'] && pds_user_can_access_service( 'gastos' ) && PDS_Telegram_Spaces::live( $job );
    }
    public static function context( $draft ) { return array_intersect_key( $draft['data'], self::fields() ); }
    private static function normalized( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $s ) ) ) ); }
    private static function words( $s ) {
        $stop = array( 'a','as','o','os','e','de','da','das','do','dos','um','uma','na','no','em','para','por','conta','pagamento','recebimento','previsto','prevista','paguei','recebi','baixa','baixar','pagar','receber','quitar','fatura','cartao','hoje','ontem','este','esse','esta','mes' );
        return array_values( array_unique( array_filter( explode( ' ', self::normalized( $s ) ), static fn( $w ) => strlen( $w ) >= 2 && ! in_array( $w, $stop, true ) ) ) );
    }
    private static function search_where( $data, $description, $date, $person = '' ) {
        global $wpdb; $where = ''; $words = self::words( $data['description'] ?? '' );
        foreach ( array_slice( $words, 0, 8 ) as $word ) {
            $variants = in_array( $word, array( 'luz', 'energia', 'eletrica' ), true ) ? array( 'luz', 'energia', 'eletrica' ) : array( $word ); $parts = array();
            foreach ( $variants as $v ) { $like = '%' . $wpdb->esc_like( $v ) . '%'; $parts[] = $wpdb->prepare( "$description LIKE %s", $like ); if ( $person ) { $parts[] = $wpdb->prepare( "$person LIKE %s", $like ); } }
            $where .= ' AND (' . implode( ' OR ', $parts ) . ')';
        }
        if ( ! empty( $data['expected_on'] ) ) { $where .= $wpdb->prepare( " AND $date=%s", $data['expected_on'] ); }
        if ( ! empty( $data['reference_month'] ) ) { $where .= $wpdb->prepare( " AND $date BETWEEN %s AND %s", $data['reference_month'] . '-01', PDS_Finance::day( $data['reference_month'], 31 ) ); }
        return $where;
    }
    /** Only this user's pending records are candidates; IDs always originate in these queries. */
    public static function candidates( $data, $settled = false ) {
        global $wpdb; $p = $wpdb->prefix; $uid = PDS_Finance_Spaces::id(); $kind = $data['kind'] ?? null; $found = array();
        if ( ! empty( $data['expected_on'] ) && ! PDS_Expenses::valid_date( $data['expected_on'] ) || ! empty( $data['reference_month'] ) && ! PDS_Finance::month( $data['reference_month'] ) ) { return array(); }
        if ( ! $kind || 'payable' === $kind ) {
            $where = self::search_where( $data, 'b.description', 'b.due_on' ); $status = $settled ? 'paid' : 'pending';
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id FROM {$p}pds_fin_payables b WHERE b.space_id=%d AND b.status=%s $where ORDER BY b.due_on,b.id LIMIT 9", $uid, $status ), ARRAY_A );
            foreach ( $rows as $r ) { $row = self::target( 'payable', $r['id'] ); if ( ! is_wp_error( $row ) ) { $found[] = $row; } }
        }
        if ( ! $kind || 'receipt' === $kind ) {
            $where = self::search_where( $data, 'r.description', 'r.received_on', 'pe.name' ); $status = $settled ? 'received' : 'pending';
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.id FROM {$p}pds_fin_receipts r LEFT JOIN {$p}pds_fin_people pe ON pe.id=r.person_id AND pe.space_id=r.space_id WHERE r.space_id=%d AND r.status=%s $where ORDER BY r.received_on,r.id LIMIT 9", $uid, $status ), ARRAY_A );
            foreach ( $rows as $r ) { $row = self::target( 'receipt', $r['id'] ); if ( ! is_wp_error( $row ) ) { $found[] = $row; } }
        }
        if ( ! $settled && ( ! $kind || 'invoice' === $kind ) ) {
            $where = self::search_where( $data, "CONCAT('Fatura ',c.name)", 'i.due_on' );
            if ( ! empty( $data['card_hint'] ) ) { $where .= $wpdb->prepare( ' AND (c.name LIKE %s OR c.last_four=%s)', '%' . $wpdb->esc_like( $data['card_hint'] ) . '%', $data['card_hint'] ); }
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT i.id FROM {$p}pds_fin_invoices i INNER JOIN {$p}pds_fin_cards c ON c.id=i.card_id AND c.space_id=i.space_id WHERE i.space_id=%d $where ORDER BY ABS(DATEDIFF(i.due_on,%s)),i.due_on,i.id LIMIT 100", $uid, current_time( 'Y-m-d' ) ) );
            foreach ( $ids as $id ) { $row = self::target( 'invoice', $id ); if ( ! is_wp_error( $row ) && $row['pending'] ) { $found[] = $row; if ( count( $found ) >= 9 ) { break; } } }
        }
        usort( $found, static fn( $a, $b ) => strcmp( $a['expected_on'], $b['expected_on'] ) ?: strcmp( $a['description'], $b['description'] ) ); return array_slice( $found, 0, 9 );
    }
    public static function target( $kind, $id, $lock = false ) {
        global $wpdb; $uid = PDS_Finance_Spaces::id(); $tables = array( 'payable' => 'payables', 'receipt' => 'receipts', 'invoice' => 'invoices' );
        if ( ! isset( $tables[ $kind ] ) ) { return PDS_Finance::error( 'Previsão inválida.' ); }
        if ( $lock && 'receipt' === $kind ) { $eid = $wpdb->get_var( $wpdb->prepare( "SELECT shared_expense_id FROM {$wpdb->prefix}pds_fin_receipts WHERE id=%d AND space_id=%d", $id, $uid ) ); if ( $eid ) { $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND space_id=%d FOR UPDATE", $eid, $uid ) ); } }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_{$tables[$kind]} WHERE id=%d AND space_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id, $uid ), ARRAY_A );
        if ( ! $row || $wpdb->last_error || ( 'payable' === $kind && 'deleted' === $row['status'] ) ) { return PDS_Finance::error( 'Previsão não encontrada ou sem acesso.', 404 ); }
        if ( 'invoice' === $kind ) { $card = PDS_Finance::find( 'cards', $row['card_id'] ); if ( is_wp_error( $card ) ) { return $card; } $totals = PDS_Finance::invoice_totals( $row['id'] ); $row['_totals'] = $totals; $description = 'Fatura · ' . $card['name']; $amount = $totals['outstanding']; $date = $row['due_on']; $pending = $amount > 0; }
        else { $description = $row['description']; $amount = null === $row['amount'] ? null : (float) $row['amount']; $date = $row[ 'receipt' === $kind ? 'received_on' : 'due_on' ]; $pending = 'pending' === $row['status']; }
        $person = ''; if ( ! empty( $row['person_id'] ) ) { $person = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}pds_fin_people WHERE id=%d AND space_id=%d", $row['person_id'], $uid ) ); if ( $person ) { $description .= ' · ' . $person; } }
        $snapshot = array_intersect_key( $row, array_flip( array( 'id','space_id','description','amount','status','account_id','category_id','notes','due_on','received_on','recurring_id','occurrence_month','expense_id','shared_expense_id','person_id','shared_planned_amount','shared_planned_on','invoice_month','closing_on','_totals' ) ) );
        return array( 'kind' => $kind, 'id' => (int) $id, 'description' => $description, 'expected_amount' => $amount, 'expected_on' => $date, 'account_id' => (int) ( $row['account_id'] ?? 0 ), 'pending' => $pending, 'fingerprint' => hash( 'sha256', wp_json_encode( $snapshot ) ), 'row' => $row );
    }
    /** An ordinary realized message with a matching forecast is reviewed instead of duplicated. */
    public static function intercept( $job, $payload, $link, $result ) {
        $kind = $result['intent'] ?? ''; if ( ! in_array( $kind, array( 'expense', 'receipt' ), true ) ) { return null; } $e = $result[ $kind ];
        $actual_date = $e[ 'receipt' === $kind ? 'received_on' : 'spent_on' ] ?? null;
        if ( null !== $actual_date && ( ! PDS_Expenses::valid_date( $actual_date ) || $actual_date > current_time( 'Y-m-d' ) ) || 'expense' === $kind && ( ( $e['installment_mode'] ?? '' ) === 'split' || ( $e['installments'] ?? 1 ) > 1 ) ) { return null; }
        if ( 'receipt' === $kind && ( $e['status'] ?? 'received' ) === 'pending' || ! self::words( $e['description'] ?? '' ) ) { return null; }
        $data = array_fill_keys( array_keys( self::fields() ), null ); $data['uncertain_fields'] = array(); $data['kind'] = 'receipt' === $kind ? 'receipt' : ( preg_match( '/\bfatura\b/iu', $e['description'] ?? '' ) ? 'invoice' : 'payable' );
        foreach ( array( 'description','amount','account_hint','card_hint','payment_method','notes' ) as $key ) { $data[ $key ] = $e[ $key ] ?? null; } $data['paid_on'] = $e[ 'receipt' === $kind ? 'received_on' : 'spent_on' ] ?? null;
        foreach ( $e['uncertain_fields'] ?? array() as $key ) { $mapped = in_array( $key, array( 'spent_on','received_on' ), true ) ? 'paid_on' : $key; if ( array_key_exists( $mapped, $data ) ) { $data['uncertain_fields'][] = $mapped; } }
        if ( ! is_wp_error( PDS_Expenses::amount( $data['amount'] ) ) && ! $data['uncertain_fields'] ) {
            $date = $data['paid_on'] ?: wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() );
            foreach ( self::candidates( $data, true ) as $old ) {
                $real = 'payable' === $old['kind'] && ! empty( $old['row']['expense_id'] ) ? PDS_Expenses::get_entry( $old['row']['expense_id'] ) : $old['row'];
                if ( ! is_wp_error( $real ) && abs( (float) $real['amount'] - (float) $data['amount'] ) < 0.000001 && ( $real['spent_on'] ?? $real['received_on'] ?? '' ) === $date ) { return PDS_Telegram::reply( 'Já encontrei essa previsão baixada com o mesmo valor e data. Nenhum novo lançamento foi criado. Para outra previsão, use /baixar; para um lançamento novo e independente, use /gasto ou /recebimento.' ); }
            }
        }
        if ( ! self::candidates( $data ) ) { return null; }
        return self::handle( $job, $payload, $link, array( 'intent' => 'settlement', 'settlement' => $data ), true );
    }
    public static function handle( $job, $payload, $link, $recognized = null, $review = false ) {
        if ( ! self::live( $job ) ) { return PDS_Telegram::reply( 'Confira o vínculo e o acesso a Meu Financeiro no portal antes de dar baixa.' ); }
        $draft = self::draft( get_current_user_id(), $job['telegram_id'] ); $text = trim( $payload['text'] ?? '' );
        if ( isset( $payload['callback'] ) ) {
            if ( ! preg_match( '/^pds_set_(save|cancel|pick):([a-f0-9]{32})(?::([0-7]))?$/D', $payload['callback'], $m ) || ! $draft || ! hash_equals( $draft['token'], $m[2] ) ) { return PDS_Telegram::reply( 'Esse resumo de baixa expirou ou já foi processado. Confira o portal ou use /pendentes.' ); }
            if ( 'cancel' === $m[1] ) { self::clear(); return PDS_Telegram::reply( 'Baixa descartada. A previsão e os saldos foram preservados.' ); }
            if ( 'pick' === $m[1] ) { if ( ! isset( $m[3], $draft['data']['candidates'][ (int) $m[3] ] ) ) { return PDS_Telegram::reply( 'Escolha uma das previsões mostradas no resumo.' ); } $draft['data']['selected'] = $draft['data']['candidates'][ (int) $m[3] ]; $draft['data']['kind'] = $draft['data']['selected']['kind']; $draft['token'] = bin2hex( random_bytes( 16 ) ); return self::finish( $job, $draft, false ); }
            return self::finish( $job, $draft, true );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) { return $draft ? self::finish( $job, $draft, true ) : PDS_Telegram::reply( 'Não há uma baixa pendente. Use /pendentes ou diga: dê baixa na luz prevista de novembro, paguei 195 reais hoje no Pix pela conta C6 PJ.' ); }
        if ( ! $draft ) { $data = array_fill_keys( array_keys( self::fields() ), null ); $data['paid_on'] = wp_date( 'Y-m-d', (int) ( $payload['date'] ?? time() ), wp_timezone() ); $data['uncertain_fields'] = array(); $data['uncertain'] = array(); $draft = array( 'telegram_id' => $job['telegram_id'], 'source_ref' => $job['event_key'], 'token' => '', 'data' => $data ); }
        if ( '/pendentes' === $text ) { if ( ! empty( $draft['data']['selected'] ) ) { return self::finish( $job, $draft, false ); } PDS_Finance_Planning::sync_user( PDS_Finance_Spaces::id() ); }
        elseif ( ! is_array( $recognized['settlement'] ?? null ) ) { return PDS_Telegram::reply( 'Diga qual previsão foi realizada, o valor real, a data e a conta. Exemplo: recebi o salário previsto de novembro, 2 mil reais hoje na conta C6 PJ.' ); }
        else {
            $entry = $recognized['settlement'];
            foreach ( self::fields() as $key => $_ ) { if ( 'uncertain_fields' !== $key && null !== ( $entry[ $key ] ?? null ) ) { if ( in_array( $key, array( 'kind','description','expected_on','reference_month' ), true ) && ( $draft['data'][ $key ] ?? null ) !== $entry[ $key ] ) { unset( $draft['data']['selected'] ); } $draft['data'][ $key ] = is_string( $entry[ $key ] ) ? sanitize_textarea_field( $entry[ $key ] ) : $entry[ $key ]; unset( $draft['data']['uncertain'][ $key ] ); if ( 'account_hint' === $key ) { unset( $draft['data']['account_id'] ); } }
            }
            foreach ( $entry['uncertain_fields'] ?? array() as $key ) { $draft['data'][ $key ] = null; $draft['data']['uncertain'][ $key ] = true; if ( in_array( $key, array( 'kind','description','expected_on','reference_month' ), true ) ) { unset( $draft['data']['selected'] ); } }
        }
        if ( $review ) { $draft['data']['review'] = true; } $draft['token'] = bin2hex( random_bytes( 16 ) ); return self::finish( $job, $draft, false );
    }
    private static function date( $s ) { return implode( '/', array_reverse( explode( '-', $s ) ) ); }
    private static function money( $n ) { return null === $n ? 'a definir' : 'R$ ' . number_format( (float) $n, 2, ',', '.' ); }
    private static function finish( $job, $draft, $confirmed ) {
        if ( ! self::live( $job ) ) { return PDS_Telegram::reply( 'O vínculo ou o acesso mudou. Confira o portal antes de dar baixa.' ); } $data = $draft['data'];
        foreach ( array( 'expected_on','reference_month','kind','description' ) as $key ) { if ( isset( $data['uncertain'][ $key ] ) || ( ! empty( $data[$key] ) && ( 'expected_on' === $key && ! PDS_Expenses::valid_date( $data[$key] ) || 'reference_month' === $key && ! PDS_Finance::month( $data[$key] ) ) ) ) { $draft['data'] = $data; self::write( $draft ); return PDS_Telegram::reply( 'Confirme a descrição e o mês/data da previsão que deseja baixar. Nenhum lançamento foi alterado.' ); } }
        if ( empty( $data['selected'] ) ) {
            $rows = self::candidates( $data ); $data['candidates'] = array_map( static fn( $r ) => array_intersect_key( $r, array_flip( array( 'kind','id','fingerprint' ) ) ), array_slice( $rows, 0, 8 ) );
            if ( 1 === count( $rows ) ) { $data['selected'] = $data['candidates'][0]; $data['kind'] = $rows[0]['kind']; }
            else {
                $draft['data'] = $data; if ( ! self::write( $draft ) ) { return PDS_Telegram::reply( 'Não consegui guardar a escolha da previsão. Tente novamente.' ); }
                if ( ! $rows ) { return PDS_Telegram::reply( 'Não encontrei uma previsão pendente com esses dados. Confira descrição e mês, ou use /pendentes. Se já foi paga/recebida, não é necessário dar baixa de novo. Nenhum gasto ou recebimento novo foi criado.' ); }
                $buttons = array(); $lines = array(); foreach ( array_slice( $rows, 0, 8 ) as $i => $row ) { $label = ( $i + 1 ) . '. ' . $row['description'] . ' · ' . self::date( $row['expected_on'] ) . ' · ' . self::money( $row['expected_amount'] ); $lines[] = $label; $buttons[] = array( array( 'text' => mb_substr( $label, 0, 100 ), 'callback_data' => 'pds_set_pick:' . $draft['token'] . ':' . $i ) ); } $buttons[] = array( array( 'text' => 'Cancelar', 'callback_data' => 'pds_set_cancel:' . $draft['token'] ) );
                return PDS_Telegram::reply( "Escolha a previsão para dar baixa:\n" . implode( "\n", $lines ) . ( count( $rows ) > 8 ? "\nHá mais resultados. Informe o mês ou uma descrição mais específica para restringir." : '' ) . "\nOs valores acima são previstos. Informe o valor real e a conta ao realizar a baixa.", array( 'inline_keyboard' => $buttons ) );
            }
        }
        $target = self::target( $data['selected']['kind'], $data['selected']['id'] );
        if ( is_wp_error( $target ) || ! $target['pending'] ) { self::clear(); return PDS_Telegram::reply( 'Essa previsão foi excluída, cancelada ou já teve baixa. Confira o portal. Nenhum novo lançamento foi criado.' ); }
        if ( ! hash_equals( $data['selected']['fingerprint'], $target['fingerprint'] ) ) { $data['selected']['fingerprint'] = $target['fingerprint']; $data['review'] = true; $draft['token'] = bin2hex( random_bytes( 16 ) ); $confirmed = false; if ( empty( $data['account_hint'] ) ) { unset( $data['account_id'] ); } }
        $missing = array(); if ( is_wp_error( PDS_Expenses::amount( $data['amount'] ) ) || isset( $data['uncertain']['amount'] ) ) { $missing[] = 'valor efetivamente pago ou recebido em reais'; }
        if ( ! PDS_Expenses::valid_date( $data['paid_on'] ) || $data['paid_on'] > current_time( 'Y-m-d' ) || isset( $data['uncertain']['paid_on'] ) ) { $missing[] = 'data real da baixa, até hoje'; }
        $bank_id = 0;
        if ( 'payable' === $target['kind'] && 'credit' === ( $data['payment_method'] ?? '' ) ) {
            $data['account_id'] = 0;
            list( $resolved, $more ) = PDS_Telegram_Finance::resolve( array( 'payment_method' => 'credit', 'card_hint' => $data['card_hint'], 'card_uncertain' => isset( $data['uncertain']['card_hint'] ), 'installment_mode' => 'single', 'installments' => 1 ) ); $data['card_id'] = $resolved['card_id']; $missing = array_merge( $missing, $more );
        } else {
            $data['card_id'] = 0;
            $bank_id = isset( $data['uncertain']['account_hint'] ) ? 0 : PDS_Telegram_Finance::account_id( $data['account_hint'], $data['account_id'] ?? $target['account_id'] ); $data['account_id'] = $bank_id;
            if ( ! $bank_id ) { $missing[] = 'nome exato da conta ativa que pagou ou recebeu (use /contas)'; }
            if ( 'invoice' === $target['kind'] && 'credit' === ( $data['payment_method'] ?? '' ) ) { $missing[] = 'conta bancária que pagou a fatura; o pagamento da fatura não é uma nova compra no crédito'; }
        }
        if ( 'payable' === $target['kind'] && ( ! is_string( $data['payment_method'] ) || '' === $data['payment_method'] || ! isset( PDS_Expenses::PAYMENTS[ $data['payment_method'] ] ) || isset( $data['uncertain']['payment_method'] ) ) ) { $missing[] = 'forma do pagamento: Pix, débito, dinheiro, transferência ou crédito à vista'; }
        if ( $bank_id && PDS_Expenses::valid_date( $data['paid_on'] ) ) { $bank = PDS_Finance::find( 'accounts', $bank_id ); if ( is_wp_error( $bank ) || $data['paid_on'] < $bank['opening_on'] ) { $missing[] = 'uma data igual ou posterior ao saldo inicial da conta escolhida'; } }
        $draft['data'] = $data; if ( ! self::write( $draft ) ) { return PDS_Telegram::reply( 'Não consegui guardar os dados da baixa. Tente novamente.' ); }
        $summary = $target['description'] . "\nPrevisto: " . self::money( $target['expected_amount'] ) . ' em ' . self::date( $target['expected_on'] );
        if ( $missing ) { return PDS_Telegram::reply( "Previsão localizada:\n" . $summary . "\nPara dar baixa, ainda preciso de:\n• " . implode( "\n• ", $missing ) . "\nResponda só o complemento por texto ou áudio. /cancelar preserva a previsão." ); }
        $summary .= "\nRealizado: " . self::money( $data['amount'] ) . ' em ' . self::date( $data['paid_on'] ) . PDS_Telegram_Finance::summary( $data );
        if ( 'payable' === $target['kind'] ) { $summary .= "\nPagamento: " . PDS_Expenses::PAYMENTS[ $data['payment_method'] ] . "\nSerá baixada somente esta ocorrência."; }
        if ( 'invoice' === $target['kind'] ) { $summary .= "\nPagamento da fatura, inclusive parcial. Não cria outro gasto."; }
        $config = PDS_Telegram_Client::settings(); $link = PDS_Telegram::link( get_current_user_id() );
        if ( ! $confirmed && ( ! empty( $data['review'] ) || ! empty( $config['confirm_before_save'] ) || ! empty( $link['confirm_before_save'] ) ) ) { return PDS_Telegram::reply( "Confira a baixa da previsão:\n" . $summary . "\nA baixa atualiza o lançamento existente.", array( 'inline_keyboard' => array( array( array( 'text' => 'Confirmar baixa', 'callback_data' => 'pds_set_save:' . $draft['token'] ), array( 'text' => 'Cancelar', 'callback_data' => 'pds_set_cancel:' . $draft['token'] ) ) ) ) ); }
        $saved = self::commit( $job, $draft ); if ( is_wp_error( $saved ) ) { return PDS_Telegram::reply( $saved->get_error_message() . ' Confira os dados no portal ou envie a correção; /cancelar descarta esta baixa.' ); } self::clear(); return self::saved_reply( $saved['id'] );
    }
    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }
    public static function commit( $job, $draft ) {
        global $wpdb; if ( ! self::live( $job ) ) { return PDS_Finance::error( 'O vínculo ou acesso mudou.', 403 ); } $uid = PDS_Finance_Spaces::id(); $d = $draft['data'];
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $draft['source_ref'] ) || false === $wpdb->query( 'START TRANSACTION' ) ) { return PDS_Finance::error( 'Não foi possível iniciar a baixa.', 500 ); }
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_settlements WHERE space_id=%d AND source_ref=%s FOR UPDATE", $uid, $draft['source_ref'] ) );
        if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'id' => (int) $existing, 'already_saved' => true ); }
        $t = self::target( $d['selected']['kind'], $d['selected']['id'], true );
        if ( is_wp_error( $t ) || ! $t['pending'] || ! hash_equals( $d['selected']['fingerprint'], $t['fingerprint'] ) ) { return self::rollback( PDS_Finance::error( 'A previsão mudou ou já teve baixa. Atualize a escolha antes de confirmar.', 409 ) ); }
        $bank_id = 'payable' === $t['kind'] && 'credit' === $d['payment_method'] ? 0 : (int) ( $d['account_id'] ?? 0 );
        if ( $bank_id ) { $bank = $wpdb->get_row( $wpdb->prepare( "SELECT id,active,opening_on FROM {$wpdb->prefix}pds_fin_accounts WHERE id=%d AND space_id=%d FOR UPDATE", $bank_id, $uid ), ARRAY_A ); if ( ! $bank || ! $bank['active'] || ! PDS_Expenses::valid_date( $d['paid_on'] ) || $d['paid_on'] < $bank['opening_on'] ) { return self::rollback( PDS_Finance::error( 'Confira a conta ativa e a data da baixa.', 409 ) ); } }
        $r = new WP_REST_Request( 'POST' ); $r->set_url_params( array( 'id' => $t['id'] ) ); $r->set_body_params( array( 'amount' => $d['amount'], 'paid_on' => $d['paid_on'], 'payment_method' => $d['payment_method'], 'account_id' => $bank_id, 'card_id' => $d['card_id'] ?? 0 ) );
        if ( 'payable' === $t['kind'] ) { $saved = PDS_Finance_Planning::pay( $r, true ); }
        elseif ( 'invoice' === $t['kind'] ) { $saved = PDS_Finance::pay_invoice( $r, true ); }
        else { $r->set_method( 'PUT' ); $r->set_body_params( array( 'description' => $t['row']['description'], 'amount' => $d['amount'], 'received_on' => $d['paid_on'], 'account_id' => $bank_id, 'status' => 'received', 'notes' => $t['row']['notes'] ) ); $saved = PDS_Finance::save_receipt( $r, null, true ); }
        if ( is_wp_error( $saved ) ) { return self::rollback( $saved ); } $movement = 'payable' === $t['kind'] ? ( $saved['expense_id'] ?? 0 ) : ( $saved['id'] ?? 0 );
        if ( ! $movement || false === PDS_Finance_Spaces::insert( $wpdb->prefix . 'pds_fin_settlements', array( 'space_id' => $uid, 'source_ref' => $draft['source_ref'], 'target_kind' => $t['kind'], 'target_id' => $t['id'], 'movement_id' => $movement, 'description' => mb_substr( $t['description'], 0, 180 ), 'amount' => PDS_Expenses::amount( $d['amount'] ), 'paid_on' => $d['paid_on'], 'account_id' => $bank_id, 'created_at' => current_time( 'mysql', true ) ) ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível confirmar o registro da baixa. Nenhum movimento foi mantido.', 500 ) ); }
        $id = (int) $wpdb->insert_id;
        if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_updates SET record_id=%d,record_service='baixas' WHERE user_id=%d AND (id=%d OR event_key=%s)", $id, get_current_user_id(), $job['id'], $draft['source_ref'] ) ) || false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível concluir a baixa.', 500 ) ); }
        if ( 'payable' === $t['kind'] ) { do_action( 'pds_expense_created', PDS_Expenses::get_entry( $movement ), get_current_user_id(), $uid ); } return array( 'id' => $id );
    }
    public static function saved_reply( $id ) {
        global $wpdb; if ( ! pds_user_can_access_service( 'gastos' ) || ! PDS_Finance_Spaces::can() ) { return PDS_Telegram::reply( 'Essa mensagem já foi processada. Confira seu acesso a Meu Financeiro.' ); }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_settlements WHERE id=%d AND space_id=%d", $id, PDS_Finance_Spaces::id() ), ARRAY_A );
        if ( ! $row ) { return PDS_Telegram::reply( 'Essa baixa já foi processada. Confira os lançamentos no portal.' ); }
        $label = 'receipt' === $row['target_kind'] ? 'Recebimento previsto baixado!' : ( 'invoice' === $row['target_kind'] ? 'Pagamento da fatura registrado!' : 'Conta prevista paga!' );
        return PDS_Telegram::reply( $label . "\n" . $row['description'] . "\nValor realizado: " . self::money( $row['amount'] ) . "\nData real: " . self::date( $row['paid_on'] ) . "\nBaixa aplicada somente ao lançamento escolhido. Confira os saldos e o histórico em Meu Financeiro." );
    }
}
