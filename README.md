# IP Debug Log Viewer

[![GitHub release (latest by date)](https://img.shields.io/github/v/release/pekarskyi/ip-debug-log-viewer?style=for-the-badge)](https://GitHub.com/pekarskyi/ip-debug-log-viewer/releases/)

A powerful WordPress debugging tool that displays PHP errors from debug.log in a structured, user-friendly format.

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

## ✅ Screenshots
![https://github.com/pekarskyi/assets/raw/master/ip-debug-log-viewer/ip-debug-log-viewer_log_1-0.jpg](https://github.com/pekarskyi/assets/raw/master/ip-debug-log-viewer/ip-debug-log-viewer_log_1-0.jpg)

![https://github.com/pekarskyi/assets/raw/master/ip-debug-log-viewer/ip-debug-log-viewer_list-themes_1-0.jpg](https://github.com/pekarskyi/assets/raw/master/ip-debug-log-viewer/ip-debug-log-viewer_list-themes_1-0.jpg)

## Installation

1. **Upload Files**:
   - Upload `debug-viewer.php` to your WordPress site's root directory (where wp-config.php is located)

2. **Verify Permissions**:
   - Make sure the file has the proper permissions (usually 644)

3. **Activate Debug Mode**:
   - Ensure WordPress debugging is enabled in your wp-config.php file:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
   ```

4. **Access the Viewer**:
   - Option 1: Visit `https://your-site.com/debug-viewer.php` directly

## Changelog

1.0 - 23.03.2025:
- Stable release