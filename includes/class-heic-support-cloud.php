<?php
/**
 * Cloud conversion client for HEIC Support.
 *
 * Additive serviceware: when local ImageMagick can't convert HEIC (or the user
 * forces it), uploads are converted by the Breakfast cloud API using purchased
 * credits. The local path in heic-support.php is untouched.
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
		const NOTICE_TRANSIENT  = 'heic_support_notice';
		const STATUS_TRANSIENT  = 'heic_support_credits_status';
		const LOCAL_WORKS_TRANSIENT = 'heic_support_local_works';

		/**
		 * Registers hooks.
		 *
		 * @return void
		 */
		public function add_hooks() {
			add_action( 'add_attachment', array( $this, 'maybe_schedule_cloud' ), 12 );
			add_action( self::EVENT, array( $this, 'cloud_convert' ), 10, 3 );
			add_action( 'admin_init', array( $this, 'register_settings' ), 11 );
			add_action( 'admin_post_heic_support_remove_license', array( $this, 'handle_remove_license' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
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
		 * Output format ('webp' or 'jpeg'), mirroring the main plugin's option.
		 *
		 * @return string
		 */
		private function format() {
			$v = get_option( 'heic_support_format' );
			if ( empty( $v ) ) {
				$v = 'webp';
			}
			$v = apply_filters( 'heic_support_format', $v );
			return 'jpeg' === $v ? 'jpeg' : 'webp';
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
			return 'webp';
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
				return (bool) $cached;
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
		 * Schedules a background cloud conversion when local can't handle the upload.
		 *
		 * @param  int $post_id Attachment ID.
		 * @return void
		 */
		public function maybe_schedule_cloud( $post_id ) {
			$file = get_attached_file( $post_id );
			if ( ! $file || 'heic' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
				return;
			}
			if ( ! $this->should_use_cloud( $post_id ) ) {
				return;
			}
			if ( ! wp_next_scheduled( self::EVENT, array( $post_id ) ) ) {
				wp_schedule_single_event( time(), self::EVENT, array( $post_id ) );
			}
		}

		/**
		 * Background event: consume -> convert -> import/replace.
		 *
		 * Transient failures (busy / unreachable) are retried with the SAME
		 * ticket, because the server only marks a ticket used once it actually
		 * converts. So, a credit isn't spent without a conversion. There is no
		 * refund endpoint; a genuine hard failure costs the credit.
		 *
		 * @param  int    $heic_id  Attachment ID.
		 * @param  string $ticket   Existing ticket to reuse on a retry.
		 * @param  int    $attempts Retry count.
		 * @return void
		 */
		public function cloud_convert( $heic_id, $ticket = '', $attempts = 0 ) {
			if ( ! $this->should_use_cloud( $heic_id ) ) {
				return;
			}
			$file = get_attached_file( $heic_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return;
			}
			$format = $this->format();

			// Consume a credit on the first attempt; reuse the ticket on retries.
			if ( '' === $ticket ) {
				$consume = $this->api_consume();
				if ( is_wp_error( $consume ) ) {
					$this->notice( $consume->get_error_message() );
					return;
				}
				$ticket = $consume['ticket'];
			}

			$dest = $this->api_convert( $file, $format, $ticket );
			if ( is_wp_error( $dest ) ) {
				// busy / unreachable did not use the ticket server-side. Retry it.
				$retryable = in_array( $dest->get_error_code(), array( 'busy', 'api_unreachable' ), true );
				if ( $retryable && $attempts < 3 ) {
					wp_schedule_single_event( time() + 120, self::EVENT, array( $heic_id, $ticket, $attempts + 1 ) );
				} else {
					$this->notice( __( 'HEIC Support cloud conversion failed for this image.', 'heic-support' ) );
				}
				return;
			}

			$replace = filter_var( get_option( 'heic_support_replace' ), FILTER_VALIDATE_BOOLEAN );
			$result  = $replace
				? $this->replace_original( $heic_id, $dest, $format )
				: $this->import_converted( $heic_id, $dest, $format );

			if ( is_wp_error( $result ) || ! $result ) {
				$this->notice( __( 'Cloud conversion succeeded, but the converted image could not be added to the Media Library.', 'heic-support' ) );
				return;
			}
			update_post_meta( $heic_id, self::META_CONVERTED, $result );
		}

		/**
		 * Copy mode: add the converted file as a new, linked attachment.
		 *
		 * @param  int    $heic_id Original HEIC attachment ID.
		 * @param  string $dest    Converted file path.
		 * @param  string $format  Format.
		 * @return int|WP_Error New attachment ID.
		 */
		private function import_converted( $heic_id, $dest, $format ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$uploads  = wp_get_upload_dir();
			$relative = ltrim( str_replace( $uploads['basedir'], '', $dest ), '/' );
			$parent   = wp_get_post_parent_id( $heic_id );

			$attach_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'image/' . $format,
					'post_title'     => get_the_title( $heic_id ),
					'post_status'    => 'inherit',
				),
				$dest,
				$parent ? $parent : 0,
				true
			);
			if ( is_wp_error( $attach_id ) || ! $attach_id ) {
				return $attach_id;
			}
			update_post_meta( $attach_id, '_wp_attached_file', $relative );
			// Let WordPress build the registered sizes locally (GD handles WebP/JPG).
			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $dest ) );

			update_post_meta( $attach_id, '_heic_support_copy_of', $heic_id );
			update_post_meta( $heic_id, '_heic_support_copy_of', $attach_id );
			return (int) $attach_id;
		}

		/**
		 * Replace mode: point the original attachment at the converted file and
		 * delete the .heic. Async copy-then-swap (not in-place during upload).
		 *
		 * @param  int    $heic_id Attachment ID.
		 * @param  string $dest    Converted file path.
		 * @param  string $format  Format.
		 * @return int|WP_Error Attachment ID.
		 */
		private function replace_original( $heic_id, $dest, $format ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$heic_file = get_attached_file( $heic_id );
			$uploads   = wp_get_upload_dir();
			$relative  = ltrim( str_replace( $uploads['basedir'], '', $dest ), '/' );

			update_post_meta( $heic_id, '_wp_attached_file', $relative );
			wp_update_post(
				array(
					'ID'             => $heic_id,
					'post_mime_type' => 'image/' . $format,
				)
			);
			wp_update_attachment_metadata( $heic_id, wp_generate_attachment_metadata( $heic_id, $dest ) );

			if ( $heic_file && file_exists( $heic_file ) && realpath( $heic_file ) !== realpath( $dest ) ) {
				wp_delete_file( $heic_file );
			}
			return (int) $heic_id;
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
			$base = basename( $file, '.heic' );
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
			// Only offer the paid cloud option to servers that can't convert HEIC
			// locally. Hosts with working ImageMagick + libheif never see it.
			if ( self::local_heic_supported() ) {
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
				echo '<input type="text" class="regular-text" name="heic_support_license_key" value="" autocomplete="off" />';
				printf( '<p class="description">%s</p>', esc_html__( 'Enter the license key from your credit purchase, then Save Changes.', 'heic-support' ) );
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
				'<label><input type="checkbox" name="heic_support_cloud_enabled" value="1" %s /> %s</label>',
				checked( $enabled, true, false ),
				esc_html__( 'Convert .heic uploads in the cloud (uses 1 credit per image).', 'heic-support' )
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
		 * Stores a one-shot admin notice (background events have no UI).
		 *
		 * @param  string $msg Message.
		 * @return void
		 */
		private function notice( $msg ) {
			set_transient( self::NOTICE_TRANSIENT, $msg, DAY_IN_SECONDS );
		}

		/**
		 * Prints and clears any pending notice.
		 *
		 * @return void
		 */
		public function maybe_show_notice() {
			$msg = get_transient( self::NOTICE_TRANSIENT );
			if ( ! $msg ) {
				return;
			}
			delete_transient( self::NOTICE_TRANSIENT );
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'HEIC Support:', 'heic-support' ),
				esc_html( $msg ),
				esc_url( admin_url( 'options-media.php' ) ),
				esc_html__( 'Cloud conversion settings', 'heic-support' )
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
