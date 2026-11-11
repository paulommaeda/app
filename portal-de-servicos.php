<?php
/**
 * Plugin Name: Portal de Serviços — Meu Combustível
 * Description: Portal com contas WordPress, catálogo extensível de serviços e controle individual de veículos e abastecimentos.
 * Version: 1.1.0
 * Plugin URI: https://github.com/paulommaeda/app
 * Update URI: https://github.com/paulommaeda/app
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Author: Paulo Maeda
 * Author URI: https://paulomaeda.com
 * License: GPL-2.0-or-later
 * Text Domain: portal-de-servicos
 */

defined( 'ABSPATH' ) || exit;

define( 'PDS_VERSION', '1.1.0' );
define( 'PDS_FILE', __FILE__ );
define( 'PDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDS_URL', plugin_dir_url( __FILE__ ) );

require_once PDS_DIR . 'includes/class-install.php';
require_once PDS_DIR . 'includes/services.php';
require_once PDS_DIR . 'includes/class-core.php';
require_once PDS_DIR . 'includes/class-auth.php';
require_once PDS_DIR . 'includes/class-fuel.php';
require_once PDS_DIR . 'includes/class-admin.php';
require_once PDS_DIR . 'includes/class-privacy.php';
require_once PDS_DIR . 'includes/class-updater.php';
require_once PDS_DIR . 'includes/class-legacy-reader.php';
require_once PDS_DIR . 'includes/class-import.php';

register_activation_hook( __FILE__, array( 'PDS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PDS_Install', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
    if ( get_option( 'pds_db_version' ) !== PDS_VERSION ) {
        PDS_Install::schema();
    }
    PDS_Core::boot();
    PDS_Auth::boot();
    PDS_Fuel::boot();
    PDS_Admin::boot();
    PDS_Privacy::boot();
    PDS_Updater::boot();
    PDS_Import::boot();
} );
