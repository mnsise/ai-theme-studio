<?php
/**
 * Writes SEO title/description/noindex for generated pages into the active
 * SEO plugin's own fields (never overwriting values a user already entered),
 * and always into this plugin's fields for the optional built-in output.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Meta manager.
 */
class MetaManager {

	public const TITLE   = '_ats_seo_title';
	public const DESC    = '_ats_seo_description';
	public const NOINDEX = '_ats_noindex';

	/**
	 * Detector.
	 *
	 * @var SeoPluginDetector
	 */
	private SeoPluginDetector $detector;

	/**
	 * Values this plugin stored before the current write.
	 *
	 * @var array<string, string>
	 */
	private array $previous = array();

	/**
	 * Constructor.
	 *
	 * @param SeoPluginDetector $detector Detector.
	 */
	public function __construct( SeoPluginDetector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Store metadata.
	 *
	 * @param int    $post_id     Page.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 * @param bool   $noindex     Noindex.
	 * @return string Where it was written (plugin id or "ats").
	 */
	public function write( int $post_id, string $title, string $description, bool $noindex ): string {
		$title       = sanitize_text_field( $title );
		$description = sanitize_textarea_field( $description );
		$this->previous = array(
			'title'       => (string) get_post_meta( $post_id, self::TITLE, true ),
			'description' => (string) get_post_meta( $post_id, self::DESC, true ),
		);
		update_post_meta( $post_id, self::TITLE, $title );
		update_post_meta( $post_id, self::DESC, $description );
		update_post_meta( $post_id, self::NOINDEX, $noindex ? '1' : '' );

		$plugin = $this->detector->active();
		switch ( $plugin ) {
			case 'yoast':
				$this->set_if_empty( $post_id, '_yoast_wpseo_title', $title );
				$this->set_if_empty( $post_id, '_yoast_wpseo_metadesc', $description );
				if ( $noindex ) {
					$this->set_if_empty( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
				}
				break;
			case 'rankmath':
				$this->set_if_empty( $post_id, 'rank_math_title', $title );
				$this->set_if_empty( $post_id, 'rank_math_description', $description );
				if ( $noindex ) {
					$this->set_if_empty( $post_id, 'rank_math_robots', array( 'noindex' ) );
				}
				break;
			case 'seopress':
				$this->set_if_empty( $post_id, '_seopress_titles_title', $title );
				$this->set_if_empty( $post_id, '_seopress_titles_desc', $description );
				if ( $noindex ) {
					$this->set_if_empty( $post_id, '_seopress_robots_index', 'yes' );
				}
				break;
			case 'tsf':
				$this->set_if_empty( $post_id, '_genesis_title', $title );
				$this->set_if_empty( $post_id, '_genesis_description', $description );
				if ( $noindex ) {
					$this->set_if_empty( $post_id, '_genesis_noindex', 1 );
				}
				break;
			case 'aioseo':
				// AIOSEO stores data in its own tables without a public write API; values stay in this plugin's
				// fields and are reported in the SEO audit so the user can copy them.
				return 'ats';
		}
		return '' === $plugin ? 'ats' : $plugin;
	}

	/**
	 * Read this plugin's stored values.
	 *
	 * @param int $post_id Page.
	 * @return array{title: string, description: string, noindex: bool}
	 */
	public function read( int $post_id ): array {
		return array(
			'title'       => (string) get_post_meta( $post_id, self::TITLE, true ),
			'description' => (string) get_post_meta( $post_id, self::DESC, true ),
			'noindex'     => '1' === get_post_meta( $post_id, self::NOINDEX, true ),
		);
	}

	/**
	 * Set meta only when empty or still equal to the value this plugin wrote
	 * previously (values entered by the user are never overwritten).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 */
	private function set_if_empty( int $post_id, string $key, $value ): void {
		$current = get_post_meta( $post_id, $key, true );
		$ours    = is_string( $current ) && '' !== $current && in_array( $current, $this->previous, true );
		if ( '' === $current || array() === $current || null === $current || $ours ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
