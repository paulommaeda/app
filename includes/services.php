<?php
defined( 'ABSPATH' ) || exit;

/** Public extension API. Call on pds_register_services, not at file load time. */
function pds_register_service( $slug, array $args ) {
    $slug = sanitize_key( $slug );
    if ( ! $slug || 'conta' === $slug || isset( $GLOBALS['pds_services'][ $slug ] ) || empty( $args['render_callback'] ) || ! is_callable( $args['render_callback'] ) ) {
        return false;
    }
    $GLOBALS['pds_services'][ $slug ] = wp_parse_args( $args, array(
        'title' => $slug, 'description' => '', 'icon' => 'grid',
        'permission_callback' => null, 'order' => 100,
    ) );
    return true;
}

function pds_get_services() {
    $services = $GLOBALS['pds_services'] ?? array();
    uasort( $services, static function ( $a, $b ) { return $a['order'] <=> $b['order']; } );
    return $services;
}

function pds_user_can_access_service( $service, $user_id = 0 ) {
    $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
    $services = pds_get_services();
    if ( ! $user_id || ! get_userdata( $user_id ) || ! isset( $services[ $service ] ) ) {
        return false;
    }
    $settings = get_option( 'pds_settings', array() );
    if ( in_array( $service, $settings['disabled_services'] ?? array(), true ) || get_user_meta( $user_id, 'pds_suspended', true ) ) {
        return false;
    }
    if ( in_array( $service, (array) get_user_meta( $user_id, 'pds_blocked_services', true ), true ) ) {
        return false;
    }
    $callback = $services[ $service ]['permission_callback'];
    $allowed = ! is_callable( $callback ) || (bool) call_user_func( $callback, $user_id );
    /** Entitlement filters may further restrict access; global/account blocks cannot be bypassed. */
    return $allowed && (bool) apply_filters( 'pds_service_access', true, $service, $user_id );
}

function pds_portal_url( $service = '' ) {
    $settings = get_option( 'pds_settings', array() );
    $url = ! empty( $settings['page_id'] ) ? get_permalink( $settings['page_id'] ) : home_url( '/' );
    return $service ? add_query_arg( 'servico', sanitize_key( $service ), $url ) : $url;
}
