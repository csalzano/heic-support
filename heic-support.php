<?php
/**
 * HEIC Support
 *
 * @package HEIC_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: HEIC Support
 * Description: Creates a .webp copy of .heic images when they are uploaded to the Media Library.
 * Author: Corey Salzano
 * Version: 0.1.0
 * Text-domain: heic-support
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
			$copy_post_id = media_sideload_image( $upload_dir['url'] . '/' . $name, 0, get_the_title( $post_id ), 'id' );
			if ( ! is_wp_error( $copy_post_id ) ) {
				update_post_meta( $copy_post_id, '_heic_support_copy_of', $post_id );
				update_post_meta( $post_id, '_heic_support_copy_of', $copy_post_id );
			} else {
				error_log( '[HEIC Support] ' . $copy_post_id->get_error_message() );
			}
		}
	} catch ( ImagickException $ie ) {
		// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
		error_log( '[HEIC Support] Failed to create copy of ' . $file_path . ', ' . $ie->getMessage() );
	}
}
add_action( 'add_attachment', 'heic_support_create_webp_copy', 10, 1 );

// - filter requests for .heic images for the substitute URLs and paths


// SETTINGS PAGE.

/**
 * heic_support_add_menu
 *
 * @return void
 */
function heic_support_add_menu() {
	add_submenu_page(
		'upload.php',
		__( 'HEIC Support', 'heic-support' ),
		__( 'HEIC Support', 'heic-support' ),
		'upload_files',
		'heic-support',
		'heic_support_page_content'
	);
}
add_action( 'admin_menu', 'heic_support_add_menu' );

/**
 * heic_support_imagemagick_version
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
 * heic_support_page_content
 *
 * @return void
 */
function heic_support_page_content() {
	?>
<div class="wrap">
	<h2><?php esc_html_e( 'HEIC Support', 'heic-support' ); ?></h2>
	<p>
	<?php
	esc_html_e( 'This plugin only works on servers running ImageMagick 7 or above. ', 'heic-support' );
	if ( ! class_exists( 'Imagick' ) ) {
		esc_html_e( 'ImageMagick is not installed on this server. Some hosts require a switch be flipped before the program is available to a site.', 'heic-support' );
		echo '</p>';
	} else {
		$folder  = dirname( __FILE__ );
		$imagick = new Imagick();
		try {
			if ( $imagick->readImage( $folder . DIRECTORY_SEPARATOR . 'image1.heic' ) ) {
				$imagick->setImageFormat( 'webp' );

				// Create a path to create a webp copy of the image.
				$name       = 'heic-support-image1.webp';
				$upload_dir = wp_upload_dir();
				$imagick->writeImage( $upload_dir['path'] . DIRECTORY_SEPARATOR . $name );
				printf(
					'</p><p>%s</p><h3>%s</h3><p>%s <a href="https://caniuse.com/webp">caniuse.com/webp</a></p><img src="%s" width="%d" />',
					esc_html( heic_support_imagemagick_version() ),
					esc_html__( 'Sample .heic image converted to .webp', 'heic-support' ),
					esc_html__( 'This plugin is working and can convert .heic images to .webp. If you do not see an image below, your browser may not support .webp.', 'heic-support' ),
					esc_attr( $upload_dir['url'] . '/' . $name ),
					esc_attr( get_option( 'large_size_w' ) )
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
	?>
<h3>Links</h3><p>ImageMagick homepage<br><a href="https://imagemagick.org/">imagemagick.org</a></p><p>Plugin homepage<br /><a href="https://github.com/csalzano/heic-support">github.com/csalzano/heic-support</p>
</p></div>
	<?php
}
