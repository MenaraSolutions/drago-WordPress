<?php
/*
Plugin Name: Drago
Plugin URI: http://wordpress.org/plugins/drago/
Description: Easy WordPress localization
Version: 2015.09.26
Author: Menara Solutions Pty Ltd < help@menara.com.au >
Author URI: https://www.menara.com.au
Text Domain: drago
*/

/**
 * Exit if absolute path
 */
if (!defined('ABSPATH')) exit;

class Drago {

    // List of supported languages
    private $languages = [
        'en' => [ 'locale' => 'en_GB', 'endpoint' => 'en' ],
        'ru' => [ 'locale' => 'ru_RU', 'endpoint' => 'ru' ]
    ];

    private $key = null;
    private $sourceLanguage = 'en';

    const DRAGO_MAX_UPLOAD_SIZE = 8000000;

    public function __construct() {
        register_activation_hook( __FILE__, array($this, 'activate'));
        register_deactivation_hook( __FILE__, array($this, 'deactivate'));

        // Links and language
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('locale' , array($this, 'locale'));
        add_filter('the_permalink', array($this, 'append_query_string'));

        // Admin panel
        add_action('admin_menu', array($this, 'my_plugin_menu'));
        add_action('admin_init', array($this, 'my_plugin_settings'));
        add_action('init', array($this, 'add_endpoints'));
        add_action('drago_check', array($this, 'checkForUpdates'));

        // Content modification
        add_filter('the_content', array($this, 'callback'));
        add_filter('the_title', array($this, 'callback'));
        add_filter('the_excerpt', array($this, 'callback'));
        add_filter('widget_text', array($this, 'callback'));
        add_action('get_header', array($this, 'callback'));

        // Our client key
        $this->key = get_option('drago_key');
    }

    /**
     * Attempt to find current language from endpoint
     *
     * @return int|string
     */
    public function getCurrentLanguage() {
        global $wp_query;

        foreach($this->languages as $key => $value) {
            if (isset($wp_query->query_vars['lang_' . $key]) && $wp_query->query_vars['lang_' . $key] === '') {
                return $key;
            }
        }

        return $this->sourceLanguage;
    }

    /**
     * Add extra parameter (language) to post links
     *
     * @param $url
     * @return string
     */
    public function append_query_string($url) {
        if (get_query_var('lang', false))
            $url = add_query_arg('lang', get_query_var('lang'), $url);

        return $url;
    }

    /**
     * Menu to appear in the sidebar in admin panel
     */
    public function my_plugin_menu() {
        //add_menu_page('Drago Settings', 'Drago Plugin Settings', 'administrator', 'drago', array($this, 'my_plugin_settings_page'), 'dashicons-admin-generic');
        add_options_page( 'Drago Settings', 'Drago Localisation', 'administrator', 'drago', array($this, 'my_plugin_settings_page'));
    }

    /**
     * Add endpoints (rewrites) to all existing pages (links) for languages other than original
     */
    public function add_endpoints() {
        foreach ($this->languages as $key => $value) {
            if ($key != $this->sourceLanguage) {
                add_rewrite_endpoint($value['endpoint'], EP_ALL, 'lang_' . $key);
            }
        }
    }

    private function translatePost($post) {
        if (!empty($post->ID)) {
            $new_title = get_post_meta($post->ID, 'drago_local_ru', true);
            if (!empty($new_title)) $post->post_title = $new_title;
        }

        //$post->post_title = str_replace('Adasd asd', 'test' . $post->ID, $post->post_title);
    }

    /**
     * Method that modified content of page – that does the actual translation
     *
     * @param $buffer
     * @return mixed
     */
    public function callback($buffer) {
        $this->translatePost($GLOBALS['post']);

        return $buffer;
    }

    /**
     * Settings page in WP Admin
     */
    public function my_plugin_settings_page() {
        $lastSubmit = get_option('drago_last_update');
        if (empty($lastSubmit)) {
            $lastText = 'Never';
        } else {
            $lastSubmit = json_decode($lastSubmit);
            $lastText = date('jS F Y h:i:s A', $lastSubmit->timestamp) .  ' UTC, ';

            switch ($lastSubmit->status) {
                case 200:
                    $lastText .= 'successful';
                    break;

                case 404:
                    $lastText .= 'server error';
                    break;

                case 500:
                    $lastText .= 'network error';
                    break;

                default:
                    $lastText .= 'result unknown';
            }
        }
        ?>
        <div class="wrap">
            <h2>Drago Project Details</h2>

            <p> Login to your <a target="_blank" href="https://www.drago.mn/">Drago</a> Dashboard to get your project key</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'my-plugin-settings-group' ); ?>
                <?php do_settings_sections( 'my-plugin-settings-group' ); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Project Key</th>
                        <td><input type="text" name="drago_key" value="<?php echo esc_attr( get_option('drago_key') ); ?>" /></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <p>Last text upload: <?php echo $lastText; ?></p>

            <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/localizejs?rate=5#postform">
                <?php _e( 'Love Drago? Help spread the word by rating us 5★ on WordPress.org', 'drago' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Cron task
     */
    public function checkForUpdates()
    {
        global $wpdb;

        // All published posts and pages
        $results = $wpdb->get_results('SELECT ID, post_title, post_content, UNIX_TIMESTAMP(post_date) as post_date, UNIX_TIMESTAMP(post_modified) as post_modified FROM wp_posts WHERE post_status = \'publish\' and
          (post_type=\'post\' or post_type=\'page\')');

        $objectsToSubmit = array();

        // Prepare a list of posts that require translations
        foreach ($results as $oneResult) {
            $lastUpdate = get_post_meta($oneResult->ID, 'drago_fetch_time', true);

            if (!empty($lastUpdate) && $lastUpdate >= $oneResult->post_modified) {
                // Nothing to do
            } else {
                $objectsToSubmit[] = [
                        'id'            => $oneResult->ID,
                        'title'         => $oneResult->post_title,
                        'content'       => $oneResult->post_content,
                        'created_at'    => $oneResult->post_date,
                        'updated_at'    => $oneResult->post_modified
                ];
            }
        }

        if (count($objectsToSubmit) == 0) return false;

        if ($lastUpdate = get_option('drago_last_update')) {
            $lastUpdateTimestamp = json_decode($lastUpdate)->timestamp;
        } else {
            $lastUpdateTimestamp = 0;
        }

        // Submit the list to Drago
        $postData = http_build_query(
            array(
                'posts'     => $objectsToSubmit,
                'min_ts'    => $lastUpdateTimestamp
            )
        );

        $opts = array('http' =>
            array(
                'method'  => 'PUT',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $postData
            )
        );

        $context  = stream_context_create($opts);
        $jsonInput = file_get_contents('http://dragoapi.loc:8080/api/v1/' . $this->key . '/texts/1', false, $context);

        if (empty($jsonInput)) {
            update_option('drago_last_update', json_encode(['timestamp' => time(), 'status' => 500]));
            return false;
        }

        $objectOutput = json_decode($jsonInput);
        if ($objectOutput->code != 200) {
            update_option('drago_last_update', json_encode(['timestamp' => time(), 'status' => 404]));
            return false;
        }

        $language = 'ru';

        foreach($objectOutput->data as $oneObject) {
            if (!empty($oneObject->translations->{$language})) {
                update_post_meta($oneObject->id, 'drago_fetch_time', time());
                update_post_meta($oneObject->id, 'drago_' . $language, $oneObject->translations->{$language});
            }
        }

        update_option('drago_last_update', json_encode(['timestamp' => time(), 'status' => 200]));
    }

    /**
     * Set API key for the website
     */
    public function my_plugin_settings() {
        register_setting('my-plugin-settings-group', 'drago_key');
    }

    /**
     * When the plugin is activated – flush existing rewrite rules
     */
    public function activate() {
        global $wp_rewrite;

        $wp_rewrite->flush_rules(); // force call to generate_rewrite_rules()

        // Check for new content
        wp_schedule_event(time(), 'hourly', 'drago_check');
    }

    /**
     * When the plugin is deactivated – delete our rewrite rules filter and flush rules
     */
    public function deactivate() {
        global $wp_rewrite;

        //remove_action( 'generate_rewrite_rules', array($this, 'add_rewrite_rules') );
        remove_action( 'init', array($this, 'add_endpoints'));

        // Flush rewrite rules
        $wp_rewrite->flush_rules();

        // Remove cron task
        wp_clear_scheduled_hook('drago_check');
    }

    /**
     * Override Wordpress' choice of locale
     *
     * @param $locale
     * @return string
     */
    public function locale($locale) {
        global $wp_locale;

        // If we got a language parameter and if that language is supported by Drago
        if ($this->getCurrentLanguage() != $this->sourceLanguage) {
            $new_locale = $this->languages[$this->getCurrentLanguage()]['locale'];

            // Only reload the text domains and locale if there has been a change
            if ($new_locale != $locale) {
                // To ensure WP messages are displayed in new language
                load_default_textdomain($new_locale);
                load_textdomain('default', 'admin-' . $new_locale);

                // To ensure dates are displayed in new language
                $wp_locale->init();

                return $new_locale;
            }
        }

        return $locale;
    }

    /**
     * Add extra variables to existing list
     *
     * @param $public_query_vars
     * @return array
     */
    public function query_vars($public_query_vars) {
        foreach($this->languages as $key => $value) {
            if ($key != $this->sourceLanguage) $public_query_vars[] = 'lang_' . $key;
        }

        return $public_query_vars;
    }
}

$drago = new Drago();