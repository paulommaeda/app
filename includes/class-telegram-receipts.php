<?php
defined( 'ABSPATH' ) || exit;

/** Incoming funds use the existing financial ledger and the linked native WordPress account. */
final class PDS_Telegram_Receipts {
    public static function draft( $uid, $telegram ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_receipt_drafts WHERE user_id=%d AND telegram_id=%s AND expires_at>%d", $uid, $telegram, time() ), ARRAY_A );
        if ( ! $row ) { return null; }
        $row['data'] = json_decode( $row['data'], true );
        return is_array( $row['data'] ) ? $row : null;
    }

    private static function write( $draft ) {
        global $wpdb;
        return false !== $wpdb->replace( $wpdb->prefix . 'pds_tg_receipt_drafts', array(
            'user_id' => get_current_user_id(), 'telegram_id' => $draft['telegram_id'],
            'source_ref' => $draft['source_ref'], 'token' => $draft['token'],
            'data' => wp_json_encode( $draft['data'] ), 'expires_at' => time() + 2 * HOUR_IN_SECONDS,
        ) );
    }

    public static function handle( $job, $payload, $link, $recognized = null ) {
        global $wpdb;
        $allowed = PDS_Expenses::permission();
        if ( is_wp_error( $allowed ) ) { return PDS_Telegram::reply( $allowed->get_error_message() ); }
        $text = trim( $payload['text'] ?? '' );
        $draft = self::draft( get_current_user_id(), $job['telegram_id'] );
        if ( isset( $payload['callback'] ) ) {
            if ( ! preg_match( '/^pds_rec_(save|cancel):([a-f0-9]{32})$/D', $payload['callback'], $m ) || ! $draft || ! hash_equals( $draft['token'], $m[2] ) ) {
                return PDS_Telegram::reply( 'Esse resumo de recebimento expirou ou já foi processado. Confira Recebimentos no portal.' );
            }
            if ( 'cancel' === $m[1] ) {
                $wpdb->delete( $wpdb->prefix . 'pds_tg_receipt_drafts', array( 'user_id' => get_current_user_id() ) );
                return PDS_Telegram::reply( 'Recebimento cancelado. Nenhum registro foi criado.' );
            }
            return self::finish( $job, $draft, true );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) {
            return $draft ? self::finish( $job, $draft, true ) : PDS_Telegram::reply( 'Não há um recebimento pendente. Exemplo: recebi 100 reais na conta C6 pessoa jurídica.' );
        }
        if ( ! is_array( $recognized ) || 'receipt' !== ( $recognized['intent'] ?? '' ) || ! is_array( $recognized['receipt'] ?? null ) ) {
            return PDS_Telegram::reply( 'Envie um recebimento por mensagem. Exemplo: recebi 100 reais na conta C6 pessoa jurídica.' );
        }
        if ( ! $draft ) {
            $draft = array( 'telegram_id' => $job['telegram_id'], 'source_ref' => $job['event_key'], 'token' => '', 'data' => array(
                'description' => 'Recebimento', 'amount' => null,
                'received_on' => wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() ),
                'account_hint' => null, 'account_id' => 0, 'account_uncertain' => false, 'notes' => '',
            ) );
        }
        $entry = $recognized['receipt'];
        foreach ( array( 'description', 'amount', 'received_on', 'account_hint', 'notes' ) as $key ) {
            if ( null !== $entry[ $key ] ) {
                $draft['data'][ $key ] = is_string( $entry[ $key ] ) ? sanitize_textarea_field( $entry[ $key ] ) : $entry[ $key ];
                if ( 'account_hint' === $key ) { $draft['data']['account_id'] = 0; $draft['data']['account_uncertain'] = false; }
            }
        }
        foreach ( $entry['uncertain_fields'] as $key ) {
            $draft['data'][ $key ] = null;
            if ( 'account_hint' === $key ) { $draft['data']['account_id'] = 0; $draft['data']['account_uncertain'] = true; }
        }
        $draft['token'] = bin2hex( random_bytes( 16 ) );
        return self::finish( $job, $draft, false );
    }

    private static function finish( $job, $draft, $confirmed ) {
        global $wpdb;
        $uid = get_current_user_id();
        wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $link = PDS_Telegram::link( $uid ); $config = PDS_Telegram_Client::settings();
        if ( ! $link || $link['telegram_id'] !== $job['telegram_id'] || ! $config['enabled'] || $link['consent_provider'] !== $config['ai_provider'] || ! pds_user_can_access_service( 'gastos' ) ) {
            return PDS_Telegram::reply( 'O vínculo ou o acesso a Meu Financeiro mudou. Confira sua conta no portal antes de reenviar.' );
        }
        $data = $draft['data']; $missing = array();
        if ( ! is_string( $data['description'] ) || mb_strlen( trim( $data['description'] ) ) < 2 || mb_strlen( $data['description'] ) > 180 ) { $missing[] = 'uma descrição curta do recebimento'; }
        if ( is_wp_error( PDS_Expenses::amount( $data['amount'] ) ) ) { $missing[] = 'valor recebido em reais, com até duas casas decimais'; }
        if ( ! PDS_Expenses::valid_date( $data['received_on'] ) || $data['received_on'] > current_time( 'Y-m-d' ) ) { $missing[] = 'data válida do recebimento realizado, até hoje (previsões podem ser cadastradas como A receber no portal)'; }
        if ( ! is_string( $data['notes'] ) || mb_strlen( $data['notes'] ) > 500 ) { $missing[] = 'observação de até 500 caracteres'; }
        $data['account_id'] = ! empty( $data['account_uncertain'] ) ? 0 : PDS_Telegram_Finance::account_id( $data['account_hint'], $data['account_id'] );
        if ( ! $data['account_id'] ) {
            $names = array_column( array_filter( PDS_Finance::accounts()['accounts'], static fn( $a ) => $a['active'] ), 'name' );
            $missing[] = $names ? 'qual conta recebeu: ' . implode( ', ', array_slice( $names, 0, 10 ) ) . '. Use /contas para consultar' : 'cadastrar uma conta em Meu Financeiro → Contas no portal e depois informar seu nome aqui';
        }
        $draft['data'] = $data;
        if ( ! self::write( $draft ) ) { return PDS_Telegram::reply( 'Não consegui guardar a pendência do recebimento. Tente novamente.' ); }
        if ( $missing ) { return PDS_Telegram::reply( "Para registrar esse recebimento, ainda preciso de:\n• " . implode( "\n• ", $missing ) . "\nPode responder só o complemento, por texto ou áudio. Use /cancelar para descartar." ); }
        $account = PDS_Finance::find( 'accounts', $data['account_id'] );
        if ( is_wp_error( $account ) ) { return PDS_Telegram::reply( 'Essa conta não está mais disponível. Confira suas contas no portal.' ); }
        if ( ! $confirmed && ( ! empty( $config['confirm_before_save'] ) || ! empty( $link['confirm_before_save'] ) ) ) {
            return PDS_Telegram::reply( "Confira o recebimento:\n" . self::summary( $data, $account['name'] ), array( 'inline_keyboard' => array( array(
                array( 'text' => 'Salvar recebimento', 'callback_data' => 'pds_rec_save:' . $draft['token'] ),
                array( 'text' => 'Cancelar', 'callback_data' => 'pds_rec_cancel:' . $draft['token'] ),
            ) ) ) );
        }
        $request = new WP_REST_Request( 'POST' );
        $request->set_body_params( array_intersect_key( $data, array_fill_keys( array( 'description', 'amount', 'received_on', 'account_id', 'notes' ), true ) ) + array( 'status' => 'received' ) );
        $saved = PDS_Finance::save_receipt( $request, 'telegram-receipt:' . $draft['source_ref'] );
        if ( is_wp_error( $saved ) ) { return PDS_Telegram::reply( $saved->get_error_message() . ' Envie a correção ou use /cancelar.' ); }
        $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'record_id' => $saved['id'], 'record_service' => 'recebimentos' ), array( 'id' => $job['id'] ) );
        $wpdb->delete( $wpdb->prefix . 'pds_tg_receipt_drafts', array( 'user_id' => $uid ) );
        return self::saved_reply( $saved['id'] );
    }

    private static function summary( $data, $account_name ) {
        return 'Descrição: ' . $data['description'] . "\nValor: R$ " . number_format( (float) $data['amount'], 2, ',', '.' ) . "\nConta: " . $account_name . "\nData: " . implode( '/', array_reverse( explode( '-', $data['received_on'] ) ) );
    }

    public static function saved_reply( $id ) {
        if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira seu acesso a Meu Financeiro no portal.' ); }
        $row = PDS_Finance::find( 'receipts', $id );
        if ( is_wp_error( $row ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira Recebimentos no portal.' ); }
        $account = PDS_Finance::find( 'accounts', $row['account_id'] );
        if ( is_wp_error( $account ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira Recebimentos no portal.' ); }
        return PDS_Telegram::reply( "Recebimento registrado!\n" . self::summary( $row, $account['name'] ) . "\nConfira em Meu Financeiro → Recebimentos. O saldo considera a data inicial da conta." );
    }
}
