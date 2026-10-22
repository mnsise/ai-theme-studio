<?php
/**
 * Secure reference-image upload into the WordPress Media Library.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Media;

use AIThemeStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Image uploader.
 */
class ImageUploader {

	public const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	public const MIN_DIM = 16;
	public const MAX_DIM = 8000;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validate an uploaded file array (from $_FILES / WP_REST_Request::get_file_params()).
	 *
	 * @param array<string, mixed> $file File.
	 * @return true|\WP_Error
	 */
	public function validate( array $file ) {
		if ( ! isset( $file['tmp_name'], $file['name'], $file['size'] ) || ! empty( $file['error'] ) ) {
			return new \WP_Error( 'ats_upload_error', __( 'آپلود فایل ناموفق بود.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) && ! apply_filters( 'ats_allow_non_uploaded_file', false, $file ) ) {
			return new \WP_Error( 'ats_upload_invalid', __( 'فایل ارسالی معتبر نیست.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$max = (int) $this->settings->get( 'max_upload_mb' ) * MB_IN_BYTES;
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max ) {
			/* translators: %d: MB */
			return new \WP_Error( 'ats_upload_size', sprintf( __( 'حجم تصویر باید کمتر از %d مگابایت باشد.', 'ai-theme-studio' ), (int) $this->settings->get( 'max_upload_mb' ) ), array( 'status' => 413 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) $file['name'] ), self::MIMES );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) ) {
			return new \WP_Error( 'ats_upload_type', __( 'فقط تصاویر JPEG، PNG و WebP پذیرفته می‌شوند.', 'ai-theme-studio' ), array( 'status' => 415 ) );
		}
		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || ! in_array( $info['mime'] ?? '', self::MIMES, true ) ) {
			return new \WP_Error( 'ats_upload_corrupt', __( 'فایل یک تصویر معتبر نیست.', 'ai-theme-studio' ), array( 'status' => 415 ) );
		}
		if ( $info[0] < self::MIN_DIM || $info[1] < self::MIN_DIM || $info[0] > self::MAX_DIM || $info[1] > self::MAX_DIM ) {
			/* translators: 1: min, 2: max */
			return new \WP_Error( 'ats_upload_dimensions', sprintf( __( 'ابعاد تصویر باید بین %1$d و %2$d پیکسل باشد.', 'ai-theme-studio' ), self::MIN_DIM, self::MAX_DIM ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Store the file as a media attachment.
	 *
	 * @param array<string, mixed> $file       File.
	 * @param int                  $project_id Project.
	 * @return int|\WP_Error Attachment id.
	 */
	public function store( array $file, int $project_id ) {
		$valid = $this->validate( $file );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name = sanitize_file_name( (string) $file['name'] );
		$id   = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $file['tmp_name'],
			),
			0,
			null,
			array( 'post_title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ) )
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_ats_project_id', $project_id );
		return (int) $id;
	}

	/**
	 * Public data for the UI.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array<string, mixed>
	 */
	public static function describe( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		return array(
			'id'       => $attachment_id,
			'url'      => (string) wp_get_attachment_url( $attachment_id ),
			'thumb'    => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
			'mime'     => (string) get_post_mime_type( $attachment_id ),
			'filename' => wp_basename( (string) get_attached_file( $attachment_id ) ),
			'alt'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}
}
