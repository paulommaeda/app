<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Fuel {
    public static function boot() {
        add_action( 'pds_register_services', static function () {
            pds_register_service( 'combustivel', array(
                'title' => 'Meu Combustível', 'description' => 'Seu veículo, seus gastos e cada quilômetro sob controle.',
                'icon' => 'fuel', 'order' => 10,
                'render_callback' => static function () { include PDS_DIR . 'templates/fuel.php'; },
            ) );
        } );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() {
        $routes = array(
            '/fuel/vehicles' => array( 'GET' => 'vehicles', 'POST' => 'save_vehicle' ),
            '/fuel/vehicles/(?P<id>\d+)' => array( 'PUT' => 'save_vehicle', 'DELETE' => 'delete_vehicle' ),
            '/fuel/vehicles/(?P<id>\d+)/shares' => array( 'GET' => 'shares', 'POST' => 'add_share' ),
            '/fuel/vehicles/(?P<id>\d+)/shares/(?P<user_id>\d+)' => array( 'DELETE' => 'remove_share' ),
            '/fuel/vehicles/(?P<id>\d+)/report' => array( 'GET' => 'report' ),
            '/fuel/vehicles/(?P<id>\d+)/records' => array( 'GET' => 'records', 'POST' => 'save_record' ),
            '/fuel/records/(?P<record_id>\d+)' => array( 'PUT' => 'save_record', 'DELETE' => 'delete_record' ),
        );
        foreach ( $routes as $route => $methods ) {
            $endpoints = array();
            foreach ( $methods as $method => $callback ) {
                $endpoints[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => array( __CLASS__, 'permission' ) );
            }
            register_rest_route( 'pds/v1', $route, $endpoints );
        }
    }

    public static function error( $message, $status = 400 ) { return new WP_Error( 'pds_fuel', $message, array( 'status' => $status ) ); }

    public static function permission() {
        if ( ! is_user_logged_in() ) { return self::error( 'Entre na sua conta para continuar.', 401 ); }
        return pds_user_can_access_service( 'combustivel' ) ? true : self::error( 'O acesso a este serviço não está liberado para sua conta.', 403 );
    }

    public static function vehicle( $id, $owner_only = false ) {
        global $wpdb;
        $uid = get_current_user_id();
        $vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_vehicles WHERE id = %d", $id ), ARRAY_A );
        if ( ! $vehicle ) { return self::error( 'Veículo não encontrado ou sem acesso.', 404 ); }
        $is_owner = (int) $vehicle['owner_user_id'] === $uid;
        $shared = ! $owner_only && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicle_shares WHERE vehicle_id = %d AND user_id = %d AND status = 'aceito'", $id, $uid ) );
        if ( ! $is_owner && ! $shared ) { return self::error( 'Veículo não encontrado ou sem acesso.', 404 ); }
        $vehicle['id'] = (int) $vehicle['id'];
        $vehicle['owner_user_id'] = (int) $vehicle['owner_user_id'];
        $vehicle['is_owner'] = $is_owner;
        return $vehicle;
    }

    public static function vehicles() {
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.* FROM {$wpdb->prefix}pds_vehicles v WHERE v.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$wpdb->prefix}pds_vehicle_shares s WHERE s.vehicle_id = v.id AND s.user_id = %d AND s.status = 'aceito') ORDER BY v.name, v.id", $uid, $uid
        ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['id'] = (int) $row['id']; $row['owner_user_id'] = (int) $row['owner_user_id'];
            $row['is_owner'] = $row['owner_user_id'] === $uid;
        }
        return array( 'vehicles' => $rows );
    }

    public static function save_vehicle( WP_REST_Request $request ) {
        global $wpdb;
        $id = absint( $request['id'] );
        if ( $id ) { $vehicle = self::vehicle( $id, true ); if ( is_wp_error( $vehicle ) ) { return $vehicle; } }
        $name = sanitize_text_field( PDS_Auth::field( $request, 'name' ) );
        $plate = strtoupper( sanitize_text_field( PDS_Auth::field( $request, 'plate' ) ) );
        if ( ! $name || mb_strlen( $name ) > 150 || strlen( $plate ) > 12 ) { return self::error( 'Informe um nome de até 150 caracteres e uma placa de até 12 caracteres.' ); }
        $data = array( 'name' => $name, 'plate' => $plate );
        if ( $id ) {
            $result = $wpdb->update( $wpdb->prefix . 'pds_vehicles', $data, array( 'id' => $id, 'owner_user_id' => get_current_user_id() ) );
        } else {
            $data['owner_user_id'] = get_current_user_id(); $data['created_at'] = current_time( 'mysql', true );
            $result = $wpdb->insert( $wpdb->prefix . 'pds_vehicles', $data );
            $id = (int) $wpdb->insert_id;
        }
        return false === $result ? self::error( 'Não foi possível salvar o veículo.', 500 ) : array( 'success' => true, 'id' => $id );
    }

    public static function delete_vehicle( WP_REST_Request $request ) {
        global $wpdb;
        $vehicle = self::vehicle( absint( $request['id'] ), true );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $wpdb->query( 'START TRANSACTION' );
        $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicles WHERE id = %d FOR UPDATE", $vehicle['id'] ) );
        $vehicle = self::vehicle( $vehicle['id'], true );
        if ( is_wp_error( $vehicle ) ) { $wpdb->query( 'ROLLBACK' ); return $vehicle; }
        $records = self::raw_records( $vehicle['id'] );
        $r1 = $wpdb->delete( $wpdb->prefix . 'pds_fuel_records', array( 'vehicle_id' => $vehicle['id'] ) );
        $r2 = $wpdb->delete( $wpdb->prefix . 'pds_vehicle_shares', array( 'vehicle_id' => $vehicle['id'] ) );
        $r3 = $wpdb->delete( $wpdb->prefix . 'pds_vehicles', array( 'id' => $vehicle['id'], 'owner_user_id' => get_current_user_id() ) );
        if ( false === $r1 || false === $r2 || ! $r3 ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível excluir o veículo.', 500 ); }
        $wpdb->query( 'COMMIT' );
        foreach ( $records as $record ) { self::event( 'deleted', $record, $vehicle ); }
        do_action( 'pds_fuel_vehicle_deleted', $vehicle );
        return array( 'success' => true );
    }

    public static function shares( WP_REST_Request $request ) {
        global $wpdb;
        $vehicle = self::vehicle( absint( $request['id'] ), true );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.user_id, s.status, u.display_name AS name, u.user_email AS email FROM {$wpdb->prefix}pds_vehicle_shares s INNER JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE s.vehicle_id = %d ORDER BY u.display_name", $vehicle['id'] ), ARRAY_A );
        return array( 'shares' => $rows );
    }

    public static function add_share( WP_REST_Request $request ) {
        global $wpdb;
        $vehicle = self::vehicle( absint( $request['id'] ), true );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $email = sanitize_email( PDS_Auth::field( $request, 'email' ) );
        $user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
        if ( ! $user || ! pds_user_can_access_service( 'combustivel', $user->ID ) ) { return self::error( 'Peça à pessoa para se cadastrar no portal com este e-mail e ter acesso ao serviço.' ); }
        if ( $user->ID === get_current_user_id() ) { return self::error( 'Você já é o proprietário deste veículo.' ); }
        $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}pds_vehicle_shares (vehicle_id,user_id,status,created_at) VALUES (%d,%d,'aceito',%s) ON DUPLICATE KEY UPDATE status = 'aceito'", $vehicle['id'], $user->ID, current_time( 'mysql', true ) ) );
        return false === $result ? self::error( 'Não foi possível compartilhar o veículo.', 500 ) : array( 'success' => true );
    }

    public static function remove_share( WP_REST_Request $request ) {
        global $wpdb;
        $vehicle = self::vehicle( absint( $request['id'] ), true );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $result = $wpdb->delete( $wpdb->prefix . 'pds_vehicle_shares', array( 'vehicle_id' => $vehicle['id'], 'user_id' => absint( $request['user_id'] ) ) );
        return false === $result ? self::error( 'Não foi possível remover o acesso.', 500 ) : array( 'success' => true );
    }

    public static function raw_records( $vehicle_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fuel_records WHERE vehicle_id = %d ORDER BY odometer ASC, id ASC", $vehicle_id ), ARRAY_A );
        return array_map( array( __CLASS__, 'cast_record' ), $rows );
    }

    public static function cast_record( $row ) {
        foreach ( array( 'id', 'vehicle_id', 'created_by' ) as $key ) { $row[ $key ] = (int) $row[ $key ]; }
        foreach ( array( 'odometer', 'price', 'liters', 'paid' ) as $key ) { $row[ $key ] = (float) $row[ $key ]; }
        $row['full_tank'] = (bool) $row['full_tank'];
        return $row;
    }

    /** Full-to-full calculation, including all partial fills between two full tanks. */
    public static function calculate( array $rows ) {
        $previous = null; $anchor = null; $pending_liters = 0; $pending_paid = 0;
        foreach ( $rows as &$row ) {
            $row['distance'] = $previous ? max( 0, $row['odometer'] - $previous['odometer'] ) : 0;
            $row['table_total'] = round( $row['price'] * $row['liters'], 2 );
            $row['discount'] = max( 0, round( $row['table_total'] - $row['paid'], 2 ) );
            $row['surcharge'] = max( 0, round( $row['paid'] - $row['table_total'], 2 ) );
            $row['effective_price'] = $row['paid'] / $row['liters'];
            $row['consumption'] = null; $row['cost_per_km'] = null;
            $row['measured_distance'] = 0; $row['interval_liters'] = 0; $row['interval_paid'] = 0;
            if ( $anchor !== null ) { $pending_liters += $row['liters']; $pending_paid += $row['paid']; }
            if ( $row['full_tank'] ) {
                if ( $anchor !== null && $row['odometer'] > $anchor && $pending_liters > 0 ) {
                    $row['measured_distance'] = $row['odometer'] - $anchor;
                    $row['interval_liters'] = $pending_liters; $row['interval_paid'] = $pending_paid;
                    $row['consumption'] = $row['measured_distance'] / $pending_liters;
                    $row['cost_per_km'] = $pending_paid / $row['measured_distance'];
                }
                $anchor = $row['odometer']; $pending_liters = 0; $pending_paid = 0;
            }
            $previous = $row;
        }
        unset( $row );
        return $rows;
    }

    private static function filtered( WP_REST_Request $request, $id ) {
        $from = PDS_Auth::field( $request, 'from' ); $to = PDS_Auth::field( $request, 'to' );
        if ( ( $from && ! self::valid_date( $from ) ) || ( $to && ! self::valid_date( $to ) ) || ( $from && $to && $from > $to ) ) {
            return self::error( 'Informe um período válido. A data inicial deve ser anterior à final.' );
        }
        $rows = self::calculate( self::raw_records( $id ) );
        return array_values( array_filter( $rows, static function ( $row ) use ( $from, $to ) {
            return ( ! $from || $row['filled_on'] >= $from ) && ( ! $to || $row['filled_on'] <= $to );
        } ) );
    }

    public static function report( WP_REST_Request $request ) {
        $vehicle = self::vehicle( absint( $request['id'] ) );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $rows = self::filtered( $request, $vehicle['id'] );
        if ( is_wp_error( $rows ) ) { return $rows; }
        $totals = array( 'spent' => 0, 'liters' => 0, 'distance' => 0, 'discount' => 0, 'surcharge' => 0, 'consumption' => null, 'cost_per_km' => null, 'count' => count( $rows ) );
        $measured = 0; $measured_liters = 0; $measured_paid = 0; $monthly = array(); $daily = array(); $evolution = array();
        foreach ( $rows as $row ) {
            $totals['spent'] += $row['paid']; $totals['liters'] += $row['liters'];
            $totals['distance'] += $row['distance']; $totals['discount'] += $row['discount']; $totals['surcharge'] += $row['surcharge'];
            $measured += $row['measured_distance']; $measured_liters += $row['interval_liters']; $measured_paid += $row['interval_paid'];
            $month = substr( $row['filled_on'], 0, 7 );
            $monthly[ $month ] = ( $monthly[ $month ] ?? 0 ) + $row['paid'];
            $daily[ $row['filled_on'] ] = ( $daily[ $row['filled_on'] ] ?? 0 ) + $row['paid'];
            if ( null !== $row['consumption'] ) { $evolution[] = array( 'date' => $row['filled_on'], 'consumption' => $row['consumption'], 'cost_per_km' => $row['cost_per_km'] ); }
        }
        ksort( $monthly ); ksort( $daily );
        $totals['consumption'] = $measured_liters > 0 ? $measured / $measured_liters : null;
        $totals['cost_per_km'] = $measured > 0 ? $measured_paid / $measured : null;
        $totals['spent'] = round( $totals['spent'], 2 ); $totals['discount'] = round( $totals['discount'], 2 );
        return array( 'summary' => $totals, 'monthly' => $monthly, 'daily' => $daily, 'evolution' => $evolution, 'recent' => array_slice( array_reverse( $rows ), 0, 3 ) );
    }

    public static function records( WP_REST_Request $request ) {
        $vehicle = self::vehicle( absint( $request['id'] ) );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        $rows = self::filtered( $request, $vehicle['id'] );
        if ( is_wp_error( $rows ) ) { return $rows; }
        $page = max( 1, absint( $request['page'] ?? 1 ) ); $per_page = min( 100, max( 1, absint( $request['per_page'] ?? 12 ) ) );
        $total = count( $rows );
        $rows = array_slice( array_reverse( $rows ), ( $page - 1 ) * $per_page, $per_page );
        foreach ( $rows as &$row ) { $row['can_modify'] = $vehicle['is_owner'] || $row['created_by'] === get_current_user_id(); }
        return array( 'records' => $rows, 'total' => $total, 'pages' => (int) ceil( $total / $per_page ), 'page' => $page );
    }

    private static function valid_date( $date ) {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) { return false; }
        return (int) $m[1] >= 1900 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    private static function existing_record( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fuel_records WHERE id = %d", $id ), ARRAY_A );
        return $row ? self::cast_record( $row ) : self::error( 'Abastecimento não encontrado.', 404 );
    }

    public static function save_record( WP_REST_Request $request, $external_ref = null ) {
        global $wpdb;
        if ( null !== $external_ref && ( ! is_string( $external_ref ) || ! preg_match( '/^telegram:[a-f0-9]{64}$/D', $external_ref ) ) ) { return self::error( 'Origem de lançamento inválida.' ); }
        $record_id = absint( $request['record_id'] ); $old = null;
        if ( $record_id ) {
            $old = self::existing_record( $record_id );
            if ( is_wp_error( $old ) ) { return $old; }
            $vehicle = self::vehicle( $old['vehicle_id'] );
        } else { $vehicle = self::vehicle( absint( $request['id'] ) ); }
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        if ( $old && ! $vehicle['is_owner'] && $old['created_by'] !== get_current_user_id() ) { return self::error( 'Apenas o proprietário ou quem registrou pode alterar este abastecimento.', 403 ); }
        $date = PDS_Auth::field( $request, 'filled_on' ); $fuel = PDS_Auth::field( $request, 'fuel_type' );
        if ( ! self::valid_date( $date ) || $date > current_time( 'Y-m-d' ) || ! in_array( $fuel, array( 'G', 'A', 'D' ), true ) ) { return self::error( 'Confira a data (sem datas futuras) e o combustível.' ); }
        $data = array( 'vehicle_id' => $vehicle['id'], 'filled_on' => $date, 'fuel_type' => $fuel );
        $bounds = array( 'odometer' => array( 0, 9999999999.99, 2 ), 'price' => array( 0.001, 9999999.999, 3 ), 'liters' => array( 0.001, 9999999.999, 3 ), 'paid' => array( 0.01, 9999999999.99, 2 ) );
        foreach ( $bounds as $key => $bound ) {
            $value = $request->get_param( $key );
            if ( ! is_scalar( $value ) || is_bool( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < $bound[0] || (float) $value > $bound[1] ) { return self::error( 'Informe quilometragem, preço, litros e valor pago válidos.' ); }
            $data[ $key ] = round( (float) $value, $bound[2] );
        }
        $full_tank = $request->get_param( 'full_tank' );
        if ( ! in_array( $full_tank, array( true, false, 0, 1, '0', '1' ), true ) ) { return self::error( 'Informe se completou o tanque.' ); }
        $data['full_tank'] = (int) (bool) $full_tank;
        $data['notes'] = sanitize_textarea_field( PDS_Auth::field( $request, 'notes' ) );
        if ( mb_strlen( $data['notes'] ) > 500 ) { return self::error( 'Use até 500 caracteres nas observações.' ); }
        // Serialize writes for the same vehicle so concurrent entries cannot invert the odometer sequence.
        $wpdb->query( 'START TRANSACTION' );
        $locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicles WHERE id = %d FOR UPDATE", $vehicle['id'] ) );
        if ( ! $locked ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Veículo indisponível.', 404 ); }
        $vehicle = self::vehicle( $vehicle['id'] );
        if ( is_wp_error( $vehicle ) ) { $wpdb->query( 'ROLLBACK' ); return $vehicle; }
        if ( ! $record_id && $external_ref ) {
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, vehicle_id, created_by FROM {$wpdb->prefix}pds_fuel_records WHERE external_ref = %s", $external_ref ), ARRAY_A );
            if ( $existing ) {
                $wpdb->query( 'ROLLBACK' );
                return (int) $existing['vehicle_id'] === $vehicle['id'] && (int) $existing['created_by'] === get_current_user_id() ? array( 'success' => true, 'id' => (int) $existing['id'], 'already_saved' => true ) : self::error( 'Este lançamento já foi processado.', 409 );
            }
            $data['external_ref'] = $external_ref;
        }
        if ( $record_id ) {
            $old = self::existing_record( $record_id );
            if ( is_wp_error( $old ) ) { $wpdb->query( 'ROLLBACK' ); return $old; }
        }
        $unchanged_position = $old && $old['odometer'] === $data['odometer'] && $old['filled_on'] === $date;
        $conflict = $unchanged_position ? false : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fuel_records WHERE vehicle_id = %d AND id <> %d AND (odometer = %f OR (filled_on < %s AND odometer > %f) OR (filled_on > %s AND odometer < %f)) LIMIT 1", $vehicle['id'], $record_id, $data['odometer'], $date, $data['odometer'], $date, $data['odometer'] ) );
        if ( $conflict ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'A quilometragem já foi usada ou está fora da sequência das datas. Confira o histórico.' ); }
        $data['updated_at'] = current_time( 'mysql', true );
        if ( $old ) { $result = $wpdb->update( $wpdb->prefix . 'pds_fuel_records', $data, array( 'id' => $record_id ) ); }
        else {
            $data['created_by'] = get_current_user_id(); $data['created_at'] = current_time( 'mysql', true );
            $result = $wpdb->insert( $wpdb->prefix . 'pds_fuel_records', $data ); $record_id = (int) $wpdb->insert_id;
        }
        if ( false === $result ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível salvar. Confira se a quilometragem já foi registrada e tente novamente.', 409 ); }
        $wpdb->query( 'COMMIT' );
        $record = self::existing_record( $record_id );
        self::event( $old ? 'updated' : 'created', $record, $vehicle, $old );
        return array( 'success' => true, 'id' => $record_id );
    }

    public static function delete_record( WP_REST_Request $request ) {
        global $wpdb;
        $record = self::existing_record( absint( $request['record_id'] ) );
        if ( is_wp_error( $record ) ) { return $record; }
        $vehicle = self::vehicle( $record['vehicle_id'] );
        if ( is_wp_error( $vehicle ) ) { return $vehicle; }
        if ( ! $vehicle['is_owner'] && $record['created_by'] !== get_current_user_id() ) { return self::error( 'Apenas o proprietário ou quem registrou pode excluir este abastecimento.', 403 ); }
        $wpdb->query( 'START TRANSACTION' );
        $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicles WHERE id = %d FOR UPDATE", $vehicle['id'] ) );
        $vehicle = self::vehicle( $record['vehicle_id'] );
        if ( is_wp_error( $vehicle ) ) { $wpdb->query( 'ROLLBACK' ); return $vehicle; }
        $result = $wpdb->delete( $wpdb->prefix . 'pds_fuel_records', array( 'id' => $record['id'] ) );
        if ( ! $result ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível excluir o abastecimento.', 500 ); }
        $wpdb->query( 'COMMIT' );
        self::event( 'deleted', $record, $vehicle );
        return array( 'success' => true );
    }

    public static function event( $event, $record, $vehicle, $previous = null ) {
        $payload = array_merge( $record, array(
            'owner_user_id' => (int) $vehicle['owner_user_id'], 'vehicle_name' => $vehicle['name'],
            'source' => 'pds_combustivel:' . $record['id'], 'currency' => 'BRL',
        ) );
        do_action( 'pds_fuel_record_' . $event, $payload, $previous );
    }
}
