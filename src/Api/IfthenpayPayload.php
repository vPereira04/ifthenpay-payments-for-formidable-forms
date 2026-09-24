<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Builds the Pay by Link payload sent to `IfthenpayClient::create_payment_link()`.
 */
class IfthenpayPayload {

	/**
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * @param SettingsRepository $settings
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string, mixed> $atts {
	 *     @type string $id          Unique payment reference.
	 *     @type float  $amount      Decimal amount.
	 *     @type string $description Optional description override.
	 *     @type string $success_url
	 *     @type string $error_url
	 *     @type string $cancel_url
	 * }
	 *
	 * @return array<string, mixed>
	 */
	public function build( array $atts ) {
		$description = ! empty( $atts['description'] ) ? $atts['description'] : $this->settings->get_default_description();

		$payload = array(
			'id'          => sanitize_text_field( $atts['id'] ),
			'amount'      => number_format( (float) $atts['amount'], 2, '.', '' ),
			'description' => sanitize_text_field( $description ),
			'accounts'    => $this->settings->get_active_accounts_string(),
			'success_url' => esc_url_raw( $atts['success_url'] ),
			'error_url'   => esc_url_raw( $atts['error_url'] ),
			'cancel_url'  => esc_url_raw( $atts['cancel_url'] ),
			'otp'         => 'true',
			'lang'        => $this->map_locale_to_lang( get_locale() ),
		);

		$expiry_days = $this->settings->get_expiry_days();

		if ( $expiry_days > 0 ) {
			$payload['expiredate'] = gmdate( 'Ymd', strtotime( '+' . $expiry_days . ' days' ) );
		}

		$default_method = $this->settings->get_default_method();

		if ( '' !== $default_method ) {
			$selected_method_position = $this->resolve_selected_method_position( $default_method );

			if ( '' !== $selected_method_position ) {
				$payload['selected_method'] = $selected_method_position;
			}
		}

		return $payload;
	}

	/**
	 * ifthenpay's Pay by Link API takes the method's numeric catalog
	 * `Position` in `selected_method`, never the entity code (`MB`,
	 * `MBWAY`, ...) — sending the entity code there silently fails to
	 * pre-select anything.
	 *
	 * @param string $entity
	 *
	 * @return string Position as a string, or '' if not enabled/provisioned.
	 */
	private function resolve_selected_method_position( $entity ) {
		foreach ( $this->settings->get_methods() as $method ) {
			if ( ! isset( $method['entity'] ) || strtoupper( $method['entity'] ) !== strtoupper( $entity ) ) {
				continue;
			}

			if ( empty( $method['enabled'] ) || empty( $method['provisioned'] ) ) {
				return '';
			}

			$position = isset( $method['position'] ) ? (int) $method['position'] : 0;

			return $position > 0 ? (string) $position : '';
		}

		return '';
	}

	/**
	 * Predictable CDN fallback for a payment method's logo, used when the
	 * catalog response has no image URL of its own. The entity code doesn't
	 * map 1:1 onto the CDN's filenames — `MB`, `COFIDIS`, `GOOGLE`, and
	 * `APPLE` need the explicit mapping below, everything else matches
	 * `strtolower($entity)`.
	 *
	 * @param string $entity
	 *
	 * @return string
	 */
	public static function fallback_logo_url( $entity ) {
		$filenames = array(
			'MB'      => 'multibanco',
			'COFIDIS' => 'cofidispay',
			'GOOGLE'  => 'googlepay',
			'APPLE'   => 'applepay',
		);

		$entity   = strtoupper( $entity );
		$filename = isset( $filenames[ $entity ] ) ? $filenames[ $entity ] : strtolower( $entity );

		return 'https://gateway.ifthenpay.com/plugins/logotipos/small/' . $filename . '.png';
	}

	/**
	 * @param string $locale
	 *
	 * @return string
	 */
	private function map_locale_to_lang( $locale ) {
		$prefix = strtolower( substr( (string) $locale, 0, 2 ) );
		$map    = array(
			'pt' => 'pt',
			'es' => 'es',
			'fr' => 'fr',
		);

		return isset( $map[ $prefix ] ) ? $map[ $prefix ] : 'en';
	}
}
