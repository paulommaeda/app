<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Privacy {
    public static function boot() {
        add_filter( 'wp_privacy_personal_data_exporters', static function ( $exporters ) {
            $exporters['pds'] = array( 'exporter_friendly_name' => 'Portal de Serviços', 'callback' => array( __CLASS__, 'export' ) ); return $exporters;
        } );
        add_filter( 'wp_privacy_personal_data_erasers', static function ( $erasers ) {
            $erasers['pds'] = array( 'eraser_friendly_name' => 'Portal de Serviços', 'callback' => array( __CLASS__, 'erase' ) ); return $erasers;
        } );
        add_action( 'delete_user', array( __CLASS__, 'delete_user' ) );
        add_action( 'admin_init', static function () {
            if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
                wp_add_privacy_policy_content( 'Portal de Serviços', '<p>O portal usa a conta WordPress (nome, e-mail, senha protegida e cookies de autenticação). O serviço Meu Combustível armazena veículos, placa opcional, quilometragem, abastecimentos, valores e observações. O proprietário pode compartilhar um veículo com outras contas cadastradas; os participantes passam a visualizar seus abastecimentos. Limites de tentativas usam identificadores criptográficos temporários, apagados periodicamente. Os dados do aplicativo ficam no banco deste site. A consulta de atualizações conecta o servidor ao GitHub e envia a versão do plugin, sem enviar usuários, veículos ou abastecimentos. Recuperação de senha utiliza o e-mail do WordPress. A importação administrativa vincula IDs antigos às contas WordPress; a preparação dos backups expira após uma hora sem processamento e seu conteúdo é removido ao concluir a importação. O vínculo opcional com o Telegram associa o ID da conta ao usuário WordPress. Mensagens de voz e texto são recebidas pelo Telegram e enviadas à OpenAI para transcrição/interpretação, com consentimento no vínculo. Áudios não são armazenados na mídia do site. Pendências expiram em duas horas; o conteúdo temporário de mensagens é limpo após o processamento ou pela limpeza diária. Identificadores mínimos de entrega podem permanecer por até 90 dias para evitar duplicações. O cron da hospedagem precisa funcionar para processar filas e efetuar as limpezas periódicas. Os dados podem ser exportados e apagados pelas ferramentas de privacidade do WordPress. Excluir uma conta ou atender à sua solicitação de apagamento remove seus veículos e registros e anonimiza a autoria em veículos de terceiros. Desativar ou desinstalar o plugin preserva os dados para reinstalação.</p>' );
            }
        } );
    }

    public static function export( $email, $page = 1 ) {
        global $wpdb;
        $user = get_user_by( 'email', $email );
        if ( ! $user ) { return array( 'data' => array(), 'done' => true ); }
        $page = max( 1, (int) $page ); $data = array();
        if ( 1 === $page ) {
            $vehicles = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_vehicles WHERE owner_user_id = %d", $user->ID ), ARRAY_A );
            foreach ( $vehicles as $vehicle ) {
                $data[] = array( 'group_id' => 'pds-vehicles', 'group_label' => 'Veículos do portal', 'item_id' => 'vehicle-' . $vehicle['id'], 'data' => array( array( 'name' => 'Veículo', 'value' => $vehicle['name'] ), array( 'name' => 'Placa', 'value' => $vehicle['plate'] ) ) );
            }
            $link = PDS_Telegram::link( $user->ID );
            if ( $link ) { $data[] = array( 'group_id' => 'pds-telegram', 'group_label' => 'Telegram do portal', 'item_id' => 'telegram-' . $user->ID, 'data' => array( array( 'name' => 'ID da conta no Telegram', 'value' => (string) $link['telegram_id'] ), array( 'name' => 'Veículo padrão (ID)', 'value' => (string) $link['default_vehicle_id'] ), array( 'name' => 'Confirmar antes de salvar', 'value' => $link['confirm_before_save'] ? 'Sim' : 'Não' ) ) ); }
        }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.*, v.name AS vehicle_name FROM {$wpdb->prefix}pds_fuel_records r INNER JOIN {$wpdb->prefix}pds_vehicles v ON v.id = r.vehicle_id WHERE v.owner_user_id = %d OR r.created_by = %d ORDER BY r.id LIMIT 100 OFFSET %d", $user->ID, $user->ID, ( $page - 1 ) * 100 ), ARRAY_A );
        foreach ( $rows as $row ) {
            $fields = array();
            foreach ( array( 'vehicle_name' => 'Veículo', 'filled_on' => 'Data', 'odometer' => 'Quilometragem', 'fuel_type' => 'Combustível', 'price' => 'Preço por litro', 'liters' => 'Litros', 'paid' => 'Valor pago', 'full_tank' => 'Tanque completo', 'notes' => 'Observações' ) as $key => $label ) { $fields[] = array( 'name' => $label, 'value' => (string) $row[ $key ] ); }
            $data[] = array( 'group_id' => 'pds-fuel', 'group_label' => 'Abastecimentos do portal', 'item_id' => 'fuel-' . $row['id'], 'data' => $fields );
        }
        return array( 'data' => $data, 'done' => count( $rows ) < 100 );
    }

    public static function erase( $email, $page = 1 ) {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
        return self::cleanup( $user->ID, 100 );
    }

    public static function delete_user( $id ) {
        self::cleanup( (int) $id, 0 );
    }

    private static function cleanup( $id, $limit ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_vehicles WHERE owner_user_id = %d", $id );
        if ( $limit ) { $query .= ' LIMIT ' . (int) $limit; }
        $vehicles = $wpdb->get_results( $query, ARRAY_A ); $removed = false; $failed = false;
        foreach ( array( 'pds_tg_drafts', 'pds_tg_updates', 'pds_tg_links' ) as $table ) {
            $deleted = $wpdb->delete( $wpdb->prefix . $table, array( 'user_id' => $id ) );
            $failed = $failed || false === $deleted; $removed = $removed || (bool) $deleted;
        }
        foreach ( $vehicles as $vehicle ) {
            $wpdb->query( 'START TRANSACTION' );
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_vehicles WHERE id = %d FOR UPDATE", $vehicle['id'] ) );
            $records = PDS_Fuel::raw_records( $vehicle['id'] );
            foreach ( $records as $record ) { $wpdb->delete( $wpdb->prefix . 'pds_import_map', array( 'entity' => 'records', 'target_id' => $record['id'] ) ); }
            $wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->prefix}pds_import_map m INNER JOIN {$wpdb->prefix}pds_vehicle_shares s ON s.id = m.target_id WHERE m.entity = 'shares' AND s.vehicle_id = %d", $vehicle['id'] ) );
            $wpdb->delete( $wpdb->prefix . 'pds_import_map', array( 'entity' => 'vehicles', 'target_id' => $vehicle['id'] ) );
            $r1 = $wpdb->delete( $wpdb->prefix . 'pds_fuel_records', array( 'vehicle_id' => $vehicle['id'] ) );
            $r2 = $wpdb->delete( $wpdb->prefix . 'pds_vehicle_shares', array( 'vehicle_id' => $vehicle['id'] ) );
            $r3 = $wpdb->delete( $wpdb->prefix . 'pds_vehicles', array( 'id' => $vehicle['id'] ) );
            if ( false === $r1 || false === $r2 || false === $r3 ) { $wpdb->query( 'ROLLBACK' ); $failed = true; continue; }
            $wpdb->query( 'COMMIT' );
            foreach ( $records as $record ) { PDS_Fuel::event( 'deleted', $record, $vehicle ); }
            $removed = true;
        }
        $anonymized = $wpdb->update( $wpdb->prefix . 'pds_fuel_records', array( 'created_by' => 0 ), array( 'created_by' => $id ) );
        $wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->prefix}pds_import_map m INNER JOIN {$wpdb->prefix}pds_vehicle_shares s ON s.id = m.target_id WHERE m.entity = 'shares' AND s.user_id = %d", $id ) );
        $shares = $wpdb->delete( $wpdb->prefix . 'pds_vehicle_shares', array( 'user_id' => $id ) );
        $failed = $failed || false === $anonymized || false === $shares;
        $removed = (bool) $anonymized || (bool) $shares || $removed;
        $done = $failed || ! $limit || count( $vehicles ) < $limit;
        if ( $done && ! $failed ) {
            $wpdb->delete( $wpdb->prefix . 'pds_import_map', array( 'entity' => 'users', 'target_id' => $id ) );
            $job = get_user_meta( $id, 'pds_import_active_job', true );
            if ( is_string( $job ) && preg_match( '/^[a-f0-9]{32}$/D', $job ) ) { delete_transient( 'pds_import_job_' . $job ); }
            foreach ( array( 'pds_member', 'pds_suspended', 'pds_blocked_services', 'pds_policy_accepted_at', 'pds_import_password_reset', 'pds_import_active_job' ) as $key ) { delete_user_meta( $id, $key ); }
        }
        return array( 'items_removed' => $removed, 'items_retained' => $failed, 'messages' => $failed ? array( 'Alguns dados não puderam ser apagados. Verifique o banco do portal e tente novamente.' ) : array(), 'done' => $done );
    }
}
