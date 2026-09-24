<?php
/**
 * Global, non-namespaced bridge class — deliberately so.
 *
 * Formidable Lite's `FrmTransLiteActionsController::trigger_action()` resolves
 * the gateway controller by building a global class name string at runtime
 * (`'Frm' . $class_name . 'ActionsController'`) with no `class_exists()`
 * guard, so a namespaced class would never be found and would fatal instead
 * of failing gracefully. That's why I keep this one class global and
 * `require_once` it directly from the bootstrap; every other class here is
 * namespaced and PSR-4 autoloaded (see blueprint §3a).
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

class FrmIfthenpayActionsController extends FrmTransLiteActionsController {

	public static function boot() {
		add_filter( 'frm_payment_gateways', array( self::class, 'add_gateway' ) );
		add_filter( 'frm_registered_form_actions', array( self::class, 'register_actions' ), 20 );
		add_action( 'frm_add_form_option_section', array( self::class, 'actions_js' ) );
		add_filter( 'frm_before_save_payment_action', array( self::class, 'before_save_settings' ), 10, 2 );

		// Gives ifthenpay its own "Add Action" card — see
		// class-frm-ifthenpay-payment-action.php's own docblock for why this
		// is a second, dedicated card rather than a change to the shared one.
		require_once IFTP_FRM_DIR . 'classes/class-frm-ifthenpay-payment-action.php';
		add_filter( 'frm_registered_form_actions', array( self::class, 'register_dedicated_action' ), 20 );
		add_filter( 'frm_action_groups', array( self::class, 'add_dedicated_action_to_payment_group' ) );
		add_filter( 'frm_before_save_ifthenpay_payment_action', array( self::class, 'force_ifthenpay_gateway' ) );
		add_action( 'frm_trigger_ifthenpay_payment_action', array( 'FrmTransLiteActionsController', 'trigger_action' ), 10, 3 );

		// Without this, FrmFormActionsController::disable_unlicensed_actions()
		// would force our card into the same "Upgrade" locked state as an
		// actual Pro-only action on any site without a connected Pro license —
		// 'payment' (the shared card) is already exempted here by Formidable
		// itself; ours needs the same exemption since it's just as free to use.
		add_filter( 'frm_lite_form_actions', array( self::class, 'add_dedicated_action_to_lite_list' ) );

		// Every admin screen that can show our action's icon (the "Add
		// Action" card, its own header once added) renders it through
		// FrmAppHelper::icon_by_class() against Formidable's own icons.svg
		// sprite — which has no filter to add a symbol to it. admin_footer
		// always fires after that sprite (and after any card/header
		// referencing it) regardless of which Formidable controller rendered
		// the current screen, so appending a second, separate sprite there
		// is enough for the browser to resolve our own <use> reference —
		// same-document SVG <use> doesn't care about source order.
		add_action( 'admin_footer', array( self::class, 'print_icon_sprite' ) );
	}

	/**
	 * @param string[] $lite_actions
	 *
	 * @return string[]
	 */
	public static function add_dedicated_action_to_lite_list( $lite_actions ) {
		$lite_actions[] = 'ifthenpay_payment';
		return $lite_actions;
	}

	/**
	 * See boot()'s own comment for why this exists. Reshapes our bundled
	 * assets/img/ifthenpay.svg (an `<svg viewBox="…">…paths…</svg>`, meant to
	 * stand alone) into a `<symbol id="ifthenpay_icon" viewBox="…">…paths…</symbol>`
	 * Formidable's own `<use href="#ifthenpay_icon">` convention can
	 * reference, the same shape every one of Formidable's own icons takes in
	 * icons.svg.
	 *
	 * @return void
	 */
	public static function print_icon_sprite() {
		$symbol = self::build_icon_symbol();

		if ( '' === $symbol ) {
			return;
		}

		// Our own bundled asset file's markup, read from disk and only
		// reshaped below — never user input, so nothing here needs kses;
		// same trust level as Formidable's own FrmAppHelper::include_svg(),
		// which readfile()s its icons.svg the same way.
		echo '<svg style="display:none" aria-hidden="true">' . $symbol . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment above.
	}

	/**
	 * @return string Empty if assets/img/ifthenpay.svg is missing or not in the expected shape.
	 */
	private static function build_icon_symbol() {
		$path = IFTP_FRM_DIR . 'assets/img/ifthenpay.svg';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$svg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- a bundled plugin asset, not a remote or user-supplied file; no filesystem API context is available this early.

		if ( ! preg_match( '/<svg[^>]*viewBox="([^"]+)"[^>]*>(.*)<\/svg>/s', $svg, $matches ) ) {
			return '';
		}

		return '<symbol id="ifthenpay_icon" viewBox="' . esc_attr( $matches[1] ) . '">' . $matches[2] . '</symbol>';
	}

	/**
	 * Keeps the native Collect a Payment action registered with current Formidable versions.
	 *
	 * @param array $actions
	 *
	 * @return array
	 */
	public static function register_actions( $actions ) {
		$actions['payment'] = 'FrmTransLiteAction';
		return $actions;
	}

	/**
	 * @param array $actions
	 *
	 * @return array
	 */
	public static function register_dedicated_action( $actions ) {
		$actions['ifthenpay_payment'] = 'FrmIfthenpayPaymentAction';
		return $actions;
	}

	/**
	 * `FrmFormActionsController::get_payment_actions()` hardcodes the
	 * E-Commerce card grid's contents (`paypal`, `stripe`, `square`, plus
	 * `payment` when registered) with no filter of its own — this is the one
	 * point downstream, `form_action_groups()`'s own return value, that IS
	 * filterable, so ifthenpay's card can be placed in the same group without
	 * touching Formidable core.
	 *
	 * @param array $groups
	 *
	 * @return array
	 */
	public static function add_dedicated_action_to_payment_group( $groups ) {
		if ( isset( $groups['payment']['actions'] ) && is_array( $groups['payment']['actions'] ) ) {
			$groups['payment']['actions'][] = 'ifthenpay_payment';
		}

		return $groups;
	}

	/**
	 * This card exists specifically so ifthenpay never needs to be picked
	 * from the shared gateway checkbox list `FrmTransLiteAction::form()`
	 * still renders (inherited as-is, see class-frm-ifthenpay-payment-action.php) —
	 * so whatever was actually submitted for it is overridden back to
	 * ifthenpay on save, rather than left editable into some other gateway
	 * (or, per FrmTransLiteActionsController::before_save_settings()'s own
	 * fallback, silently defaulted to 'stripe' if left unchecked — never run
	 * for this action type in the first place, see boot()'s own comment).
	 *
	 * Also locks `type` to 'single' — ifthenpay has no recurring support (see
	 * `add_gateway()`'s own `'recurring' => false`), and
	 * `FrmIfthenpayPaymentAction::form()` already removes the "Recurring"
	 * choice from this action's own settings UI; this is the belt-and-braces
	 * backstop for a directly-tampered submission, not the primary defense.
	 *
	 * @param array $post_content
	 *
	 * @return array
	 */
	public static function force_ifthenpay_gateway( $post_content ) {
		$post_content['gateway'] = array( 'ifthenpay' );
		$post_content['type']    = 'single';
		return $post_content;
	}

	/**
	 * Loads Formidable's payment-action admin behavior for the action editor.
	 *
	 * @return void
	 */
	public static function actions_js() {
		if ( class_exists( 'FrmTransLiteActionsController' ) ) {
			FrmTransLiteActionsController::actions_js();
		}
	}

	/**
	 * Lets Formidable normalize and persist the native payment settings.
	 *
	 * @param array $settings
	 * @param array $action
	 *
	 * @return array
	 */
	public static function before_save_settings( $settings, $action ) {
		if ( class_exists( 'FrmTransLiteActionsController' ) ) {
			$settings = FrmTransLiteActionsController::before_save_settings( $settings, $action );
		}

		return $settings;
	}

	/**
	 * Registers ifthenpay into Formidable Lite's shared gateway registry,
	 * same as Stripe/Square/PayPal. `recurring => false` hides this gateway
	 * for recurring payment actions (Formidable's own gateway-buttons.php
	 * already handles that). `include => []` mirrors the built-in gateways'
	 * field-mapping key, but nothing in Lite actually reads it for us —
	 * ifthenpay's hosted page collects payer details itself.
	 *
	 * @param array $gateways
	 *
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		$gateways['ifthenpay'] = array(
			'label'      => 'ifthenpay',
			'user_label' => __( 'Payment', 'ifthenpay-payments-for-formidable-forms' ),
			'class'      => 'Ifthenpay',
			'recurring'  => false,
			'include'    => array(),
		);

		return $gateways;
	}

	/**
	 * Thin shim — I keep the one class Formidable finds by dynamic name
	 * trivial to reason about; all real logic lives in the namespaced
	 * Gateway\IfthenpayGateway.
	 *
	 * @param WP_Post  $action
	 * @param stdClass $entry
	 * @param mixed    $form
	 *
	 * @return array
	 */
	public static function trigger_gateway( $action, $entry, $form ) {
		return \Ifthenpay\Formidable\Gateway\IfthenpayGateway::trigger( $action, $entry, $form );
	}
}
