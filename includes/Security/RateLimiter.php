<?php
/**
 * Fixed-window per-user rate limiter for AI-consuming actions.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiter.
 */
final class RateLimiter {

	/**
	 * Consume one unit. Returns false when the limit is exceeded.
	 *
	 * @param string $bucket  Bucket name.
	 * @param int    $limit   Max hits per window.
	 * @param int    $window  Window in seconds.
	 * @param int    $user_id User id (defaults to current).
	 */
	public static function hit( string $bucket, int $limit, int $window = HOUR_IN_SECONDS, int $user_id = 0 ): bool {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$slot    = (int) floor( time() / $window );
		$key     = 'ats_rl_' . md5( $bucket . '|' . $user_id . '|' . $slot );
		$count   = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
