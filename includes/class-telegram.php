<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Telegram {
    public static function boot() {
        add_filter( 'cron_schedules', static function ( $schedules ) { $schedules['pds_minute'] = array( 'interval' => 60, 'display' => 'A cada minuto — Portal' ); return $schedules; } );
        add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
        add_action( 'pds_tg_process_queue', array( __CLASS__, 'work' ) );
        add_action( 'pds_tg_process_once', array( __CLASS__, 'work' ) );
        add_action( 'pds_cleanup_limits', array( __CLASS__, 'cleanup' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'pds_account_after', static function () { include PDS_DIR . 'templates/telegram-account.php'; } );
        add_action( 'pds_enqueue_assets', static function () { wp_enqueue_script( 'pds-telegram-account', PDS_URL . 'assets/telegram.js', array( 'pds-portal' ), PDS_VERSION, true ); } );
    }

    public static function schedule() {
        if ( ! empty( PDS_Telegram_Client::settings()['enabled'] ) && ! wp_next_scheduled( 'pds_tg_process_queue' ) ) { wp_schedule_event( time() + 5, 'pds_minute', 'pds_tg_process_queue' ); }
    }

    public static function routes() {
        register_rest_route( 'pds/v1', '/telegram/link', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'account' ), 'permission_callback' => array( __CLASS__, 'user_permission' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'link_code' ), 'permission_callback' => array( __CLASS__, 'user_permission' ) ),
            array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'unlink_account' ), 'permission_callback' => array( 'PDS_Auth', 'require_user' ) ),
        ) );
        register_rest_route( 'pds/v1', '/telegram/preferences', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'preferences' ), 'permission_callback' => array( __CLASS__, 'user_permission' ) ) );
        register_rest_route( 'pds/v1', '/telegram/webhook', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'webhook' ), 'permission_callback' => array( __CLASS__, 'webhook_permission' ) ) );
        register_rest_route( 'pds/v1', '/telegram/run', array( 'methods' => 'POST', 'callback' => static function () { self::work(); return array( 'ok' => true ); }, 'permission_callback' => array( __CLASS__, 'worker_permission' ) ) );
    }

    public static function user_permission() {
        if ( ! is_user_logged_in() ) { return new WP_Error( 'pds_tg_login', 'Entre na sua conta.', array( 'status' => 401 ) ); }
        return get_user_meta( get_current_user_id(), 'pds_suspended', true ) ? new WP_Error( 'pds_tg_blocked', 'O acesso desta conta ao portal está suspenso.', array( 'status' => 403 ) ) : true;
    }

    public static function link( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_links WHERE user_id = %d", $user_id ), ARRAY_A );
    }

    public static function account() {
        $config = PDS_Telegram_Client::settings(); $link = self::link( get_current_user_id() );
        return array(
            'enabled' => ! empty( $config['enabled'] ), 'bot_username' => $config['bot_username'],
            'linked' => ! empty( $link['telegram_id'] ), 'default_vehicle_id' => (int) ( $link['default_vehicle_id'] ?? 0 ),
            'confirm_before_save' => ! empty( $link['confirm_before_save'] ), 'global_confirmation' => ! empty( $config['confirm_before_save'] ),
            'vehicles' => pds_user_can_access_service( 'combustivel' ) ? PDS_Fuel::vehicles()['vehicles'] : array(),
            'daily_limit' => (int) $config['daily_limit'], 'voice_seconds' => (int) $config['voice_seconds'],
        );
    }

    public static function link_code( WP_REST_Request $request ) {
        global $wpdb;
        $config = PDS_Telegram_Client::settings();
        if ( empty( $config['enabled'] ) || ! $config['bot_username'] ) { return new WP_Error( 'pds_tg_disabled', 'A integração ainda não foi ativada pelo responsável pelo portal.', array( 'status' => 400 ) ); }
        if ( true !== $request->get_param( 'accepted' ) ) { return new WP_Error( 'pds_tg_consent', 'Confirme o envio das mensagens ao Telegram e à OpenAI para reconhecimento.', array( 'status' => 400 ) ); }
        $uid = get_current_user_id(); $existing = self::link( $uid );
        if ( ! empty( $existing['telegram_id'] ) ) { return new WP_Error( 'pds_tg_linked', 'Sua conta já está vinculada. Desvincule antes de trocar o Telegram.', array( 'status' => 409 ) ); }
        $token = bin2hex( random_bytes( 16 ) ); $now = current_time( 'mysql', true );
        $data = array( 'code_hash' => hash( 'sha256', $token ), 'code_expires' => time() + 600, 'updated_at' => $now );
        $ok = $existing ? $wpdb->update( $wpdb->prefix . 'pds_tg_links', $data, array( 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_tg_links', $data + array( 'user_id' => $uid, 'created_at' => $now ) );
        if ( false === $ok ) { return new WP_Error( 'pds_tg_storage', 'Não foi possível gerar o vínculo.', array( 'status' => 500 ) ); }
        return array( 'url' => 'https://t.me/' . $config['bot_username'] . '?start=pds_' . $token, 'code' => '/start pds_' . $token, 'expires_in' => 600 );
    }

    public static function unlink_user( $uid ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'pds_tg_links', array( 'user_id' => $uid ) );
        $wpdb->delete( $wpdb->prefix . 'pds_tg_drafts', array( 'user_id' => $uid ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_updates SET status='done', payload=NULL, response=NULL, error_code='unlinked' WHERE user_id=%d AND status IN ('pending','notify')", $uid ) );
    }

    public static function unlink_account() { self::unlink_user( get_current_user_id() ); return array( 'success' => true ); }

    public static function preferences( WP_REST_Request $request ) {
        global $wpdb;
        $link = self::link( get_current_user_id() );
        if ( ! $link || ! $link['telegram_id'] ) { return new WP_Error( 'pds_tg_not_linked', 'Vincule o Telegram antes de configurar.', array( 'status' => 400 ) ); }
        $id = $request->get_param( 'default_vehicle_id' ); $confirm = $request->get_param( 'confirm_before_save' );
        if ( ! is_int( $id ) || $id < 0 || ! is_bool( $confirm ) ) { return new WP_Error( 'pds_tg_fields', 'Confira o veículo e a confirmação.', array( 'status' => 400 ) ); }
        if ( $id && ( ! pds_user_can_access_service( 'combustivel' ) || is_wp_error( PDS_Fuel::vehicle( $id ) ) ) ) { return new WP_Error( 'pds_tg_vehicle', 'Escolha um veículo disponível para sua conta.', array( 'status' => 403 ) ); }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_tg_links', array( 'default_vehicle_id' => $id, 'confirm_before_save' => (int) $confirm, 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => get_current_user_id() ) );
        return false === $ok ? new WP_Error( 'pds_tg_storage', 'Não foi possível salvar.', array( 'status' => 500 ) ) : array( 'success' => true );
    }

    public static function webhook_permission( WP_REST_Request $request ) {
        $config = PDS_Telegram_Client::settings(); $secret = $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );
        if ( empty( $config['enabled'] ) || ! $config['webhook_hash'] || ! $secret || ! hash_equals( $config['webhook_hash'], hash( 'sha256', $secret ) ) ) { return new WP_Error( 'pds_tg_webhook', 'Solicitação não autorizada.', array( 'status' => 403 ) ); }
        return true;
    }

    public static function worker_token( $time = null ) {
        $time = $time ?? time();
        return $time . ':' . hash_hmac( 'sha256', 'pds-tg-worker:' . $time, wp_salt( 'auth' ) );
    }

    public static function worker_permission( WP_REST_Request $request ) {
        $token = (string) $request->get_header( 'X-PDS-Worker' );
        if ( empty( PDS_Telegram_Client::settings()['enabled'] ) || ! preg_match( '/^([0-9]{10,12}):[a-f0-9]{64}$/D', $token, $m ) || abs( time() - (int) $m[1] ) > 90 || ! hash_equals( self::worker_token( (int) $m[1] ), $token ) ) { return new WP_Error( 'pds_tg_worker', 'Solicitação não autorizada.', array( 'status' => 403 ) ); }
        return true;
    }

    private static function identifier( $value ) { return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[1-9][0-9]{0,19}$/D', (string) $value ) ? (string) $value : ''; }

    public static function quota( $scope, $actor, $limit, $seconds ) {
        global $wpdb;
        $key = hash_hmac( 'sha256', $scope . '|' . $actor . '|' . ( DAY_IN_SECONDS === $seconds ? current_time( 'Y-m-d' ) : floor( time() / $seconds ) ), wp_salt( 'auth' ) );
        $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}pds_rate_limits (limit_key,hits,expires_at) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1", $key, time() + $seconds ) );
        if ( false === $result ) { return false; }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}pds_rate_limits WHERE limit_key=%s", $key ) ) <= $limit;
    }

    public static function webhook( WP_REST_Request $request ) {
        global $wpdb;
        if ( strlen( $request->get_body() ) > 65536 ) { return new WP_Error( 'pds_tg_size', 'Mensagem muito grande.', array( 'status' => 413 ) ); }
        $update = $request->get_json_params();
        if ( ! is_array( $update ) || ! isset( $update['update_id'] ) || ! is_int( $update['update_id'] ) || $update['update_id'] < 0 ) { return new WP_Error( 'pds_tg_payload', 'Mensagem inválida.', array( 'status' => 400 ) ); }
        $callback = $update['callback_query'] ?? null; $message = is_array( $callback ) ? ( $callback['message'] ?? null ) : ( $update['message'] ?? null );
        $from = is_array( $callback ) ? ( $callback['from'] ?? array() ) : ( $message['from'] ?? array() );
        if ( ! is_array( $message ) || ( $message['chat']['type'] ?? '' ) !== 'private' || ! empty( $from['is_bot'] ) ) { return array( 'ok' => true, 'ignored' => true ); }
        $telegram = self::identifier( $from['id'] ?? null ); $chat = self::identifier( $message['chat']['id'] ?? null );
        if ( ! $telegram || $telegram !== $chat ) { return array( 'ok' => true, 'ignored' => true ); }
        $config = PDS_Telegram_Client::settings(); $event_key = hash( 'sha256', $config['bot_id'] . ':' . $update['update_id'] );
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_tg_updates WHERE event_key=%s", $event_key ) ) ) { return array( 'ok' => true, 'duplicate' => true ); }
        if ( ! self::quota( 'tg-ingress', $telegram, 40, 60 ) ) { return array( 'ok' => true, 'limited' => true ); }
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_links WHERE telegram_id=%s", $telegram ), ARRAY_A );
        $payload = array( 'text' => is_string( $message['text'] ?? null ) ? mb_substr( $message['text'], 0, 4000 ) : '', 'date' => is_int( $message['date'] ?? null ) ? $message['date'] : time() );
        if ( is_array( $callback ) ) {
            if ( ! is_string( $callback['data'] ?? null ) || ! is_string( $callback['id'] ?? null ) || strlen( $callback['data'] ) > 64 ) { return array( 'ok' => true, 'ignored' => true ); }
            $payload['callback'] = $callback['data']; $payload['callback_id'] = $callback['id'];
        } else {
            $voice = $message['voice'] ?? ( $message['audio'] ?? null );
            if ( is_array( $voice ) && is_string( $voice['file_id'] ?? null ) && strlen( $voice['file_id'] ) <= 256 ) { $payload['audio'] = array( 'file_id' => $voice['file_id'], 'duration' => (int) ( $voice['duration'] ?? 0 ), 'size' => (int) ( $voice['file_size'] ?? 0 ) ); }
        }
        // Keep only the linking command for unlinked accounts; never their fuel/audio content.
        if ( ! $link && ! preg_match( '/^\/start(?:@[A-Za-z0-9_]+)?\s+pds_[a-f0-9]{32}$/D', $payload['text'] ) ) { $payload = array( 'text' => '/start', 'date' => $payload['date'] ); }
        $pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_tg_updates WHERE telegram_id=%s AND status IN ('pending','processing','notify')", $telegram ) );
        if ( $pending >= 10 ) { return array( 'ok' => true, 'limited' => true ); }
        $result = $wpdb->insert( $wpdb->prefix . 'pds_tg_updates', array( 'event_key' => $event_key, 'bot_id' => $config['bot_id'], 'user_id' => (int) ( $link['user_id'] ?? 0 ), 'telegram_id' => $telegram, 'chat_id' => $chat, 'payload' => wp_json_encode( $payload ), 'created_at' => current_time( 'mysql', true ) ) );
        if ( ! $result ) { return new WP_Error( 'pds_tg_queue', 'Não foi possível receber a mensagem.', array( 'status' => 503 ) ); }
        if ( ! wp_next_scheduled( 'pds_tg_process_once' ) ) { wp_schedule_single_event( time(), 'pds_tg_process_once' ); }
        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            // A bounded, signed loopback lets the webhook return before speech recognition.
            wp_remote_post( rest_url( 'pds/v1/telegram/run' ), array( 'timeout' => 0.01, 'blocking' => false, 'redirection' => 0, 'headers' => array( 'X-PDS-Worker' => self::worker_token(), 'Content-Type' => 'application/json' ), 'body' => '{}' ) );
            spawn_cron();
        }
        return new WP_REST_Response( array( 'ok' => true, 'queued' => true ), 200 );
    }

    public static function consume_code( $token, $telegram, $chat ) {
        global $wpdb;
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) { return self::reply( 'Código inválido. Gere um novo vínculo em Minha conta no portal.' ); }
        $wpdb->query( 'START TRANSACTION' );
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_links WHERE code_hash=%s FOR UPDATE", hash( 'sha256', $token ) ), ARRAY_A );
        $other = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}pds_tg_links WHERE telegram_id=%s", $telegram ) );
        if ( ! $link || (int) $link['code_expires'] < time() || $link['telegram_id'] || $other || ! get_userdata( $link['user_id'] ) || get_user_meta( $link['user_id'], 'pds_suspended', true ) ) {
            $wpdb->query( 'ROLLBACK' ); return self::reply( 'O código expirou, já foi utilizado ou este Telegram já está vinculado. Confira Minha conta no portal.' );
        }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_tg_links', array( 'telegram_id' => $telegram, 'chat_id' => $chat, 'code_hash' => null, 'code_expires' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => $link['user_id'] ) );
        if ( ! $ok ) { $wpdb->query( 'ROLLBACK' ); return self::reply( 'Não foi possível concluir o vínculo. Gere outro código no portal.' ); }
        $wpdb->query( 'COMMIT' );
        return self::reply( "Telegram vinculado à sua conta do portal!\nEnvie um áudio ou texto com veículo, data, combustível, litros, preço de tabela, valor pago, quilometragem e se completou o tanque.\nUse /veiculos para escolher um veículo padrão ou /ajuda para ver um exemplo." );
    }

    public static function reply( $text, $markup = null ) { $reply = array( 'text' => mb_substr( $text, 0, 4000 ) ); if ( $markup ) { $reply['reply_markup'] = $markup; } return $reply; }

    private static function worker_lock() {
        global $wpdb;
        $old = get_option( 'pds_tg_worker_mutex' );
        if ( is_string( $old ) && (int) explode( ':', $old )[0] < time() ) {
            $wpdb->delete( $wpdb->options, array( 'option_name' => 'pds_tg_worker_mutex', 'option_value' => $old ) ); wp_cache_delete( 'pds_tg_worker_mutex', 'options' ); wp_cache_delete( 'notoptions', 'options' );
        }
        $lock = ( time() + 360 ) . ':' . bin2hex( random_bytes( 12 ) );
        return add_option( 'pds_tg_worker_mutex', $lock, '', false ) ? $lock : false;
    }

    public static function work() {
        global $wpdb;
        $lock = self::worker_lock(); if ( ! $lock ) { return; }
        $previous_uid = get_current_user_id();
        try {
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_updates SET status=IF(attempts<3,'pending','error'),error_code='worker_timeout' WHERE status='processing' AND locked_until<%d", time() ) );
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_updates WHERE status IN ('pending','notify') AND locked_until<=%d ORDER BY id LIMIT 2", time() ), ARRAY_A );
            foreach ( $rows as $job ) {
                $config = PDS_Telegram_Client::settings();
                if ( empty( $config['enabled'] ) || $config['bot_id'] !== $job['bot_id'] ) { $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'status' => 'done', 'payload' => null, 'response' => null, 'error_code' => 'disabled' ), array( 'id' => $job['id'] ) ); continue; }
                $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_updates SET status='processing',attempts=attempts+1,locked_until=%d WHERE id=%d AND status=%s", time() + 180, $job['id'], $job['status'] ) );
                if ( ! $claimed ) { continue; }
                $payload = json_decode( $job['payload'] ?? '{}', true ); $reply = null;
                try {
                    if ( 'notify' === $job['status'] ) {
                        $current_link = (int) $job['user_id'] ? self::link( $job['user_id'] ) : null;
                        if ( (int) $job['user_id'] && ( ! $current_link || $current_link['telegram_id'] !== $job['telegram_id'] ) ) { $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'status' => 'done', 'response' => null, 'error_code' => 'unlinked' ), array( 'id' => $job['id'] ) ); continue; }
                        $reply = json_decode( $job['response'], true );
                    }
                    else { $reply = self::handle( $job, is_array( $payload ) ? $payload : array() ); }
                    if ( ! is_array( $reply ) || ! is_string( $reply['text'] ?? null ) ) { throw new RuntimeException( 'invalid_reply' ); }
                } catch ( Throwable $error ) { $reply = self::reply( 'Não consegui concluir esta mensagem. Confira o histórico no portal antes de reenviar.' ); }
                $sent = PDS_Telegram_Client::telegram( 'sendMessage', array_merge( $reply, array( 'chat_id' => $job['chat_id'], 'link_preview_options' => array( 'is_disabled' => true ) ) ) );
                if ( isset( $payload['callback_id'] ) ) { PDS_Telegram_Client::telegram( 'answerCallbackQuery', array( 'callback_query_id' => $payload['callback_id'] ) ); }
                $failed = is_wp_error( $sent );
                $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array(
                    'status' => $failed ? ( (int) $job['attempts'] + 1 >= 3 ? 'error' : 'notify' ) : 'done',
                    'payload' => null, 'response' => $failed ? wp_json_encode( $reply ) : null,
                    'locked_until' => $failed ? time() + 60 : 0, 'error_code' => $failed ? 'telegram_delivery' : '',
                ), array( 'id' => $job['id'] ) );
                wp_set_current_user( $previous_uid );
            }
        } finally {
            wp_set_current_user( $previous_uid );
            update_option( 'pds_tg_last_run', current_time( 'mysql', true ), false );
            $wpdb->delete( $wpdb->options, array( 'option_name' => 'pds_tg_worker_mutex', 'option_value' => $lock ) ); wp_cache_delete( 'pds_tg_worker_mutex', 'options' ); wp_cache_delete( 'notoptions', 'options' );
        }
    }

    private static function handle( $job, $payload ) {
        global $wpdb;
        $text = trim( $payload['text'] ?? '' );
        if ( preg_match( '/^\/start(?:@[A-Za-z0-9_]+)?\s+pds_([a-f0-9]{32})$/D', $text, $m ) ) {
            $reply = self::consume_code( $m[1], $job['telegram_id'], $job['chat_id'] );
            $uid = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}pds_tg_links WHERE telegram_id=%s", $job['telegram_id'] ) );
            if ( $uid ) { $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'user_id' => (int) $uid ), array( 'id' => $job['id'] ) ); }
            return $reply;
        }
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_tg_links WHERE telegram_id=%s AND chat_id=%s", $job['telegram_id'], $job['chat_id'] ), ARRAY_A );
        if ( ! $link || ! get_userdata( $link['user_id'] ) ) { return self::reply( 'Primeiro vincule seu Telegram em Minha conta no portal. Não é necessário enviar sua senha por aqui.' ); }
        if ( '/desvincular' === $text ) { self::unlink_user( $link['user_id'] ); return self::reply( 'Telegram desvinculado. Você pode vinculá-lo novamente em Minha conta no portal.' ); }
        if ( get_user_meta( $link['user_id'], 'pds_suspended', true ) ) { return self::reply( 'O acesso desta conta ao portal está suspenso. Consulte o responsável pelo portal.' ); }
        wp_set_current_user( $link['user_id'] );
        $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'user_id' => (int) $link['user_id'] ), array( 'id' => $job['id'] ) );
        if ( in_array( $text, array( '/start', '/ajuda', '/help' ), true ) ) { return self::reply( "Exemplo: Hoje abasteci o HB20 com gasolina, 30 litros a R$ 6,20. Paguei R$ 180, odômetro 45.200 e completei o tanque.\n\n/veiculos — listar veículos\n/veiculo ID — definir o padrão\n/confirmar — salvar resumo pendente\n/cancelar — descartar pendência\n/desvincular — remover o vínculo" ); }
        if ( (int) $job['record_id'] > 0 ) { return PDS_Telegram_Fuel::saved_reply( (int) $job['record_id'] ); }
        /** Future modules must enforce their own service and record permissions. */
        $custom = apply_filters( 'pds_telegram_reply', null, $payload, $link );
        if ( is_array( $custom ) ) { return $custom; }
        return PDS_Telegram_Fuel::handle( $job, $payload, $link );
    }

    public static function cleanup() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pds_tg_drafts WHERE expires_at<%d", time() ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_links SET code_hash=NULL, code_expires=0 WHERE code_expires>0 AND code_expires<%d", time() ) );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pds_tg_updates SET payload=NULL,response=NULL,status='error',error_code='expired' WHERE status<>'done' AND created_at<%s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pds_tg_updates WHERE created_at<%s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
    }
}
