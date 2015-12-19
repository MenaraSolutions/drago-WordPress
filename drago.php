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
 * Exit if absolute path is absent
 */
if (!defined('ABSPATH')) exit;
define( 'DRAGO__PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DRAGO__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once( DRAGO__PLUGIN_DIR . 'drago-widget.php' );

class Drago {

    // List of supported languages
    private $languages = [
        'en' => [ 'locale' => 'en_GB', 'endpoint' => 'en' ],
        'ru' => [ 'locale' => 'ru_RU', 'endpoint' => 'ru' ]
    ];

    private $prefixAPI = 'http://demo.drago.mn/api/v1/';
//    private $prefixAPI = 'http://dragoapi.loc:8080/api/v1/';
    private $key = null;
    private $sourceLanguage = 'en';

    const DRAGO_MAX_UPLOAD_SIZE = 8000000;
    const API_KEY_LEN = 36;
    const ENGINE_ID_WORDPRESS = 1;

    public function __construct() {
        register_activation_hook( __FILE__, array($this, 'activate'));
        register_deactivation_hook( __FILE__, array($this, 'deactivate'));

        // Links and language parsing
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('locale' , array($this, 'locale'));
        add_filter('the_permalink', array($this, 'append_query_string'));

        // Admin panel
        add_action('admin_menu', array($this, 'my_plugin_menu'));
        add_action('admin_init', array($this, 'my_plugin_settings'));

        // Plugin initialization
        add_action('init', array($this, 'add_endpoints'));

        // Scheduled tasks
        add_action('drago_check', array($this, 'checkForUpdates'));

        // Content modification (localisation) is happening here
        add_filter('the_content', array($this, 'callback'));
        add_filter('the_title', array($this, 'callback'));
        add_filter('the_excerpt', array($this, 'callback'));
        add_filter('widget_text', array($this, 'callback'));
        add_action('get_header', array($this, 'callback'));

        // Add language to all links
        //add_filter('the_permalink', [$this, 'add_lang_to_links']);
        add_filter('term_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('page_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('post_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('author_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('day_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('month_link', [$this, 'add_lang_to_links'], 10, 3);
        add_filter('year_link', [$this, 'add_lang_to_links'], 10, 3);

        // Our client key
        $this->key = get_option('drago_key');
    }

    /**
     * Attempt to find current language from endpoint
     *
     * @return int|string
     */
    public function getCurrentLanguage()
    {
        global $wp_query;

        foreach($this->languages as $key => $value) {
            if (isset($wp_query->query_vars['lang_' . $key]) && $wp_query->query_vars['lang_' . $key] === '') {
                return $key;
            }
        }

        return $this->sourceLanguage;
    }

    /**
     * @param $url
     * @return string
     */
    public function add_lang_to_links($url)
    {
        if ($this->getCurrentLanguage() != $this->sourceLanguage) {
            return $url . $this->getCurrentLanguage();
        } else {
            return $url;
        }
    }

    /**
     * Add extra parameter (language) to post links
     *
     * @param $url
     * @return string
     */
    public function append_query_string($url)
    {
        if (get_query_var('lang', false))
            $url = add_query_arg('lang', get_query_var('lang'), $url);

        return $url;
    }

    /**
     * Menu to appear in the sidebar in admin panel
     */
    public function my_plugin_menu()
    {
        add_options_page( 'Drago Settings', 'Drago Localisation', 'administrator', 'drago', array($this, 'my_plugin_settings_page'));
    }

    /**
     * Add endpoints (rewrites) to all existing pages (links) for languages other than original
     */
    public function add_endpoints()
    {
        foreach ($this->languages as $key => $value) {
            if ($key != $this->sourceLanguage) {
                add_rewrite_endpoint($value['endpoint'], EP_ALL, 'lang_' . $key);
            }
        }
    }

    private function translatePost($post)
    {
        if (!empty($post->ID)) {
            $post_meta = get_post_meta($post->ID, 'drago', true);
            if (!empty($post_meta)) {
                $post_meta = json_decode($post_meta);
                $varname = 'content_' . $this->getCurrentLanguage();
                if (!empty($post_meta->{$varname})) {
                    $post->post_title = $post_meta->{$varname}->title;
                    $post->post_content = $post_meta->{$varname}->content;
                }
            }
        }

        //$post->post_title = str_replace('Adasd asd', 'test' . $post->ID, $post->post_title);
    }

    /**
     * Method that modifies content of page – that does the actual translation
     *
     * @param $buffer
     * @return mixed
     */
    public function callback($buffer)
    {
        $this->translatePost($GLOBALS['post']);

        return $buffer;
    }

    /**
     * Settings page in WP Admin
     */
    public function my_plugin_settings_page()
    {
        $meta = $this->getMetaData();

        if (empty($meta['last_update'])) {
            $lastText = 'Never';
        } else {
            $lastText = date('jS F Y h:i:s A', $meta['last_update']['timestamp']) .  ' UTC, ';

            switch ($meta['last_update']['status']) {
                case 200:
                    $lastText .= 'successful';
                    break;

                case 404:
                    $lastText .= 'wrong key';
                    break;

                case 500:
                    $lastText .= 'server error';
                    break;

                case 504:
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

                <?php if ($meta['status']) {
                    echo '<p>Available languages: ' . implode(', ', $meta['extra_languages']) . '</p>';
                    echo '<p>Original language: ' . $meta['original_language'] . '</p>';
                } ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Project Key</th>
                        <td><input type="text" size="40" name="drago_key" value="<?php echo esc_attr( get_option('drago_key') ); ?>" /></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <p>
                Last text upload: <?php echo $lastText; ?>
                <button class="button">Force full re-upload</button>
            </p>

            <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/localizejs?rate=5#postform">
                <?php _e( 'Love Drago? Help spread the word by rating us 5★ on WordPress.org', 'drago' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Get Drago meta data from WP options
     */
    protected function getMetaData()
    {
        $data = get_option('drago_metadata');
        if (!$data) return [
            'last_texts_submit' => 0,
            'last_texts_received' => 0,
            'extra_languages' => [],
            'original_language' => null,
            'status' => false
        ];

        return json_decode($data, true);
    }

    /**
     * Update Drago meta data in the WP options
     *
     * @param $data
     */
    protected function setMetaData($data)
    {
        update_option('drago_metadata', json_encode($data));
    }

    /**
     * Cron task: submit our new content and fetch new translations from API
     */
    public function checkForUpdates()
    {
        global $wpdb;

        // Get meta data from WP
        $meta = $this->getMetaData();

        // Basic check for correct key length
        if (strlen($this->key) != self::API_KEY_LEN) {
            $meta['last_update'] = ['timestamp' => null, 'status' => 404];
            $this->setMetaData($meta);

            return false;
        }

        // Only publish if status is active
        if ($meta['status']) {
            $objectsToSubmit = array();

            // All published posts and pages
            $results = $wpdb->get_results('SELECT ID, post_title, post_type, post_content, UNIX_TIMESTAMP(post_date) as post_date,
          post_modified FROM wp_posts WHERE post_status = \'publish\' and
          (post_type=\'post\' or post_type=\'page\')');

            // Prepare a list of posts that require translations
            foreach ($results as $oneResult) {
                // Check last submit time for this particular post
                //$lastUpdate = get_post_meta($oneResult->ID, 'drago_fetch_time', true);

                // If it was submitted later than last local modification – no work to do
                if ($meta['last_texts_submit'] >= strtotime($oneResult->post_modified)) {
                    // Nothing to do
                } else {
                    $objectsToSubmit[] = [
                        'id'            => $oneResult->ID,
                        'title'         => $oneResult->post_title,
                        'content'       => [
                                'title' => $oneResult->post_title,
                                'content' => $oneResult->post_content,
                                'type' => $oneResult->post_type
                                ],
                        'created_at'    => $oneResult->post_date,
                        'updated_at'    => strtotime($oneResult->post_modified)
                    ];
                }
            }
        }

        // Submit the list to Drago
        $postData = http_build_query(
            array(
                'posts'     => !isset($objectsToSubmit) ? [] : $objectsToSubmit,
                'min_ts'    => empty($meta['last_texts_received']) ? 0 : ($meta['last_texts_received'] + 1)
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
        $jsonInput = file_get_contents($this->prefixAPI . $this->key . '/texts-batch', false, $context);

        // Looks like a network error
        if (empty($jsonInput)) {
            $meta['last_update'] = ['timestamp' => time(), 'status' => 504];
            $this->setMetaData($meta);
            return false;
        }

        // Looks like a server error
        $objectOutput = json_decode($jsonInput);
        if ($objectOutput->code != 200) {
            $meta['last_update'] = ['timestamp' => time(), 'status' => $objectOutput->code];
            $this->setMetaData($meta);
            return false;
        }

        // Only save the language once (for now)
        if (empty($meta['original_language'])) $meta['original_language'] = $objectOutput->meta->website->original_language;
        $meta['status'] = $objectOutput->meta->website->status;
        $meta['extra_languages'] = $objectOutput->meta->website->extra_languages;
        $meta['last_texts_submit'] = $objectOutput->meta->website->last_texts_submit;

        // Temporary hard code
        $language = 'ru';

        if (!empty($objectOutput->data)) {
            foreach($objectOutput->data as $oneObject) {
                if (!empty($oneObject->translations->{$language})) {
                    $pageMeta = [
                        'fetch_time' => time(),
                        'content_' . $language => $oneObject->translations->{$language}
                    ];
                    update_post_meta($oneObject->id, 'drago', wp_slash(json_encode($pageMeta)));
                }
            }

            $meta['last_texts_received'] = $objectOutput->meta->timestamp;
        }

        $meta['last_update'] = ['timestamp' => time(), 'status' => 200];
        $this->setMetaData($meta);
    }

    /**
     * Set API key for the website
     */
    public function my_plugin_settings()
    {
        register_setting('my-plugin-settings-group', 'drago_key');
    }

    /**
     * When the plugin is activated – flush existing rewrite rules
     */
    public function activate()
    {
        global $wp_rewrite;

        // Install new endpoints
        $this->add_endpoints();

        // Force rewrite rules regeneration
        $wp_rewrite->flush_rules(); // force call to generate_rewrite_rules()

        // Check for new content
        wp_schedule_event(time(), 'hourly', 'drago_check');
    }

    /**
     * When the plugin is deactivated – delete our rewrite rules filter and flush rules
     */
    public function deactivate()
    {
        global $wp_rewrite;

        // Flush rewrite rules
        $wp_rewrite->flush_rules();

        // This actually doesn't work, and supposedly there is no nice solution?
        // Give user an advice to visit permalinks page?
        remove_action('init', array($this, 'add_endpoints'));

        // Remove cron task
        wp_clear_scheduled_hook('drago_check');
    }

    /**
     * Override Wordpress' choice of locale
     *
     * @param $locale
     * @return string
     */
    public function locale($locale)
    {
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
    public function query_vars($public_query_vars)
    {
        foreach($this->languages as $key => $value) {
            if ($key != $this->sourceLanguage) $public_query_vars[] = 'lang_' . $key;
        }

        return $public_query_vars;
    }
}

$drago = new Drago();