<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Updater {
    const MANIFEST = 'https://raw.githubusercontent.com/paulommaeda/app/main/update.json';
    const REPOSITORY = 'https://github.com/paulommaeda/app';
    const CACHE = 'pds_github_update_v1';

    public static function boot() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'information' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_directory' ), 20, 4 );
        add_action( 'admin_post_pds_check_updates', array( __CLASS__, 'check_now' ) );
        add_action( 'upgrader_process_complete', static function ( $upgrader, $options ) {
            if ( ( $options['type'] ?? '' ) === 'plugin' ) { delete_site_transient( self::CACHE ); }
        }, 10, 2 );
    }

    public static function manifest( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE );
            if ( is_array( $cached ) ) { return $cached; }
        }
        $response = wp_remote_get( self::MANIFEST, array(
            'timeout' => 10, 'redirection' => 3, 'limit_response_size' => 65536,
            'headers' => array( 'Accept' => 'application/json' ),
            'user-agent' => 'Portal-de-Servicos/' . PDS_VERSION,
        ) );
        $data = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
        $version = is_array( $data ) && is_string( $data['version'] ?? null ) ? $data['version'] : '';
        $expected = 'https://raw.githubusercontent.com/paulommaeda/app/main/releases/portal-de-servicos-' . $version . '.zip';
        if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ( $data['download_url'] ?? '' ) !== $expected || ! is_string( $data['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            set_site_transient( self::CACHE, array(), 10 * MINUTE_IN_SECONDS );
            return array();
        }
        $result = array(
            'version' => $version, 'download_url' => $expected, 'sha256' => $data['sha256'],
            'requires' => sanitize_text_field( $data['requires'] ?? '6.6' ),
            'requires_php' => sanitize_text_field( $data['requires_php'] ?? '8.0' ),
            'tested' => sanitize_text_field( $data['tested'] ?? '' ),
            'last_updated' => sanitize_text_field( $data['last_updated'] ?? '' ),
            'changelog' => wp_kses_post( is_string( $data['changelog'] ?? null ) ? $data['changelog'] : '' ),
        );
        set_site_transient( self::CACHE, $result, 6 * HOUR_IN_SECONDS );
        return $result;
    }

    public static function download( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || strpos( $package, 'https://raw.githubusercontent.com/paulommaeda/app/main/releases/portal-de-servicos-' ) !== 0 ) { return $reply; }
        $data = self::manifest();
        if ( ! $data || $package !== $data['download_url'] ) { return new WP_Error( 'pds_update_package', 'Não foi possível validar o pacote de atualização do portal. Verifique as atualizações novamente.' ); }
        $path = download_url( $package, 120 );
        if ( is_wp_error( $path ) ) { return $path; }
        if ( ! hash_equals( $data['sha256'], hash_file( 'sha256', $path ) ) ) {
            wp_delete_file( $path );
            return new WP_Error( 'pds_update_integrity', 'O pacote baixado não corresponde à versão publicada. Tente verificar as atualizações novamente.' );
        }
        return $path;
    }

    public static function source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( PDS_FILE ) ) { return $source; }
        $directory = dirname( plugin_basename( PDS_FILE ) );
        if ( ! preg_match( '/^[a-zA-Z0-9_.-]+$/D', $directory ) || in_array( $directory, array( '.', '..' ), true ) || basename( untrailingslashit( $source ) ) === $directory ) { return $source; }
        global $wp_filesystem;
        $destination = trailingslashit( $remote_source ) . $directory;
        if ( ! $wp_filesystem->move( untrailingslashit( $source ), $destination ) ) { return new WP_Error( 'pds_update_directory', 'Não foi possível preparar a pasta de atualização do portal.' ); }
        return trailingslashit( $destination );
    }

    public static function update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( plugin_basename( PDS_FILE ) !== $plugin_file || ( $plugin_data['UpdateURI'] ?? '' ) !== self::REPOSITORY ) { return $update; }
        $data = self::manifest();
        if ( ! $data ) { return $update; }
        return array(
            'id' => self::REPOSITORY, 'slug' => 'portal-de-servicos',
            'version' => $data['version'], 'url' => self::REPOSITORY,
            'package' => $data['download_url'], 'tested' => $data['tested'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
        );
    }

    public static function information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'portal-de-servicos' ) { return $result; }
        $data = self::manifest();
        if ( ! $data ) { return $result; }
        return (object) array(
            'name' => 'Portal de Serviços — Meu Combustível', 'slug' => 'portal-de-servicos',
            'version' => $data['version'], 'author' => '<a href="https://paulomaeda.com">Paulo Maeda</a>',
            'homepage' => self::REPOSITORY, 'download_link' => $data['download_url'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
            'tested' => $data['tested'], 'last_updated' => $data['last_updated'],
            'sections' => array(
                'description' => '<p>Portal modular com usuários WordPress, veículos privados, abastecimentos, relatórios e importação do aplicativo original.</p>',
                'changelog' => $data['changelog'],
            ),
        );
    }

    public static function check_now() {
        if ( ! current_user_can( 'update_plugins' ) ) { wp_die( 'Sem permissão para verificar atualizações.' ); }
        check_admin_referer( 'pds_check_updates' );
        $data = self::manifest( true );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
        wp_safe_redirect( add_query_arg( 'pds_update', $data ? 'checked' : 'failed', admin_url( 'admin.php?page=pds-portal' ) ) ); exit;
    }

    public static function panel() {
        ?>
        <h2>Atualizações pelo WordPress</h2>
        <p>Versão instalada: <strong><?php echo esc_html( PDS_VERSION ); ?></strong>. As novas versões publicadas no <a href="<?php echo esc_url( self::REPOSITORY ); ?>" target="_blank" rel="noopener">GitHub do projeto</a> aparecem em <strong>Painel → Atualizações</strong> e <strong>Plugins</strong>.</p>
        <p>Você pode ativar as atualizações automáticas na lista de plugins, se desejar.</p>
        <?php if ( isset( $_GET['pds_update'] ) && 'failed' === $_GET['pds_update'] ) : ?><div class="notice notice-warning inline"><p>Não foi possível consultar a versão no GitHub. Confira a conexão da hospedagem e tente novamente.</p></div><?php elseif ( isset( $_GET['pds_update'] ) ) : ?><div class="notice notice-success inline"><p>Consulta concluída. Confira as atualizações disponíveis na lista de plugins.</p></div><?php endif; ?>
        <?php if ( current_user_can( 'update_plugins' ) ) : ?><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="pds_check_updates"><?php wp_nonce_field( 'pds_check_updates' ); ?><button type="submit" class="button">Verificar atualizações agora</button></form><?php endif; ?>
        <?php
    }
}
