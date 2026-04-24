<?php
/**
 * Plugin Name: Per-Product Shipping Disable
 * Description: Disable WooCommerce shipping methods per product using ACF Pro. Populates an ACF checkbox field with shipping method instance IDs and hides selected methods at checkout.
 * Version:     1.0.0
 * Author:      Anton Vakulov
 * Text Domain: per-product-shipping-disable
 *
 * @package PerProductShippingDisable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main plugin class.
 *
 * Handles ACF field registration and WooCommerce shipping filters.
 *
 * @package PerProductShippingDisable
 */
final class PerProductShippingDisable {

	/** Plugin version. */
	public const VERSION = '1.0.0';

	/** Text domain for translations. */
	public const TEXT_DOMAIN = 'per-product-shipping-disable';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether ACF is available.
	 *
	 * @var bool
	 */
	private $acf_available = false;

	/**
	 * Whether WooCommerce is available.
	 *
	 * @var bool
	 */
	private $wc_available = false;

	/**
	 * Transient key for caching shipping methods.
	 *
	 * @var string
	 */
	private $transient_key = 'per_product_shipping_disable_methods_v1';

	/** Get singleton instance. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor is private — use instance(). */
	private function __construct() {
		// Register hooks once plugins are loaded.
		// NOTE: availability checks are deferred to init() so that all plugins
		// are guaranteed to be loaded before we check for ACF / WooCommerce.
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/** Initialize plugin: register ACF fields and WooCommerce filters. */
	public function init() {
		// Detect dependencies here — all plugins are loaded by plugins_loaded priority 20.
		$this->acf_available = function_exists( 'acf_add_local_field_group' );
		$this->wc_available  = class_exists( 'WooCommerce' ) || function_exists( 'WC' );

		// Register ACF field group if ACF is present.
		if ( $this->acf_available ) {
			add_action( 'acf/init', array( $this, 'register_acf_field_group' ) );
			// Populate choices dynamically with available shipping methods.
			add_filter( 'acf/load_field/name=disabled_shipping_methods', array( $this, 'acf_load_field_populate_shipping_methods' ) );
		}

		// Hook into WooCommerce shipping rates — only if WooCommerce exists.
		if ( $this->wc_available ) {
			add_filter( 'woocommerce_package_rates', array( $this, 'filter_woocommerce_package_rates' ), 10, 2 );
			// Try to clear cached shipping methods when zones/methods change (hooks exist in modern WC).
			add_action( 'woocommerce_shipping_zone_method_added', array( $this, 'clear_shipping_methods_cache' ) );
			add_action( 'woocommerce_shipping_zone_method_removed', array( $this, 'clear_shipping_methods_cache' ) );
			add_action( 'woocommerce_shipping_zone_saved', array( $this, 'clear_shipping_methods_cache' ) );
		}

		// Provide a small helper URL to clear cache during dev (admin only).
		add_action( 'admin_init', array( $this, 'maybe_handle_clear_cache' ) );
	}

	/**
	 * Show admin notices if required dependencies are missing.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! $this->acf_available ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Per-Product Shipping Disable: ACF Pro not active. Please activate ACF Pro to use per-product shipping controls.', 'per-product-shipping-disable' ) );
		}

		if ( ! $this->wc_available ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Per-Product Shipping Disable: WooCommerce not active. Plugin requires WooCommerce to filter shipping methods.', 'per-product-shipping-disable' ) );
		}
	}

	/** Register the ACF field group for products (programmatic ACF registration). */
	public function register_acf_field_group() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return; // ACF not present — nothing to register.
		}

		// Register a simple checkbox field. Choices are populated dynamically via acf/load_field.
		// Field key and group keys are prefixed to avoid collisions.
		acf_add_local_field_group(
			array(
				'key'                   => 'group_per_product_disabled_shipping_methods',
				'title'                 => __( 'Disabled shipping methods (per product)', 'per-product-shipping-disable' ),
				'fields'                => array(
					array(
						'key'               => 'field_per_product_disabled_shipping_methods',
						'label'             => __( 'Disable shipping methods', 'per-product-shipping-disable' ),
						'name'              => 'disabled_shipping_methods',
						'type'              => 'checkbox',
						'choices'           => array(), // Populated by acf/load_field.
						'allow_null'        => 0,
						'other_choice'      => 0,
						'save_other_choice' => 0,
						'layout'            => 'horizontal',
						'return_format'     => 'value', // Values saved are the method instance ID strings.
					),
				),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'product',
						),
					),
				),
				'menu_order'            => 0,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen'        => '',
			)
		);
	}

	/**
	 * Populate the ACF field choices dynamically with shipping methods.
	 * Uses transient caching for performance.
	 *
	 * @param array $field ACF field settings.
	 * @return array Modified ACF field with `choices` populated.
	 */
	public function acf_load_field_populate_shipping_methods( $field ) {
		// If WooCommerce not available, leave choices empty — graceful failure.
		if ( ! $this->wc_available ) {
			return $field;
		}

		// Try cached value first.
		$choices = get_transient( $this->transient_key );

		if ( false === $choices || ! is_array( $choices ) ) {
			$choices = array();

			// Get shipping zones from WC. This returns an array of zone arrays with zone_id and zone_name.
			if ( class_exists( 'WC_Shipping_Zones' ) ) {
				$zones = WC_Shipping_Zones::get_zones();

				// Build a list that includes the default (zone 0).
				$zone_names = array();
				if ( is_array( $zones ) ) {
					foreach ( $zones as $zone ) {
						if ( isset( $zone['zone_id'], $zone['zone_name'] ) ) {
							$zone_names[ absint( $zone['zone_id'] ) ] = $zone['zone_name'];
						}
					}
				}
				// Default zone (locations not covered by other zones).
				$zone_names[0] = __( 'Locations not covered by other zones', 'per-product-shipping-disable' );

				// Iterate zones and collect method instances.
				foreach ( $zone_names as $zone_id => $zone_name ) {
					try {
						$zone_obj = new WC_Shipping_Zone( $zone_id );
						$methods  = $zone_obj->get_shipping_methods( true );

						if ( is_array( $methods ) ) {
							foreach ( $methods as $method ) {
								// Determine method id and instance id with robust fallbacks.
								$method_id   = null;
								$instance_id = null;

								if ( is_object( $method ) ) {
									if ( method_exists( $method, 'get_method_id' ) ) {
										$method_id = $method->get_method_id();
									} elseif ( isset( $method->id ) ) {
										$method_id = $method->id;
									}

									if ( method_exists( $method, 'get_instance_id' ) ) {
										$instance_id = $method->get_instance_id();
									} elseif ( isset( $method->instance_id ) ) {
										$instance_id = $method->instance_id;
									}
								}

								if ( empty( $method_id ) ) {
									continue; // cannot build identifier.
								}

								// Some shipping methods might not have instance ids — normalize to 0.
								$instance_id = '' === (string) $instance_id ? 0 : absint( $instance_id );

								// Value stored in ACF must be the actual method identifier used by WC rates.
								$value = $method_id . ':' . $instance_id;

								// Prefer a human-readable title when available.
								$title = '';
								if ( is_object( $method ) ) {
									if ( method_exists( $method, 'get_title' ) ) {
										$title = $method->get_title();
									} elseif ( isset( $method->title ) ) {
										$title = $method->title;
									} elseif ( isset( $method->method_title ) ) {
										$title = $method->method_title;
									}
								}
								if ( empty( $title ) ) {
									$title = ucwords( str_replace( array( '_', '-' ), ' ', $method_id ) );
								}

								// Label shows zone + method title.
								$label = sprintf( '%s — %s', $zone_name, $title );

								// Avoid duplicate keys — last one wins (shouldn't happen often).
								$choices[ $value ] = $label;
							}
						}
					} catch ( Exception $e ) {
						// Ignore errors per graceful degradation.
						continue;
					}
				}
			}

			// Cache for 6 hours by default.
			set_transient( $this->transient_key, $choices, 6 * HOUR_IN_SECONDS );
		}

		// Assign choices to field (ACF expects value => label array).
		$field['choices'] = (array) $choices;
		return $field;
	}

	/**
	 * Main filter: remove disabled shipping rates from the package rates.
	 *
	 * @param array $rates   Array of WC_Shipping_Rate objects keyed by rate ID.
	 * @param array $package Shipping package data.
	 * @return array Modified rates array.
	 */
	public function filter_woocommerce_package_rates( $rates, $package ) {
		// Fail gracefully if prerequisites missing.
		if ( ! $this->wc_available || ! function_exists( 'get_field' ) ) {
			return $rates;
		}

		// If cart is empty or rates empty, nothing to do.
		if ( empty( $rates ) ) {
			return $rates;
		}

		if ( WC()->cart && WC()->cart->is_empty() ) {
			return $rates;
		}

		// Collect disabled methods from all products in the cart (union).
		$disabled_methods = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			// Prefer product_id from cart item (works for variations as well).
			$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			if ( ! $product_id ) {
				continue;
			}

			// Use ACF to get the saved values for this product. Returns array or single value depending on field settings.
			$values = get_field( 'disabled_shipping_methods', $product_id );
			if ( empty( $values ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				$values = array( $values );
			}

			// Normalize values to strings and merge.
			$values           = array_map( 'strval', $values );
			$disabled_methods = array_merge( $disabled_methods, $values );
		}

		$disabled_methods = array_unique( array_filter( $disabled_methods ) );

		// If no disabled methods, leave rates unchanged.
		if ( empty( $disabled_methods ) ) {
			return $rates;
		}

		/**
		 * Allow other code to modify the list of disabled method identifiers.
		 * Receives: (array) $disabled_methods, (array) $rates, (array) $package
		 */
		$disabled_methods = (array) apply_filters( 'per_product_disabled_shipping_methods', $disabled_methods, $rates, $package );

		// Remove rates which match any disabled method identifier.
		foreach ( $rates as $rate_id => $rate ) {
			// $rate is a WC_Shipping_Rate object in modern WC.
			if ( is_object( $rate ) ) {
				$method_id   = ( method_exists( $rate, 'get_method_id' ) ) ? $rate->get_method_id() : ( isset( $rate->method_id ) ? $rate->method_id : null );
				$instance_id = ( method_exists( $rate, 'get_instance_id' ) ) ? $rate->get_instance_id() : ( isset( $rate->instance_id ) ? $rate->instance_id : null );

				// Construct identifier to compare against stored values.
				$constructed_id = null;
				if ( null !== $method_id && null !== $instance_id ) {
					$constructed_id = $method_id . ':' . absint( $instance_id );
				}

				// Compare either by constructed id or the array key $rate_id.
				if ( ( $constructed_id && in_array( $constructed_id, $disabled_methods, true ) ) || in_array( $rate_id, $disabled_methods, true ) ) {
					unset( $rates[ $rate_id ] );
				}
			} elseif ( in_array( $rate_id, $disabled_methods, true ) ) {
				// Fallback: rate keyed strings matching disabled methods.
				unset( $rates[ $rate_id ] );
			}
		}

		return $rates;
	}

	/**
	 * Clear cached shipping methods transient.
	 *
	 * @return void
	 */
	public function clear_shipping_methods_cache() {
		delete_transient( $this->transient_key );
	}

	/**
	 * If admin clicked a special ?per_product_clear_shipping_cache=1 URL, clear the cache.
	 * Helpful during development to avoid needing WP-CLI or DB work.
	 */
	/**
	 * Handle admin cache-clear request.
	 *
	 * @return void
	 */
	public function maybe_handle_clear_cache() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['per_product_clear_shipping_cache'] ) && '1' === $_GET['per_product_clear_shipping_cache'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->clear_shipping_methods_cache();
			add_action(
				'admin_notices',
				function () {
					printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Per-Product cache cleared.', 'per-product-shipping-disable' ) );
				}
			);
		}
	}
}

// Initialize plugin singleton.
PerProductShippingDisable::instance();
