<?php
/*
	Plugin Name: Scheduled Updates
	Description: Allows users to schedule updates to posts.
	Version: 0.1
	Author: Jeff Stieler, Chris Scott
	Author URI: http://plugins.voceconnect.com
*/

require('meta-revisions/meta-revisions.php');

class Scheduled_Updates {

	const POST_STATUS = 'future-update';

	public static function initialize() {
		add_action('init', array(__CLASS__, 'create_future_update_status'), 1);
		add_action('Meta_Revisions_init', array(__CLASS__, 'setup_term_revisions'));
		add_action('pre_version_meta', array(__CLASS__, 'setup_meta_revisions'));
	}

	public static function create_future_update_status() {
		register_post_status(
			self::POST_STATUS,
			array(
				'label' => 'Scheduled Update',
				'exclude_from_search' => true,
				'public' => true,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true
			)
		);
	}

	public static function setup_term_revisions() {
		$post_types = get_post_types();
		foreach ($post_types as $post_type) {
			if (post_type_supports($post_type, self::POST_STATUS)) {
				$taxonomies = get_object_taxonomies($post_type, 'objects');
				foreach ($taxonomies as $taxonomy_obj) {
					error_log('tracking taxonomy: ' . $taxonomy_obj->name . ' for post type: ' . $post_type);
					meta_revisions_track_taxonomy_field($taxonomy_obj->name, $taxonomy_obj->labels->name, $post_type);
				}
			}
		}
	}

	public static function setup_meta_revisions($post_id) {
		$post_type = get_post_type($post_id);
		if (post_type_supports($post_type, self::POST_STATUS)) {
			$meta_keys = get_post_custom_keys($post_id);
			foreach ($meta_keys as $meta_key) {
				error_log('tracking meta key: ' . $meta_key . ' for post type: ' . $post_type);
				meta_revisions_track_postmeta_field($meta_key, $meta_key, $post_type);
			}
		}
	}

}

add_action('init', array('Scheduled_Updates', 'initialize'), 0); // 0 priority since we are adding actions with priority = 1