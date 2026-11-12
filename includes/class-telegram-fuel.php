<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Telegram_Fuel {
    private static function draft( $uid, $telegram ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_drafts WHERE user_id=%d AND telegram_id=%s AND expires_at>%d", $uid, $telegram, time() ), ARRAY_A );
        if ( ! $row ) { return null; }
        $row['data'] = json_decode( $row['data'], true );
        return is_array( $row['data'] ) ? $row : null;
    }

    private static function write_draft( $draft ) {
        global $wpdb;
        $result = $wpdb->replace( $wpdb->prefix . 'pds_tg_drafts', array(
            'user_id' => $draft['user_id'], 'telegram_id' => $draft['telegram_id'], 'source_ref' => $draft['source_ref'],
            'token' => $draft['token'], 'data' => wp_json_encode( $draft['data'] ), 'expires_at' => time() + 2 * HOUR_IN_SECONDS,
        ) );
        return false !== $result;
    }

    private static function vehicles() { return PDS_Fuel::vehicles()['vehicles']; }
    private static function normalized_name( $name ) { return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $name ) ) ); }

    private static function choose_vehicle( $data, $link, $vehicles ) {
        if ( ! empty( $data['vehicle_uncertain'] ) ) { return 0; }
        $hint = self::normalized_name( (string) ( $data['vehicle_hint'] ?? '' ) );
        if ( in_array( $hint, array( 'carro', 'meucarro', 'veiculo', 'meuveiculo', 'padrao' ), true ) ) { $hint = ''; }
        if ( $hint ) {
            $exact = array(); $partial = array();
            foreach ( $vehicles as $v ) {
                $name = self::normalized_name( $v['name'] ); $plate = self::normalized_name( $v['plate'] );
                if ( $hint === $name || ( $plate && $hint === $plate ) ) { $exact[] = $v['id']; }
                elseif ( strlen( $hint ) >= 3 && ( strpos( $name, $hint ) !== false || ( $plate && strpos( $plate, $hint ) !== false ) ) ) { $partial[] = $v['id']; }
            }
            return 1 === count( $exact ) ? $exact[0] : ( ! $exact && 1 === count( $partial ) ? $partial[0] : 0 );
        }
        foreach ( array( $data['vehicle_id'] ?? 0, $link['default_vehicle_id'] ?? 0 ) as $id ) {
            if ( ! $id ) { continue; }
            foreach ( $vehicles as $v ) { if ( (int) $v['id'] === (int) $id ) { return (int) $id; } }
            return 0; // A revoked default/draft vehicle must never silently become another vehicle.
        }
        return 1 === count( $vehicles ) ? (int) $vehicles[0]['id'] : 0;
    }

    public static function handle( $job, $payload, $link ) {
        global $wpdb;
        $uid = get_current_user_id(); $telegram = $job['telegram_id'];
        if ( ! pds_user_can_access_service( 'combustivel', $uid ) ) { return PDS_Telegram::reply( 'O serviço Meu Combustível não está liberado para sua conta.' ); }
        $text = trim( $payload['text'] ?? '' ); $draft = self::draft( $uid, $telegram );
        if ( '/cancelar' === $text || '/novo' === $text ) { $wpdb->delete( $wpdb->prefix . 'pds_tg_drafts', array( 'user_id' => $uid ) ); return PDS_Telegram::reply( 'Pendência descartada. Pode enviar um novo abastecimento.' ); }
        if ( isset( $payload['callback'] ) ) {
            if ( ! preg_match( '/^pds_(save|cancel):([a-f0-9]{32})$/D', $payload['callback'], $m ) || ! $draft || ! hash_equals( $draft['token'], $m[2] ) ) { return PDS_Telegram::reply( 'Esse resumo expirou ou já foi processado. Confira o histórico ou envie /confirmar para a pendência atual.' ); }
            if ( 'cancel' === $m[1] ) { $wpdb->delete( $wpdb->prefix . 'pds_tg_drafts', array( 'user_id' => $uid ) ); return PDS_Telegram::reply( 'Abastecimento cancelado. Nenhum registro foi criado.' ); }
            return self::finish( $job, $link, $draft, true );
        }
        $vehicles = self::vehicles();
        if ( '/veiculos' === $text ) {
            if ( ! $vehicles ) { return PDS_Telegram::reply( 'Cadastre seu primeiro veículo no portal antes de registrar por aqui.' ); }
            $lines = array( 'Seus veículos (ID — nome):' );
            foreach ( array_slice( $vehicles, 0, 15 ) as $v ) { $lines[] = $v['id'] . ' — ' . $v['name'] . ( $v['is_owner'] ? '' : ' (compartilhado)' ); }
            $lines[] = 'Envie /veiculo ID para definir o padrão. Você também pode falar o nome ou a placa no áudio.';
            return PDS_Telegram::reply( implode( "\n", $lines ) );
        }
        if ( preg_match( '/^\/veiculo\s+([1-9][0-9]{0,17})$/D', $text, $m ) ) {
            $id = (int) $m[1]; $vehicle = PDS_Fuel::vehicle( $id );
            if ( is_wp_error( $vehicle ) ) { return PDS_Telegram::reply( 'Esse veículo não está disponível para sua conta. Use /veiculos.' ); }
            $wpdb->update( $wpdb->prefix . 'pds_tg_links', array( 'default_vehicle_id' => $id ), array( 'user_id' => $uid ) ); $link['default_vehicle_id'] = $id;
            if ( $draft ) { $draft['data']['vehicle_id'] = $id; $draft['data']['vehicle_hint'] = null; $draft['data']['vehicle_uncertain'] = false; $draft['token'] = bin2hex( random_bytes( 16 ) ); if ( ! self::write_draft( $draft ) ) { return PDS_Telegram::reply( 'Não foi possível atualizar a pendência. Tente novamente.' ); } return self::finish( $job, $link, $draft, false ); }
            return PDS_Telegram::reply( 'Veículo padrão: ' . $vehicle['name'] . '. Pode enviar o abastecimento.' );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) { return $draft ? self::finish( $job, $link, $draft, true ) : PDS_Telegram::reply( 'Não há um abastecimento pendente. Pode enviar um áudio ou texto.' ); }
        if ( strpos( $text, '/' ) === 0 ) { return PDS_Telegram::reply( 'Comando não reconhecido. Use /ajuda.' ); }
        if ( ! $vehicles ) { return PDS_Telegram::reply( 'Cadastre seu primeiro veículo no portal antes de registrar por aqui.' ); }
        $config = PDS_Telegram_Client::settings();
        if ( isset( $payload['audio'] ) && ( $payload['audio']['duration'] <= 0 || $payload['audio']['duration'] > (int) $config['voice_seconds'] || $payload['audio']['size'] > PDS_Telegram_Client::MAX_AUDIO_BYTES ) ) { return PDS_Telegram::reply( 'Envie um áudio de até ' . (int) $config['voice_seconds'] . ' segundos e 5 MB, com um abastecimento por mensagem.' ); }
        if ( ! $text && ! isset( $payload['audio'] ) ) { return PDS_Telegram::reply( 'Envie um áudio ou uma mensagem de texto sobre o abastecimento.' ); }
        if ( ! PDS_Telegram::quota( 'tg-ai', (string) $uid, (int) $config['daily_limit'], DAY_IN_SECONDS ) ) { return PDS_Telegram::reply( 'Você atingiu o limite diário de ' . (int) $config['daily_limit'] . ' mensagens com reconhecimento. Os registros pelo portal continuam disponíveis.' ); }
        if ( isset( $payload['audio'] ) ) { $text = PDS_Telegram_Client::transcribe( $payload['audio']['file_id'] ); if ( is_wp_error( $text ) ) { return PDS_Telegram::reply( $text->get_error_message() ); } }
        $reference_date = wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() );
        $previous = $draft ? $draft['data'] : array();
        $extracted = PDS_Telegram_Client::extract( $text, $reference_date, $previous );
        if ( is_wp_error( $extracted ) ) { return PDS_Telegram::reply( $extracted->get_error_message() ); }
        if ( 'fuel' !== $extracted['intent'] ) { return PDS_Telegram::reply( 'Posso registrar abastecimentos por áudio ou texto. Use /ajuda para ver um exemplo.' ); }
        if ( ! $draft ) {
            $fields = array_fill_keys( array( 'vehicle_hint', 'odometer', 'fuel_type', 'price', 'liters', 'paid', 'full_tank', 'no_discount', 'notes' ), null );
            $fields['filled_on'] = $reference_date; $fields['vehicle_id'] = 0; $fields['vehicle_uncertain'] = false;
            $draft = array( 'user_id' => $uid, 'telegram_id' => $telegram, 'source_ref' => $job['event_key'], 'token' => '', 'data' => $fields );
        }
        foreach ( array( 'vehicle_hint', 'filled_on', 'odometer', 'fuel_type', 'price', 'liters', 'paid', 'full_tank', 'no_discount', 'notes' ) as $key ) {
            if ( null !== $extracted[ $key ] ) {
                $draft['data'][ $key ] = is_string( $extracted[ $key ] ) ? sanitize_textarea_field( $extracted[ $key ] ) : $extracted[ $key ];
                if ( 'vehicle_hint' === $key ) { $draft['data']['vehicle_uncertain'] = false; $draft['data']['vehicle_id'] = 0; }
            }
        }
        foreach ( $extracted['uncertain_fields'] as $key ) {
            $draft['data'][ $key ] = null;
            if ( 'vehicle_hint' === $key ) { $draft['data']['vehicle_uncertain'] = true; $draft['data']['vehicle_id'] = 0; }
        }
        $draft['data']['vehicle_id'] = self::choose_vehicle( $draft['data'], $link, $vehicles );
        $draft['token'] = bin2hex( random_bytes( 16 ) );
        if ( ! self::write_draft( $draft ) ) { return PDS_Telegram::reply( 'Não consegui preparar o abastecimento. Tente novamente.' ); }
        return self::finish( $job, $link, $draft, false );
    }

    private static function missing( &$data ) {
        $missing = array();
        if ( empty( $data['vehicle_id'] ) ) { $missing[] = 'veículo (nome/placa, ou /veiculos e /veiculo ID)'; }
        $date = $data['filled_on'];
        if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $date < '1900-01-01' || $date > current_time( 'Y-m-d' ) ) { $missing[] = 'data válida do abastecimento (até hoje)'; }
        if ( true === $data['no_discount'] && is_numeric( $data['liters'] ) && $data['liters'] > 0 ) {
            if ( null === $data['price'] && is_numeric( $data['paid'] ) && $data['paid'] > 0 ) { $data['price'] = round( $data['paid'] / $data['liters'], 3 ); }
            if ( null === $data['paid'] && is_numeric( $data['price'] ) && $data['price'] > 0 ) { $data['paid'] = round( $data['price'] * $data['liters'], 2 ); }
        }
        $limits = array( 'odometer' => array( 0, 9999999999.99, 'quilometragem total do odômetro' ), 'price' => array( 0.001, 9999999.999, 'preço de tabela por litro' ), 'liters' => array( 0.001, 9999999.999, 'quantidade de litros' ), 'paid' => array( 0.01, 9999999999.99, 'valor total pago' ) );
        foreach ( $limits as $key => $limit ) { if ( null === $data[ $key ] || ! is_numeric( $data[ $key ] ) || ! is_finite( (float) $data[ $key ] ) || $data[ $key ] < $limit[0] || $data[ $key ] > $limit[1] ) { $missing[] = $limit[2]; } }
        if ( ! in_array( $data['fuel_type'], array( 'G', 'A', 'D' ), true ) ) { $missing[] = 'combustível (gasolina, etanol ou diesel)'; }
        if ( ! is_bool( $data['full_tank'] ) ) { $missing[] = 'se completou o tanque ou foi parcial'; }
        if ( true === $data['no_discount'] && is_numeric( $data['price'] ) && is_numeric( $data['liters'] ) && is_numeric( $data['paid'] ) && abs( round( $data['price'] * $data['liters'], 2 ) - $data['paid'] ) > 0.02 ) { $missing[] = 'confirmação do preço ou valor pago (a conta não corresponde a um abastecimento sem desconto)'; }
        if ( mb_strlen( (string) $data['notes'] ) > 500 ) { $missing[] = 'observação com até 500 caracteres'; }
        return $missing;
    }

    private static function summary( $record, $name ) {
        $fuel = array( 'G' => 'Gasolina', 'A' => 'Etanol', 'D' => 'Diesel' )[ $record['fuel_type'] ];
        return $name . "\nData: " . implode( '/', array_reverse( explode( '-', $record['filled_on'] ) ) ) . "\nCombustível: " . $fuel . "\nLitros: " . number_format( $record['liters'], 3, ',', '.' ) . "\nPreço de tabela/L: R$ " . number_format( $record['price'], 3, ',', '.' ) . "\nValor pago: R$ " . number_format( $record['paid'], 2, ',', '.' ) . "\nOdômetro: " . number_format( $record['odometer'], 2, ',', '.' ) . " km\nTanque: " . ( $record['full_tank'] ? 'completo' : 'parcial' );
    }

    private static function finish( $job, $link, $draft, $confirmed ) {
        global $wpdb;
        $uid = get_current_user_id();
        $link = PDS_Telegram::link( $uid );
        wp_cache_delete( $uid, 'user_meta' );
        if ( ! $link || $link['telegram_id'] !== $job['telegram_id'] || ! pds_user_can_access_service( 'combustivel', $uid ) ) {
            if ( ! $link ) { $wpdb->delete( $wpdb->prefix . 'pds_tg_drafts', array( 'user_id' => $uid ) ); }
            return PDS_Telegram::reply( 'O vínculo ou o acesso ao serviço mudou. Confira sua conta no portal antes de reenviar.' );
        }
        $data = $draft['data']; $data['vehicle_id'] = self::choose_vehicle( $data, $link, self::vehicles() );
        $missing = self::missing( $data ); $draft['data'] = $data;
        if ( ! self::write_draft( $draft ) ) { return PDS_Telegram::reply( 'Não foi possível guardar a pendência. Tente novamente.' ); }
        if ( $missing ) { return PDS_Telegram::reply( "Ainda preciso de:\n• " . implode( "\n• ", $missing ) . "\nEnvie só os dados que faltam, por texto ou áudio. Use /cancelar para descartar." ); }
        $vehicle = PDS_Fuel::vehicle( $data['vehicle_id'] );
        if ( is_wp_error( $vehicle ) ) { return PDS_Telegram::reply( 'O veículo não está mais disponível. Use /veiculos para escolher outro.' ); }
        $config = PDS_Telegram_Client::settings();
        if ( ! $confirmed && ( ! empty( $config['confirm_before_save'] ) || ! empty( $link['confirm_before_save'] ) ) ) {
            return PDS_Telegram::reply( "Confira o abastecimento:\n" . self::summary( $data, $vehicle['name'] ), array( 'inline_keyboard' => array( array( array( 'text' => 'Salvar abastecimento', 'callback_data' => 'pds_save:' . $draft['token'] ), array( 'text' => 'Cancelar', 'callback_data' => 'pds_cancel:' . $draft['token'] ) ) ) ) );
        }
        $uid = get_current_user_id();
        wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $fresh_link = PDS_Telegram::link( $uid );
        if ( ! $fresh_link || $fresh_link['telegram_id'] !== $job['telegram_id'] || empty( PDS_Telegram_Client::settings()['enabled'] ) || ! pds_user_can_access_service( 'combustivel', $uid ) ) { return PDS_Telegram::reply( 'O vínculo ou o acesso ao serviço mudou. Confira sua conta no portal antes de reenviar.' ); }
        $request = new WP_REST_Request( 'POST' ); $request->set_url_params( array( 'id' => $data['vehicle_id'] ) ); $request->set_body_params( array_intersect_key( $data, array_fill_keys( array( 'filled_on', 'odometer', 'fuel_type', 'price', 'liters', 'paid', 'full_tank', 'notes' ), true ) ) );
        $result = PDS_Fuel::save_record( $request, 'telegram:' . $draft['source_ref'] );
        if ( is_wp_error( $result ) ) { return PDS_Telegram::reply( $result->get_error_message() . ' Envie a correção por aqui ou use /cancelar.' ); }
        $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'record_id' => $result['id'] ), array( 'id' => $job['id'] ) );
        $wpdb->delete( $wpdb->prefix . 'pds_tg_drafts', array( 'user_id' => $uid ) );
        return self::saved_reply( $result['id'] );
    }

    public static function saved_reply( $record_id ) {
        global $wpdb;
        $record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fuel_records WHERE id=%d", $record_id ), ARRAY_A );
        if ( ! $record || ! pds_user_can_access_service( 'combustivel' ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira o histórico no portal.' ); }
        $vehicle = PDS_Fuel::vehicle( $record['vehicle_id'] );
        if ( is_wp_error( $vehicle ) ) { return PDS_Telegram::reply( 'Esta mensagem já foi processada. Confira seu acesso ao veículo no portal.' ); }
        return PDS_Telegram::reply( "Abastecimento salvo!\n" . self::summary( PDS_Fuel::cast_record( $record ), $vehicle['name'] ) . "\nVocê pode conferir e corrigir pelo histórico do app." );
    }
}
