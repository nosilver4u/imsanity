=== Imsanity ===
Contributors: nosilver4u,verysimple
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MKMQKCBFFG3WW
Tags: image, scale, resize, space saver, quality
Requires at least: 4.5
Tested up to: 5.1
Requires PHP: 5.6
Stable tag: 2.4.2
License: GPLv3

Imsanity automatically resizes huge image uploads. Are contributors uploading huge photos? Tired of manually scaling? Imsanity to the rescue!

== Description ==

Imsanity automatically resizes huge image uploads down to a size that is
more reasonable for display in browser, yet still more than large enough for typical website use.
The plugin is configurable with a max width, height and quality.  When a contributor uploads an
image that is larger than the configured size, Imsanity will automatically scale it down to the
configured size and replace the original image.

Imsanity also provides a bulk-resize feature to selectively resize previously uploaded images
to free up disk space.

This plugin is ideal for blogs that do not require hi-resolution original images
to be stored and/or the contributors don't want (or understand how) to scale images
before uploading.

= Features =

* Automatically scales large image uploads to a more "sane" size
* Bulk-resize feature to selectively resize existing images
* Allows configuration of max width/height and jpg quality
* Optionally converts BMP files to JPG so image can be scaled
* Once enabled, Imsanity requires no actions on the part of the user
* Uses WordPress built-in image scaling functions

= Translations =

Imsanity is available in several languages, each of which will be downloaded automatically when you install the plugin. To help translate it into your language, visit https://translate.wordpress.org/projects/wp-plugins/imsanity

= Contribute =

Imsanity is developed at https://github.com/nosilver4u/imsanity (pull requests are welcome)

== Installation ==

Automatic Installation:

1. Go to Admin -> Plugins -> Add New and search for "imsanity"
2. Click the Install Button
3. Click 'Activate'

Manual Installation:

1. Download imsanity.zip
2. Unzip and upload the 'imsanity' folder to your '/wp-content/plugins/' directory
3. Activate the plugin through the 'Plugins' menu in WordPress

== Screenshots ==

1. Imsanity settings page to configure max height/width
2. Imsanity bulk image resize feature

== Frequently Asked Questions ==

= What is Imsanity? =

Imsanity is a plugin that automatically resizes uploaded images that are larger than the configured max width/height

= Will installing the Imsanity plugin alter existing images in my blog? =

Activating Imsanity will not alter any existing images.  Imsanity resizes images as they are uploaded so
it does not affect existing images unless you specifically use the "Bulk Image Resize" feature on
the Imsanity settings page.  The "Bulk Image Resize" feature allows you to selectively resize existing images.

= Why aren't all of my images detected when I try to use the bulk resize feature? =

Imsanity doesn't search your file system to find large files, instead it looks at the "metadata"
in the WordPress media library database. To override this behavior, enable deep scanning.

= Why am I getting an error saying that my "File is not an image" ? =

WordPress uses the GD library to handle the image manipulation.  GD can be installed and configured to support
various types of images.  If GD is not configured to handle a particular image type then you will get
this message when you try to upload it.  For more info see http://php.net/manual/en/image.installation.php

= How can I tell Imsanity to ignore a certain image so I can upload it without being resized? =

You can re-name your file and add "-noresize" to the filename.  For example if your file is named
"photo.jpg" you can rename it "photo-noresize.jpg" and Imsanity will ignore it, allowing you
to upload the full-sized image.

Optionally you can temporarily adjust the max image size settings and set them to a number that is
higher than the resolution of the image you wish to upload

= Why would I need this plugin? =

Photos taken on any modern camera and even most cellphones are too large for display full-size in a browser.
In the case of modern DSLR cameras, the image sizes are intended for high-quality printing and are ridiculously
over-sized for display on a web page.

Imsanity allows you to set a sanity limit so that all uploaded images will be constrained
to a reasonable size which is still more than large enough for the needs of a typical website.
Imsanity hooks into WordPress immediately after the image upload, but before WordPress processing
occurs.  So WordPress behaves exactly the same in all ways, except it will be as if the contributor
had scaled their image to a reasonable size before uploading.

The size limit that imsanity uses is configurable.  The default value is large enough to fill
the average vistors entire screen without scaling so it is still more than large enough for
typical usage.

= Why would I NOT want to use this plugin? =

You might not want to use Imsanity if you use WordPress as a stock art download
site, provide high-res images for print or use WordPress as a high-res photo
storage archive.  If you are doing any of these things then most likely
you already have a good understanding of image resolution.

= Doesn't WordPress already automatically scale images? =

When an image is uploaded WordPress keeps the original and, depending on the size of the original,
will create up to 4 smaller sized copies of the file (Large, Medium-Large, Medium, Thumbnail) which are intended
for embedding on your pages.  Unless you have special photographic needs, the original usually sits
there unused, but taking up disk quota.

= Why did you spell Insanity wrong? =

Imsanity is short for "Image Sanity Limit". A sanity limit is a term for limiting something down to
a size or value that is reasonable.

= Where do I go for support? =

Questions may be posted on the support forum at https://wordpress.org/support/plugin/imsanity but if you don't get an answer, please use https://ewww.io/contact-us/.

== Changelog ==

= 2.4.2 =
* changed: noresize in filename also works in batch processing
* fixed: error message does not contain filename when file is missing
* fixed: notice on network settings when deep scan option has not been set before

= 2.4.1 =
* fixed: bulk resizer scan returning incorrect results
* fixed: sprintf error during resizing and upload
 
= 2.4.0 =
* added: deep scanning option for when attachment metadata isn't updating properly
* fixed: uploads from Gutenberg not detected properly
* fixed: some other plugin(s) trying to muck with the Imsanity settings links and breaking things
* fixed: undefined notice for query during ajax operation
* fixed: stale metadata could prevent further resizing

= Earlier versions =
Please refer to the separate changelog.txt file.
