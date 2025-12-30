<?php
/*
Plugin Name: RESTful Content Syndication
Plugin URI: https://mediarealm.com.au/
Description: Import content from the Wordpress REST API on another Wordpress site
Version: 1.7.0
Author: Media Realm
Author URI: https://www.mediarealm.com.au/
License: GPL2
RESTful Syndication is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
RESTful Syndication is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with RESTful Syndication. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

class RESTfulSyndication {

    public $settings_prefix = "restful-syndication_";
    public $errors_prefix = "RESTful Syndication ERROR: ";
    public $settings = array(
        "site_url" => array(
            "title" => "Master Site URL",
            "type" => "text",
        ),
        "auth_username" => array(
            "title" => "Username for Master Site",
            "type" => "text",
        ),
        "auth_password" => array(
            "title" => "Password for Master Site",
            "type" => "password",
        ),
        "default_status" => array(
            "title" => "Default Post Status",
            "type" => "select",
            "options" => array(
                "draft" => 'Draft',
                "pending" => 'Pending',
                "publish" => 'Published',
            )
        ),
        "default_author" => array(
            "title" => "Default Post Author",
            "type" => "select",
            "options" => array()
        ),
        "earliest_post_date" => array(
            "title" => "Do not import posts earlier than this date (format: YYYY-MM-DD HH:MM:SS)",
            "type" => "text",
        ),
        "create_tags" => array(
            "title" => "Create Missing Tags?",
            "type" => "checkbox",
        ),
        "create_categories" => array(
            "title" => "Create Missing Categories?",
            "type" => "checkbox",
        ),
        "automatic_category" => array(
            "title" => "Add specific category to every incoming post",
            "type" => "select",
            "options" => array(0 => '')
        ),
        "yoast_noindex" => array(
            "title" => "Always Enable Yoast No-Index?",
            "type" => "checkbox",
        ),
        "image_embed_size" => array(
            "title" => "Embedded Image Size",
            "type" => "select",
            "options" => array()
        ),
        "purge_media_days" => array(
            "title" => "Auto Purge Media After X Days",
            "type" => "text",
            "default" => "",
        ),
        "purge_posts_days" => array(
            "title" => "Auto Purge Posts After X Days",
            "type" => "text",
            "default" => "",
        ),
        "ignore_posts_containing" => array(
            "title" => "Ignore Incoming Posts Containing This Text (one entry per line)",
            "type" => "textarea",
        ),
        "remote_push_key" => array(
            "title" => "Remote Content Push - Secure Key",
            "type" => "readonly",
        ),
        "content_raw_unauth" => array(
            "title" => "Always expose Raw Block Markup for Unauthenticated Users (this could pose a security risk)",
            "type" => "checkbox",
        ),
        "remote_push_domains" => array(
            "title" => "Remote Content Push - Allowed Domains (one per line)",
            "type" => "textarea",
        ),
        
    );

    public $errors_logged = array();

    private $push_remote_auth_key = null;
    private $push_remote_domain = null;

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('restful-syndication_cron', array($this, 'syndicate'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));

        add_action('rest_api_init', array($this, 'rest_api_raw_post_content'));

        add_shortcode('restful_syndication_iframe', array($this, 'sc_iframe'));

        add_action('rest_api_init', function () {
            register_rest_route('restful-syndication/v1', '/push/', array(
                    'methods' => 'POST',
                    'callback' => array($this, 'push_receive'),
            ));
        });

        if(function_exists('is_plugin_active') && (is_plugin_active('advanced-custom-fields/acf.php') || is_plugin_active('advanced-custom-fields-pro/acf.php'))) {
            if(apply_filters('restful_syndication_allow_image_credit_fields', true) === true) {
                $this->settings['fields_image_credits'] = array(
                    "title" => "Enable Image Credit Fields",
                    "type" => "checkbox",
                );

                $options = get_option($this->settings_prefix . 'settings');
                if(isset($options['fields_image_credits']) && $options['fields_image_credits'] == "true") {
                    add_action('acf/include_fields',                    array($this, 'fields_image_credits_acf'));
                    add_filter('restful_syndication_api_headers',       array($this, 'fields_image_credits_api_header'));
                }
            }
            
        }
    }

    public function activate() {
        wp_schedule_event(time(), 'fifteen_minutes', 'restful-syndication_cron');
    }

    private function log($log)  {
        if(is_array($log) || is_object($log)) {
           error_log($this->errors_prefix . print_r($log, true));
           $this->errors_logged[] = print_r($log, true);
        } else {
           error_log($this->errors_prefix . $log);
           $this->errors_logged[] = $log;
        }
     }

    public function admin_menu() {
        add_submenu_page('options-general.php', "RESTful Syndication", "RESTful Syndication", 'manage_options', 'restful-syndication', array($this, 'options_page'));
    }

    public function settings_init() { 

        register_setting($this->settings_prefix, $this->settings_prefix . 'settings');

        add_settings_section(
            $this->settings_prefix . 'section',
            __('Syndication Settings', $this->settings_prefix),
            false,
            $this->settings_prefix
        );

        // Setup list of authors
        $users = get_users(array('fields' => array('ID', 'display_name')));
        foreach($users as $user_id) {
            $this->settings['default_author']['options'][$user_id->ID] = $user_id->display_name;
        }

        // Add a list of categories
        $categories = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
        ));
        foreach($categories as $category) {
            $this->settings['automatic_category']['options'][$category->term_id] = $category->name;
        }

        // Add the list of image sizes used on this site
        foreach(get_intermediate_image_sizes() as $size) {
            $this->settings['image_embed_size']['options'][$size] = $size;
        }

        foreach($this->settings as $settingId => $setting) {
            add_settings_field( 
                $this->settings_prefix . $settingId, 
                __($setting['title'], $this->settings_prefix),
                array($this, 'setting_render'),
                $this->settings_prefix,
                $this->settings_prefix . 'section',
                array(
                    "field_key" => $settingId
                )
            );
        }

        if(!wp_get_schedule('restful-syndication_cron')) {
            // The scheduled task has disappeared - add it again
            $this->activate();
        }

    }

    public function setting_render($args = array()) {
        if(!isset($this->settings[$args['field_key']])) {
            echo "Field not found:" . $args['field_key'];
        }

        $field = $this->settings[$args['field_key']];
        $options = get_option($this->settings_prefix . 'settings');

        if(isset($options[$args['field_key']])) {
            $value = $options[$args['field_key']];
        } else {
            $value = "";
        }

        if($args['field_key'] == 'remote_push_key' && empty($value)) {
            $value = $this->random_str(32);
        }

        if($field['type'] == "text") {
            // Text fields
            echo '<input type="text" name="restful-syndication_settings['.esc_attr($args['field_key']).']" value="'.esc_attr($value).'" />';
        }  elseif($field['type'] == "textarea") {
            // Textarea fields
            echo '<textarea name="restful-syndication_settings['.esc_attr($args['field_key']).']">'.esc_html($value).'</textarea>';
        } elseif($field['type'] == "password") {
            // Password fields
            echo '<input type="password" name="restful-syndication_settings['.esc_attr($args['field_key']).']" value="'.esc_attr($value).'" />';
        } elseif($field['type'] == "select") {
            // Select / drop-down fields
            echo '<select name="restful-syndication_settings['.$args['field_key'].']">';
            foreach($field['options'] as $selectValue => $name) {
                echo '<option value="'.esc_attr($selectValue).'" '.($value == $selectValue ? "selected" : "").'>'.esc_html($name).'</option>';
            }
            echo '</select>';
        } elseif($field['type'] == "checkbox") {
            // Checkbox fields
            echo '<input type="checkbox" name="restful-syndication_settings['.esc_attr($args['field_key']).']" value="true" '.("true" == $value ? "checked" : "").' />';
        } elseif($field['type'] == "readonly") {
            // Readonly field
            echo '<input type="text" name="restful-syndication_settings['.esc_attr($args['field_key']).']" value="'.esc_attr($value).'" readonly />';
        }
    }

    public function options_page() {
        echo '<form action="options.php" method="POST">';
        echo '<h1>RESTful Syndication <span style="font-size: 0.6em; font-weight: normal;">by <a href="https://mediarealm.com.au/" target="_blank">Media Realm</a></span></h1>';

        settings_fields($this->settings_prefix);
        do_settings_sections($this->settings_prefix);
        submit_button();

        echo '</form>';

        // Display a history of successful syndication runs
        $runs = get_option($this->settings_prefix . 'history', array());
        $runs_delete = get_option($this->settings_prefix . 'history_delete', array());
        $runs_delete_media = get_option($this->settings_prefix . 'history_delete_media', array());
        $last_attempt = get_option($this->settings_prefix . 'last_attempt', 0);
        krsort($runs);
        krsort($runs_delete);
        krsort($runs_delete_media);

        echo '<h2>Syndication History</h2>';
        echo '<p>Last Attempted Run: '.($last_attempt > 0 ? date("Y-m-d H:i:s", $last_attempt) : "NEVER").'</p>';
        echo '<p>Successful Runs:</p>';
        echo '<ul>';
        $runCount = 0;
        foreach($runs as $time => $count) {
            echo '<li>'.date("Y-m-d H:i:s", $time).': '.$count.' '.($count == 1 ? "post" : "posts").' ingested from master site</li>';
            $runCount++;

            if($runCount > 10)
                break;
        }
        if(count($runs) === 0) {
            echo '<li>No posts have ever been ingested by this plugin</li>';
        }
        $runCount = 0;
        foreach($runs_delete as $time => $count) {
            echo '<li>'.date("Y-m-d H:i:s", $time).': '.$count.' '.($count == 1 ? "post" : "posts").' deleted</li>';
            $runCount++;

            if($runCount > 10)
                break;
        }
        $runCount = 0;
        foreach($runs_delete_media as $time => $count) {
            echo '<li>'.date("Y-m-d H:i:s", $time).': '.$count.' media files deleted</li>';
            $runCount++;

            if($runCount > 10)
                break;
        }
        echo '</ul>';


        echo '<h2>Ingest Posts Now</h2>';
        echo "<p>This plugin uses WP-Cron to automatically ingest posts every 15 minutes. If you're impatient, you can do it now using the button below.</p>";
        if(isset($_GET['ingestnow']) && $_GET['ingestnow'] == "true") {

            // Verify nonce
            check_admin_referer('restful_syndication_ingest');

            echo "<p><strong>Attempting syndication now...</strong></p>";
            $this->syndicate();

            if(count($this->errors_logged) > 0) {
                echo '<p>Errors Logged During This Ingestion:</p>';
                echo '<ul>';
                foreach($this->errors_logged as $error) {
                    echo '<li>'.esc_html($error).'</li>';
                }
                echo '</ul>';

            }

            echo '<p><strong>Syndication complete!</strong></p>';
        } else {
            echo '<p class="submit"><a href="'.wp_nonce_url('?page='.$_GET['page'].'&ingestnow=true', 'restful_syndication_ingest').'" class="button button-primary">Ingest Posts Now</a></p>';
        }
        

    }

    private function rest_fetch($url, $full = false) {
        $options = get_option($this->settings_prefix . 'settings');

        if($full === false && (!isset($options['site_url']) || empty($options['site_url']) || filter_var($options['site_url'], FILTER_VALIDATE_URL) === FALSE)) {
            $this->log("Master Site URL not specified, or URL format invalid.");
            return;
        }

        $headers = array(
            'X-RESTful-Syndication-Version' => get_file_data(__FILE__, array('Version' => 'Version'), false)['Version'],
        );

        $headers = apply_filters('restful_syndication_api_headers', $headers);

        if(!empty($options['auth_username']) && !empty($options['auth_password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($options['auth_username'] . ':' . $options['auth_password']);
        }

        if($full === true && $this->push_remote_domain !== null && $this->push_remote_auth_key !== null && strpos($url, "restful_push_auth_key=") === false) {
            // Add the Push Auth Key back to the URL as a parameter for subsequent calls
            // This is needed for taxonomies, authors, images, etc.

            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                $url .= '&restful_push_auth_key=' . urlencode($this->push_remote_auth_key);
            } else {
                $url .= '?restful_push_auth_key=' . urlencode($this->push_remote_auth_key);
            }
        }

        $response = wp_remote_get(
            ($full === false ? $options['site_url'] : "") . $url,
            array(
                "headers" => $headers,
                "timeout" => 20,
            )
        );

        if(is_wp_error($response)) {
            $this->log("HTTP request failed when calling WP REST API. " . $response->get_error_message());
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if($data == false) {
            $this->log("rest_fetch() - JSON not decoded when fetching ".$url.".");
        }

        return $data;
    }

    public function syndicate() {
        // This function does the hard yards of fetching the content and ingesting it

        $posts = $this->rest_fetch('/wp-json/wp/v2/posts/?per_page=10');

        if(empty($posts)) {
            $this->log("Invalid response received from WP REST API when looking up latest posts.");
            return;
        }

        $options = get_option($this->settings_prefix . 'settings');

        if($options['create_categories'] == "true") {
            require_once(ABSPATH . '/wp-admin/includes/taxonomy.php');
        }

        $count = 0;
        $count_delete = 0;
        $count_delete_media = 0;

        // Loop over every post and create a post entry
        foreach($posts as $post) {
            $this->syndicate_one($post);
            $count++;
        }

        // Delete media older than a certain date
        if(is_numeric($options['purge_media_days']) && $options['purge_media_days'] > 7) {
            $count_delete_media += count($this->clean_media($options['purge_media_days'], 'post'));
        }

        // Delete posts older than a certain date
        if(is_numeric($options['purge_posts_days']) && $options['purge_posts_days'] > 7) {
            $count_delete += count($this->clean_posts($options['purge_posts_days'], 'post'));
        }

        if($count > 0) {
            $runs = get_option($this->settings_prefix . 'history', array());
            $runs[time()] = $count;
            update_option($this->settings_prefix . 'history', $runs, false);
        }

        if($count_delete > 0) {
            $runs = get_option($this->settings_prefix . 'history_delete', array());
            $runs[time()] = $count_delete;
            update_option($this->settings_prefix . 'history_delete', $runs, false);
        }

        if($count_delete_media > 0) {
            $runs = get_option($this->settings_prefix . 'history_delete_media', array());
            $runs[time()] = $count_delete_media;
            update_option($this->settings_prefix . 'history_delete_media', $runs, false);
        }

        update_option($this->settings_prefix . 'last_attempt', time(), false);
    }

    private function syndicate_one($post, $allow_old = false, $force_publish = false, $match_author = false, $post_status = false, $allow_overwrite = false) {
        // Process one post

        $options = get_option($this->settings_prefix . 'settings');

        // Setting this to 0 creates a new post.
        $existing_post_id = 0;

        if(!is_array($post)) {
            $this->log("syndicate_one() - post is not an array. Failing.");
            return;
        }

        // Have we already ingested this post?
        if($allow_overwrite === true && $this->post_guid_exists($post['guid']['rendered']) !== null) {
            $existing_post_id = $this->post_guid_exists($post['guid']['rendered']);

        } else if($this->post_guid_exists($post['guid']['rendered']) !== null) {
            // Already exists on this site - skip over this post
            $this->log("syndicate_one() - Post GUID already exists. Skipping.");
            return;
        }

        // Do not import posts earlier than a certain date
        if(!$allow_old) {
            if(isset($options['earliest_post_date']) && strtotime($options['earliest_post_date']) !== false && strtotime($post['date']) <= strtotime($options['earliest_post_date'])) {
                // This post is earlier than the specified start date
                $this->log("syndicate_one() - Post is earlier than earliest post cutoff date. Skipping.");
                return;
            }
        }

        // Check for empty fields and fail early
        if(!isset($post['guid']['rendered']) || empty($post['guid']['rendered'])) {
            $this->log("Post GUID is empty - skipping.");
            return;
        }

        $post['title']['rendered'] = trim($post['title']['rendered']);

        if(empty($post['title']['rendered'])) {
            $this->log("Post title is empty - skipping. " . $post['guid']['rendered']);
            return;
        }

        if(isset($post['content_raw']) && !empty($post['content_raw'])) {
            $content = $post['content_raw'];
            $content_type = 'raw';
        } else if(isset($post['content']['rendered']) && !empty($post['content']['rendered'])) {
            $content = $post['content']['rendered'];
            $content_type = 'rendered';
        }

        if(!isset($content)) {
            $this->log("Post body is empty - skipping. " . $post['guid']['rendered']);
            return;
        }

        if(isset($options['ignore_posts_containing']) && !empty($options['ignore_posts_containing'])) {
            $ignore_posts_containing = explode("\n", $options['ignore_posts_containing']);

            foreach($ignore_posts_containing as $ignore_text) {
                $ignore_text = trim($ignore_text);

                if(!empty($ignore_text) && stripos($post['title']['rendered'], $ignore_text) !== false) {
                    $this->log("Post title contains ignored text '" . $ignore_text . "' - skipping. " . $post['guid']['rendered']);
                    return;
                }

                if(!empty($ignore_text) && stripos($content, $ignore_text) !== false) {
                    $this->log("Post content contains ignored text '" . $ignore_text . "' - skipping. " . $post['guid']['rendered']);
                    return;
                }
            }
        }

        if($content_type == 'rendered') {
            // Strip some problematic conditional tags from the HTML
            $html = $content;
            $html = str_replace("<!--[if lt IE 9]>", "", $html);
            $html = str_replace("<![endif]-->", "", $html);

            // Parse the HTML
            if(class_exists('\Dom\HTMLDocument')) {
                // PHP 8.4+
                $dom = \Dom\HTMLDocument::createFromString($html, LIBXML_HTML_NOIMPLIED|LIBXML_NOERROR);
                $dom->strictErrorChecking = false;
            } else {
                $dom = new domDocument;
                $html = str_replace("<hr>", "<hr />", $html); // Workaround for certain libxml versions breaking on this unclosed tag
                $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
            }

            // Find and download any embedded images found in the HTML
            $images = $dom->getElementsByTagName('img');
            $images_to_attach = array();

            foreach($images as $imgKey => $img) {
                // Download the image and attach it
                $url = $img->getAttribute('src');
                $classes = $img->getAttribute('class');
                $image_id = $this->get_image_id_from_classes($classes);
                $attachment_id = $this->ingest_image($url, null, null, $image_id, $post);

                // Update the SRC in the HTML
                $url_new = wp_get_attachment_image_src($attachment_id, $options['image_embed_size']);
                $img->setAttribute('src', $url_new[0]);
                $img->setAttribute('width', $url_new[1]);
                $img->setAttribute('height', $url_new[2]);
                $img->removeAttribute('srcset');
                $img->removeAttribute('sizes');

                // Later on, we'll link these attachments to this specific post
                $images_to_attach[] = $attachment_id;

                // Fix up the classes
                $classes = explode(" ", $img->getAttribute('class'));
                foreach($classes as $classKey => $class) {
                    if(substr($class, 0, 9) == "wp-image-") {
                        $classes[$classKey] = "wp-image-" . $attachment_id;
                    } elseif(substr($class, 0, 5) == "size-") {
                        $classes[$classKey] = "size-" . $options['image_embed_size'];
                    }
                }
                $img->setAttribute('class', implode(" ", $classes));
            }

            // Turn <audio> tags into [audio] shortcodes
            $audios = $dom->getElementsByTagName('audio');

            foreach($audios as $audioKey => $audio) {
                // Get the original audio URL
                $audio_source = $audio->getElementsByTagName('source');
                $url = $audio_source->item(0)->getAttribute('src');

                if(empty($url)) {
                    continue;
                }

                // There is a bug in Wordpress causing audio URLs with URL Parameters to fail to load the player
                // See https://core.trac.wordpress.org/ticket/30377
                // As a partial workaround, we strip URL parameters
                if(strpos($url, "?") !== false) {
                    $url = substr($url, 0, strpos($url, "?"));
                }

                // Create a new paragraph, and insert the audio shortcode
                $audio_shortcode = $dom->createElement('div');
                $audio_shortcode->setAttribute('class', 'audio-filter');
                $audio_shortcode->textContent = '[audio src="'.esc_url($url).'"]';

                // Replace the original <audio> tag with this new <div class="audio-filter">[audio]</div> arrangement
                $audio->parentNode->replaceChild($audio_shortcode, $audio);
            }

            // Find YouTube embeds, and turn them into [embed] shortcodes
            $youtubes = $dom->getElementsByTagName('div');

            foreach($youtubes as $youtubeKey => $youtube) {

                // Skip non-youtube divs
                if(!$youtube->hasAttribute('class') || (strpos($youtube->getAttribute('class'), 'embed_youtube') === false && strpos($youtube->getAttribute('class'), 'video-filter') === false))
                    continue;

                // Get the original YouTube embed URL
                $video_source = $youtube->getElementsByTagName('iframe');
                $url = $video_source->item(0)->getAttribute('src');

                // Parse the Video ID from the URL
                if(preg_match("/^((?:https?:)?\\/\\/)?((?:www|m)\\.)?((?:youtube\\.com|youtu.be))(\\/(?:[\\w\\-]+\\?v=|embed\\/|v\\/)?)([\\w\\-]+)(\\S+)?$/", $url, $matches_youtube) === 1) {
                    // Create the new URL
                    $url_new = "https://youtube.com/watch?v=" . $matches_youtube[5];

                    // Create a new paragraph, and insert the audio shortcode
                    $embed_shortcode = $dom->createElement('div');
                    $embed_shortcode->setAttribute('class', 'video-filter');
                    $embed_shortcode->textContent = '[embed]'.esc_url($url_new).'[/embed]';

                    // Replace the original <div class="embed_youtube"> tag with this new <div>[embed]url[/embed]</div> arrangement
                    $youtube->parentNode->replaceChild($embed_shortcode, $youtube);
                }
            }

            // Find Instagram embeds, and turn them into [restful_syndication_iframe] shortcodes
            $instagrams = $dom->getElementsByTagName('blockquote');

            foreach($instagrams as $instagram) {

                // Skip non-youtube blockquotes
                if(!$instagram->hasAttribute('data-instgrm-permalink'))
                    continue;

                // Get the original Instagram URL
                $url = $instagram->getAttribute('data-instgrm-permalink');

                // Skip empty URLs
                if(empty($url)) {
                    continue;
                }

                // Add /embed to URL
                $url_parsed = parse_url($url);
                if($url_parsed === false) {
                    continue;
                }
                $url = $url_parsed['scheme'] . "://" . $url_parsed['host'] . str_replace("//", "/", $url_parsed['path'] . "/embed") . "?" . $url_parsed['query'];

                // Get width and height
                $width = '100%';
                $height = '650';

                // Create a new paragraph, and insert the iframe shortcode
                $embed_shortcode = $dom->createElement('p');
                $embed_shortcode->textContent = '[restful_syndication_iframe src="'.esc_url($url).'" width="'.esc_attr($width).'" height="'.esc_attr($height).'"]';

                // Replace the original <iframe> tag with this new <p>[restful_syndication_iframe src="url"]</p> arrangement
                $instagram->parentNode->replaceChild($embed_shortcode, $instagram);
            }

            // Find iFrames, and turn them into [restful_syndication_iframe] shortcodes
            $iframes = $dom->getElementsByTagName('iframe');

            foreach($iframes as $iframeKey => $iframe) {

                // Skip iframes without src field
                if(!$iframe->hasAttribute('src')) {
                    continue;
                }

                // Get the original iFrame src URL
                $url = $iframe->getAttribute('src');

                // Skip empty URLs
                if(empty($url)) {
                    continue;
                }

                // Get width and height
                $width = '100%';
                $height = '300';
                if($iframe->hasAttribute('height')) {
                    $height = $iframe->getAttribute('height');
                }

                // Create a new paragraph, and insert the iframe shortcode
                $embed_shortcode = $dom->createElement('p');
                $embed_shortcode->textContent = '[restful_syndication_iframe src="'.esc_url($url).'" width="'.esc_attr($width).'" height="'.esc_attr($height).'"]';

                // Replace the original <iframe> tag with this new <p>[restful_syndication_iframe src="url"]</p> arrangement
                $iframe->parentNode->replaceChild($embed_shortcode, $iframe);
            }

            $html = $dom->saveHTML();
            $html = str_replace('<?xml encoding="utf-8" ?>', '', $html);
        } else {
            $html = $content;
        }

        // Find local matching categories, or create missing ones
        $categories = array();

        if(isset($options['automatic_category']) && !empty($options['automatic_category']) && $options['automatic_category'] !== 0) {
            $categories[] = $options['automatic_category'];
        }

        if(isset($post['_links']['wp:term'])) {
            foreach($post['_links']['wp:term'] as $term_link) {
                if($term_link['taxonomy'] == 'category') {
                    $category_data_all = $this->rest_fetch($term_link['href'], true);

                    foreach($category_data_all as $category_data) {
                        if(isset($category_data['name']) && !empty($category_data['name'])) {
                            $term = get_term_by('name', $category_data['name'], 'category');
            
                            if($term !== false) {
                                // Category already exists
                                $categories[] = $term->term_id;
                            } elseif(isset($options['create_categories']) && $options['create_categories'] == "true") {
                                // Create the category
                                $cat_new = wp_insert_term($category_data['name'], 'category');

                                if(is_array($cat_new)) {
                                    $categories[] = $cat_new['term_id'];
                                }
                            }
                        }

                    }
                }
            }

        }

        // Find local matching tags
        $tags = array();

        if(isset($post['_links']['wp:term'])) {
            foreach($post['_links']['wp:term'] as $term_link) {
                if($term_link['taxonomy'] == 'post_tag') {
                    $tag_data_all = $this->rest_fetch($term_link['href'], true);

                    foreach($tag_data_all as $tag_data) {
                        if(isset($tag_data['name']) && !empty($tag_data['name'])) {
                            $term = get_term_by('name', $tag_data['name'], 'post_tag');
            
                            if($term !== false) {
                                // Tag already exists
                                $tags[] = $term->term_id;
                            } elseif($options['create_tags'] == "true") {
                                // Create the category
                                $tag = wp_insert_term($tag_data['name'], 'post_tag');
            
                                if(is_array($tag)) {
                                    $tags[] = $tag['term_id'];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Find local matching author taxonomy
        $author_terms = array();

        if(isset($post['_links']['wp:term']) && taxonomy_exists('author')) {
            foreach($post['_links']['wp:term'] as $term_link) {
                if($term_link['taxonomy'] == 'author') {
                    $term_data_all = $this->rest_fetch($term_link['href'], true);

                    foreach($term_data_all as $term_data) {
                        if(isset($term_data['name']) && !empty($term_data['name'])) {
                            $term = get_term_by('name', $term_data['name'], 'author');

                            if($term !== false) {
                                // Term already exists
                                $author_terms[] = $term->term_id;
                            } else {
                                // Create the author taxonomy term
                                $term = wp_insert_term($term_data['name'], 'author');

                                if(is_array($term)) {
                                    $author_terms[] = $term['term_id'];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Try and match to a local author
        $author = $options['default_author'];
        if($match_author === true && is_numeric($post['author']) && isset($post['_links']['author'][0])) {

            $author_data = $this->rest_fetch($post['_links']['author'][0]['href'], true);

            if(isset($author_data['name']) && !empty($author_data['name'])) {
                $user_lookup = $this->find_user($author_data['name']);

                if($user_lookup != false) {
                    $author = $user_lookup;
                }
            }
        }

        if(!isset($post['yoast_meta']['yoast_wpseo_metadesc']) || empty($post['yoast_meta']['yoast_wpseo_metadesc'])) {
            if(isset($post['yoast_head_json']['description']) && !empty($post['yoast_head_json']['description'])) {
                $post['yoast_meta']['yoast_wpseo_metadesc'] = $post['yoast_head_json']['description'];
            } else {
                $post['yoast_meta']['yoast_wpseo_metadesc'] = "";
            }
        }

        if(!isset($post['yoast_meta']['yoast_wpseo_canonical']) || empty($post['yoast_meta']['yoast_wpseo_canonical'])) {
            if(isset($post['yoast_head_json']['canonical']) && !empty($post['yoast_head_json']['canonical'])) {
                $post['yoast_meta']['yoast_wpseo_canonical'] = $post['yoast_head_json']['canonical'];
            } else {
                $post['yoast_meta']['yoast_wpseo_canonical'] = $post['link'];
            }
        }

        if($options['yoast_noindex'] == "true") {
            $post['yoast_meta']['yoast_wpseo_noindex'] = "1";
        } else {
            $post['yoast_meta']['yoast_wpseo_noindex'] = "0";
        }

        if($force_publish == true) {
            $post_status = 'publish';
        } elseif($post_status == false) {
            $post_status = $options['default_status'];
        }

        // Insert a new post
        $post_id = wp_insert_post(array(
            'ID' => $existing_post_id,
            'post_author' => $author,
            'post_date' => $post['date'],
            'post_date_gmt' => $post['date_gmt'],
            'post_content' => $html,
            'post_title' => $post['title']['rendered'],
            'post_excerpt' => strip_tags($post['excerpt']['rendered']),
            'post_status' => $post_status,
            'post_type' => 'post',
            'guid' => $post['guid']['rendered'],
            'post_category' => $categories,
            'tags_input' => $tags,
            'meta_input' => array(
                '_'.$this->settings_prefix.'source_guid' => $post['guid']['rendered'],
                '_yoast_wpseo_metadesc' => $post['yoast_meta']['yoast_wpseo_metadesc'],
                '_yoast_wpseo_canonical' => $post['yoast_meta']['yoast_wpseo_canonical'],
                '_yoast_wpseo_meta-robots-noindex' => $post['yoast_meta']['yoast_wpseo_noindex'],
            )
        ), false);

        if($post_id > 0 && count($author_terms) > 0) {
            // Set author taxonomy terms
            wp_set_object_terms($post_id, $author_terms, 'author');
        }

        if($post_id > 0 && isset($post['_links']['wp:featuredmedia'][0]['href'])) {
            // Download and attach featured image

            $api_url = $post['_links']['wp:featuredmedia'][0]['href'];
            $attachment = $this->rest_fetch($api_url, true);

            if(isset($attachment['media_details']['sizes']['full']['source_url'])) {
                $featured_attachment_id = $this->ingest_image($attachment['media_details']['sizes']['full']['source_url'], $post_id, $attachment);
                set_post_thumbnail($post_id, $featured_attachment_id);
            } elseif(isset($attachment['source_url'])) {
                $featured_attachment_id = $this->ingest_image($attachment['source_url'], $post_id, $attachment);
                set_post_thumbnail($post_id, $featured_attachment_id);
            } else {
                $this->log("Attachment full image not found: " . $api_url . " - " . print_r($attachment, true));
            }
        } else {
            $this->log("Featured Media Link not found. " . $post_id);
        }

        if($post_id > 0) {
            foreach($images_to_attach as $attachment_id) {
                // Attach images to this new post
                wp_update_post(
                    array(
                        'ID' => $attachment_id, 
                        'post_parent' => $post_id
                    )
                );
            }
        }

        return $post_id;
    }

    public function push_receive() {
        // Receive a push request from another server
        global $wp;

        // Check credentials
        if(!isset($_POST['restful_push_key'])) {
            return array('error' => 'Authentication failure');
        }

        $options = get_option($this->settings_prefix . 'settings');

        if($_POST['restful_push_key'] !== $options['remote_push_key'] || strlen($options['remote_push_key']) < 31) {
            return array('error' => 'Authentication failure');
        }

        // Check list of allowed domains
        if(!isset($_POST['restful_push_url'])) {
            return array('error' => 'URL not provided');
        }

        if(empty($options['remote_push_domains'])) {
            return array('error' => 'Allowed domains not configured');
        }

        $domains = explode("\n", $options['remote_push_domains']);
        $supplied_url_info = parse_url($_POST['restful_push_url']);
        $supplied_domain = $supplied_url_info['host'];
        $query_string =  $supplied_url_info['query'];
        parse_str($query_string, $query_string_parsed);

        foreach($domains as $key => $domain) {
            $domains[$key] = trim($domain);
        }

        if(!in_array($supplied_domain, $domains)) {
            return array('error' => 'Could not validate domain');
        }

        if(isset($query_string_parsed['restful_push_auth_key'])) {
            $this->push_remote_auth_key = $query_string_parsed['restful_push_auth_key'];
            $this->push_remote_domain = $supplied_domain;
        }

        // Request data
        $payload = $this->rest_fetch($_POST['restful_push_url'], true);

        if(empty($payload) || $payload == null) {
            return array("error" => 'Failed to fetch post data from API', "errors" => $this->errors_logged);
        }

        $post_status = false;

        // Post Status & Scheduled Date
        if(isset($_POST['post_status']) && in_array($_POST['post_status'], array('draft', 'pending', 'publish', 'future'))) {
            $post_status = $_POST['post_status'];

            if($_POST['post_status'] == 'future' && isset($_POST['schedule_date'])) {
                $payload['date'] = wp_date('Y-m-d H:i:s', $this->wp_strtotime($_POST['schedule_date']));
                $payload['date_gmt'] = date('Y-m-d H:i:s', $this->wp_strtotime($_POST['schedule_date']));
            }
        }

        // Process data
        $post_id = $this->syndicate_one($payload, true, false, true, $post_status, true);

        return array(
            "post_id" => $post_id,
            "errors" => $this->errors_logged,
            'post_status' => $post_status,
            'post_date' => $payload['date'],
        );
    }

    public function rest_api_raw_post_content() {
        // Adds the raw post content (block content) to the REST API endpoint
        // This is protected by the remote_push_key secret

        $options = get_option($this->settings_prefix . 'settings');

        $enable = false;

        if(isset($options['content_raw_unauth']) && $options['content_raw_unauth'] == "true") {
            // Always expose the raw block markup for Posts
            $enable = true;

        } else if(isset($_GET['restful_push_auth_key']) && $_GET['restful_push_auth_key'] === $options['remote_push_key'] && strlen($options['remote_push_key']) > 30) {
            // Check permissions - Push Auth (GET Param)
            $enable = true;

        }

        if($enable === false) {
            return;
        }

        if(!function_exists('use_block_editor_for_post_type')) {
            require ABSPATH . 'wp-admin/includes/post.php';
        }

        // Surface all Gutenberg blocks in the WordPress REST API
        $post_types = get_post_types_by_support(array('editor'));

        foreach($post_types as $post_type) {
            if(use_block_editor_for_post_type($post_type)) {
                register_rest_field(
                    $post_type,
                    'content_raw',
                    [
                        'get_callback' => function (array $post) {
                            return $post['content']['raw'];
                        },
                    ]
                );
            }
        }
    }

    private function clean_media($days, $post_type) {
        // Deletes media after a certain age

        if(!is_numeric($days)) {
            return;
        }

        $days = absint($days);

        if($days < 7) {
            return;
        }

        // Find posts
        $post_ids = $this->posts_older_than($days, $post_type);

        $return = array();
        
        // Loop over posts and find featured images attached to these posts
        foreach($post_ids as $post_id) {
            $image_id = get_post_thumbnail_id($post_id);

            if($image_id === false || $image_id === 0) {
                continue;
            }

            // Send the attachment to the trash
            $delete_image = wp_delete_attachment($image_id, false);

            if($delete_image !== false && $delete_image !== null) {
                $return[] = $image_id;
            }
        }

        return $return;
    }

    private function clean_posts($days, $post_type) {
        // Deletes posts after a certain age

        if(!is_numeric($days)) {
            return;
        }

        $days = absint($days);

        if($days < 7) {
            return;
        }

        // Find posts
        $post_ids = $this->posts_older_than($days, $post_type);

        $return = array();

        // Loop over posts
        foreach($post_ids as $post_id) {
            // Delete featured image first
            $image_id = get_post_thumbnail_id($post_id);

            if($image_id !== false) {
                // Send the attachment to the trash
                $delete_image = wp_delete_attachment($image_id, false);

                if($delete_image !== false) {
                    $return[] = $image_id;
                }
            }

            // Now delete the post itself
            $delete_post = wp_trash_post($post_id);
            if($delete_post !== false) {
                $return[] = $post_id;
            }
        }

        return $return;
    }

    private function posts_older_than($days, $post_type) {
        // Returns a list of Posts older than a certain age

        if(!is_numeric($days)) {
            return array();
        }

        $days = absint($days);

        $options = get_option($this->settings_prefix . 'settings');
        $domain = parse_url($options['site_url'], PHP_URL_HOST);
        $domain_1 = 'https://' . $domain;
        $domain_2 = 'http://' . $domain;

        if($domain_1 == 'https://' || $domain_2 == 'http://') {
            return array();
        }

        $posts = new WP_Query(array(
            'post_type' => $post_type,
            'orderby'   => 'post_date_gmt',
            'order' => 'ASC',
            'meta_query' => array(
                'relation' => 'OR',
                'meta_value_1' => array(
                      'key' => '_'.$this->settings_prefix.'source_guid',
                      'value' => $domain_1,
                      'compare' => 'LIKE',
                ),
                'meta_value_2' => array(
                    'key' => '_'.$this->settings_prefix.'source_guid',
                    'value' => $domain_2,
                    'compare' => 'LIKE',
              )
            ),
            'date_query' => array(
                array(
                    'column' => 'post_date_gmt',
                    'before' => $days . ' days ago',
                ),
            ),
            'fields' => 'ids',
            'posts_per_page' => -1,
        ));

        return $posts->posts;
    }

    private function post_guid_exists($guid) {
        // Find if a post with this source GUID already exists (as a meta value for a non-trashed post)
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT
                    post_id
                FROM
                    $wpdb->postmeta,
                    $wpdb->posts
                WHERE meta_key = %s
                AND meta_value = %s
                AND $wpdb->postmeta.post_id = $wpdb->posts.ID
                ",
                '_'.$this->settings_prefix.'source_guid',
                $guid
            )
        );
    }

    private function ingest_image($url, $parent_post_id = null, $attachment_rest_data = null, $original_attachment_id = null, $post_rest_data = null) {
        // Download a file from a URL, and add it to the media library

        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Lookup existing file by meta field
        $search_existing = get_posts(array(
            'post_type'      => 'attachment',
            'meta_key'       => 'restfulsyndication_original_url',
            'meta_value'     => $url,
            'posts_per_page' => 1,
        ));

        if(is_array($search_existing) && count($search_existing) > 0 && isset($search_existing[0]->ID)) {
            return $search_existing[0]->ID;
        }

        $filename = basename($url);

        // Check extension - uses get_allowed_mime_types() for mime type list
        $check_filetype = wp_check_filetype($filename);

        if($check_filetype['type'] == false) {
            $this->log("Stopped download of image due to invalid file type: " . $url);
            return null;
        }

        $response = wp_remote_get($url,
            array(
                "timeout" => 30,
            )
        );

        if(is_wp_error($response)) {
            $this->log("HTTP request failed when downloading image. " . $response->get_error_message());
            return;
        }

        $image_data = wp_remote_retrieve_body($response);

        if(empty($image_data)) {
            $this->log("Failed to download image " . $url);
            return null;
        }

        $upload_dir = wp_upload_dir();

        if(wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        if(file_exists($file)) {
            // File already exists - get the attachment ID
            $url_new = content_url(substr($file, strpos($file, "/uploads/")));
            $attach_id = attachment_url_to_postid($url_new);
        }

        if(!isset($attach_id) || $attach_id == 0) {
            // File doesn't already exist - save it
            file_put_contents($file, $image_data);

            if(!file_exists($file)) {
                $this->log("Failed to save image " . $file);
                return null;
            }

            // Check if file is an image
            $check_filetype_and_ext = wp_check_filetype_and_ext($file, $filename);

            if($check_filetype_and_ext['type'] == false) {
                $this->log("Downloaded file is not a valid image: " . $file . ". Deleting file from disk.");
                unlink($file);
                return null;
            }

            $attachment = array(
                'post_mime_type' => $check_filetype_and_ext['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            $attach_id = wp_insert_attachment($attachment, $file, $parent_post_id);
            
            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            wp_update_attachment_metadata($attach_id, $attach_data);

            update_post_meta($attach_id, 'restfulsyndication_original_url', $url);

            // Fetch rest data for image
            // We will want to save some metadata
            if(empty($attachment_rest_data)) {
                if(!empty($original_attachment_id) && !empty($post_rest_data)) {
                    if(isset($post_rest_data['_links']['self'][0]['href'])) {
                        $post_rest_url = $post_rest_data['_links']['self'][0]['href'];
                        $attachment_rest_url = str_replace("wp/v2/posts/", "wp/v2/media/", $post_rest_url);
                        $attachment_rest_url = substr($attachment_rest_url, 0, strpos($attachment_rest_url, "/media/") + 7) . $original_attachment_id;
                        $attachment_rest_data = $this->rest_fetch($attachment_rest_url, true);
                    }
                }
            }

            if(isset($attachment_rest_data['alt_text'])) {
                update_post_meta($attach_id, '_wp_attachment_image_alt', $attachment_rest_data['alt_text']);
            }

            if(isset($attachment_rest_data['title']['rendered'])) {
                $update = array(
                    'ID' => $attach_id,
                    'post_title' => strip_tags($attachment_rest_data['title']['rendered']),
                );
                wp_update_post($update);
            }

            if(isset($attachment_rest_data['caption']['rendered'])) {
                $update = array(
                    'ID' => $attach_id,
                    'post_excerpt' => strip_tags($attachment_rest_data['caption']['rendered']),
                );
                wp_update_post($update);
            }

            if(isset($attachment_rest_data['description']['rendered'])) {
                $update = array(
                    'ID' => $attach_id,
                    'post_content' => strip_tags($attachment_rest_data['description']['rendered']),
                );
                wp_update_post($update);
            }

            // Image Credit fields
            $fields = array(
                'media_credit_text',
                'media_credit_url',
                'media_credit_supplied',
                'supplied_notes',
                'media_credit_original',
                'media_credit_ai_generated',
            );

            foreach($fields as $field) {
                if(isset($attachment_rest_data['image_credit'][$field])) {
                    if($attachment_rest_data['image_credit'][$field] === true) {
                        $attachment_rest_data['image_credit'][$field] = '1';
                    } elseif($attachment_rest_data['image_credit'][$field] === false) {
                        $attachment_rest_data['image_credit'][$field] = '0';
                    }
                    update_post_meta($attach_id, $field, sanitize_text_field($attachment_rest_data['image_credit'][$field]));
                }
            }

            if(!file_exists($file)) {
                $this->log("Image doesn't exist after processing " . $file);
                return null;
            }

        }

        return $attach_id;
    }

    private function find_user($name) {
        // Lookup a user by their display name
        global $wpdb;

        if(!$user = $wpdb->get_row($wpdb->prepare("SELECT `ID` FROM $wpdb->users WHERE `display_name` = %s", $name)))
            return false;

        return $user->ID;
    }

    public function cron_schedules($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => esc_html__('Every Fifteen Minutes'),
        );
     
        return $schedules;
    }

    public function sc_iframe($atts) {
        // This iFrame can only be used on posts imported by this plugin
        // It is a security mechanism instead of just allowing iFrame's for all users accross the site
        // We assume the source site is at least semi-trusted (trusted enough to embed an iframe at least)

        $a = shortcode_atts(array(
            "src" => "",
            "width" => "100%",
            "height" => "200",
        ), $atts);

        global $post;
        if(!isset($post)) {
            return '';
        }

        $source_guid = get_post_meta($post->ID, '_'.$this->settings_prefix.'source_guid', true);

        if(empty($source_guid)) {
            return '';
        }

        return '<iframe src="'.esc_url($a['src']).'" width="'.esc_attr($a['width']).'" height="'.esc_attr($a['height']).'" border="0"></iframe>';
    }

    private function get_image_id_from_classes($class_string) {
        // If given a string of classes, return just the Image ID (if it exists)
        if(preg_match('/wp-image-(\d+)/', $class_string, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    public function fields_image_credits_acf() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group(array(
            'key' => 'group_683a92f3ef691',
            'title' => 'Media Source',
            'fields' => array(
                array(
                    'key' => 'field_683a92f4ab28d',
                    'label' => 'Media Credit',
                    'name' => 'media_credit_text',
                    'aria-label' => '',
                    'type' => 'text',
                    'instructions' => 'Store the name of the photographer or stock photo website here.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 1,
                    'default_value' => '',
                    'maxlength' => '',
                    'allow_in_bindings' => 0,
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                ),
                array(
                    'key' => 'field_683a9338ab28e',
                    'label' => 'Media Credit URL',
                    'name' => 'media_credit_url',
                    'aria-label' => '',
                    'type' => 'url',
                    'instructions' => 'Paste the link to the original media source.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 1,
                    'default_value' => '',
                    'allow_in_bindings' => 0,
                    'placeholder' => '',
                ),
                array(
                    'key' => 'field_683d2a1832a6d',
                    'label' => 'Supplied?',
                    'name' => 'media_credit_supplied',
                    'aria-label' => '',
                    'type' => 'true_false',
                    'instructions' => 'If this image has been supplied with permission to use it on your website, tick this box. This is sometimes the case with press releases. However, you should still ensure you have permission to use it from the original creator or photographer. If you downloaded this photo from a stock website, do not tick this box.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 1,
                    'message' => '',
                    'default_value' => 0,
                    'allow_in_bindings' => 0,
                    'ui' => 0,
                    'ui_on_text' => '',
                    'ui_off_text' => '',
                ),
                array(
                    'key' => 'field_6853aa7b51fdd',
                    'label' => 'Supplied Notes',
                    'name' => 'supplied_notes',
                    'aria-label' => '',
                    'type' => 'textarea',
                    'instructions' => 'Please note who who gave you permission to use this image. This is an internal field and won\'t be displayed to end-users of your website.',
                    'required' => 0,
                    'conditional_logic' => array(
                        array(
                            array(
                                'field' => 'field_683d2a1832a6d',
                                'operator' => '==',
                                'value' => '1',
                            ),
                        ),
                    ),
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 0,
                    'default_value' => '',
                    'maxlength' => '',
                    'allow_in_bindings' => 0,
                    'rows' => '',
                    'placeholder' => '',
                    'new_lines' => '',
                ),
                array(
                    'key' => 'field_683d2af832a6e',
                    'label' => 'Original?',
                    'name' => 'media_credit_original',
                    'aria-label' => '',
                    'type' => 'true_false',
                    'instructions' => 'If you have created this image yourself, tick this box.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 1,
                    'message' => '',
                    'default_value' => 0,
                    'allow_in_bindings' => 0,
                    'ui' => 0,
                    'ui_on_text' => '',
                    'ui_off_text' => '',
                ),
                array(
                    'key' => 'field_683d2b2732a6f',
                    'label' => 'AI Generated?',
                    'name' => 'media_credit_ai_generated',
                    'aria-label' => '',
                    'type' => 'true_false',
                    'instructions' => 'If this image was created with Artificial Intelligence (AI) tools, tick this box. Usage of AI media creation tools is still a grey area, and caution should be exercised.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'relevanssi_exclude' => 1,
                    'message' => '',
                    'default_value' => 0,
                    'allow_in_bindings' => 0,
                    'ui' => 0,
                    'ui_on_text' => '',
                    'ui_off_text' => '',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'attachment',
                        'operator' => '==',
                        'value' => 'all',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'seamless',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => 0,
        ));
    }

    public function fields_image_credits_api_header($headers) {
        // Header to disable embedding image credits in 
        $headers['X-RESTful-Syndication-Hide-Image-Credits'] = 'True';

        return $headers;
    }

    private function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()-=+`~') {
        // From https://stackoverflow.com/a/31284266/2888733
        $str = '';
        $max = mb_strlen($keyspace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[random_int(0, $max)];
        }
        return $str;
    }

    private function wp_strtotime($str) {
        // Timezone aware strtotime()
        try {
            $datetime = new DateTime($str, wp_timezone());
        } catch (Exception $e)  {
            return false;
        }

        return $datetime->format('U');
   }

}

$RESTfulSyndicationObj = New RESTfulSyndication();
register_activation_hook(__FILE__, array($RESTfulSyndicationObj, 'activate'));
