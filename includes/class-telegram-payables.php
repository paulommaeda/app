<?php
defined( 'ABSPATH' ) || exit;

/** Telegram registers pending obligations; it never pays a bill because its due date arrived. */
final class PDS_Telegram_Payables {
    public static function draft( $uid, $telegram ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_payable_drafts WHERE user_id=%d AND telegram_id=%s AND expires_at>%d", $uid, $telegram, time() ), ARRAY_A );
        if ( ! $row ) { return null; } $row['data'] = json_decode( $row['data'], true ); return is_array( $row['data'] ) ? $row : null;
    }
    private static function write( $draft ) {
        global $wpdb; return false !== $wpdb->replace( $wpdb->prefix . 'pds_tg_payable_drafts', array( 'user_id' => get_current_user_id(), 'telegram_id' => $draft['telegram_id'], 'source_ref' => $draft['source_ref'], 'token' => $draft['token'], 'data' => wp_json_encode( $draft['data'] ), 'expires_at' => time() + 2 * HOUR_IN_SECONDS ) );
    }
    private static function category( $hint ) {
        $hint = $hint ?? 'Contas e serviços';
        foreach ( PDS_Expenses::category_list() as $c ) { if ( PDS_Finance_Import::normalized( $c['name'] ) === PDS_Finance_Import::normalized( $hint ) ) { return $c['id']; } }
        return 0;
    }
    public static function handle( $job, $payload, $link, $recognized = null ) {
        global $wpdb; $allowed = PDS_Expenses::permission(); if ( is_wp_error( $allowed ) ) { return PDS_Telegram::reply( $allowed->get_error_message() ); }
        $draft = self::draft( get_current_user_id(), $job['telegram_id'] ); $text = trim( $payload['text'] ?? '' );
        if ( isset( $payload['callback'] ) ) {
            if ( ! preg_match( '/^pds_pay_(save|cancel):([a-f0-9]{32})$/D', $payload['callback'], $m ) || ! $draft || ! hash_equals( $draft['token'], $m[2] ) ) { return PDS_Telegram::reply( 'Esse resumo de conta expirou ou já foi processado. Confira Contas a pagar no portal.' ); }
            if ( 'cancel' === $m[1] ) { $wpdb->delete( $wpdb->prefix . 'pds_tg_payable_drafts', array( 'user_id' => get_current_user_id() ) ); return PDS_Telegram::reply( 'Cadastro da conta cancelado. Nenhuma previsão foi criada.' ); }
            return self::finish( $job, $draft, true );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) { return $draft ? self::finish( $job, $draft, true ) : PDS_Telegram::reply( 'Não há uma conta pendente de cadastro. Exemplo: cadastre conta de luz de 200 reais vencendo dia 20, todo mês.' ); }
        if ( ! is_array( $recognized['payable'] ?? null ) || 'payable' !== ( $recognized['intent'] ?? '' ) ) { return PDS_Telegram::reply( 'Envie uma conta prevista por mensagem, com descrição e vencimento. Isso não efetua pagamento.' ); }
        if ( ! $draft ) { $draft = array( 'telegram_id' => $job['telegram_id'], 'source_ref' => $job['event_key'], 'token' => '', 'data' => array( 'description' => null, 'amount' => null, 'amount_unknown' => true, 'due_on' => null, 'category_hint' => null, 'account_hint' => null, 'account_id' => 0, 'recurrence' => 'once', 'occurrences' => null, 'billing_day' => null, 'notes' => '', 'uncertain' => array() ) ); }
        $entry = $recognized['payable'];
        foreach ( array( 'description', 'amount', 'amount_unknown', 'due_on', 'category_hint', 'account_hint', 'recurrence', 'occurrences', 'billing_day', 'notes' ) as $key ) {
            if ( null !== $entry[ $key ] ) { $draft['data'][ $key ] = is_string( $entry[ $key ] ) ? sanitize_textarea_field( $entry[ $key ] ) : $entry[ $key ]; unset( $draft['data']['uncertain'][ $key ] ); if ( 'account_hint' === $key ) { $draft['data']['account_id'] = 0; } }
        }
        if ( true === $entry['amount_unknown'] ) { $draft['data']['amount'] = null; unset( $draft['data']['uncertain']['amount'] ); }
        foreach ( $entry['uncertain_fields'] as $key ) { $draft['data'][ $key ] = null; $draft['data']['uncertain'][ $key ] = true; if ( 'account_hint' === $key ) { $draft['data']['account_id'] = 0; } }
        $draft['token'] = bin2hex( random_bytes( 16 ) ); return self::finish( $job, $draft, false );
    }
    private static function finish( $job, $draft, $confirmed ) {
        global $wpdb; $uid = get_current_user_id(); wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $link = PDS_Telegram::link( $uid ); $config = PDS_Telegram_Client::settings();
        if ( ! $link || $link['telegram_id'] !== $job['telegram_id'] || ! $config['enabled'] || $link['consent_provider'] !== $config['ai_provider'] || ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'O vínculo ou o acesso a Meu Financeiro mudou. Confira o portal antes de reenviar.' ); }
        $data = $draft['data']; $missing = array();
        if ( ! is_string( $data['description'] ) || mb_strlen( trim( $data['description'] ) ) < 2 || mb_strlen( $data['description'] ) > 180 ) { $missing[] = 'descrição curta da conta'; }
        if ( ! PDS_Expenses::valid_date( $data['due_on'] ) ) { $missing[] = 'data do primeiro vencimento'; }
        if ( ( null !== $data['amount'] && is_wp_error( PDS_Expenses::amount( $data['amount'] ) ) ) || isset( $data['uncertain']['amount'] ) ) { $missing[] = 'valor previsto em reais, ou diga valor a definir'; }
        $data['category_id'] = isset( $data['uncertain']['category_hint'] ) ? 0 : self::category( $data['category_hint'] );
        if ( ! $data['category_id'] ) { $missing[] = 'uma categoria ativa da sua conta (use /categorias)'; }
        if ( isset( $data['uncertain']['account_hint'] ) ) { $missing[] = 'o nome da conta bancária prevista para pagamento, se desejar vinculá-la'; }
        elseif ( $data['account_hint'] ) { $data['account_id'] = PDS_Telegram_Finance::account_id( $data['account_hint'], $data['account_id'] ); if ( ! $data['account_id'] ) { $missing[] = 'o nome de uma conta bancária ativa cadastrada por você (use /contas)'; } }
        if ( ! is_string( $data['notes'] ) || mb_strlen( $data['notes'] ) > 500 ) { $missing[] = 'observação de até 500 caracteres'; }
        $r = new WP_REST_Request( 'POST' ); $r->set_body_params( array_intersect_key( $data, array_flip( array( 'recurrence', 'occurrences', 'billing_day' ) ) ) );
        $repeat = PDS_Finance_Planning::recurrence( $r, is_string( $data['due_on'] ) ? $data['due_on'] : '' );
        if ( is_wp_error( $repeat ) || array_intersect( array_keys( $data['uncertain'] ), array( 'recurrence', 'occurrences', 'billing_day' ) ) ) { $missing[] = 'recorrência: uma vez ou mensal, com dia e quantidade de meses quando informada'; }
        $draft['data'] = $data; if ( ! self::write( $draft ) ) { return PDS_Telegram::reply( 'Não consegui guardar a pendência da conta. Tente novamente.' ); }
        if ( $missing ) { return PDS_Telegram::reply( "Para cadastrar essa conta a pagar, ainda preciso de:\n• " . implode( "\n• ", $missing ) . "\nPode responder só o complemento por texto ou áudio. Use /cancelar para descartar." ); }
        if ( ! $confirmed && ( ! empty( $config['confirm_before_save'] ) || ! empty( $link['confirm_before_save'] ) ) ) { return PDS_Telegram::reply( "Confira a conta prevista:\n" . self::summary( $data ), array( 'inline_keyboard' => array( array( array( 'text' => 'Cadastrar previsão', 'callback_data' => 'pds_pay_save:' . $draft['token'] ), array( 'text' => 'Cancelar', 'callback_data' => 'pds_pay_cancel:' . $draft['token'] ) ) ) ) ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( array_intersect_key( $data, array_flip( array( 'description', 'amount', 'due_on', 'category_id', 'account_id', 'recurrence', 'occurrences', 'billing_day', 'notes' ) ) ) );
        $saved = PDS_Finance_Planning::save_payable( $request, 'telegram-payable:' . $draft['source_ref'] );
        if ( is_wp_error( $saved ) ) { return PDS_Telegram::reply( $saved->get_error_message() . ' Envie a correção ou use /cancelar.' ); }
        $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'record_id' => $saved['id'], 'record_service' => 'contas_pagar' ), array( 'id' => $job['id'] ) );
        $wpdb->delete( $wpdb->prefix . 'pds_tg_payable_drafts', array( 'user_id' => $uid ) ); return self::saved_reply( $saved['id'] );
    }
    private static function summary( $data ) {
        return $data['description'] . "\nValor previsto: " . ( null === $data['amount'] ? 'a definir' : 'R$ ' . number_format( (float) $data['amount'], 2, ',', '.' ) ) . "\nPrimeiro vencimento: " . implode( '/', array_reverse( explode( '-', $data['due_on'] ) ) ) . ( ( $data['recurrence'] ?? '' ) === 'monthly' || ! empty( $data['recurring_id'] ) ? "\nRecorrência: mensal" . ( ! empty( $data['occurrences'] ) ? ' por ' . $data['occurrences'] . ' meses' : '' ) : "\nLançamento único" ) . "\nBaixa: somente quando você confirmar o pagamento no portal.";
    }
    public static function saved_reply( $id ) {
        if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira seu acesso a Meu Financeiro.' ); }
        $row = PDS_Finance_Planning::get_payable( $id );
        if ( is_wp_error( $row ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira Contas a pagar no portal.' ); }
        return PDS_Telegram::reply( "Conta a pagar cadastrada!\n" . self::summary( $row ) . "\nConfira em Meu Financeiro → Contas a pagar. O cadastro não altera o saldo." );
    }
}
