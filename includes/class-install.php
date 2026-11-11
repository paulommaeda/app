<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Install {
    public static function activate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
            wp_die( 'Ative o Portal de Serviços individualmente no site desejado, em vez de ativá-lo para toda a rede.' );
        }
        $result = self::schema();
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        add_role( 'pds_member', 'Cliente do portal', array( 'read' => true ) );
        if ( ! get_option( 'pds_settings' ) ) {
            add_option( 'pds_settings', array(
                'name' => 'Meu Portal', 'registration' => 1, 'standalone' => 1,
                'disabled_services' => array(), 'page_id' => 0,
            ), '', false );
        }
        $settings = get_option( 'pds_settings', array() );
        if ( empty( $settings['page_id'] ) || ! get_post( $settings['page_id'] ) || 'trash' === get_post_status( $settings['page_id'] ) ) {
            $id = wp_insert_post( array(
                'post_type' => 'page', 'post_status' => 'publish',
                'post_title' => 'Portal de Serviços', 'post_name' => 'portal',
                'post_content' => '[portal_servicos]', 'comment_status' => 'closed',
            ) );
            if ( ! is_wp_error( $id ) && $id ) {
                $settings['page_id'] = $id;
                update_option( 'pds_settings', $settings, false );
            }
        }
        if ( ! wp_next_scheduled( 'pds_cleanup_limits' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pds_cleanup_limits' );
        }
    }

    public static function schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$prefix}pds_vehicles (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            plate varchar(12) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_id (owner_user_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$prefix}pds_vehicle_shares (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vehicle_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'aceito',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY vehicle_user (vehicle_id,user_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $collate;" );
        // Convert the 1.0 unique index before dbDelta compares it with the new ordinary key.
        $fuel_table = $prefix . 'pds_fuel_records';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $fuel_table ) ) ) === $fuel_table ) {
            $index = $wpdb->get_row( "SHOW INDEX FROM {$prefix}pds_fuel_records WHERE Key_name = 'vehicle_odometer'", ARRAY_A );
            if ( $index && ! (int) $index['Non_unique'] ) {
                $wpdb->query( "ALTER TABLE {$prefix}pds_fuel_records DROP INDEX vehicle_odometer, ADD KEY vehicle_odometer (vehicle_id,odometer)" );
            }
        }
        dbDelta( "CREATE TABLE {$prefix}pds_fuel_records (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vehicle_id bigint(20) unsigned NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            filled_on date NOT NULL,
            odometer decimal(12,2) NOT NULL,
            fuel_type char(1) NOT NULL,
            price decimal(10,3) NOT NULL,
            liters decimal(10,3) NOT NULL,
            paid decimal(12,2) NOT NULL,
            full_tank tinyint(1) NOT NULL DEFAULT 1,
            notes varchar(500) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY vehicle_odometer (vehicle_id,odometer),
            KEY vehicle_date (vehicle_id,filled_on),
            KEY created_by (created_by)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$prefix}pds_rate_limits (
            limit_key varchar(64) NOT NULL,
            hits int unsigned NOT NULL DEFAULT 1,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (limit_key),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$prefix}pds_import_map (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key varchar(64) NOT NULL,
            entity varchar(20) NOT NULL,
            legacy_id bigint(20) unsigned NOT NULL,
            target_id bigint(20) unsigned NOT NULL,
            imported_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_entity (source_key,entity,legacy_id),
            KEY target_entity (target_id,entity)
        ) ENGINE=InnoDB $collate;" );
        foreach ( array( 'pds_vehicles', 'pds_vehicle_shares', 'pds_fuel_records', 'pds_rate_limits', 'pds_import_map' ) as $table ) {
            $name = $prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
                return new WP_Error( 'pds_schema', 'Não foi possível criar as tabelas do portal. Verifique as permissões do banco de dados e reative o plugin.' );
            }
        }
        update_option( 'pds_db_version', PDS_VERSION, false );
        return true;
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'pds_cleanup_limits' );
    }
}
