![HEIC Support](assets/banner-1544x500.jpg)

# HEIC Support

Allows .heic uploads to the Media Library. Creates a .webp copy of .heic images when they are uploaded. An optional feature replaces the original upload instead of creating a copy.

Creation of .webp copies usually works on servers running ImageMagick 7 or above. Check Settings → Media → HEIC Support after activating to see if your server provides ImageMagick.

Saves attachment IDs in meta key `_heic_support_copy_of` on both the uploaded .heic and the generated .webp attachment posts.

## Filters

`heic_support_extension`
Filters the file extension string "webp".

`heic_support_mime`
Filters the image mime type stirng "image/webp".

## Links

ImageMagick homepage
[imagemagick.org](https://imagemagick.org/)

Plugin homepage
[breakfastco.xyz/heic-support/](https://breakfastco.xyz/heic-support/)

Project homepage
[github.com/csalzano/heic-support](https://github.com/csalzano/heic-support)