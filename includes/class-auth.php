<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Auth {
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() {
        foreach ( array( 'login', 'register', 'forgot', 'reset' ) as $action ) {
            register_rest_route( 'pds/v1', '/auth/' . $action, array(
                'methods' => 'POST', 'callback' => array( __CLASS__, $action ),
                'permission_callback' => array( __CLASS__, 'guest_permission' ),
            ) );
        }
        register_rest_route( 'pds/v1', '/account', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'account' ), 'permission_callback' => array( __CLASS__, 'require_user' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_account' ), 'permission_callback' => array( __CLASS__, 'require_user' ) ),
        ) );
        register_rest_route( 'pds/v1', '/auth/logout', array(
            'methods' => 'POST', 'callback' => static function () { wp_logout(); return array( 'success' => true ); },
            'permission_callback' => array( __CLASS__, 'require_user' ),
        ) );
    }

    public static function guest_cookie() {
        if ( is_user_logged_in() || ( isset( $_COOKIE['pds_guest'] ) && is_string( $_COOKIE['pds_guest'] ) && preg_match( '/^[a-zA-Z0-9]{40}$/', $_COOKIE['pds_guest'] ) ) ) { return; }
        $value = wp_generate_password( 40, false, false );
        setcookie( 'pds_guest', $value, array(
            'expires' => time() + DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/',
            'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
        ) );
        $_COOKIE['pds_guest'] = $value;
    }

    public static function guest_token( $tick = null ) {
        $cookie = $_COOKIE['pds_guest'] ?? '';
        if ( ! is_string( $cookie ) || ! preg_match( '/^[a-zA-Z0-9]{40}$/', $cookie ) ) { return ''; }
        $tick = $tick ?? (int) floor( time() / ( 20 * MINUTE_IN_SECONDS ) );
        return hash_hmac( 'sha256', $cookie . '|' . $tick, wp_salt( 'nonce' ) );
    }

    public static function guest_permission( WP_REST_Request $request ) {
        if ( is_user_logged_in() ) { return new WP_Error( 'pds_already_logged_in', 'Sua conta já está conectada. Atualize a página.', array( 'status' => 409 ) ); }
        $token = $request->get_header( 'X-PDS-CSRF' );
        $tick = (int) floor( time() / ( 20 * MINUTE_IN_SECONDS ) );
        $current = self::guest_token( $tick );
        if ( ! $current || ! $token || ( ! hash_equals( $current, $token ) && ! hash_equals( self::guest_token( $tick - 1 ), $token ) ) ) {
            return new WP_Error( 'pds_csrf', 'A página expirou. Atualize e tente novamente.', array( 'status' => 403 ) );
        }
        if ( $request->get_param( 'website' ) ) {
            return new WP_Error( 'pds_invalid_request', 'Não foi possível processar a solicitação.', array( 'status' => 400 ) );
        }
        return true;
    }

    public static function require_user() {
        return is_user_logged_in() ? true : new WP_Error( 'pds_auth', 'Entre na sua conta para continuar.', array( 'status' => 401 ) );
    }

    /** Fixed-window, atomic counters. Only a salted hash is stored, never a raw IP/email. */
    public static function rate_limit( $scope, $max = 10, $seconds = 900 ) {
        global $wpdb;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = hash_hmac( 'sha256', $scope . '|' . $ip . '|' . floor( time() / $seconds ), wp_salt( 'auth' ) );
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}pds_rate_limits (limit_key,hits,expires_at) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits = hits + 1",
            $key, time() + $seconds
        ) );
        if ( false === $result ) { return new WP_Error( 'pds_unavailable', 'O serviço está temporariamente indisponível. Tente novamente em alguns minutos.', array( 'status' => 503 ) ); }
        $hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$wpdb->prefix}pds_rate_limits WHERE limit_key = %s", $key ) );
        if ( $hits > $max ) { return new WP_Error( 'pds_rate_limit', 'Muitas tentativas. Aguarde 15 minutos e tente novamente.', array( 'status' => 429 ) ); }
        return true;
    }

    public static function field( WP_REST_Request $request, $key ) {
        $value = $request->get_param( $key );
        return is_string( $value ) ? $value : '';
    }

    public static function login( WP_REST_Request $request ) {
        $limited = self::rate_limit( 'login', 15 );
        if ( is_wp_error( $limited ) ) { return $limited; }
        $login = trim( self::field( $request, 'email' ) );
        $password = self::field( $request, 'password' );
        if ( ! $login || ! $password ) { return new WP_Error( 'pds_fields', 'Informe e-mail e senha.', array( 'status' => 400 ) ); }
        $user = wp_signon( array( 'user_login' => $login, 'user_password' => $password, 'remember' => (bool) $request->get_param( 'remember' ) ), is_ssl() );
        if ( is_wp_error( $user ) ) { return new WP_Error( 'pds_login', 'Não foi possível entrar. Confira seus dados ou recupere a senha.', array( 'status' => 401 ) ); }
        update_user_meta( $user->ID, 'pds_member', 1 );
        return array( 'success' => true );
    }

    public static function register( WP_REST_Request $request ) {
        $settings = get_option( 'pds_settings', array() );
        if ( empty( $settings['registration'] ) ) { return new WP_Error( 'pds_closed', 'Novos cadastros estão temporariamente desativados.', array( 'status' => 403 ) ); }
        $limited = self::rate_limit( 'register', 5 );
        if ( is_wp_error( $limited ) ) { return $limited; }
        $name = sanitize_text_field( self::field( $request, 'name' ) );
        $email = sanitize_email( self::field( $request, 'email' ) );
        $password = self::field( $request, 'password' );
        if ( ! $name || mb_strlen( $name ) > 150 || ! is_email( $email ) || strlen( $email ) > 100 || strlen( $password ) < 10 || strlen( $password ) > 200 ) {
            return new WP_Error( 'pds_fields', 'Informe seu nome, um e-mail válido e uma senha entre 10 e 200 caracteres.', array( 'status' => 400 ) );
        }
        if ( get_privacy_policy_url() && ! $request->get_param( 'accepted_policy' ) ) {
            return new WP_Error( 'pds_policy', 'Confirme a leitura da política de privacidade.', array( 'status' => 400 ) );
        }
        if ( email_exists( $email ) ) { return new WP_Error( 'pds_email_exists', 'Este e-mail já possui uma conta. Entre ou recupere a senha.', array( 'status' => 409 ) ); }
        // Public registrations always get the fixed, minimal role. Client-supplied roles are ignored.
        $id = wp_insert_user( array(
            'user_login' => 'pds_' . wp_generate_password( 20, false, false ),
            'user_email' => $email, 'user_pass' => $password, 'display_name' => $name,
            'first_name' => $name, 'role' => 'pds_member',
        ) );
        if ( is_wp_error( $id ) ) { return new WP_Error( 'pds_register_failed', 'Não foi possível criar sua conta. Tente novamente.', array( 'status' => 400 ) ); }
        update_user_meta( $id, 'pds_member', 1 );
        if ( get_privacy_policy_url() ) { update_user_meta( $id, 'pds_policy_accepted_at', current_time( 'mysql', true ) ); }
        wp_set_current_user( $id );
        wp_set_auth_cookie( $id, false, is_ssl() );
        do_action( 'pds_user_registered', $id );
        return array( 'success' => true );
    }

    public static function forgot( WP_REST_Request $request ) {
        $limited = self::rate_limit( 'forgot', 5 );
        if ( is_wp_error( $limited ) ) { return $limited; }
        $email = sanitize_email( self::field( $request, 'email' ) );
        if ( ! is_email( $email ) ) { return new WP_Error( 'pds_email', 'Informe um e-mail válido.', array( 'status' => 400 ) ); }
        add_filter( 'retrieve_password_message', array( __CLASS__, 'reset_message' ), 10, 4 );
        retrieve_password( $email );
        remove_filter( 'retrieve_password_message', array( __CLASS__, 'reset_message' ), 10 );
        return array( 'success' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um link para criar uma nova senha. Confira também o spam.' );
    }

    public static function reset_message( $message, $key, $login, $user ) {
        $url = add_query_arg( array( 'pds_action' => 'reset', 'key' => $key, 'login' => $login ), pds_portal_url() );
        return 'Olá, ' . $user->display_name . ".\n\nRecebemos um pedido para redefinir sua senha. Acesse o link abaixo:\n\n" . $url . "\n\nSe você não fez esse pedido, ignore esta mensagem.\n";
    }

    public static function reset( WP_REST_Request $request ) {
        $limited = self::rate_limit( 'reset', 10 );
        if ( is_wp_error( $limited ) ) { return $limited; }
        $user = check_password_reset_key( self::field( $request, 'key' ), self::field( $request, 'login' ) );
        if ( is_wp_error( $user ) ) { return new WP_Error( 'pds_reset', 'O link de recuperação expirou ou já foi utilizado. Solicite um novo link.', array( 'status' => 400 ) ); }
        $password = self::field( $request, 'password' );
        if ( strlen( $password ) < 10 || strlen( $password ) > 200 ) { return new WP_Error( 'pds_password', 'A senha deve ter entre 10 e 200 caracteres.', array( 'status' => 400 ) ); }
        reset_password( $user, $password );
        return array( 'success' => true, 'message' => 'Senha atualizada. Entre com sua nova senha.' );
    }

    public static function account() {
        $user = wp_get_current_user();
        return array( 'id' => $user->ID, 'name' => $user->display_name, 'email' => $user->user_email );
    }

    public static function update_account( WP_REST_Request $request ) {
        $user = wp_get_current_user();
        $name = sanitize_text_field( self::field( $request, 'name' ) );
        $email = sanitize_email( self::field( $request, 'email' ) );
        $new_password = self::field( $request, 'new_password' );
        if ( ! $name || mb_strlen( $name ) > 150 || ! is_email( $email ) || strlen( $email ) > 100 ) {
            return new WP_Error( 'pds_fields', 'Confira o nome e o e-mail informados.', array( 'status' => 400 ) );
        }
        $sensitive = $email !== $user->user_email || $new_password !== '';
        if ( $sensitive && ! wp_check_password( self::field( $request, 'current_password' ), $user->user_pass, $user->ID ) ) {
            return new WP_Error( 'pds_password', 'Informe sua senha atual para alterar o e-mail ou a senha.', array( 'status' => 400 ) );
        }
        if ( $new_password && ( strlen( $new_password ) < 10 || strlen( $new_password ) > 200 ) ) {
            return new WP_Error( 'pds_password', 'A nova senha deve ter entre 10 e 200 caracteres.', array( 'status' => 400 ) );
        }
        $data = array( 'ID' => $user->ID, 'display_name' => $name, 'user_email' => $email );
        if ( $new_password ) { $data['user_pass'] = $new_password; }
        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) { return new WP_Error( 'pds_update', 'Não foi possível salvar. Verifique se o e-mail já está em uso.', array( 'status' => 400 ) ); }
        if ( $new_password ) { wp_set_auth_cookie( $user->ID, false, is_ssl() ); }
        return array( 'success' => true, 'reload' => true );
    }
}
