<?php
/**
 * HEIC Support
 *
 * @package HEIC_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: HEIC Support
 * Description: Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded.
 * Author: Breakfast Co.
 * Author URI: https://breakfastco.xyz/
 * Version: 1.0.1
 * Text-domain: heic-support
 * License: GPLv2
 */

// CORE FUNCTIONALITY.

/**
 * Allow .heic files to be uploaded into the Media Library.
 *
 * @param  array            $mimes Array of allowed mime types.
 * @param  int|WP_User|null $user The current user.
 * @return array
 */
function heic_support_add_mimes( $mimes, $user ) {
	if ( empty( $mimes['heic'] ) ) {
		$mimes['heic'] = 'image/heic';
	}
	return $mimes;
}
add_filter( 'upload_mimes', 'heic_support_add_mimes', 10, 2 );

/**
 * Filter callback on add_attachment. Creates a .webp copy of .heic images
 * uploaded to the Media Library.
 *
 * @param  int $post_id The ID of a new attachment.
 * @return void
 */
function heic_support_create_webp_copy( $post_id ) {
	// Is ImageMagick running?
	if ( ! class_exists( 'Imagick' ) ) {
		// No.
		return;
	}
	$file_path = get_attached_file( $post_id );
	if ( false === $file_path ) {
		return;
	}
	// Is the attachment an heic?
	if ( 'heic' !== pathinfo( $file_path, PATHINFO_EXTENSION ) ) {
		// No.
		return;
	}
	$imagick = new Imagick();
	try {
		if ( $imagick->readImage( $file_path ) ) {
			$imagick->setImageFormat( 'webp' );
			// Create a path to create a webp copy of the image.
			$name       = basename( $file_path, '.heic' ) . '.webp';
			$upload_dir = wp_upload_dir();
			$imagick->writeImage( $upload_dir['path'] . DIRECTORY_SEPARATOR . $name );

			/**
			 * The Media Library loads these files, but not the Editor.
			 *
			 * @link https://developer.wordpress.org/reference/functions/media_sideload_image/#more-information
			 */
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$copy_post_id = media_sideload_image( $upload_dir['url'] . '/' . $name, 0, get_the_title( $post_id ), 'id' );
			if ( ! is_wp_error( $copy_post_id ) ) {
				update_post_meta( $copy_post_id, '_heic_support_copy_of', $post_id );
				update_post_meta( $post_id, '_heic_support_copy_of', $copy_post_id );
			}
		}
	} catch ( ImagickException $ie ) {
		// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
		// The version of Imagick does not support heic
		return;
	}
}
add_action( 'add_attachment', 'heic_support_create_webp_copy', 12, 1 );

/**
 * When .heic files are added to the Media Library, populate their width,
 * height, and other attributes that live in meta key _wp_attachment_metadata.
 *
 * @param array  $metadata      An array of attachment meta data.
 * @param int    $attachment_id Current attachment ID.
 * @param string $context       Additional context. Can be 'create' when metadata was initially created for new attachment
 *                              or 'update' when the metadata was updated.
 * @return array
 */
function heic_support_populate_meta( $metadata, $attachment_id, $context ) {
	// Is ImageMagick running?
	if ( ! class_exists( 'Imagick' ) ) {
		// No.
		return $metadata;
	}
	$file_path = get_attached_file( $attachment_id );
	if ( false === $file_path ) {
		return $metadata;
	}
	// Is the attachment an heic?
	if ( 'heic' !== pathinfo( $file_path, PATHINFO_EXTENSION ) ) {
		// No.
		return $metadata;
	}
	// Are the width and height missing?
	if ( false === $metadata ) {
		$metadata = array();
	}
	if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
		// No.
		return $metadata;
	}
	$imagick = new Imagick();
	try {
		if ( $imagick->readImage( $file_path ) ) {
			$new_values          = $imagick->getImageGeometry();
			$new_values['sizes'] = array();
			$new_values['file']  = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$metadata            = wp_parse_args( $metadata, $new_values );
			return $metadata;
		}
	} catch ( ImagickException $ie ) {
		// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
		// The version of Imagick does not support heic
		return $metadata;
	}
}
add_filter( 'wp_generate_attachment_metadata', 'heic_support_populate_meta', 10, 3 );

// SETTINGS PAGE.

/**
 * Adds an HEIC Support submenu under Media.
 *
 * @return void
 */
function heic_support_add_menu() {
	add_submenu_page(
		'upload.php',
		_x( 'HEIC Support', 'UI Strings', 'heic-support' ),
		_x( 'HEIC Support', 'UI Strings', 'heic-support' ),
		'upload_files',
		'heic-support',
		'heic_support_page_content'
	);
}
add_action( 'admin_menu', 'heic_support_add_menu' );

/**
 * Returns the ImageMagick version string.
 *
 * @return string
 */
function heic_support_imagemagick_version() {
	if ( ! class_exists( 'Imagick' ) ) {
		return '';
	}
	return Imagick::getVersion()['versionString'];
}

/**
 * Outputs HTML that renders the HEIC Support dashboard page.
 *
 * @return void
 */
function heic_support_page_content() {
	?>
<div class="wrap">
	<h2><?php echo esc_html_x( 'HEIC Support', 'UI Strings', 'heic-support' ); ?></h2>
	<p>
	<?php
	esc_html_e( 'This plugin only works on servers running ImageMagick 7 or above. ', 'heic-support' );
	if ( ! class_exists( 'Imagick' ) ) {
		esc_html_e( 'ImageMagick is not installed on this server. Some hosts require a switch be flipped before the program is available to a site.', 'heic-support' );
		echo '</p>';
	} else {
		$imagick = new Imagick();
		try {
			if ( $imagick->readImage( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'image4.heic' ) ) {
				$imagick->setImageFormat( 'webp' );

				// Create a webp copy of the image.
				$path = heic_support_test_file_path();
				$imagick->writeImage( $path );
				$upload_dir = wp_upload_dir();
				$name = basename( $path );
				printf(
					'</p><p>%s</p><h3>%s</h3><p>%s <a href="https://caniuse.com/webp">caniuse.com/webp</a></p><img src="%s" width="%d" />',
					esc_html( heic_support_imagemagick_version() ),
					esc_html__( 'Sample .heic image converted to .webp', 'heic-support' ),
					esc_html__( 'This plugin is working and can convert .heic images to .webp. If you do not see an image below, your browser may not support .webp.', 'heic-support' ),
					esc_attr( $upload_dir['url'] . '/' . $name ),
					esc_attr( get_option( 'medium_size_w' ) )
				);
			}
		} catch ( ImagickException $ie ) {
			// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
			$msg = 'no decode delegate for this image format `HEIC\'';
			if ( false !== strpos( $ie->getMessage(), $msg ) ) {
				esc_html_e( 'ImageMagick is installed, but does not support HEIC. Oldest version with HEIC support is 7.0.8-46. Installed version is ', 'heic-support' );
				echo esc_html( heic_support_imagemagick_version() );
				echo '</p>';
			}
		}
	}
	printf(
		'<h3>%s</h3><p>%s<br><a href="https://imagemagick.org/">imagemagick.org</a></p><p>%s<br /><a href="https://github.com/csalzano/heic-support">github.com/csalzano/heic-support</p></p></div>',
		esc_html__( 'Links', 'heic-support' ),
		esc_html__( 'ImageMagick homepage', 'heic-support' ),
		esc_html__( 'Plugin homepage', 'heic-support' )
	);
}

/**
 * Returns a file path to the webp copy of the test image.
 *
 * @return void
 */
function heic_support_test_file_path() {
	$upload_dir = wp_upload_dir();
	return $upload_dir['path'] . DIRECTORY_SEPARATOR
		. 'heic-support-image4.webp';
}

/**
 * Deletes the test image when the plugin is uninstalled.
 *
 * @return void
 */
function heic_support_uninstall() {
	if ( ! is_multisite() ) {
		$path = heic_support_test_file_path();
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	} else {
		$sites = get_sites(
			array(
				'network' => 1,
				'limit'   => 1000,
			)
		);
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );

			$path = heic_support_test_file_path();
			if ( file_exists( $path ) ) {
				unlink( $path );
			}

			restore_current_blog();
		}
	}
}
register_uninstall_hook( __FILE__, 'heic_support_uninstall' );
