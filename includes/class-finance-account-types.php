<?php
defined( 'ABSPATH' ) || exit;

/** User-owned account classifications; legacy kind codes remain stable. */
final class PDS_Finance_Account_Types {
    private static $seeded = array();

    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'pds/v1', '/finance/account-types', array(
                array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'listing' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
                array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
            ) );
            register_rest_route( 'pds/v1', '/finance/account-types/(?P<id>\d+)', array(
                array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
                array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'archive' ), 'permission_callback' => array( 'PDS_Expenses', 'permission' ) ),
            ) );
        } );
        add_action( 'pds_enqueue_assets', static function () {
            wp_enqueue_script( 'pds-account-types', PDS_URL . 'assets/account-types.js', array( 'pds-finance' ), PDS_VERSION, true );
        } );
    }

    public static function seed() {
        global $wpdb; $uid = get_current_user_id();
        if ( ! $uid || isset( self::$seeded[ $uid ] ) ) { return; }
        $failed = false;
        foreach ( array( 'checking' => 'Conta corrente', 'savings' => 'Poupança', 'cash' => 'Dinheiro / carteira' ) as $code => $name ) {
            $ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_fin_account_types (user_id,code,name,slug,active,created_at) VALUES (%d,%s,%s,%s,1,%s)", $uid, $code, $name, sanitize_title( $name ), current_time( 'mysql', true ) ) );
            $failed = $failed || false === $ok;
        }
        if ( ! $failed ) { self::$seeded[ $uid ] = true; }
    }

    public static function listing() {
        global $wpdb; self::seed();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id,t.code,t.name,t.active,COUNT(a.id) AS account_count FROM {$wpdb->prefix}pds_fin_account_types t LEFT JOIN {$wpdb->prefix}pds_fin_accounts a ON a.user_id=t.user_id AND a.kind=t.code WHERE t.user_id=%d GROUP BY t.id,t.code,t.name,t.active ORDER BY t.active DESC,t.name,t.id", get_current_user_id() ), ARRAY_A );
        foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['active'] = (bool) $row['active']; $row['account_count'] = (int) $row['account_count']; } unset( $row );
        return array( 'account_types' => $rows );
    }

    private static function find( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_account_types WHERE id=%d AND user_id=%d", $id, get_current_user_id() ), ARRAY_A );
        return $row ?: PDS_Finance::error( 'Tipo de conta não encontrado ou sem acesso.', 404 );
    }

    /** Called inside the account transaction; archiving a type cannot race with assignment. */
    public static function validate( $code, $old_code = '' ) {
        global $wpdb;
        if ( ! is_string( $code ) || ! preg_match( '/^[a-z0-9_]{1,16}$/D', $code ) ) { return PDS_Finance::error( 'Escolha um tipo de conta cadastrado.' ); }
        $type = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM {$wpdb->prefix}pds_fin_account_types WHERE user_id=%d AND code=%s FOR UPDATE", get_current_user_id(), $code ), ARRAY_A );
        if ( $wpdb->last_error ) { return PDS_Finance::error( 'Não foi possível conferir o tipo de conta.', 500 ); }
        if ( ! $type ) { return PDS_Finance::error( 'Tipo de conta não encontrado ou sem acesso.', 404 ); }
        return ! $type['active'] && $code !== $old_code ? PDS_Finance::error( 'Escolha um tipo de conta ativo.', 409 ) : true;
    }

    public static function save( WP_REST_Request $r ) {
        global $wpdb; self::seed(); $uid = get_current_user_id(); $id = absint( $r->get_url_params()['id'] ?? 0 );
        $old = $id ? self::find( $id ) : array(); if ( is_wp_error( $old ) ) { return $old; }
        $name = $r->get_param( 'name' ) ?? ( $old['name'] ?? '' ); $active = $r->get_param( 'active' );
        if ( ! is_string( $name ) || ( null !== $active && ! is_bool( $active ) ) ) { return PDS_Finance::error( 'Informe um nome e uma situação válidos.' ); }
        $name = sanitize_text_field( trim( $name ) );
        if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 ) { return PDS_Finance::error( 'Use um nome de tipo com 2 a 80 caracteres.' ); }
        $slug = substr( sanitize_title( $name ), 0, 170 ); if ( ! $slug ) { $slug = hash( 'sha256', $name ); }
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_account_types WHERE user_id=%d AND slug=%s AND id<>%d", $uid, $slug, $id ) ) ) { return PDS_Finance::error( 'Você já tem esse tipo de conta. Se estiver arquivado, reative-o.', 409 ); }
        if ( ! $id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_account_types WHERE user_id=%d", $uid ) ) >= 100 ) { return PDS_Finance::error( 'Limite de 100 tipos de conta por usuário.' ); }
        $data = array( 'name' => $name, 'slug' => $slug, 'active' => null === $active ? (int) ( $old['active'] ?? 1 ) : (int) $active );
        $code = $old['code'] ?? ( 'type_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 11 ) );
        $ok = $id ? $wpdb->update( $wpdb->prefix . 'pds_fin_account_types', $data, array( 'id' => $id, 'user_id' => $uid ) ) : $wpdb->insert( $wpdb->prefix . 'pds_fin_account_types', $data + array( 'code' => $code, 'user_id' => $uid, 'created_at' => current_time( 'mysql', true ) ) );
        return false === $ok ? PDS_Finance::error( 'Não foi possível salvar o tipo. Confira se o nome já está cadastrado.', 409 ) : array( 'id' => $id ?: (int) $wpdb->insert_id, 'code' => $code );
    }

    public static function archive( WP_REST_Request $r ) {
        global $wpdb; $old = self::find( absint( $r->get_url_params()['id'] ?? 0 ) ); if ( is_wp_error( $old ) ) { return $old; }
        $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_account_types', array( 'active' => 0 ), array( 'id' => $old['id'], 'user_id' => get_current_user_id() ) );
        return false === $ok ? PDS_Finance::error( 'Não foi possível arquivar o tipo.', 500 ) : array( 'success' => true );
    }
}
