=== RESTful Content Syndication ===
Contributors: anthonyeden
Tags: syndication, rest, wp-rest
Requires at least: 6.0.0
Tested up to: 6.7.1
Requires PHP: 7.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import post content from the Wordpress REST API on another Wordpress site

== Description ==

RESTful Syndication allows you to automatically ingest content from other Wordpress sites, using the Wordpress REST API.

This can allow you to run a network of sites, which all receive the same post content.

There is a small selection of options, allowing you select the author, default post status, automatically create the appropriate terms, and set the Yoast No-Index status.

== Installation ==

1. Install this plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings -> RESTful Syndication screen to configure the plugin
 a. Set the 'Master Site URL' to the base URL of the site to pull the content from (e.g. https://example.com/)
 b. Username and Password are not required by default (but may be required by the person running the Master Site). You can use the WordPress Applicaton Password feature for authentication.
 c. Set the other options as desired
 d. Save your settings
 e. Press the 'Ingest Posts Now' button to test it works!

Posts will automatically be ingested every 15 minutes by WP-Cron. There's also a button to manually pull content on-demand.

If you also control the master site, and use the Yoast SEO plugin, you may want to install this additional plugin: https://github.com/ChazUK/wp-api-yoast-meta

== Frequently Asked Questions ==

= Do I need to install a plugin on the master site? =

No, so long as the master site has the Wordpress REST API enabled, then you're all good to go.

If both the Master and Child sites use Yoast SEO, the Canonical URLs and Meta Descriptions of each post will also be imported.

= I'm having trouble connecting to a HTTPS Master Site, but HTTP works fine. =

Check your web host has installed the appropriate CA Root Certs for PHP's CURL.

= Something isn't working. What do I do? =

Find the PHP Error Log for your website/web-server. Any errors from this plugin should be prefixed with 'RESTful Syndication ERROR'.

= Do you provide support? =

Commercial support is available from Media Realm (for a fee). Email us here: https://mediarealm.com.au/contact/

= Can you add a certain feature? =

You may be able to sponsor feature development. Email us here with your feature request: https://mediarealm.com.au/contact/

== Changelog ==

= 1.5.0 =

* Content Push: Allow accepting post status & schedule date fields from source site
* Content Push: Expose the Raw Content (Blocks Markup) via the API when doing a Content Push
* Content Raw Field: Add option to expose this field in the API to unauthenticated users

= 1.4.2 =

* Add an explicit timeout for accessing the REST API
* When downloading images, use wp_remote_get instead of file_get_contents

= 1.4.1 =

* Uses the native Yoast SEO REST fields, instead of fields supplied by a third party plugin
* Adds additional checks to see if Yoast fields are populated or empty

= 1.4.0 =

* Adds an option to add a specific category to every incoming post
* Tracks syndicated media in a meta field, instead of relying on the filename.

= 1.3.0 =

* Add new options to purge media & posts older than a certain number of days
* Additional compatibility for YouTube and Audio embeds
* Allow iFrames to be syndicated
* Translate Instagram embeds into iFrames
* Bugfix for Audio embeds
* Catch errors causing empty posts to be syndicated
* Security hardening on the admin screen
* Additional logging details

= 1.2.1 =

* Fix a bug where category creation wasn't working during content Push

= 1.2.0 = 

* Change the method used to pull categories, tags, and authors

= 1.1.1 =

* Featured Image: Fallback to alternative URL if full URL isn't found
* Syndication Push: Fix issue matching domains on incoming content

= 1.1.0 =

* PHP 8 compatibility
* Bugfixes when adding tags and categories
* Add logging for failed image downloads
* Push data receive: Check if no payload is received from remote server

= 1.0.6 =
* Handle YouTube embeds, and convert them into the [embed] shortcode

= 1.0.5 =
* Prevent the same image being imported multiple times
* Fix a pre-PHP 5.6 compatibity issue with the DOM manipulation class

= 1.0.4 =
* Add some handling of <audio> HTML5 tags, to convert them into [audio] shortcodes

= 1.0.3 =
* Fix issues with Auto-Publishing, and auto Tag/Category creation (thanks to David from Advantage IT)

= 1.0.2 =
* Add a check to see if the background wp-cron task has dropped off the scheduled tasks list

= 1.0.1 =
* Fix cron timing.

= 1.0 =
* Initial public release.
