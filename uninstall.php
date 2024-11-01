<?php
/**
 * Predator Alert Tool for Twitter uninstaller
 *
 * @package plugin
 */

// Don't execute any uninstall code unless WordPress core requests it.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit(); }

// Delete options.
delete_option('wp-patt_settings');

// Delete user data.
foreach (get_users() as $user) {
    delete_user_meta($user->ID, 'wp-patt_access_token');
    delete_user_meta($user->ID, 'wp-patt_access_token_secret');
    delete_user_meta($user->ID, 'wp-patt_twitter_id');
    delete_user_meta($user->ID, 'wp-patt_twitter_screen_name');
    delete_user_meta($user->ID, 'wp-patt_profile_image_url_https');
    delete_user_meta($user->ID, 'wp-patt_twitter_data');
}

// TODO:
// Do we also want to delete the actual warnlists?
