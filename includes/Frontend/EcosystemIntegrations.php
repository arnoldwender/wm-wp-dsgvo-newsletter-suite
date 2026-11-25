<?php
declare(strict_types=1);

namespace WMCyber\Newsletter\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Newsletter\Database\DoiRepository;

/**
 * WordPress 6.5+ Block Bindings (`wm-newsletter/stats`) and GenerateBlocks 2.x Dynamic Tag (`{{wm_newsletter_stat}}`).
 *
 * Exposes non-personal aggregate subscriber counters from `DoiRepository::get_stats()` to core blocks
 * and GenerateBlocks templates without database exposure of individual subscriber addresses.
 */
final class EcosystemIntegrations {
	public const BINDING_SOURCE = 'wm-newsletter/stats';
	public const GB_TAG         = 'wm_newsletter_stat';
	public const ALLOWED_STATS  = [
		'active_subscribers',
		'pending_doi',
		'synced_to_provider',
		'kept_local',
		'waiting_for_sync',
	];

	private DoiRepository $repo;

	public function __construct( DoiRepository $repo ) {
		$this->repo = $repo;
	}

	public function boot(): void {
		if ( did_action( 'init' ) ) {
			$this->register_integrations();
		} else {
			add_action( 'init', [ $this, 'register_integrations' ] );
		}
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_tag' ], 10, 2 );
	}

	public function register_integrations(): void {
		if ( function_exists( 'register_block_bindings_source' ) ) {
			$already = function_exists( 'get_all_registered_block_bindings_sources' )
				&& isset( get_all_registered_block_bindings_sources()[ self::BINDING_SOURCE ] );
			if ( ! $already ) {
				register_block_bindings_source(
					'wm-newsletter/stats',
					[
						'label'              => __( 'WM Newsletter Statistik', 'wm-dsgvo-newsletter-suite' ),
						'get_value_callback' => [ $this, 'get_bound_stat_value' ],
						'uses_context'       => [],
					]
				);
			}
		}

		if ( class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			new \GenerateBlocks_Register_Dynamic_Tag(
				[
					'title'    => __( 'Newsletter-Statistik', 'wm-dsgvo-newsletter-suite' ),
					'tag'      => 'wm_newsletter_stat',
					'type'     => 'wm-newsletter',
					'supports' => [],
					'options'  => [
						'key' => [
							'type'  => 'select',
							'label' => __( 'Kennzahl', 'wm-dsgvo-newsletter-suite' ),
						],
					],
					'return'   => [ $this, 'render_generateblocks_tag' ],
				]
			);
		}
	}

	public function resolve_stat( string $metric = 'active_subscribers' ): string {
		$key = sanitize_key( '' !== $metric ? $metric : 'active_subscribers' );
		if ( ! in_array( $key, self::ALLOWED_STATS, true ) ) {
			$key = 'active_subscribers';
		}
		$stats = $this->repo->get_stats();
		return number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) );
	}

	/**
	 * @param array  $source_args    Binding source arguments (`key` selects the counter).
	 * @param mixed  $block_instance Block instance.
	 * @param string $attribute_name Bound attribute name.
	 */
	public function get_bound_stat_value( array $source_args, $block_instance = null, string $attribute_name = 'content' ): ?string {
		$key = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? $source_args['key'] : 'active_subscribers';
		return $this->resolve_stat( $key );
	}

	/**
	 * @param array $options Tag options (`key` selects the counter).
	 * @param array $block   Block array.
	 * @param mixed $instance Block instance.
	 */
	public function render_generateblocks_tag( array $options = [], array $block = [], $instance = null ): string {
		$key = isset( $options['key'] ) && is_string( $options['key'] ) ? $options['key'] : 'active_subscribers';
		return esc_html( $this->resolve_stat( $key ) );
	}

	/**
	 * @param mixed $output         Current tag replacement/output.
	 * @param mixed $tag_data       Parsed GenerateBlocks tag descriptor or legacy tag identifier.
	 * @param mixed $options_or_raw Optional legacy options array or raw scalar output.
	 * @return mixed
	 */
	public function filter_generateblocks_tag( mixed $output, mixed $tag_data = '', mixed $options_or_raw = null ): mixed {
		$tag_name = '';
		$options  = [];
		if ( is_string( $tag_data ) ) {
			$tag_name = $tag_data;
			$options  = is_array( $options_or_raw ) ? $options_or_raw : [];
		} elseif ( is_array( $tag_data ) ) {
			$tag_name = (string) ( $tag_data['tag'] ?? $tag_data['name'] ?? '' );
			$options  = isset( $tag_data['options'] ) && is_array( $tag_data['options'] ) ? $tag_data['options'] : [];
		}

		if ( self::GB_TAG === $tag_name && ( null === $output || '' === $output ) ) {
			$key = isset( $options['key'] ) && is_string( $options['key'] ) ? $options['key'] : 'active_subscribers';
			return esc_html( $this->resolve_stat( $key ) );
		}
		return $output;
	}
}
