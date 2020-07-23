<?php
/*
Plugin Name: RESTful Content Syndication
Plugin URI: https://mediarealm.com.au/
Description: Import content from the Wordpress REST API on another Wordpress site
Version: 1.0.6
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
        "yoast_noindex" => array(
            "title" => "Always Enable Yoast No-Index?",
            "type" => "checkbox",
        ),
        "image_embed_size" => array(
            "title" => "Embedded Image Size",
            "type" => "select",
            "options" => array()
        ),
    );

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('restful-syndication_cron', array($this, 'syndicate'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
    }

    public function activate() {
        wp_schedule_event(time(), 'fifteen_minutes', 'restful-syndication_cron');
    }

    private function log($log)  {
        if(is_array($log) || is_object($log)) {
           error_log($this->errors_prefix . print_r($log, true));
        } else {
           error_log($this->errors_prefix . $log);
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

        if($field['type'] == "text") {
            // Text fields
            echo '<input type="text" name="restful-syndication_settings['.$args['field_key'].']" value="'.htmlspecialchars($value, ENT_QUOTES).'" />';
        } elseif($field['type'] == "password") {
            // Password fields
            echo '<input type="password" name="restful-syndication_settings['.$args['field_key'].']" value="'.htmlspecialchars($value, ENT_QUOTES).'" />';
        } elseif($field['type'] == "select") {
            // Select / drop-down fields
            echo '<select name="restful-syndication_settings['.$args['field_key'].']">';
            foreach($field['options'] as $selectValue => $name) {
                echo '<option value="'.$selectValue.'" '.($value == $selectValue ? "selected" : "").'>'.$name.'</option>';
            }
            echo '</select>';
        } elseif($field['type'] == "checkbox") {
            // Checkbox fields
            echo '<input type="checkbox" name="restful-syndication_settings['.$args['field_key'].']" value="true" '.("true" == $value ? "checked" : "").' />';
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
        $last_attempt = get_option($this->settings_prefix . 'last_attempt', 0);
        krsort($runs);

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
        echo '</ul>';


        echo '<h2>Ingest Posts Now</h2>';
        echo "<p>This plugin uses WP-Cron to automatically ingest posts every 15 minutes. If you're impatient, you can do it now using the button below.</p>";
        if(isset($_GET['ingestnow']) && $_GET['ingestnow'] == "true") {
            echo "<p><strong>Attempting syndication now...</strong></p>";
            $this->syndicate();
            echo '<p><strong>Syndication complete!</strong></p>';
        } else {
            echo '<p class="submit"><a href="?page='.$_GET['page'].'&ingestnow=true" class="button button-primary">Ingest Posts Now</a></p>';
        }
        

    }

    private function rest_fetch($url, $full = false) {
        $options = get_option($this->settings_prefix . 'settings');

        if($full === false && (!isset($options['site_url']) || empty($options['site_url']) || filter_var($options['site_url'], FILTER_VALIDATE_URL) === FALSE)) {
            $this->log("Master Site URL not specified, or URL format invalid.");
            return;
        }

        if(!empty($options['auth_username']) && !empty($options['auth_password'])) {
            $headers = array(
                'Authorization' => 'Basic ' . base64_encode($options['auth_username'] . ':' . $options['auth_password']),
            );
        } else {
            $headers = array();
        }

        $response = wp_remote_get(
            ($full === false ? $options['site_url'] : "") . $url,
            array(
                "headers" => $headers,
            )
        );

        if(is_wp_error($response)) {
            $this->log("HTTP request failed when calling WP REST API.");
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

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

        foreach($posts as $post) {
            // Loop over every post and create a post entry

            // Have we already ingested this post?
            if($this->post_guid_exists($post['guid']['rendered']) !== null) {
                // Already exists on this site - skip over this post
                continue;
            }

            // Do not import posts earlier than a certain date
            if(isset($options['earliest_post_date']) && strtotime($options['earliest_post_date']) !== false && strtotime($post['date']) <= strtotime($options['earliest_post_date'])) {
                // This post is earlier than the specified start date
                continue;
            }

            // Download any embedded images found in the HTML
            $dom = new domDocument;
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $post['content']['rendered'], LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);

            $images = $dom->getElementsByTagName('img');
            $images_to_attach = array();

            foreach($images as $imgKey => $img) {
                // Download the image and attach it
                $url = $img->getAttribute('src');
                $attachment_id = $this->ingest_image($url);
                
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

                // There is a bug in Wordpress causing audio URLs with URL Parameters to fail to load the player
                // See https://core.trac.wordpress.org/ticket/30377
                // As a workaround, we strip URL parameters
                if(strpos($url, "?") !== false) {
                    $url = substr($url, 0, strpos($url, "?"));
                }

                // Create a new paragraph, and insert the audio shortcode
                $audio_shortcode = $dom->createElement('p');
                $audio_shortcode->nodeValue = '[audio src="'.$url.'"]';

                // Replace the original <audio> tag with this new <p>[audio]</p> arrangement
                $audio->parentNode->replaceChild($audio_shortcode, $audio);
            }

            // Find YouTube embeds, and turn them into [embed] shortcodes
            $youtubes = $dom->getElementsByTagName('div');

            foreach($youtubes as $youtubeKey => $youtube) {

                // Skip non-youtube divs
                if(!$youtube->hasAttribute('class') || strpos($youtube->getAttribute('class'), 'embed_youtube') === false)
                    continue;

                // Get the original YouTube embed URL
                $video_source = $youtube->getElementsByTagName('iframe');
                $url = $video_source->item(0)->getAttribute('src');

                // Parse the Video ID from the URL
                if(preg_match("/^((?:https?:)?\\/\\/)?((?:www|m)\\.)?((?:youtube\\.com|youtu.be))(\\/(?:[\\w\\-]+\\?v=|embed\\/|v\\/)?)([\\w\\-]+)(\\S+)?$/", $url, $matches_youtube) === 1) {
                    // Create the new URL
                    $url_new = "https://youtube.com/watch?v=" . $matches_youtube[5];

                    // Create a new paragraph, and insert the audio shortcode
                    $embed_shortcode = $dom->createElement('p');
                    $embed_shortcode->nodeValue = '[embed]'.$url_new.'[/embed]';

                    // Replace the original <div class="embed_youtube"> tag with this new <p>[embed]url[/embed]</p> arrangement
                    $youtube->parentNode->replaceChild($embed_shortcode, $youtube);
                }
            }

            $html = $dom->saveHTML();
            $html = str_replace('<?xml encoding="utf-8" ?>', '', $html);

            // Find local matching categories, or create missing ones
            $categories = array();

            foreach($post['categories'] as $category) {
                $category_data = $this->rest_fetch('/wp-json/wp/v2/categories/'.$category);
                $term = get_term_by('name', $category_data['name'], 'category');

                if($term !== false) {
                    // Category already exists
                    $categories[] = $term->term_id;
                } elseif($options['create_categories'] == "true") {
                    // Create the category
                    $categories[] = wp_insert_category(array('cat_name' => $category_data['name']));
                }
            }

            // Find local matching tags
            $tags = array();

            foreach($post['tags'] as $tag) {
                $tag_data = $this->rest_fetch('/wp-json/wp/v2/tags/'.$tag);
                $term = get_term_by('name', $tag_data['name'], 'post_tag');

                if($term !== false) {
                    // Tag already exists
                    $tags[] = $term->term_id;
                } elseif($options['create_tags'] == "true") {
                    // Create the category
                    $tag = wp_insert_term($tag_data['name'], 'post_tag' );
                    $tags[] = $tag['term_id'];

                }
            }

            if(!isset($post['yoast_meta']['yoast_wpseo_metadesc'])) {
                $post['yoast_meta']['yoast_wpseo_metadesc'] = "";
            }

            if(!isset($post['yoast_meta']['yoast_wpseo_canonical'])) {
                $post['yoast_meta']['yoast_wpseo_canonical'] = $post['link'];
            }

            if($options['yoast_noindex'] == "true") {
                $post['yoast_meta']['yoast_wpseo_noindex'] = "1";
            } else {
                $post['yoast_meta']['yoast_wpseo_noindex'] = "0";
            }

            // Insert a new post
            $post_id = wp_insert_post(array(
                'ID' => 0,
                'post_author' => $options['default_author'],
                'post_date' => $post['date'],
                'post_date_gmt' => $post['date_gmt'],
                'post_content' => $html,
                'post_title' => $post['title']['rendered'],
                'post_excerpt' => strip_tags($post['excerpt']['rendered']),
                'post_status' => $options['default_status'],
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

            if($post_id > 0 && isset($post['_links']['wp:featuredmedia'][0]['href'])) {
                // Download and attach featured image

                $api_url = $post['_links']['wp:featuredmedia'][0]['href'];
                $attachment = $this->rest_fetch($api_url, true);

                if(isset($attachment['media_details']['sizes']['full']['source_url'])) {
                    $featured_attachment_id = $this->ingest_image($attachment['media_details']['sizes']['full']['source_url'], $post_id);
                    set_post_thumbnail($post_id, $featured_attachment_id);
                } else {
                    $this->log("Attachment full image not found: " . $api_url);
                }
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

            $count++;

        }

        if($count > 0) {
            $runs = get_option($this->settings_prefix . 'history', array());
            $runs[time()] = $count;
            update_option($this->settings_prefix . 'history', $runs);
        }

        update_option($this->settings_prefix . 'last_attempt', time());
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
                AND $wpdb->posts.post_status != 'trash'
                ",
                '_'.$this->settings_prefix.'source_guid',
                $guid
            )
        );
    }

    private function ingest_image($url, $parent_post_id = null) {
        // Download a file from a URL, and add it to the media library

        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Get the source file
        $image_data = file_get_contents($url);
        $filename = basename($url);

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

            $wp_filetype = wp_check_filetype($filename, null);

            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            $attach_id = wp_insert_attachment($attachment, $file, $parent_post_id);
            
            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
            wp_update_attachment_metadata($attach_id, $attach_data);
        }

        

        return $attach_id;
    }

    public function cron_schedules($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => esc_html__('Every Fifteen Minutes'),
        );
     
        return $schedules;
    }

}

$RESTfulSyndicationObj = New RESTfulSyndication();
register_activation_hook(__FILE__, array($RESTfulSyndicationObj, 'activate'));
