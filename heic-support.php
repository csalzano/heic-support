<?php
/**
 * HEIC Support
 *
 * @author Corey Salzano <csalzano@duck.com>
 * @package HEIC_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: HEIC Support
 * Description: Allows .heic uploads to the Media Library. Creates .webp or .jpg copies of .heic images when they are uploaded.
 * Plugin URI: https://breakfastco.xyz/heic-support/
 * Author: Breakfast
 * Author URI: https://breakfastco.xyz/
 * Version: 2.1.1
 * Text-domain: heic-support
 * License: GPLv2
 */

if ( ! class_exists( 'Heic_Support_Plugin' ) ) {
	/**
	 * Heic_Support_Plugin
	 */
	class Heic_Support_Plugin {
		/**
		 * Adds filter and action hooks that power this plugin.
		 *
		 * @return void
		 */
		public function add_hooks() {
			// Allow .heic files to be uploaded into the Media Library.
			add_filter( 'upload_mimes', array( $this, 'add_mimes' ) );

			// Creates a copy of .heic images uploaded to the Media Library.
			add_action( 'add_attachment', array( $this, 'create_copy' ), 12, 1 );

			// Replace heic uploads without preserving the heic.
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'replace' ) );

			// Populates width, height, and other attributes in meta key _wp_attachment_metadata.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'populate_meta' ), 10, 3 );

			// Adds settings to the dashboard at Settings → Media.
			add_action( 'admin_init', array( $this, 'add_settings' ) );

			// Adds a link to the plugins list that helps users find Settings → Media.
			add_filter( 'plugin_action_links_heic-support/heic-support.php', array( $this, 'add_settings_link' ) );

			// Deletes the test image when the plugin is uninstalled.
			register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
		}

		/**
		 * Allow .heic files to be uploaded into the Media Library.
		 *
		 * @param  array $mimes Array of allowed mime types.
		 * @return array
		 */
		public function add_mimes( $mimes ) {
			if ( empty( $mimes['heic'] ) ) {
				$mimes['heic'] = 'image/heic';
			}
			return $mimes;
		}

		/**
		 * Adds settings to the dashboard at Settings → Media.
		 *
		 * @return void
		 */
		public function add_settings() {

			$section = 'heic_support_section';
			add_settings_section(
				$section,
				__( 'HEIC Support', 'heic-support' ),
				array( $this, 'callback_section' ),
				'media'
			);

			// Format setting registration.
			register_setting(
				'media',
				'heic_support_format',
				array(
					'type'              => 'string',
					'description'       => __( 'Convert .heic images to this format.', 'heic-support' ),
					'sanitize_callback' => 'sanitize_text_field',
					'show_in_rest'      => true,
				)
			);

			// Replace setting registration.
			register_setting(
				'media',
				'heic_support_replace',
				array(
					'type'              => 'boolean',
					'description'       => __( 'Replace .heic images uploaded to the Media Library instead of creating copies.', 'heic-support' ),
					'sanitize_callback' => 'rest_sanitize_boolean',
					'show_in_rest'      => true,
				)
			);

			// Is the plugin's primary feature going to work?
			if ( ! class_exists( 'Imagick' ) ) {
				// No. Do not output any of the options.
				return;
			}

			// Format setting output.
			add_settings_field(
				'format',
				__( 'Convert To', 'heic-support' ),
				array( $this, 'callback_format_setting' ),
				'media',
				$section
			);

			// Replace setting output.
			add_settings_field(
				'replace',
				__( 'Replace', 'heic-support' ),
				array( $this, 'callback_replace_setting' ),
				'media',
				$section
			);

			// ImageMagick setting.
			add_settings_field(
				'imagemagick',
				__( 'ImageMagick', 'heic-support' ),
				array( $this, 'callback_imagemagick_setting' ),
				'media',
				$section
			);

			// Test setting.
			add_settings_field(
				'test',
				__( 'Test', 'heic-support' ),
				array( $this, 'callback_test_setting' ),
				'media',
				$section
			);
		}

		/**
		 * add_settings_link
		 *
		 * @param  array $links
		 * @return array
		 */
		public function add_settings_link( $links ) {
			$links[] = '<a href="' . admin_url( 'options-media.php' ) . '">' . __( 'Settings', 'heic-support' ) . '</a>';
			return $links;
		}

		/**
		 * get_extension
		 *
		 * @return string
		 */
		protected static function get_extension() {
			$format = self::get_format();
			if ( 'jpeg' === $format ) {
				return apply_filters( 'heic_support_extension', 'jpg' );
			}
			return $format;
		}

		/**
		 * get_format
		 *
		 * @return string
		 */
		protected static function get_format() {
			$value = get_option( 'heic_support_format' );
			if ( empty( $value ) ) {
				$value = 'webp';
			}
			return apply_filters( 'heic_support_format', $value );
		}

		/**
		 * callback_imagemagick_setting
		 *
		 * @return void
		 */
		public function callback_imagemagick_setting() {
			echo esc_html( $this->imagemagick_version() );
		}

		/**
		 * Outputs HTML that renders the Format setting radio buttons.
		 *
		 * @return void
		 */
		public function callback_format_setting() {
			$value = self::get_format();
			printf(
				'<fieldset><label for="heic_support_webp"><input type="radio" id="heic_support_webp" name="heic_support_format" value="webp" %1$s/> %2$s</label><br />'
				. '<label for="heic_support_jpeg"><input type="radio" id="heic_support_jpeg" name="heic_support_format" value="jpeg" %3$s/> %4$s</label></fieldset>',
				checked( $value, 'webp', false ),
				esc_html__( '.webp', 'heic-support' ),
				checked( $value, 'jpeg', false ),
				esc_html__( '.jpg', 'heic-support' )
			);
		}

		/**
		 * Outputs HTML that renders the Replace ID settings checkbox.
		 *
		 * @return void
		 */
		public function callback_replace_setting() {
			$value = get_option( 'heic_support_replace' );
			printf(
				'<input type="checkbox" id="heic_support_replace" name="heic_support_replace" %s/> <label for="heic_support_replace">%s</label><p class="description">%s</p>',
				checked( $value, '1', false ),
				esc_html__( 'Replace .heic images uploaded to the Media Library instead of creating copies.', 'heic-support' ),
				esc_html__( 'Does not preserve the original .heic files.', 'heic-support' )
			);
		}

		public function callback_test_setting() {
			if ( ! class_exists( 'Imagick' ) ) {
				esc_html_e( 'ImageMagick is not installed on this server. This plugin only works on servers running ImageMagick. Some hosts require a switch be flipped before the program is available to a site.', 'heic-support' );
			} else {
				$imagick = new Imagick();
				try {
					if ( $imagick->readImage( __DIR__ . DIRECTORY_SEPARATOR . 'image4.heic' ) ) {
						$imagick->setImageFormat( self::get_format() );

						// Create a copy of the image.
						$path = self::test_file_path();
						$imagick->writeImage( $path );
						$upload_dir = wp_upload_dir();
						$name       = basename( $path );
						printf(
							'<figure><img src="%s" width="%d" /><figcaption>%s .%s.</figcaption></figure>',
							esc_attr( $upload_dir['url'] . '/' . $name ),
							esc_attr( get_option( 'medium_size_w' ) ),
							esc_html__( 'This plugin can convert .heic images. If you do not see an image, your browser may not support', 'heic-support' ),
							esc_html( self::get_extension() )
						);
					}
				} catch ( ImagickException $ie ) {
					// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
					$msg = 'no decode delegate for this image format `HEIC\'';
					if ( false !== strpos( $ie->getMessage(), $msg ) ) {
						esc_html_e( 'ImageMagick is installed, but does not support HEIC. The version might be too old, or perhaps your server is missing libheif. Installed version is ', 'heic-support' );
						echo esc_html( $this->imagemagick_version() );
						echo '</p>';
					}
				}
			}
		}

		/**
		 * callback_section
		 *
		 * @return void
		 */
		public function callback_section() {
			// Is the plugin's primary feature going to work?
			if ( ! class_exists( 'Imagick' ) ) {
				// No.
				printf(
					/* translators: 1. Anchor element opening tag. 2. Anchor element closing tag. */
					esc_html__( 'This server does not provide ImageMagick, and .heic images cannot be converted without it. Ask your web host to enable ImageMagick, or %1$sread our list of compatible hosts%2$s.', 'heic-support' ),
					'<a href="https://breakfastco.xyz/heic-support/#hosting">',
					'</a>'
				);
				return;
			}
			esc_html_e( 'Control how .heic images are handled during uploads.', 'heic-support' );
		}

		/**
		 * check_for_attachment_metadata
		 *
		 * @param  array $data
		 * @param  int $post_id
		 * @return array
		 */
		public function check_for_attachment_metadata( $data, $post_id ) {
			$transient_guid = get_transient( 'heic_support_guid_' . $post_id );
			if ( ! empty( $transient_guid ) ) {
				global $wpdb;
				$wpdb->update( $wpdb->posts, array( 'guid' => $transient_guid ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) );
				delete_transient( 'heic_support_guid_' . $post_id );
			}
			$transient = get_transient( 'heic_support_metadata_' . $post_id );
			if ( empty( $transient ) ) {
				return $data;
			}
			delete_transient( 'heic_support_metadata_' . $post_id );
			return $transient;
		}

		/**
		 * Filter callback on add_attachment. Creates a copy of .heic images
		 * uploaded to the Media Library.
		 *
		 * @param  int $post_id The ID of a new attachment.
		 * @return void
		 */
		public function create_copy( $post_id ) {
			// Is the Replace feature enabled? If so, abort the copy.
			$replace = filter_var( get_option( 'heic_support_replace' ), FILTER_VALIDATE_BOOLEAN );
			if ( $replace ) {
				// Yes. Replace is enabled. Abort.
				return;
			}

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
					$imagick->setImageFormat( self::get_format() );
					// Create a path to a copy of the image.
					$name       = basename( $file_path, '.heic' ) . '.' . self::get_extension();
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
					$copy_post_id = media_sideload_image( $upload_dir['url'] . '/' . $name, 0 /* post_parent */, get_the_title( $post_id ), 'id' );
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

		/**
		 * Returns the ImageMagick version string.
		 *
		 * @return string
		 */
		protected function imagemagick_version() {
			if ( ! class_exists( 'Imagick' ) ) {
				return '';
			}
			return Imagick::getVersion()['versionString'];
		}

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
		public function populate_meta( $metadata, $attachment_id, $context ) {
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

		/**
		 * Replaces uploaded .heic files with equivalents during uploads.
		 *
		 * @param  array $file An array of data for a single file.
		 * @return array
		 */
		public function replace( $file ) {
			// Does $file look like an uploaded file?
			if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
				return $file;
			}

			// Is this image even an heic?
			$wp_filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $wp_filetype['type'] ) || 'image/heic' !== $wp_filetype['type'] ) {
				// No.
				return $file;
			}

			// Is ImageMagick available?
			if ( ! class_exists( 'Imagick' ) ) {
				// No.
				return $file;
			}

			// Is this replace feature enabled?
			$replace = filter_var( get_option( 'heic_support_replace' ), FILTER_VALIDATE_BOOLEAN );
			if ( ! $replace ) {
				// No. The feature is not enabled.
				return $file;
			}

			$imagick = new Imagick();
			try {
				if ( $imagick->readImage( $file['tmp_name'] ) ) {
					$format = self::get_format();
					$imagick->setImageFormat( $format );
					$file['type'] = apply_filters( 'heic_support_mime', 'image/' . $format );
					$file['name'] = basename( $file['name'], '.heic' ) . '.' . self::get_extension();
					$imagick->writeImage( $file['tmp_name'] );
					$file['size'] = wp_filesize( $file['tmp_name'] );
				}
			} catch ( ImagickException $ie ) {
				// "Fatal error: Uncaught ImagickException: no decode delegate for this image format `HEIC'".
				// The version of Imagick does not support heic
				return $file;
			}

			return $file;
		}

		/**
		 * Returns a file path to the copy of the test image.
		 *
		 * @return string
		 */
		protected static function test_file_path() {
			$upload_dir = wp_upload_dir();
			return $upload_dir['path'] . DIRECTORY_SEPARATOR
				. 'heic-support-image4.' . self::get_extension();
		}

		/**
		 * Deletes the test image when the plugin is uninstalled.
		 *
		 * @return void
		 */
		public static function uninstall() {
			if ( ! is_multisite() ) {
				$path = self::test_file_path();
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
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

					$path = self::test_file_path();
					if ( file_exists( $path ) ) {
						wp_delete_file( $path );
					}

					restore_current_blog();
				}
			}
		}
	}
}
$heic_support_plugin = new Heic_Support_Plugin();
$heic_support_plugin->add_hooks();
