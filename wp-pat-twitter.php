<?php
/**
 * Plugin Name: Predator Alert Tool for Twitter
 * Plugin URI: http://maybemaimed.com/predator-alert-tool-for-twitter/
 * Description: Turns your WordPress-powered website into a <a href="https://github.com/meitar/pat-twitter/">Predator Alert Tool for Twitter</a> facilitation server so you can help keep your community safe from predators on Twitter. Remember, there is <a href="http://maybemaimed.com/2013/10/09/no-good-excuse-for-not-building-sexual-violence-prevention-tools-into-every-social-network-on-the-internet/">no good excuse for not building sexual violence prevention tools into every social network on the Internet</a>.
 * Author: <a href="http://maymay.net/">Maymay</a>
 * Version: 0.1
 * Text Domain: wp-pat-twitter
 * Domain Path: /languages
 */

class WP_PAT_Twitter {
    private $prefix = 'wp-patt_';

    public function __construct () {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'registerL10n'));
        add_action('init', array($this, 'initialize'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_menu', array($this, 'registerAdminMenu'));
        //add_action('wp_ajax_MY_ACTION', array($this, 'handleJSON'));

        add_filter('the_content', array($this, 'displayContent'));

        add_action('show_user_profile', array($this, 'showUserProfile'));

        $options = get_option($this->prefix . 'settings');
        // Initialize consumer if we can, set up authroization flow if we can't.
        require_once 'lib/TwitterAPIClient.php';
        if (isset($options['consumer_key']) && isset($options['consumer_secret'])) {
            $this->api = new Twitter_API_Client($options['consumer_key'], $options['consumer_secret']);
            if (
                get_user_meta(get_current_user_id(), $this->prefix . 'access_token', true)
                &&
                get_user_meta(get_current_user_id(), $this->prefix . 'access_token_secret', true)
            ) {
                $this->api->client->access_token = get_user_meta(get_current_user_id(), $this->prefix . 'access_token', true);
                $this->api->client->access_token_secret = get_user_meta(get_current_user_id(), $this->prefix . 'access_token_secret', true);
            }
        } else {
            $this->api = new Twitter_API_Client;
            add_action('admin_notices', array($this, 'showMissingConfigNotice'));
        }

        if (isset($options['debug'])) {
            $this->api->client->debug = 1;
            $this->api->client->debug_http = 1;
        }

        // OAuth connection workflow.
        if (isset($_GET[$this->prefix . 'oauth_authorize'])) {
            add_action('init', array($this, 'authorizeApp'));
        } else if (isset($_GET[$this->prefix . 'callback']) && !empty($_GET['oauth_verifier'])) {
            // Unless we're just saving the options, hook the final step in OAuth authorization.
            if (!isset($_GET['settings-updated'])) {
                add_action('init', array($this, 'completeAuthorization'));
            }
        }
    }

    public function authorizeApp () {
        check_admin_referer($this->prefix . 'oauth_authorize', $this->prefix . 'nonce');
        $this->api->authorize(admin_url('profile.php?' . $this->prefix . 'callback'));
    }

    public function completeAuthorization () {
        $tokens = $this->api->completeAuthorization(admin_url('profile.php?' . $this->prefix . 'callback#wp-patt_download-userscript'));
        update_user_meta(get_current_user_id(), $this->prefix . 'access_token', $tokens['value']);
        update_user_meta(get_current_user_id(), $this->prefix . 'access_token_secret', $tokens['secret']);
        $x = $this->api->verifyCredentials();
        update_user_meta(get_current_user_id(), $this->prefix . 'twitter_id', $x->id_str);
        update_user_meta(get_current_user_id(), $this->prefix . 'twitter_screen_name', $x->screen_name);
        update_user_meta(get_current_user_id(), $this->prefix . 'profile_image_url_https', $x->profile_image_url_https);
        update_user_meta(get_current_user_id(), $this->prefix . 'twitter_data', json_encode($x));
    }

    public function registerL10n () {
        load_plugin_textdomain('wp-pat-twitter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function initialize () {
        $this->registerCustomPostType();

        // Figure out what we're being asked to do.
        // This could be a request from the PAT Twitter userscript
        // itself or a request to get it, or just a regular WP one.
        $this->routeRequest();
    }

    public function showMissingConfigNotice () {
        $screen = get_current_screen();
        if ($screen->base === 'plugins') {
?>
<div class="updated">
    <p><a href="<?php print admin_url('options-general.php?page=' . $this->prefix . 'settings');?>" class="button"><?php esc_html_e('Connect to Twitter', 'wp-pat-twitter');?></a> &mdash; <?php esc_html_e('Almost done! Connect your blog to Twitter to begin using Predator Alert Tool for Twitter.', 'wp-pat-twitter');?></p>
</div>
<?php
        }
    }

    public function registerSettings () {
        register_setting(
            $this->prefix . 'settings',
            $this->prefix . 'settings',
            array($this, 'validateSettings')
        );
    }

    public function displayContent ($content) {
        global $post;
        $options = get_option($this->prefix . 'settings');
        if ($this->prefix . 'list' === get_post_type($post->ID)) {
            $append = '<ul id="' . esc_attr($this->prefix . 'list-meta-' . $post->ID) . '" class="' . esc_attr($this->prefix) . 'list-meta">';
            $count = count($this->getUsersOnList($post->ID));
            $append .= '<li>' . sprintf(_n('1 member', '%s members', $count, 'wp-pat-twitter'), $count) . '</li>';
            $append .= '</ul>';
            $url = wp_nonce_url(admin_url('admin-ajax.php'), $this->prefix . 'export', $this->prefix . 'nonce');
            $append .= '<p><a href="' . $url . '&amp;action=view&amp;id=' . $post->ID . '" class="button">' . esc_html__('Export', 'wp-pat-twitter') . '</a></p>';
        }
        return $content . $append;
    }

    /**
     * @param array $input An array of of our unsanitized options.
     * @return array An array of sanitized options.
     */
    public function validateSettings ($input) {
        $safe_input = array();
        foreach ($input as $k => $v) {
            switch ($k) {
                case 'consumer_key':
                    if (empty($v)) {
                        $errmsg = __('Consumer key cannot be empty.', 'wp-pat-twitter');
                        add_settings_error($this->prefix . 'settings', 'empty-consumer-key', $errmsg);
                    }
                    $safe_input[$k] = sanitize_text_field($v);
                break;
                case 'consumer_secret':
                    if (empty($v)) {
                        $errmsg = __('Consumer secret cannot be empty.', 'wp-pat-twitter');
                        add_settings_error($this->prefix . 'settings', 'empty-consumer-secret', $errmsg);
                    }
                    $safe_input[$k] = sanitize_text_field($v);
                break;
                case 'debug':
                    $safe_input[$k] = intval($v);
                break;
            }
        }
        return $safe_input;
    }

    private function routeRequest () {
        // If we're being asked for the userscript,
        if (isset($_GET[$this->prefix . 'nonce']) && wp_verify_nonce($_GET[$this->prefix . 'nonce'], 'download_userscript')) {
            // generate the code we need, return it to the user,
            $this->outputUserscript();
            die(); // and finish.
        }
        // If we're being asked to export...
        if (isset($_GET[$this->prefix . 'nonce']) && wp_verify_nonce($_GET[$this->prefix . 'nonce'], $this->prefix . 'export')) {
            // prompt a download with the appropriate data.
            header('Content-Disposition: attachment; filename=PAT-List-' . get_post_field('post_name', $_GET['id']) . '.json');
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
        }

        // If we detect that this is a request FROM the userscript...
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (isset($_GET['action'])) {
                switch ($_GET['action']) {
                    case 'login':
                        if (is_user_logged_in()) {
                            wp_redirect(get_edit_profile_url());
                        } else {
                            wp_redirect(wp_login_url());
                        }
                        exit();
                        break;
                    case 'logout':
                        wp_logout();
                        wp_redirect(site_url());
                        exit();
                        break;
                    case 'view':
                        $list_id = (isset($_GET['id'])) ? $_GET['id'] : false;
                        if (isset($_POST['do']) && 'new' === $_POST['do']) {
                            switch ($_POST['what']) {
                                case 'list':
                                    $params = array(
                                        'name' => $_POST['name'],
                                        'desc' => $_POST['desc']
                                    );
                                    $list_id = $this->addList($params);
                                    break;
                                case 'alert':
                                    $alert_desc = (empty($_POST['data']['alert_desc'])) ? null : $_POST['data']['alert_desc'];
                                    $this->addAlert($_POST['who'], $_POST['where'], $alert_desc);
                                    wp_send_json_success();
                                    exit();
                                    break;
                            }
                        }
                        if (false === $list_id) {
                            wp_send_json_error();
                            exit();
                        }
                        $list_info = new stdClass();
                        $list_info->id = $list_id;
                        $list_info->list_name = get_post_field('post_title', $list_info->id);
                        $list_info->list_desc = get_post_field('post_content', $list_info->id);
                        $list_info->creation_time = date('c', strtotime(get_post_field('post_date_gmt', $list_info->id)));
                        $list_info->last_modified = date('c', strtotime(get_post_field('post_modified_gmt', $list_info->id)));
                        $list_info->author = new stdClass();
                        $list_info->author->twitter_id = get_user_meta(get_post_field('post_author', $list_info->id), $this->prefix . 'twitter_id', true);
                        $list_info->author->twitter_screen_name = get_user_meta(get_post_field('post_author', $list_info->id), $this->prefix . 'twitter_screen_name', true);
                        $list_info->author->twitter_data = json_decode(get_user_meta(get_post_field('post_author', $list_info->id), $this->prefix . 'twitter_data', true));
                        $listed_users = $this->getUsersOnList($list_info->id);
                        if ('application/json' === $_SERVER['HTTP_ACCEPT']) {
                            // Send back a JSON response.
                            $response = new stdClass();
                            $response->list_info = $list_info;
                            $response->listed_users = $listed_users;
                            wp_send_json($response);
                        }
                        break;
                }
            }
        }
    }

    private function addList ($params = array()) {
        $post = array(
            'post_content' => $params['desc'],
            'post_title' => $params['name'],
            'post_status' => 'publish',
            'post_type' => $this->prefix . 'list',
            'post_author' => get_current_user_id(),
        );
        return wp_insert_post($post);
    }

    private function getUsersOnList ($list_id) {
        $keys = get_post_custom_keys($list_id);
        if (empty($keys)) {
            return array();
        }
        $metas = array();
        foreach ($keys as $k) {
            if (0 === strpos($k, $this->prefix . 'alert_')) {
                $metas[] = $k;
            }
        }
        $vals = array();
        foreach ($metas as $k) {
            $vals[] = get_post_meta($list_id, $k, true);
        }
        return $vals;
    }

    private function addAlert ($who, $where, $desc) {
        $alert_data = new stdClass();
        $alert_data->id = $who . time();
        $alert_data->twitter_data = $this->api->getTwitterDataFor($who);
        $alert_data->twitter_id = $alert_data->twitter_data->id_str;
        $alert_data->screen_name = $alert_data->twitter_data->screen_name;
        $alert_data->alerted_by = get_user_meta(get_current_user_id(), $this->prefix . 'twitter_id', true);
        $alert_data->alert_desc = $desc;
        $alert_data->creation_time = date('c', time());
        return add_post_meta($where, $this->prefix . 'alert_' . $who, $alert_data, true); // unique for now?
    }

    private function outputUserscript () {
        $raw_script_src = file_get_contents(plugin_dir_path(__FILE__) . 'userscript/predator-alert-tool-for-twitter.user.js');
        $script_src = str_replace(
            '__PAT_TWITTER_CLIENT_HOME_URL__',
            admin_url('admin-ajax.php'),
            $raw_script_src
        );
        $script_src = str_replace(
            '__PAT_TWITTER_CLIENT_NAMESPACE__',
            implode('.', array_reverse(explode('.', $_SERVER['HTTP_HOST']))),
            $script_src
        );
        $script_src = str_replace(
            '__PAT_TWITTER_CLIENT_INCLUDE_URL__',
            plugins_url('userscript', __FILE__),
            $script_src
        );

        header('Content-Type: application/javascript');
        print $script_src;
    }

    private function registerCustomPostType () {
        $options = get_option($this->prefix . 'settings');
        $labels = array(
            'name'               => __('PAT Twitter Lists', 'wp-pat-twitter'),
            'singular_name'      => __('PAT Twitter List', 'wp-pat-twitter')
        );
        $args = array(
            'labels' => $labels,
            'description' => __('A PAT Twitter list is a way to group alerts about dangerous, predatory, or harassing behavior on Twitter and share that information with other users.', 'wp-pat-twitter'),
            'supports' => array(
                'title',
                'editor',
                'author',
                'custom-fields'
            ),
            'publicly_queryable' => true
        );
        register_post_type($this->prefix . 'list', $args);
    }

    public function registerAdminMenu () {
        add_options_page(
            __('PAT Twitter Settings', 'wp-pat-twitter'),
            __('PAT Twitter Settings', 'wp-pat-twitter'),
            'manage_options',
            $this->prefix . 'settings',
            array($this, 'renderOptionsPage')
        );

        add_management_page(
            __('Download Predator Alert Tool for Twitter', 'wp-pat-twitter'),
            __('Install PAT Twitter', 'wp-pat-twitter'),
            'edit_posts',
            $this->prefix . 'download_userscript',
            array($this, 'renderDownloadUserscriptPage')
        );
    }

    /**
     * Writes the HTML for the options page, and each setting, as needed.
     */
    // TODO: Add contextual help menu to this page.
    public function renderOptionsPage () {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-pat-twitter'));
        }
        $options = get_option($this->prefix . 'settings');
?>
<h2><?php esc_html_e('Predator Alert Tool for Twitter Settings', 'wp-pat-twitter');?></h2>
<form method="post" action="options.php">
<?php settings_fields($this->prefix . 'settings');?>
<fieldset><legend><?php esc_html_e('Connection to Twitter', 'wp-pat-twitter');?></legend>
<table class="form-table" summary="<?php esc_attr_e('Required settings to connect to Twitter.', 'wp-pat-twitter');?>">
    <tbody>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>consumer_key"><?php esc_html_e('Twitter API key/OAuth consumer key', 'wp-pat-twitter');?></label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>consumer_key" name="<?php esc_attr_e($this->prefix);?>settings[consumer_key]" value="<?php esc_attr_e($options['consumer_key']);?>" placeholder="<?php esc_attr_e('Paste your API key here', 'wp-pat-twitter');?>" />
                <p class="description">
                    <?php esc_html_e('Your Twitter API key is also called your consumer key.', 'wp-pat-twitter');?>
                    <?php print sprintf(
                        esc_html__('If you need an API key, you can %s.', 'wp-pat-twitter'),
                        '<a href="https://apps.twitter.com/app/new" target="_blank" ' .
                        'title="' . __('Get an API key from Twitter by registering your WordPress blog as a new Twitter app.', 'wp-pat-twitter') . '">' .
                        __('create one here', 'wp-pat-twitter') . '</a>'
                    );?>
                    <?php esc_html_e('Be sure you grant "Read & write" permissions for your app after creating it.', 'wp-pat-twitter');?>
                </p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>consumer_secret"><?php esc_html_e('Twitter API secret/OAuth consumer secret', 'wp-pat-twitter');?></label>
            </th>
            <td>
                <input id="<?php esc_attr_e($this->prefix);?>consumer_secret" name="<?php esc_attr_e($this->prefix);?>settings[consumer_secret]" value="<?php esc_attr_e($options['consumer_secret']);?>" placeholder="<?php esc_attr_e('Paste your consumer secret here', 'wp-pat-twitter');?>" />
                <p class="description">
                    <?php esc_html_e('Your consumer secret is like your app password. Never share this with anyone.', 'wp-pat-twitter');?>
                </p>
            </td>
        </tr>
        <tr>
            <th>
                <label for="<?php esc_attr_e($this->prefix);?>debug">
                    <?php esc_html_e('Enable detailed debugging information?', 'wp-pat-twitter');?>
                </label>
            </th>
            <td>
                <input type="checkbox" <?php if (isset($options['debug'])) : print 'checked="checked"'; endif; ?> value="1" id="<?php esc_attr_e($this->prefix);?>debug" name="<?php esc_attr_e($this->prefix);?>settings[debug]" />
                <label for="<?php esc_attr_e($this->prefix);?>debug"><span class="description"><?php
        print sprintf(
            esc_html__('Turn this on only if you are experiencing problems using this plugin, or if you were told to do so by someone helping you fix a problem (or if you really know what you are doing). When enabled, extremely detailed technical information is displayed as a WordPress admin notice when you take actions. If you have also enabled WordPress\'s built-in debugging (%1$s) and debug log (%2$s) feature, additional information will be sent to a log file (%3$s). This file may contain sensitive information, so turn this off and erase the debug log file when you have resolved the issue.', 'wp-pat-twitter'),
            '<a href="https://codex.wordpress.org/Debugging_in_WordPress#WP_DEBUG"><code>WP_DEBUG</code></a>',
            '<a href="https://codex.wordpress.org/Debugging_in_WordPress#WP_DEBUG_LOG"><code>WP_DEBUG_LOG</code></a>',
            '<code>' . content_url() . '/debug.log' . '</code>'
        );
                ?></span></label>
            </td>
        </tr>
    </tbody>
</table>
<?php submit_button();?>
</form>
<?php
    }

    public function showUserProfile () {
?>
<h3 id="<?php print esc_attr($this->prefix)?>download-userscript"><?php esc_html_e('Predator Alert Tool for Twitter', 'wp-pat-twitter');?></h3>
<?php
        if ($this->currentUserHasTwitterAccess()) {
            $this->renderDownloadUserscriptButton();
        } else {
            $this->renderConnectToTwitterButton();
        }
    }

    private function currentUserHasTwitterAccess () {
        $access_token = get_user_meta(get_current_user_id(), $this->prefix . 'access_token', true);
        $access_token_secret = get_user_meta(get_current_user_id(), $this->prefix . 'access_token_secret', true);
        if (empty($access_token) || empty($access_token_secret)) {
            return false;
        } else {
            return true;
        }
    }

    public function renderDownloadUserscriptPage () {
?>
<h3 id="<?php print esc_attr($this->prefix)?>download-userscript"><?php esc_html_e('Predator Alert Tool for Twitter', 'wp-pat-twitter');?></h3>
<?php
        if ($this->currentUserHasTwitterAccess()) {
            $this->renderDownloadUserscriptButton();
        } else {
            $this->renderConnectToTwitterButton();
        }
    }

    private function renderConnectToTwitterButton () {
?>
<p><a href="<?php print wp_nonce_url(admin_url('profile.php?' . $this->prefix . 'oauth_authorize'), $this->prefix . 'oauth_authorize', $this->prefix . 'nonce');?>" class="button button-primary"><?php esc_html_e('Click here to connect to Twitter', 'wp-pat-twitter');?></a></p>
<?php
    }

    private function renderDownloadUserscriptButton () {
?>
<p>
    <a href="https://twitter.com/<?php print esc_attr(get_user_meta(get_current_user_id(), $this->prefix . 'twitter_screen_name', true))?>"><img src="<?php print esc_attr(get_user_meta(get_current_user_id(), $this->prefix . 'profile_image_url_https', true));?>" alt="@<?php print esc_attr(get_user_meta(get_current_user_id(), $this->prefix . 'twitter_screen_name', true));?>" /></a>
    <?php esc_html_e('Connected to Twitter!', 'wp-pat-twitter');?>
</p>
<p>
    <a href="<?php print wp_nonce_url('tools.php?', 'download_userscript', $this->prefix . 'nonce');?>&amp;page=predator-alert-tool-for-twitter.user.js" class="button button-primary"><?php esc_html_e('Click here to download and install Predator Alert Tool for Twitter in your browser.', 'wp-pat-twitter');?></a>
</p>
<?php
    }
}

$WP_PAT_Twitter = new WP_Pat_Twitter();
