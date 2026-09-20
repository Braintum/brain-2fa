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
$excluded_vendor_path = $root . '/vendor/endroid/qr-code/assets/';

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
 * @param string $source Source path.
 * @param string $destination Destination path.
 * @return void
 */
function brain2fa_copy( string $source, string $destination, string $excluded_vendor_path ): void {
	if ( is_dir( $source ) ) {
		mkdir( $destination, 0755, true );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( str_starts_with( $item->getPathname(), $excluded_vendor_path ) ) {
				continue;
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

foreach ( $directories as $directory ) {
	brain2fa_copy( $root . '/' . $directory, $staging_dir . '/' . $directory, $excluded_vendor_path );
}

foreach ( $files as $file ) {
	brain2fa_copy( $root . '/' . $file, $staging_dir . '/' . $file, $excluded_vendor_path );
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
