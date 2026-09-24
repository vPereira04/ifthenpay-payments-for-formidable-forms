<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Ajax\Controller;
use Ifthenpay\Formidable\Api\IfthenpayClient;
use Ifthenpay\Formidable\Settings\SettingsRepository;
use Ifthenpay\Formidable\Webhook\WebhookController;

/**
 * Renders two ifthenpay admin surfaces on Formidable's Global Settings
 * screen: the account/gateway settings as a pill tab inside Formidable's
 * own Payments section, and "Ifthenpay Extras" as its own top-level tab.
 *
 * I hook the gateway tab into `frm_payments_settings_form` instead of the
 * generic `frm_add_settings_section` Extras uses (see
 * `register_extras_settings_tab()`), because Formidable's own Payments pill
 * row is hardcoded with no filter of its own for a 3rd-party gateway to
 * register into. Both tabs still save through the one shared
 * `frm_update_settings` action (see `process_form()`), since all of
 * Formidable's Global Settings tabs share a single form/Save button.
 *
 * The Backoffice Key is never part of this form — it's AJAX-only
 * (write-only credential, see blueprint §8.4).
 */
class SettingsField {

	public static function boot() {
		add_action( 'frm_payments_settings_form', array( self::class, 'render_payments_pill_panel' ) );
		add_filter( 'frm_add_settings_section', array( self::class, 'register_extras_settings_tab' ) );
		add_action( 'frm_update_settings', array( self::class, 'process_form' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_hide_refund_script' ) );
		add_action( 'frm_pay_ifthenpay_sidebar', array( self::class, 'hide_refund_link' ) );
	}

	/**
	 * Stripe's sidebar template shows a "Refund" action link for every
	 * completed payment regardless of gateway, but ifthenpay Pay by Link has
	 * no refund API to back it. I print a static marker right after that
	 * link's DOM position (there can be more than one on this page) so
	 * `maybe_enqueue_hide_refund_script()`'s JS can hide just this payment's
	 * one — never an inline `<script>` tag here.
	 *
	 * @return void
	 */
	public static function hide_refund_link() {
		echo '<span class="iftp-frm-hide-refund-marker" style="display:none" aria-hidden="true"></span>';
	}

	/**
	 * Enqueues the script that acts on `hide_refund_link()`'s marker(s), only
	 * on the `formidable-payments` screen where they can occur. No script
	 * file of its own — this exists purely to carry the inline script below.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_hide_refund_script() {
		if ( 'formidable-payments' !== \FrmAppHelper::simple_get( 'page' ) ) {
			return;
		}

		wp_register_script( 'ifthenpay-frm-hide-refund', false, array(), IFTP_FRM_VERSION, true );
		wp_enqueue_script( 'ifthenpay-frm-hide-refund' );
		wp_add_inline_script(
			'ifthenpay-frm-hide-refund',
			"document.querySelectorAll( '.iftp-frm-hide-refund-marker' ).forEach( function ( marker ) {\n"
			. "\tvar prev = marker.previousElementSibling;\n"
			. "\tif ( prev && prev.classList.contains( 'misc-pub-section' ) ) {\n"
			. "\t\tprev.style.display = 'none';\n"
			. "\t}\n"
			. '} );'
		);
	}

	/**
	 * I use filemtime instead of the fixed IFTP_FRM_VERSION string so an
	 * actual CSS/JS edit during development always busts the browser cache;
	 * falls back to the release version if the file can't be stat'd (e.g. a
	 * packaged build).
	 *
	 * @param string $relative_path Relative to the plugin root, e.g. 'assets/css/admin.css'.
	 *
	 * @return string
	 */
	public static function asset_version( $relative_path ) {
		$mtime = @filemtime( IFTP_FRM_DIR . $relative_path );

		return $mtime ? (string) $mtime : IFTP_FRM_VERSION;
	}

	public static function maybe_enqueue_assets() {
		if ( ! self::on_settings_page() ) {
			return;
		}

		// I load frontend.css here too so the Extras tab's live button
		// preview uses the exact same styles as the real pay button.
		wp_enqueue_style( 'ifthenpay-frm-frontend', IFTP_FRM_URL . 'assets/css/frontend.css', array(), self::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_style( 'ifthenpay-frm-admin', IFTP_FRM_URL . 'assets/css/admin.css', array( 'ifthenpay-frm-frontend' ), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'ifthenpay-frm-admin', IFTP_FRM_URL . 'assets/js/admin.js', array( 'jquery' ), self::asset_version( 'assets/js/admin.js' ), true );

		$settings = new SettingsRepository();

		wp_localize_script(
			'ifthenpay-frm-admin',
			'iftpFrmAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( Controller::NONCE_ACTION ),
				'logoUrl'       => IFTP_FRM_URL . 'assets/img/logo-color.svg',
				// White mark for the button preview — logoUrl above is the
				// colored mark, which would be invisible against the
				// button's own colored background.
				'buttonLogoUrl' => IFTP_FRM_URL . 'assets/img/logo_ifthenpay_white.svg',
				'i18n'          => array(
					'connecting'          => __( 'Connecting…', 'ifthenpay-payments-for-formidable-forms' ),
					'requesting'          => __( 'Requesting…', 'ifthenpay-payments-for-formidable-forms' ),
					'requested'           => __( 'Requested', 'ifthenpay-payments-for-formidable-forms' ),
					'loadingTable'        => __( 'Loading payment methods…', 'ifthenpay-payments-for-formidable-forms' ),
					'selectGatewayPrompt' => __( 'Select a Gateway Key to load its payment methods.', 'ifthenpay-payments-for-formidable-forms' ),
					'genericError'        => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-formidable-forms' ),
					'ifthenpay'           => __( 'ifthenpay', 'ifthenpay-payments-for-formidable-forms' ),
					'saving'              => __( 'Saving…', 'ifthenpay-payments-for-formidable-forms' ),
					'defaultButtonText'   => $settings->get_button_text(),
				),
				'hasBackofficeKey' => $settings->has_backoffice_key(),
			)
		);
	}

	/**
	 * Covers both the Global Settings Payments pill and the per-form editor's
	 * "Collect Payment" gateway tab — both are cheap no-ops when their
	 * markup isn't on the page.
	 *
	 * @return bool
	 */
	private static function on_settings_page() {
		return in_array( \FrmAppHelper::simple_get( 'page' ), array( 'formidable-settings', 'formidable' ), true );
	}

	/**
	 * Prints ifthenpay's hidden panel as a sibling of Formidable's own
	 * PayPal/Stripe/Square panels. Starts hidden (`frm_hidden`) like every
	 * inactive panel there — the JS-inserted pill's data-frmshow/data-frmhide
	 * is what reveals it.
	 *
	 * @return void
	 */
	public static function render_payments_pill_panel() {
		$settings    = new SettingsRepository();
		$connected   = $settings->has_backoffice_key();
		$gateway_key = $settings->get_gateway_key();

		// I live-fetch from the ifthenpay API on every render instead of
		// trusting the saved snapshot, so the tab never drifts from what's
		// actually configured (see fetch_live_gateway_state() for the
		// fallback on a transient API failure).
		$live         = $connected
			? Controller::fetch_live_gateway_state( $settings )
			: array(
				'gateway_keys' => array(),
				'methods'      => array(),
			);
		$gateway_keys = $live['gateway_keys'];
		$methods      = $live['methods'];
		?>
		<div id="frm_ifthenpay_settings_section" class="frm_payments_section frm_hidden" role="tabpanel">
			<?php include IFTP_FRM_DIR . 'src/Admin/views/settings-tab.php'; ?>
		</div>
		<?php
	}

	/**
	 * Adds "Ifthenpay Extras" as its own top-level Global Settings tab — the
	 * generic extension point, unlike the Payments-pill mechanism
	 * `render_payments_pill_panel()` needs (see class docblock).
	 *
	 * @param array<array> $sections
	 *
	 * @return array<array>
	 */
	public static function register_extras_settings_tab( $sections ) {
		$sections['ifthenpay_extras'] = array(
			'class'      => self::class,
			'function'   => 'render_extras_settings_tab',
			'name'       => __( 'Ifthenpay Extras', 'ifthenpay-payments-for-formidable-forms' ),
			// Same glyph as Formidable's own "Manage Styles" tab — the
			// previous icon needed a CSS scale-up hack to match its neighbors.
			'icon'       => 'frmfont frm_pallet_icon',
			'html_class' => 'iftp-frm-extras-tab',
		);

		return $sections;
	}

	public static function render_extras_settings_tab() {
		$settings = new SettingsRepository();
		include IFTP_FRM_DIR . 'src/Admin/views/confirmation-settings-tab.php';
	}

	/**
	 * Renders just the `<tr>` rows — shared between the initial page render
	 * and the AJAX response from `Ajax\Controller::select_gateway_key()` so
	 * the markup never drifts between the two (blueprint §8.2).
	 *
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $gateway_key
	 * @param string                            $default_method
	 *
	 * @return string
	 */
	public static function render_methods_table_rows( array $methods, $gateway_key, $default_method ) {
		ob_start();
		include IFTP_FRM_DIR . 'src/Admin/views/methods-table-rows.php';
		return (string) ob_get_clean();
	}

	/**
	 * Persists everything on this tab except the Backoffice Key (AJAX-only,
	 * see class docblock) and the Gateway Key + methods snapshot, already
	 * saved by `Ajax\Controller::select_gateway_key()` the moment the
	 * merchant picked it.
	 *
	 * @return void
	 */
	public static function process_form() {
		if ( ! isset( $_POST['frm_ifthenpay_settings_submitted'] ) ) {
			return;
		}

		// I re-verify Formidable's own process_form nonce explicitly here so
		// this function stays safe even if that upstream hook gate ever changes.
		if ( ! isset( $_POST['process_form'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['process_form'] ) ), 'process_form_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			return;
		}

		$settings = new SettingsRepository();

		if ( isset( $_POST['frm_ifthenpay_expiry_days'] ) ) {
			$settings->save_expiry_days( absint( wp_unslash( $_POST['frm_ifthenpay_expiry_days'] ) ) );
		}

		$enabled_entities = isset( $_POST['frm_ifthenpay_methods_enabled'] ) && is_array( $_POST['frm_ifthenpay_methods_enabled'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['frm_ifthenpay_methods_enabled'] ) )
			: array();

		$default_method = isset( $_POST['frm_ifthenpay_default_method'] ) ? sanitize_text_field( wp_unslash( $_POST['frm_ifthenpay_default_method'] ) ) : '';

		$settings->apply_enabled_methods( $enabled_entities, $default_method );

		self::save_popup_messages( $settings );
		self::save_outcome_redirects( $settings );
		self::save_extra_settings( $settings );
		self::maybe_activate_callback( $settings );
	}

	/**
	 * Persists the Payment Received / Pending popup messages — each is
	 * optional, an empty submission just keeps the built-in default text.
	 * Canceled/Failed have no message setting; they always show a fixed
	 * message (see RedirectHandler::resolve_message()).
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function save_popup_messages( SettingsRepository $settings ) {
		$fields = array(
			'frm_ifthenpay_msg_success' => 'save_success_message',
			'frm_ifthenpay_msg_pending' => 'save_pending_message',
		);

		foreach ( $fields as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce/capability already verified in SettingsField::process_form() before this is called.
				$settings->$setter( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- sanitized inside the setter.
			}
		}
	}

	/**
	 * Persists the Show Message / Redirect / Open-in-a-New-Tab mode + target
	 * URL for Payment Received and Pending. Just persists whatever was
	 * submitted — same "every field optional" contract as
	 * save_popup_messages() above.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function save_outcome_redirects( SettingsRepository $settings ) {
		$modes = array(
			'frm_ifthenpay_mode_success' => 'save_success_mode',
			'frm_ifthenpay_mode_pending' => 'save_pending_mode',
		);

		foreach ( $modes as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce/capability already verified in SettingsField::process_form() before this is called.
				$settings->$setter( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$urls = array(
			'frm_ifthenpay_url_success' => 'save_success_redirect_url',
			'frm_ifthenpay_url_pending' => 'save_pending_redirect_url',
		);

		foreach ( $urls as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce/capability already verified in SettingsField::process_form() before this is called.
				$settings->$setter( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- sanitized (esc_url_raw) inside the setter.
			}
		}
	}

	/**
	 * Persists the pay button's icons/text customization. The "enabled"
	 * checkbox only submits when checked, so its absence means "unchecked" —
	 * unlike the "absent means keep default" contract the other save_*()
	 * methods use.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function save_extra_settings( SettingsRepository $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce/capability already verified in SettingsField::process_form() before this is called.
		$settings->save_method_icons_disabled( isset( $_POST['frm_ifthenpay_disable_method_icons'] ) );

		if ( isset( $_POST['frm_ifthenpay_button_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce/capability already verified in SettingsField::process_form() before this is called.
			$settings->save_button_text( wp_unslash( $_POST['frm_ifthenpay_button_text'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- sanitized inside the setter.
		}
	}

	/**
	 * Re-registers the webhook callback with ifthenpay on every settings
	 * save, same convention other ifthenpay gateway integrations use.
	 * Idempotent on ifthenpay's side; best-effort here, a failure never
	 * blocks the rest of the settings save.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function maybe_activate_callback( SettingsRepository $settings ) {
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key ) {
			return;
		}

		IfthenpayClient::activate_callback( $gateway_key, WebhookController::base_url() );
	}
}
