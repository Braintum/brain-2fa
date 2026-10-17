<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * System clock for the OTPHP library.
 *
 * @package Brain_2FA
 */

namespace Brain_2FA\TwoFactor;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-20 clock that returns the current server time.
 *
 * OTPHP 11.3+ deprecates creating a TOTP without a clock, and 12.0 will require one.
 *
 * @since 1.1.0
 */
class SystemClock implements ClockInterface {

	/**
	 * Get the current time.
	 *
	 * @return DateTimeImmutable Current time.
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable();
	}
}
