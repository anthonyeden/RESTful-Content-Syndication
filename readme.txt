=== Plugin Name ===
Contributors: anthonyeden
Tags: syndication, rest, wp-rest
Requires at least: 4.7
Tested up to: 4.9.7
Requires PHP: 5.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import post content from the Wordpress REST API on another Wordpress site

== Description ==

RESTful Syndication allows you to automatically ingest content from other Wordpress sites, using the Wordpress REST API.

This can allow you to run a network of sites, which all receive the same post content.

There is a small selection of options, allowing you select the author, default post status, automatically create the appropriate terms, and set the Yoast No-Index status.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/restful-syndication` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->RESTful Syndication screen to configure the plugin
 a. Set the 'Master Site URL' to the base URL of the site to pull the content from (e.g. https://example.com/)
 b. Username and Password are not required by default (but may be required by the person running the Master Site)
 c. Set the other options as desired
 d. Save your settings
 e. Press the 'Ingest Posts Now' button to test it works!

Posts will automatically be ingested every 15 minutes by WP-Cron. There's also a button to manually pull content on-demand.

If you also control the master site, and use the Yoast SEO plugin, you may want to install this additional plugin: https://github.com/ChazUK/wp-api-yoast-meta

== Frequently Asked Questions ==

= Do I need to install a plugin on the master site? =

No, so long as the master site has the Wordpress REST API enabled, then you're all good to go.

If you use Yoast, you can optionally install https://github.com/ChazUK/wp-api-yoast-meta to get canonical URLs and Meta Descriptions.

= I'm having trouble connecting to a HTTPS Master Site, but HTTP works fine. =

Check your web host has installed the appropriate CA Root Certs for PHP's CURL.

= Something isn't working. What do I do? =

Find the PHP Error log for your website/web-server. Any errors from this plugin should be prefixed with 'RESTful Syndication ERROR'.

= Do you provide support? =

Commercial support may be available from Media Realm (for a fee). Email us here: https://mediarealm.com.au/contact/

= Can you add a certain feature? =

You may be able to sponsor feature development. Email us here with your feature request and budget: https://mediarealm.com.au/contact/

== Changelog ==

= 1.0.1 =
* Fix cron timing.

= 1.0 =
* Initial public release.
