<?php
/**
 * Minimal JSON-Schema subset validator and sanitiser for AI output.
 *
 * Supported keywords: type (string or list), enum, properties, required,
 * additionalProperties (unknown keys are stripped), items, minItems,
 * maxItems (truncates), minLength, maxLength (truncates), pattern (unicode),
 * minimum/maximum (clamps), default, $ref (to a named definition).
 *
 * The validator returns a cleaned copy of the data plus hard errors. Soft
 * problems (too long strings, too many items, out of range numbers) are fixed
 * in place so that verbose model output does not fail the whole generation.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Validator.
 */
class SchemaValidator {

	/**
	 * Named definitions for $ref.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $definitions;

	/**
	 * Errors collected during the current run.
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Max recursion depth.
	 */
	private const MAX_DEPTH = 12;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $definitions Named schemas.
	 */
	public function __construct( array $definitions = array() ) {
		$this->definitions = $definitions;
	}

	/**
	 * Validate and clean.
	 *
	 * @param mixed                $data   Input.
	 * @param array<string, mixed> $schema Schema.
	 * @return array{valid: bool, errors: array<int, string>, data: mixed}
	 */
	public function validate( $data, array $schema ): array {
		$this->errors = array();
		$clean        = $this->walk( $data, $schema, '$', 0 );
		return array(
			'valid'  => empty( $this->errors ),
			'errors' => $this->errors,
			'data'   => $clean,
		);
	}

	/**
	 * Recursive walk.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   JSON path for messages.
	 * @param int                  $depth  Depth.
	 * @return mixed
	 */
	private function walk( $value, array $schema, string $path, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			$this->errors[] = "{$path}: nesting too deep";
			return null;
		}

		if ( isset( $schema['$ref'] ) ) {
			$ref = $schema['$ref'];
			if ( ! isset( $this->definitions[ $ref ] ) ) {
				$this->errors[] = "{$path}: unknown schema reference {$ref}";
				return null;
			}
			$schema = array_merge( $this->definitions[ $ref ], array_diff_key( $schema, array( '$ref' => true ) ) );
		}

		$types = isset( $schema['type'] ) ? (array) $schema['type'] : array();
		$value = $this->coerce( $value, $types );

		if ( $types && ! $this->matches_type( $value, $types ) ) {
			$this->errors[] = sprintf( '%s: expected %s, got %s', $path, implode( '|', $types ), $this->type_of( $value ) );
			return $value;
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$this->errors[] = sprintf( '%s: value "%s" is not one of [%s]', $path, is_scalar( $value ) ? (string) $value : $this->type_of( $value ), implode( ', ', array_map( 'strval', $schema['enum'] ) ) );
			return $value;
		}

		if ( is_string( $value ) ) {
			return $this->check_string( $value, $schema, $path );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				$value = $schema['minimum'];
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				$value = $schema['maximum'];
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			$is_object = in_array( 'object', $types, true ) && ! in_array( 'array', $types, true );
			if ( $is_object || ( ! $types && isset( $schema['properties'] ) ) ) {
				return $this->check_object( $value, $schema, $path, $depth );
			}
			return $this->check_array( $value, $schema, $path, $depth );
		}
		return $value;
	}

	/**
	 * String checks.
	 *
	 * @param string               $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path.
	 */
	private function check_string( string $value, array $schema, string $path ): string {
		$value = trim( $value );
		if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > $schema['maxLength'] ) {
			$value = rtrim( mb_substr( $value, 0, (int) $schema['maxLength'] ) );
		}
		if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < $schema['minLength'] ) {
			$this->errors[] = "{$path}: string shorter than {$schema['minLength']} characters";
		}
		if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) {
			$this->errors[] = "{$path}: \"{$value}\" does not match pattern {$schema['pattern']}";
		}
		return $value;
	}

	/**
	 * Object checks.
	 *
	 * @param array<string, mixed> $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path.
	 * @param int                  $depth  Depth.
	 * @return array<string, mixed>
	 */
	private function check_object( array $value, array $schema, string $path, int $depth ): array {
		$props  = $schema['properties'] ?? array();
		$out    = array();
		$extras = $schema['additionalProperties'] ?? false;

		foreach ( $schema['required'] ?? array() as $req ) {
			if ( ! array_key_exists( $req, $value ) || null === $value[ $req ] ) {
				if ( isset( $props[ $req ] ) && array_key_exists( 'default', $props[ $req ] ) ) {
					$value[ $req ] = $props[ $req ]['default'];
				} else {
					$this->errors[] = "{$path}: missing required property \"{$req}\"";
				}
			}
		}

		foreach ( $props as $name => $prop_schema ) {
			if ( array_key_exists( $name, $value ) ) {
				if ( null === $value[ $name ] && $this->allows_null( $prop_schema ) ) {
					$out[ $name ] = null;
					continue;
				}
				$out[ $name ] = $this->walk( $value[ $name ], $prop_schema, "{$path}.{$name}", $depth + 1 );
			} elseif ( array_key_exists( 'default', $prop_schema ) ) {
				$out[ $name ] = $prop_schema['default'];
			}
		}

		if ( true === $extras ) {
			foreach ( $value as $k => $v ) {
				if ( ! array_key_exists( $k, $props ) ) {
					$out[ $k ] = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * Array checks.
	 *
	 * @param array<int|string, mixed> $value  Value.
	 * @param array<string, mixed>     $schema Schema.
	 * @param string                   $path   Path.
	 * @param int                      $depth  Depth.
	 * @return array<int, mixed>
	 */
	private function check_array( array $value, array $schema, string $path, int $depth ): array {
		$value = array_values( $value );
		if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) {
			$value = array_slice( $value, 0, (int) $schema['maxItems'] );
		}
		if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
			$this->errors[] = "{$path}: expected at least {$schema['minItems']} items";
		}
		if ( isset( $schema['items'] ) ) {
			foreach ( $value as $i => $item ) {
				$value[ $i ] = $this->walk( $item, $schema['items'], "{$path}[{$i}]", $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Light, lossless coercion for common model mistakes.
	 *
	 * @param mixed              $value Value.
	 * @param array<int, string> $types Allowed types.
	 * @return mixed
	 */
	private function coerce( $value, array $types ) {
		if ( is_string( $value ) ) {
			if ( in_array( 'integer', $types, true ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
				return (int) $value;
			}
			if ( in_array( 'number', $types, true ) && is_numeric( trim( $value ) ) ) {
				return (float) $value;
			}
			if ( in_array( 'boolean', $types, true ) && in_array( strtolower( $value ), array( 'true', 'false' ), true ) ) {
				return 'true' === strtolower( $value );
			}
		}
		if ( is_int( $value ) && in_array( 'string', $types, true ) && ! in_array( 'integer', $types, true ) && ! in_array( 'number', $types, true ) ) {
			return (string) $value;
		}
		if ( is_int( $value ) && in_array( 'number', $types, true ) && ! in_array( 'integer', $types, true ) ) {
			return (float) $value;
		}
		return $value;
	}

	/**
	 * Type match.
	 *
	 * @param mixed              $value Value.
	 * @param array<int, string> $types Types.
	 */
	private function matches_type( $value, array $types ): bool {
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'string':
					if ( is_string( $value ) ) {
						return true;
					}
					break;
				case 'integer':
					if ( is_int( $value ) ) {
						return true;
					}
					break;
				case 'number':
					if ( is_int( $value ) || is_float( $value ) ) {
						return true;
					}
					break;
				case 'boolean':
					if ( is_bool( $value ) ) {
						return true;
					}
					break;
				case 'null':
					if ( null === $value ) {
						return true;
					}
					break;
				case 'object':
					if ( is_array( $value ) && ( empty( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
						return true;
					}
					break;
				case 'array':
					if ( is_array( $value ) && ( empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/**
	 * Whether a schema allows null.
	 *
	 * @param array<string, mixed> $schema Schema.
	 */
	private function allows_null( array $schema ): bool {
		return in_array( 'null', (array) ( $schema['type'] ?? array() ), true );
	}

	/**
	 * Describe a value type.
	 *
	 * @param mixed $value Value.
	 */
	private function type_of( $value ): string {
		if ( is_array( $value ) ) {
			return ( empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ? 'array' : 'object';
		}
		return gettype( $value );
	}
}
