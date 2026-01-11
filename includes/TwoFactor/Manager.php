<?php // phpcs:disable PSR1.Files.SideEffects
/**
 * Two Factor Authentication Manager
 *
 * @package Brain_2FA
 */
declare( strict_types=1 );

namespace Brain_2FA\TwoFactor;

use Brain_2FA\TwoFactor\Interfaces\TwoFactorMethodInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager class for handling two-factor authentication methods.
 */
class Manager {
	/**
	 * Singleton instance.
	 *
	 * @var Manager|null
	 */
	protected static $instance = null;

	/**
	 * Registered two-factor authentication methods.
	 *
	 * @var TwoFactorMethodInterface[]
	 */
	protected $methods = array();

	/**
	 * Get singleton instance of the Manager.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a two-factor authentication method.
	 *
	 * @param TwoFactorMethodInterface $method The two-factor method to register.
	 * @return void
	 */
	public function register_method( TwoFactorMethodInterface $method ): void {
		$this->methods[ $method->get_id() ] = $method;
	}

	/**
	 * Get a specific two-factor authentication method by ID.
	 *
	 * @param string $id The method ID.
	 * @return TwoFactorMethodInterface|null The method instance or null if not found.
	 */
	public function get_method( string $id ) {
		return $this->methods[ $id ] ?? null;
	}

	/**
	 * Get all registered two-factor authentication methods.
	 *
	 * @return TwoFactorMethodInterface[] Array of registered methods.
	 */
	public function get_methods(): array {
		return $this->methods;
	}
}
