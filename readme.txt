=== HEIC Support ===
Contributors: salzano
Donate link: https://coreysalzano.com/donate/
Tags: heic, webp, iphone
Requires at least: 5.9
Tested up to: 6.1.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded.


== Description ==

Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded.

Creation of .webp copies only works on servers running ImageMagick 7 or above. Check the page at Media → HEIC Support after activating to see if your server provides ImageMagick 7.


== Installation ==

1. Upload the entire `heic-support` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the **Plugins** screen (**Plugins > Installed Plugins**).

Check the page at Media → HEIC Support after activating to see if your server provides ImageMagick 7.


== Changelog ==

= 1.0.0 =
* [Added] First public version. Adds `.heic` support to WordPress. If ImageMagick 7 or above is installed, creates `.webp` copies of `.heic` images uploaded to the Media Library.