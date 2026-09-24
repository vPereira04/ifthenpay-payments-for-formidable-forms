<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Admin\SettingsField;
use Ifthenpay\Formidable\Api\IfthenpayClient;
use Ifthenpay\Formidable\Api\IfthenpayPayload;
use Ifthenpay\Formidable\Mail\IfthenpayEmailHelper;
use Ifthenpay\Formidable\Settings\SettingsRepository;
use Ifthenpay\Formidable\Webhook\WebhookController;

/**
 * Every AJAX endpoint behind the reactive settings screen (blueprint §8.2):
 * connect/disconnect the Backoffice Key, select a Gateway Key, save the
 * panel, and request activation of an unprovisioned method.
 *
 * `frm_change_settings` gates every endpoint here except
 * `request_activation`, which uses `manage_options` per the WPREA
 * "Activation Request Flow" mandate (blueprint §6).
 */
class Controller {

	const NONCE_ACTION = 'iftp_frm_admin';

	public static function boot() {
		add_action( 'wp_ajax_ifthenpay_frm_connect_backoffice', array( self::class, 'connect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_frm_disconnect_backoffice', array( self::class, 'disconnect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_frm_select_gateway_key', array( self::class, 'select_gateway_key' ) );
		add_action( 'wp_ajax_ifthenpay_frm_request_activation', array( self::class, 'request_activation' ) );
		add_action( 'wp_ajax_ifthenpay_frm_save_settings', array( self::class, 'save_settings' ) );
	}

	public static function connect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable-forms' ) ), 403 );
		}

		$backoffice_key = isset( $_POST['backoffice_key'] ) ? sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ) ) : '';

		if ( '' === $backoffice_key || ! IfthenpayClient::validate_backoffice_key( $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That Backoffice Key could not be validated. Please double-check it and try again.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$settings = new SettingsRepository();
		$settings->save_backoffice_key( $backoffice_key );

		$client       = new IfthenpayClient( $backoffice_key );
		$gateway_rows = self::safe_call( array( $client, 'get_gateway_keys' ) );

		wp_send_json_success(
			array(
				'connected'    => true,
				'gateway_keys' => self::map_gateway_key_choices( (array) $gateway_rows ),
			)
		);
	}

	/**
	 * Live-refreshes the Gateway Key list and methods table from the
	 * ifthenpay API on every settings-tab render, instead of only trusting
	 * the last-saved snapshot. Never overwrites the saved snapshot on a
	 * transient API failure, so a temporary outage can't break a working
	 * payment configuration.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return array{gateway_keys: array<int, array<string, string>>, methods: array<int, array<string, mixed>>}
	 */
	public static function fetch_live_gateway_state( SettingsRepository $settings ) {
		$methods = $settings->get_methods();

		if ( ! $settings->has_backoffice_key() ) {
			return array(
				'gateway_keys' => array(),
				'methods'      => $methods,
			);
		}

		$client       = new IfthenpayClient( $settings->get_backoffice_key() );
		$gateway_rows = (array) self::safe_call( array( $client, 'get_gateway_keys' ) );
		$gateway_keys = self::map_gateway_key_choices( $gateway_rows );

		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key || empty( $gateway_rows ) ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$gateway_row = null;

		foreach ( $gateway_rows as $row ) {
			if ( self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) ) === $gateway_key ) {
				$gateway_row = $row;
				break;
			}
		}

		if ( null === $gateway_row ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$catalog = self::safe_call( array( IfthenpayClient::class, 'get_available_methods' ) );

		if ( empty( $catalog ) ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$fresh_methods = self::build_methods_from_catalog( (array) $catalog, $gateway_row, self::index_by_entity( $methods ) );

		$settings->save_methods( $fresh_methods );

		return array(
			'gateway_keys' => $gateway_keys,
			'methods'      => $fresh_methods,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $gateway_rows
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function map_gateway_key_choices( array $gateway_rows ) {
		$choices = array();

		foreach ( $gateway_rows as $row ) {
			$key = self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) );

			if ( '' === $key ) {
				continue;
			}

			$choices[] = array(
				'key'   => $key,
				'label' => self::first_present( $row, array( 'Alias', 'alias', 'Description', 'description' ), $key ),
			);
		}

		return $choices;
	}

	/**
	 * Shared build step for a methods table row set — used by both
	 * `select_gateway_key()`'s AJAX response and `fetch_live_gateway_state()`'s
	 * page-render refresh so the two call sites can never drift.
	 *
	 * @param array<int, array<string, mixed>>    $catalog
	 * @param array<string, mixed>                $gateway_row
	 * @param array<string, array<string, mixed>> $previous_methods Indexed by uppercase entity.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_methods_from_catalog( array $catalog, array $gateway_row, array $previous_methods ) {
		$methods = array();

		foreach ( $catalog as $method ) {
			$entity = strtoupper( self::first_present( $method, array( 'Entity', 'entity' ) ) );

			if ( '' === $entity ) {
				continue;
			}

			$is_visible = self::first_present( $method, array( 'IsVisible', 'is_visible' ), true );

			if ( ! $is_visible ) {
				continue;
			}

			// 'Method' is the catalog's real display name (e.g. "Multibanco",
			// "MB WAY", "Cofidis Pay") — I only fall back to the bare entity
			// code when even that's missing from a given catalog row.
			$label       = self::first_present( $method, array( 'Method', 'method', 'Alias', 'alias', 'Label', 'label' ), $entity );
			$account     = self::find_account_for_entity( $gateway_row, $entity, $label );
			$provisioned = '' !== $account;
			$previous    = isset( $previous_methods[ $entity ] ) ? $previous_methods[ $entity ] : array();

			$methods[] = array(
				'entity'      => $entity,
				'label'       => $label,
				'account'     => $account,
				// The ifthenpay PBL API's `selected_method` field takes this
				// numeric catalog Position, never the entity code — see
				// IfthenpayPayload::resolve_selected_method_position().
				'position'    => (int) self::first_present( $method, array( 'Position', 'position' ), 0 ),
				'provisioned' => $provisioned,
				'enabled'     => $provisioned && ! empty( $previous['enabled'] ),
				'image_url'   => self::resolve_logo_url( $method, $entity ),
			);
		}

		return $methods;
	}

	public static function disconnect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable-forms' ) ), 403 );
		}

		( new SettingsRepository() )->delete_backoffice_key();

		wp_send_json_success( array( 'connected' => false ) );
	}

	/**
	 * Re-fetches the methods table for a newly selected Gateway Key and
	 * returns its rendered HTML for the front-end to inject in place, no
	 * page reload (blueprint §8.2).
	 *
	 * @return void
	 */
	public static function select_gateway_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable-forms' ) ), 403 );
		}

		$gateway_key = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';

		if ( '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a Gateway Key.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$settings = new SettingsRepository();

		if ( ! $settings->has_backoffice_key() ) {
			wp_send_json_error( array( 'message' => __( 'Connect a Backoffice Key first.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$client       = new IfthenpayClient( $settings->get_backoffice_key() );
		$gateway_rows = self::safe_call( array( $client, 'get_gateway_keys' ) );
		$catalog      = self::safe_call( array( IfthenpayClient::class, 'get_available_methods' ) );

		$gateway_row = null;

		foreach ( (array) $gateway_rows as $row ) {
			$key = self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) );

			if ( $key === $gateway_key ) {
				$gateway_row = $row;
				break;
			}
		}

		if ( null === $gateway_row ) {
			wp_send_json_error( array( 'message' => __( 'That Gateway Key could not be found on this Backoffice account.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		if ( empty( $catalog ) ) {
			// Same protection as fetch_live_gateway_state(): I never overwrite a
			// working methods snapshot with an empty one on a transient failure.
			wp_send_json_error( array( 'message' => __( 'Could not load the payment methods catalog from ifthenpay. Please try again.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$previous_methods = self::index_by_entity( $settings->get_methods() );
		$methods          = self::build_methods_from_catalog( (array) $catalog, $gateway_row, $previous_methods );

		$settings->save_gateway_key( $gateway_key );
		$settings->save_methods( $methods );

		if ( '' !== $settings->get_default_method() && ! self::default_method_still_valid( $methods, $settings->get_default_method() ) ) {
			$settings->save_default_method( '' );
		}

		wp_send_json_success(
			array(
				'html' => SettingsField::render_methods_table_rows( $methods, $gateway_key, $settings->get_default_method() ),
			)
		);
	}

	public static function request_activation() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// This one endpoint uses manage_options, not frm_change_settings, per
		// the WPREA "Activation Request Flow" mandate (blueprint §6).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable-forms' ) ), 403 );
		}

		$entity = isset( $_POST['entity'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['entity'] ) ) ) : '';

		$settings    = new SettingsRepository();
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $entity || '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing method or Gateway Key.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		if ( $settings->is_activation_requested( $gateway_key, $entity ) ) {
			wp_send_json_error( array( 'message' => __( 'Activation for this method was already requested recently.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'    => $gateway_key,
				'entity'         => $entity,
				// I read this server-side only, at point of use — never echoed back to JS (blueprint §8.4).
				'backoffice_key' => $settings->get_backoffice_key(),
				'customer_email' => wp_get_current_user()->user_email,
				'site_url'       => home_url( '/' ),
				'site_name'      => get_bloginfo( 'name' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'frm_version'    => class_exists( '\FrmAppHelper' ) ? \FrmAppHelper::plugin_version() : '',
				'plugin_version' => defined( 'IFTP_FRM_VERSION' ) ? IFTP_FRM_VERSION : '',
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The activation request could not be sent. Please try again.', 'ifthenpay-payments-for-formidable-forms' ) ) );
		}

		$settings->mark_activation_requested( $gateway_key, $entity );

		wp_send_json_success( array( 'message' => __( 'Requested', 'ifthenpay-payments-for-formidable-forms' ) ) );
	}

	/**
	 * Dedicated save for this panel, independent of Formidable's own shared
	 * Global Settings form submit (still handled separately by
	 * `SettingsField::process_form()`) — that form's single "Update" button
	 * is several sections away and gives no ifthenpay-specific confirmation,
	 * so this button saves and confirms this panel on its own.
	 *
	 * @return void
	 */
	public static function save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable-forms' ) ), 403 );
		}

		$settings = new SettingsRepository();

		if ( isset( $_POST['expiry_days'] ) ) {
			$settings->save_expiry_days( absint( wp_unslash( $_POST['expiry_days'] ) ) );
		}

		$enabled_entities = isset( $_POST['methods_enabled'] ) && is_array( $_POST['methods_enabled'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['methods_enabled'] ) )
			: array();

		$default_method = isset( $_POST['default_method'] ) ? sanitize_text_field( wp_unslash( $_POST['default_method'] ) ) : '';

		$settings->apply_enabled_methods( $enabled_entities, $default_method );

		$gateway_key    = $settings->get_gateway_key();
		$callback_saved = '' !== $gateway_key && IfthenpayClient::activate_callback( $gateway_key, WebhookController::base_url() );

		wp_send_json_success(
			array(
				'message'        => __( 'Settings saved.', 'ifthenpay-payments-for-formidable-forms' ),
				'callback_saved' => $callback_saved,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $entity
	 *
	 * @return bool
	 */
	private static function default_method_still_valid( array $methods, $entity ) {
		foreach ( $methods as $method ) {
			if ( strtoupper( $method['entity'] ) === strtoupper( $entity ) ) {
				return ! empty( $method['enabled'] ) && ! empty( $method['provisioned'] );
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function index_by_entity( array $methods ) {
		$indexed = array();

		foreach ( $methods as $method ) {
			if ( isset( $method['entity'] ) ) {
				$indexed[ strtoupper( $method['entity'] ) ] = $method;
			}
		}

		return $indexed;
	}

	/**
	 * ifthenpay keys some `/gateway/get` columns by the method's display
	 * name rather than its entity code (e.g. the "MB" entity's column is
	 * named "Multibanco", not "MB"). I try several casings of both the
	 * entity name and the label as a column key before giving up.
	 *
	 * @param array<string, mixed> $gateway_row
	 * @param string               $entity
	 * @param string               $label
	 *
	 * @return string
	 */
	private static function find_account_for_entity( array $gateway_row, $entity, $label = '' ) {
		$candidates = array_filter(
			array(
				$entity,
				ucfirst( strtolower( $entity ) ),
				str_replace( ' ', '', ucwords( strtolower( str_replace( '_', ' ', $entity ) ) ) ),
				$label,
				strtoupper( $label ),
				ucfirst( strtolower( $label ) ),
			)
		);

		if ( 'MB' === strtoupper( $entity ) ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $gateway_row[ $candidate ] ) && '' !== $gateway_row[ $candidate ] ) {
				return self::extract_account_number( (string) $gateway_row[ $candidate ] );
			}
		}

		return '';
	}

	/**
	 * ifthenpay returns each provisioned method's value already formatted as
	 * "ENTITY | ACCOUNT", not a bare account number. Since
	 * `SettingsRepository::get_active_accounts_string()` rebuilds
	 * "ENTITY|ACCOUNT" itself, keeping the entity prefix here used to
	 * produce a doubled-up string ifthenpay's hosted page couldn't match —
	 * I strip it back off so only the bare account number is stored.
	 *
	 * @param string $raw_value
	 *
	 * @return string
	 */
	private static function extract_account_number( $raw_value ) {
		$raw_value = trim( $raw_value );

		if ( false !== strpos( $raw_value, '|' ) ) {
			$parts     = explode( '|', $raw_value, 2 );
			$raw_value = trim( $parts[1] );
		}

		return sanitize_text_field( $raw_value );
	}

	/**
	 * The methods catalog carries the method's own logo under
	 * `SmallImageUrl`/`ImageUrl`; falls back to ifthenpay's predictable logo
	 * CDN via `IfthenpayPayload::fallback_logo_url()` when absent.
	 *
	 * @param array<string, mixed> $method
	 * @param string               $entity
	 *
	 * @return string
	 */
	private static function resolve_logo_url( array $method, $entity ) {
		$url = self::first_present( $method, array( 'SmallImageUrl', 'small_image_url', 'ImageUrl', 'image_url', 'Logo', 'logo' ) );

		return '' !== $url ? esc_url_raw( $url ) : IfthenpayPayload::fallback_logo_url( $entity );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[]             $keys
	 * @param mixed                $default
	 *
	 * @return mixed
	 */
	private static function first_present( array $row, array $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== $row[ $key ] ) {
				return is_string( $row[ $key ] ) ? sanitize_text_field( $row[ $key ] ) : $row[ $key ];
			}
		}

		return $default;
	}

	/**
	 * @param callable $callback
	 *
	 * @return array<int, mixed>
	 */
	private static function safe_call( $callback ) {
		try {
			$result = call_user_func( $callback );
			return is_array( $result ) ? $result : array();
		} catch ( \RuntimeException $e ) {
			return array();
		}
	}
}
