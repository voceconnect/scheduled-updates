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
		add_action('post_row_actions', array(__CLASS__, 'action_post_row_actions'), 10, 2);
		add_action('load-edit.php', array(__CLASS__, 'action_check_args'));
		add_action('Meta_Revisions_init', array(__CLASS__, 'setup_term_revisions'));
		add_action('pre_version_meta', array(__CLASS__, 'setup_meta_revisions'));
		add_filter('redirect_post_location', array(__CLASS__, 'filter_redirect_post_location'), 10, 2);
		add_action('load-post.php', array(__CLASS__, 'action_add_admin_notice'));

		wp_register_style('scheduled-update-admin', plugins_url('scheduled-update-admin.css', __FILE__));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_edit_style'));
		add_action('wp_insert_post_data', array(__CLASS__, 'maintain_scheduled_update_status'), 10, 2);

		$post_types = get_post_types();
		foreach ($post_types as $post_type) {
			if (post_type_supports($post_type, self::POST_STATUS)) {
				add_action("manage_{$post_type}_posts_columns", array(__CLASS__, 'action_manage_posts_columns'));
				add_action("manage_{$post_type}_posts_custom_column", array(__CLASS__, 'action_manage_posts_custom_column'), 10, 2);
			}
		}

		add_action('transition_post_status', array(__CLASS__, 'schedule_post_update'), 10, 3);
		add_action('publish_scheduled_update', array(__CLASS__, 'update_post'));
		add_filter('meta_revisions_should_version_meta', array(__CLASS__, 'prevent_scheduled_update_revision'), 10, 2);
	}

	public static function create_future_update_status() {
		register_post_status(
			self::POST_STATUS,
			array(
				'label' => 'Scheduled Update',
				'exclude_from_search' => true,
				'public' => false,
				'show_in_admin_all_list' => false,
				'show_in_admin_status_list' => false
			)
		);
	}

	public static function enqueue_edit_style($hook_suffix) {
		$post_id = isset($_GET['post']) ? (int)$_GET['post'] : false;
		if (('post.php' == $hook_suffix) && $post_id && (self::POST_STATUS == get_post_status($post_id))) {
			wp_enqueue_style('scheduled-update-admin');
		}
	}

	function action_manage_posts_columns($posts_columns) {
		$posts_columns['scheduled_update'] = __('Scheduled<br />Update', 'scheduled-update');

		return $posts_columns;
	}

	function action_manage_posts_custom_column($column_name, $post_id) {
		if ('scheduled_update' == $column_name && $post = self::get_scheduled_update_post($post_id)) {
			$time = get_post_time( 'G', true, $post );
			$time_diff = time() - $time;
			$t_time = get_the_time( __( 'Y/m/d g:i:s A' ), $post );
			$h_time = sprintf(__('<abbr title="%s">%s from now</abbr>', 'scheduled-update'), $t_time, human_time_diff($time));

			echo $h_time;
		}
	}

	/**
	 * add an admin notice if the post being edited is a scheduled post
	 */
	function action_add_admin_notice() {
		if (isset($_REQUEST['action']) && 'edit' == $_REQUEST['action'] && isset($_REQUEST['post'])) {
			if (self::is_scheduled_post(absint($_REQUEST['post']))) {
				add_action('admin_notices', array(__CLASS__, 'action_show_admin_notice'));
			}
		}
	}

	/**
	 * show an admin notice to warn the user they are editing a scheduled post
	 * and give them a link to edit the original
	 */
	function action_show_admin_notice() {
		$scheduled_post = get_post(absint($_REQUEST['post']));
		$edit_url = get_edit_post_link($scheduled_post->post_parent);
		?>
		<div class="updated">
			<p><b>NOTE: You are editing a Scheduled Update.</b> This update will go live
				once the publish date of this post is reached. <a href="<?php echo esc_url($edit_url); ?>">Edit the original post</a>.</p>
		</div>
		<?php
	}

	/**
	 * Does the post have a scheduled update? Scheduled updates are stored as
	 * revisions with a custom post status
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function get_scheduled_update_post($post_id = 0) {
		$post_id = !$post_id ? get_the_ID() : $post_id;

		if (!$post_id) {
			return false;
		}

		$args = array('posts_per_page' => 1, 'orderby' => 'date', 'post_parent' => $post_id, 'post_status' => self::POST_STATUS);

		$revision = get_children($args);

		if (is_array($revision)) {
			$revision = array_pop($revision);
		}

		return $revision;
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
	public static function action_post_row_actions($actions, $post) {
		if (post_type_supports($post->post_type, self::POST_STATUS) && self::is_schedulable($post->ID)) {
			if ($update = self::get_scheduled_update_post($post->ID)) {
				$link = self::get_edit_post_link($update->ID, $post->post_type);
				$label = __('Edit Scheduled Update', 'scheduled-update');
				$actions['edit-scheduled-update'] = sprintf('<a href="%s" title="%s">%s</a>', esc_url($link), esc_attr($label), esc_html($label));
			} else {
				$link = add_query_arg('schedule_update', $post->ID);
				$label = __('Schedule Update', 'scheduled-update');
				$actions['schedule-update'] = sprintf('<a href="%s" title="%s">%s</a>', esc_url($link), esc_attr($label), esc_html($label));
			}
		}

		return $actions;
	}

	/**
	 * Check args to see if we should schedule or update a scheduled post
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
	 * Create a scheduled update. This is a revision with a custom post status.
	 *
	 * @param int $post_id
	 */
	public function create_update($post_id) {
		global $wpdb;

		$original_post = get_post($post_id);
		$revision_id = Meta_Revisions::version_post_meta_and_terms($post_id);

		// mark this as a future-update, and revert to the original post type for edit purposes
		// NOTE: this is meant to be a direct update in order avoid an additional revision being created, taking the attached meta/terms with it
		$fields = array(
			'post_status' => self::POST_STATUS,
			'post_type' => $original_post->post_type,
			'post_date' => date('Y-m-d H:i:s', strtotime('+1 day'))
		);
		$wpdb->update($wpdb->posts, $fields, array('ID' => $revision_id));

		$link = self::get_edit_post_link($revision_id, $original_post->post_type);

		wp_redirect($link);
		exit;
	}

	/**
	 * Get the edit post link for a revision that will allow editing instead
	 * of viewing on revision.php
	 *
	 * @param int $revision_id
	 * @param string $post_type
	 * @return string
	 */
	public static function get_edit_post_link($revision_id, $post_type) {
		$post_type_object = get_post_type_object( $post_type );
		if ( !$post_type_object )
			return;

		if ( !current_user_can( $post_type_object->cap->edit_post, $revision_id ) )
			return;

		$action = '&action=edit';
		$link = admin_url(sprintf($post_type_object->_edit_link . $action, $revision_id));

		return $link;
	}

	/**
	 * Filter the redirect post location
	 *
	 * @param string $location
	 * @param int $post_id
	 * @return string
	 */
	public static function filter_redirect_post_location($location, $post_id) {
		if (!self::is_scheduled_post($post_id)) {
			return $location;
		}

		$revision = get_post($post_id);
		$parent_post_type = get_post_type($revision->post_parent);

		$location = self::get_edit_post_link($post_id, $parent_post_type);

		return $location;

	}

	/**
	 * Is this a revision with our custom status?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_scheduled_post($post_id) {
		return self::POST_STATUS == get_post_status($post_id);
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

	/**
	 * Prevents WordPress from changing a scheduled update's post_status when saved
	 *
	 * @param array $data - Data to be inserted in posts table
	 * @param array $postarr - Data passed to wp_insert_post
	 */
	public static function maintain_scheduled_update_status($data, $postarr) {
		if (
			isset($data['post_parent']) &&
			post_type_supports(get_post_type($data['post_parent']), self::POST_STATUS) &&
			isset($postarr['original_post_status']) &&
			(self::POST_STATUS == $postarr['original_post_status'])
		) {
			$data['post_status'] = self::POST_STATUS;
		}
		return $data;
	}

	/**
	 * Schedule the post update using the date of the "future-status" version
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param object $post
	 */
	public static function schedule_post_update($new_status, $old_status, $post) {
		if (
			(self::POST_STATUS == $old_status) &&
			(self::POST_STATUS == $new_status) &&
			post_type_supports(get_post_type($post->post_parent), self::POST_STATUS)
		) {
			wp_clear_scheduled_hook( 'publish_scheduled_update', array( $post->ID ) );
			wp_schedule_single_event( strtotime( get_gmt_from_date( $post->post_date ) . ' GMT') , 'publish_scheduled_update', array( $post->ID ) );
		}
	}

	/**
	 * Use the revision functionality to "restore" the scheduled update
	 *
	 * @param int $post_id - ID of the scheduled update post
	 */
	public static function update_post($post_id) {
		global $wpdb;
		$wpdb->update($wpdb->posts, array('post_type' => 'revision', 'post_status' => 'inherit'), array('ID' => $post_id));
		$result = wp_restore_post_revision($post_id);
		if ($result && !is_wp_error($result)) {
			wp_delete_post($post_id);
		}
	}

	/**
	 * Prevent meta-revisions to future-update status posts
	 *
	 * @param bool $should_version_meta
	 * @param int $post_id
	 */
	public static function prevent_scheduled_update_revision($should_version_meta, $post_id) {
		$post = get_post($post_id);
		if (post_type_supports(get_post_type($post), self::POST_STATUS) && (self::POST_STATUS == $post->post_status)) {
			$should_version_meta = false;
		}
		return $should_version_meta;
	}
}

add_action('init', array('Scheduled_Updates', 'initialize'), 0); // 0 priority since we are adding actions with priority = 1