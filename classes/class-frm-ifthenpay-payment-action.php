<?php
/**
 * Global, non-namespaced — same reasoning as class-frm-ifthenpay-actions-controller.php:
 * it extends `FrmTransLiteAction`, a global class Formidable's own
 * `frm_registered_form_actions` machinery resolves and instantiates by the
 * bare class name string we hand it, with no namespace involved anywhere in
 * that path.
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

/**
 * Gives ifthenpay its own dedicated "Add Action" card (data-actiontype
 * `ifthenpay`) in the form builder's E-Commerce group, next to Stripe/Square/
 * PayPal's own cards — instead of only ever being reachable as one checkbox
 * inside the generic, shared "Collect a Payment" card. Extends
 * `FrmTransLiteAction` (rather than duplicating it) so the settings form,
 * field-mapping helpers, and defaults handling all stay byte-for-byte the
 * same proven code the shared card already uses — only the card's own
 * identity (id_base, name, icon, color) and its default gateway selection
 * differ.
 *
 * Clicking this card's own "Add" button creates a normal `payment`-style
 * action (Formidable stores it under this class's own id_base,
 * `ifthenpay_payment`) with ifthenpay already checked as the gateway — see
 * `get_defaults()` — satisfying "click + and it's added with ours already
 * selected" without touching the existing shared card at all: a merchant
 * who genuinely wants Stripe/Square/PayPal can still use their own cards,
 * or the shared one, exactly as before.
 */
class FrmIfthenpayPaymentAction extends FrmTransLiteAction {

	public function __construct() {
		$action_ops = array(
			// 'frmfont ' (with the trailing space) is what makes
			// FrmAppHelper::icon_by_class() treat this as an SVG-sprite
			// reference (`<svg><use href="#ifthenpay_icon">`) rather than an
			// icon-webfont glyph — 'ifthenpay_icon' is the <symbol> our own
			// FrmIfthenpayActionsController::print_icon_sprite() appends to
			// the page (Formidable's own icons.svg has no filter to add to
			// it directly), built from assets/img/ifthenpay.svg. This one
			// `action_options` entry is what both the "Add Action" card and
			// the action's own header render their icon from, so fixing it
			// here fixes both.
			'classes'     => 'frmfont ifthenpay_icon',
			'color'       => '#00a768',
			// "Stripe Lite only supports a single action" applies here too —
			// this is still the same one-payment-action-per-form model, just
			// reached from a second card.
			'limit'       => 1,
			'active'      => true,
			'priority'    => 45,
			'event'       => array( 'create' ),
			'description' => __( 'Payment gateway', 'ifthenpay-payments-for-formidable-forms' ),
		);

		$this->FrmFormAction( 'ifthenpay_payment', 'ifthenpay', $action_ops );
	}

	/**
	 * Same defaults as the shared "Collect a Payment" action
	 * (`FrmTransLiteAction::get_defaults()`), except `gateway` starts
	 * pre-checked to ifthenpay instead of empty — the whole point of this
	 * card existing separately.
	 *
	 * @return array
	 */
	public function get_defaults() {
		$defaults            = parent::get_defaults();
		$defaults['gateway'] = array( 'ifthenpay' );

		return $defaults;
	}

	/**
	 * ifthenpay has no recurring/subscription support (`add_gateway()`
	 * already registers it with `'recurring' => false`) — the inherited
	 * `FrmTransLiteAction::form()` template has no filter of its own for its
	 * "Payment Type" select, so the "Recurring" `<option>` is stripped from
	 * its already-rendered output instead of duplicating that whole template
	 * just to drop one line. The "Recurring Payment Settings" section further
	 * down that same template only ever reveals itself via JS gated on this
	 * select's value, so removing the option here is enough to keep that
	 * section unreachable too — no separate strip needed for it.
	 *
	 * @param WP_Post $instance
	 * @param array   $args
	 *
	 * @return void
	 */
	public function form( $instance, $args = array() ) {
		ob_start();
		parent::form( $instance, $args );
		$html = (string) ob_get_clean();
		$html = preg_replace( '/\s*<option value="recurring"[^>]*>.*?<\/option>/s', '', $html, 1 );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Formidable's own already-escaped settings markup, with one <option> stripped out of it above.
	}
}
