<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Core {
    private static $rendered = false;

    public static function boot() {
        add_action( 'init', static function () { do_action( 'pds_register_services' ); }, 20 );
        add_shortcode( 'portal_servicos', array( __CLASS__, 'shortcode' ) );
        add_shortcode( 'portal_combustivel', static function () { return self::shortcode( array( 'service' => 'combustivel' ) ); } );
        add_shortcode( 'portal_gastos', static function () { return self::shortcode( array( 'service' => 'gastos' ) ); } );
        add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ), 0 );
        add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'protect_admin' ) );
        add_filter( 'show_admin_bar', static function ( $show ) { return self::is_portal() ? false : $show; } );
        add_filter( 'wp_robots', static function ( $robots ) {
            if ( self::is_portal() ) { $robots['noindex'] = true; $robots['nofollow'] = true; }
            return $robots;
        } );
        add_action( 'pds_cleanup_limits', static function () {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pds_rate_limits WHERE expires_at < %d", time() ) );
        } );
        add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
            if ( strpos( $request->get_route(), '/pds/v1/' ) === 0 ) {
                $response->header( 'Cache-Control', 'no-store, private, max-age=0' );
                $response->header( 'Vary', 'Cookie', false );
            }
            return $response;
        }, 10, 3 );
    }

    public static function is_portal() {
        if ( ! is_singular( 'page' ) ) { return false; }
        $post = get_queried_object();
        $settings = get_option( 'pds_settings', array() );
        return (int) get_queried_object_id() === (int) ( $settings['page_id'] ?? 0 ) || ( $post && ( has_shortcode( $post->post_content, 'portal_servicos' ) || has_shortcode( $post->post_content, 'portal_combustivel' ) || has_shortcode( $post->post_content, 'portal_gastos' ) ) );
    }

    public static function prepare_page() {
        if ( ! self::is_portal() ) { return; }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        nocache_headers();
        header( 'Referrer-Policy: same-origin' );
        PDS_Auth::guest_cookie();
    }

    public static function template( $template ) {
        $settings = get_option( 'pds_settings', array() );
        return self::is_portal() && ! empty( $settings['standalone'] ) ? PDS_DIR . 'templates/standalone.php' : $template;
    }

    public static function assets() {
        if ( ! self::is_portal() ) { return; }
        wp_enqueue_style( 'pds-portal', PDS_URL . 'assets/portal.css', array(), PDS_VERSION );
        wp_enqueue_script( 'pds-portal', PDS_URL . 'assets/portal.js', array(), PDS_VERSION, true );
        $settings = get_option( 'pds_settings', array() );
        wp_localize_script( 'pds-portal', 'PDS', array(
            'api' => esc_url_raw( rest_url( 'pds/v1/' ) ),
            'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'csrf' => PDS_Auth::guest_token(), 'portalUrl' => pds_portal_url(),
            'today' => current_time( 'Y-m-d' ), 'registration' => ! empty( $settings['registration'] ),
            'privacyUrl' => get_privacy_policy_url(),
            'reset' => array(
                'key' => isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '',
                'login' => isset( $_GET['login'] ) && is_string( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '',
            ),
        ) );
        do_action( 'pds_enqueue_assets' );
    }

    public static function protect_admin() {
        if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_user_logged_in() ) { return; }
        $user = wp_get_current_user();
        if ( in_array( 'pds_member', $user->roles, true ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ) ) {
            wp_safe_redirect( pds_portal_url() ); exit;
        }
    }

    public static function shortcode( $attributes = array() ) {
        if ( self::$rendered ) { return ''; }
        self::$rendered = true;
        $settings = get_option( 'pds_settings', array() );
        $brand = $settings['name'] ?? 'Meu Portal';
        $service = isset( $_GET['servico'] ) && is_string( $_GET['servico'] ) ? sanitize_key( wp_unslash( $_GET['servico'] ) ) : '';
        if ( ! $service && ! empty( $attributes['service'] ) ) { $service = sanitize_key( $attributes['service'] ); }
        $services = pds_get_services();
        ob_start();
        include PDS_DIR . 'templates/portal.php';
        return ob_get_clean();
    }

    public static function icon( $name, $class = '' ) {
        $paths = array(
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'fuel' => '<path d="M4 21V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v17M2 21h14M4 11h10M17 5l4 4v9a2 2 0 0 1-4 0v-4h-3"/><path d="M18 6v4h3"/>',
            'wallet' => '<path d="M20 7H5a2 2 0 0 1 0-4h13v4M3 5v14a2 2 0 0 0 2 2h15V7M20 11h-6v6h6"/><circle cx="16" cy="14" r=".6"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
            'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'car' => '<path d="m5 7 2-4h10l2 4 2 4v8H3v-8l2-4ZM3 11h18M7 15h1m8 0h1M5 19v2m14-2v2"/>',
            'chart' => '<path d="M3 3v18h18M7 16l4-5 4 2 6-8"/>',
            'history' => '<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-5v6l4 2"/>',
            'logout' => '<path d="M9 5H4v14h5m5-14 7 7-7 7m-6-7h13"/>',
            'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
            'chevron' => '<path d="m9 5 7 7-7 7"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
        );
        return '<svg class="pds-icon ' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? $paths['grid'] ) . '</svg>';
    }
}
