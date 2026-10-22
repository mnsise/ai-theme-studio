<?php
/**
 * Applies design tokens to the active Elementor Kit (Site Settings).
 *
 * Two levels:
 * - register_globals(): adds plugin-owned custom colors/typography (ids "ats*").
 *   Non-destructive: existing system colors, fonts and theme styles stay unchanged.
 * - apply_site_styles(): sets system colors/fonts and theme styles (body, headings,
 *   links, buttons, form fields). Only on explicit authorization at publish time;
 *   a backup is stored and can be restored.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

use AIThemeStudio\Database\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Kit manager.
 */
class KitManager {

	/**
	 * Compatibility.
	 *
	 * @var Compatibility
	 */
	private Compatibility $compat;

	/**
	 * Artifacts.
	 *
	 * @var ArtifactRepository
	 */
	private ArtifactRepository $artifacts;

	/**
	 * Constructor.
	 *
	 * @param Compatibility      $compat    Compatibility.
	 * @param ArtifactRepository $artifacts Artifacts.
	 */
	public function __construct( Compatibility $compat, ArtifactRepository $artifacts ) {
		$this->compat    = $compat;
		$this->artifacts = $artifacts;
	}

	/**
	 * Add/refresh plugin-owned global colors and typography.
	 *
	 * @param DesignTokens $t          Tokens.
	 * @param int          $project_id Project.
	 */
	public function register_globals( DesignTokens $t, int $project_id ): bool {
		$kit_id = $this->compat->kit_id();
		if ( ! $kit_id ) {
			return false;
		}
		$settings = $this->settings( $kit_id );
		$this->backup( $kit_id, $settings, $project_id, 'kit_backup_globals' );

		$colors = array();
		foreach ( DesignTokens::COLOR_GLOBALS as $token => $id ) {
			$colors[] = array(
				'_id'   => $id,
				'title' => 'ATS ' . ucwords( str_replace( '_', ' ', $token ) ),
				'color' => $t->color( $token ),
			);
		}
		$settings['custom_colors'] = $this->merge_items( (array) ( $settings['custom_colors'] ?? array() ), $colors );

		$typos = array();
		foreach ( DesignTokens::TYPO_GLOBALS as $token ) {
			$typos[] = array_merge(
				array(
					'_id'   => 'ats' . $token,
					'title' => 'ATS ' . strtoupper( $token ),
				),
				$t->typography( $token, 'typography' )
			);
		}
		$settings['custom_typography'] = $this->merge_items( (array) ( $settings['custom_typography'] ?? array() ), $typos );

		$this->save( $kit_id, $settings );
		return true;
	}

	/**
	 * Apply site-wide styles (explicitly authorized).
	 *
	 * @param DesignTokens $t          Tokens.
	 * @param int          $project_id Project.
	 */
	public function apply_site_styles( DesignTokens $t, int $project_id ): bool {
		$kit_id = $this->compat->kit_id();
		if ( ! $kit_id ) {
			return false;
		}
		$settings = $this->settings( $kit_id );
		$this->backup( $kit_id, $settings, $project_id, 'kit_backup_site' );

		$settings['system_colors'] = $this->merge_items(
			(array) ( $settings['system_colors'] ?? array() ),
			array(
				array(
					'_id'   => 'primary',
					'title' => 'Primary',
					'color' => $t->color( 'primary' ),
				),
				array(
					'_id'   => 'secondary',
					'title' => 'Secondary',
					'color' => $t->color( 'secondary' ),
				),
				array(
					'_id'   => 'text',
					'title' => 'Text',
					'color' => $t->color( 'text' ),
				),
				array(
					'_id'   => 'accent',
					'title' => 'Accent',
					'color' => $t->color( 'accent' ),
				),
			)
		);

		$heading = $t->typo_spec( 'h2' );
		$body    = $t->typo_spec( 'body' );
		$settings['system_typography'] = $this->merge_items(
			(array) ( $settings['system_typography'] ?? array() ),
			array(
				array(
					'_id'                    => 'primary',
					'title'                  => 'Primary',
					'typography_typography'  => 'custom',
					'typography_font_family' => $heading['family'],
					'typography_font_weight' => $heading['weight'],
				),
				array(
					'_id'                    => 'secondary',
					'title'                  => 'Secondary',
					'typography_typography'  => 'custom',
					'typography_font_family' => $heading['family'],
					'typography_font_weight' => '500',
				),
				array(
					'_id'                    => 'text',
					'title'                  => 'Text',
					'typography_typography'  => 'custom',
					'typography_font_family' => $body['family'],
					'typography_font_weight' => $body['weight'],
				),
				array(
					'_id'                    => 'accent',
					'title'                  => 'Accent',
					'typography_typography'  => 'custom',
					'typography_font_family' => $body['family'],
					'typography_font_weight' => '600',
				),
			)
		);

		$settings['container_width']       = DesignTokens::px( $t->container_width() );
		$settings['body_background_background'] = 'classic';
		$settings['body_background_color'] = $t->color( 'background' );
		$settings['body_color']            = $t->color( 'text' );
		$settings                          = array_merge( $settings, $t->typography( 'body', 'body_typography' ) );
		$settings['link_normal_color']     = $t->color( 'primary' );
		$settings['link_hover_color']      = DesignTokens::shade( $t->color( 'primary' ), -15 );
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
			$settings[ $h . '_color' ] = $t->color( 'heading' );
			$settings                  = array_merge( $settings, $t->typography( $h, $h . '_typography' ) );
		}
		$r                                       = $t->radius( 'button' );
		$settings['button_text_color']           = $t->color( 'on_primary' );
		$settings['button_background_color']     = $t->color( 'primary' );
		$settings['button_hover_background_color'] = DesignTokens::shade( $t->color( 'primary' ), -12 );
		$settings['button_border_radius']        = DesignTokens::dims( $r, $r, $r, $r );
		$settings                                = array_merge( $settings, $t->typography( 'button', 'button_typography' ) );
		$fr                                      = $t->radius( 'small' );
		$settings['form_field_background_color'] = $t->color( 'background' );
		$settings['form_field_text_color']       = $t->color( 'text' );
		$settings['form_field_border_border']    = 'solid';
		$settings['form_field_border_color']     = $t->color( 'border' );
		$settings['form_field_border_radius']    = DesignTokens::dims( $fr, $fr, $fr, $fr );

		$this->save( $kit_id, $settings );
		return true;
	}

	/**
	 * Restore the kit as it was before apply_site_styles (or register_globals).
	 *
	 * @param int    $project_id Project.
	 * @param string $which      kit_backup_site|kit_backup_globals.
	 */
	public function restore( int $project_id, string $which = 'kit_backup_site' ): bool {
		$kit_id = $this->compat->kit_id();
		$backup = $kit_id ? $this->artifacts->latest( $project_id, $which, (string) $kit_id ) : null;
		if ( ! is_array( $backup ) ) {
			return false;
		}
		$this->save( $kit_id, $backup );
		return true;
	}

	/**
	 * Current kit settings.
	 *
	 * @param int $kit_id Kit id.
	 * @return array<string, mixed>
	 */
	private function settings( int $kit_id ): array {
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Persist and flush Elementor's generated CSS.
	 *
	 * @param int                  $kit_id   Kit id.
	 * @param array<string, mixed> $settings Settings.
	 */
	private function save( int $kit_id, array $settings ): void {
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( $kit && method_exists( $kit, 'save' ) && $kit->is_editable_by_current_user() ) {
			$kit->save( array( 'settings' => $settings ) );
		} else {
			update_post_meta( $kit_id, '_elementor_page_settings', $settings );
		}
		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Store a backup once per project and kind.
	 *
	 * @param int                  $kit_id     Kit.
	 * @param array<string, mixed> $settings   Settings.
	 * @param int                  $project_id Project.
	 * @param string               $type       Artifact type.
	 */
	private function backup( int $kit_id, array $settings, int $project_id, string $type ): void {
		if ( null === $this->artifacts->latest( $project_id, $type, (string) $kit_id ) ) {
			$this->artifacts->save( $project_id, $type, (string) $kit_id, $settings );
		}
	}

	/**
	 * Replace items with the same _id, append new ones, keep the rest.
	 *
	 * @param array<int, array<string, mixed>> $existing Existing items.
	 * @param array<int, array<string, mixed>> $items    New items.
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_items( array $existing, array $items ): array {
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ $item['_id'] ] = $item;
		}
		$out = array();
		foreach ( $existing as $item ) {
			$id = $item['_id'] ?? '';
			if ( isset( $by_id[ $id ] ) ) {
				$out[] = $by_id[ $id ];
				unset( $by_id[ $id ] );
			} else {
				$out[] = $item;
			}
		}
		return array_merge( $out, array_values( $by_id ) );
	}
}
