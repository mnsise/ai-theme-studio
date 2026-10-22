<?php
/**
 * Downscales attachments into base64 payloads for vision models. Only the
 * resized copy is sent; EXIF metadata is not preserved by the image editor.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Image preparer.
 */
final class ImagePreparer {

	public const MAX_EDGE  = 1280;
	public const MAX_BYTES = 4 * 1024 * 1024;

	/**
	 * Prepare one attachment.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $label         Label shown to the model.
	 * @return array{mime: string, data: string, label: string}|null
	 */
	public static function prepare( int $attachment_id, string $label ): ?array {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}
		$mime   = (string) get_post_mime_type( $attachment_id );
		$out    = 'image/png' === $mime ? 'image/png' : 'image/jpeg';
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return self::raw( $path, $mime, $label );
		}
		$size = $editor->get_size();
		if ( $size['width'] > self::MAX_EDGE || $size['height'] > self::MAX_EDGE ) {
			$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		}
		$editor->set_quality( 82 );
		$tmp   = wp_tempnam( 'ats-vision' );
		$saved = $editor->save( $tmp, $out );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			wp_delete_file( $tmp );
			return self::raw( $path, $mime, $label );
		}
		$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $saved['path'] );
		wp_delete_file( $tmp );
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return null;
		}
		return array(
			'mime'  => $out,
			'data'  => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'label' => $label,
		);
	}

	/**
	 * Fallback: send the original if small enough.
	 *
	 * @param string $path  Path.
	 * @param string $mime  Mime.
	 * @param string $label Label.
	 * @return array{mime: string, data: string, label: string}|null
	 */
	private static function raw( string $path, string $mime, string $label ): ?array {
		if ( filesize( $path ) > self::MAX_BYTES || ! in_array( $mime, ImageUploader::MIMES, true ) ) {
			return null;
		}
		return array(
			'mime'  => $mime,
			'data'  => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore
			'label' => $label,
		);
	}
}
