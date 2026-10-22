<?php
/**
 * Persists Elementor element data through Elementor's Document API (falls
 * back to the documented meta keys when the API is unavailable), keeps a
 * backup of the previous version and tracks hashes to detect manual edits.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

use AIThemeStudio\Database\ArtifactRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Document writer.
 */
class DocumentWriter {

	public const META_HASH     = '_ats_generated_hash';
	public const META_SECTIONS = '_ats_section_hashes';

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
	 * Write elements to a post.
	 *
	 * @param int                              $post_id  Post id.
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param array<string, mixed>             $opts     template, template_type, fallback_html, project_id.
	 * @return array{method: string}
	 * @throws \RuntimeException When validation fails.
	 */
	public function write( int $post_id, array $elements, array $opts = array() ): array {
		$opts = array_merge(
			array(
				'template'      => null,
				'template_type' => 'wp-page',
				'fallback_html' => '',
				'project_id'    => 0,
			),
			$opts
		);

		$check = DocumentValidator::validate( $elements, array( $this, 'widget_available' ) );
		if ( ! $check['valid'] ) {
			throw new \RuntimeException( 'Elementor data failed validation: ' . implode( '; ', array_slice( $check['errors'], 0, 5 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->backup( $post_id, (int) $opts['project_id'] );

		$method = 'meta';
		if ( $this->compat->meets_minimum() ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
				if ( $document && $document->is_editable_by_current_user() ) {
					if ( $document->save( array( 'elements' => $elements ) ) ) {
						// Elementor sets the edit mode when its editor opens, not in Document::save().
						$document->set_is_built_with_elementor( true );
						$method = 'document';
					}
				}
			} catch ( \Throwable $e ) {
				$method = 'meta';
			}
		}
		if ( 'meta' === $method ) {
			$this->write_meta( $post_id, $elements, (string) $opts['template_type'], (string) $opts['fallback_html'] );
		}

		if ( $opts['template'] ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_key( (string) $opts['template'] ) );
		}

		$this->store_hashes( $post_id );
		return array( 'method' => $method );
	}

	/**
	 * Widget availability passthrough (skipped if Elementor is not loaded).
	 *
	 * @param string $name Widget name.
	 */
	public function widget_available( string $name ): bool {
		if ( ! $this->compat->is_active() ) {
			return true;
		}
		return $this->compat->widget_available( $name );
	}

	/**
	 * Direct meta write using Elementor's documented storage format.
	 *
	 * @param int                              $post_id       Post id.
	 * @param array<int, array<string, mixed>> $elements      Elements.
	 * @param string                           $template_type Elementor document type.
	 * @param string                           $fallback_html Plain HTML for post_content.
	 */
	private function write_meta( int $post_id, array $elements, string $template_type, string $fallback_html ): void {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', sanitize_key( $template_type ) );
		update_post_meta( $post_id, '_elementor_version', $this->compat->version() ? $this->compat->version() : ATS_MIN_ELEMENTOR );
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $elements ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( '' !== $fallback_html ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_kses_post( $fallback_html ),
				)
			);
		}
		if ( $this->compat->is_active() && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		}
	}

	/**
	 * Read decoded element data.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string, mixed>>
	 */
	public function read( int $post_id ): array {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether the post's Elementor data changed since this plugin last wrote it.
	 *
	 * @param int $post_id Post id.
	 */
	public function is_modified( int $post_id ): bool {
		$stored = (string) get_post_meta( $post_id, self::META_HASH, true );
		if ( '' === $stored ) {
			return false;
		}
		return $stored !== $this->hash_elements( $this->read( $post_id ) );
	}

	/**
	 * Whether a specific top-level section changed since generation.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $element_id Element id.
	 */
	public function section_modified( int $post_id, string $element_id ): bool {
		$hashes = get_post_meta( $post_id, self::META_SECTIONS, true );
		if ( ! is_array( $hashes ) || ! isset( $hashes[ $element_id ] ) ) {
			return false;
		}
		$elements = $this->read( $post_id );
		$index    = DocumentValidator::find_top_level( $elements, $element_id );
		if ( null === $index ) {
			return true;
		}
		return $hashes[ $element_id ] !== md5( (string) wp_json_encode( $elements[ $index ] ) );
	}

	/**
	 * Replace one top-level element in place, keeping all other (possibly edited) sections.
	 *
	 * @param int                  $post_id    Post id.
	 * @param string               $element_id Existing element id.
	 * @param array<string, mixed> $element    New element.
	 * @param int                  $project_id Project.
	 * @return bool False if the element no longer exists.
	 */
	public function replace_section( int $post_id, string $element_id, array $element, int $project_id ): bool {
		$elements = $this->read( $post_id );
		$index    = DocumentValidator::find_top_level( $elements, $element_id );
		if ( null === $index ) {
			return false;
		}
		$element['id']      = $element_id;
		$elements[ $index ] = $element;
		$this->write(
			$post_id,
			$elements,
			array(
				'project_id'    => $project_id,
				'template_type' => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
			)
		);
		return true;
	}

	/**
	 * Store whole-document and per-section hashes.
	 *
	 * @param int $post_id Post id.
	 */
	private function store_hashes( int $post_id ): void {
		$elements = $this->read( $post_id );
		update_post_meta( $post_id, self::META_HASH, $this->hash_elements( $elements ) );
		$sections = array();
		foreach ( $elements as $el ) {
			$sections[ (string) $el['id'] ] = md5( (string) wp_json_encode( $el ) );
		}
		update_post_meta( $post_id, self::META_SECTIONS, $sections );
	}

	/**
	 * Hash of normalised element data.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 */
	private function hash_elements( array $elements ): string {
		return md5( (string) wp_json_encode( $elements ) );
	}

	/**
	 * Back up existing Elementor data (version history).
	 *
	 * @param int $post_id    Post id.
	 * @param int $project_id Project.
	 */
	private function backup( int $post_id, int $project_id ): void {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! $raw || ! $project_id ) {
			return;
		}
		$this->artifacts->save(
			$project_id,
			'elementor_backup',
			(string) $post_id,
			array(
				'data'     => is_string( $raw ) ? $raw : wp_json_encode( $raw ),
				'modified' => get_post_field( 'post_modified_gmt', $post_id ),
			)
		);
	}

	/**
	 * Restore a backup version.
	 *
	 * @param int $post_id    Post id.
	 * @param int $project_id Project.
	 * @param int $version    Artifact version.
	 */
	public function restore( int $post_id, int $project_id, int $version ): bool {
		$backup = $this->artifacts->version( $project_id, 'elementor_backup', (string) $post_id, $version );
		if ( ! $backup || empty( $backup['data'] ) ) {
			return false;
		}
		$elements = json_decode( (string) $backup['data'], true );
		if ( ! is_array( $elements ) ) {
			return false;
		}
		$this->write(
			$post_id,
			$elements,
			array(
				'project_id'    => $project_id,
				'template_type' => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
			)
		);
		return true;
	}
}
