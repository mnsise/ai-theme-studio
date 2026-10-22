<?php
/**
 * Normalised design tokens and their mapping to Elementor global ids.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Design tokens.
 */
final class DesignTokens {

	/**
	 * Map of token color => Elementor custom global color id. Plugin-owned ids
	 * mean generation never alters the site's existing system colors.
	 */
	public const COLOR_GLOBALS = array(
		'primary'    => 'atsprimary',
		'secondary'  => 'atssecondary',
		'text'       => 'atstext',
		'accent'     => 'atsaccent',
		'heading'    => 'atsheading',
		'background' => 'atsbg',
		'surface'    => 'atssurface',
		'muted'      => 'atsmuted',
		'dark'       => 'atsdark',
		'on_dark'    => 'atsondark',
		'on_primary' => 'atsonprimary',
		'border'     => 'atsborder',
	);

	public const TYPO_GLOBALS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body', 'button', 'small' );

	public const PADDING = array(
		'sm' => array( 32, 28, 24 ),
		'md' => array( 56, 44, 36 ),
		'lg' => array( 88, 64, 48 ),
		'xl' => array( 120, 88, 64 ),
	);

	public const GAP = array(
		'sm' => 12,
		'md' => 24,
		'lg' => 40,
	);

	/**
	 * Raw tokens.
	 *
	 * @var array<string, mixed>
	 */
	private array $t;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $tokens Validated design_system output.
	 */
	public function __construct( array $tokens ) {
		$defaults = array(
			'colors'          => array(
				'primary'    => '#2563EB',
				'secondary'  => '#0F172A',
				'accent'     => '#2563EB',
				'background' => '#FFFFFF',
				'surface'    => '#F5F7FB',
				'text'       => '#334155',
				'muted'      => '#556274',
				'heading'    => '#0F172A',
				'dark'       => '#0F172A',
				'on_dark'    => '#F8FAFC',
				'on_primary' => '#FFFFFF',
				'border'     => '#E2E8F0',
			),
			'typography'      => array(
				'heading_font'   => 'Vazirmatn',
				'body_font'      => 'Vazirmatn',
				'base_size'      => 16,
				'scale'          => 1.25,
				'heading_weight' => '700',
				'body_weight'    => '400',
				'line_height'    => 1.8,
			),
			'radius'          => array(
				'small'  => 6,
				'medium' => 12,
				'button' => 8,
			),
			'spacing'         => array(
				'section_padding' => 'lg',
				'gap'             => 'md',
			),
			'container_width' => 1200,
			'shadow'          => 'soft',
			'button_style'    => 'solid',
		);
		$this->t  = array(
			'colors'          => array_merge( $defaults['colors'], (array) ( $tokens['colors'] ?? array() ) ),
			'typography'      => array_merge( $defaults['typography'], (array) ( $tokens['typography'] ?? array() ) ),
			'radius'          => array_merge( $defaults['radius'], (array) ( $tokens['radius'] ?? array() ) ),
			'spacing'         => array_merge( $defaults['spacing'], (array) ( $tokens['spacing'] ?? array() ) ),
			'container_width' => (int) ( $tokens['container_width'] ?? $defaults['container_width'] ),
			'shadow'          => (string) ( $tokens['shadow'] ?? $defaults['shadow'] ),
			'button_style'    => (string) ( $tokens['button_style'] ?? $defaults['button_style'] ),
		);
	}

	/**
	 * Color hex.
	 *
	 * @param string $name Token.
	 */
	public function color( string $name ): string {
		return strtoupper( (string) ( $this->t['colors'][ $name ] ?? '#000000' ) );
	}

	/**
	 * Global reference for a color token.
	 *
	 * @param string $name Token.
	 */
	public function color_ref( string $name ): string {
		return 'globals/colors?id=' . ( self::COLOR_GLOBALS[ $name ] ?? $name );
	}

	/**
	 * Global reference for a typography token.
	 *
	 * @param string $name h1..h6|body|button|small.
	 */
	public function typo_ref( string $name ): string {
		return 'globals/typography?id=ats' . $name;
	}

	/**
	 * Typography settings for a token, with a key prefix (e.g. "typography", "title_typography").
	 *
	 * @param string $name   Token (h1..h6, body, button, small).
	 * @param string $prefix Control prefix.
	 * @return array<string, mixed>
	 */
	public function typography( string $name, string $prefix = 'typography' ): array {
		$spec = $this->typo_spec( $name );
		$out  = array(
			$prefix . '_typography'         => 'custom',
			$prefix . '_font_family'        => $spec['family'],
			$prefix . '_font_weight'        => $spec['weight'],
			$prefix . '_font_size'          => self::px( $spec['size'][0] ),
			$prefix . '_font_size_tablet'   => self::px( $spec['size'][1] ),
			$prefix . '_font_size_mobile'   => self::px( $spec['size'][2] ),
			$prefix . '_line_height'        => array(
				'unit'  => 'em',
				'size'  => $spec['line_height'],
				'sizes' => array(),
			),
		);
		return $out;
	}

	/**
	 * Resolved typography spec.
	 *
	 * @param string $name Token.
	 * @return array{family: string, weight: string, size: array<int, int>, line_height: float}
	 */
	public function typo_spec( string $name ): array {
		$ty      = $this->t['typography'];
		$base    = (int) $ty['base_size'];
		$scale   = (float) $ty['scale'];
		$heading = 0 === strpos( $name, 'h' ) && 2 === strlen( $name );
		$steps   = array(
			'h1' => 5,
			'h2' => 4,
			'h3' => 2,
			'h4' => 1,
			'h5' => 0.5,
			'h6' => 0,
		);
		if ( $heading ) {
			$desktop = (int) round( $base * ( $scale ** $steps[ $name ] ) );
			$desktop = min( $desktop, 64 );
			$factor  = array(
				'h1' => array( 0.82, 0.66 ),
				'h2' => array( 0.86, 0.74 ),
				'h3' => array( 0.92, 0.86 ),
			)[ $name ] ?? array( 1, 0.95 );
			return array(
				'family'      => (string) $ty['heading_font'],
				'weight'      => (string) $ty['heading_weight'],
				'size'        => array( $desktop, (int) round( $desktop * $factor[0] ), max( $base, (int) round( $desktop * $factor[1] ) ) ),
				'line_height' => in_array( $name, array( 'h1', 'h2' ), true ) ? 1.35 : 1.5,
			);
		}
		switch ( $name ) {
			case 'button':
				return array(
					'family'      => (string) $ty['body_font'],
					'weight'      => '600',
					'size'        => array( $base, $base, $base ),
					'line_height' => 1.4,
				);
			case 'small':
				$small = max( 13, $base - 2 );
				return array(
					'family'      => (string) $ty['body_font'],
					'weight'      => '600',
					'size'        => array( $small, $small, $small ),
					'line_height' => 1.6,
				);
			default:
				return array(
					'family'      => (string) $ty['body_font'],
					'weight'      => (string) $ty['body_weight'],
					'size'        => array( $base, $base, max( 15, $base - 1 ) ),
					'line_height' => (float) $ty['line_height'],
				);
		}
	}

	/**
	 * Section padding [desktop, tablet, mobile].
	 *
	 * @param string|null $size Override.
	 * @return array<int, int>
	 */
	public function padding( ?string $size = null ): array {
		$size = $size ? $size : (string) $this->t['spacing']['section_padding'];
		return self::PADDING[ $size ] ?? self::PADDING['lg'];
	}

	/**
	 * Gap in px.
	 *
	 * @param string|null $size Override.
	 */
	public function gap( ?string $size = null ): int {
		$size = $size ? $size : (string) $this->t['spacing']['gap'];
		return self::GAP[ $size ] ?? self::GAP['md'];
	}

	/**
	 * Radius.
	 *
	 * @param string $name small|medium|button.
	 */
	public function radius( string $name ): int {
		return (int) ( $this->t['radius'][ $name ] ?? 8 );
	}

	/**
	 * Container width.
	 */
	public function container_width(): int {
		return (int) $this->t['container_width'];
	}

	/**
	 * Shadow style.
	 */
	public function shadow(): string {
		return $this->t['shadow'];
	}

	/**
	 * Button style.
	 */
	public function button_style(): string {
		return $this->t['button_style'];
	}

	/**
	 * Font families in use.
	 *
	 * @return array<int, string>
	 */
	public function fonts(): array {
		return array_values( array_unique( array( (string) $this->t['typography']['heading_font'], (string) $this->t['typography']['body_font'] ) ) );
	}

	/**
	 * Raw tokens.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->t;
	}

	/**
	 * Elementor slider value in px.
	 *
	 * @param int|float $size Size.
	 * @return array<string, mixed>
	 */
	public static function px( $size ): array {
		return array(
			'unit'  => 'px',
			'size'  => $size,
			'sizes' => array(),
		);
	}

	/**
	 * Elementor dimensions value.
	 *
	 * @param int  $top    Top.
	 * @param int  $right  Right.
	 * @param int  $bottom Bottom.
	 * @param int  $left   Left.
	 * @param string $unit Unit.
	 * @return array<string, mixed>
	 */
	public static function dims( int $top, int $right, int $bottom, int $left, string $unit = 'px' ): array {
		return array(
			'unit'     => $unit,
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => ( $top === $right && $right === $bottom && $bottom === $left ),
		);
	}

	/**
	 * Darken/lighten a hex color by a percentage (-100..100).
	 *
	 * @param string $hex     Color.
	 * @param int    $percent Amount.
	 */
	public static function shade( string $hex, int $percent ): string {
		$hex = ltrim( $hex, '#' );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) );
			$c    = $percent < 0 ? $c * ( 1 + $percent / 100 ) : $c + ( 255 - $c ) * ( $percent / 100 );
			$out .= str_pad( dechex( (int) max( 0, min( 255, round( $c ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return strtoupper( $out );
	}
}
