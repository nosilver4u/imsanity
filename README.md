# Imsanity
**Minimum WordPress:** 6.2
**Minimum PHP:** 7.4

[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/imsanity.svg)](https://wordpress.org/plugins/imsanity/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/r/imsanity.svg)](https://wordpress.org/support/plugin/imsanity/reviews/)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/imsanity.svg)](https://wordpress.org/plugins/imsanity/)
[![WordPress Tested Up To](https://img.shields.io/wordpress/v/imsanity.svg)](https://wordpress.org/plugins/imsanity/)
[![GNU General Public License 3.0](https://img.shields.io/github/license/nosilver4u/imsanity.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)

## Description

Automatically resize huge image uploads with Imsanity. Choose whatever size and quality you like, and let Imsanity do the rest.  When a contributor uploads an image that is larger than the configured size, Imsanity will automatically scale it down to the configured size and replace the original image.

Imsanity also provides a bulk-resize feature to resize previously uploaded images and free up disk space. You may resize individual images from the Media Library's List View.

This plugin is ideal for blogs that do not require hi-resolution original images to be stored and/or the contributors don't want (or understand how) to scale images before uploading.

You may report security issues through our Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/imsanity)

### Features

* Automatically scales large image uploads to a more "sane" size
* Bulk-resize feature to resize existing images
* Selectively resize images directly in the Media Library (List View)
* Allows configuration of max width/height and JPG quality
* Optionally converts BMP and PNG files to JPG for more savings
* Once enabled, Imsanity requires no actions on the part of the user
* Uses WordPress built-in image scaling functions

### Translations

Imsanity is available in several languages, each of which will be downloaded automatically when you install the plugin. To help translate it into your language, visit https://translate.wordpress.org/projects/wp-plugins/imsanity

## Installation

### Automatic Installation:

1. Go to Admin -> Plugins -> Add New and search for "imsanity"
1. Click the Install Button
1. Click 'Activate'

### Manual Installation:

1. Download imsanity.zip
1. Unzip and upload the 'imsanity' folder to your '/wp-content/plugins/' directory
1. Activate the plugin through the 'Plugins' menu in WordPress

## Frequently Asked Questions

### Will installing the Imsanity plugin alter existing images in my blog?

Activating Imsanity will not alter any existing images.  Imsanity resizes images as they are uploaded so it does not affect existing images unless you specifically use the "Bulk Image Resize" feature on the Imsanity settings page.  The Bulk Resize feature allows you to quickly resize existing images.

### Why am I getting an error saying that my "File is not an image"?

WordPress uses the GD library to handle the image manipulation.  GD can be installed and configured to support various types of images.  If GD is not configured to handle a particular image type then you will get this message when you try to upload it.  For more info see http://php.net/manual/en/image.installation.php

### How can I tell Imsanity to ignore a certain image so I can upload it without being resized?

You can re-name your file and add "-noresize" to the filename.  For example if your file is named
"photo.jpg" you can rename it "photo-noresize.jpg" and Imsanity will ignore it, allowing you
to upload the full-sized image.

If you are a developer (or have one handy), you can also use the 'imsanity_skip_image' filter to bypass resizing for any image.

### Does Imsanity compress or optimize my images?

While Imsanity does compress JPG images in the process of resizing them, it uses the standard WordPress compression. Thus, the resulting images are not efficiently encoded and can be optimized further (without quality loss) by the EWWW Image Optimizer and many other image optimization plugins.

### Will Imsanity resize images from plugin X, Y, or Z?

If the images can be found in the Media Library of your site, then it is likely Imsanity will resize them. Imsanity uses the wp_handle_upload hook to process new uploads and can resize any existing images in the Media Library using the Bulk Resizer. If the images are not in the Media Library, you can use the EWWW Image Optimizer to resize them.

### Why would I need this plugin?

Photos taken on any modern camera and most cellphones are too large to display full-size in a browser.
This wastes space on your web server, and wastes bandwidth for your visitors to view these files.

Imsanity allows you to set a sanity limit so that all uploaded images will be constrained to a reasonable size which is still more than large enough for the needs of a typical website. Imsanity hooks into WordPress immediately after the image upload, but before WordPress processing occurs. So WordPress behaves exactly the same in all ways, except it will be as if the contributor had scaled their image to a reasonable size before uploading.

The size limit that imsanity uses is configurable. The default value is large enough to fill the average vistor's entire screen without scaling so it is still more than large enough for typical usage.

### Why would I NOT want to use this plugin?

You might not want to use Imsanity if you use WordPress as a stock art download site, to provide hi-resolution images for print or use WordPress as a hi-resolution photo storage archive.

### Doesn't WordPress already automatically scale images?

When an image is uploaded WordPress keeps the original and, depending on the size of the original, will create up to 4 smaller sized copies of the file (Large, Medium-Large, Medium, Thumbnail) which are intended for embedding on your pages.  Unless you have special photographic needs, the original usually sits there unused, but taking up disk quota.

### Why did you spell Insanity wrong?

Imsanity is short for "Image Sanity Limit". A sanity limit is a term for limiting something down to
a size or value that is reasonable.

### Where do I go for support?

Questions may be posted on the support forum at https://wordpress.org/support/plugin/imsanity but if you don't get an answer, please use https://ewww.io/contact-us/.

## Credits

Originally written by Jason Hinkle (RIP). Maintained and developed by [Shane Bishop](https://ewww.io) with special thanks to my [Lord and Savior](https://www.iamsecond.com/).
