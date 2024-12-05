<?php
/**
 * Plugin Name: WP Facebook Auto Publish
 * Plugin URI: https://github.com/MantasDainys/wp-facebook-auto-publish
 * GitHub Plugin URI: https://github.com/MantasDainys/wp-facebook-auto-publish
 * GitHub Branch: main
 * Description: Automatically publish posts to Facebook posts
 * Version: 1.0.2
 * Author: Mantas Dainys
 * Text Domain: wp-facebook-auto-publish
 * Domain Path: /languages
 * @package wp-facebook-auto-publish
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

require_once __DIR__ . '/vendor/autoload.php';

use Facebook\Facebook;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;

register_activation_hook( __FILE__, array( 'WPFacebookAutoPublish', 'facebook_options' ) );

/**
 * @todo Remember me
 */
class WPFacebookAutoPublish {

	private $appId;
	private $appSecret;
	private $pageAccessToken;
	private $pageId;

	public static function facebook_options() {
		add_option( 'wp_facebook_auto_publish_app_id', '' );
		add_option( 'wp_facebook_auto_publish_app_secret', '' );
		add_option( 'wp_facebook_auto_publish_page_access_token', '' );
		add_option( 'wp_facebook_auto_publish_page_id', '' );
	}

	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'add_facebook_publish_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_facebook_publish_meta_box_data' ] );
		add_action( 'admin_notices', [ $this, 'custom_admin_error_notice' ] );

		add_filter( 'plugins_api', [ $this, 'get_plugin_update_info' ], 20, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_plugin_update' ] );

		$this->appId           = get_option( 'wp_facebook_auto_publish_app_id' );
		$this->appSecret       = get_option( 'wp_facebook_auto_publish_app_secret' );
		$this->pageAccessToken = get_option( 'wp_facebook_auto_publish_page_access_token' );
		$this->pageId          = get_option( 'wp_facebook_auto_publish_page_id' );

	}

	function check_for_plugin_update($transient) {

		if (empty($transient->checked)) {
			return $transient;
		}

		$plugin_slug = 'wp-facebook-auto-publish/wp-facebook-auto-publish.php';
		$repo_url = 'https://api.github.com/repos/MantasDainys/wp-facebook-auto-publish/releases/latest';

		$response = wp_remote_get($repo_url);

		if (is_wp_error($response)) {
			return $transient;
		}

		$release = json_decode(wp_remote_retrieve_body($response), true);

		if (isset($release['tag_name'])) {
			$remote_version = ltrim($release['tag_name'], 'v');
			$current_version = '1.0.1';

			if (version_compare($remote_version, $current_version, '>')) {
				$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_slug);

				$transient->response[$plugin_slug] = (object) [
					'slug' => $plugin_slug,
					'new_version' => $remote_version,
					'package' => $release['zipball_url'],
					'url' => $release['html_url'],
				];
			}
		}

		return $transient;
	}

	function get_plugin_update_info($res, $action, $args) {
		if ($action !== 'plugin_information') {
			return $res;
		}

		if ($args->slug !== 'wp-facebook-auto-publish') {
			return $res;
		}

		$repo_url = 'https://api.github.com/repos/MantasDainys/wp-facebook-auto-publish/releases/latest';

		$response = wp_remote_get($repo_url);

		if (is_wp_error($response)) {
			return $res;
		}

		$release = json_decode(wp_remote_retrieve_body($response), true);

		if (isset($release['tag_name'])) {
			$res = (object) [
				'name' => 'WP Facebook Auto Publish',
				'slug' => 'wp-facebook-auto-publish',
				'version' => ltrim($release['tag_name'], 'v'),
				'author' => '<a href="https://github.com/MantasDainys">Mantas Dainys</a>',
				'homepage' => 'https://github.com/MantasDainys/wp-facebook-auto-publish',
				'download_link' => $release['zipball_url'],
				'sections' => [
					'description' => 'Automatically publish posts to Facebook.',
				],
			];
		}

		return $res;
	}

	public function add_facebook_publish_meta_box() {
		add_meta_box(
			'facebook_publish_meta_box',
			__( 'Publish on Facebook', 'wp-facebook-auto-publish' ),
			[ $this, 'facebook_publish_meta_box_callback' ],
			'post',
			'side',
			'high'
		);
	}

	public function facebook_publish_meta_box_callback( $post ) {
		$value = get_post_meta( $post->ID, '_facebook_publish', true );
		echo '<label for="facebook_publish">';
		echo '<input type="checkbox" id="facebook_publish" name="facebook_publish" value="1" ' . checked( 1, $value, false ) . ' />';
		_e( 'Publish this post on the FB page', 'wp-facebook-auto-publish' );
		echo '</label>';
	}

	public function save_facebook_publish_meta_box_data( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['facebook_publish'] ) ) {
			$value = get_post_meta( $post_id, '_facebook_publish', true );
			if ( ! $value ) {
				update_post_meta( $post_id, '_facebook_publish', 1 );
				$this->publish_post_to_facebook( $post_id );
			}
		} else {
			update_post_meta( $post_id, '_facebook_publish', 0 );
		}
	}

	function get_post_gallery_images_full( $post ) {

		if ( ! has_shortcode( $post->post_content, 'gallery' ) ) {
			return [];
		}
		preg_match( '/\[gallery.*ids=.(.*).\]/', $post->post_content, $ids );
		if ( empty( $ids[1] ) ) {
			return [];
		}

		$image_ids = explode( ',', $ids[1] );
		$images    = [];
		foreach ( $image_ids as $image_id ) {
			$image_url = wp_get_attachment_image_src( $image_id, 'full' )[0];
			if ( $image_url ) {
				$images[] = $image_url;
			}
		}

		return $images;
	}

	private function publish_post_to_facebook( $post_id ) {

		$post = get_post( $post_id );

		$fb = new Facebook( [
			'app_id'                => $this->appId,
			'app_secret'            => $this->appSecret,
			'default_graph_version' => 'v21.0',
		] );

		$text = preg_replace( '/\[gallery[^\]]*\]/', '', $post->post_content );

		$postData = [
			'message'   => $post->post_title . "\n\n" . wp_strip_all_tags( $text ),
			'published' => false
		];

		$attached_media = [];
		$gallery_images = $this->get_post_gallery_images_full( $post );

		if ( ! empty( $gallery_images ) ) {
			foreach ( $gallery_images as $index => $image_url ) {
				try {
					$postData['url']       = $image_url;
					$postData['published'] = false;
					$photoResponse         = $fb->post( '/' . $this->pageId . '/photos', $postData, $this->pageAccessToken );
					$photoId = $photoResponse->getGraphNode()['id'];
					$attached_media[] = [ 'media_fbid' => $photoId ];
				} catch ( FacebookResponseException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				} catch ( FacebookSDKException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				}
			}
		}

		if ( ! empty( $attached_media ) ) {
			try {
				$postData['attached_media'] = $attached_media;
				$postData['published']      = true;
				$fb->post( '/' . $this->pageId . '/feed', $postData, $this->pageAccessToken );
			} catch ( FacebookResponseException $e ) {
				set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
			} catch ( FacebookSDKException $e ) {
				set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
			}
		} else {
			$thumbnail_id = get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id ) {
				$thumbnail_url = wp_get_attachment_image_src( $thumbnail_id, 'full' )[0];
				try {
					$postData['url']       = $thumbnail_url;
					$postData['published'] = true;
					$fb->post( '/' . $this->pageId . '/photos', $postData, $this->pageAccessToken );
				} catch ( FacebookResponseException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				} catch ( FacebookSDKException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				}
			} else {
				try {
					$postData['published'] = true;
					$fb->post( '/' . $this->pageId . '/feed', $postData, $this->pageAccessToken );
				} catch ( FacebookResponseException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				} catch ( FacebookSDKException $e ) {
					set_transient( 'fb_error', 'FacebookResponseException: ' . $e->getMessage(), 30 );
				}
			}
		}
	}

	public function custom_admin_error_notice() {
		$error = get_transient( 'fb_error' );
		if ( ! empty( $error ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			delete_transient( 'fb_error' );
		}
	}
}

new WPFacebookAutoPublish();
