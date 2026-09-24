<?php
/**
 * Plugin Name:       ifthenpay | Payments for Formidable Forms
 * Plugin URI:        https://github.com/vPereira04/ifthenpay-payments-for-formidable-forms
 * Description:       ifthenpay Pay by Link integration for Formidable Forms. Requires the free Formidable Forms plugin (with its bundled payments module).
 * Version:           1.0.0
 * Tested up to:      7.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  formidable
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-formidable-forms
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_FRM_VERSION', '1.0.0' );
define( 'IFTP_FRM_FILE', __FILE__ );
define( 'IFTP_FRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_FRM_URL', plugin_dir_url( __FILE__ ) );
define( 'IFTP_FRM_SLUG', 'iftp_frm' );

// I use the same "iftp_{tag}_{version}" slug pattern as the FluentForms
// integration for this callback endpoint, so I don't expose admin-ajax.php
// to ifthenpay.
define( 'IFTP_FRM_CALLBACK_SLUG', 'iftp_frm_' );

$ifthenpay_frm_dir      = plugin_dir_path( __FILE__ );
$ifthenpay_frm_autoload = $ifthenpay_frm_dir . 'vendor/autoload.php';

if ( file_exists( $ifthenpay_frm_autoload ) ) {
	require_once $ifthenpay_frm_autoload;
} else {
	// Composer's vendor/ folder wasn't installed, so I fall back to a small
	// PSR-4 autoloader for our own namespace to keep the plugin working.
	spl_autoload_register(
		function ( $class ) use ( $ifthenpay_frm_dir ) {
			$prefix = 'Ifthenpay\\Formidable\\';

			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $ifthenpay_frm_dir . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action( 'plugins_loaded', 'ifthenpay_frm_boot', 20 );

/**
 * I defer this to priority 20 so Formidable's own `plugins_loaded` boot
 * (priority 0) has already registered its classes before I check for them.
 */
function ifthenpay_frm_boot() {
	// I need autoload enabled here — Formidable defines its classes lazily via
	// its own autoloader, so class_exists( ..., false ) would always miss them.
	if ( ! class_exists( 'FrmFormAction' ) ) {
		add_action( 'admin_notices', 'ifthenpay_frm_missing_formidable_notice' );
		return;
	}

	// Formidable's free "Lite" payments module isn't loaded (e.g. the paid
	// "Payments" add-on replaced it) — supporting that separate registry is
	// out of scope (blueprint §7.2).
	if ( ! class_exists( 'FrmTransLiteActionsController' ) || ! class_exists( 'FrmTransLiteAppHelper' ) ) {
		add_action( 'admin_notices', 'ifthenpay_frm_missing_payments_module_notice' );
		return;
	}

	// I require this directly rather than via autoloader — Formidable calls
	// this one class by a dynamically-built global class name (blueprint §3a).
	require_once IFTP_FRM_DIR . 'classes/class-frm-ifthenpay-actions-controller.php';

	FrmIfthenpayActionsController::boot();

	\Ifthenpay\Formidable\Gateway\IfthenpayGateway::boot();
	\Ifthenpay\Formidable\Frontend\RedirectHandler::boot();
	\Ifthenpay\Formidable\Frontend\PaymentSelector::boot();
	\Ifthenpay\Formidable\Ajax\Controller::boot();
	\Ifthenpay\Formidable\Webhook\WebhookController::boot();
	\Ifthenpay\Formidable\Admin\SettingsField::boot();
}

function ifthenpay_frm_missing_formidable_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'ifthenpay Payments for Formidable Forms requires the free Formidable Forms plugin to be installed and active.', 'ifthenpay-payments-for-formidable-forms' ) .
		'</p></div>';
}

function ifthenpay_frm_missing_payments_module_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'ifthenpay Payments for Formidable Forms requires Formidable\'s free bundled payments module. It could not be found (the paid Formidable "Payments" add-on, if active, is not currently supported by this integration).', 'ifthenpay-payments-for-formidable-forms' ) .
		'</p></div>';
}

register_activation_hook( __FILE__, 'ifthenpay_frm_activate' );

function ifthenpay_frm_activate() {
	// Nothing to install — settings default lazily and the shared payments
	// table is Formidable's own. I just flush rewrite rules once here so the
	// callback URL works immediately, without a manual Permalinks re-save.
	add_rewrite_rule( IFTP_FRM_CALLBACK_SLUG . '/?$', 'index.php?' . IFTP_FRM_CALLBACK_SLUG . '=1', 'top' );
	flush_rewrite_rules();
}
