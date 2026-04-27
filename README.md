# Blog Publisher for WordPress

A WordPress plugin that automatically publishes blog posts from `.docx` files with AI-generated images and SEO metadata.

## Features

- **Upload .docx files** - Drag and drop Microsoft Word documents
- **AI-powered images** - Automatically fetches relevant images from Pexels using Anthropic AI
- **SEO optimization** - Auto-generates SEO metadata (compatible with Yoast, Rank Math, and AIOSEO)
- **Background processing** - Queue multiple files and process them asynchronously
- **Real-time logs** - Watch the processing progress in real-time

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- ZipArchive extension (usually enabled by default)
- SimpleXML extension (usually enabled by default)

## API Keys Required

Before using the plugin, you need:

1. **Anthropic API Key** - For AI-powered image queries and SEO metadata
   - Get your key at: [console.anthropic.com](https://console.anthropic.com)

2. **Pexels API Key** - For fetching stock photos
   - Get your key at: [pexels.com/api](https://www.pexels.com/api)

## Installation

### Manual Installation

1. Download the plugin zip file
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

### FTP Installation

1. Upload the `blog-publisher` folder to `/wp-content/plugins/`
2. Go to WordPress Admin → Plugins
3. Activate "Blog Publisher"

## Usage

### 1. Configure API Keys

1. Go to **Blog Publisher → Settings**
2. Enter your Anthropic API key
3. Enter your Pexels API key
4. Click **Save Settings**

### 2. Upload Documents

1. Go to **Blog Publisher → Upload Posts**
2. Drag and drop one or more `.docx` files
3. Select the post type (Post, Page, or any custom post type)
4. Click **Start Publishing**

### 3. Monitor Progress

The plugin shows real-time logs during processing:
- **Queued** - File is waiting in the queue
- **Processing** - Currently being processed (parsing, generating SEO, fetching images)
- **Complete** - Post created successfully (click "Edit draft" to view)
- **Error** - Something went wrong (check the error message)

## Document Format

For best results, format your `.docx` files as follows:

- **Title**: Use Heading 1 (H1) for the post title (only the first H1 is used)
- **Sections**: Use Heading 2 (H2) for section headings
- **Content**: Regular paragraphs under each section
- **Images**: Each section will automatically get a relevant image

## How It Works

1. **Parse**: Extracts title and sections from the .docx file
2. **SEO**: Uses Anthropic AI to generate meta description, focus keyword, and other SEO data
3. **Images**: For each section:
   - AI generates an image search query
   - Fetches matching image from Pexels
   - Resizes and converts to WebP format
   - Adds to WordPress Media Library with alt text
4. **Publish**: Creates the WordPress post with all content, images, and SEO metadata

## Troubleshooting

### "API keys are not configured"

Go to Settings and ensure both API keys are saved correctly.

### "No files uploaded" or upload fails

- Check that your file is a valid `.docx` (not `.doc`)
- Verify the file size doesn't exceed your server's `upload_max_filesize`
- Check `wp-content/debug.log` for detailed error messages

### Files stuck in "Queued" status

WordPress cron may not be running. Add this to your server's crontab:
```bash
* * * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Or use a real cron service like WP-CLI:
```bash
wp cron event run --due-now
```

### "Failed to create upload directory"

Check that `wp-content/uploads/` is writable by the web server:
```bash
chmod 755 wp-content/uploads
chown www-data:www-data wp-content/uploads
```

### Images not appearing

- Verify your Pexels API key is valid
- Check that your server can reach `api.pexels.com`
- Ensure GD or Imagick extension is enabled for image processing

## Database Table

The plugin creates a table `wp_bp_jobs` to track processing jobs. This table is automatically created on activation and cleaned up as jobs complete.

## Uninstall

To completely remove the plugin:

1. Deactivate the plugin in WordPress
2. Delete the plugin from WordPress
3. Optionally, remove the database table:
   ```sql
   DROP TABLE IF EXISTS wp_bp_jobs;
   ```

## Support

For issues and feature requests, please open an issue on the [GitHub repository](https://github.com/your-repo/blog-publisher-wp).

## License

GPL-2.0+

## Credits

- Built with [Anthropic API](https://www.anthropic.com/api)
- Images from [Pexels](https://www.pexels.com)
