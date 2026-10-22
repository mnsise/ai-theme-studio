<?php
/**
 * Scripted AI provider for integration tests and the Lighthouse demo site.
 * Installed only through the public `ats_ai_provider` filter; never shipped as a runtime fallback.
 *
 * @package AIThemeStudio
 */

// phpcs:disable

use AIThemeStudio\AI\AIRequest;
use AIThemeStudio\AI\AIResponse;
use AIThemeStudio\AI\ProviderInterface;

function ats_test_rest( string $method, string $path, array $params = array() ) {
	$req = new WP_REST_Request( $method, '/ai-theme-studio/v1' . $path );
	if ( $params ) {
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
	}
	return rest_do_request( $req );
}

/**
 * Answers by inspecting which output schema was requested.
 */
final class ATS_Test_Provider implements ProviderInterface {
	public array $calls      = array();
	public array $fail_queue = array();
	public bool $garbage     = false;
	private array $fixture;
	private int $page_calls  = 0;

	public function __construct( array $fixture ) {
		$this->fixture = $fixture;
	}
	public function id(): string {
		return 'test';
	}
	public function model(): string {
		return 'scripted';
	}
	public function supports_vision(): bool {
		return true;
	}
	public function complete( AIRequest $r ): AIResponse {
		$this->calls[] = $r;
		if ( $this->fail_queue ) {
			throw array_shift( $this->fail_queue );
		}
		if ( $this->garbage ) {
			return new AIResponse( 'Sorry, I cannot help with that.' );
		}
		$schema = substr( $r->user, (int) strrpos( $r->user, '## Output JSON Schema' ) );
		$data   = $this->answer( $schema, $r );
		return new AIResponse( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), false, array( 'input' => 100, 'output' => 50 ) );
	}
	private function answer( string $schema, AIRequest $r ): array {
		if ( str_contains( $schema, '"site_name"' ) ) {
			return array(
				'site_name'      => 'کلینیک لبخند',
				'site_type'      => 'medical',
				'language'       => 'fa',
				'direction'      => 'rtl',
				'required_pages' => array( 'home', 'services', 'contact', 'not_found' ),
				'business'       => array( 'email' => 'info@example.test' ),
				'warnings'       => array( 'نظر مشتری واقعی ارائه نشده است؛ جای آن خالی می‌ماند.' ),
			);
		}
		if ( str_contains( $schema, '"suggested_role"' ) ) {
			preg_match_all( '/img_\d+/', $r->user, $m );
			return array(
				'images'  => array_map(
					static fn( $ref ) => array(
						'ref'            => $ref,
						'suggested_role' => 'hero',
						'alt_text'       => 'نمای داخلی کلینیک',
						'decorative'     => false,
						'use_as_asset'   => true,
						'description'    => 'test image',
					),
					array_values( array_unique( $m[0] ) )
				),
				'overall' => array( 'palette' => array( '#0E7490' ) ),
			);
		}
		if ( str_contains( $schema, '"in_footer"' ) ) {
			return $this->fixture['sitemap'];
		}
		if ( str_contains( $schema, '"on_primary"' ) ) {
			return $this->fixture['design'];
		}
		if ( str_contains( $schema, '"sections"' ) ) {
			++$this->page_calls;
			if ( 1 === $this->page_calls ) {
				return $this->fixture['home'];
			}
			return array(
				'key'      => 'generic',
				'sections' => array(
					array(
						'id'       => 'hero',
						'type'     => 'hero',
						'elements' => array(
							array( 'type' => 'heading', 'level' => 'h1', 'text' => 'عنوان صفحه ' . $this->page_calls ),
							array( 'type' => 'text', 'html' => '<p>متن صفحه با <a href="page:home">پیوند به خانه</a>.</p>' ),
							array( 'type' => 'button', 'text' => 'تماس', 'link' => 'page:contact' ),
						),
					),
					array(
						'id'       => 'more',
						'type'     => 'content',
						'elements' => array(
							array( 'type' => 'heading', 'level' => 'h2', 'text' => 'بیشتر بدانید' ),
							array( 'type' => 'contact_form' ),
						),
					),
				),
			);
		}
		if ( str_contains( $schema, '"$ref":"section"' ) ) {
			return array(
				'id'       => 'services',
				'type'     => 'services',
				'elements' => array(
					array( 'type' => 'heading', 'level' => 'h2', 'text' => 'خدمات بازتولیدشده' ),
					array( 'type' => 'text', 'html' => '<p>نسخه جدید</p>' ),
				),
			);
		}
		if ( str_contains( $schema, '"title"' ) && str_contains( $schema, '"description"' ) ) {
			return array( 'pages' => array() );
		}
		throw new LogicException( 'Unknown schema request' );
	}
}
