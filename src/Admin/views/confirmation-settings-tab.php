<?php
/**
 * The "Ifthenpay Extras" top-level Global Settings tab — outcome
 * messages/redirects below, plus pay button icons/text further down. The
 * Gateway tab (Backoffice Key / Gateway Key / Methods) lives separately in
 * `settings-tab.php`.
 *
 * Expects: $settings (SettingsRepository).
 *
 * @package Ifthenpay\Formidable
 */

use Ifthenpay\Formidable\Frontend\PaymentSelector;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}
?>
<p class="frm-description">
	<?php esc_html_e( 'Shown once the payer is sent back from the ifthenpay hosted page. Message fields support Formidable field shortcodes like [21]; Redirect/Open-in-a-New-Tab URLs are plain URLs only. "Open in a New Tab" shows the message here while also opening the URL in a new tab — handy for pointing Payment Pending to the same "Thank you" page as Payment Received, with its own wording for the still-confirming case. For Payment Received specifically: a form with its own native "On Submit → Redirect to URL" action always keeps using that instead of anything set here; otherwise "Show Message" shows the form\'s own native message when it has one, while "Open in a New Tab" always uses the message configured here.', 'ifthenpay-payments-for-formidable-forms' ); ?>
</p>
<p class="frm-description">
	<?php esc_html_e( 'Payment Canceled and Payment Failed are not configurable: the payer is always sent back to the form and shown a fixed message.', 'ifthenpay-payments-for-formidable-forms' ); ?>
</p>
<?php
$iftp_frm_mode_labels = array(
	'message'      => __( 'Show Message', 'ifthenpay-payments-for-formidable-forms' ),
	'redirect'     => __( 'Redirect to URL', 'ifthenpay-payments-for-formidable-forms' ),
	'open_new_tab' => __( 'Open in a New Tab', 'ifthenpay-payments-for-formidable-forms' ),
);
// Hand-rolled inline SVGs, not Formidable's own bundled sprite — it has no
// symbol for any of these.
$iftp_frm_mode_icons = array(
	'message'      => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4 8.9 8.9 0 0 1-3.8-.9L3 21l1.9-5.4A8.4 8.4 0 1 1 21 11.5z"/></svg>',
	'redirect'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
	'open_new_tab' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>',
);

$iftp_frm_outcomes = array(
	'success' => array(
		'label' => __( 'Payment Received', 'ifthenpay-payments-for-formidable-forms' ),
		'mode'  => $settings->get_success_mode(),
		'msg'   => $settings->get_success_message(),
		'url'   => $settings->get_success_redirect_url(),
		'modes' => array( 'message', 'redirect', 'open_new_tab' ),
	),
	'pending' => array(
		'label' => __( 'Payment Pending', 'ifthenpay-payments-for-formidable-forms' ),
		'mode'  => $settings->get_pending_mode(),
		'msg'   => $settings->get_pending_message(),
		'url'   => $settings->get_pending_redirect_url(),
		'modes' => array( 'message', 'redirect', 'open_new_tab' ),
	),
);
?>
<table class="form-table">
	<?php foreach ( $iftp_frm_outcomes as $iftp_frm_outcome_key => $iftp_frm_outcome ) : ?>
		<tr class="form-field">
			<th scope="row"><?php echo esc_html( $iftp_frm_outcome['label'] ); ?></th>
			<td>
				<div class="iftp-frm-outcome-mode" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>">
					<?php foreach ( $iftp_frm_outcome['modes'] as $iftp_frm_mode_key ) : ?>
						<label class="iftp-frm-outcome-box">
							<input type="radio" name="frm_ifthenpay_mode_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" value="<?php echo esc_attr( $iftp_frm_mode_key ); ?>" class="iftp-frm-outcome-mode-input" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" <?php checked( $iftp_frm_outcome['mode'], $iftp_frm_mode_key ); ?> />
							<span class="iftp-frm-outcome-box__icon"><?php echo $iftp_frm_mode_icons[ $iftp_frm_mode_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?></span>
							<span class="iftp-frm-outcome-box__label"><?php echo esc_html( $iftp_frm_mode_labels[ $iftp_frm_mode_key ] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php
				// "Open in a New Tab" needs both fields shown at once, so only
				// "Redirect to URL" hides the message field, and only "Show
				// Message" hides the URL field.
				$iftp_frm_message_hidden = 'redirect' === $iftp_frm_outcome['mode'];
				$iftp_frm_url_hidden     = 'message' === $iftp_frm_outcome['mode'];
				?>
				<div class="iftp-frm-outcome-field <?php echo esc_attr( $iftp_frm_message_hidden ? 'frm_hidden' : '' ); ?>" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" data-mode="message">
					<label class="iftp-frm-outcome-field__label" for="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>"><?php esc_html_e( 'Message', 'ifthenpay-payments-for-formidable-forms' ); ?></label>
					<textarea id="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" name="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" rows="2" class="frm_with_left_label" style="width:100%;max-width:740px;border-radius: 6px;border-color: #d0d0d0;color: #4f4f4f;"><?php echo esc_textarea( $iftp_frm_outcome['msg'] ); ?></textarea>
				</div>
				<div class="iftp-frm-outcome-field <?php echo esc_attr( $iftp_frm_url_hidden ? 'frm_hidden' : '' ); ?>" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" data-mode="url">
					<label class="iftp-frm-outcome-field__label" for="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>"><?php esc_html_e( 'URL', 'ifthenpay-payments-for-formidable-forms' ); ?></label>
					<input type="url" id="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" name="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" class="frm_with_left_label" style="width:100%;max-width:740px;border-radius: 6px;border-color: #d0d0d0;color: #4f4f4f;" placeholder="https://" value="<?php echo esc_attr( $iftp_frm_outcome['url'] ); ?>" />
				</div>
			</td>
		</tr>
	<?php endforeach; ?>
</table>

<h2><?php esc_html_e( 'Pay Button', 'ifthenpay-payments-for-formidable-forms' ); ?></h2>
<p class="frm-description">
	<?php esc_html_e( 'Controls how the ifthenpay pay button looks on the form itself, not the confirmation screen above.', 'ifthenpay-payments-for-formidable-forms' ); ?>
</p>
<table class="form-table">
	<tr class="form-field">
		<th scope="row"><label for="frm_ifthenpay_disable_method_icons"><?php esc_html_e( 'Payment Method Icons', 'ifthenpay-payments-for-formidable-forms' ); ?></label></th>
		<td>
			<label>
				<input type="checkbox" id="frm_ifthenpay_disable_method_icons" name="frm_ifthenpay_disable_method_icons" value="1" class="iftp-frm-extra-toggle" <?php checked( $settings->is_method_icons_disabled() ); ?> />
				<?php esc_html_e( 'Hide the row of payment method icons shown above the pay button', 'ifthenpay-payments-for-formidable-forms' ); ?>
			</label>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="frm_ifthenpay_button_text"><?php esc_html_e( 'Button Text', 'ifthenpay-payments-for-formidable-forms' ); ?></label></th>
		<td>
			<input type="text" id="frm_ifthenpay_button_text" name="frm_ifthenpay_button_text" class="regular-text iftp-frm-extra-toggle" value="<?php echo esc_attr( $settings->get_button_text() ); ?>" placeholder="<?php esc_attr_e( 'Pay with {logo}', 'ifthenpay-payments-for-formidable-forms' ); ?>" />
			<p class="frm-description">
				<?php esc_html_e( 'Use {logo} to place the ifthenpay logo inside your own text, e.g. "Pay here with {logo}". Leave {logo} out entirely to show text only, with no logo.', 'ifthenpay-payments-for-formidable-forms' ); ?>
			</p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><?php esc_html_e( 'Preview', 'ifthenpay-payments-for-formidable-forms' ); ?></th>
		<td>
			<button type="button" class="iftp-frm-pay-button iftp-frm-extra-preview" id="iftp-frm-button-preview" tabindex="-1">
				<?php echo PaymentSelector::render_button_content( $settings->get_button_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in render_button_content(). ?>
			</button>
		</td>
	</tr>
</table>
