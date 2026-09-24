<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Makes ifthenpay a visible, selectable payment method on the form itself,
 * mirroring Formidable's own bundled PayPal module's pattern instead of
 * being an invisible, purely server-side gateway choice.
 *
 * I keep this as its own block rather than injecting into PayPal/Stripe's
 * selector — their radio group is built by a private JS closure with no
 * extension point. Rendered at `frm_before_submit_btn`, the generic hook
 * Formidable exposes regardless of whether a gateway-specific field is
 * present, since none of ifthenpay's methods are card-based.
 */
class PaymentSelector {

	public static function boot() {
		add_action( 'frm_before_submit_btn', array( self::class, 'maybe_render' ) );
	}

	/**
	 * @param array $args {
	 *     @type object $form
	 * }
	 *
	 * @return void
	 */
	public static function maybe_render( $args ) {
		$form = isset( $args['form'] ) ? $args['form'] : null;

		if ( ! $form || ! isset( $form->id ) || ! RedirectHandler::form_has_ifthenpay_payment_action( $form ) ) {
			return;
		}

		$settings = new SettingsRepository();
		$methods  = self::active_methods( $settings );

		if ( ! $methods ) {
			return;
		}

		echo self::render( $methods, RedirectHandler::color_style_for_form( $form ), $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built (and escaped piece by piece) in render()/render_method_icon()/render_button_content().
	}

	/**
	 * Same enabled+provisioned filter as
	 * `SettingsRepository::get_active_accounts_string()`, but keeping the
	 * full row (label, image_url) instead of collapsing to a string.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function active_methods( SettingsRepository $settings ) {
		$active = array();

		foreach ( $settings->get_methods() as $method ) {
			if ( empty( $method['enabled'] ) || empty( $method['provisioned'] ) ) {
				continue;
			}

			$active[] = $method;
		}

		return $active;
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $color_style Inline CSS custom properties from
	 *                                                        RedirectHandler::color_style_for_form().
	 * @param SettingsRepository                $settings
	 *
	 * @return string
	 */
	private static function render( array $methods, $color_style, SettingsRepository $settings ) {
		$hide_icons = $settings->is_method_icons_disabled();
		ob_start();
		?>
		<div class="iftp-frm-method-block iftp-frm-method-block--preselected" style="<?php echo esc_attr( $color_style ); ?>">
			<?php if ( ! $hide_icons ) : ?>
			<div class="iftp-frm-method-selector">
				<div class="iftp-frm-method-option">
					<?php /* No ifthenpay brand mark here — the button below already carries it, so this row reads purely as "here's what you can pay with". */ ?>
					<span class="iftp-frm-method-marks">
						<?php foreach ( $methods as $method ) : ?>
							<?php echo self::render_method_icon( $method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()/esc_attr() already applied inside render_method_icon() itself. ?>
						<?php endforeach; ?>
					</span>
				</div>
			</div>
			<?php endif; ?>
			<div class="iftp-frm-method-action">
				<?php /* A real submit button, deliberately — this needs to go through Formidable's own native AJAX submission (validation included), not a separate endpoint. See assets/js/frontend.js. */ ?>
				<button type="submit" class="iftp-frm-pay-button">
					<?php echo self::render_button_content( $settings->get_button_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in render_button_content(). ?>
				</button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Splits the merchant's custom button text on the literal `{logo}` token
	 * to interleave the ifthenpay logo wherever they placed it — the token
	 * is the only control over the logo, never auto-appended. Public and
	 * shared with the admin live preview (`confirmation-settings-tab.php`)
	 * so the two never drift.
	 *
	 * @param string $button_text
	 *
	 * @return string
	 */
	public static function render_button_content( $button_text ) {
		if ( false === strpos( $button_text, '{logo}' ) ) {
			return '<span class="iftp-frm-pay-button__text">' . esc_html( $button_text ) . '</span>';
		}

		$logo = sprintf(
			'<img class="iftp-frm-pay-button__logo" src="%1$s" alt="%2$s" loading="lazy" />',
			esc_url( IFTP_FRM_URL . 'assets/img/logo_ifthenpay_white.svg' ),
			esc_attr__( 'ifthenpay', 'ifthenpay-payments-for-formidable-forms' )
		);

		$parts  = explode( '{logo}', $button_text );
		$output = '';

		foreach ( $parts as $i => $part ) {
			$part = trim( $part );

			if ( '' !== $part ) {
				$output .= '<span class="iftp-frm-pay-button__text">' . esc_html( $part ) . '</span>';
			}

			if ( $i < count( $parts ) - 1 ) {
				$output .= $logo;
			}
		}

		return $output;
	}

	/**
	 * @param array<string, mixed> $method
	 *
	 * @return string
	 */
	private static function render_method_icon( array $method ) {
		$entity    = isset( $method['entity'] ) ? strtoupper( $method['entity'] ) : '';
		$label     = isset( $method['label'] ) ? $method['label'] : $entity;
		$image_url = ! empty( $method['image_url'] )
			? $method['image_url']
			: \Ifthenpay\Formidable\Api\IfthenpayPayload::fallback_logo_url( $entity );

		return sprintf(
			'<img class="iftp-frm-method-mark" src="%1$s" alt="%2$s" loading="lazy" />',
			esc_url( $image_url ),
			esc_attr( $label )
		);
	}
}
