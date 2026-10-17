<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Rounded finder pattern for QR codes.
 *
 * @package Brain_2FA
 */

namespace Brain_2FA\TwoFactor;

use BaconQrCode\Renderer\Eye\EyeInterface;
use BaconQrCode\Renderer\Path\Path;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the three QR finder patterns ("eyes") as rounded squares.
 *
 * Paths are centred on the origin in module units: the outer ring spans 7x7
 * modules with a 5x5 hole, and the inner dot spans 3x3 modules. The shapes
 * keep those dimensions, so scanners still find the 1:1:3:1:1 ratio.
 *
 * @since 1.1.0
 */
class QrRoundedEye implements EyeInterface {

	/**
	 * Get the outer ring of the eye.
	 *
	 * The SVG back end fills with the even-odd rule, so the inner square cuts the hole.
	 *
	 * @return Path Outer ring path.
	 */
	public function getExternalPath(): Path { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Interface method.
		return self::rounded_square( self::rounded_square( new Path(), 3.5, 1.6 ), 2.5, 0.9 );
	}

	/**
	 * Get the inner dot of the eye.
	 *
	 * @return Path Inner dot path.
	 */
	public function getInternalPath(): Path { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Interface method.
		return self::rounded_square( new Path(), 1.5, 0.7 );
	}

	/**
	 * Append a rounded square centred on the origin to a path.
	 *
	 * @param Path  $path   Path to append to.
	 * @param float $half   Half the side length, in modules.
	 * @param float $radius Corner radius, in modules.
	 *
	 * @return Path The extended path.
	 */
	private static function rounded_square( Path $path, float $half, float $radius ): Path {
		return $path
			->move( -$half + $radius, -$half )
			->line( $half - $radius, -$half )
			->ellipticArc( $radius, $radius, 0, false, true, $half, -$half + $radius )
			->line( $half, $half - $radius )
			->ellipticArc( $radius, $radius, 0, false, true, $half - $radius, $half )
			->line( -$half + $radius, $half )
			->ellipticArc( $radius, $radius, 0, false, true, -$half, $half - $radius )
			->line( -$half, -$half + $radius )
			->ellipticArc( $radius, $radius, 0, false, true, -$half + $radius, -$half )
			->close();
	}
}
