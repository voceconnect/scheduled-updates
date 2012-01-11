<?php
/*
	Plugin Name: Scheduled Updates
	Description: Allows users to schedule updates to posts.
	Version: 0.1
	Author: Jeff Stieler, Chris Scott
	Author URI: http://plugins.voceconnect.com
*/

class Scheduled_Updates {

	const POST_STATUS = 'future-update';

	public static function initialize() {
		add_action('init', array(__CLASS__, 'create_future_update_status'), 1);
		add_action('post_row_actions', array(__CLASS__, 'action_post_row_actions'));
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
			if (self::has_scheduled_update()) {
				unset($actions['edit']);
				$actions['edit-scheduled-update'] = '<a href="" title="' . esc_attr( __( 'Edit Scheduled Update', 'scheduled-update' ) ) . '">' . __( 'Edit Scheduled Update' ) . '</a>';
			} else {
				$actions['schedule-update'] = '<a href="" title="' . esc_attr( __( 'Schedule Update', 'scheduled-update' ) ) . '">' . __( 'Schedule Update' ) . '</a>';
			}
		}

		return ($actions);
	}

}

add_action('init', array('Scheduled_Updates', 'initialize'), 0); // 0 priority since we are adding actions with priority = 1