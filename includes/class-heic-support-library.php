<?php
/**
 * Converts HEIC and HEIF attachments already in the Media Library.
 *
 * @package HEIC_Support
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Heic_Support_Library' ) ) {
	/**
	 * Heic_Support_Library
	 */
	class Heic_Support_Library {

		const COUNT_TRANSIENT = 'heic_support_pending_count';
		const COPY_META       = '_heic_support_copy_of';

		/**
		 * Cloud client used for route checks and cloud conversions.
		 *
		 * @var Heic_Support_Cloud|null
		 */
		private $cloud;

		/**
		 * Registers hooks.
		 *
		 * @return void
		 */
		public function add_hooks() {
			add_action( 'wp_ajax_heic_support_convert', array( $this, 'ajax_convert' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
			add_action( 'wp_enqueue_media', array( $this, 'enqueue_script' ) );
			add_action( 'attachment_submitbox_misc_actions', array( $this, 'submitbox_convert_button' ) );
			add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
			add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
			add_filter( 'post_mime_types', array( $this, 'register_heic_mime_type' ) );
			add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
			add_filter( 'ajax_query_attachments_args', array( $this, 'filter_grid_query' ) );
			add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
			add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
			add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
		}

		/**
		 * Enqueues the script on the settings screen and the media list.
		 *
		 * @param  string $hook Current admin page hook suffix.
		 * @return void
		 */
		public function enqueue_admin( $hook ) {
			if ( 'post.php' === $hook ) {
				$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
				if ( $post_id && 'attachment' === get_post_type( $post_id ) ) {
					$this->enqueue_script();
				}
				return;
			}
			if ( 'upload.php' !== $hook ) {
				return;
			}
			$this->enqueue_script();
		}

		/**
		 * Enqueues the library script.
		 *
		 * @return void
		 */
		public function enqueue_script() {
			$plugin = dirname( __DIR__ ) . '/heic-support.php';
			$path   = dirname( __DIR__ ) . '/assets/heic-support-library.js';
			$ver    = file_exists( $path ) ? (string) filemtime( $path ) : false;

			wp_enqueue_script( 'heic-support-library', plugins_url( 'assets/heic-support-library.js', $plugin ), array( 'jquery' ), $ver, true );
			wp_localize_script(
				'heic-support-library',
				'heicSupportLibrary',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'heic_support_library' ),
					'replace' => self::replaces() ? 1 : 0,
					'warning' => self::replace_warning(),
					'replaced' => __( 'Replaced', 'heic-support' ),
					'viewCopy' => __( 'View converted copy', 'heic-support' ),
					'working' => __( 'Converting…', 'heic-support' ),
					'failed'  => __( 'Conversion failed.', 'heic-support' ),
				)
			);
		}

		/**
		 * Whether Replace is enabled.
		 *
		 * @return bool
		 */
		public static function replaces() {
			return (bool) filter_var( get_option( 'heic_support_replace' ), FILTER_VALIDATE_BOOLEAN );
		}

		/**
		 * Warning shown before a replace deletes the original file.
		 *
		 * @return string
		 */
		public static function replace_warning() {
			return __( 'Replace deletes the .heic files. URLs already saved in posts and pages will break.', 'heic-support' );
		}

		/**
		 * Adds a convert button to the attachment submit box on the full edit page.
		 *
		 * @param  WP_Post $post Current post.
		 * @return void
		 */
		public function submitbox_convert_button( $post ) {
			if ( 'attachment' !== get_post_type( $post )
				|| ! self::user_can_convert( $post->ID )
				|| ! self::is_pending_attachment( $post->ID )
				|| 'unavailable' === $this->route() ) {
				return;
			}
			$label   = $this->action_label();
			$replace = self::replaces();
			$ext     = Heic_Support_Plugin::get_extension();
			$title   = $replace
				? sprintf(
					/* translators: %s: file extension without leading dot, e.g. "jpg". */
					__( 'Deletes the .heic original and saves a .%s copy in its place. URLs saved in posts or pages will break. Change this at Settings → Media → HEIC Support.', 'heic-support' ),
					$ext
				)
				: sprintf(
					/* translators: %s: file extension without leading dot, e.g. "webp". */
					__( 'Keeps the .heic original and creates a .%s copy. Change the output format at Settings → Media → HEIC Support.', 'heic-support' ),
					$ext
				);
			printf(
				'<div class="misc-pub-section heic-support-submit" id="heic-support-submit-%1$d">'
				. '<button type="button" class="button heic-support-convert" data-id="%1$d" data-label="%2$s" title="%3$s">%4$s</button>'
				. '<span class="heic-support-convert-result"></span>'
				. '</div>',
				(int) $post->ID,
				esc_attr( $label ),
				esc_attr( $title ),
				esc_html( $label )
			);
		}

		/**
		 * Adds a convert button to a pending attachment in the media modal.
		 *
		 * The full edit page uses submitbox_convert_button() instead, so this
		 * section is only added in AJAX contexts (the modal).
		 *
		 * @param  array   $form_fields Existing fields.
		 * @param  WP_Post $post        Attachment.
		 * @return array
		 */
		public function attachment_field( $form_fields, $post ) {
			if ( ! wp_doing_ajax() ) {
				return $form_fields;
			}
			if ( ! self::user_can_convert( $post->ID ) || ! self::is_pending_attachment( $post->ID ) || 'unavailable' === $this->route() ) {
				return $form_fields;
			}

			$label = $this->action_label();
			$html  = '<div class="heic-support-convert-wrap">';
			$html .= '<button type="button" class="button heic-support-convert" data-id="' . esc_attr( $post->ID ) . '" data-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</button>';
			if ( self::replaces() ) {
				$html .= '<p class="description">' . esc_html( self::replace_warning() ) . '</p>';
			}
			$html .= '<p class="heic-support-convert-result"></p></div>';

			$form_fields['heic_support_convert'] = array(
				'label' => __( 'HEIC Support', 'heic-support' ),
				'input' => 'html',
				'html'  => $html,
			);
			return $form_fields;
		}

		/**
		 * Adds a convert or replace action to the media list table.
		 *
		 * @param  array   $actions Existing actions.
		 * @param  WP_Post $post    Attachment.
		 * @return array
		 */
		public function row_action( $actions, $post ) {
			if ( ! self::user_can_convert( $post->ID ) || ! self::is_pending_attachment( $post->ID ) || 'unavailable' === $this->route() ) {
				return $actions;
			}

			$ext     = Heic_Support_Plugin::get_extension();
			$replace = self::replaces();
			$text    = $this->action_label();
			$title   = $replace
				? sprintf(
					/* translators: %s: file extension without leading dot, e.g. "jpg". */
					__( 'Deletes the .heic original and saves a .%s copy in its place. URLs saved in posts or pages will break. Change this at Settings → Media → HEIC Support.', 'heic-support' ),
					$ext
				)
				: sprintf(
					/* translators: %s: file extension without leading dot, e.g. "webp". */
					__( 'Keeps the .heic original and creates a .%s copy. Change the output format at Settings → Media → HEIC Support.', 'heic-support' ),
					$ext
				);
			$actions['heic_support_convert'] = sprintf(
				'<a href="#" class="heic-support-convert" data-id="%1$d" data-row="1" data-label="%2$s" title="%3$s">%4$s</a>',
				(int) $post->ID,
				esc_attr( $text ),
				esc_attr( $title ),
				esc_html( $text )
			);
			return $actions;
		}

		/**
		 * Adds HEIC / HEIF to the Media Library filter bar.
		 *
		 * @param  array $post_mime_types Registered mime types.
		 * @return array
		 */
		public function register_heic_mime_type( $post_mime_types ) {
			$post_mime_types['image/heic'] = array(
				__( 'HEIC / HEIF', 'heic-support' ),
				__( 'Manage HEIC / HEIF', 'heic-support' ),
				/* translators: %s: number of HEIC/HEIF images */
				_n_noop( '%s HEIC / HEIF', '%s HEIC / HEIF', 'heic-support' ),
			);
			return $post_mime_types;
		}

		/**
		 * Applies the HEIC / HEIF filter to the Media Library list view.
		 *
		 * @param  WP_Query $query Query.
		 * @return void
		 */
		public function filter_list_query( $query ) {
			if ( ! is_admin() || ! $query->is_main_query() || 'image/heic' !== $query->get( 'post_mime_type' ) ) {
				return;
			}
			$vars = self::heic_query_vars(
				array(
					'post_mime_type' => $query->get( 'post_mime_type' ),
					'meta_query'     => $query->get( 'meta_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			$query->set( 'post_mime_type', $vars['post_mime_type'] );
			$query->set( 'meta_query', $vars['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		/**
		 * Applies the HEIC / HEIF filter to the Media Library grid view.
		 *
		 * @param  array $query Query vars.
		 * @return array
		 */
		public function filter_grid_query( $query ) {
			if ( empty( $query['post_mime_type'] ) || 'image/heic' !== $query['post_mime_type'] ) {
				return $query;
			}
			return self::heic_query_vars( $query );
		}

		/**
		 * Matches files still stored as .heic or .heif. WordPress 6.7+ converts
		 * HEIC uploads to JPEG but keeps post_mime_type image/heic, so the mime
		 * type alone also matches those JPEGs.
		 *
		 * @param  array $vars Query vars.
		 * @return array
		 */
		private static function heic_query_vars( array $vars ) {
			$meta = empty( $vars['meta_query'] ) ? array() : (array) $vars['meta_query'];

			$meta[] = array(
				'key'     => '_wp_attached_file',
				'value'   => '\.(heic|heif)$',
				'compare' => 'REGEXP',
			);

			$vars['post_mime_type'] = array( 'image/heic', 'image/heif' );
			$vars['meta_query']     = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			return $vars;
		}

		/**
		 * Adds the convert action to the Media Library bulk actions dropdown.
		 *
		 * @param  array $actions Existing bulk actions.
		 * @return array
		 */
		public function register_bulk_action( $actions ) {
			if ( 'unavailable' === $this->route() ) {
				return $actions;
			}
			$ext   = Heic_Support_Plugin::get_extension();
			$label = self::replaces()
				? sprintf(
					/* translators: %s: file extension without leading dot, e.g. "jpg". */
					__( 'Replace with .%s', 'heic-support' ),
					$ext
				)
				: sprintf(
					/* translators: %s: file extension without leading dot, e.g. "webp". */
					__( 'Create .%s copies', 'heic-support' ),
					$ext
				);
			if ( 'cloud' === $this->route() ) {
				$label = sprintf(
					/* translators: %s: bulk action label, e.g. "Create .webp copies". */
					__( '%s (1 credit each)', 'heic-support' ),
					$label
				);
			}
			$actions['heic_support_convert'] = $label;
			return $actions;
		}

		/**
		 * Processes selected Media Library items for HEIC conversion.
		 *
		 * @param  string $redirect_url Redirect URL after the action.
		 * @param  string $action       Bulk action key.
		 * @param  array  $post_ids     Selected attachment IDs.
		 * @return string
		 */
		public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
			if ( 'heic_support_convert' !== $action ) {
				return $redirect_url;
			}
			if ( ! current_user_can( 'upload_files' ) ) {
				return $redirect_url;
			}
			$converted = 0;
			$failed    = 0;
			foreach ( $post_ids as $post_id ) {
				if ( ! self::is_pending_attachment( (int) $post_id ) ) {
					continue;
				}
				$result = $this->convert_attachment( (int) $post_id );
				if ( is_wp_error( $result ) ) {
					$failed++;
				} else {
					$converted++;
				}
			}
			$redirect_url = remove_query_arg( array( 'heic_support_converted', 'heic_support_failed' ), $redirect_url );
			$redirect_url = add_query_arg(
				array(
					'heic_support_converted' => $converted,
					'heic_support_failed'    => $failed,
				),
				$redirect_url
			);
			return $redirect_url;
		}

		/**
		 * Shows the result of a bulk conversion as an admin notice.
		 *
		 * @return void
		 */
		public function bulk_action_notice() {
			$screen = get_current_screen();
			if ( ! $screen || 'upload' !== $screen->id || ! current_user_can( 'upload_files' ) ) {
				return;
			}
			// phpcs:disable WordPress.Security.NonceVerification
			if ( ! isset( $_GET['heic_support_converted'] ) && ! isset( $_GET['heic_support_failed'] ) ) {
				return;
			}
			$converted = isset( $_GET['heic_support_converted'] ) ? (int) $_GET['heic_support_converted'] : 0;
			$failed    = isset( $_GET['heic_support_failed'] ) ? (int) $_GET['heic_support_failed'] : 0;
			// phpcs:enable WordPress.Security.NonceVerification
			if ( $converted > 0 ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: number of images. */
							_n( 'Converted %s image.', 'Converted %s images.', $converted, 'heic-support' ),
							number_format_i18n( $converted )
						)
					)
				);
			}
			if ( $failed > 0 ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: number of conversions. */
							_n( '%s conversion failed.', '%s conversions failed.', $failed, 'heic-support' ),
							number_format_i18n( $failed )
						)
					)
				);
			}
		}

		/**
		 * Converts the attachment posted by the media library button.
		 *
		 * @return void
		 */
		public function ajax_convert() {
			check_ajax_referer( 'heic_support_library', 'nonce' );
			$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$result = $this->convert_attachment( $id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( $result );
		}

		/**
		 * Converts one pending attachment.
		 *
		 * @param  int $id Attachment ID.
		 * @return array|WP_Error
		 */
		public function convert_attachment( $id ) {
			$id = (int) $id;
			if ( ! self::user_can_convert( $id ) ) {
				return new WP_Error( 'forbidden', __( 'You cannot convert this image.', 'heic-support' ) );
			}
			if ( ! self::is_pending_attachment( $id ) ) {
				return new WP_Error( 'not_pending', __( 'This image does not need conversion.', 'heic-support' ) );
			}

			$lock = 'heic_support_lock_' . $id;
			if ( get_transient( $lock ) ) {
				return new WP_Error( 'locked', __( 'This image is already being converted.', 'heic-support' ) );
			}
			set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

			$path   = get_attached_file( $id );
			$result = $this->convert_pending( $id, $path );
			delete_transient( $lock );
			if ( ! is_wp_error( $result ) ) {
				delete_transient( self::COUNT_TRANSIENT );
			}
			return $result;
		}

		/**
		 * Whether the current user may convert this attachment. upload_files
		 * alone is not enough: Authors have it but may only edit their own media.
		 *
		 * @param  int $id Attachment ID.
		 * @return bool
		 */
		private static function user_can_convert( $id ) {
			return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $id );
		}

		/**
		 * Whether the attachment is still an unconverted HEIC or HEIF.
		 *
		 * @param  int $id Attachment ID.
		 * @return bool
		 */
		public static function is_pending_attachment( $id ) {
			$path = get_attached_file( $id );
			if ( ! is_string( $path ) || '' === $path || ! self::is_heic_path( $path ) ) {
				return false;
			}
			return ! self::has_living_copy( $id );
		}

		/**
		 * Converts the file, then copies or replaces the attachment.
		 *
		 * @param  int    $id   Attachment ID.
		 * @param  string $path Original file path.
		 * @return array|WP_Error
		 */
		private function convert_pending( $id, $path ) {
			if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
				return new WP_Error( 'missing_file', __( 'The original file is missing.', 'heic-support' ) );
			}

			$route = $this->route();
			if ( 'unavailable' === $route ) {
				return new WP_Error( 'unavailable', __( 'This server cannot convert .heic images.', 'heic-support' ) );
			}

			if ( 'cloud' === $route ) {
				$dest = $this->cloud()->convert_file_to_temp( $path );
			} else {
				$dest = $this->convert_local( $path );
			}
			if ( is_wp_error( $dest ) ) {
				return $dest;
			}

			$attached = $this->attach_converted( $id, $path, $dest );
			if ( is_wp_error( $attached ) ) {
				wp_delete_file( $dest );
			}
			return $attached;
		}

		/**
		 * Writes a converted file next to the original.
		 *
		 * @param  string $path Source path.
		 * @return string|WP_Error
		 */
		private function convert_local( $path ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return new WP_Error( 'no_imagick', __( 'ImageMagick is not available.', 'heic-support' ) );
			}

			$format = Heic_Support_Plugin::get_format();
			$dir    = dirname( $path );
			$name   = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '.' . Heic_Support_Plugin::get_extension() );
			$dest   = trailingslashit( $dir ) . $name;

			try {
				$imagick = new Imagick();
				if ( ! $imagick->readImage( $path ) ) {
					return new WP_Error( 'read_failed', __( 'Could not read the .heic image.', 'heic-support' ) );
				}
				$imagick->setImageFormat( $format );
				$imagick->writeImage( $dest );
				$imagick->clear();
			} catch ( Exception $e ) {
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
				$message = $e->getMessage();
				if ( '' === $message ) {
					$message = __( 'Conversion failed.', 'heic-support' );
				}
				return new WP_Error( 'imagick', $message );
			}

			if ( ! file_exists( $dest ) || 0 === (int) filesize( $dest ) ) {
				wp_delete_file( $dest );
				return new WP_Error( 'empty', __( 'Conversion produced an empty file.', 'heic-support' ) );
			}
			return $dest;
		}

		/**
		 * Attaches a converted file as a copy, or in place of the HEIC.
		 *
		 * @param  int    $id   Original attachment ID.
		 * @param  string $path Original file path.
		 * @param  string $dest Converted file path.
		 * @return array|WP_Error
		 */
		private function attach_converted( $id, $path, $dest ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$format = Heic_Support_Plugin::get_format();
			$mime   = apply_filters( 'heic_support_mime', 'image/' . $format );

			if ( ! self::replaces() ) {
				$copy_id = wp_insert_attachment(
					array(
						'post_mime_type' => $mime,
						'post_title'     => get_the_title( $id ),
						'post_content'   => '',
						'post_status'    => 'inherit',
					),
					$dest,
					0,
					true
				);
				if ( is_wp_error( $copy_id ) ) {
					return $copy_id;
				}
				$metadata = wp_generate_attachment_metadata( $copy_id, $dest );
				if ( is_array( $metadata ) ) {
					wp_update_attachment_metadata( $copy_id, $metadata );
				}
				update_post_meta( $copy_id, self::COPY_META, $id );
				update_post_meta( $id, self::COPY_META, $copy_id );

				$edit = get_edit_post_link( $copy_id, 'raw' );
				if ( ! $edit ) {
					$edit = wp_get_attachment_url( $copy_id );
				}
				return array(
					'mode'   => 'copy',
					'id'     => $id,
					'copyId' => (int) $copy_id,
					'editUrl' => $edit,
				);
			}

			update_attached_file( $id, $dest );
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $mime,
				)
			);
			$metadata = wp_generate_attachment_metadata( $id, $dest );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $id, $metadata );
			}

			$real_src  = realpath( $path );
			$real_dest = realpath( $dest );
			if ( $path && file_exists( $path ) && $real_src && $real_dest && $real_src !== $real_dest ) {
				wp_delete_file( $path );
			}
			clean_post_cache( $id );

			return array(
				'mode' => 'replace',
				'id'   => $id,
				'url'  => wp_get_attachment_url( $id ),
			);
		}

		/**
		 * Label for the sidebar button, modal button, and row action.
		 *
		 * @return string
		 */
		private function action_label() {
			$ext   = Heic_Support_Plugin::get_extension();
			$label = self::replaces()
				? sprintf(
					/* translators: %s: file extension, without the leading dot. */
					__( 'Replace with .%s', 'heic-support' ),
					$ext
				)
				: sprintf(
					/* translators: %s: file extension, without the leading dot. */
					__( 'Create .%s copy', 'heic-support' ),
					$ext
				);
			if ( 'cloud' === $this->route() ) {
				$label = sprintf(
					/* translators: %s: action label, e.g. "Create .webp copy". */
					__( '%s (1 credit)', 'heic-support' ),
					$label
				);
			}
			return $label;
		}

		/**
		 * local, cloud, or unavailable.
		 *
		 * @return string
		 */
		private function route() {
			return $this->cloud()->conversion_route();
		}

		/**
		 * Cloud client. Constructing it does not register upload hooks.
		 *
		 * @return Heic_Support_Cloud
		 */
		private function cloud() {
			if ( ! $this->cloud ) {
				$this->cloud = new Heic_Support_Cloud();
			}
			return $this->cloud;
		}

		/**
		 * Whether a path is a still HEIC or HEIF.
		 *
		 * @param  string $path File path.
		 * @return bool
		 */
		private static function is_heic_path( $path ) {
			$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			return in_array( $ext, array( 'heic', 'heif' ), true );
		}

		/**
		 * Whether _heic_support_copy_of points at an attachment that is still there.
		 *
		 * @param  int $id Attachment ID.
		 * @return bool
		 */
		private static function has_living_copy( $id ) {
			$copy_id = (int) get_post_meta( $id, self::COPY_META, true );
			if ( $copy_id <= 0 ) {
				return false;
			}
			$copy = get_post( $copy_id );
			if ( ! $copy || 'attachment' !== $copy->post_type ) {
				return false;
			}
			return ! in_array( $copy->post_status, array( 'trash', 'auto-draft' ), true );
		}
	}
}
