=== HEIC Support ===
Contributors: salzano
Donate link: https://breakfastco.xyz/heic-support/
Tags: heic, webp, iphone
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows .heic uploads to the Media Library. Creates .webp or .jpg copies of .heic images when they are uploaded.


== Description ==

Allows .heic uploads to the Media Library. Creates .webp or .jpg copies of .heic images when they are uploaded. An optional feature replaces the original upload instead of creating a copy.

Creation of .webp or .jpg copies usually works on servers running ImageMagick 7 or above. Check Settings → Media → HEIC Support after activating to see if your server provides ImageMagick.

**Optional cloud conversion (paid).** If your server cannot convert .heic images (no ImageMagick or no libheif), you can optionally convert uploads using a paid cloud conversion service. This requires purchasing conversion credits and entering a license key at Settings → Media. The free plugin works exactly as before without it. Cloud conversion is an additional, opt-in option, not a replacement for any existing feature.


== External services ==

This plugin's optional, opt-in cloud conversion feature connects to an external service operated by Breakfast (breakfastco.xyz). With no license key saved, the plugin does not contact any external service.

**Credit balance checks.** After you save a license key at Settings → Media, the plugin may contact breakfastco.xyz to fetch your remaining conversion credit balance. This request is made when the settings page is displayed (and the result is cached for about 10 minutes) so the plugin can show how many credits you have left. It sends your license key and your site URL. This happens even if "Cloud Conversion" is not yet enabled.

**Cloud conversion.** When you have saved a license key and enabled "Cloud Conversion" at Settings → Media, the plugin sends your license key and your site URL to breakfastco.xyz to reserve conversion credits and perform a conversions. The plugin uploads the .heic image file to the conversion server, which returns the converted .webp or .jpg image. Uploaded images are processed in memory and the temporary files are deleted immediately after conversion; they are not stored or shared.


== Installation ==

1. Upload the entire `heic-support` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the **Plugins** screen (**Plugins → Installed Plugins**).

Check the page at Media → HEIC Support after activating to see if your server provides ImageMagick 7.

== Screenshots ==

1. The settings are located at Settings → Media → HEIC Support in the dashboard.

== Changelog ==

= 2.2.0 =
* [Added] Optional, opt-in cloud conversion for servers without ImageMagick/libheif. Enter a license key and enable it at Settings → Media; converts .heic uploads via the Breakfast cloud conversion service using purchased credits. See the External services section.
* [Changed] Changes the tested up to version to 7.0.

= 2.1.4 =
* [Fixed] Makes sure the test runs before the settings output is generated.
* [Changed] Changes the tested up to version to 6.8.3.

= 2.1.3 =
* [Changed] Changes the tested up to version to 6.6.2.

= 2.1.2 =
* [Fixed] Shows better error output when ImageMagick is installed on the server but conversions cannot be completed.
* [Fixed] Uses a unique file name when creating test images in the uploads folder.
* [Changed] Changes the tested up to version to 6.4.0.

= 2.1.1 =
* [Added] Adds a screenshot of the plugin settings.
* [Fixed] Fixes a bug in the replace feature that prevented it from working in certain environments. See https://wordpress.org/support/topic/replace-does-not-work-because-of-file-type/
* [Changed] Changes the tested up to version to 6.3.2.

= 2.1.0 =
* [Added] Adds .jpg support. Adds a setting to toggle whether images are converted to webp or jpg. Defaults to webp.
* [Added] Show the settings section at Settings → Media even if ImageMagick is not installed. Explain to users that their host does not provide the library.
* [Fixed] Removes layers from the icon .svg file.
* [Changed] Changes the tested up to version to 6.3.1.

= 2.0.0 =
* [Added] Add filters around the webp format and image/webp mime type strings so they can be changed by other developers.
* [Added] Adds an optional feature to replace .heic images rather than create a copy. A switch enables the feature at Settings → Media.
* [Fixed] Updates an error message to stop mentioning a specific minimum version of ImageMagick. It was not accurate, and other dependencies like libheif could be missing that prevent conversions from working.
* [Changed] Moves all plugin settings from Media → HEIC Support to Settings → Media.
* [Changed] Changes the tested up to version to 6.2.0.
* [Removed] Removes the menu at Media → HEIC Support.

= 1.0.1 =
* [Fixed] Fixes a bug that caused a parse error in PHP versions less than or equal to 7.2.
* [Changed] Changes the tested up to version to 6.1.1.

= 1.0.0 =
* [Added] First public version. Adds `.heic` support to WordPress. If ImageMagick 7 or above is installed, creates `.webp` copies of `.heic` images uploaded to the Media Library.

== Upgrade Notice ==

= 2.2.0 =
Adds optional, opt-in cloud conversion for servers that can't convert .heic locally. No change to existing behavior unless you enable it.

= 2.1.4 =
Makes sure the test runs before the settings output is generated. Changes the tested up to version to 6.8.3.

= 2.1.3 =
Changes the tested up to version to 6.6.2.

= 2.1.2 =
Shows better error output when ImageMagick is installed on the server but conversions cannot be completed. Uses a unique file name when creating test images in the uploads folder. Changes the tested up to version to 6.4.0.

= 2.1.1 =
Adds a screenshot of the plugin settings. Fixes a bug in the replace feature that prevented it from working in certain environments. Changes the tested up to version to 6.3.2.

= 2.1.0 =
Adds .jpg support. Adds a setting to toggle whether images are converted to webp or jpg. Defaults to webp. Show the settings section at Settings → Media even if ImageMagick is not installed. Explain to users that their host does not provide the library. Changes the tested up to version to 6.3.1.

= 2.0.0 =
Moves all plugin settings from Media → HEIC Support to Settings → Media. Adds an optional feature to replace .heic images rather than copy. Changes the tested up to version to 6.2.0. Add filters around the webp format and image/webp mime type strings.

= 1.0.1 =
Prevents an error on sites running PHP versions less than or equal to 7.2.