<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Telegram_Expenses {
    public static function draft( $uid, $telegram ) {
        global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_expense_drafts WHERE user_id=%d AND telegram_id=%s AND expires_at>%d", $uid, $telegram, time() ), ARRAY_A );
        if ( ! $row ) { return null; } $row['data'] = json_decode( $row['data'], true ); return is_array( $row['data'] ) ? $row : null;
    }
    private static function write( $draft ) {
        global $wpdb; return false !== $wpdb->replace( $wpdb->prefix . 'pds_tg_expense_drafts', array( 'user_id' => get_current_user_id(), 'telegram_id' => $draft['telegram_id'], 'source_ref' => $draft['source_ref'], 'token' => $draft['token'], 'data' => wp_json_encode( $draft['data'] ), 'expires_at' => time() + 2 * HOUR_IN_SECONDS ) );
    }
    private static function category_id( $hint, $previous_id = 0 ) {
        $categories = PDS_Expenses::category_list();
        if ( null === $hint && $previous_id ) { foreach ( $categories as $c ) { if ( $c['id'] === (int) $previous_id ) { return $c['id']; } } return 0; }
        $key = static fn( $s ) => strtolower( preg_replace( '/\s+/u', ' ', trim( remove_accents( $s ) ) ) );
        $hint = $key( $hint ?? 'Outros' );
        foreach ( $categories as $c ) { if ( $key( $c['name'] ) === $hint ) { return $c['id']; } }
        return 0;
    }

    public static function handle( $job, $payload, $link, $recognized = null ) {
        global $wpdb; $allowed = PDS_Expenses::permission(); if ( is_wp_error( $allowed ) ) { return PDS_Telegram::reply( $allowed->get_error_message() ); }
        $text = trim( $payload['text'] ?? '' ); $draft = self::draft( get_current_user_id(), $job['telegram_id'] );
        if ( isset( $payload['callback'] ) ) {
            if ( ! preg_match( '/^pds_exp_(save|cancel):([a-f0-9]{32})$/D', $payload['callback'], $m ) || ! $draft || ! hash_equals( $draft['token'], $m[2] ) ) { return PDS_Telegram::reply( 'Esse resumo de gasto expirou ou já foi processado. Confira o histórico no portal.' ); }
            if ( 'cancel' === $m[1] ) { $wpdb->delete( $wpdb->prefix . 'pds_tg_expense_drafts', array( 'user_id' => get_current_user_id() ) ); return PDS_Telegram::reply( 'Gasto cancelado. Nenhum registro foi criado.' ); }
            return self::finish( $job, $draft, true );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) { return $draft ? self::finish( $job, $draft, true ) : PDS_Telegram::reply( 'Não há um gasto pendente. Pode enviar a descrição e o valor.' ); }
        if ( ! is_array( $recognized ) || 'expense' !== ( $recognized['intent'] ?? '' ) || ! is_array( $recognized['expense'] ?? null ) ) { return PDS_Telegram::reply( 'Envie um gasto por mensagem. Exemplo: gastei 35 reais no almoço hoje, no Pix.' ); }
        $entry = $recognized['expense'];
        if ( ! $draft ) { $draft = array( 'telegram_id' => $job['telegram_id'], 'source_ref' => $job['event_key'], 'token' => '', 'data' => array( 'description' => null, 'spent_on' => wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() ), 'amount' => null, 'category_hint' => null, 'category_id' => 0, 'category_uncertain' => false, 'payment_method' => '', 'merchant' => '', 'notes' => '' ) ); }
        foreach ( array( 'description', 'spent_on', 'amount', 'category_hint', 'merchant', 'notes' ) as $key ) { if ( null !== $entry[ $key ] ) { $draft['data'][ $key ] = is_string( $entry[ $key ] ) ? sanitize_textarea_field( $entry[ $key ] ) : $entry[ $key ]; } }
        $draft['data'] = PDS_Telegram_Finance::merge( $draft['data'], $entry );
        if ( null !== $entry['category_hint'] ) { $draft['data']['category_uncertain'] = false; }
        foreach ( $entry['uncertain_fields'] as $key ) { $draft['data'][ $key ] = null; if ( 'category_hint' === $key ) { $draft['data']['category_id'] = 0; $draft['data']['category_uncertain'] = true; } }
        $draft['data']['category_id'] = ! empty( $draft['data']['category_uncertain'] ) ? 0 : self::category_id( $draft['data']['category_hint'], $draft['data']['category_id'] ); $draft['token'] = bin2hex( random_bytes( 16 ) );
        return self::finish( $job, $draft, false );
    }

    private static function finish( $job, $draft, $confirmed ) {
        global $wpdb; $data = $draft['data']; $missing = array();
        if ( ! is_string( $data['description'] ) || mb_strlen( $data['description'] ) < 2 || mb_strlen( $data['description'] ) > 180 ) { $missing[] = 'com o que você gastou (descrição curta)'; }
        if ( is_wp_error( PDS_Expenses::amount( $data['amount'] ) ) ) { $missing[] = 'valor total do gasto em reais, com até duas casas decimais'; }
        if ( ! PDS_Expenses::valid_date( $data['spent_on'] ) || $data['spent_on'] > current_time( 'Y-m-d' ) ) { $missing[] = 'data válida do gasto realizado (até hoje)'; }
        $category = $data['category_id'] ? PDS_Expenses::category( $data['category_id'] ) : null;
        if ( ! $category || is_wp_error( $category ) || ! $category['active'] ) { $missing[] = 'uma categoria ativa da sua conta (use /categorias ou cadastre a categoria no portal)'; }
        if ( mb_strlen( (string) $data['merchant'] ) > 120 || mb_strlen( (string) $data['notes'] ) > 500 ) { $missing[] = 'estabelecimento/observação mais curtos'; }
        list( $data, $financial_missing ) = PDS_Telegram_Finance::resolve( $data ); $missing = array_merge( $missing, $financial_missing ); $draft['data'] = $data;
        if ( ! self::write( $draft ) ) { return PDS_Telegram::reply( 'Não consegui guardar a pendência do gasto. Tente novamente.' ); }
        if ( $missing ) { return PDS_Telegram::reply( "Para registrar esse gasto, ainda preciso de:\n• " . implode( "\n• ", $missing ) . "\nPode responder só o complemento, por texto ou áudio. Use /cancelar para descartar." ); }
        $config = PDS_Telegram_Client::settings(); $link = PDS_Telegram::link( get_current_user_id() );
        if ( ! $confirmed && ( ! empty( $config['confirm_before_save'] ) || ! empty( $link['confirm_before_save'] ) ) ) { return PDS_Telegram::reply( "Confira o gasto:\n" . self::summary( $data, $category['name'] ), array( 'inline_keyboard' => array( array( array( 'text' => 'Salvar gasto', 'callback_data' => 'pds_exp_save:' . $draft['token'] ), array( 'text' => 'Cancelar', 'callback_data' => 'pds_exp_cancel:' . $draft['token'] ) ) ) ) ); }
        wp_cache_delete( get_current_user_id(), 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $link = PDS_Telegram::link( get_current_user_id() ); $config = PDS_Telegram_Client::settings();
        if ( ! $link || $link['telegram_id'] !== $job['telegram_id'] || ! $config['enabled'] || $link['consent_provider'] !== $config['ai_provider'] || ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'O vínculo ou o acesso a Meus Gastos mudou. Confira sua conta no portal antes de reenviar.' ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_body_params( array_intersect_key( $data, array_fill_keys( array( 'description', 'amount', 'spent_on', 'category_id', 'payment_method', 'merchant', 'notes', 'card_id', 'account_id', 'installments' ), true ) ) );
        $saved = PDS_Expenses::save_entry( $request, 'telegram-expense:' . $draft['source_ref'] );
        if ( is_wp_error( $saved ) ) { return PDS_Telegram::reply( $saved->get_error_message() . ' Envie a correção ou use /cancelar.' ); }
        $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'record_id' => $saved['id'], 'record_service' => 'gastos' ), array( 'id' => $job['id'] ) );
        $wpdb->delete( $wpdb->prefix . 'pds_tg_expense_drafts', array( 'user_id' => get_current_user_id() ) );
        return self::saved_reply( $saved['id'] );
    }

    private static function summary( $data, $category ) {
        return 'Descrição: ' . $data['description'] . "\nValor: R$ " . number_format( (float) $data['amount'], 2, ',', '.' ) . "\nCategoria: " . $category . "\nData: " . implode( '/', array_reverse( explode( '-', $data['spent_on'] ) ) ) . "\nPagamento: " . ( PDS_Expenses::PAYMENTS[ $data['payment_method'] ?? '' ] ?? 'Não informado' ) . PDS_Telegram_Finance::summary( $data ) . ( ! empty( $data['merchant'] ) ? "\nEstabelecimento: " . $data['merchant'] : '' );
    }
    public static function saved_reply( $id ) {
        if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira seu acesso ao serviço no portal.' ); }
        $entry = PDS_Expenses::get_entry( $id );
        return is_wp_error( $entry ) ? PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira o histórico de gastos no portal.' ) : PDS_Telegram::reply( "Gasto registrado!\n" . self::summary( $entry, $entry['category_name'] ) . "\nConfira o dashboard e corrija qualquer dado em Meus Gastos no portal." );
    }
}
