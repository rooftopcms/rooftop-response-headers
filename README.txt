=== Rooftop Response Headers ===
Contributors: rooftopcms
Tags: rooftop, api, headless, content
Requires at least: 4.3
Tested up to: 4.8.1
Stable tag: 4.3
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

rooftop-response-headers sends some additional headers back in each request
to make it easier to build apps that can cache the responses and check for updates

== Description ==

rooftop-response-headers sends some additional headers back in each request
to make it easier to build apps that can cache the responses and check for updates

Track progress, raise issues and contribute at http://github.com/rooftopcms/rooftop-response-headers

== Installation ==

rooftop-response-headers is a Composer plugin, so you can include it in your Composer.json.

Otherwise you can install manually:

1. Upload the `rooftop-response-headers` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. There is no step 3 :-)

== Frequently Asked Questions ==

= Can this be used without Rooftop CMS? =

Yes, it's a Wordpress plugin you're welcome to use outside the context of Rooftop CMS. We haven't tested it, though.


== Changelog ==

= 1.2.2 =
* Cleanup etags on init and return a 304 if the client has a cached document

= 1.2.1 =
* Tweak readme for packaging

= 1.2.0 =
* Fix issue where endpoint type was null


== What's Rooftop CMS? ==

Rooftop CMS is a hosted, API-first WordPress CMS for developers and content creators. Use WordPress as your content management system, and build your website or application in the language best suited to the job.

https://www.rooftopcms.com
