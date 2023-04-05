=== HEIC Support ===
Contributors: salzano
Donate link: https://coreysalzano.com/donate/
Tags: heic, webp, iphone
Requires at least: 5.9
Tested up to: 6.2.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded.

Visit this plugin's home page at [https://breakfastco.xyz/heic-support/](https://breakfastco.xyz/heic-support/)


== Description ==

Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded. An optional feature replaces the original upload instead of creating a copy.

Creation of .webp copies usually works on servers running ImageMagick 7 or above. Check Settings → Media → HEIC Support after activating to see if your server provides ImageMagick.


== Installation ==

1. Upload the entire `heic-support` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the **Plugins** screen (**Plugins → Installed Plugins**).

Check the page at Media → HEIC Support after activating to see if your server provides ImageMagick 7.


== Changelog ==

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

= 2.0.0 =
Moves all plugin settings from Media → HEIC Support to Settings → Media. Adds an optional feature to replace .heic images rather than create a copy. A switch enables the feature at Settings → Media. Changes the tested up to version to 6.2.0. Add filters around the webp format and image/webp mime type strings so they can be changed by other developers.

= 1.0.1 =
Prevents an error on sites running PHP versions less than or equal to 7.2.