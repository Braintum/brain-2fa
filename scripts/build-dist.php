<?php
/**
 * Build a WordPress.org-ready plugin archive.
 *
 * @package Brain2FA
 */

declare( strict_types=1 );

$root        = dirname( __DIR__ );
$plugin_slug = 'brain-2fa';
$dist_dir    = $root . '/dist';
$staging_dir = $dist_dir . '/' . $plugin_slug;
$archive     = $dist_dir . '/' . $plugin_slug . '.zip';
$directories = array( 'assets', 'includes', 'languages', 'vendor' );
$files       = array( 'brain-2fa.php', 'composer.json', 'composer.lock', 'readme.txt' );

// Paths never shipped: lbuchs/webauthn's _test folder contains a web-reachable demo server.
$excluded    = array( 'vendor/lbuchs/webauthn/_test' );

/**
 * Remove a known build directory.
 *
 * @param string $directory Directory to remove.
 * @return void
 */
function brain2fa_remove_directory( string $directory ): void {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $directory );
}

/**
 * Copy a file or directory into the staging directory.
 *
 * @param string   $source Source path.
 * @param string   $destination Destination path.
 * @param string[] $excluded Absolute paths to skip, with everything below them.
 * @return void
 */
function brain2fa_copy( string $source, string $destination, array $excluded = array() ): void {
	if ( is_dir( $source ) ) {
		mkdir( $destination, 0755, true );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			foreach ( $excluded as $excluded_path ) {
				if ( $item->getPathname() === $excluded_path || str_starts_with( $item->getPathname(), $excluded_path . '/' ) ) {
					continue 2;
				}
			}

			$target = $destination . '/' . $iterator->getSubPathName();
			if ( $item->isDir() ) {
				mkdir( $target, 0755, true );
			} else {
				copy( $item->getPathname(), $target );
			}
		}

		return;
	}

	copy( $source, $destination );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP ZipArchive extension is required to build a distribution package.\n" );
	exit( 1 );
}

brain2fa_remove_directory( $staging_dir );
if ( file_exists( $archive ) ) {
	unlink( $archive );
}

mkdir( $staging_dir, 0755, true );

$excluded_paths = array_map( static fn( string $path ) => $root . '/' . $path, $excluded );

foreach ( $directories as $directory ) {
	brain2fa_copy( $root . '/' . $directory, $staging_dir . '/' . $directory, $excluded_paths );
}

foreach ( $files as $file ) {
	brain2fa_copy( $root . '/' . $file, $staging_dir . '/' . $file );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Unable to create distribution archive.\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $staging_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ( $iterator as $item ) {
	$zip->addFile( $item->getPathname(), $plugin_slug . '/' . $iterator->getSubPathName() );
}

$zip->close();

printf( "Created %s\n", $archive );
