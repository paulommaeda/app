<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Admin {
    public static function boot() {
        add_action( 'admin_menu', static function () { add_menu_page( 'Portal de Serviços', 'Portal de Serviços', 'manage_options', 'pds-portal', array( __CLASS__, 'page' ), 'dashicons-screenoptions', 30 ); } );
        add_action( 'admin_post_pds_save_settings', array( __CLASS__, 'save' ) );
        add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( PDS_FILE ), static function ( $links ) {
            array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pds-portal' ) ) . '">Configurações</a>' );
            return $links;
        } );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $settings = get_option( 'pds_settings', array() );
        $users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'pds_member' AND meta_value = '1'" );
        $vehicles = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pds_vehicles" );
        $stats = $wpdb->get_row( "SELECT COUNT(*) AS records, COALESCE(SUM(liters),0) AS liters, COALESCE(SUM(paid),0) AS spent FROM {$wpdb->prefix}pds_fuel_records", ARRAY_A );
        ?>
        <div class="wrap" style="max-width:1000px">
            <h1>Portal de Serviços</h1>
            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div><?php endif; ?>
            <p><a class="button button-primary" href="<?php echo esc_url( pds_portal_url() ); ?>" target="_blank" rel="noopener">Abrir o portal</a> <a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Gerenciar usuários e acessos</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pds-import' ) ); ?>">Importar app antigo</a></p>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:24px 0">
                <?php foreach ( array( 'Participantes' => number_format_i18n( $users ), 'Veículos' => number_format_i18n( $vehicles ), 'Abastecimentos' => number_format_i18n( $stats['records'] ?? 0 ), 'Litros registrados' => number_format_i18n( $stats['liters'] ?? 0, 2 ) ) as $label => $value ) : ?>
                    <div style="padding:20px;background:white;border:1px solid #ddd;min-width:155px"><span><?php echo esc_html( $label ); ?></span><strong style="display:block;font-size:28px;margin-top:8px"><?php echo esc_html( $value ); ?></strong></div>
                <?php endforeach; ?>
            </div>
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <input type="hidden" name="action" value="pds_save_settings">
                <?php wp_nonce_field( 'pds_settings' ); ?>
                <h2>Configuração do portal</h2>
                <table class="form-table" role="presentation">
                    <tr><th><label for="pds-name">Nome do portal</label></th><td><input id="pds-name" name="name" class="regular-text" maxlength="80" required value="<?php echo esc_attr( $settings['name'] ?? 'Meu Portal' ); ?>"></td></tr>
                    <tr><th><label for="pds-page">Página principal</label></th><td><?php wp_dropdown_pages( array( 'name' => 'page_id', 'id' => 'pds-page', 'selected' => $settings['page_id'] ?? 0, 'show_option_none' => 'Selecione a página' ) ); ?><p class="description">A página deve conter o shortcode <code>[portal_servicos]</code>.</p></td></tr>
                    <tr><th>Cadastro público</th><td><label><input type="checkbox" name="registration" value="1" <?php checked( ! empty( $settings['registration'] ) ); ?>> Permitir criar contas pelo portal</label><p class="description">Cria clientes com permissão apenas de leitura. Não altera o cadastro público geral do WordPress.</p></td></tr>
                    <tr><th>Aparência</th><td><label><input type="checkbox" name="standalone" value="1" <?php checked( ! empty( $settings['standalone'] ) ); ?>> Exibir o portal em tela própria, sem cabeçalho e rodapé do tema</label></td></tr>
                    <tr><th>Serviços disponíveis</th><td><?php foreach ( pds_get_services() as $slug => $service ) : ?><p><label><input type="checkbox" name="enabled_services[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! in_array( $slug, $settings['disabled_services'] ?? array(), true ) ); ?>> <?php echo esc_html( $service['title'] ); ?></label></p><?php endforeach; ?></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php PDS_Updater::panel(); ?>
            <h2>Como usar</h2>
            <p><code>[portal_servicos]</code> exibe os serviços e a conta do cliente. <code>[portal_combustivel]</code> abre diretamente o controle de combustível.</p>
            <p>Em <strong>Usuários → Editar usuário → Acesso ao Portal de Serviços</strong>, você pode suspender a conta no portal ou bloquear serviços específicos.</p>
            <p>Para a recuperação de senha, configure o envio de e-mails do WordPress. Exclua a página do portal e a API <code>/wp-json/pds/v1/</code> do cache da hospedagem e da CDN.</p>
            <p>O plugin funciona com as contas existentes do WordPress. Os próximos serviços podem usar a mesma conta, sem outro cadastro.</p>
        </div>
        <?php
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sem permissão.' ); }
        check_admin_referer( 'pds_settings' );
        $settings = get_option( 'pds_settings', array() );
        $page_id = absint( $_POST['page_id'] ?? 0 );
        if ( ! $page_id || get_post_type( $page_id ) !== 'page' || get_post_status( $page_id ) !== 'publish' ) { wp_die( 'Selecione uma página publicada.' ); }
        $name = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( ! $name || strlen( $name ) > 80 ) { wp_die( 'Informe um nome de até 80 caracteres.' ); }
        $enabled = isset( $_POST['enabled_services'] ) && is_array( $_POST['enabled_services'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_services'] ) ) : array();
        $known = array_keys( pds_get_services() );
        $settings = array_merge( $settings, array(
            'name' => $name, 'page_id' => $page_id, 'registration' => isset( $_POST['registration'] ) ? 1 : 0,
            'standalone' => isset( $_POST['standalone'] ) ? 1 : 0,
            'disabled_services' => array_values( array_diff( $known, $enabled ) ),
        ) );
        update_option( 'pds_settings', $settings, false );
        wp_safe_redirect( admin_url( 'admin.php?page=pds-portal&saved=1' ) ); exit;
    }

    public static function profile( $user ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $blocked = (array) get_user_meta( $user->ID, 'pds_blocked_services', true );
        ?>
        <h2>Acesso ao Portal de Serviços</h2>
        <?php wp_nonce_field( 'pds_user_' . $user->ID, 'pds_user_nonce' ); ?>
        <table class="form-table" role="presentation">
            <tr><th>Conta no portal</th><td><label><input type="checkbox" name="pds_suspended" value="1" <?php checked( (bool) get_user_meta( $user->ID, 'pds_suspended', true ) ); ?>> Suspender acesso a todos os serviços do portal</label></td></tr>
            <tr><th>Bloquear serviços</th><td><?php foreach ( pds_get_services() as $slug => $service ) : ?><p><label><input type="checkbox" name="pds_blocked[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $blocked, true ) ); ?>> Bloquear <?php echo esc_html( $service['title'] ); ?></label></p><?php endforeach; ?><p class="description">O bloqueio também é aplicado na API, inclusive para veículos compartilhados.</p></td></tr>
        </table>
        <?php
    }

    public static function save_profile( $user_id ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) || empty( $_POST['pds_user_nonce'] ) || ! is_string( $_POST['pds_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pds_user_nonce'] ) ), 'pds_user_' . $user_id ) ) { return; }
        $blocked = isset( $_POST['pds_blocked'] ) && is_array( $_POST['pds_blocked'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['pds_blocked'] ) ) : array();
        update_user_meta( $user_id, 'pds_blocked_services', array_values( array_intersect( array_keys( pds_get_services() ), $blocked ) ) );
        update_user_meta( $user_id, 'pds_suspended', isset( $_POST['pds_suspended'] ) ? 1 : 0 );
    }
}
