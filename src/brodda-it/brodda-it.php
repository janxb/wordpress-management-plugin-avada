<?php
/**
 * Plugin Name: brodda.IT
 * Author: Jan Brodda / brodda.IT
 * Version: 2
 */

defined( 'ABSPATH' ) or die();

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

require_once __DIR__ . '/vendor/autoload.php';

class BroddaITPlugin {
	public function __construct() {
		$this->update_check();
		$this->disable_comments();
		$this->force_auto_updates();
		$this->remove_all_dashboard_widgets();
		$this->disable_gutenberg_editor();
		$this->prepare_custom_user_roles();
		$this->create_shortcode_ics();
		$this->disable_user_avatars();
		$this->random_upload_filenames();
	}

	private function is_avada_theme_installed(): bool {
		return $this->is_theme_installed( 'Avada' );
	}

	private function is_enfold_theme_installed(): bool {
		return $this->is_theme_installed( 'Enfold' );
	}

	private function is_theme_installed( $name ): bool {
		$theme        = wp_get_theme();
		$parent_theme = $theme->parent();

		return $theme->__get( 'name' ) === $name || $parent_theme && $parent_theme->__get( 'name' ) === $name;
	}

	private function disable_user_avatars(): void {
		// Replace Gravatar with a local default avatar
		add_filter( 'get_avatar_url', function ( $url, $id_or_email, $args ) {
			// Return a local default avatar image
			return plugin_dir_url( __FILE__ ) . '/default-avatar.png';
		}, 10, 3 );

// Prevent DNS prefetch to gravatar.com
		remove_action( 'wp_head', 'wp_resource_hints', 2 );
	}

	private function random_upload_filenames(): void {
		add_filter( 'wp_insert_attachment_data', function ( $data, $postarr ) {
			if ( $_POST['action'] == 'upload-attachment' && ! empty( $data['post_title'] ) && isset( $postarr['post_type'] ) && $postarr['post_type'] === 'attachment' ) {
				$data['post_title'] = '';
			}

			return $data;
		}, 10, 2 );

		add_filter( 'sanitize_file_name', function ( $filename ) {
			$name = sha1( random_bytes( 32 ) );
			$ext  = pathinfo( $filename, PATHINFO_EXTENSION );
			if ( $ext === 'zip' ) {
				return $filename;
			}

			return "$name.$ext";
		} );

		add_action( 'admin_init', function () {
			if ( get_option( 'uploads_use_yearmonth_folders' ) != 0 ) {
				update_option( 'uploads_use_yearmonth_folders', 0 );
			}
		} );
	}

	private function create_shortcode_ics(): void {
		add_shortcode( 'ics_events', function ( $atts ) {

			$atts = shortcode_atts( array(
				'url'   => '',
				'limit' => 50,
			), $atts );

			if ( empty( $atts['url'] ) ) {
				return '<p>No ICS URL provided.</p>';
			}

			require_once __DIR__ . '/vendor/autoload.php';

			// Cache key
			$cache_key = 'ics_cache_' . md5( $atts['url'] );

			// Try cache
			$body = get_transient( $cache_key );

			// Cache miss
			if ( $body === false ) {

				$response = wp_remote_get( $atts['url'], array(
					'timeout'    => 20,
					'user-agent' => 'WordPress ICS Reader'
				) );

				if ( is_wp_error( $response ) ) {
					return '<p>Fetch failed: ' . esc_html( $response->get_error_message() ) . '</p>';
				}

				$body = wp_remote_retrieve_body( $response );

				if ( empty( $body ) ) {
					return '<p>ICS response empty.</p>';
				}

				// Cache for 1 hour
				set_transient( $cache_key, $body, HOUR_IN_SECONDS );
			}

			if ( strpos( $body, 'BEGIN:VCALENDAR' ) === false ) {

				return '<pre style="white-space:pre-wrap;">'
				       . esc_html( substr( $body, 0, 1000 ) )
				       . '</pre>';
			}

			try {

				$ical = new ICal\ICal( false, array(
					'defaultSpan'     => 2,
					'defaultTimeZone' => 'Europe/Berlin',
				) );

				$ical->initString( $body );

				$events = $ical->events();

				if ( empty( $events ) ) {
					return '<p>No parsed events found.</p>';
				}

				ob_start();

				echo '
					<style>
					    .ics-month-headline {
					        font-size: 32px;
					        font-weight: 700;
					        margin: 50px 0 30px;
					        text-align: center;
					    }
					
					    .ics-event {
					        display: flex;
					        justify-content: center;
					        gap: 40px;
					        margin-bottom: 35px;
					        align-items: flex-start;
					        text-align: center;
					    }
					
					    .ics-date {
					        width: 260px;
					        flex-shrink: 0;
					        font-weight: 700;
					        text-align: right;
					    }
					
					    .ics-content {
					        width: 500px;
					        text-align: left;
					    }
					
					    .ics-title {
					        font-weight: 700;
					        margin-bottom: 8px;
					    }
					
					    .ics-description {
					        line-height: 1.5;
					        opacity: 0.85;
					    }
					
					    @media(max-width: 700px) {
					
					        .ics-event {
					            flex-direction: column;
					            gap: 10px;
					            align-items: center;
					        }
					
					        .ics-date {
					            width: auto;
					            text-align: center;
					        }
					
					        .ics-content {
					            width: 100%;
					            text-align: center;
					        }
					    }
					</style>
					';

				$count         = 0;
				$current_month = '';

				foreach ( $events as $event ) {

					if ( $count >= intval( $atts['limit'] ) ) {
						break;
					}

					if ( empty( $event->dtstart ) ) {
						continue;
					}

					$date_output    = '';
					$month_headline = '';

					// Full-day event
					if ( strlen( $event->dtstart ) === 8 ) {

						$dt = DateTime::createFromFormat(
							'Ymd',
							$event->dtstart
						);

						if ( ! $dt ) {
							continue;
						}

						$date_output = wp_date(
							'D d.m.Y',
							$dt->getTimestamp()
						);

						$month_headline = wp_date(
							'F Y',
							$dt->getTimestamp()
						);

					} else {

						// Timed event
						$dt = new DateTime( $event->dtstart );

						$date_output = wp_date(
							'D d.m.Y, H:i',
							$dt->getTimestamp()
						);

						$month_headline = wp_date(
							'F Y',
							$dt->getTimestamp()
						);
					}

					// Month headline
					if ( $month_headline !== $current_month ) {

						$current_month = $month_headline;

						echo '<h2 class="ics-month-headline">'
						     . esc_html( $month_headline )
						     . '</h2>';
					}

					$title = ! empty( $event->summary )
						? esc_html( $event->summary )
						: '';

					$description = ! empty( $event->description )
						? nl2br( esc_html( $event->description ) )
						: '';

					echo '<div class="ics-event">';

					echo '<div class="ics-date">';
					echo esc_html( $date_output );
					echo '</div>';

					echo '<div class="ics-content">';

					echo '<div class="ics-title">';
					echo $title;
					echo '</div>';

					echo '<div class="ics-description">';
					echo $description;
					echo '</div>';

					echo '</div>';

					echo '</div>';

					$count ++;
				}

				return ob_get_clean();

			} catch ( Exception $e ) {

				return '<pre>'
				       . esc_html( $e->getMessage() )
				       . '</pre>';
			}
		} );
	}

	private function create_customer_admin_role(): void {
		// Create role only once
		if ( get_role( 'customer_admin' ) ) {
			return;
		}

		add_role( 'customer_admin', 'Customer Admin', [

			// Login / dashboard
			'read'                => true,

			// Posts
			'edit_posts'          => true,
			'edit_others_posts'   => true,
			'publish_posts'       => true,
			'delete_posts'        => true,
			'delete_others_posts' => true,

			// Pages
			'edit_pages'          => true,
			'edit_others_pages'   => true,
			'publish_pages'       => true,
			'delete_pages'        => true,
			'delete_others_pages' => true,

			// Media
			'upload_files'        => true,

			// Categories / tags
			'manage_categories'   => true,

			// Comments
			'moderate_comments'   => true,
			'edit_comment'        => true,

			// Themes appearance basics
			'edit_theme_options'  => false,

			// Plugins/themes/core
			'activate_plugins'    => false,
			'install_plugins'     => false,
			'update_plugins'      => false,
			'delete_plugins'      => false,

			'install_themes' => false,
			'switch_themes'  => false,
			'update_themes'  => false,
			'delete_themes'  => false,

			'update_core'                 => false,

			// Users
			'list_users'                  => true,
			'create_users'                => true,
			'edit_users'                  => true,
			'delete_users'                => true,
			'promote_users'               => true,

			// Settings/tools
			'manage_options'              => false,
			'import'                      => false,
			'export'                      => false,
			'view_site_health_checks'     => false,
			'export_others_personal_data' => false,
			'erase_others_personal_data'  => false,

			// Customizer
			'customize'                   => false,

			// Full site editing
			'edit_theme_options'          => false,

		] );
		add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
			$current_user = wp_get_current_user();
			// Only affect customer_admin
			if ( in_array( 'administrator', (array) $current_user->roles, true ) ) {
				return $caps;
			}
			// Target user ID
			$target_user_id = $args[0] ?? 0;
			if ( ! $target_user_id ) {
				return $caps;
			}
			$target_user = get_userdata( $target_user_id );
			if ( ! $target_user ) {
				return $caps;
			}
			// Protect administrators
			if ( in_array( 'administrator', (array) $target_user->roles, true ) ) {
				// Block editing / deleting / promoting admins
				if ( in_array( $cap, [
					'edit_user',
					'remove_user',
					'delete_user',
					'promote_user',
				], true ) ) {
					return [ 'do_not_allow' ];
				}
			}

			return $caps;
		}, 10, 4 );
	}

	private function prepare_custom_user_roles(): void {
		add_action( 'init', function () {
			global $wp_roles;
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new WP_Roles();
			}
			foreach ( $wp_roles->roles as $role => $details ) {
				if ( $role === 'administrator' ) {
					continue;
				}
				remove_role( $role );
			}
			$this->create_customer_admin_role();
		}, 999 );
	}

	private function disable_gutenberg_editor(): void {
		add_filter( 'use_block_editor_for_post', '__return_false', 10 );
		add_filter( 'use_widgets_block_editor', '__return_false' );
	}

	private function remove_all_dashboard_widgets(): void {
		add_action( 'wp_dashboard_setup', function () {
			global $wp_meta_boxes;
			$wp_meta_boxes['dashboard'] = [];
		}, 999 );
	}

	private function force_auto_updates(): void {
		add_filter( 'auto_update_plugin', '__return_true' );
		add_filter( 'auto_update_theme', '__return_true' );
		add_filter( 'auto_update_core', '__return_true' );
		add_filter( 'allow_major_auto_core_updates', '__return_true' );
	}

	private function disable_comments(): void {
		add_action( 'admin_init', function () {
			// Redirect any user trying to access comments page
			global $pagenow;

			if ( $pagenow === 'edit-comments.php' || $pagenow === 'options-discussion.php' ) {
				wp_redirect( admin_url() );
				exit;
			}

			// Remove comments metabox from dashboard
			remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );

			// Disable support for comments and trackbacks in post types
			foreach ( get_post_types() as $post_type ) {
				if ( post_type_supports( $post_type, 'comments' ) ) {
					remove_post_type_support( $post_type, 'comments' );
					remove_post_type_support( $post_type, 'trackbacks' );
				}
			}

			add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
				$wp_admin_bar->remove_node( 'comments' );
			}, 999 );
		} );

// Close comments on the front-end
		add_filter( 'comments_open', '__return_false', 20, 2 );
		add_filter( 'pings_open', '__return_false', 20, 2 );

// Hide existing comments
		add_filter( 'comments_array', '__return_empty_array', 10, 2 );

// Remove comments page and option page in menu
		add_action( 'admin_menu', function () {
			remove_menu_page( 'edit-comments.php' );
			remove_submenu_page( 'options-general.php', 'options-discussion.php' );
		} );

// Remove comments links from admin bar
		add_action( 'init', function () {
			if ( is_admin_bar_showing() ) {
				remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
			}
		} );
	}

	private string $updateCheckCacheKey = 'broddait_plugin_update_cache';

	private function update_check(): void {
		$updateCheckCacheKey = 'broddait_plugin_update_cache';
		add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
			if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
				delete_transient( $updateCheckCacheKey );
			}
		}, 10, 2 );


		add_filter( 'pre_set_site_transient_update_plugins',
			function ( $transient ) {
				$plugin = ( json_decode( file_get_contents( dirname( __FILE__ ) . "/info.json" ) ) );

				$remote = get_transient( $updateCheckCacheKey );

				if ( $remote === false ) {
					$remote = wp_remote_get(
						$plugin->info_url,
						array(
							'timeout' => 10,
							'headers' => array(
								'Accept' => 'application/json'
							)
						)
					);
					set_transient( $updateCheckCacheKey, $remote, 10 );
				}

				if (
					is_wp_error( $remote )
					|| 200 !== wp_remote_retrieve_response_code( $remote )
					|| empty( wp_remote_retrieve_body( $remote ) )
				) {
					return $transient;
				}

				$remote = json_decode( wp_remote_retrieve_body( $remote ) );

				if ( $remote ) {
					$res              = new stdClass();
					$res->slug        = 'brodda-it';
					$res->id          = plugin_basename( __FILE__ );
					$res->plugin      = plugin_basename( __FILE__ );
					$res->new_version = $remote->version;
					$res->package     = $remote->download_url;

					if ( version_compare( $plugin->version, $remote->version, '<' ) ) {
						$transient->response[ $res->plugin ] = $res;
					} else {
						$transient->no_update[ $res->plugin ] = $res;
					}
				}

				return $transient;
			} );
	}
}

new BroddaITPlugin();