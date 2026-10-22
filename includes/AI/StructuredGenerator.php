<?php
/**
 * Prompt -> provider -> JSON -> schema validation -> semantic validation,
 * with one bounded repair round-trip when the output is invalid.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

use AIThemeStudio\Core\Logger;
use AIThemeStudio\Validation\Schemas;
use AIThemeStudio\Validation\SchemaValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Structured generator.
 */
class StructuredGenerator {

	/**
	 * Providers.
	 *
	 * @var ProviderFactory
	 */
	private ProviderFactory $providers;

	/**
	 * Prompts.
	 *
	 * @var PromptLibrary
	 */
	private PromptLibrary $prompts;

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Cached provider.
	 *
	 * @var ProviderInterface|null
	 */
	private ?ProviderInterface $provider = null;

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $providers Factory.
	 * @param PromptLibrary   $prompts   Prompts.
	 * @param Logger|null     $logger    Logger.
	 */
	public function __construct( ProviderFactory $providers, PromptLibrary $prompts, ?Logger $logger = null ) {
		$this->providers = $providers;
		$this->prompts   = $prompts;
		$this->logger    = $logger;
	}

	/**
	 * Provider in use.
	 */
	public function provider(): ProviderInterface {
		if ( null === $this->provider ) {
			$this->provider = $this->providers->make();
		}
		return $this->provider;
	}

	/**
	 * Generate structured data.
	 *
	 * Options:
	 * - images:      list of images for vision models.
	 * - validate:    callable( array $data ): array{data, errors, warnings} for semantic checks.
	 * - repair:      prompt template used for repair (default "repair").
	 * - max_repairs: int (default 1).
	 * - project_id, job_id: for logging.
	 * - temperature: float.
	 *
	 * @param string               $template Prompt template.
	 * @param array<string, mixed> $vars     Template variables.
	 * @param string               $schema   Schema name.
	 * @param array<string, mixed> $options  Options.
	 * @return array{data: array<string, mixed>, warnings: array<int, string>}
	 * @throws AIException On provider failure or unrecoverable invalid output.
	 */
	public function generate( string $template, array $vars, string $schema, array $options = array() ): array {
		$options = array_merge(
			array(
				'images'      => array(),
				'validate'    => null,
				'repair'      => 'repair',
				'max_repairs' => 1,
				'project_id'  => 0,
				'job_id'      => 0,
				'temperature' => 0.4,
			),
			$options
		);

		$schema_def = Schemas::get( $schema );
		$system     = $this->prompts->render( 'system' );
		$user       = $this->prompts->render( $template, $vars ) . "\n\n## Output JSON Schema\n" . $this->schema_text( $schema_def );

		$provider = $this->provider();
		$request  = new AIRequest( $system, $user, $options['images'], true, $this->providers->max_tokens(), (float) $options['temperature'] );

		$attempt = 0;
		while ( true ) {
			$response = $provider->complete( $request );
			$this->log_usage( $template, $response, $options );

			$errors = array();
			$data   = null;
			try {
				if ( $response->truncated ) {
					$errors[] = 'The output was truncated because it exceeded the token limit. Produce a shorter, complete JSON object.';
				}
				$raw    = JsonExtractor::extract( $response->text );
				$result = ( new SchemaValidator( Schemas::definitions() ) )->validate( $raw, $schema_def );
				$errors = array_merge( $errors, $result['errors'] );
				$data   = $result['data'];
			} catch ( AIException $e ) {
				$errors[] = 'Output was not a valid JSON object.';
			}

			$warnings = array();
			if ( ! $errors && is_callable( $options['validate'] ) ) {
				$semantic = call_user_func( $options['validate'], $data );
				$data     = $semantic['data'];
				$errors   = $semantic['errors'];
				$warnings = $semantic['warnings'];
			}

			if ( ! $errors ) {
				return array(
					'data'     => $data,
					'warnings' => $warnings,
				);
			}

			if ( $attempt >= (int) $options['max_repairs'] ) {
				throw new AIException(
					sprintf(
						/* translators: %s: validation errors */
						__( 'خروجی مدل با ساختار مورد انتظار مطابقت نداشت: %s', 'ai-theme-studio' ),
						implode( ' | ', array_slice( $errors, 0, 5 ) )
					),
					true,
					array( 'errors' => array_slice( $errors, 0, 30 ) )
				);
			}
			++$attempt;

			if ( $this->logger ) {
				$this->logger->warning( 'AI output invalid, requesting repair: ' . implode( ' | ', array_slice( $errors, 0, 5 ) ), (int) $options['project_id'], (int) $options['job_id'] );
			}

			$repair_template = is_callable( $options['repair'] ) ? (string) call_user_func( $options['repair'], $errors ) : (string) $options['repair'];
			$repair_user     = $this->prompts->render(
				$repair_template,
				array(
					'errors'          => implode( "\n", array_map( static fn( $e ) => '- ' . $e, array_slice( $errors, 0, 30 ) ) ),
					'previous_output' => mb_substr( $response->text, 0, 60000 ),
					'original_task'   => $user,
				)
			);
			// Images are not re-sent on repair: the fix concerns structure only.
			$request = new AIRequest( $system, $repair_user, array(), true, $this->providers->max_tokens(), 0.2 );
		}
	}

	/**
	 * Compact schema description for the prompt.
	 *
	 * @param array<string, mixed> $schema Schema.
	 */
	private function schema_text( array $schema ): string {
		$payload = array( 'schema' => $schema );
		if ( false !== strpos( (string) wp_json_encode( $schema ), '$ref' ) ) {
			$payload['definitions'] = Schemas::definitions();
		}
		return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Usage logging (no prompt contents, no secrets).
	 *
	 * @param string               $template Template.
	 * @param AIResponse           $response Response.
	 * @param array<string, mixed> $options  Options.
	 */
	private function log_usage( string $template, AIResponse $response, array $options ): void {
		if ( ! $this->logger ) {
			return;
		}
		$this->logger->info(
			sprintf( 'AI call "%s" completed (%d input / %d output tokens).', $template, $response->usage['input'] ?? 0, $response->usage['output'] ?? 0 ),
			(int) $options['project_id'],
			(int) $options['job_id'],
			array(
				'provider' => $this->provider()->id(),
				'model'    => $this->provider()->model(),
			)
		);
	}
}
