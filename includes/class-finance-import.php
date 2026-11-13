<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Finance_Import {
    const MAX_ROWS = 300;
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            foreach ( array( 'preview' => 'preview', 'apply' => 'apply', 'cancel' => 'cancel' ) as $path => $method ) { register_rest_route( 'pds/v1', '/finance/import/' . $path, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $method ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ) ); }
        } );
    }
    public static function normalized( $v ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '', remove_accents( $v ) ) ); }
    private static function date( $v ) { if ( ! is_string( $v ) ) { return ''; } $v = trim( $v ); if ( PDS_Expenses::valid_date( $v ) ) { return $v; } if ( preg_match( '#^(\d{2})/(\d{2})/(\d{4})$#D', $v, $m ) ) { $v = $m[3] . '-' . $m[2] . '-' . $m[1]; return PDS_Expenses::valid_date( $v ) ? $v : ''; } if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', $v, $m ) ) { $v = $m[1] . '-' . $m[2] . '-' . $m[3]; return PDS_Expenses::valid_date( $v ) ? $v : ''; } return ''; }
    public static function parse_csv( $bytes ) {
        $bytes = preg_replace( '/^\xEF\xBB\xBF/', '', $bytes ); if ( strpos( $bytes, "\0" ) !== false ) { return PDS_Finance::error( 'CSV inválido. Exporte como CSV UTF-8.' ); }
        if ( ! mb_check_encoding( $bytes, 'UTF-8' ) ) { $bytes = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' ); }
        $stream = fopen( 'php://temp', 'r+' ); fwrite( $stream, $bytes ); rewind( $stream ); $first = fgets( $stream ); rewind( $stream ); $delimiter = ';'; $max = 0;
        foreach ( array( ';', ',', "\t" ) as $candidate ) { $count = count( str_getcsv( $first ?: '', $candidate, '"', '' ) ); if ( $count > $max ) { $delimiter = $candidate; $max = $count; } }
        $headers = fgetcsv( $stream, 0, $delimiter, '"', '' ); if ( ! $headers ) { fclose( $stream ); return PDS_Finance::error( 'CSV vazio.' ); }
        $aliases = array( 'spent_on' => array( 'data', 'date', 'datacompra', 'datadalancamento', 'datadolancamento' ), 'description' => array( 'descricao', 'description', 'title', 'historico', 'estabelecimento', 'lancamento' ), 'amount' => array( 'valor', 'amount', 'valorr', 'valorbrl', 'valordaparcela' ), 'current_installment' => array( 'parcela', 'parcelaatual', 'currentinstallment' ), 'total_installments' => array( 'totalparcelas', 'totaldeparcelas', 'parcelas', 'totalinstallments' ), 'bank_id' => array( 'id', 'fitid', 'identificador' ), 'category_hint' => array( 'categoria', 'category' ) );
        $map = array(); foreach ( $headers as $i => $header ) { $key = self::normalized( $header ); foreach ( $aliases as $field => $names ) { if ( in_array( $key, $names, true ) ) { $map[ $field ] = $i; } } }
        if ( ! isset( $map['spent_on'], $map['description'], $map['amount'] ) ) { fclose( $stream ); return PDS_Finance::error( 'O CSV precisa ter colunas Data, Descrição e Valor. Use o modelo fornecido na tela. Parcela e Total de parcelas são opcionais.' ); }
        $rows = array(); while ( false !== ( $line = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) ) { if ( count( $line ) === 1 && trim( (string) $line[0] ) === '' ) { continue; } $row = array(); foreach ( $map as $field => $index ) { $row[ $field ] = trim( (string) ( $line[ $index ] ?? '' ) ); } $rows[] = $row; if ( count( $rows ) > self::MAX_ROWS ) { fclose( $stream ); return PDS_Finance::error( 'Importe até 300 linhas por arquivo.' ); } } fclose( $stream ); return array( 'rows' => $rows, 'invoice_total' => null );
    }
    public static function parse_ofx( $bytes ) {
        if ( ! mb_check_encoding( $bytes, 'UTF-8' ) ) { $bytes = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' ); }
        preg_match_all( '#<STMTTRN\s*>(.*?)</STMTTRN\s*>#is', $bytes, $matches ); if ( ! $matches[1] || count( $matches[1] ) > self::MAX_ROWS ) { return PDS_Finance::error( 'O OFX precisa conter de 1 a 300 transações de cartão.' ); }
        $tag = static function ( $block, $name ) { return preg_match( '#<' . $name . '\s*>([^<\r\n]*)#i', $block, $m ) ? trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) : ''; };
        $rows = array(); foreach ( $matches[1] as $block ) { $amount = PDS_Finance::cents( $tag( $block, 'TRNAMT' ), true ); if ( is_wp_error( $amount ) ) { return PDS_Finance::error( 'Há um valor inválido no OFX.' ); } $description = trim( $tag( $block, 'NAME' ) . ' ' . $tag( $block, 'MEMO' ) ); $rows[] = array( 'spent_on' => $tag( $block, 'DTPOSTED' ), 'description' => $description, 'amount' => PDS_Finance::decimal( -$amount ), 'bank_id' => $tag( $block, 'FITID' ) ); } return array( 'rows' => $rows, 'invoice_total' => null );
    }
    private static function prepare_rows( $raw, $card, $month ) {
        $out = array(); $occurrences = array(); $projected_parts = 0; $category_map = array(); foreach ( PDS_Expenses::category_list() as $c ) { $category_map[ self::normalized( $c['name'] ) ] = $c['id']; } $default = $category_map['outros'] ?? 0;
        foreach ( $raw as $index => $row ) {
            if ( ! is_array( $row ) ) { return PDS_Finance::error( 'Uma linha da fatura não pôde ser lida.' ); }
            $date = self::date( $row['spent_on'] ?? '' ); $description = is_string( $row['description'] ?? null ) ? mb_substr( sanitize_text_field( $row['description'] ), 0, 180 ) : '';
            $amount = PDS_Finance::cents( $row['amount'] ?? '', true ); $i = $row['current_installment'] ?? 1; $n = $row['total_installments'] ?? 1;
            if ( null === $i || '' === $i ) { $i = 1; } if ( null === $n || '' === $n ) { $n = 1; }
            if ( (int) $n === 1 && preg_match( '/\bparc(?:ela)?\.?\s*(\d{1,3})\s*\/\s*(\d{1,3})\b/iu', $description, $m ) ) { $i = $m[1]; $n = $m[2]; $description = trim( str_replace( $m[0], '', $description ), ' -' ); }
            $errors = array(); if ( ! $date || $date > current_time( 'Y-m-d' ) ) { $errors[] = 'Data da compra inválida/futura'; } if ( mb_strlen( $description ) < 2 ) { $errors[] = 'Descrição ausente'; }
            if ( is_wp_error( $amount ) || ! $amount ) { $errors[] = 'Valor inválido'; $amount = 0; }
            if ( ! is_scalar( $i ) || ! is_scalar( $n ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $i ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $n ) || (int) $i < 1 || (int) $n < (int) $i || (int) $n > 120 ) { $errors[] = 'Parcela/total inválidos'; $i = 1; $n = 1; }
            $kind = preg_match( '/pagamento.*fatura|pagamento\s+recebido|payment.*thank|pagto.*cart[aã]o|pgto.*fatura/iu', $description ) ? 'payment' : ( $amount < 0 ? 'credit' : 'charge' );
            if ( ! empty( $row['kind'] ) && in_array( $row['kind'], array( 'charge', 'credit', 'payment' ), true ) ) { $kind = $row['kind']; }
            if ( ! empty( $row['uncertain'] ) ) { $errors[] = 'IA sinalizou dado incerto: confira e corrija'; }
            $bank_id = is_string( $row['bank_id'] ?? null ) ? trim( $row['bank_id'] ) : ''; $signature = implode( '|', array( $card['id'], $month, $date, self::normalized( $description ), $amount, (int) $i, (int) $n ) ); $occurrences[ $signature ] = ( $occurrences[ $signature ] ?? 0 ) + 1;
            $ref = hash( 'sha256', get_current_user_id() . '|' . $card['id'] . '|' . ( $bank_id ? 'bank:' . $bank_id . '|' . (int) $i . '/' . (int) $n : 'row:' . $signature . '|' . $occurrences[ $signature ] ) );
            $category = $category_map[ self::normalized( is_string( $row['category_hint'] ?? null ) ? $row['category_hint'] : '' ) ] ?? $default;
            $entry = array( 'row' => $index, 'spent_on' => $date, 'description' => $description, 'amount' => $amount / 100, 'current_installment' => (int) $i, 'total_installments' => (int) $n, 'kind' => $kind, 'category_id' => $category, 'bank_ref' => $ref, 'skip' => $kind === 'payment' || (bool) $errors, 'errors' => $errors );
            $status = self::status( $entry, $card['id'], $month ); $entry = array_merge( $entry, $status ); if ( $entry['duplicate'] || $entry['possible_duplicate'] ) { $entry['skip'] = true; }
            if ( $kind === 'charge' ) { $projected_parts += (int) $n - (int) $i + 1; } $out[] = $entry;
        }
        if ( $projected_parts > 5000 ) { return PDS_Finance::error( 'Essa importação geraria mais de 5.000 parcelas. Divida o arquivo.' ); } return $out;
    }
    private static function status( $row, $card_id, $month ) {
        global $wpdb; $uid = get_current_user_id(); $ref = $row['bank_ref'];
        $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_installments WHERE user_id=%d AND bank_ref=%s", $uid, $ref ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE user_id=%d AND external_ref=%s", $uid, 'statement:' . $ref ) ) || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_adjustments WHERE user_id=%d AND external_ref=%s", $uid, $ref ) );
        $candidates = array(); $exact_candidates = array(); if ( ! $duplicate && $row['kind'] === 'charge' ) {
            $found = $wpdb->get_results( $wpdb->prepare( "SELECT s.id,s.confirmed,s.amount,e.description,e.spent_on FROM {$wpdb->prefix}pds_fin_installments s INNER JOIN {$wpdb->prefix}pds_fin_invoices i ON i.id=s.invoice_id INNER JOIN {$wpdb->prefix}pds_expenses e ON e.id=s.expense_id AND e.user_id=s.user_id WHERE s.user_id=%d AND s.card_id=%d AND i.invoice_month=%s AND s.installment_no=%d AND s.installment_total=%d LIMIT 500", $uid, $card_id, $month, $row['current_installment'], $row['total_installments'] ), ARRAY_A );
            foreach ( $found as $candidate ) { $exact = self::normalized( $candidate['description'] ) === self::normalized( $row['description'] ) && $candidate['spent_on'] === $row['spent_on']; if ( $exact || ( $candidate['spent_on'] === $row['spent_on'] && abs( (float) $candidate['amount'] - (float) $row['amount'] ) < 0.005 ) ) { $choice = array( 'id' => (int) $candidate['id'], 'amount' => (float) $candidate['amount'], 'confirmed' => (bool) $candidate['confirmed'], 'description' => $candidate['description'] ); $candidates[] = $choice; if ( $exact ) { $exact_candidates[] = $choice; } } }
        }
        $match = count( $exact_candidates ) === 1 && ! $exact_candidates[0]['confirmed'] ? $exact_candidates[0]['id'] : 0;
        return array( 'duplicate' => (bool) $duplicate, 'match_id' => $match, 'candidates' => $candidates, 'possible_duplicate' => ! $match && (bool) $candidates );
    }
    public static function preview( WP_REST_Request $r ) {
        $card = PDS_Finance::find( 'cards', is_scalar( $r->get_param( 'card_id' ) ) ? absint( $r->get_param( 'card_id' ) ) : 0 ); if ( is_wp_error( $card ) ) { return $card; } if ( ! $card['active'] ) { return PDS_Finance::error( 'Selecione um cartão ativo.' ); } $month = $r->get_param( 'month' ); if ( ! PDS_Finance::month( $month ) ) { return PDS_Finance::error( 'Escolha o mês de vencimento da fatura.' ); }
        $files = $r->get_file_params(); $file = $files['file'] ?? null; if ( ! is_array( $file ) || ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) { return PDS_Finance::error( 'Envie um arquivo CSV, OFX ou PDF válido.' ); }
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ); if ( ! in_array( $extension, array( 'csv', 'ofx', 'pdf' ), true ) || filesize( $file['tmp_name'] ) > ( $extension === 'pdf' ? 8388608 : 3145728 ) ) { return PDS_Finance::error( 'Use CSV/OFX de até 3 MB ou PDF de até 8 MB.' ); }
        $bytes = file_get_contents( $file['tmp_name'] ); if ( ! is_string( $bytes ) || ! $bytes ) { return PDS_Finance::error( 'O arquivo está vazio.' ); }
        if ( 'pdf' === $extension ) {
            if ( (string) $r->get_param( 'accepted_pdf' ) !== '1' ) { return PDS_Finance::error( 'Autorize o envio deste PDF ao Gemini para reconhecimento. CSV e OFX são lidos no próprio servidor.' ); }
            if ( strpos( $bytes, '%PDF-' ) !== 0 ) { return PDS_Finance::error( 'O arquivo não parece um PDF válido.' ); }
            if ( ! PDS_Telegram::quota( 'finance-pdf', (string) get_current_user_id(), 5, DAY_IN_SECONDS ) ) { return PDS_Finance::error( 'Limite de cinco PDFs por conta/dia. Você ainda pode importar CSV/OFX.' ); }
            $parsed = PDS_Telegram_Client::statement( $bytes, $month, array_column( PDS_Expenses::category_list(), 'name' ) );
        } else { $parsed = 'csv' === $extension ? self::parse_csv( $bytes ) : self::parse_ofx( $bytes ); }
        unset( $bytes ); if ( is_wp_error( $parsed ) ) { return $parsed; } return self::stage( $parsed, $card, $month, hash_file( 'sha256', $file['tmp_name'] ) );
    }
    public static function stage( $parsed, $card, $month, $file_hash ) {
        if ( ! is_array( $parsed['rows'] ?? null ) || ! count( $parsed['rows'] ) || count( $parsed['rows'] ) > self::MAX_ROWS ) { return PDS_Finance::error( 'Não foi possível identificar de 1 a 300 linhas. Use CSV/OFX ou um PDF legível sem senha.' ); }
        $rows = self::prepare_rows( $parsed['rows'], $card, $month ); if ( is_wp_error( $rows ) ) { return $rows; } $token = bin2hex( random_bytes( 24 ) ); $hash = hash( 'sha256', $token );
        $job = array( 'user_id' => get_current_user_id(), 'card_id' => (int) $card['id'], 'month' => $month, 'rows' => $rows, 'offset' => 0, 'file_hash' => $file_hash, 'declared_total' => is_numeric( $parsed['invoice_total'] ?? null ) ? (float) $parsed['invoice_total'] : null, 'totals' => array( 'created' => 0, 'matched' => 0, 'credits' => 0, 'skipped' => 0 ), 'errors' => array() );
        $old = get_user_meta( get_current_user_id(), 'pds_fin_import_jobs', true ); $old = is_array( $old ) ? $old : array(); while ( count( $old ) >= 5 ) { delete_transient( 'pds_fin_preview_' . array_shift( $old ) ); } $old[] = $hash; update_user_meta( get_current_user_id(), 'pds_fin_import_jobs', $old ); set_transient( 'pds_fin_preview_' . $hash, $job, 30 * MINUTE_IN_SECONDS );
        $public = $rows; foreach ( $public as &$row ) { unset( $row['bank_ref'] ); } unset( $row ); return array( 'token' => $token, 'rows' => $public, 'month' => $month, 'declared_total' => $job['declared_total'], 'expires_in' => 1800 );
    }
    private static function job( $token ) { if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{48}$/D', $token ) ) { return PDS_Finance::error( 'Prévia inválida ou expirada.', 404 ); } $hash = hash( 'sha256', $token ); $job = get_transient( 'pds_fin_preview_' . $hash ); return ! is_array( $job ) || (int) $job['user_id'] !== get_current_user_id() ? PDS_Finance::error( 'Prévia inválida ou expirada.', 404 ) : array( $hash, $job ); }
    public static function cancel( WP_REST_Request $r ) { $found = self::job( $r->get_param( 'token' ) ); if ( is_wp_error( $found ) ) { return $found; } delete_transient( 'pds_fin_preview_' . $found[0] ); return array( 'success' => true ); }
    public static function apply( WP_REST_Request $r ) {
        global $wpdb; $found = self::job( $r->get_param( 'token' ) ); if ( is_wp_error( $found ) ) { return $found; } list( $hash, $job ) = $found; $lock = 'pds_fin_import_lock_' . $hash;
        $expires = get_option( $lock ); if ( is_numeric( $expires ) && (int) $expires < time() ) { delete_option( $lock ); }
        if ( ! add_option( $lock, time() + 180, '', false ) ) { return PDS_Finance::error( 'A importação já está em processamento. Aguarde e tente continuar.', 409 ); }
        try {
            $fresh = self::job( $r->get_param( 'token' ) ); if ( is_wp_error( $fresh ) ) { return $fresh; } $job = $fresh[1];
            if ( ! empty( $job['done'] ) ) { return array( 'done' => true, 'processed' => $job['offset'], 'totals' => $job['totals'], 'errors' => $job['errors'] ); }
            $card = PDS_Finance::find( 'cards', $job['card_id'] ); if ( is_wp_error( $card ) ) { return $card; }
            if ( ! $card['active'] ) { return PDS_Finance::error( 'O cartão foi arquivado. Crie outra prévia com um cartão ativo.' ); }
            if ( ! $job['offset'] && is_array( $r->get_param( 'edits' ) ) ) { $edits = $r->get_param( 'edits' ); if ( count( $edits ) > self::MAX_ROWS ) { return PDS_Finance::error( 'Prévia muito grande.' ); } foreach ( $edits as $index => $edit ) { if ( ! isset( $job['rows'][ $index ] ) || ! is_array( $edit ) ) { return PDS_Finance::error( 'Linha de prévia inválida.' ); } foreach ( array( 'spent_on', 'description', 'amount', 'current_installment', 'total_installments', 'category_id', 'match_id', 'skip' ) as $key ) { if ( array_key_exists( $key, $edit ) ) { $job['rows'][ $index ][ $key ] = $edit[ $key ]; } } } }
            $projected = 0; foreach ( $job['rows'] as $row ) { if ( ! is_bool( $row['skip'] ) ) { return PDS_Finance::error( 'Selecione as linhas com um estado válido.' ); } if ( ! $row['skip'] && $row['kind'] === 'charge' ) { if ( ! is_scalar( $row['current_installment'] ) || ! is_scalar( $row['total_installments'] ) ) { return PDS_Finance::error( 'Confira as parcelas na prévia.' ); } $projected += max( 1, (int) $row['total_installments'] - (int) $row['current_installment'] + 1 ); } } if ( $projected > 5000 ) { return PDS_Finance::error( 'Divida a importação em até 5.000 parcelas por arquivo.' ); }
            $end = min( count( $job['rows'] ), $job['offset'] + 20 );
            for ( $i = $job['offset']; $i < $end; $i++ ) { $row = $job['rows'][ $i ]; $result = self::apply_row( $row, $card, $job['month'] ); if ( is_wp_error( $result ) ) { $job['errors'][] = array( 'row' => $i, 'message' => $result->get_error_message() ); } else { $job['totals'][ $result ]++; } $job['offset'] = $i + 1; }
            $done = $job['offset'] >= count( $job['rows'] ); if ( $done ) { unset( $job['rows'] ); $job['done'] = true; } set_transient( 'pds_fin_preview_' . $hash, $job, 30 * MINUTE_IN_SECONDS ); return array( 'done' => $done, 'processed' => $job['offset'], 'totals' => $job['totals'], 'errors' => $job['errors'] );
        } finally { delete_option( $lock ); }
    }
    public static function apply_row( $row, $card, $month ) {
        global $wpdb; if ( ! empty( $row['skip'] ) || $row['kind'] === 'payment' ) { return 'skipped'; }
        if ( self::status( $row, $card['id'], $month )['duplicate'] ) { return 'skipped'; }
        $description = is_string( $row['description'] ) ? sanitize_text_field( trim( $row['description'] ) ) : ''; $date = self::date( $row['spent_on'] ); $cents = PDS_Finance::cents( $row['amount'], true );
        if ( mb_strlen( $description ) < 2 || mb_strlen( $description ) > 180 || ! $date || $date > current_time( 'Y-m-d' ) || is_wp_error( $cents ) || ! $cents ) { return PDS_Finance::error( 'Corrija descrição, data e valor dessa linha.' ); }
        $invoice = PDS_Finance::ensure_invoice( $card, $month ); if ( is_wp_error( $invoice ) ) { return $invoice; }
        if ( $row['kind'] === 'credit' ) { if ( $cents > 0 ) { return PDS_Finance::error( 'Créditos/estornos devem ter valor negativo.' ); } $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_adjustments', array( 'user_id' => get_current_user_id(), 'invoice_id' => $invoice, 'description' => $description, 'amount' => PDS_Finance::decimal( $cents ), 'external_ref' => $row['bank_ref'], 'created_at' => current_time( 'mysql', true ) ) ); return false === $ok ? PDS_Finance::error( 'Não foi possível registrar o crédito. Confira se já foi importado.', 409 ) : 'credits'; }
        if ( $cents < 1 ) { return PDS_Finance::error( 'O gasto precisa ter valor positivo.' ); }
        $i = $row['current_installment']; $n = $row['total_installments']; if ( ! is_scalar( $i ) || ! is_scalar( $n ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $i ) || ! preg_match( '/^[0-9]{1,3}$/D', (string) $n ) || (int) $i < 1 || (int) $n < (int) $i || (int) $n > 120 ) { return PDS_Finance::error( 'Confira a parcela atual e o total, de 1 a 120.' ); }
        $category = PDS_Expenses::category( is_scalar( $row['category_id'] ) ? absint( $row['category_id'] ) : 0 ); if ( is_wp_error( $category ) ) { return $category; } if ( ! $category['active'] ) { return PDS_Finance::error( 'Escolha uma categoria ativa.' ); }
        if ( ! empty( $row['match_id'] ) ) {
            if ( ! is_scalar( $row['match_id'] ) ) { return PDS_Finance::error( 'Parcela de conferência inválida.' ); }
            $expense_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT expense_id FROM {$wpdb->prefix}pds_fin_installments WHERE id=%d AND user_id=%d", $row['match_id'], get_current_user_id() ) );
            $wpdb->query( 'START TRANSACTION' ); $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d FOR UPDATE", $expense_id, get_current_user_id() ) ); $s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_installments WHERE id=%d AND user_id=%d AND card_id=%d AND invoice_id=%d FOR UPDATE", $row['match_id'], get_current_user_id(), $card['id'], $invoice ), ARRAY_A );
            if ( ! $s || (int) $s['installment_no'] !== (int) $i || (int) $s['installment_total'] !== (int) $n || ( $s['bank_ref'] && $s['bank_ref'] !== $row['bank_ref'] ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'A parcela escolhida não corresponde a esta fatura ou já foi conferida.' ); }
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_invoices WHERE id=%d FOR UPDATE", $invoice ) );
            if ( abs( (float) $s['amount'] - $cents / 100 ) > 0.000001 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_bill_payments WHERE invoice_id=%d LIMIT 1", $invoice ) ) ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'A fatura tem pagamento registrado. Corrija/remova o pagamento antes de alterar o valor da parcela.' ); }
            $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_installments', array( 'amount' => PDS_Finance::decimal( $cents ), 'confirmed' => 1, 'bank_ref' => $row['bank_ref'] ), array( 'id' => $s['id'], 'user_id' => get_current_user_id() ) );
            if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return PDS_Finance::error( 'Não foi possível conferir essa parcela.', 500 ); }
            $wpdb->update( $wpdb->prefix . 'pds_expenses', array( 'category_id' => $category['id'] ), array( 'id' => $s['expense_id'], 'user_id' => get_current_user_id() ) );
            $e = $wpdb->get_row( $wpdb->prepare( "SELECT id,source FROM {$wpdb->prefix}pds_expenses WHERE id=%d AND user_id=%d", $s['expense_id'], get_current_user_id() ), ARRAY_A ); if ( $e && $e['source'] === 'statement' ) { $sum = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$wpdb->prefix}pds_fin_installments WHERE expense_id=%d", $e['id'] ) ); $wpdb->update( $wpdb->prefix . 'pds_expenses', array( 'amount' => $sum ), array( 'id' => $e['id'] ) ); }
            $shared = PDS_Finance_Sharing::sync( $s['expense_id'], get_current_user_id() ); if ( is_wp_error( $shared ) ) { $wpdb->query( 'ROLLBACK' ); return $shared; }
            $wpdb->query( 'COMMIT' ); return 'matched';
        }
        $remaining = (int) $n - (int) $i + 1; $total = $cents * $remaining;
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( array( 'description' => $description, 'spent_on' => $date, 'amount' => PDS_Finance::decimal( $total ), 'category_id' => $category['id'], 'payment_method' => 'credit', 'card_id' => $card['id'], 'installments' => (int) $n, 'first_invoice' => $month, 'notes' => 'Importado da fatura ' . $month . '. Parcela ' . (int) $i . '/' . (int) $n . '. Parcelas seguintes projetadas com o valor informado; parcelas anteriores não são recriadas.' ) );
        $saved = PDS_Expenses::save_entry( $request, 'statement:' . $row['bank_ref'], array( 'installment_start' => (int) $i, 'installment_value' => PDS_Finance::decimal( $cents ), 'bank_ref' => $row['bank_ref'] ) ); return is_wp_error( $saved ) ? $saved : 'created';
    }
}
