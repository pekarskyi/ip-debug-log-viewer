# IP Debug Log Viewer

**Version:** 1.0

A powerful WordPress debugging tool that displays PHP errors from debug.log in a structured, user-friendly format.

![IP Debug Log Viewer](https://via.placeholder.com/800x400?text=IP+Debug+Log+Viewer)

## Features

- **Organized Error Display**: Shows errors from debug.log in a clean, organized table format
- **Error Grouping**: Automatically groups similar errors to reduce clutter
- **Color-coded Errors**: Different error types (Fatal, Warning, Notice, etc.) are color-coded for quick identification
- **Fatal Error Highlighting**: Fatal errors are prominently displayed in bright red
- **Component Detection**: Automatically identifies which plugin, theme, or core component is causing the error
- **Server Environment Information**: Displays detailed information about your WordPress, PHP, and database configuration
- **Plugins List**: Shows all installed plugins with their versions and activation status
- **Themes List**: Shows all installed themes with their versions and activation status
- **Error Log Clearing**: One-click button to clear the debug.log file
- **Admin Integration**: Adds a convenient menu item in the WordPress admin panel for quick access

## Installation

1. **Upload Files**:
   - Upload `debug-viewer.php` to your WordPress site's root directory (where wp-config.php is located)
   - Upload `debug-log-viewer.php` to your site's `wp-content/mu-plugins/` directory (create this directory if it doesn't exist)

2. **Verify Permissions**:
   - Make sure both files have proper permissions (typically 644)

3. **Activate Debug Mode**:
   - Ensure WordPress debugging is enabled in your wp-config.php file:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

4. **Access the Viewer**:
   - Option 1: Visit `https://your-site.com/debug-viewer.php` directly
   - Option 2: Use the "IP Debug Log Viewer" link in the WordPress admin Tools menu
   - Option 3: Click the "Debug Log" button in the admin toolbar

## Security Note

The IP Debug Log Viewer is protected with WordPress authentication. Only users with administrator privileges (`manage_options` capability) can access it.

## Requirements

- WordPress 4.7 or higher
- PHP 5.6 or higher

## License

This tool is released under the GPL v2 or later license, the same as WordPress.

## Credits

Created by [InwebPress](https://inwepress.com)