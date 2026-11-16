<?php
defined( 'ABSPATH' ) || exit;

/** Financial data belongs to a space; authentication always remains the real WordPress user. */
final class PDS_Finance_Spaces {
    const ROLES = array( 'viewer' => 'Consulta', 'editor' => 'Lançamentos', 'admin' => 'Administração', 'owner' => 'Proprietário' );
    private static $scope = null;
    
    public static function tables() { return array( 'pds_expense_categories', 'pds_expenses', 'pds_fin_settlements', 'pds_fin_subscriptions', 'pds_fin_subscription_charges', 'pds_fin_splits', 'pds_fin_people', 'pds_fin_account_types', 'pds_fin_accounts', 'pds_fin_transfers', 'pds_fin_receipts', 'pds_fin_cards', 'pds_fin_invoices', 'pds_fin_installments', 'pds_fin_bill_payments', 'pds_fin_adjustments', 'pds_fin_recurring', 'pds_fin_payables' ); }
    public static function schema( $collate ) {
        global $wpdb; $p = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$p}pds_fin_spaces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            personal_owner_id bigint(20) unsigned DEFAULT NULL,
            name varchar(80) NOT NULL,
            kind varchar(12) NOT NULL DEFAULT 'personal',
            active tinyint(1) NOT NULL DEFAULT 1,
            categories_seeded tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY personal_owner (personal_owner_id),
            KEY owner_id (owner_user_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_members (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(12) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY space_user (space_id,user_id),
            KEY user_active (user_id,active)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_invites (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            space_id bigint(20) unsigned NOT NULL,
            email varchar(100) NOT NULL,
            role varchar(12) NOT NULL,
            token_hash varchar(64) NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'pending',
            created_by bigint(20) unsigned NOT NULL,
            accepted_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY space_status (space_id,status)
        ) ENGINE=InnoDB $collate;" );
        // Backfill before dbDelta adds the new unique indexes. Existing IDs and relationships stay intact.
        foreach ( self::tables() as $suffix ) {
            $table = $p . $suffix;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { continue; }
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
            foreach ( array( 'space_id', 'created_by', 'updated_by' ) as $col ) {
                if ( ! in_array( $col, $columns, true ) && false === $wpdb->query( "ALTER TABLE `$table` ADD `$col` bigint(20) unsigned NOT NULL DEFAULT 0" ) ) { return self::error( 'Não foi possível preparar o financeiro compartilhado.', 500 ); }
            }
            $owners = $wpdb->get_col( "SELECT DISTINCT user_id FROM `$table` WHERE space_id=0 AND user_id>0" );
            foreach ( $owners as $uid ) {
                $space = self::personal( (int) $uid ); if ( ! $space ) { return self::error( 'Não foi possível migrar o financeiro pessoal.', 500 ); }
                if ( false === $wpdb->query( $wpdb->prepare( "UPDATE `$table` SET space_id=%d,created_by=user_id,updated_by=user_id WHERE user_id=%d AND space_id=0", $space, $uid ) ) ) { return self::error( 'Não foi possível migrar os dados financeiros.', 500 ); }
                if ( get_user_meta( $uid, 'pds_expenses_seeded', true ) ) { $wpdb->update( $p . 'pds_fin_spaces', array( 'categories_seeded' => 1 ), array( 'id' => $space ) ); }
            }
            $indexes = $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ); $drop = array();
            foreach ( $indexes as $index ) { if ( 'user_id' === $index['Column_name'] && 'PRIMARY' !== $index['Key_name'] ) { $drop[ $index['Key_name'] ] = true; } }
            foreach ( array_keys( $drop ) as $index ) { if ( false === $wpdb->query( "ALTER TABLE `$table` DROP INDEX `$index`" ) ) { return self::error( 'Não foi possível atualizar os índices financeiros.', 500 ); } }
        }
        delete_option( 'pds_finance_planning_cursor' ); delete_option( 'pds_subscriptions_cursor' );
        return true;
    }
    public static function migrate_telegram() {
        global $wpdb;
        foreach ( array( 'pds_tg_links', 'pds_tg_updates', 'pds_tg_drafts', 'pds_tg_expense_drafts', 'pds_tg_receipt_drafts', 'pds_tg_payable_drafts', 'pds_tg_settlement_drafts' ) as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $users = $wpdb->get_col( "SELECT DISTINCT user_id FROM `$table` WHERE user_id>0 AND finance_space_id=0" );
            foreach ( $users as $uid ) { $space = self::personal( (int) $uid ); if ( ! $space || false === $wpdb->update( $table, array( 'finance_space_id' => $space ), array( 'user_id' => $uid, 'finance_space_id' => 0 ) ) ) { return self::error( 'Não foi possível preservar o destino das mensagens do Telegram.', 500 ); } }
        }
        return true;
    }
    public static function boot() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_pre_dispatch', static function ( $result, $server, $r ) {
            if ( preg_match( '#^/pds/v1/(?:finance|expenses)/#', $r->get_route() ) && strpos( $r->get_route(), '/pds/v1/finance/spaces' ) !== 0 ) {
                $ok = self::bind_request( $r ); if ( is_wp_error( $ok ) ) { return $ok; }
            } else { self::$scope = null; }
            return $result;
        }, 5, 3 );
        add_action( 'pds_enqueue_assets', static function () {
            wp_enqueue_script( 'pds-spaces', PDS_URL . 'assets/spaces.js', array( 'pds-finance' ), PDS_VERSION, true );
            wp_enqueue_style( 'pds-spaces', PDS_URL . 'assets/spaces.css', array( 'pds-finance' ), PDS_VERSION );
        } );
    }
    public static function error( $message, $status = 403 ) { return new WP_Error( 'pds_finance_space', $message, array( 'status' => $status ) ); }
    public static function personal( $uid = 0 ) {
        global $wpdb; $uid = (int) ( $uid ?: get_current_user_id() ); if ( ! $uid ) { return 0; }
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_spaces WHERE personal_owner_id=%d AND kind='personal' AND active=1", $uid ) );
        if ( ! $id ) {
            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}pds_fin_spaces (owner_user_id,personal_owner_id,name,kind,created_at) VALUES (%d,%d,'Meu financeiro pessoal','personal',%s)", $uid, $uid, current_time( 'mysql', true ) ) );
            $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_spaces WHERE personal_owner_id=%d AND active=1", $uid ) );
        }
        return $id;
    }
    public static function id() { $actor = get_current_user_id(); return self::$scope && self::$scope['actor'] === $actor ? self::$scope['id'] : self::personal( $actor ); }
    public static function row( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_spaces WHERE id=%d AND active=1", $id ), ARRAY_A ); }
    public static function owner( $id ) { $s = self::row( $id ); return $s ? (int) $s['owner_user_id'] : 0; }
    public static function service( $id ) { $owner = self::owner( $id ); return $owner && get_userdata( $owner ) && pds_user_can_access_service( 'gastos', $owner ); }
    public static function role( $id = 0, $actor = 0 ) {
        global $wpdb; $actor = (int) ( $actor ?: get_current_user_id() ); $s = self::row( $id ?: self::id() );
        if ( ! $s || ! $actor || ! pds_user_can_access_service( 'gastos', $actor ) || ! self::service( $s['id'] ) ) { return ''; }
        if ( (int) $s['owner_user_id'] === $actor ) { return 'owner'; }
        if ( 'shared' !== $s['kind'] ) { return ''; }
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT role FROM {$wpdb->prefix}pds_fin_members WHERE space_id=%d AND user_id=%d AND active=1", $s['id'], $actor ) );
    }
    public static function can( $action = 'read', $id = 0 ) {
        $role = self::role( $id ); return 'read' === $action ? isset( self::ROLES[ $role ] ) : ( 'write' === $action ? in_array( $role, array( 'editor', 'admin', 'owner' ), true ) : in_array( $role, array( 'admin', 'owner' ), true ) );
    }
    public static function select( $id ) {
        $id = (int) $id; if ( ! self::can( 'read', $id ) ) { return self::error( 'Você não tem mais acesso a este financeiro. Escolha outro financeiro no portal ou use /financeiros no Telegram.' ); }
        self::$scope = array( 'id' => $id, 'actor' => get_current_user_id() ); return true;
    }
    public static function reset() { self::$scope = null; }
    public static function within( $id, $callback ) { $previous = self::$scope; self::$scope = array( 'id' => (int) $id, 'actor' => get_current_user_id() ); try { return $callback(); } finally { self::$scope = $previous; } }
    public static function bind_request( $r ) {
        if ( ! is_user_logged_in() ) { return self::error( 'Entre na sua conta para continuar.', 401 ); }
        $raw = $r->get_header( 'X-PDS-Finance-Space' );
        if ( $raw !== null && $raw !== '' && ! preg_match( '/^[1-9][0-9]{0,17}$/D', $raw ) ) { return self::error( 'Escolha um financeiro válido.', 400 ); }
        $ok = self::select( $raw ? (int) $raw : self::personal() ); if ( is_wp_error( $ok ) ) { return $ok; }
        return self::request_allowed( $r );
    }
    public static function request_allowed( $r ) {
        if ( ! self::can() ) { return self::error( 'O acesso a este financeiro foi removido.' ); }
        if ( in_array( $r->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || '/pds/v1/finance/plan' === $r->get_route() ) { return true; }
        if ( self::can( 'manage' ) ) { return true; }
        if ( ! self::can( 'write' ) ) { return self::error( 'Seu acesso permite apenas consultar este financeiro.' ); }
        $path = $r->get_route();
        if ( 'POST' === $r->get_method() && preg_match( '#^/pds/v1/(?:expenses/entries|finance/(?:receipts|receipts/plan|payables|transfers)|finance/payables/[0-9]+/pay|finance/invoices/[0-9]+/payments)$#D', $path ) ) { return true; }
        if ( 'PUT' === $r->get_method() && preg_match( '#^/pds/v1/finance/receipts/([0-9]+)$#D', $path, $m ) ) { return self::receipt_allowed( $r, (int) $m[1] ); }
        return self::error( 'Você pode registrar lançamentos e baixas. Para editar, excluir ou alterar cadastros, peça acesso de administração.' );
    }
    public static function receipt_allowed( $r, $id ) {
        global $wpdb; $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_receipts WHERE id=%d AND space_id=%d", $id, self::id() ), ARRAY_A );
        if ( ! $old || 'pending' !== $old['status'] || 'received' !== $r->get_param( 'status' ) ) { return self::error( 'Seu acesso permite apenas dar baixa em um recebimento pendente.' ); }
        foreach ( $r->get_params() as $key => $value ) {
            if ( in_array( $key, array( 'id', 'status', 'amount', 'account_id', 'received_on', '_locale', '_wpnonce' ), true ) ) { continue; }
            if ( ! array_key_exists( $key, $old ) || (string) $value !== (string) $old[ $key ] ) { return self::error( 'Seu acesso permite dar baixa, sem alterar os outros dados da previsão.' ); }
        }
        return true;
    }
    public static function stamp( $data ) {
        $space = (int) ( $data['space_id'] ?? self::id() );
        return array_merge( $data, array( 'space_id' => $space, 'user_id' => self::owner( $space ), 'created_by' => get_current_user_id() ?: self::owner( $space ), 'updated_by' => get_current_user_id() ?: self::owner( $space ) ) );
    }
    public static function insert( $table, $data, $format = null, $creator = null ) { global $wpdb; $stamped = self::stamp( $data ); if ( null !== $creator ) { $stamped['created_by'] = (int) $creator; $stamped['updated_by'] = (int) $creator; } return $wpdb->insert( $table, $stamped, $format ); }
    public static function update( $table, $data, $where, $format = null, $where_format = null ) { global $wpdb; $data['updated_by'] = get_current_user_id() ?: self::owner( $where['space_id'] ?? self::id() ); return $wpdb->update( $table, $data, $where, $format, $where_format ); }
    public static function attribution( $row ) {
        foreach ( array( 'created_by', 'updated_by' ) as $key ) { if ( isset( $row[ $key ] ) ) { $u = get_userdata( (int) $row[ $key ] ); $row[ $key ] = (int) $row[ $key ]; $row[ $key . '_name' ] = $u ? $u->display_name : 'Usuário removido'; } }
        unset( $row['user_id'] ); return $row;
    }
    public static function accessible() {
        global $wpdb; $uid = get_current_user_id(); if ( ! $uid || ! pds_user_can_access_service( 'gastos' ) ) { return array(); } self::personal();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT s.* FROM {$wpdb->prefix}pds_fin_spaces s LEFT JOIN {$wpdb->prefix}pds_fin_members m ON m.space_id=s.id AND m.user_id=%d AND m.active=1 WHERE s.active=1 AND (s.owner_user_id=%d OR (s.kind='shared' AND m.id IS NOT NULL)) ORDER BY s.kind,s.name,s.id", $uid, $uid ), ARRAY_A ); $out = array();
        foreach ( $rows as $s ) { $role = self::role( $s['id'] ); if ( ! isset( self::ROLES[ $role ] ) ) { continue; } $owner = get_userdata( $s['owner_user_id'] ); $out[] = array( 'id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'role' => $role, 'role_label' => self::ROLES[ $role ], 'owner_name' => $owner ? $owner->display_name : '', 'can_write' => in_array( $role, array( 'editor', 'admin', 'owner' ), true ), 'can_manage' => in_array( $role, array( 'admin', 'owner' ), true ) ); }
        return $out;
    }
    public static function routes() {
        foreach ( array( '/finance/spaces' => array( 'GET' => 'listing', 'POST' => 'create' ), '/finance/spaces/accept' => array( 'POST' => 'accept' ), '/finance/spaces/(?P<space>\d+)/activity' => array( 'GET' => 'activity' ), '/finance/spaces/(?P<space>\d+)/members' => array( 'GET' => 'members' ), '/finance/spaces/(?P<space>\d+)/invites' => array( 'POST' => 'invite' ), '/finance/spaces/(?P<space>\d+)/invites/(?P<id>\d+)' => array( 'DELETE' => 'cancel_invite' ), '/finance/spaces/(?P<space>\d+)/members/(?P<id>\d+)' => array( 'PUT' => 'member_role', 'DELETE' => 'remove_member' ), '/finance/spaces/(?P<space>\d+)/leave' => array( 'POST' => 'leave' ) ) as $path => $methods ) {
            $endpoints = array(); foreach ( $methods as $method => $callback ) { $endpoints[] = array( 'methods' => $method, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => static function () { return is_user_logged_in() && pds_user_can_access_service( 'gastos' ) ? true : self::error( 'Entre em uma conta com acesso ao financeiro.', is_user_logged_in() ? 403 : 401 ); } ); } register_rest_route( 'pds/v1', $path, $endpoints );
        }
    }
    public static function listing() { return array( 'spaces' => self::accessible(), 'personal_id' => self::personal() ); }
    public static function create( $r ) {
        global $wpdb; $name = $r->get_param( 'name' ); $mode = $r->get_param( 'mode' );
        if ( ! is_string( $name ) || mb_strlen( trim( $name ) ) < 2 || mb_strlen( $name ) > 80 || ! in_array( $mode, array( 'empty', 'share_personal' ), true ) ) { return self::error( 'Informe um nome de 2 a 80 caracteres e escolha como criar o financeiro.', 400 ); }
        if ( 'share_personal' === $mode && true !== $r->get_param( 'confirm_share' ) ) { return self::error( 'Confirme o compartilhamento dos dados atuais.', 400 ); }
        $uid = get_current_user_id(); if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_spaces WHERE owner_user_id=%d AND kind='shared'", $uid ) ) >= 20 ) { return self::error( 'Limite de 20 financeiros compartilhados.', 400 ); }
        $name = sanitize_text_field( trim( $name ) ); $personal = self::personal(); $wpdb->query( 'START TRANSACTION' );
        if ( 'share_personal' === $mode ) {
            $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_spaces WHERE id=%d AND personal_owner_id=%d AND kind='personal' FOR UPDATE", $personal, $uid ), ARRAY_A );
            if ( ! $old ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'O financeiro pessoal mudou. Atualize a página.', 409 ); }
            $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_spaces', array( 'name' => $name, 'kind' => 'shared', 'personal_owner_id' => null ), array( 'id' => $personal ) ); $id = $personal;
            $new_personal = false === $ok ? 0 : self::personal();
        } else { $ok = $wpdb->insert( $wpdb->prefix . 'pds_fin_spaces', array( 'name' => $name, 'owner_user_id' => $uid, 'kind' => 'shared', 'created_at' => current_time( 'mysql', true ) ) ); $id = (int) $wpdb->insert_id; $new_personal = $personal; }
        if ( false === $ok || ! $new_personal ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível criar o financeiro.', 500 ); }
        $wpdb->query( 'COMMIT' ); return array( 'id' => $id, 'personal_id' => $new_personal, 'spaces' => self::accessible() );
    }
    public static function activity( $r ) {
        global $wpdb; $space = (int) $r['space']; if ( ! self::can( 'read', $space ) ) { return self::error( 'Você não tem acesso a este financeiro.' ); }
        $parts = array(); foreach ( array( 'pds_expenses' => array( 'Gasto', 'description', 'spent_on', "'realized'" ), 'pds_fin_receipts' => array( 'Recebimento', 'description', 'received_on', 'status' ), 'pds_fin_bill_payments' => array( 'Pagamento de fatura', "'Pagamento de fatura'", 'paid_on', "'paid'" ), 'pds_fin_transfers' => array( 'Transferência', "'Transferência entre contas'", 'transferred_on', 'status' ), 'pds_fin_payables' => array( 'Conta a pagar', 'description', 'due_on', 'status' ) ) as $table => $f ) {
            $parts[] = $wpdb->prepare( "SELECT id,%s AS kind,{$f[1]} AS description,amount,{$f[2]} AS movement_on,{$f[3]} AS status,created_at,created_by,updated_by FROM {$wpdb->prefix}{$table} WHERE space_id=%d", $f[0], $space );
        }
        $page = max( 1, min( 10000, (int) ( $r->get_param( 'page' ) ?: 1 ) ) ); $offset = ( $page - 1 ) * 50;
        $rows = $wpdb->get_results( 'SELECT * FROM (' . implode( ' UNION ALL ', $parts ) . ") a ORDER BY created_at DESC,id DESC,kind LIMIT 51 OFFSET $offset", ARRAY_A );
        $more = count( $rows ) > 50; $rows = array_slice( $rows, 0, 50 ); foreach ( $rows as &$row ) { $row = self::attribution( $row ); $row['amount'] = null === $row['amount'] ? null : (float) $row['amount']; } unset( $row ); return array( 'activity' => $rows, 'page' => $page, 'has_more' => $more );
    }
    private static function manage( $r ) { $id = (int) $r['space']; $s = self::row( $id ); return $s && 'shared' === $s['kind'] && self::can( 'manage', $id ) ? $s : self::error( 'Somente administradores deste financeiro podem gerenciar participantes.' ); }
    public static function members( $r ) {
        global $wpdb; $s = self::manage( $r ); if ( is_wp_error( $s ) ) { return $s; } $owner = get_userdata( $s['owner_user_id'] );
        $members = array( array( 'user_id' => (int) $s['owner_user_id'], 'name' => $owner ? $owner->display_name : '', 'email' => $owner ? $owner->user_email : '', 'role' => 'owner' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_members WHERE space_id=%d AND active=1 ORDER BY id", $s['id'] ), ARRAY_A );
        foreach ( $rows as $m ) { $u = get_userdata( $m['user_id'] ); if ( $u ) { $members[] = array( 'user_id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'role' => $m['role'] ); } }
        $invites = $wpdb->get_results( $wpdb->prepare( "SELECT id,email,role,expires_at FROM {$wpdb->prefix}pds_fin_invites WHERE space_id=%d AND status='pending' AND expires_at>%d ORDER BY id DESC LIMIT 100", $s['id'], time() ), ARRAY_A );
        return array( 'members' => $members, 'invites' => $invites );
    }
    private static function valid_role( $role, $space ) { return in_array( $role, array( 'viewer', 'editor', 'admin' ), true ) && ( 'admin' !== $role || 'owner' === self::role( $space ) ); }
    public static function invite( $r ) {
        global $wpdb; $s = self::manage( $r ); if ( is_wp_error( $s ) ) { return $s; } $email = $r->get_param( 'email' ); $role = $r->get_param( 'role' );
        if ( ! is_string( $email ) || strlen( $email ) > 100 || ! is_email( $email ) || ! self::valid_role( $role, $s['id'] ) ) { return self::error( 'Confira o e-mail e a permissão. Somente o proprietário pode nomear administradores.', 400 ); }
        $email = strtolower( sanitize_email( trim( $email ) ) ); $u = get_user_by( 'email', $email );
        if ( $u && ( (int) $u->ID === (int) $s['owner_user_id'] || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pds_fin_members WHERE space_id=%d AND user_id=%d AND active=1", $s['id'], $u->ID ) ) ) ) { return self::error( 'Essa pessoa já participa deste financeiro.', 409 ); }
        if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_fin_invites WHERE space_id=%d AND created_at>%s", $s['id'], gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) >= 30 ) { return self::error( 'Limite diário de 30 convites para este financeiro.', 429 ); }
        $token = bin2hex( random_bytes( 32 ) ); $wpdb->query( 'START TRANSACTION' );
        $old = $wpdb->update( $wpdb->prefix . 'pds_fin_invites', array( 'status' => 'canceled' ), array( 'space_id' => $s['id'], 'email' => $email, 'status' => 'pending' ) );
        $ok = false !== $old && $wpdb->insert( $wpdb->prefix . 'pds_fin_invites', array( 'space_id' => $s['id'], 'email' => $email, 'role' => $role, 'token_hash' => hash( 'sha256', $token ), 'expires_at' => time() + 7 * DAY_IN_SECONDS, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
        if ( ! $ok ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível gerar o convite.', 500 ); } $id = (int) $wpdb->insert_id; $wpdb->query( 'COMMIT' );
        $url = add_query_arg( array( 'servico' => 'gastos', 'convite_financeiro' => $token ), pds_portal_url() ); $actor = wp_get_current_user();
        $sent = wp_mail( $email, 'Convite para o financeiro ' . $s['name'], $actor->display_name . ' convidou você para ' . $s['name'] . '. Permissão: ' . self::ROLES[ $role ] . ".\n\nEntre ou crie sua própria conta WordPress com este e-mail e aceite o convite:\n" . $url . "\n\nO convite vence em 7 dias. Seus dados pessoais não serão transferidos para este financeiro." );
        return array( 'id' => $id, 'url' => $url, 'email_sent' => (bool) $sent );
    }
    public static function accept( $r ) {
        global $wpdb; $token = $r->get_param( 'token' ); if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return self::error( 'Convite inválido ou expirado.', 404 ); }
        $wpdb->query( 'START TRANSACTION' ); $i = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_invites WHERE token_hash=%s FOR UPDATE", hash( 'sha256', $token ) ), ARRAY_A ); $u = wp_get_current_user();
        if ( ! $i || 'pending' !== $i['status'] || (int) $i['expires_at'] <= time() || strtolower( $u->user_email ) !== strtolower( $i['email'] ) || ! self::service( $i['space_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Convite inválido, expirado ou destinado a outro e-mail. Entre com o e-mail que recebeu o convite.', 403 ); }
        $s = self::row( $i['space_id'] );
        if ( ! $s || 'shared' !== $s['kind'] || (int) $s['owner_user_id'] === (int) $u->ID || ! in_array( self::role( $s['id'], $i['created_by'] ), array( 'owner', 'admin' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Este convite não está mais disponível.' ); }
        if ( 'admin' === $i['role'] && (int) $i['created_by'] !== (int) $s['owner_user_id'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Este convite não está mais disponível.' ); }
        $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}pds_fin_members (space_id,user_id,role,active,created_by,created_at) VALUES (%d,%d,%s,1,%d,%s) ON DUPLICATE KEY UPDATE role=VALUES(role),active=1,created_by=VALUES(created_by),created_at=VALUES(created_at)", $s['id'], $u->ID, $i['role'], $i['created_by'], current_time( 'mysql', true ) ) );
        $saved = $wpdb->update( $wpdb->prefix . 'pds_fin_invites', array( 'status' => 'accepted', 'accepted_by' => $u->ID ), array( 'id' => $i['id'], 'status' => 'pending' ) );
        if ( false === $ok || 1 !== $saved ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'Não foi possível aceitar o convite.', 500 ); } $wpdb->query( 'COMMIT' ); return array( 'id' => (int) $s['id'], 'spaces' => self::accessible() );
    }
    public static function cancel_invite( $r ) { global $wpdb; $s = self::manage( $r ); if ( is_wp_error( $s ) ) { return $s; } $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_invites', array( 'status' => 'canceled' ), array( 'id' => (int) $r['id'], 'space_id' => $s['id'], 'status' => 'pending' ) ); return false === $ok ? self::error( 'Não foi possível cancelar o convite.', 500 ) : array( 'success' => true ); }
    private static function change_member( $r, $remove ) {
        global $wpdb; $s = self::manage( $r ); if ( is_wp_error( $s ) ) { return $s; } $uid = (int) $r['id'];
        $m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pds_fin_members WHERE space_id=%d AND user_id=%d AND active=1", $s['id'], $uid ), ARRAY_A );
        if ( $uid === (int) $s['owner_user_id'] || ! $m || ( 'admin' === $m['role'] && 'owner' !== self::role( $s['id'] ) ) ) { return self::error( 'Você não pode alterar este participante.' ); }
        $role = $r->get_param( 'role' ); if ( ! $remove && ! self::valid_role( $role, $s['id'] ) ) { return self::error( 'Escolha uma permissão válida.', 400 ); }
        $data = $remove ? array( 'active' => 0 ) : array( 'role' => $role ); $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_members', $data, array( 'id' => $m['id'] ) );
        if ( false === $ok ) { return self::error( 'Não foi possível alterar o acesso.', 500 ); }
        // Outstanding invitations must not restore an access that was just revoked.
        $u = get_userdata( $uid ); if ( $remove && $u ) { $wpdb->update( $wpdb->prefix . 'pds_fin_invites', array( 'status' => 'canceled' ), array( 'space_id' => $s['id'], 'email' => strtolower( $u->user_email ), 'status' => 'pending' ) ); }
        return array( 'success' => true );
    }
    public static function member_role( $r ) { return self::change_member( $r, false ); }
    public static function remove_member( $r ) { return self::change_member( $r, true ); }
    public static function leave( $r ) { global $wpdb; $id = (int) $r['space']; if ( ! self::role( $id ) || 'owner' === self::role( $id ) ) { return self::error( 'O proprietário não pode sair do próprio financeiro.' ); } $ok = $wpdb->update( $wpdb->prefix . 'pds_fin_members', array( 'active' => 0 ), array( 'space_id' => $id, 'user_id' => get_current_user_id() ) ); return false === $ok ? self::error( 'Não foi possível sair.', 500 ) : array( 'success' => true ); }
}
