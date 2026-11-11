<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Import {
    const PHASES = array( 'users', 'vehicles', 'shares', 'records' );
    const LABELS = array( 'new_users' => 'Contas WordPress criadas', 'linked_users' => 'Contas existentes vinculadas', 'vehicles' => 'Veículos importados', 'shares' => 'Compartilhamentos importados', 'records' => 'Abastecimentos importados', 'unchanged' => 'Registros já importados', 'skipped' => 'Registros removidos no destino ou redundantes', 'needs_reset' => 'Contas novas que precisam recuperar a senha' );

    public static function boot() {
        add_action( 'admin_menu', static function () { add_submenu_page( 'pds-portal', 'Importar app antigo', 'Importar app antigo', 'manage_options', 'pds-import', array( __CLASS__, 'page' ) ); } );
        add_action( 'admin_post_pds_import_preview', array( __CLASS__, 'preview' ) );
        add_action( 'admin_post_pds_import_cancel', array( __CLASS__, 'cancel' ) );
        add_action( 'wp_ajax_pds_import_batch', array( __CLASS__, 'ajax_batch' ) );
        add_action( 'admin_enqueue_scripts', static function () {
            if ( isset( $_GET['page'] ) && 'pds-import' === $_GET['page'] ) { wp_enqueue_script( 'pds-import', PDS_URL . 'assets/import.js', array(), PDS_VERSION, true ); }
        } );
        add_action( 'after_password_reset', static function ( $user ) { delete_user_meta( $user->ID, 'pds_import_password_reset' ); } );
    }

    private static function key( $job ) {
        if ( ! is_string( $job ) || ! preg_match( '/^[a-f0-9]{32}$/D', $job ) ) { return ''; }
        return 'pds_import_job_' . $job;
    }

    public static function state( $job ) {
        $key = self::key( $job ); $state = $key ? get_transient( $key ) : false;
        return is_array( $state ) && (int) $state['owner'] === get_current_user_id() ? $state : false;
    }

    public static function prepare( $data, $source ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'pds_import_permission', 'Sem permissão para importar.', array( 'status' => 403 ) ); }
        if ( ! is_string( $source ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $source ) ) { return new WP_Error( 'pds_import_source', 'Use um identificador de origem com até 64 letras minúsculas, números, hífen ou sublinhado.' ); }
        foreach ( self::PHASES as $phase ) { if ( ! isset( $data[ $phase ] ) || ! is_array( $data[ $phase ] ) ) { return new WP_Error( 'pds_import_data', 'Dados de importação incompletos.' ); } }
        $owner = get_current_user_id(); $old = get_user_meta( $owner, 'pds_import_active_job', true );
        if ( $old && self::key( $old ) ) { delete_transient( self::key( $old ) ); }
        $job = bin2hex( random_bytes( 16 ) ); $sizes = array();
        foreach ( self::PHASES as $phase ) { $sizes[ $phase ] = count( $data[ $phase ] ); }
        $state = array(
            'owner' => $owner, 'source' => $source, 'data' => $data,
            'sizes' => $sizes, 'total' => array_sum( $sizes ), 'processed' => 0,
            'phase' => 0, 'offset' => 0, 'done' => false, 'error' => '',
            'warnings' => $data['warnings'] ?? array(), 'counts' => array_fill_keys( array_keys( self::LABELS ), 0 ),
        );
        if ( ! set_transient( self::key( $job ), $state, HOUR_IN_SECONDS ) ) { return new WP_Error( 'pds_import_storage', 'Não foi possível preparar a importação no banco do WordPress.' ); }
        update_user_meta( $owner, 'pds_import_active_job', $job );
        return $job;
    }

    public static function preview() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissão.' ); }
        check_admin_referer( 'pds_import_preview' );
        wp_raise_memory_limit( 'admin' );
        try {
            $type = isset( $_POST['source_type'] ) && is_string( $_POST['source_type'] ) ? $_POST['source_type'] : '';
            $prefix = isset( $_POST['table_prefix'] ) && is_string( $_POST['table_prefix'] ) ? wp_unslash( $_POST['table_prefix'] ) : '';
            $source = isset( $_POST['source_key'] ) && is_string( $_POST['source_key'] ) ? wp_unslash( $_POST['source_key'] ) : '';
            $options = array( 'full_tank' => ! empty( $_POST['full_tank'] ), 'missing_paid' => ! empty( $_POST['missing_paid'] ) );
            if ( 'database' === $type ) { $data = PDS_Legacy_Reader::database( $prefix, $options ); }
            elseif ( 'file' === $type ) {
                $file = $_FILES['legacy_file'] ?? array();
                if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { throw new InvalidArgumentException( 'Selecione um backup SQL/JSON válido de até 10 MB e confira o limite de upload da hospedagem.' ); }
                $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
                $data = PDS_Legacy_Reader::file( $file['tmp_name'], $extension, $prefix, $options );
            } else { throw new InvalidArgumentException( 'Selecione a origem dos dados antigos.' ); }
            $job = self::prepare( $data, $source );
            if ( is_wp_error( $job ) ) { throw new RuntimeException( $job->get_error_message() ); }
            wp_safe_redirect( add_query_arg( 'job', $job, admin_url( 'admin.php?page=pds-import' ) ) ); exit;
        } catch ( Throwable $error ) {
            set_transient( 'pds_import_message_' . get_current_user_id(), sanitize_text_field( $error->getMessage() ), MINUTE_IN_SECONDS );
            wp_safe_redirect( admin_url( 'admin.php?page=pds-import' ) ); exit;
        }
    }

    public static function cancel() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissão.' ); }
        $job = isset( $_POST['job'] ) && is_string( $_POST['job'] ) ? $_POST['job'] : '';
        check_admin_referer( 'pds_import_cancel_' . $job );
        if ( self::state( $job ) ) { delete_transient( self::key( $job ) ); delete_user_meta( get_current_user_id(), 'pds_import_active_job' ); }
        wp_safe_redirect( admin_url( 'admin.php?page=pds-import' ) ); exit;
    }

    private static function lock() {
        global $wpdb;
        $lock = array( 'token' => bin2hex( random_bytes( 12 ) ), 'expires' => time() + 300 );
        $old = get_option( 'pds_import_mutex' );
        if ( is_array( $old ) && (int) $old['expires'] < time() ) {
            $wpdb->delete( $wpdb->options, array( 'option_name' => 'pds_import_mutex', 'option_value' => maybe_serialize( $old ) ) );
            wp_cache_delete( 'pds_import_mutex', 'options' ); wp_cache_delete( 'notoptions', 'options' );
        }
        return add_option( 'pds_import_mutex', $lock, '', false ) ? $lock : false;
    }

    private static function unlock( $lock ) {
        global $wpdb;
        $wpdb->delete( $wpdb->options, array( 'option_name' => 'pds_import_mutex', 'option_value' => maybe_serialize( $lock ) ) );
        wp_cache_delete( 'pds_import_mutex', 'options' ); wp_cache_delete( 'notoptions', 'options' );
    }

    private static function mapped( $source, $entity, $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT target_id FROM {$wpdb->prefix}pds_import_map WHERE source_key = %s AND entity = %s AND legacy_id = %d", $source, $entity, $id ), ARRAY_A );
    }

    private static function target( $source, $entity, $id ) {
        global $wpdb;
        $map = self::mapped( $source, $entity, $id );
        if ( ! $map || ! $map['target_id'] ) { return 0; }
        $target = (int) $map['target_id'];
        if ( 'users' === $entity ) { return get_userdata( $target ) ? $target : 0; }
        $table = array( 'vehicles' => 'pds_vehicles', 'shares' => 'pds_vehicle_shares', 'records' => 'pds_fuel_records' )[ $entity ];
        return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}{$table} WHERE id = %d", $target ) ) ? $target : 0;
    }

    private static function import_row( $source, $entity, $row, &$counts ) {
        global $wpdb;
        $existing = self::mapped( $source, $entity, $row['id'] );
        if ( $existing ) { $counts[ self::target( $source, $entity, $row['id'] ) ? 'unchanged' : 'skipped' ]++; return; }
        $wpdb->query( 'START TRANSACTION' ); $new_user = 0; $event = null; $counter = $entity;
        try {
            if ( 'users' === $entity ) {
                $user = get_user_by( 'email', $row['email'] );
                if ( $user ) { $target = $user->ID; $counter = 'linked_users'; }
                else {
                    $login = 'pds_' . substr( hash( 'sha256', $source ), 0, 8 ) . '_' . $row['id'];
                    if ( username_exists( $login ) ) { $login .= '_' . wp_generate_password( 6, false, false ); }
                    $target = wp_insert_user( array( 'user_login' => $login, 'user_email' => $row['email'], 'user_pass' => wp_generate_password( 40, true, true ), 'display_name' => $row['name'], 'first_name' => $row['name'], 'user_registered' => $row['created_at'], 'role' => 'pds_member' ) );
                    if ( is_wp_error( $target ) ) { throw new RuntimeException( 'Não foi possível criar a conta do usuário antigo ID ' . $row['id'] . '.' ); }
                    $new_user = $target; $counter = 'new_users';
                    if ( $row['hash'] ) {
                        if ( false === $wpdb->update( $wpdb->users, array( 'user_pass' => $row['hash'] ), array( 'ID' => $target ) ) ) { throw new RuntimeException( 'Não foi possível preservar a senha do usuário antigo ID ' . $row['id'] . '.' ); }
                        clean_user_cache( $target );
                    } else { update_user_meta( $target, 'pds_import_password_reset', 1 ); }
                }
                update_user_meta( $target, 'pds_member', 1 );
            } elseif ( 'vehicles' === $entity ) {
                $owner = self::target( $source, 'users', $row['owner'] );
                if ( ! $owner ) { $target = 0; $counter = 'skipped'; }
                else {
                    $ok = $wpdb->insert( $wpdb->prefix . 'pds_vehicles', array( 'owner_user_id' => $owner, 'name' => $row['name'], 'plate' => '', 'created_at' => $row['created_at'] ) );
                    if ( ! $ok ) { throw new RuntimeException( 'Não foi possível importar o veículo antigo ID ' . $row['id'] . '.' ); } $target = (int) $wpdb->insert_id;
                }
            } elseif ( 'shares' === $entity ) {
                $vehicle = self::target( $source, 'vehicles', $row['vehicle'] ); $user = self::target( $source, 'users', $row['user'] );
                if ( ! $vehicle || ! $user ) { $target = 0; $counter = 'skipped'; }
                else {
                    $target = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicle_shares WHERE vehicle_id = %d AND user_id = %d", $vehicle, $user ) );
                    if ( $target ) { $counter = 'unchanged'; }
                    else {
                        $ok = $wpdb->insert( $wpdb->prefix . 'pds_vehicle_shares', array( 'vehicle_id' => $vehicle, 'user_id' => $user, 'status' => $row['status'], 'created_at' => $row['created_at'] ) );
                        if ( ! $ok ) { throw new RuntimeException( 'Não foi possível importar o compartilhamento antigo ID ' . $row['id'] . '.' ); } $target = (int) $wpdb->insert_id;
                    }
                }
            } else {
                $vehicle = self::target( $source, 'vehicles', $row['vehicle'] ); $author = self::target( $source, 'users', $row['author'] );
                if ( ! $vehicle || ! $author ) { $target = 0; $counter = 'skipped'; }
                else {
                    $data = array_diff_key( $row, array_fill_keys( array( 'id', 'vehicle', 'author' ), true ) ); $data['vehicle_id'] = $vehicle; $data['created_by'] = $author;
                    if ( ! $wpdb->insert( $wpdb->prefix . 'pds_fuel_records', $data ) ) { throw new RuntimeException( 'Não foi possível importar o abastecimento antigo ID ' . $row['id'] . '.' ); }
                    $target = (int) $wpdb->insert_id;
                    $data['id'] = $target;
                    $event = array( PDS_Fuel::cast_record( $data ), $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_vehicles WHERE id = %d", $vehicle ), ARRAY_A ) );
                }
            }
            if ( ! $wpdb->insert( $wpdb->prefix . 'pds_import_map', array( 'source_key' => $source, 'entity' => $entity, 'legacy_id' => $row['id'], 'target_id' => $target, 'imported_at' => current_time( 'mysql', true ) ) ) ) { throw new RuntimeException( 'Não foi possível salvar o vínculo de importação. Tente novamente.' ); }
            $wpdb->query( 'COMMIT' );
            $counts[ $counter ]++;
            if ( $new_user && ! $row['hash'] ) { $counts['needs_reset']++; }
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            if ( $new_user ) { clean_user_cache( $new_user ); wp_cache_delete( $new_user, 'user_meta' ); }
            throw $error;
        }
        if ( $event ) { PDS_Fuel::event( 'created', $event[0], $event[1] ); }
        if ( $new_user ) { do_action( 'pds_user_imported', $new_user, $source, $row['id'] ); }
    }

    public static function process( $job ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'pds_import_permission', 'Sem permissão para importar.', array( 'status' => 403 ) ); }
        $state = self::state( $job );
        if ( ! $state ) { return new WP_Error( 'pds_import_expired', 'A preparação expirou. Envie o mesmo backup com o mesmo identificador de origem para continuar sem duplicar os registros.', array( 'status' => 410 ) ); }
        if ( $state['done'] ) { return self::public_state( $state ); }
        $lock = self::lock();
        if ( ! $lock ) { return new WP_Error( 'pds_import_busy', 'Outra etapa está em andamento. Aguarde alguns segundos e tente novamente.', array( 'status' => 409 ) ); }
        try {
            $state = self::state( $job );
            if ( ! $state || $state['done'] ) { return $state ? self::public_state( $state ) : new WP_Error( 'pds_import_expired', 'A preparação expirou.', array( 'status' => 410 ) ); }
            $started = microtime( true ); $batch = 0; $state['error'] = '';
            try {
                while ( $state['phase'] < count( self::PHASES ) && $batch < 30 && microtime( true ) - $started < 8 ) {
                    $phase = self::PHASES[ $state['phase'] ];
                    if ( $state['offset'] >= $state['sizes'][ $phase ] ) { $state['data'][ $phase ] = array(); $state['phase']++; $state['offset'] = 0; continue; }
                    self::import_row( $state['source'], $phase, $state['data'][ $phase ][ $state['offset'] ], $state['counts'] );
                    $state['offset']++; $state['processed']++; $batch++;
                }
                if ( $state['phase'] >= count( self::PHASES ) ) { $state['done'] = true; unset( $state['data'] ); delete_user_meta( $state['owner'], 'pds_import_active_job' ); }
            } catch ( Throwable $error ) { $state['error'] = sanitize_text_field( $error->getMessage() ); }
            set_transient( self::key( $job ), $state, $state['done'] ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
            return self::public_state( $state );
        } finally { self::unlock( $lock ); }
    }

    private static function public_state( $state ) {
        return array_intersect_key( $state, array_fill_keys( array( 'source', 'total', 'processed', 'done', 'error', 'counts' ), true ) );
    }

    public static function ajax_batch() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 ); }
        $job = isset( $_POST['job'] ) && is_string( $_POST['job'] ) ? $_POST['job'] : '';
        check_ajax_referer( 'pds_import_' . $job );
        $result = self::process( $job );
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), $result->get_error_data()['status'] ?? 400 ); }
        wp_send_json_success( $result );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $job = isset( $_GET['job'] ) && is_string( $_GET['job'] ) ? $_GET['job'] : get_user_meta( get_current_user_id(), 'pds_import_active_job', true );
        $state = self::state( $job );
        $message = get_transient( 'pds_import_message_' . get_current_user_id() );
        delete_transient( 'pds_import_message_' . get_current_user_id() );
        include PDS_DIR . 'templates/import-admin.php';
    }
}
