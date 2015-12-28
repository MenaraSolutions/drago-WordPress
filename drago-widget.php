<?php
/**
 * @package Drago
 */
class Drago_Widget extends WP_Widget {

    protected $meta;

    protected $languageTitles = [
        'en' => 'English',
        'es' => 'Español',
        'ru' => 'По-русски',
        'zh' => '中国普通话'
    ];

    protected $currentUrl;
    protected $currentLanguage;

    function __construct()
    {
        global $drago, $wp;

        load_plugin_textdomain( 'drago' );

        parent::__construct(
            'drago_widget',
            __( 'Drago Widget' , 'drago'),
            array( 'description' => __( 'Language selector for your website' , 'drago') )
        );

        if (is_active_widget( false, false, $this->id_base)) {
            add_action('wp_head', array( $this, 'css'));
        }

        if (isset($drago))
            $this->meta = $drago->getMetaData();

        $this->currentUrl = home_url(add_query_arg(NULL, NULL));
    }

    /**
     * ToDo: add ugly links support
     *
     * @param $language
     *
     * @return string
     */
    protected function getLink($language)
    {
        if (!$this->meta['status']) return null;

        if ($this->currentLanguage != $this->meta['original_language']) {
            $pathElements = explode('/', trim(parse_url($this->currentUrl, PHP_URL_PATH), '/'));
            array_pop($pathElements);
            $path = implode('/', $pathElements);

            $nudeUrl = parse_url($this->currentUrl, PHP_URL_SCHEME) . '://' .
                parse_url($this->currentUrl, PHP_URL_HOST) .
                ':' . parse_url($this->currentUrl, PHP_URL_PORT) . '/' .
                $path .
                parse_url($this->currentUrl, PHP_URL_QUERY);
        } else {
            $nudeUrl = $this->currentUrl;
        }

        if ($language == $this->meta['original_language']) return $nudeUrl . '/';
        if ($nudeUrl[strlen($nudeUrl) - 1] != '/') $nudeUrl .= '/';

        return $nudeUrl . $language . '/';
    }

    /**
     *
     */
    function css() {
        ?>

        <style type="text/css">
            /* basic menu code 1.0 */
            .language-dropmenu ul {
                margin: 0;
                padding: 0;
            }

            .language-dropmenu li {
                float: left;
                list-style: none;
                background: #eeeeee;
                position: relative;
            }

            .language-dropmenu li ul li {
                float: none;
            }

            .language-dropmenu li ul {
                display: none;
                position: absolute;
                left: 0; top: 100%;
                width: 10em;
                box-shadow: 0 0 2px rgba(0,0,0,0.2);
                z-index: 999;
            }

            .language-dropmenu li a {
                text-decoration: none;
                display: block;
                padding: 0.5em 1em;
            }

            .language-dropmenu li:hover > ul {
                display: block;
            }

            .language-dropmenu li:hover {
                background: #d8d8d8;
            }

            .language-dropmenu:after {
                content: "";
                display: table;
                clear: both;
            }
        </style>

        <?php
    }

    function form($instance) {
        if ($instance) {
            $title = $instance['title'];
        } else {
            $title = __( 'Our site in' , 'drago');
        }
        ?>

        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php esc_html_e( 'Title:' , 'drago'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>

        <?php
    }

    function update($new_instance, $old_instance)
    {
        $instance['title'] = strip_tags($new_instance['title']);
        return $instance;
    }

    function widget($args, $instance)
    {
        global $drago;

        $this->currentLanguage = $drago->getCurrentLanguage();

        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'];
            echo esc_html($instance['title']);
            echo $args['after_title'];
        }

        if ($this->meta['status']) {
            ?>

            <ul class="language-dropmenu">
                <li class="dir"><a href="#"><?php echo $this->languageTitles[$this->currentLanguage]; ?></a>
                    <ul>
                        <?php
                        foreach (array_merge($this->meta['extra_languages'], [$this->meta['original_language']]) as $language) {
                        if ($language !== $this->currentLanguage) {
                        ?>
                        <li class="dir"><a href="<?php echo $this->getLink($language); ?>"><?php echo $this->languageTitles[$language]; ?></a></li>
                        <?php } } ?>
                    </ul>
                </li>
            </ul>

            <?php
        }

        echo $args['after_widget'];
    }
}

function drago_register_widgets()
{
    register_widget( 'Drago_Widget' );
}

add_action( 'widgets_init', 'drago_register_widgets' );
