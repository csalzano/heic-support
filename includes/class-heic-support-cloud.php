<?php
/**
 * Cloud conversion client for HEIC Support.
 *
 * Additive serviceware: when local ImageMagick can't convert HEIC (or the user
 * forces it), uploads are converted by the Breakfast cloud API using purchased
 * credits. Conversion runs inside the upload request so the attachment the
 * media modal receives is already a browser-displayable image. The local path
 * in heic-support.php is untouched.
 *
 * @package HEIC_Support
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Heic_Support_Cloud' ) ) {
	/**
	 * Heic_Support_Cloud
	 */
	class Heic_Support_Cloud {

		const EVENT             = 'heic_support_cloud_convert';
		const META_CONVERTED    = '_heic_support_cloud_converted';
		const META_ORIGINAL     = '_heic_support_original_file';
		const STATUS_TRANSIENT  = 'heic_support_credits_status';
		const LOCAL_WORKS_TRANSIENT = 'heic_support_local_works';

		/**
		 * HEIC attachment IDs that still need a cloud conversion this request.
		 * Inline conversion clears an ID. Anything left at shutdown is scheduled
		 * the old way, for uploads that never generate attachment metadata.
		 *
		 * @var array<int, bool>
		 */
		private $pending = array();

		/**
		 * True while a cloud conversion is installing its file, so metadata
		 * generation for that file does not start another conversion.
		 *
		 * @var bool
		 */
		private $converting = false;

		/**
		 * Registers hooks.
		 *
		 * @return void
		 */
		public function add_hooks() {
			add_action( 'add_attachment', array( $this, 'maybe_schedule_cloud' ), 12 );
			// After core and the local converter have had a chance to write a JPEG.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_during_upload' ), 20, 2 );
			add_action( 'shutdown', array( $this, 'schedule_pending' ) );
			add_action( 'delete_attachment', array( $this, 'delete_preserved_original' ) );
			add_action( self::EVENT, array( $this, 'cloud_convert' ), 10, 3 );
			add_action( 'admin_init', array( $this, 'register_settings' ), 11 );
			add_action( 'admin_post_heic_support_remove_license', array( $this, 'handle_remove_license' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_uploader_notice' ) );
		}

		/**
		 * Whether cloud conversion is set up (license saved and enabled).
		 *
		 * @return bool
		 */
		private function cloud_active() {
			return '' !== self::license_key()
				&& filter_var( get_option( 'heic_support_cloud_enabled' ), FILTER_VALIDATE_BOOLEAN );
		}

		/**
		 * Enqueues the uploader script that warns, inline and immediately, when a
		 * .heic is added on a server that can't convert it and has no cloud set up.
		 *
		 * @param  string $hook Current admin page hook suffix.
		 * @return void
		 */
		public function enqueue_uploader_notice( $hook ) {
			$screens = array( 'media-new.php', 'upload.php', 'post.php', 'post-new.php' );
			if ( ! in_array( $hook, $screens, true ) ) {
				return;
			}
			// Only servers that can't convert locally and haven't set up cloud.
			if ( self::local_heic_supported() || $this->cloud_active() ) {
				return;
			}

			$src  = plugins_url( 'assets/heic-support-uploader.js', dirname( __DIR__ ) . '/heic-support.php' );
			$path = dirname( __DIR__ ) . '/assets/heic-support-uploader.js';
			$ver  = file_exists( $path ) ? (string) filemtime( $path ) : false;

			wp_enqueue_script( 'heic-support-uploader', $src, array( 'jquery', 'plupload' ), $ver, true );
			wp_localize_script(
				'heic-support-uploader',
				'heicSupportUploader',
				array(
					'title'    => __( 'HEIC Support:', 'heic-support' ),
					'message'  => __( "This server can't convert .heic images, so this upload won't display in most browsers. You can convert automatically in the cloud on any host.", 'heic-support' ),
					'linkText' => __( 'Learn more at Settings → Media', 'heic-support' ),
					'url'      => admin_url( 'options-media.php' ),
					'dismiss'  => __( 'Dismiss this notice.', 'heic-support' ),
				)
			);
		}

		/* --------------------------------------------------------------------- */
		/* Config helpers                                                        */
		/* --------------------------------------------------------------------- */

		/**
		 * The license key (constant overrides the option).
		 *
		 * @return string
		 */
		public static function license_key() {
			if ( defined( 'HEIC_SUPPORT_LICENSE_KEY' ) && HEIC_SUPPORT_LICENSE_KEY ) {
				return (string) HEIC_SUPPORT_LICENSE_KEY;
			}
			return (string) get_option( 'heic_support_license_key', '' );
		}

		/**
		 * Site identifier sent to the store/API.
		 *
		 * @return string
		 */
		private function site() {
			return home_url();
		}

		/**
		 * Output format ('webp', 'avif', or 'jpeg'), mirroring the main plugin's option.
		 *
		 * @return string
		 */
		private function format() {
			$v = get_option( 'heic_support_format' );
			if ( ! in_array( $v, array( 'webp', 'jpeg', 'avif' ), true ) ) {
				$v = 'webp';
			}
			$v = apply_filters( 'heic_support_format', $v );
			return in_array( $v, array( 'webp', 'jpeg', 'avif' ), true ) ? $v : 'webp';
		}

		/**
		 * File extension for a format.
		 *
		 * @param  string $format Format.
		 * @return string
		 */
		private function extension( $format ) {
			if ( 'jpeg' === $format ) {
				return apply_filters( 'heic_support_extension', 'jpg' );
			}
			return in_array( $format, array( 'avif', 'webp' ), true ) ? $format : 'webp';
		}

		/**
		 * Caches whether local HEIC conversion works (24 hours).
		 *
		 * @param  bool $works Whether local ImageMagick can decode HEIC.
		 * @return void
		 */
		public static function cache_local_heic_supported( $works ) {
			set_transient( self::LOCAL_WORKS_TRANSIENT, $works ? '1' : '0', DAY_IN_SECONDS );
		}

		/**
		 * Whether local ImageMagick can decode HEIC here (cached 24 hours).
		 *
		 * @return bool
		 */
		public static function local_heic_supported() {
			$cached = get_transient( self::LOCAL_WORKS_TRANSIENT );
			if ( false !== $cached ) {
				// Stored as '1' or '0'. A plain (bool) cast treats '0' as true.
				return in_array( $cached, array( '1', 1, true ), true );
			}
			delete_option( self::LOCAL_WORKS_TRANSIENT );

			$works = false;
			if ( class_exists( 'Imagick' ) ) {
				try {
					$im    = new Imagick();
					$works = (bool) $im->readImage( dirname( __DIR__ ) . '/image4.heic' );
				} catch ( Exception $e ) {
					$works = false;
				}
			}
			self::cache_local_heic_supported( $works );
			return $works;
		}

		/**
		 * Whether a given HEIC should be converted via the cloud.
		 *
		 * @param  int $heic_id Attachment ID.
		 * @return bool
		 */
		private function should_use_cloud( $heic_id ) {
			if ( '' === self::license_key() ) {
				return false;
			}
			if ( ! filter_var( get_option( 'heic_support_cloud_enabled' ), FILTER_VALIDATE_BOOLEAN ) ) {
				return false;
			}
			$forced = filter_var( get_option( 'heic_support_cloud_force' ), FILTER_VALIDATE_BOOLEAN );
			if ( self::local_heic_supported() && ! $forced ) {
				return false;
			}
			if ( get_post_meta( $heic_id, self::META_CONVERTED, true ) ) {
				return false;
			}
			return true;
		}

		/* --------------------------------------------------------------------- */
		/* Scheduling + conversion                                               */
		/* --------------------------------------------------------------------- */

		/**
		 * Remembers a HEIC that needs cloud conversion.
		 *
		 * The conversion itself runs in convert_during_upload(), before WordPress
		 * answers the upload request. Scheduling here would leave Dokan's cropper
		 * (and any other media modal) holding the .heic URL until a later request.
		 *
		 * @param  int $post_id Attachment ID.
		 * @return void
		 */
		public function maybe_schedule_cloud( $post_id ) {
			$file = get_attached_file( $post_id );
			if ( ! $file || 'heic' !== strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
				return;
			}
			if ( ! $this->should_use_cloud( $post_id ) ) {
				return;
			}
			$this->pending[ (int) $post_id ] = true;
		}

		/**
		 * Converts a just-uploaded HEIC before the upload response is built.
		 *
		 * WordPress calls this while media_handle_upload() (and the REST media
		 * endpoint) are still building the attachment. Dokan's product photo
		 * modal then reads that attachment's URL into the cropper. The URL has
		 * to be a JPEG, WebP, or AVIF already; a .heic paints as a blank thumb
		 * and a white crop surface in browsers that cannot decode HEIC.
		 *
		 * @param  array $metadata      Attachment metadata. Empty when the server cannot read the HEIC.
		 * @param  int   $attachment_id Attachment ID.
		 * @return array
		 */
		public function convert_during_upload( $metadata, $attachment_id ) {
			if ( $this->converting ) {
				return $metadata;
			}
			$attachment_id = (int) $attachment_id;
			$file          = get_attached_file( $attachment_id );
			$extension     = strtolower( (string) pathinfo( (string) $file, PATHINFO_EXTENSION ) );
			$is_heic       = in_array( $extension, array( 'heic', 'heif' ), true );

			if ( ! $is_heic || $this->metadata_is_displayable( $metadata ) || ! $this->should_use_cloud( $attachment_id ) ) {
				unset( $this->pending[ $attachment_id ] );
				return $metadata;
			}

			unset( $this->pending[ $attachment_id ] );
			$converted = $this->cloud_convert( $attachment_id );
			if ( is_array( $converted ) && ! empty( $converted['file'] ) ) {
				return $converted;
			}
			return $metadata;
		}

		/**
		 * Schedules conversions that never reached convert_during_upload().
		 *
		 * @return void
		 */
		public function schedule_pending() {
			foreach ( array_keys( $this->pending ) as $post_id ) {
				if ( ! wp_next_scheduled( self::EVENT, array( $post_id ) ) ) {
					wp_schedule_single_event( time(), self::EVENT, array( $post_id ) );
				}
			}
			$this->pending = array();
		}

		/**
		 * Whether metadata already points at a browser-displayable image.
		 *
		 * Core does this itself when ImageMagick can decode HEIC: the attached
		 * file becomes a JPEG (often "-scaled.jpg") before this filter runs.
		 *
		 * @param  mixed $metadata Attachment metadata.
		 * @return bool
		 */
		private function metadata_is_displayable( $metadata ) {
			if ( ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['file'] ) ) {
				return false;
			}
			$extension = strtolower( (string) pathinfo( $metadata['file'], PATHINFO_EXTENSION ) );
			return ! in_array( $extension, array( 'heic', 'heif' ), true );
		}

		/**
		 * Consumes a credit, converts, and points the same attachment at the result.
		 *
		 * Transient failures (busy / unreachable) are retried with the SAME
		 * ticket, because the server only marks a ticket used once it actually
		 * converts. A credit isn't spent without a conversion. There is no
		 * refund endpoint; a genuine hard failure costs the credit.
		 *
		 * @param  int    $heic_id  Attachment ID.
		 * @param  string $ticket   Existing ticket to reuse on a retry.
		 * @param  int    $attempts Retry count.
		 * @return array|null Attachment metadata for the converted file, or null when nothing was installed.
		 */
		public function cloud_convert( $heic_id, $ticket = '', $attempts = 0 ) {
			if ( $this->converting ) {
				return null;
			}
			$this->converting = true;
			$metadata         = $this->perform_cloud_convert( $heic_id, $ticket, $attempts );
			$this->converting = false;
			return $metadata;
		}

		/**
		 * Body of cloud_convert(), split so the re-entry guard always clears.
		 *
		 * @param  int    $heic_id  Attachment ID.
		 * @param  string $ticket   Existing ticket to reuse on a retry.
		 * @param  int    $attempts Retry count.
		 * @return array|null
		 */
		private function perform_cloud_convert( $heic_id, $ticket, $attempts ) {
			if ( ! $this->should_use_cloud( $heic_id ) ) {
				return null;
			}
			$file = get_attached_file( $heic_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return null;
			}
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 180 );
			}
			$format = $this->format();

			// Consume a credit on the first attempt; reuse the ticket on retries.
			if ( '' === $ticket ) {
				$consume = $this->api_consume();
				if ( is_wp_error( $consume ) ) {
					return null;
				}
				$ticket = $consume['ticket'];
			}

			$dest = $this->api_convert( $file, $format, $ticket );
			if ( is_wp_error( $dest ) ) {
				// busy / unreachable did not use the ticket server-side. Retry it.
				$retryable = in_array( $dest->get_error_code(), array( 'busy', 'api_unreachable' ), true );
				if ( $retryable && $attempts < 3 ) {
					wp_schedule_single_event( time() + 120, self::EVENT, array( $heic_id, $ticket, $attempts + 1 ) );
				}
				return null;
			}

			$metadata = $this->install_converted( $heic_id, $dest, $format, $file );
			if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
				return null;
			}
			update_post_meta( $heic_id, self::META_CONVERTED, $heic_id );
			wp_clear_scheduled_hook( self::EVENT, array( $heic_id ) );
			return $metadata;
		}

		/**
		 * Points the uploaded attachment at the converted file and builds sizes.
		 *
		 * The media modal is already bound to this attachment ID. A second
		 * library item, created later by cron, is what vendors had to go back
		 * and select. Keeping the ID stable makes Select and Crop load the
		 * converted image on the first open.
		 *
		 * Replace mode deletes the .heic. Otherwise the original file stays on
		 * disk, recorded in meta, and is removed with the attachment.
		 *
		 * @param  int    $heic_id   Attachment ID.
		 * @param  string $dest      Converted file path.
		 * @param  string $format    Format (jpeg, webp, or avif).
		 * @param  string $heic_file Original HEIC path, captured before the swap.
		 * @return array|null Metadata, or null when the converted file is missing.
		 */
		private function install_converted( $heic_id, $dest, $format, $heic_file ) {
			if ( ! file_exists( $dest ) ) {
				return null;
			}
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$replace = filter_var( get_option( 'heic_support_replace' ), FILTER_VALIDATE_BOOLEAN );

			update_attached_file( $heic_id, $dest );
			wp_update_post(
				array(
					'ID'             => $heic_id,
					'post_mime_type' => 'image/' . $format,
				)
			);

			if ( ! $replace && $heic_file && file_exists( $heic_file ) && realpath( $heic_file ) !== realpath( $dest ) ) {
				update_post_meta( $heic_id, self::META_ORIGINAL, _wp_relative_upload_path( $heic_file ) );
			}

			$metadata = wp_create_image_subsizes( $dest, $heic_id );
			if ( ! is_array( $metadata ) || empty( $metadata['width'] ) ) {
				$size     = wp_getimagesize( $dest );
				$metadata = array(
					'file'     => _wp_relative_upload_path( $dest ),
					'width'    => $size ? (int) $size[0] : 0,
					'height'   => $size ? (int) $size[1] : 0,
					'filesize' => wp_filesize( $dest ),
					'sizes'    => array(),
				);
				wp_update_attachment_metadata( $heic_id, $metadata );
			}

			if ( $replace && $heic_file && file_exists( $heic_file ) && realpath( $heic_file ) !== realpath( $dest ) ) {
				wp_delete_file( $heic_file );
			}
			clean_post_cache( $heic_id );
			return $metadata;
		}

		/**
		 * Deletes a preserved .heic when its converted attachment is deleted.
		 *
		 * @param  int $post_id Attachment ID.
		 * @return void
		 */
		public function delete_preserved_original( $post_id ) {
			$relative = get_post_meta( $post_id, self::META_ORIGINAL, true );
			if ( ! is_string( $relative ) || '' === $relative ) {
				return;
			}
			$uploads   = wp_get_upload_dir();
			$real_base = realpath( $uploads['basedir'] );
			$real_path = realpath( path_join( $uploads['basedir'], $relative ) );
			if ( ! $real_base || ! $real_path ) {
				return;
			}
			$real_base = wp_normalize_path( trailingslashit( $real_base ) );
			$real_path = wp_normalize_path( $real_path );
			if ( 0 !== strpos( $real_path, $real_base ) ) {
				return;
			}
			$extension = strtolower( (string) pathinfo( $real_path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'heic', 'heif' ), true ) ) {
				return;
			}
			wp_delete_file( $real_path );
		}

		/* --------------------------------------------------------------------- */
		/* API client                                                            */
		/* --------------------------------------------------------------------- */

		/**
		 * Consumes one credit; returns ['ticket'=>..,'remaining'=>..] or WP_Error.
		 *
		 * @return array|WP_Error
		 */
		private function api_consume() {
			$resp = wp_remote_post(
				trailingslashit( HEIC_SUPPORT_STORE_URL ) . 'wp-json/heic-support/v1/credits/consume',
				array(
					'timeout' => 20,
					'body'    => array(
						'license' => self::license_key(),
						'site'    => $this->site(),
						'amount'  => 1,
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return new WP_Error( 'store_unreachable', __( 'Could not reach the cloud conversion server.', 'heic-support' ) );
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );

			if ( 402 === $code ) {
				return new WP_Error( 'no_credits', __( 'Out of credits. Buy more to keep converting.', 'heic-support' ) );
			}
			if ( 401 === $code ) {
				return new WP_Error( 'invalid_license', __( 'Your license key is invalid.', 'heic-support' ) );
			}
			if ( 200 !== $code || empty( $body['ticket'] ) ) {
				return new WP_Error( 'consume_failed', __( 'Could not start cloud conversion.', 'heic-support' ) );
			}
			if ( isset( $body['remaining'] ) ) {
				$this->cache_status( $body['remaining'] > 0 ? 'active' : 'empty', (int) $body['remaining'] );
			}
			return array(
				'ticket'    => (string) $body['ticket'],
				'remaining' => (int) ( $body['remaining'] ?? 0 ),
			);
		}

		/**
		 * Sends the HEIC to the conversion API, streaming the result to $dest.
		 *
		 * @param  string $file   Source HEIC path.
		 * @param  string $format Format.
		 * @param  string $ticket Signed ticket.
		 * @return string|WP_Error Destination path on success.
		 */
		private function api_convert( $file, $format, $ticket ) {
			$dir  = dirname( $file );
			$ext  = $this->extension( $format );
			$base = pathinfo( $file, PATHINFO_FILENAME );
			$name = wp_unique_filename( $dir, "{$base}.{$ext}" );
			$dest = trailingslashit( $dir ) . $name;

			$boundary = wp_generate_password( 24, false );
			$body     = $this->multipart_body(
				$boundary,
				array(
					'ticket' => $ticket,
					'format' => $format,
				),
				'file',
				$file,
				basename( $file ),
				'image/heic'
			);

			$resp = wp_remote_post(
				trailingslashit( HEIC_SUPPORT_REMOTE_API_URL ) . 'convert',
				array(
					'timeout'  => 60,
					'headers'  => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
					'body'     => $body,
					'stream'   => true,
					'filename' => $dest,
				)
			);

			if ( is_wp_error( $resp ) ) {
				$this->cleanup( $dest );
				return new WP_Error( 'api_unreachable', __( 'Could not reach the conversion server.', 'heic-support' ) );
			}
			$code = wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				$this->cleanup( $dest );
				if ( 503 === $code ) {
					return new WP_Error( 'busy', 'busy' );
				}
				return new WP_Error( 'convert_failed', 'convert_failed_' . $code );
			}
			if ( ! file_exists( $dest ) || 0 === filesize( $dest ) ) {
				$this->cleanup( $dest );
				return new WP_Error( 'convert_empty', 'empty_output' );
			}
			return $dest;
		}

		/**
		 * Builds a multipart/form-data body with one file part.
		 *
		 * @param  string $boundary   Boundary.
		 * @param  array  $fields     Simple field => value pairs.
		 * @param  string $file_field File field name.
		 * @param  string $file_path  File path.
		 * @param  string $file_name  File name.
		 * @param  string $file_type  MIME type.
		 * @return string
		 */
		private function multipart_body( $boundary, array $fields, $file_field, $file_path, $file_name, $file_type ) {
			$eol  = "\r\n";
			$body = '';
			foreach ( $fields as $name => $value ) {
				$body .= '--' . $boundary . $eol;
				$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
				$body .= $value . $eol;
			}
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $file_name . '"' . $eol;
			$body .= 'Content-Type: ' . $file_type . $eol . $eol;
			$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$body .= '--' . $boundary . '--' . $eol;
			return $body;
		}

		/**
		 * Deletes a temp file if present.
		 *
		 * @param  string $path Path.
		 * @return void
		 */
		private function cleanup( $path ) {
			if ( $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		/* --------------------------------------------------------------------- */
		/* Settings UI                                                           */
		/* --------------------------------------------------------------------- */

		/**
		 * Registers cloud settings + fields under the existing HEIC Support section.
		 *
		 * @return void
		 */
		public function register_settings() {
			// Hosts that can convert HEIC locally never see the paid option, unless
			// a key or the force flag is already stored (so it can be managed).
			$forced = filter_var( get_option( 'heic_support_cloud_force' ), FILTER_VALIDATE_BOOLEAN );
			if ( self::local_heic_supported() && '' === self::license_key() && ! $forced ) {
				return;
			}

			register_setting(
				'media',
				'heic_support_license_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_license_key' ),
				)
			);
			register_setting(
				'media',
				'heic_support_cloud_enabled',
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			add_settings_field(
				'heic_cloud_license',
				__( 'License Key', 'heic-support' ),
				array( $this, 'field_license' ),
				'media',
				'heic_support_section'
			);
			add_settings_field(
				'heic_cloud_enable',
				__( 'Cloud Conversion', 'heic-support' ),
				array( $this, 'field_enable' ),
				'media',
				'heic_support_section'
			);
		}

		/**
		 * Sanitizes the license key on save and busts the status cache.
		 *
		 * @param  string $value Submitted value.
		 * @return string
		 */
		public function sanitize_license_key( $value ) {
			delete_transient( self::STATUS_TRANSIENT );
			return sanitize_text_field( (string) $value );
		}

		/**
		 * Renders the license-key field (sample-plugin style: masked + status + remove).
		 *
		 * @return void
		 */
		public function field_license() {
			$constant = defined( 'HEIC_SUPPORT_LICENSE_KEY' ) && HEIC_SUPPORT_LICENSE_KEY;
			$key      = self::license_key();

			if ( '' === $key ) {
				echo '<input type="text" class="regular-text" name="heic_support_license_key" value="" autocomplete="off" placeholder="' . esc_attr__( 'Paste your license key', 'heic-support' ) . '" />';
				echo '<p class="description">';
				printf(
					/* translators: 1. Anchor opening tag. 2. Anchor closing tag. */
					esc_html__( 'Already bought credits? Enter your license key and click Save Changes. Need credits? %1$sGet a pack of conversion credits%2$s. One credit converts one image, starting at 3 conversions for $5.99.', 'heic-support' ),
					'<a href="' . esc_url( trailingslashit( HEIC_SUPPORT_STORE_URL ) . 'heic-support/' ) . '" target="_blank" rel="noopener"><strong>',
					'</strong></a>'
				);
				echo '</p>';
				return;
			}

			$info   = $this->status();
			$status = $info['status'];

			printf(
				'<input type="text" class="regular-text code" style="letter-spacing:2px" disabled="disabled" value="%s" /> ',
				esc_attr( $this->obfuscate( $key ) )
			);
			printf(
				'<span class="heic-support-license-status heic-support-license-status-%s">%s</span>',
				esc_attr( $status ),
				esc_html( ucfirst( $status ) )
			);

			if ( $constant ) {
				printf( '<p class="description">%s</p>', esc_html__( 'Set via the HEIC_SUPPORT_LICENSE_KEY constant in wp-config.php.', 'heic-support' ) );
			} else {
				printf( '<input type="hidden" name="heic_support_license_key" value="%s" />', esc_attr( $key ) );
				$remove = wp_nonce_url( admin_url( 'admin-post.php?action=heic_support_remove_license' ), 'heic_support_remove_license' );
				printf( ' <a href="%s" class="button button-secondary">%s</a>', esc_url( $remove ), esc_html__( 'Remove License Key', 'heic-support' ) );
			}

			echo '<p class="description">';
			printf(
				/* translators: %s: number of credits remaining. */
				esc_html__( 'Credits remaining: %s.', 'heic-support' ),
				'<strong>' . esc_html( number_format_i18n( $info['remaining'] ) ) . '</strong>'
			);
			printf(
				' <a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( trailingslashit( HEIC_SUPPORT_STORE_URL ) . 'heic-support/' ),
				esc_html__( 'Buy more credits', 'heic-support' )
			);
			echo '</p>';
		}

		/**
		 * Renders the enable cloud checkbox.
		 *
		 * @return void
		 */
		public function field_enable() {
			$enabled = filter_var( get_option( 'heic_support_cloud_enabled' ), FILTER_VALIDATE_BOOLEAN );
			printf(
				'<label><input type="checkbox" name="heic_support_cloud_enabled" value="1" %s /> %s</label><p class="description">%s</p>',
				checked( $enabled, true, false ),
				esc_html__( 'Automatically convert every new .heic upload in the cloud (uses 1 credit per image).', 'heic-support' ),
				esc_html__( 'Turn this on after you have saved a license key with available credits.', 'heic-support' )
			);
		}

		/**
		 * Handles the Remove License Key action.
		 *
		 * @return void
		 */
		public function handle_remove_license() {
			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'heic_support_remove_license' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'heic-support' ) );
			}
			delete_option( 'heic_support_license_key' );
			delete_transient( self::STATUS_TRANSIENT );
			wp_safe_redirect( admin_url( 'options-media.php' ) );
			exit;
		}

		/* --------------------------------------------------------------------- */
		/* Status cache + notices                                                */
		/* --------------------------------------------------------------------- */

		/**
		 * License/credit status, cached ~10 min, from GET /credits.
		 *
		 * @return array{status:string,remaining:int}
		 */
		private function status() {
			$cached = get_transient( self::STATUS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$info = array(
				'status'    => 'invalid',
				'remaining' => 0,
			);
			$key = self::license_key();
			if ( '' !== $key ) {
				$resp = wp_remote_get(
					add_query_arg(
						array(
							'license' => rawurlencode( $key ),
							'site'    => rawurlencode( $this->site() ),
						),
						trailingslashit( HEIC_SUPPORT_STORE_URL ) . 'wp-json/heic-support/v1/credits'
					)
				);
				if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
					$body = json_decode( wp_remote_retrieve_body( $resp ), true );
					if ( is_array( $body ) ) {
						$info['status']    = isset( $body['status'] ) ? (string) $body['status'] : 'invalid';
						$info['remaining'] = isset( $body['remaining'] ) ? (int) $body['remaining'] : 0;
					}
				}
			}
			set_transient( self::STATUS_TRANSIENT, $info, 10 * MINUTE_IN_SECONDS );
			return $info;
		}

		/**
		 * Updates the cached status (e.g. after a consume returns remaining).
		 *
		 * @param  string $status    Status.
		 * @param  int    $remaining Remaining credits.
		 * @return void
		 */
		private function cache_status( $status, $remaining ) {
			set_transient(
				self::STATUS_TRANSIENT,
				array(
					'status'    => $status,
					'remaining' => (int) $remaining,
				),
				10 * MINUTE_IN_SECONDS
			);
		}

		/**
		 * Masks all but the last 4 characters of a key.
		 *
		 * @param  string $key Key.
		 * @return string
		 */
		private function obfuscate( $key ) {
			$len = strlen( $key );
			if ( $len <= 4 ) {
				return str_repeat( '*', $len );
			}
			return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
		}
	}
}
