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
		add_action('post_row_actions', array(__CLASS__, 'action_post_row_actions'));
		add_action('load-edit', array(__CLASS__, 'action_check_args'));
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

	/**
	 * Does the post have a scheduled update? Scheduled updates are stored as
	 * revisions with a custom post status
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function has_scheduled_update($post_id = 0) {
		$post_id = !$post_id ? get_the_ID() : $post_id;

		if (!$post_id) {
			return false;
		}

		$revision = wp_get_post_revision(get_post($post_id));

		return self::POST_STATUS == $revision->post_status;
	}

	/**
	 * Is the post schedulable? Only visible posts are schedulable.
	 *
	 * @param int $post_id
	 * @return boolean
	 */
	public static function is_schedulable($post_id = 0) {
		$post_id = !$post_id ? get_the_ID() : $post_id;

		if (!$post_id) {
			return false;
		}

		$post = get_post($post_id);

		$schedulable = in_array($post->post_status, array('publish', 'private'));

		return $schedulable;
	}


	/**
	 * For schedulable posts, update the post row actions. Remove the edit link
	 * for posts with and update and add a link to edit the update. Add a link
	 * to schedule and update for posts without an update.
	 *
	 * @param array $actions
	 * @return array possibly modified actions
	 */
	public static function action_post_row_actions($actions) {
		if (self::is_schedulable()) {
			$post_id = get_the_ID();

			if (self::has_scheduled_update()) {
				unset($actions['edit']);

				$link = add_query_arg('edit_scheduled_update', $post_id);
				$label = __('Edit Scheduled Update', 'scheduled-update');
				$actions['edit-scheduled-update'] = sprintf('<a href="%s" title="%s">%s</a>', esc_url($link), esc_attr($label), esc_html($label));
			} else {
				$link = add_query_arg('schedule_update', $post_id);
				$label = __('Schedule Update', 'scheduled-update');
				$actions['schedule-update'] = sprintf('<a href="%s" title="%s">%s</a>', esc_url($link), esc_attr($label), esc_html($label));
			}
		}

		return $actions;
	}

	/**
	 * check args to see if we should schedule or update a scheduled post
	 */
	public function action_check_args() {
		if (isset($_REQUEST['edit_scheduled_update']) && $post_id = absint($_REQUEST['edit_scheduled_update'])) {
			self::edit_update($post_id);
		}

		if (isset($_REQUEST['schedule_update']) && $post_id = absint($_REQUEST['schedule_update'])) {
			self::create_update($post_id);
		}
	}

	/**
	 * For all post types that support scheduled updates,
	 * use Meta-Revisions plugin to version their taxonomy terms
	 */
	public static function setup_term_revisions() {
		$post_types = get_post_types();
		foreach ($post_types as $post_type) {
			if (post_type_supports($post_type, self::POST_STATUS)) {
				$taxonomies = get_object_taxonomies($post_type, 'objects');
				foreach ($taxonomies as $taxonomy_obj) {
					meta_revisions_track_taxonomy_field($taxonomy_obj->name, $taxonomy_obj->labels->name, $post_type);
				}
			}
		}
	}

	/**
	 * For posts with scheduled update support,
	 * use Meta-Revisions to version their post meta values
	 *
	 * @param int $post_id
	 */
	public static function setup_meta_revisions($post_id) {
		$post_type = get_post_type($post_id);
		if (post_type_supports($post_type, self::POST_STATUS)) {
			$meta_keys = get_post_custom_keys($post_id);
			foreach ($meta_keys as $meta_key) {
				meta_revisions_track_postmeta_field($meta_key, $meta_key, $post_type);
			}
		}
	}

}

add_action('init', array('Scheduled_Updates', 'initialize'), 0); // 0 priority since we are adding actions with priority = 1