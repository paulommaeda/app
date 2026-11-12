<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Telegram_Admin {
    public static function boot() {
        add_action( 'admin_menu', static function () { add_submenu_page( 'pds-portal', 'Telegram', 'Telegram', 'manage_options', 'pds-telegram', array( __CLASS__, 'page' ) ); } );
        add_action( 'admin_post_pds_telegram_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_pds_telegram_connect', array( __CLASS__, 'connect' ) );
        add_action( 'admin_post_pds_telegram_disconnect', array( __CLASS__, 'disconnect' ) );
        add_action( 'admin_post_pds_telegram_work', array( __CLASS__, 'work' ) );
    }

    private static function authorize( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissão.' ); } check_admin_referer( $action ); }
    private static function finish( $message, $error = false ) {
        set_transient( 'pds_tg_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pds-telegram' ) ); exit;
    }

    public static function save() {
        self::authorize( 'pds_telegram_save' );
        $config = PDS_Telegram_Client::settings();
        foreach ( array( 'bot_token' => 'PDS_TELEGRAM_BOT_TOKEN', 'openai_key' => 'PDS_OPENAI_API_KEY' ) as $key => $constant ) {
            if ( defined( $constant ) ) { continue; }
            $value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( wp_unslash( $_POST[ $key ] ) ) : '';
            if ( ! $value ) { continue; }
            if ( strlen( $value ) > 512 || preg_match( '/[\r\n]/', $value ) || ( 'bot_token' === $key && ! preg_match( '/^[0-9]{1,20}:[A-Za-z0-9_-]{20,120}$/D', $value ) ) || ( 'openai_key' === $key && strlen( $value ) < 20 ) ) { self::finish( 'Confira o token do bot e a chave da OpenAI.', true ); }
            $sealed = PDS_Telegram_Client::seal( $value );
            if ( is_wp_error( $sealed ) ) { self::finish( $sealed->get_error_message(), true ); }
            if ( 'bot_token' === $key && $value !== PDS_Telegram_Client::secret( 'bot_token' ) ) { $config['enabled'] = 0; $config['webhook_hash'] = ''; $config['bot_username'] = ''; $config['bot_id'] = ''; }
            $config[ $key ] = $sealed;
        }
        foreach ( array( 'transcription_model', 'extraction_model' ) as $key ) {
            $value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( wp_unslash( $_POST[ $key ] ) ) : '';
            if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,100}$/D', $value ) ) { self::finish( 'Confira os nomes dos modelos de reconhecimento.', true ); }
            $config[ $key ] = $value;
        }
        $config['daily_limit'] = min( 200, max( 1, absint( $_POST['daily_limit'] ?? 30 ) ) );
        $config['voice_seconds'] = min( 300, max( 15, absint( $_POST['voice_seconds'] ?? 120 ) ) );
        $config['confirm_before_save'] = isset( $_POST['confirm_before_save'] ) ? 1 : 0;
        update_option( 'pds_telegram_settings', $config, false );
        self::finish( 'Configurações salvas. Se alterou o bot, clique em Ativar conexão.' );
    }

    public static function activate_connection() {
        $url = rest_url( 'pds/v1/telegram/webhook' );
        if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) { return new WP_Error( 'pds_tg_https', 'O endereço do WordPress precisa usar HTTPS para receber mensagens do Telegram.' ); }
        if ( ! PDS_Telegram_Client::secret( 'openai_key' ) ) { return new WP_Error( 'pds_tg_key', 'Salve a chave da OpenAI para o reconhecimento de voz e texto.' ); }
        $bot = PDS_Telegram_Client::telegram( 'getMe' );
        if ( is_wp_error( $bot ) ) { return $bot; }
        if ( ! is_array( $bot ) || empty( $bot['is_bot'] ) || ! preg_match( '/^[0-9]{1,20}$/D', (string) ( $bot['id'] ?? '' ) ) || ! is_string( $bot['username'] ?? null ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{3,63}$/D', $bot['username'] ) ) { return new WP_Error( 'pds_tg_bot', 'Não foi possível identificar o bot.' ); }
        $secret = bin2hex( random_bytes( 32 ) );
        $result = PDS_Telegram_Client::telegram( 'setWebhook', array( 'url' => $url, 'secret_token' => $secret, 'allowed_updates' => array( 'message', 'callback_query' ), 'max_connections' => 1, 'drop_pending_updates' => false ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $config = PDS_Telegram_Client::settings(); $config['bot_id'] = (string) $bot['id']; $config['bot_username'] = $bot['username']; $config['webhook_hash'] = hash( 'sha256', $secret ); $config['enabled'] = 1;
        update_option( 'pds_telegram_settings', $config, false );
        PDS_Telegram::schedule();
        return true;
    }

    public static function connect() { self::authorize( 'pds_telegram_connect' ); $result = self::activate_connection(); self::finish( is_wp_error( $result ) ? $result->get_error_message() : 'Bot conectado. Cada usuário já pode vincular seu Telegram em Minha conta no portal.', is_wp_error( $result ) ); }

    public static function disconnect() {
        self::authorize( 'pds_telegram_disconnect' );
        $result = PDS_Telegram_Client::telegram( 'deleteWebhook', array( 'drop_pending_updates' => false ) );
        $config = PDS_Telegram_Client::settings(); $config['enabled'] = 0; $config['webhook_hash'] = '';
        update_option( 'pds_telegram_settings', $config, false ); wp_clear_scheduled_hook( 'pds_tg_process_queue' ); wp_clear_scheduled_hook( 'pds_tg_process_once' );
        self::finish( is_wp_error( $result ) ? 'Recebimento desativado no portal. Não foi possível confirmar a remoção da conexão no Telegram; confira o token e tente novamente.' : 'Recebimento pelo Telegram desativado.', is_wp_error( $result ) );
    }

    public static function work() { self::authorize( 'pds_telegram_work' ); PDS_Telegram::work(); self::finish( 'Etapa da fila processada. As mensagens restantes seguem pelo agendamento do WordPress.' ); }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $config = PDS_Telegram_Client::settings(); $notice = get_transient( 'pds_tg_notice_' . get_current_user_id() ); delete_transient( 'pds_tg_notice_' . get_current_user_id() );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_tg_updates WHERE status IN ('pending','processing','notify')" );
        $errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_tg_updates WHERE status='error'" );
        $linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_tg_links WHERE telegram_id IS NOT NULL" );
        include PDS_DIR . 'templates/telegram-admin.php';
    }
}
