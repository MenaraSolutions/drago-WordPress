<?php
/*
Plugin Name: Drago
Plugin URI: https://www.drago.mn
Description: Wordpress localization
Version: 2015.09.06
Author: Menara Solutions Pty Ltd < help@menara.com.au >
Author URI: https://www.menara.com.au
*/

/**
 * Exit if absolute path
 */
if (!defined('ABSPATH')) exit;

class Drago {

    // List of supported languages
    private $languages = [
        'en' => [ 'locale' => 'en_GB' ],
        'ru' => [ 'locale' => 'ru_RU' ]
    ];

    private $key = null;
    private $currentLanguage = null;

    const DRAGO_MAX_UPLOAD_SIZE = 8000000;

    function __construct() {
        register_activation_hook( __FILE__, array($this, 'activate'));
        register_deactivation_hook( __FILE__, array($this, 'deactivate'));

        // Links and language
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('locale' , array($this, 'locale'));
        add_filter('the_permalink', array($this, 'append_query_string'));
        add_filter('request', array($this, 'set_query_var'));

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
        //add_action('get_header', array($this, 'buffer_start'));
        //add_action('wp_footer', array($this, 'buffer_end'));

        // Our client key
        $this->key = get_option('drago_key');
    }

    /**
     * @param $vars
     * @return mixed
     */
    function set_query_var($vars) {
        if (isset($vars['lang']) && $vars['lang'] === '') {
            $vars['lang'] = 'ru';
        }

        return $vars;
    }

    /**
     * Add extra parameter (language) to post links
     *
     * @param $url
     * @return string
     */
    function append_query_string($url) {
        if (get_query_var('lang', false))
            $url = add_query_arg('lang', get_query_var('lang'), $url);

        return $url;
    }

    /**
     * Menu to appear in the sidebar in admin panel
     */
    function my_plugin_menu() {
        add_menu_page('Drago Settings', 'Drago Plugin Settings', 'administrator', 'drago', array($this, 'my_plugin_settings_page'), 'dashicons-admin-generic');
    }

    /**
     * Add endpoints (rewrites) to all existing pages (links)
     */
    function add_endpoints() {
        add_rewrite_endpoint('ru', EP_ALL, 'lang'); // Adds endpoint to all links
    }

    function translatePost($post) {
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
    function callback($buffer) {
        // modify buffer here, and then return the updated code
        //$buffer = str_replace('Adasd asd', get_the_ID(), $buffer);
        $this->translatePost($GLOBALS['post']);

        return $buffer;
    }

    function buffer_start() {
        ob_start(array($this, "callback"));
    }

    function buffer_end() {
        ob_end_flush();
    }

    // SETTINGS PAGE SETUP
    function my_plugin_settings_page() {
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

            <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/localizejs?rate=5#postform">
                <?php _e( 'Love Localize.js? Help spread the word by rating us 5★ on WordPress.org', 'drago' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Cron task
     */
    function checkForUpdates()
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

        // Submit the list to Drago
        $postData = http_build_query(
            array(
                'posts'     => $objectsToSubmit
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
        $jsonInput = file_get_contents('http://dragoapi.loc:8080/api/v1/text/' . $this->key, false, $context);
        if (empty($jsonInput)) return false;

        $objectOutput = json_decode($jsonInput);
        if ($objectOutput->code != 200) return false;

        $language = 'ru';

        foreach($objectOutput->data as $oneObject) {
            if (!empty($oneObject->translations->{$language})) {
                update_post_meta($oneObject->id, 'drago_fetch_time', time());
                update_post_meta($oneObject->id, 'drago_' . $language, $oneObject->translations->{$language});
            }
        }
    }

    /**
     * Set API key for the website
     */
    function my_plugin_settings() {
        register_setting('my-plugin-settings-group', 'drago_key');
    }

    /**
     * When the plugin is activated – flush existing rewrite rules
     */
    function activate() {
        global $wp_rewrite;

        $wp_rewrite->flush_rules(); // force call to generate_rewrite_rules()

        // Check for new content
        wp_schedule_event(time(), 'hourly', 'drago_check');

        // Initial check
        //$this->checkForUpdates();
    }

    /**
     * When the plugin is deactivated – delete our rewrite rules filter and flush rules
     */
    function deactivate() {
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
    function locale($locale) {
        global $wp_locale;

        //echo 'lang = '.get_query_var('lang');
        //return $locale;
        // If we got a language parameter and if that language is supported by Drago
        //if (isset($wp_query->query_vars['ru'])) {
        if (get_query_var('lang', false) && array_key_exists(get_query_var('lang', false), $this->languages)) {
            $new_locale = $this->languages[get_query_var('lang')]['locale'];

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

    /*
    function add_rewrite_rules() {
        global $wp_rewrite;

        $rules = array(
            //'([a-z]{2})/category/(.+?)/?$'                              => 'index.php?lang_code=$matches[1]&except_category_naame=$matches[2]',
            //'except/category/(.+?)/feed/(feed|rdf|rss|rss2|atom)/?$'  => 'index.php?lang_code=ru&except_category_name=$matches[1]&feed=$matches[2]',
            //'except/category/(.+?)/(feed|rdf|rss|rss2|atom)/?$'       => 'index.php?lang_code=ru&except_category_name=$matches[1]&feed=$matches[2]',
            //'except/category/(.+?)/page/?([0-9]{1,})/?$'              => 'index.php?lang_code=ru&except_category_name=$matches[1]&paged=$matches[2]',
        );

        $wp_rewrite->rules = $rules + (array)$wp_rewrite->rules;
    } */

    /**
     * Add one extra variable to existing list
     *
     * @param $public_query_vars
     * @return array
     */
    function query_vars($public_query_vars) {
        $public_query_vars[] = 'lang';

        return $public_query_vars;
    }
}

$drago = new Drago();