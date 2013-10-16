<?php
/**
 * Credly Badge Builder
 *
 * @package BadgeOS
 * @subpackage Credly
 * @author Credly, LLC
 * @license http://www.gnu.org/licenses/agpl.txt GNU AGPL v3.0
 * @link https://credly.com
 */

/**
 * Return link to Badge Builder.
 *
 * @since  1.3.0
 *
 * @param  integer $post_id Post ID.
 * @return string           Admin link to media manager.
 */
function badgeos_get_badge_builder_link( $args ) {
	$builder = new Credly_Badge_Builder();
	return $builder->render_badge_builder_link( $args );
}

/**
 * Add Badge Builder link to Featured Image meta box for achievement posts.
 *
 * @since  1.3.0
 *
 * @param  string  $content Meta box content.
 * @param  integer $post_id Post ID.
 * @return string           Potentially updated content.
 */
function badgeos_badge_builder_filter_thumbnail_metabox( $content, $post_id ) {

	// Add output only for achievement posts that have no thumbnails
	if ( badgeos_is_achievement( $post_id ) ) {
		if ( ! has_post_thumbnail( $post_id ) ) {
			$content .= '<p>' . badgeos_get_badge_builder_link( array( 'link_text' => __( 'Use Credly Badge Builder', 'badgeos' ) ) ) . '</p>';
		} else {
			$attachment_id = get_post_thumbnail_id( $post_id );
			$continue = get_post_meta( $attachment_id, '_credly_badge_meta', true );
			$content .= '<p>' . badgeos_get_badge_builder_link( array( 'link_text' => __( 'Edit in Credly Badge Builder', 'badgeos' ), 'continue' => $continue ) ) . '</p>';
		}

	}

	// Return the meta box content
	return $content;

}
add_filter( 'admin_post_thumbnail_html', 'badgeos_badge_builder_filter_thumbnail_metabox', 10, 2 );


class Credly_Badge_Builder {

	/**
	 * SDK URL for accessing badge builder.
	 * @var string
	 */
	public $sdk_url = 'https://credly.com/badge-builder/';

	/**
	 * Temp token used for running badge builder.
	 * @var string
	 */
	public $temp_token = '';

	/**
	 * Badge Attacment ID.
	 * @var integer
	 */
	public $attachment_id = 0;

	/**
	 * Instantiate the badge builder.
	 *
	 * @since 1.3.0
	 *
	 */
	public function __construct( $args = array() ) {

		// Setup any passed args
		$this->attachment_id = isset( $args['attachment_id'] ) ? $args['attachment_id'] : null;

		// Fetch our temp token
		$this->temp_token    = $this->fetch_temp_token();

		add_action( 'wp_ajax_credly-save-badge', array( $this, 'ajax_save_badge' ) );

	}

	/**
	 * Fetch the temp badge builder token.
	 *
	 * @since  1.3.0
	 */
	public function fetch_temp_token() {

		// If we have a valid Credly API key
		if ( $credly_api_key = credly_get_api_key() ) {

			// Trade the key for a temp token
			$response = wp_remote_post(
				trailingslashit( $this->sdk_url ) . 'code',
				array(
					'body' => array(
						'access_token' => $credly_api_key
					)
				)
			);

			// If the response was not an error
			if ( ! is_wp_error( $response ) ) {
				// Decode the response
				$response_body = json_decode( $response['body'] );

				// If response was successful, return the temp token
				if ( isset( $response_body->temp_token ) )
					return $response_body->temp_token;
			}
		}

		// If we made it here, we couldn't retrieve a token
		return false;
	}

	/**
	 * Render the badge builder.
	 *
	 * @since  1.3.0
	 *
	 * @param  integer $width  Output width.
	 * @param  integer $height Output height.
	 * @return string          Concatenated markup for badge builder.
	 */
	public function render_badge_builder_link( $args = array() ) {

		if ( empty( $this->temp_token ) )
			return false;

		// Setup and parse our default args
		$defaults = array(
			'width'     => '960',
			'height'    => '540',
			'continue'  => null,
			'link_text' => __( 'Use Credly Badge Builder', 'badgeos' ),
		);
		$args = wp_parse_args( $args, $defaults );

		// Build our embed url
		$embed_url = add_query_arg(
			array(
				'continue'  => rawurlencode( json_encode( $args['continue'] ) ),
				'TB_iframe' => 'true',
				'width'     => $args['width'],
				'height'    => $args['height'],
			),
			trailingslashit( $this->sdk_url ) . 'embed/' . $this->temp_token
		);

		$output = '<a href="' . esc_url( $embed_url ) . '" class="thickbox badge-builder-link" data-width="' . $args['width'] . '" data-height="' . $args['height'] . '">' . $args['link_text'] . '</a>';
		add_thickbox();
		return apply_filters( 'credly_render_badge_builder', $output, $embed_url, $args['width'], $args['height'] );
	}

	/**
	 * Save badge builder data via AJAX.
	 *
	 * @since  1.3.0
	 */
	public function ajax_save_badge() {

		// If this wasn't an ajax call from admin, bail
		if ( ! defined( 'DOING_AJAX' ) || ! defined( 'WP_ADMIN' ) )
			wp_send_json_error();

		// Grab all our data
		$post_id    = $_REQUEST['post_id'];
		$image      = $_REQUEST['image'];
		$icon_meta  = $_REQUEST['icon_meta'];
		$badge_meta = $_REQUEST['all_data'];

		// Upload the image
		$this->attachment_id = $this->media_sideload_image( $image, $post_id );

		// Set as featured image
		set_post_thumbnail( $post_id, $this->attachment_id, __( 'Badge created with Credly Badge Builder', 'badgeos' ) );

		// Store badge builder meta
		$this->update_badge_meta( $this->attachment_id, $badge_meta, $icon_meta );

		// Build new markup for the featured image metabox
		$metabox_html = _wp_post_thumbnail_html( $this->attachment_id, $post_id );

		// Return our success response
		wp_send_json_success( array( 'attachment_id' => $this->attachment_id, 'metabox_html' => $metabox_html ) );

	}

	/**
	 * Upload an image and return the attachment ID.
	 *
	 * Ripped from wp-admin/includes/media.php because
	 * core's version returns a full <img> string rather
	 * than the attachment ID, or an array of data, or any
	 * other useful thing. Yeah, I don't get it either.
	 *
	 * @since  1.3.0
	 *
	 * @param  string  $file    Image URI.
	 * @param  integer $post_id Post ID.
	 * @param  string  $desc    Image description.
	 * @return integer          Attachment ID.
	 */
	function media_sideload_image( $file, $post_id, $desc = null ) {
		if ( ! empty( $file ) ) {
			// Download file to temp location
			$tmp = download_url( $file );

			// Set variables for storage
			// fix file filename for query strings
			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
			$file_array['name'] = basename($matches[0]);
			$file_array['tmp_name'] = $tmp;

			// If error storing temporarily, unlink
			if ( is_wp_error( $tmp ) ) {
				@unlink($file_array['tmp_name']);
				$file_array['tmp_name'] = '';
			}

			// do the validation and storage stuff
			$id = media_handle_sideload( $file_array, $post_id, $desc );

			// If error storing permanently, unlink
			if ( is_wp_error($id) ) {
				@unlink($file_array['tmp_name']);
			}

			// Send back the attachment ID
			return $id;
		}
	}

	/**
	 * Update badge attachment meta
	 *
	 * @since 1.3.0
	 *
	 * @param integer $attachment_id Attachment ID.
	 * @param string  $badge_meta    Badge Builder Meta.
	 * @param string  $icon_meta     Badge Icon Meta.
	 */
	public function update_badge_meta( $attachment_id = 0, $badge_meta = null, $icon_meta = null ) {

		// Bail if no attachment ID
		if ( ! $attachment_id )
			return;

		if ( ! empty( $badge_meta ) )
			update_post_meta( $attachment_id, '_credly_badge_meta', $badge_meta );

		if ( ! empty( $icon_meta ) )
			update_post_meta( $attachment_id, '_credly_icon_meta', $icon_meta );
	}

}
