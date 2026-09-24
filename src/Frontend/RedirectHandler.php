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
 * Bridges an ifthenpay redirect URL into Formidable's own native "On Submit
 * → Redirect to URL" mechanism, so its existing AJAX `response.redirect`
 * handling carries the payer to the payment page with no custom front-end
 * JS required (blueprint §3e). The URL is a same-origin wrapper
 * (`handle_open()`), not the real ifthenpay URL, so `assets/js/frontend.js`
 * can open it in a *new* tab and let the original tab close itself (or show
 * a "continue in the other tab" notice).
 *
 * The new tab does everything else: it goes to ifthenpay, comes back via
 * `handle_return()`, and either redirects to the merchant's target or shows
 * a themed popup (`maybe_render_modal()`) — the real payment status is
 * always confirmed server-to-server by `WebhookController`, never by this
 * front-channel tab.
 *
 * I deliberately avoid a two-tab "original tab polls, new tab tries to
 * self-close" design — an earlier version worked that way and was fragile:
 * a same-origin redirect chain can lose the "opened by script" relationship
 * a same-tab `window.close()` needs, leaving the tab stuck open instead.
 */
class RedirectHandler {

	const TRANSIENT_PREFIX         = 'frm_ifthenpay_redirect_';
	const CONTEXT_TRANSIENT_PREFIX = 'frm_ifthenpay_context_';
	const TRANSIENT_TTL     = 600; // 10 minutes — long enough to cover the redirect step.

	/**
	 * Memoized compute_modal_data() — both maybe_enqueue_frontend_assets()
	 * and maybe_render_modal() need it, no reason to run its DB lookups twice.
	 *
	 * @var array|null
	 */
	private static $modal_data;

	/**
	 * @var bool
	 */
	private static $modal_data_computed = false;

	public static function boot() {
		add_filter( 'frm_redirect_url', array( self::class, 'maybe_override_redirect_url' ), 5, 3 );
		add_filter( 'frm_get_met_on_submit_actions', array( self::class, 'maybe_force_single_redirect_action' ), 10, 2 );
		add_filter( 'frm_form_attributes', array( self::class, 'maybe_mark_payment_form' ), 10, 2 );
		add_filter( 'frm_time_to_check_duplicates', array( self::class, 'maybe_disable_duplicate_check' ), 10, 2 );
		add_action( 'wp_ajax_ifthenpay_frm_return', array( self::class, 'handle_return' ) );
		add_action( 'wp_ajax_nopriv_ifthenpay_frm_return', array( self::class, 'handle_return' ) );
		add_action( 'wp_ajax_ifthenpay_frm_open', array( self::class, 'handle_open' ) );
		add_action( 'wp_ajax_nopriv_ifthenpay_frm_open', array( self::class, 'handle_open' ) );
		add_action( 'wp_ajax_ifthenpay_frm_check_status', array( self::class, 'handle_check_status' ) );
		add_action( 'wp_ajax_nopriv_ifthenpay_frm_check_status', array( self::class, 'handle_check_status' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( self::class, 'maybe_render_modal' ) );
	}

	/**
	 * @param int    $entry_id
	 * @param string $redirect_url   The real ifthenpay hosted payment page URL.
	 * @param string $token          Same one-time secret threaded through this entry's whole
	 *                               payment lifecycle — see IfthenpayGateway::trigger(). Required
	 *                               by handle_open() before it will hand the URL back out.
	 * @param string $transaction_id ifthenpay's own id for this attempt (its `RequestId`/
	 *                               `TransactionId`), if it returned one — used by
	 *                               maybe_sync_payment_status() (see its own docblock), empty
	 *                               string otherwise.
	 *
	 * @return void
	 */
	public static function remember_redirect( $entry_id, $redirect_url, $token, $transaction_id = '' ) {
		set_transient(
			self::TRANSIENT_PREFIX . (int) $entry_id,
			array(
				'url'            => esc_url_raw( $redirect_url ),
				'token'          => (string) $token,
				'transaction_id' => (string) $transaction_id,
			),
			self::TRANSIENT_TTL
		);
	}

	/**
	 * Remembers what Formidable would have done on its own — the form's real
	 * On Submit behavior, plus the page the payer actually submitted from —
	 * so `handle_return()` can hand the payer back to something resembling
	 * that native experience once ifthenpay is done with them instead of a
	 * bare `home_url('/')`.
	 *
	 * @param int   $entry_id
	 * @param array $context {
	 *     @type string $referrer     The page the form was submitted from — already
	 *                                validated by sanitize_referrer().
	 *     @type array  $success_info See `IfthenpayGateway::capture_real_success_info()`.
	 *     @type string $token        The one secret IfthenpayGateway::trigger() mints once
	 *                                and reuses for this entry's whole payment lifecycle —
	 *                                see its own docblock for the full list of endpoints
	 *                                this gates.
	 * }
	 *
	 * @return void
	 */
	public static function remember_context( $entry_id, array $context ) {
		set_transient( self::CONTEXT_TRANSIENT_PREFIX . (int) $entry_id, $context, self::TRANSIENT_TTL );
	}

	/**
	 * @param int $entry_id
	 *
	 * @return array
	 */
	private static function get_context( $entry_id ) {
		$context = get_transient( self::CONTEXT_TRANSIENT_PREFIX . (int) $entry_id );
		return is_array( $context ) ? $context : array();
	}

	/**
	 * Rejects anything that isn't a normal on-site front-end page — an
	 * admin-ajax.php URL (e.g. Formidable's own form-preview iframe), a
	 * wp-admin/wp-login screen, or an off-site URL. None of those render
	 * `wp_footer()`, so I fall back to the homepage rather than stranding
	 * the payer on a blank response.
	 *
	 * @param string|false $referrer
	 *
	 * @return string
	 */
	public static function sanitize_referrer( $referrer ) {
		if ( ! $referrer ) {
			return home_url( '/' );
		}

		$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( ! $referrer_host || ! $site_host || $referrer_host !== $site_host ) {
			return home_url( '/' );
		}

		$path = (string) wp_parse_url( $referrer, PHP_URL_PATH );

		if ( false !== stripos( $path, 'admin-ajax.php' ) || false !== stripos( $path, '/wp-admin/' ) || false !== stripos( $path, '/wp-login.php' ) ) {
			return home_url( '/' );
		}

		return esc_url_raw( $referrer );
	}

	/**
	 * Marks the `<form>` tag with `data-iftp-payment="1"` for any form with
	 * an active ifthenpay payment action — `assets/js/frontend.js` uses this
	 * to decide whether to pre-open a blank tab at submit time, rather than
	 * flashing one open-then-closed on every unrelated form.
	 *
	 * @param string $attributes
	 * @param object $form
	 *
	 * @return string
	 */
	public static function maybe_mark_payment_form( $attributes, $form ) {
		if ( self::form_has_ifthenpay_payment_action( $form ) ) {
			$attributes .= ' data-iftp-payment="1"';
		}

		return $attributes;
	}

	/**
	 * @param object $form
	 *
	 * @return bool
	 */
	public static function form_has_ifthenpay_payment_action( $form ) {
		if ( ! $form || ! isset( $form->id ) || ! class_exists( 'FrmFormAction' ) ) {
			return false;
		}

		// 'payment' is the shared "Collect a Payment" card (any gateway can be
		// checked on it); 'ifthenpay_payment' is our own dedicated card added
		// by FrmIfthenpayPaymentAction — a form can carry either one.
		foreach ( array( 'payment', 'ifthenpay_payment' ) as $action_type ) {
			foreach ( (array) \FrmFormAction::get_action_for_form( $form->id, $action_type ) as $payment_action ) {
				if ( self::action_allows_ifthenpay( $payment_action ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The `gateway` field is a checkbox group, not a single choice, so
	 * `post_content['gateway']` is stored as an array (e.g. `['ifthenpay']`)
	 * — comparing it directly against `'ifthenpay'` with `===` can never match.
	 *
	 * @param object $payment_action
	 *
	 * @return bool
	 */
	private static function action_allows_ifthenpay( $payment_action ) {
		if ( ! isset( $payment_action->post_content['gateway'] ) ) {
			return false;
		}

		return in_array( 'ifthenpay', (array) $payment_action->post_content['gateway'], true );
	}

	/**
	 * Formidable refuses a new entry when another with identical field
	 * values was created on the same form recently — but
	 * `IfthenpayGateway::trigger()` always creates a real entry per payment
	 * *attempt*, so a payer retrying after a failed/canceled/abandoned
	 * attempt would get wrongly told they already submitted. ifthenpay
	 * itself, not this heuristic, is what prevents a duplicate charge, so I
	 * disable the check entirely for ifthenpay payment forms.
	 *
	 * @param int   $seconds
	 * @param array $new_values `FrmEntry::package_entry_data()`'s output — includes `form_id`.
	 *
	 * @return int
	 */
	public static function maybe_disable_duplicate_check( $seconds, $new_values ) {
		$form_id = isset( $new_values['form_id'] ) ? (int) $new_values['form_id'] : 0;

		if ( ! $form_id || ! class_exists( 'FrmForm' ) ) {
			return $seconds;
		}

		$form = \FrmForm::getOne( $form_id );

		return $form && self::form_has_ifthenpay_payment_action( $form ) ? 0 : $seconds;
	}

	/**
	 * Filters Formidable's own "Redirect to URL" target — only for the one
	 * entry with a pending ifthenpay redirect, every other entry is left
	 * untouched. Returns handle_open()'s wrapper URL instead of the real
	 * ifthenpay URL, and forces `open_in_new_tab` on (see
	 * `force_open_in_new_tab()`) so Formidable's own `doRedirect()` opens a
	 * new tab instead of navigating the current one away — keeping the
	 * payer's original tab on the form long enough to hand off (see class
	 * docblock). With no JS, Formidable falls back to `window.location`,
	 * landing on the same handle_open() 302 in the current tab instead.
	 *
	 * @param string $url
	 * @param object $form
	 * @param array  $args
	 *
	 * @return string
	 */
	public static function maybe_override_redirect_url( $url, $form, $args ) {
		$entry_id = isset( $args['entry_id'] ) ? (int) $args['entry_id'] : 0;

		if ( ! $entry_id ) {
			return $url;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $entry_id );

		if ( ! is_array( $pending ) || empty( $pending['url'] ) ) {
			return $url;
		}

		self::strip_redirect_delay( $form );
		self::force_open_in_new_tab( $form );

		return add_query_arg(
			array(
				'action' => 'ifthenpay_frm_open',
				'entry'  => $entry_id,
				'token'  => isset( $pending['token'] ) ? $pending['token'] : '',
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Formidable only takes its fast single-action redirect path (which
	 * fires the `frmBeforeFormRedirect` event `assets/js/frontend.js` needs)
	 * when exactly one On Submit action is met. If a merchant's own
	 * confirmation is *also* met alongside our synthetic redirect one,
	 * Formidable instead forces a slow, delayed multi-action path that never
	 * fires that event — so I filter `$met_actions` down to just the
	 * redirect-type action(s) for the one entry mid-payment. Their own
	 * confirmations are simply deferred to `resolve_message()`'s popup once
	 * the payment resolves. Never returns an empty array — falls back to the
	 * unfiltered list if no redirect-type action is found.
	 *
	 * @param array $met_actions Formidable's own On Submit actions that meet this entry's conditional logic.
	 * @param array $args        See `FrmFormsController::get_met_on_submit_actions()` — includes `entry_id`.
	 *
	 * @return array
	 */
	public static function maybe_force_single_redirect_action( $met_actions, $args ) {
		$entry_id = isset( $args['entry_id'] ) ? (int) $args['entry_id'] : 0;

		if ( ! $entry_id || ! class_exists( 'FrmOnSubmitHelper' ) ) {
			return $met_actions;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $entry_id );

		if ( ! is_array( $pending ) || empty( $pending['url'] ) ) {
			return $met_actions;
		}

		$redirect_only = array();

		foreach ( (array) $met_actions as $action ) {
			if ( 'redirect' === \FrmOnSubmitHelper::get_action_type( $action ) ) {
				$redirect_only[] = $action;
			}
		}

		return $redirect_only ? $redirect_only : $met_actions;
	}

	/**
	 * Zeroes Formidable's own "Redirect" action delay for this form —
	 * whatever action `ensure_redirect_action_exists()` reused might have a
	 * delay meant for a merchant's own thank-you-page hand-off, but the
	 * ifthenpay redirect must never be delayed. I mutate `$form` directly
	 * (the same object `redirect_after_submit()` reads
	 * `options['redirect_delay']` from later) since hooking
	 * `frm_get_run_success_action_args` only fires for migrated forms. Also
	 * zeroes `redirect_delay_time` as a cheap second line of defense, in
	 * case the delayed-JS path is ever reached anyway.
	 *
	 * @param object $form
	 *
	 * @return void
	 */
	private static function strip_redirect_delay( $form ) {
		if ( isset( $form->options ) && is_array( $form->options ) ) {
			if ( isset( $form->options['redirect_delay'] ) ) {
				$form->options['redirect_delay'] = '';
			}

			if ( isset( $form->options['redirect_delay_time'] ) ) {
				$form->options['redirect_delay_time'] = 0;
			}
		}
	}

	/**
	 * Forces `openInNewTab: 1` onto Formidable's own AJAX response for this
	 * one redirect, mutating `$form` directly for the same reason as
	 * strip_redirect_delay() above, so `doRedirect()` opens a new tab
	 * instead of navigating the current one away. Every other submission on
	 * this form keeps whatever the merchant actually configured.
	 *
	 * @param object $form
	 *
	 * @return void
	 */
	private static function force_open_in_new_tab( $form ) {
		if ( isset( $form->options ) && is_array( $form->options ) ) {
			$form->options['open_in_new_tab'] = true;
		}
	}

	/**
	 * 302s to the real ifthenpay hosted payment page. Token-gated — without
	 * it, small sequential entry ids would let anyone probe
	 * `?entry=<guessed-id>` and get redirected into another payer's live
	 * payment link (an avoidable info leak even though their card can't be
	 * charged without their own action there).
	 *
	 * @return void
	 */
	public static function handle_open() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- same-tab hand-off immediately after an AJAX submit, not a WP form submission; token-gated below.
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$stored = $entry_id ? get_transient( self::TRANSIENT_PREFIX . $entry_id ) : false;

		if ( is_array( $stored ) && ! empty( $stored['url'] ) && ! empty( $stored['token'] ) && hash_equals( $stored['token'], $token ) ) {
			wp_redirect( $stored['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- the ifthenpay hosted payment page, off-site by design.
			exit;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Polled by the popup itself (see `frontend.js`'s `pollForPaidStatus()`)
	 * while it's showing anything other than the success state, so a payer
	 * who completes a real-time method (card, MB WAY) right after landing
	 * back on a Pending/Canceled/Failed popup sees it flip to Paid without a
	 * page reload — instead of only ever finding out on their next visit,
	 * once the async webhook has landed. Re-derives the outcome from the
	 * payment's own current DB status, same as `compute_modal_data()`, and
	 * also gives `maybe_sync_payment_status()` one more chance to resolve it
	 * synchronously for a still-pending real-time payment. Token-gated
	 * exactly like `handle_open()`/`handle_return()` — without it, a guessed
	 * `?entry=` could both read another payer's payment outcome and force an
	 * extra ifthenpay status lookup for it.
	 *
	 * @return void
	 */
	public static function handle_check_status() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- polled from the popup itself, not a WP form submission; token-verified below.
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$context      = $entry_id ? self::get_context( $entry_id ) : array();
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';

		if ( ! $entry_id || '' === $stored_token || ! hash_equals( $stored_token, $token ) || ! class_exists( 'FrmTransLitePayment' ) ) {
			wp_send_json_error();
		}

		$payment = ( new \FrmTransLitePayment() )->get_one_by( $entry_id, 'item_id' );

		if ( ! $payment ) {
			wp_send_json_error();
		}

		if ( 'pending' === $payment->status ) {
			self::maybe_sync_payment_status( $payment, $entry_id );
			$payment = ( new \FrmTransLitePayment() )->get_one( $payment->id );
		}

		if ( ! $payment || 'complete' !== $payment->status ) {
			wp_send_json_success( array( 'paid' => false ) );
		}

		wp_send_json_success(
			array(
				'paid' => true,
				// Sanitized the same way build_modal_html() sanitizes it for
				// the initial page-load render — this is inserted client-side
				// via innerHTML, so it must already be safe HTML by the time
				// it leaves this endpoint, not left for `frontend.js` to trust.
				'message'  => wp_kses_post( self::resolve_message( 'success', $entry_id, $token, self::get_entry_form( $entry_id ) ) ),
				'icon'     => self::status_icon( 'success', 'success' ),
				'open_url' => self::open_new_tab_url( 'success', $context ),
			)
		);
	}

	/**
	 * Intermediary target for the PBL success/error/cancel URLs (blueprint
	 * §5a/§7.5). The real payment status is always confirmed
	 * server-to-server by `WebhookController`, never by this front-channel
	 * redirect — I only use it to pick which popup to show and, for a
	 * cancel/error, to mark the payment as such (only while still `pending`;
	 * a completed payment is never overwritten). Sends the payer back to the
	 * page Formidable itself would have used, with a query flag
	 * `maybe_render_modal()` turns into a themed popup.
	 *
	 * @return void
	 */
	public static function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- front-channel hand-off from ifthenpay, not a WP form submission; token-verified below.
		$entry_id     = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$status_param = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$token        = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$context      = $entry_id ? self::get_context( $entry_id ) : array();
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';
		$token_ok     = $entry_id && '' !== $stored_token && hash_equals( $stored_token, $token );

		// A cancel/error claim only flips a payment to 'failed' when the
		// token proves this came from ifthenpay for this entry — otherwise
		// anyone could flip a stranger's pending payment to 'failed' just by
		// guessing this nopriv endpoint's URL with a different entry id.
		$outcome = self::resolve_outcome( $entry_id, $token_ok ? $status_param : '' );

		// I don't clear the context transient here — the next page load
		// (one hop away) still needs it to resolve the message via
		// compute_modal_data(); it self-deletes via its own TTL either way.

		// I forward the verified token when I have one, or none at all —
		// never a value that would incorrectly pass compute_modal_data()'s check.
		$destination = self::build_destination( $outcome, $context, $entry_id, $token_ok ? $stored_token : null );

		// A merchant's "Redirect to URL" target is allowed to be off-site, so
		// I use wp_redirect() only for these two configured-redirect
		// branches; everything else (including 'open_new_tab', which never
		// navigates this page away) stays on-site via wp_safe_redirect().
		$is_configured_redirect = ( 'success' === $outcome && 'redirect' === self::success_mode_target( $context )['mode'] )
			|| ( 'pending' === $outcome && '' !== self::outcome_redirect_url( $outcome ) );

		if ( $is_configured_redirect ) {
			wp_redirect( $destination ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * @param int    $entry_id
	 * @param string $status_param Raw `status` query arg ifthenpay sent us back — a hint, never authoritative.
	 *
	 * @return string One of 'success', 'pending', 'failed', 'canceled'.
	 */
	private static function resolve_outcome( $entry_id, $status_param ) {
		$payment = $entry_id ? ( new \FrmTransLitePayment() )->get_one_by( $entry_id, 'item_id' ) : null;

		if ( $payment && 'complete' === $payment->status ) {
			return 'success';
		}

		if ( 'cancel' === $status_param ) {
			self::mark_unfinished_payment( $payment, __( 'Payment canceled by the payer at the ifthenpay hosted page.', 'ifthenpay-payments-for-formidable-forms' ) );
			return 'canceled';
		}

		if ( 'error' === $status_param ) {
			self::mark_unfinished_payment( $payment, __( 'ifthenpay reported the payment as failed or incomplete.', 'ifthenpay-payments-for-formidable-forms' ) );
			return 'failed';
		}

		// The webhook often hasn't landed yet when the payer's browser gets
		// here — this is my one chance to resolve synchronously, so I ask
		// ifthenpay directly for this attempt's real-time status. A no-op for
		// an offline method or a missing transaction id (see
		// maybe_sync_payment_status()); falls through to 'pending' either
		// way, and the webhook still completes it later.
		if ( $payment && 'pending' === $payment->status ) {
			self::maybe_sync_payment_status( $payment, $entry_id );
			$payment = ( new \FrmTransLitePayment() )->get_one( $payment->id );

			if ( $payment && 'complete' === $payment->status ) {
				return 'success';
			}
		}

		return 'pending';
	}

	/**
	 * Marks a still-pending payment 'failed' and fires Formidable's own
	 * payment-status trigger, mirroring what `WebhookController` does for a
	 * completed one. I use one conditional `UPDATE ... WHERE status =
	 * 'pending'` rather than a read-then-write, so a webhook that completes
	 * this same payment in the narrow race window can never be overwritten
	 * back to 'failed'.
	 *
	 * @param object|null $payment
	 * @param string      $note
	 *
	 * @return void
	 */
	private static function mark_unfinished_payment( $payment, $note ) {
		if ( ! $payment || 'pending' !== $payment->status ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FrmTransLitePayment::update() can't express the conditional `WHERE status = 'pending'` guard needed to avoid racing a concurrent webhook completion.
		$claimed = $wpdb->update(
			$wpdb->prefix . 'frm_payments',
			array(
				'status'     => 'failed',
				// maybe_serialize() directly — I need the raw $wpdb query below
				// for the conditional WHERE, which bypasses the serialization
				// FrmTransLitePayment::update() would normally do.
				'meta_value' => maybe_serialize( \FrmTransLiteAppHelper::add_meta_to_payment( $payment->meta_value, $note ) ),
			),
			array(
				'id'     => $payment->id,
				'status' => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $claimed ) {
			return;
		}

		$frm_payment = new \FrmTransLitePayment();

		\FrmTransLiteActionsController::trigger_payment_status_change(
			array(
				'status'  => 'failed',
				'payment' => $frm_payment->get_one( $payment->id ),
			)
		);
	}

	/**
	 * @param string      $outcome From `resolve_outcome()`.
	 * @param array       $context From `get_context()`.
	 * @param int         $entry_id
	 * @param string|null $token   One-time secret from `handle_return()`, forwarded as
	 *                             `ifthenpay_token` so `compute_modal_data()` can prove
	 *                             the popup load is the actual return trip and not a
	 *                             guessed `?ifthenpay_entry=` URL.
	 *
	 * @return string
	 */
	private static function build_destination( $outcome, $context, $entry_id, $token = null ) {
		$referrer = ! empty( $context['referrer'] ) ? $context['referrer'] : home_url( '/' );

		if ( 'success' === $outcome ) {
			$target = self::success_mode_target( $context );

			if ( 'redirect' === $target['mode'] ) {
				return $target['url'];
			}

			// 'message' and 'open_new_tab' both stay on-site (the popup query
			// string below) — open_new_tab's extra tab is opened client-side
			// once the popup itself renders, see build_modal_html().
		} elseif ( 'pending' === $outcome ) {
			$redirect_url = self::outcome_redirect_url( $outcome );

			if ( '' !== $redirect_url ) {
				return $redirect_url;
			}
		}

		// Canceled and Failed always fall through to here — the payer is
		// always sent back to the form page (referrer) with the popup query
		// string below, never a configured redirect.

		$args = array(
			'ifthenpay_notice' => $outcome,
			'ifthenpay_entry'  => $entry_id,
		);

		if ( $token ) {
			$args['ifthenpay_token'] = $token;
		}

		return add_query_arg( $args, $referrer );
	}

	/**
	 * Resolves what Payment Received should actually do — kept as one
	 * shared helper (used by build_destination(), handle_return(), and
	 * compute_modal_data()) so the precedence can never drift:
	 *
	 * 1. The form's own native "Redirect to URL" action, captured at submit
	 *    time, always wins when present.
	 * 2. Otherwise this plugin's own Payment Received setting decides —
	 *    falls back to 'message' when no URL is configured.
	 *
	 * @param array $context From `get_context()` — needs `success_info`.
	 *
	 * @return array{mode:string, url:string} `url` is always empty when `mode` is 'message'.
	 */
	private static function success_mode_target( array $context ) {
		$success_info = isset( $context['success_info'] ) ? $context['success_info'] : array();

		if ( 'redirect' === ( $success_info['type'] ?? '' ) && ! empty( $success_info['url'] ) ) {
			return array(
				'mode' => 'redirect',
				'url'  => esc_url_raw( $success_info['url'] ),
			);
		}

		$settings = new SettingsRepository();
		$mode     = $settings->get_success_mode();
		$url      = $settings->get_success_redirect_url();

		if ( 'message' === $mode || '' === $url ) {
			return array(
				'mode' => 'message',
				'url'  => '',
			);
		}

		return array(
			'mode' => $mode,
			'url'  => $url,
		);
	}

	/**
	 * The merchant's Redirect choice for the Pending outcome only — Success
	 * has its own three-way lookup (success_mode_target()), and
	 * Canceled/Failed are never a configured redirect.
	 *
	 * @param string $outcome Must be 'pending'.
	 *
	 * @return string Empty when Pending has no URL configured.
	 */
	private static function outcome_redirect_url( $outcome ) {
		if ( 'pending' !== $outcome ) {
			return '';
		}

		$settings = new SettingsRepository();
		$mode     = $settings->get_pending_mode();
		$url      = $settings->get_pending_redirect_url();

		return ( 'redirect' === $mode && '' !== $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * The URL to open in a new tab alongside the popup — only for
	 * 'open_new_tab' mode, unlike outcome_redirect_url() which is only for
	 * 'redirect' mode (a real page navigation replacing the popup).
	 *
	 * @param string $claimed
	 * @param array  $context From `get_context()` — needs `success_info` for the 'success' case.
	 *
	 * @return string
	 */
	private static function open_new_tab_url( $claimed, array $context ) {
		if ( 'success' === $claimed ) {
			$target = self::success_mode_target( $context );
			return 'open_new_tab' === $target['mode'] ? $target['url'] : '';
		}

		if ( 'pending' === $claimed ) {
			$settings = new SettingsRepository();
			return 'open_new_tab' === $settings->get_pending_mode() ? $settings->get_pending_redirect_url() : '';
		}

		return '';
	}

	/**
	 * Best-effort synchronous status check so a real-time method (card, MB
	 * WAY, wallets) can resolve the instant the payer's tab lands back here,
	 * instead of waiting for the async webhook. Can't do anything for an
	 * offline method (Multibanco, Payshop) — those only ever resolve via the
	 * webhook, sometimes hours or days later. Never throws or blocks the
	 * redirect that follows; a failure just leaves the payer seeing
	 * 'pending', and the webhook remains the fallback either way.
	 *
	 * @param object $payment  The still-'pending' wp_frm_payments row.
	 * @param int    $entry_id
	 *
	 * @return void
	 */
	private static function maybe_sync_payment_status( $payment, $entry_id ) {
		$stored         = get_transient( self::TRANSIENT_PREFIX . (int) $entry_id );
		$transaction_id = is_array( $stored ) && ! empty( $stored['transaction_id'] ) ? (string) $stored['transaction_id'] : '';

		if ( '' === $transaction_id ) {
			return;
		}

		try {
			$status = \Ifthenpay\Formidable\Api\IfthenpayClient::get_payment_status( $transaction_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $status ) ) {
			return;
		}

		$value = isset( $status['Status'] ) ? $status['Status'] : ( isset( $status['status'] ) ? $status['status'] : '' );

		if ( ! in_array( strtolower( (string) $value ), array( '1', 'paid', 'payed', 'completed', 'success' ), true ) ) {
			return;
		}

		\Ifthenpay\Formidable\Webhook\WebhookController::complete_pending_payment(
			$payment,
			isset( $status['Method'] ) ? (string) $status['Method'] : '',
			isset( $status['RequestId'] ) ? (string) $status['RequestId'] : $transaction_id
		);
	}

	/**
	 * Enqueued unconditionally (only gated on Formidable being active) since
	 * frontend.js has two jobs: show the return-trip popup, and hand off to
	 * the pre-opened tab on the original submit page — neither shares the
	 * same DOM markers, so both self-gate and this is a cheap no-op elsewhere.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_frontend_assets() {
		if ( ! class_exists( 'FrmForm' ) ) {
			return;
		}

		// Same filemtime-based cache-busting as Admin\SettingsField::asset_version().
		wp_enqueue_style( 'ifthenpay-frm-frontend', IFTP_FRM_URL . 'assets/css/frontend.css', array(), \Ifthenpay\Formidable\Admin\SettingsField::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'ifthenpay-frm-frontend', IFTP_FRM_URL . 'assets/js/frontend.js', array( 'jquery' ), \Ifthenpay\Formidable\Admin\SettingsField::asset_version( 'assets/js/frontend.js' ), true );

		wp_localize_script(
			'ifthenpay-frm-frontend',
			'iftpFrmFlow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'logoUrl' => IFTP_FRM_URL . 'assets/img/logo_ifthenpay_white.svg',
				'i18n'    => array(
					'continueInOtherTab' => __( 'Continue in the other tab — you can close this one.', 'ifthenpay-payments-for-formidable-forms' ),
					'poweredBy'          => __( 'Powered by', 'ifthenpay-payments-for-formidable-forms' ),
				),
			)
		);
	}

	/**
	 * Prints the popup just before `</body>`, not via `the_content`, so it
	 * works on every front-end template, not just a singular post's content.
	 *
	 * @return void
	 */
	public static function maybe_render_modal() {
		$data = self::get_modal_data();

		if ( ! $data ) {
			return;
		}

		echo self::build_modal_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built (and escaped/sanitized piece by piece) in build_modal_html().
	}

	/**
	 * Memoized — both hooks above need this and it's expensive (DB lookups),
	 * no reason to run it twice.
	 *
	 * @return array{status:string, claimed:string, message:string, form:object|null, open_url:string}|null
	 */
	private static function get_modal_data() {
		if ( ! self::$modal_data_computed ) {
			self::$modal_data          = self::compute_modal_data();
			self::$modal_data_computed = true;
		}

		return self::$modal_data;
	}

	/**
	 * Re-derives the outcome from the payment's own current DB status
	 * rather than trusting `ifthenpay_notice` outright, so a guessed entry
	 * id can't paint a false popup for someone else's payment. This is the
	 * only tab that ever renders this for a given entry, so `open_url` is
	 * safe from a duplicate `window.open()` anywhere else.
	 *
	 * @return array{status:string, claimed:string, message:string, form:object|null, open_url:string, entry_id:int, poll_token:string}|null
	 */
	private static function compute_modal_data() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display hint, re-verified against the DB below.
		if ( empty( $_GET['ifthenpay_notice'] ) || empty( $_GET['ifthenpay_entry'] ) ) {
			return null;
		}

		$claimed  = sanitize_key( wp_unslash( $_GET['ifthenpay_notice'] ) );
		$entry_id = absint( wp_unslash( $_GET['ifthenpay_entry'] ) );
		$token    = isset( $_GET['ifthenpay_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ifthenpay_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $entry_id || ! class_exists( 'FrmTransLitePayment' ) ) {
			return null;
		}

		$payment = ( new \FrmTransLitePayment() )->get_one_by( $entry_id, 'item_id' );

		if ( ! $payment || ! self::claim_matches_payment( $claimed, $payment->status ) ) {
			return null;
		}

		$form    = self::get_entry_form( $entry_id );
		$message = self::resolve_message( $claimed, $entry_id, $token, $form );

		if ( ! $message ) {
			return null;
		}

		// Polling only ever makes sense for a payer looking at a not-yet-paid
		// popup, and only when this load can prove it's the real return trip
		// (the same token handle_check_status() itself re-verifies) — a
		// guessed `?ifthenpay_entry=` with no/wrong token gets a static popup
		// instead of a poll loop that would just error on every tick.
		$context      = self::get_context( $entry_id );
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';
		$can_poll     = 'success' !== $claimed && '' !== $stored_token && hash_equals( $stored_token, $token );

		return array(
			'status'     => in_array( $claimed, array( 'failed', 'canceled' ), true ) ? 'error' : 'success',
			'claimed'    => $claimed,
			'message'    => $message,
			'form'       => $form,
			'open_url'   => self::open_new_tab_url( $claimed, $context ),
			'entry_id'   => $entry_id,
			'poll_token' => $can_poll ? $token : '',
		);
	}

	/**
	 * @param string $claimed        The `ifthenpay_notice` query value.
	 * @param string $payment_status The payment row's actual current status.
	 *
	 * @return bool
	 */
	private static function claim_matches_payment( $claimed, $payment_status ) {
		if ( 'success' === $claimed ) {
			return 'complete' === $payment_status;
		}

		if ( in_array( $claimed, array( 'failed', 'canceled' ), true ) ) {
			// Both cancel and error map to the same 'failed' DB status — the
			// distinct wording is cosmetic only (see resolve_outcome()).
			return 'failed' === $payment_status;
		}

		if ( 'pending' === $claimed ) {
			return 'pending' === $payment_status;
		}

		return false;
	}

	/**
	 * Builds the final popup message: for a success, the form's own native
	 * message ONLY while Payment Received is still on its default "Show
	 * Message" mode — the moment a merchant picks "Open in a New Tab", this
	 * plugin's own configured message wins instead. Pending (and Success
	 * otherwise) falls back to this plugin's own message or its built-in
	 * default. Canceled/Failed always show a fixed message.
	 *
	 * Formidable shortcodes (e.g. `[21]`) are only resolved when `$token`
	 * matches the one-time secret `IfthenpayGateway::trigger()` minted for
	 * this entry — otherwise anyone could read another payer's submitted
	 * field values by guessing the query string, so the message is shown as
	 * plain text instead.
	 *
	 * @param string      $claimed
	 * @param int         $entry_id
	 * @param string      $token
	 * @param object|null $form
	 *
	 * @return string
	 */
	private static function resolve_message( $claimed, $entry_id, $token, $form ) {
		$context      = self::get_context( $entry_id );
		$success_info = isset( $context['success_info'] ) ? $context['success_info'] : array();
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';
		$token_ok     = '' !== $stored_token && hash_equals( $stored_token, $token );

		// I never delete the context transient here, even on a verified read —
		// a reload of this same return-trip page should still resolve the
		// real message. Its own short TTL already covers cleanup.
		$settings = new SettingsRepository();

		if ( 'success' === $claimed && $token_ok && 'message' === $settings->get_success_mode() && 'message' === ( $success_info['type'] ?? '' ) && isset( $success_info['message'] ) ) {
			return self::render_message( $success_info['message'], $form, $entry_id, true );
		}

		$defaults = array(
			'success'  => $settings->get_success_message(),
			'pending'  => $settings->get_pending_message(),
			// Fixed, non-configurable text — Canceled/Failed have no message
			// setting of their own (see this method's own docblock).
			'canceled' => __( 'You canceled the payment before it was completed.', 'ifthenpay-payments-for-formidable-forms' ),
			'failed'   => __( 'Your payment could not be completed. Please try again.', 'ifthenpay-payments-for-formidable-forms' ),
		);

		if ( ! isset( $defaults[ $claimed ] ) ) {
			return '';
		}

		return self::render_message( $defaults[ $claimed ], $form, $entry_id, $token_ok );
	}

	/**
	 * @param string      $message
	 * @param object|null $form
	 * @param int         $entry_id
	 * @param bool        $resolve_shortcodes
	 *
	 * @return string
	 */
	private static function render_message( $message, $form, $entry_id, $resolve_shortcodes ) {
		if ( ! $resolve_shortcodes || ! $form || ! class_exists( 'FrmFormsHelper' ) ) {
			return '<p>' . esc_html( $message ) . '</p>';
		}

		return \FrmFormsHelper::get_success_message(
			array(
				'message'  => $message,
				'form'     => $form,
				'entry_id' => $entry_id,
				'class'    => 'iftp-frm-modal__frm-message',
			)
		);
	}

	/**
	 * @param int $entry_id
	 *
	 * @return object|null
	 */
	private static function get_entry_form( $entry_id ) {
		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
			return null;
		}

		$entry = \FrmEntry::getOne( $entry_id );

		if ( ! $entry || ! isset( $entry->form_id ) ) {
			return null;
		}

		$form = \FrmForm::getOne( $entry->form_id );

		return $form ? $form : null;
	}

	/**
	 * @param array{status:string, claimed:string, message:string, form:object|null, open_url:string, entry_id:int, poll_token:string} $data
	 *
	 * @return string
	 */
	private static function build_modal_html( array $data ) {
		$colors     = self::build_color_style( self::get_style_vars( $data['form'] ) );
		$open_url   = isset( $data['open_url'] ) ? (string) $data['open_url'] : '';
		$poll_token = isset( $data['poll_token'] ) ? (string) $data['poll_token'] : '';

		// `frontend.js`'s `pollForPaidStatus()` only starts once both of these
		// are present — see compute_modal_data()'s own docblock for why
		// `poll_token` is already empty here for anything it can't prove is
		// the real return trip.
		$poll_attrs = '';

		if ( '' !== $poll_token ) {
			$poll_attrs = ' data-iftp-poll-entry="' . (int) $data['entry_id'] . '" data-iftp-poll-token="' . esc_attr( $poll_token ) . '"';
		}

		ob_start();
		?>
		<div id="iftp-frm-modal" class="iftp-frm-modal iftp-frm-modal--<?php echo esc_attr( $data['status'] ); ?>" style="<?php echo esc_attr( $colors ); ?>" role="dialog" aria-modal="true" aria-labelledby="iftp-frm-modal-title" <?php echo $open_url ? 'data-iftp-open-url="' . esc_url( $open_url ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() already applied above; the surrounding attribute markup itself is a static literal. ?><?php echo $poll_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from (int) and esc_attr() just above. ?> hidden>
			<div class="iftp-frm-modal__backdrop" data-iftp-close></div>
			<div class="iftp-frm-modal__box">
				<button type="button" class="iftp-frm-modal__close" data-iftp-close aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-formidable-forms' ); ?>">&times;</button>
				<div class="iftp-frm-modal__icon"><?php echo self::status_icon( $data['status'], $data['claimed'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?></div>
				<div id="iftp-frm-modal-title" class="iftp-frm-modal__message"><?php echo wp_kses_post( $data['message'] ); // Formidable's own FrmFormsHelper::get_success_message() resolves field shortcodes (payer-submitted values) into this string unescaped — escaped late here, right at the echo, rather than trusted from resolve_message()/render_message(). ?></div>
				<button type="button" class="iftp-frm-modal__ok" data-iftp-close><?php esc_html_e( 'OK', 'ifthenpay-payments-for-formidable-forms' ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public entry point for PaymentSelector to reuse the same "look like it
	 * belongs to this form" CSS custom properties the popup uses.
	 *
	 * @param object|null $form
	 *
	 * @return string Inline `style` attribute content (CSS custom properties only).
	 */
	public static function color_style_for_form( $form ) {
		return self::build_color_style( self::get_style_vars( $form ) );
	}

	/**
	 * Pulls the merchant's own form-style colors (Formidable's Style/Theme
	 * Builder) so the popup looks like it belongs to their form instead of a
	 * generic plugin dialog.
	 *
	 * @param object|null $form
	 *
	 * @return array<string, string>
	 */
	private static function get_style_vars( $form ) {
		if ( ! $form || ! class_exists( 'FrmStylesController' ) ) {
			return array();
		}

		$style = \FrmStylesController::get_form_style( $form );

		if ( ! $style || empty( $style->post_content ) || ! is_array( $style->post_content ) ) {
			return array();
		}

		return $style->post_content;
	}

	/**
	 * @param array<string, string> $vars
	 *
	 * @return string Inline `style` attribute content (CSS custom properties only).
	 */
	private static function build_color_style( array $vars ) {
		$props = array(
			'--iftp-success-bg'     => self::sanitize_hex_color( $vars['success_bg_color'] ?? '', 'dff0d8' ),
			'--iftp-success-border' => self::sanitize_hex_color( $vars['success_border_color'] ?? '', 'd6e9c6' ),
			'--iftp-success-text'   => self::sanitize_hex_color( $vars['success_text_color'] ?? '', '468847' ),
			'--iftp-error-bg'       => self::sanitize_hex_color( $vars['error_bg'] ?? '', 'fee4e2' ),
			'--iftp-error-border'   => self::sanitize_hex_color( $vars['error_border'] ?? '', 'f5b8aa' ),
			'--iftp-error-text'     => self::sanitize_hex_color( $vars['error_text'] ?? '', 'f04438' ),
			'--iftp-accent'         => self::sanitize_hex_color( $vars['submit_bg_color'] ?? '', '4199fd' ),
			'--iftp-accent-hover'   => self::sanitize_hex_color( $vars['submit_hover_bg_color'] ?? '', '3680d3' ),
			'--iftp-accent-text'    => self::sanitize_hex_color( $vars['submit_text_color'] ?? '', 'ffffff' ),
		);

		$css = '';

		foreach ( $props as $name => $hex ) {
			$css .= $name . ':#' . $hex . ';';
		}

		$css .= '--iftp-radius:' . self::sanitize_css_length( $vars['border_radius'] ?? '', '8px' ) . ';';

		return $css;
	}

	/**
	 * @param string $value
	 * @param string $fallback Already-known-safe (no leading '#').
	 *
	 * @return string Hex digits only, no leading '#'.
	 */
	private static function sanitize_hex_color( $value, $fallback ) {
		$value = ltrim( (string) $value, '#' );
		return preg_match( '/^[0-9a-fA-F]{3,8}$/', $value ) ? $value : $fallback;
	}

	/**
	 * @param string $value
	 * @param string $fallback Already-known-safe.
	 *
	 * @return string
	 */
	private static function sanitize_css_length( $value, $fallback ) {
		return preg_match( '/^\d{1,3}(?:\.\d+)?(?:px|em|rem|%)$/', (string) $value ) ? $value : $fallback;
	}

	/**
	 * Static inline SVGs — no dynamic data, colored via `currentColor` so
	 * they pick up `--iftp-success-text`/`--iftp-error-text` automatically.
	 *
	 * @param string $status  From compute_modal_data(): 'success' or 'error'.
	 * @param string $claimed The raw `ifthenpay_notice` value — 'pending' gets its own icon.
	 *
	 * @return string
	 */
	private static function status_icon( $status, $claimed ) {
		if ( 'pending' === $claimed ) {
			return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
		}

		if ( 'error' === $status ) {
			return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>';
		}

		return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12l2.5 2.5L16 9"/></svg>';
	}
}
