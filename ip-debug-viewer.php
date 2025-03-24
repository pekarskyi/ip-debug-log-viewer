<?php
/**
 * IP Debug Log Viewer
 *
 * Displays the contents of debug.log in a convenient format
 * with grouping of similar messages and color highlighting of error types.
 *
 * @package     IP Debug Log Viewer
 * @version     1.0.0
 * @author      InwebPress
 * @link        https://github.com/pekarskyi/ip-debug-log-viewer
 * @license     GPL-3.0+
 */

// Check if the user has administrative rights
if (!function_exists('is_user_logged_in') || !function_exists('current_user_can')) {
    require_once(dirname(__FILE__) . '/wp-load.php');
}

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Access denied!');
}

// Path to debug.log file
$debug_file = dirname(__FILE__) . '/wp-content/debug.log';

// Handle file clearing
if (isset($_POST['clear_log']) && check_admin_referer('clear_debug_log')) {
    file_put_contents($debug_file, '');
    wp_redirect($_SERVER['REQUEST_URI']);
    exit;
}

// Read the contents of debug.log file
$log_content = file_exists($debug_file) ? file_get_contents($debug_file) : '';

// Get the view mode
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'errors';

// Process logs and group similar messages
$error_groups = [];
$pattern = '/\[(.*?)\] (PHP .*?): (.*?) in (.*?) on line (\d+)/';
$fatal_pattern = '/\[(.*?)\] (PHP Fatal error): (.*)/';

if (!empty($log_content) && $view_mode == 'errors') {
    $lines = explode("\n", $log_content);
    
    foreach ($lines as $line) {
        if (empty(trim($line))) {
            continue;
        }
        
        if (preg_match($pattern, $line, $matches)) {
            $timestamp = $matches[1];
            $error_type = $matches[2];
            $message = $matches[3];
            $file = $matches[4];
            $line_num = $matches[5];
            
            // Remove HTML tags for better display
            $clean_message = strip_tags(html_entity_decode($message));
            
            // Create a key for grouping (error type + message + file + line)
            $group_key = $error_type . '|' . $clean_message . '|' . $file . '|' . $line_num;
            
            if (!isset($error_groups[$group_key])) {
                $error_groups[$group_key] = [
                    'type' => $error_type,
                    'message' => $clean_message,
                    'file' => $file,
                    'line' => $line_num,
                    'timestamps' => [$timestamp],
                    'count' => 1,
                    'component' => identify_component($file, $clean_message)
                ];
            } else {
                $error_groups[$group_key]['timestamps'][] = $timestamp;
                $error_groups[$group_key]['count']++;
            }
        } elseif (preg_match($fatal_pattern, $line, $matches)) {
            // Handle PHP Fatal error specially
            $timestamp = $matches[1];
            $error_type = $matches[2];
            $message = $matches[3];
            
            // Try to extract file and line info if available
            $file = '';
            $line_num = '';
            if (preg_match('/in (.*?) on line (\d+)/', $message, $file_matches)) {
                $file = $file_matches[1];
                $line_num = $file_matches[2];
                // Remove the file info from message to avoid duplication
                $message = preg_replace('/in (.*?) on line (\d+)/', '', $message);
            }
            
            // Remove HTML tags for better display
            $clean_message = strip_tags(html_entity_decode($message));
            
            // Create a key for grouping fatal errors
            $group_key = $error_type . '|' . $clean_message . '|' . $file . '|' . $line_num;
            
            if (!isset($error_groups[$group_key])) {
                $error_groups[$group_key] = [
                    'type' => $error_type,
                    'message' => $clean_message,
                    'file' => $file,
                    'line' => $line_num,
                    'timestamps' => [$timestamp],
                    'count' => 1,
                    'component' => identify_component($file, $clean_message)
                ];
            } else {
                $error_groups[$group_key]['timestamps'][] = $timestamp;
                $error_groups[$group_key]['count']++;
            }
        } else {
            // If the line doesn't match the patterns, try to identify error type
            $error_type = 'Unknown';
            $message = $line;
            $file = '';
            $line_num = '';
            $timestamp = '';
            
            // Try to extract timestamp if available
            if (preg_match('/\[(.*?)\]/', $line, $timestamp_match)) {
                $timestamp = $timestamp_match[1];
                // Remove timestamp from message
                $message = trim(str_replace('['.$timestamp.']', '', $message));
            }
            
            // Try to determine if it's a PHP error message
            if (strpos($line, 'PHP') !== false) {
                if (strpos($line, 'Fatal error') !== false) {
                    $error_type = 'PHP Fatal error';
                } elseif (strpos($line, 'Warning') !== false) {
                    $error_type = 'PHP Warning';
                } elseif (strpos($line, 'Notice') !== false) {
                    $error_type = 'PHP Notice';
                } elseif (strpos($line, 'Deprecated') !== false) {
                    $error_type = 'PHP Deprecated';
                } elseif (strpos($line, 'Parse error') !== false) {
                    $error_type = 'PHP Parse error';
                }
            }
            
            // Create a unique key for unknown error types
            $group_key = $error_type . '|' . md5($line);
            
            if (!isset($error_groups[$group_key])) {
                $error_groups[$group_key] = [
                    'type' => $error_type,
                    'message' => $message,
                    'file' => $file,
                    'line' => $line_num,
                    'timestamps' => [$timestamp],
                    'count' => 1,
                    'component' => identify_component('', $message)
                ];
            } else {
                $error_groups[$group_key]['timestamps'][] = $timestamp;
                $error_groups[$group_key]['count']++;
            }
        }
    }
}

/**
 * Determines the plugin or theme associated with the error
 *
 * @param string $file     Path to the file with the error
 * @param string $message  Error message
 * @return array           Component information [name, type, status]
 */
function identify_component($file, $message) {
    $component = [
        'name' => 'WordPress Core',
        'type' => 'core',
        'status' => 'active',
    ];
    
    $plugins_dir = 'wp-content/plugins/';
    $themes_dir = 'wp-content/themes/';
    
    // Check if the error is related to a plugin
    if (strpos($file, $plugins_dir) !== false) {
        $parts = explode($plugins_dir, $file);
        if (isset($parts[1])) {
            $plugin_parts = explode('/', $parts[1]);
            $plugin_slug = $plugin_parts[0];
            
            $component['name'] = get_plugin_name($plugin_slug);
            $component['type'] = 'plugin';
            $component['status'] = is_plugin_active($plugin_slug . '/' . $plugin_slug . '.php') ? 'active' : 'inactive';
            
            // Check other plugin activity options if the main file has a different name
            if ($component['status'] === 'inactive') {
                if ($plugin_files = glob(WP_PLUGIN_DIR . '/' . $plugin_slug . '/*.php')) {
                    foreach ($plugin_files as $plugin_file) {
                        $plugin_file = str_replace(WP_PLUGIN_DIR . '/', '', $plugin_file);
                        if (is_plugin_active($plugin_file)) {
                            $component['status'] = 'active';
                            break;
                        }
                    }
                }
            }
        }
    } 
    // Check if the error is related to a theme
    elseif (strpos($file, $themes_dir) !== false) {
        $parts = explode($themes_dir, $file);
        if (isset($parts[1])) {
            $theme_parts = explode('/', $parts[1]);
            $theme_slug = $theme_parts[0];
            
            $component['name'] = get_theme_name($theme_slug);
            $component['type'] = 'theme';
            $component['status'] = wp_get_theme()->get_template() === $theme_slug ? 'active' : 'inactive';
            
            // Check if this is a child theme
            if ($component['status'] === 'inactive' && wp_get_theme()->get_stylesheet() === $theme_slug) {
                $component['status'] = 'active_child';
            }
        }
    }
    
    // Additional check by message text for mentions of plugins or themes
    if ($component['type'] === 'core') {
        // Check for Zakra theme mentioned in the message
        if (strpos(strtolower($message), 'zakra') !== false) {
            $component['name'] = get_theme_name('zakra') ?: 'Zakra Theme';
            $component['type'] = 'theme';
            $component['status'] = wp_get_theme()->get_template() === 'zakra' ? 'active' : 'inactive';
        }
        
        // Check for other components mentioned in the message
        foreach (get_plugins() as $plugin_path => $plugin_data) {
            $plugin_name = strtolower($plugin_data['Name']);
            $plugin_slug = dirname($plugin_path);
            
            if (!empty($plugin_slug) && strpos(strtolower($message), $plugin_slug) !== false || 
                strpos(strtolower($message), $plugin_name) !== false) {
                $component['name'] = $plugin_data['Name'];
                $component['type'] = 'plugin';
                $component['status'] = is_plugin_active($plugin_path) ? 'active' : 'inactive';
                break;
            }
        }
        
        // Check for themes mentioned in the message
        $themes = wp_get_themes();
        foreach ($themes as $theme_slug => $theme_obj) {
            $theme_name = strtolower($theme_obj->get('Name'));
            
            if (strpos(strtolower($message), strtolower($theme_slug)) !== false || 
                strpos(strtolower($message), $theme_name) !== false) {
                $component['name'] = $theme_obj->get('Name');
                $component['type'] = 'theme';
                $component['status'] = wp_get_theme()->get_template() === $theme_slug ? 'active' : 'inactive';
                
                if ($component['status'] === 'inactive' && wp_get_theme()->get_stylesheet() === $theme_slug) {
                    $component['status'] = 'active_child';
                }
                break;
            }
        }
    }
    
    return $component;
}

/**
 * Gets the plugin name by its slug
 */
function get_plugin_name($plugin_slug) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $plugins = get_plugins();
    foreach ($plugins as $plugin_path => $plugin_data) {
        if (strpos($plugin_path, $plugin_slug . '/') === 0) {
            return $plugin_data['Name'];
        }
    }
    
    // If the name is not found, return slug with capital letter
    return ucfirst(str_replace('-', ' ', $plugin_slug));
}

/**
 * Gets the theme name by its slug
 */
function get_theme_name($theme_slug) {
    $theme = wp_get_theme($theme_slug);
    if ($theme->exists()) {
        return $theme->get('Name');
    }
    
    // If the theme is not found, return slug with capital letter
    return ucfirst(str_replace('-', ' ', $theme_slug));
}

/**
 * Gets the debug settings from wp-config.php
 */
function get_debug_status() {
    $debug_status = [
        'WP_DEBUG' => defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled',
        'WP_DEBUG_LOG' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled',
        'WP_DEBUG_DISPLAY' => defined('WP_DEBUG_DISPLAY') ? (WP_DEBUG_DISPLAY ? 'Enabled' : 'Disabled') : 'Not defined (Default: Enabled)',
        'SCRIPT_DEBUG' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'Enabled' : 'Disabled',
        'SAVEQUERIES' => defined('SAVEQUERIES') && SAVEQUERIES ? 'Enabled' : 'Disabled',
        'DISPLAY_ERRORS' => ini_get('display_errors') ? 'Enabled' : 'Disabled',
    ];
    
    return $debug_status;
}

/**
 * Gets server environment information
 */
function get_server_environment() {
    global $wpdb;
    
    $server_info = [];
    
    // WordPress Information
    $server_info['WordPress'] = [
        'Version' => get_bloginfo('version'),
        'Site URL' => get_bloginfo('url'),
        'Home URL' => get_home_url(),
        'Is Multisite' => is_multisite() ? 'Yes' : 'No',
        'WP Memory Limit' => WP_MEMORY_LIMIT,
    ];
    
    // Debug Information
    $server_info['Debug Settings'] = get_debug_status();
    
    // Server Information
    $server_info['Server'] = [
        'Operating System' => PHP_OS,
        'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'PHP Version' => phpversion(),
        'PHP SAPI' => php_sapi_name(),
        'PHP Memory Limit' => ini_get('memory_limit'),
        'PHP Max Execution Time' => ini_get('max_execution_time') . ' seconds',
        'PHP Max Input Vars' => ini_get('max_input_vars'),
        'PHP Post Max Size' => ini_get('post_max_size'),
        'PHP Upload Max Filesize' => ini_get('upload_max_filesize'),
        'cURL Version' => function_exists('curl_version') ? curl_version()['version'] : 'Not available',
        'SUHOSIN Installed' => extension_loaded('suhosin') ? 'Yes' : 'No',
    ];
    
    // MySQL/MariaDB Information
    $mysql_version = $wpdb->get_var('SELECT VERSION()');
    $server_info['Database'] = [
        'MySQL/MariaDB Version' => $mysql_version,
        'MySQL/MariaDB Character Set' => $wpdb->charset,
        'MySQL/MariaDB Collation' => $wpdb->collate,
        'Table Prefix' => $wpdb->prefix,
        'Table Prefix Length' => strlen($wpdb->prefix),
    ];
    
    return $server_info;
}

/**
 * Gets list of plugins with versions and status
 */
function get_plugins_list() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', []);
    
    $plugins_list = [];
    
    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $is_active = in_array($plugin_path, $active_plugins) ? true : false;
        
        $plugins_list[$plugin_path] = [
            'name' => $plugin_data['Name'],
            'version' => $plugin_data['Version'],
            'author' => $plugin_data['Author'],
            'description' => $plugin_data['Description'],
            'status' => $is_active ? 'active' : 'inactive'
        ];
    }
    
    return $plugins_list;
}

/**
 * Gets list of themes with versions and status
 */
function get_themes_list() {
    $all_themes = wp_get_themes();
    $current_theme = wp_get_theme();
    
    $themes_list = [];
    
    foreach ($all_themes as $theme_slug => $theme_obj) {
        $status = 'inactive';
        
        if ($current_theme->get_stylesheet() === $theme_slug) {
            $status = 'active';
        } else if ($current_theme->get_template() === $theme_slug && $current_theme->get_stylesheet() !== $theme_slug) {
            $status = 'parent';
        }
        
        $themes_list[$theme_slug] = [
            'name' => $theme_obj->get('Name'),
            'version' => $theme_obj->get('Version'),
            'author' => $theme_obj->get('Author'),
            'description' => $theme_obj->get('Description'),
            'status' => $status
        ];
    }
    
    return $themes_list;
}

// Define color for error type
function get_error_color($error_type) {
    if (strpos($error_type, 'Fatal') !== false) {
        return '#FF0000'; // Bright red for Fatal errors
    } elseif (strpos($error_type, 'Parse error') !== false) {
        return '#FF3D00'; // Orange-red for Parse errors
    } elseif (strpos($error_type, 'Warning') !== false) {
        return '#FFC107';
    } elseif (strpos($error_type, 'Notice') !== false) {
        return '#2196F3';
    } elseif (strpos($error_type, 'Deprecated') !== false) {
        return '#9C27B0';
    } else {
        return '#78909C';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Debug Log Viewer</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f0f1;
            color: #3c434a;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        h1, h2 {
            color: #1d2327;
            margin-top: 0;
            border-bottom: 1px solid #c3c4c7;
            padding-bottom: 15px;
        }
        .controls {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .button-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .button {
            display: inline-block;
            background-color: #2271b1;
            color: #fff;
            padding: 8px 16px;
            border-radius: 3px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .button:hover {
            background-color: #135e96;
        }
        .button.active {
            background-color: #135e96;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        .button.danger {
            background-color: #b32d2e;
        }
        .button.danger:hover {
            background-color: #8c2424;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #c3c4c7;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #f0f0f1;
            font-weight: 600;
        }
        tr:nth-child(even) {
            background-color: #f6f7f7;
        }
        .error-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #2271b1;
            color: white;
            border-radius: 50%;
            min-width: 24px;
            height: 24px;
            text-align: center;
            margin-left: 8px;
            font-size: 12px;
            font-weight: 600;
            padding: 0 4px;
        }
        .timestamp-preview {
            color: #646970;
            font-size: 12px;
        }
        .timestamps {
            display: none;
            margin-top: 10px;
            background: #f6f7f7;
            padding: 10px;
            border-radius: 4px;
            font-size: 13px;
            max-height: 200px;
            overflow-y: auto;
        }
        .toggle-timestamps {
            display: inline-block;
            cursor: pointer;
            color: #2271b1;
            font-size: 13px;
            margin-top: 5px;
        }
        .toggle-timestamps:hover {
            color: #135e96;
            text-decoration: underline;
        }
        .no-errors {
            padding: 40px;
            text-align: center;
            font-size: 16px;
            color: #646970;
        }
        .error-type {
            font-weight: 600;
            border-left: 3px solid;
            padding-left: 8px;
        }
        .error-type.fatal {
            color: #FF0000;
            font-weight: 700;
        }
        .file-info {
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            word-break: break-all;
        }
        .component {
            display: inline-flex;
            align-items: center;
            font-size: 13px;
        }
        .component-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            margin-right: 6px;
            color: white;
            font-weight: 500;
        }
        .component-core {
            background-color: #3949AB;
        }
        .component-plugin {
            background-color: #00897B;
        }
        .component-theme {
            background-color: #D81B60;
        }
        .status-badge {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }
        .status-active, .status-parent {
            background-color: #4CAF50;
        }
        .status-active_child {
            background-color: #8BC34A;
        }
        .status-inactive {
            background-color: #9E9E9E;
        }
        .section {
            margin-bottom: 25px;
        }
        .info-group {
            margin-bottom: 20px;
        }
        .info-group h3 {
            margin-bottom: 10px;
            color: #2271b1;
        }
        dl {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 8px 16px;
            margin: 0;
        }
        dt {
            font-weight: 600;
        }
        dd {
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>IP Debug Log Viewer</h1>
        
        <div class="controls">
            <div class="button-group">
                <a href="<?php echo admin_url(); ?>" class="button">← Admin Panel</a>
                <a href="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" class="button">Refresh</a>
            </div>
            
            <div class="button-group">
                <a href="?view=errors" class="button <?php echo ($view_mode == 'errors') ? 'active' : ''; ?>">Error Log</a>
                <a href="?view=server" class="button <?php echo ($view_mode == 'server') ? 'active' : ''; ?>">Server Environment</a>
                <a href="?view=plugins" class="button <?php echo ($view_mode == 'plugins') ? 'active' : ''; ?>">Plugins List</a>
                <a href="?view=themes" class="button <?php echo ($view_mode == 'themes') ? 'active' : ''; ?>">Themes List</a>
                <a href="?view=debug" class="button <?php echo ($view_mode == 'debug') ? 'active' : ''; ?>">Debug Status</a>
            </div>
            
            <?php if ($view_mode == 'errors'): ?>
            <form method="post">
                <?php wp_nonce_field('clear_debug_log'); ?>
                <button type="submit" name="clear_log" class="button danger" onclick="return confirm('Are you sure you want to clear the debug.log file?');">Clear Log</button>
            </form>
            <?php endif; ?>
        </div>
        
        <?php if ($view_mode == 'errors'): ?>
            <?php if (empty($error_groups)): ?>
                <div class="no-errors">
                    <p>The debug.log file is empty or does not contain error messages.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Error Type</th>
                            <th>Message</th>
                            <th>Component</th>
                            <th>File & Line</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($error_groups as $error): ?>
                            <tr>
                                <td>
                                    <div class="error-type <?php echo (strpos($error['type'], 'Fatal') !== false) ? 'fatal' : ''; ?>" style="border-color: <?php echo get_error_color($error['type']); ?>;">
                                        <?php echo esc_html($error['type']); ?>
                                        <?php if ($error['count'] > 1): ?>
                                            <span class="error-count"><?php echo $error['count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo esc_html($error['message']); ?></td>
                                <td>
                                    <div class="component">
                                        <span class="component-badge component-<?php echo esc_attr($error['component']['type']); ?>">
                                            <?php echo ucfirst(esc_html($error['component']['type'])); ?>
                                        </span>
                                        <span>
                                            <span class="status-badge status-<?php echo esc_attr($error['component']['status']); ?>" 
                                                title="<?php echo ucfirst(str_replace('_', ' ', esc_attr($error['component']['status']))); ?>"></span>
                                            <?php echo esc_html($error['component']['name']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="file-info">
                                    <?php if (!empty($error['file'])): ?>
                                        <?php echo esc_html($error['file']); ?>:<?php echo esc_html($error['line']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($error['timestamps'][0])): ?>
                                        <div class="timestamp-preview">
                                            <?php echo esc_html($error['timestamps'][0]); ?>
                                            <?php if (count($error['timestamps']) > 1): ?>
                                                <span class="toggle-timestamps" onclick="toggleTimestamps(this)">+ show all</span>
                                                <div class="timestamps">
                                                    <?php foreach ($error['timestamps'] as $timestamp): ?>
                                                        <div><?php echo esc_html($timestamp); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
        <?php elseif ($view_mode == 'server'): ?>
            <?php $server_info = get_server_environment(); ?>
            <h2>Server Environment</h2>
            
            <?php foreach ($server_info as $section => $info): ?>
                <div class="info-group">
                    <h3><?php echo esc_html($section); ?></h3>
                    <dl>
                        <?php foreach ($info as $key => $value): ?>
                            <dt><?php echo esc_html($key); ?></dt>
                            <dd><?php echo esc_html($value); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>
            
        <?php elseif ($view_mode == 'plugins'): ?>
            <?php 
            $plugins = get_plugins_list(); 
            $total_plugins = count($plugins);
            $active_plugins = count(array_filter($plugins, function($plugin) {
                return $plugin['status'] === 'active';
            }));
            ?>
            <h2>Plugins List <span style="font-size: 14px; font-weight: normal; color: #646970; margin-left: 10px;">
                (<?php echo $total_plugins; ?> total, <?php echo $active_plugins; ?> active)
            </span></h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Plugin Name</th>
                        <th>Version</th>
                        <th>Author</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $plugin_path => $plugin): ?>
                        <tr>
                            <td><?php echo esc_html($plugin['name']); ?></td>
                            <td><?php echo esc_html($plugin['version']); ?></td>
                            <td><?php echo wp_kses_post($plugin['author']); ?></td>
                            <td>
                                <div class="component">
                                    <span class="status-badge status-<?php echo $plugin['status']; ?>" 
                                          title="<?php echo ucfirst($plugin['status']); ?>"></span>
                                    <?php echo ucfirst(esc_html($plugin['status'])); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
        <?php elseif ($view_mode == 'themes'): ?>
            <?php 
            $themes = get_themes_list(); 
            $total_themes = count($themes);
            $active_themes = count(array_filter($themes, function($theme) {
                return $theme['status'] === 'active' || $theme['status'] === 'parent';
            }));
            ?>
            <h2>Themes List <span style="font-size: 14px; font-weight: normal; color: #646970; margin-left: 10px;">
                (<?php echo $total_themes; ?> total, <?php echo $active_themes; ?> active)
            </span></h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Theme Name</th>
                        <th>Version</th>
                        <th>Author</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($themes as $theme_slug => $theme): ?>
                        <tr>
                            <td><?php echo esc_html($theme['name']); ?></td>
                            <td><?php echo esc_html($theme['version']); ?></td>
                            <td><?php echo wp_kses_post($theme['author']); ?></td>
                            <td>
                                <div class="component">
                                    <span class="status-badge status-<?php echo $theme['status']; ?>" 
                                          title="<?php echo ucfirst($theme['status']); ?>"></span>
                                    <?php echo ucfirst(esc_html($theme['status'])); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <?php if ($view_mode == 'debug'): ?>
            <?php $debug_info = get_debug_status(); ?>
            <h2>WordPress Debug Status</h2>
            
            <div class="info-group">
                <table>
                    <thead>
                        <tr>
                            <th>Directive</th>
                            <th>Status</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>WP_DEBUG</strong></td>
                            <td><span style="color: <?php echo ($debug_info['WP_DEBUG'] === 'Enabled') ? '#4CAF50' : '#FF5252'; ?>; font-weight: 600;"><?php echo $debug_info['WP_DEBUG']; ?></span></td>
                            <td>Main WordPress debugging switch. When enabled, all PHP errors, warnings, and notices are displayed.</td>
                        </tr>
                        <tr>
                            <td><strong>WP_DEBUG_LOG</strong></td>
                            <td><span style="color: <?php echo ($debug_info['WP_DEBUG_LOG'] === 'Enabled') ? '#4CAF50' : '#FF5252'; ?>; font-weight: 600;"><?php echo $debug_info['WP_DEBUG_LOG']; ?></span></td>
                            <td>When enabled, all errors are logged to the debug.log file in the wp-content directory.</td>
                        </tr>
                        <tr>
                            <td><strong>WP_DEBUG_DISPLAY</strong></td>
                            <td><span style="color: <?php echo ($debug_info['WP_DEBUG_DISPLAY'] === 'Enabled') ? '#4CAF50' : (($debug_info['WP_DEBUG_DISPLAY'] === 'Disabled') ? '#FF5252' : '#FFC107'); ?>; font-weight: 600;"><?php echo $debug_info['WP_DEBUG_DISPLAY']; ?></span></td>
                            <td>Determines whether or not PHP errors are displayed in the browser. Used in conjunction with WP_DEBUG.</td>
                        </tr>
                        <tr>
                            <td><strong>SCRIPT_DEBUG</strong></td>
                            <td><span style="color: <?php echo ($debug_info['SCRIPT_DEBUG'] === 'Enabled') ? '#4CAF50' : '#FF5252'; ?>; font-weight: 600;"><?php echo $debug_info['SCRIPT_DEBUG']; ?></span></td>
                            <td>When enabled, WordPress uses unminified versions of JavaScript and CSS files.</td>
                        </tr>
                        <tr>
                            <td><strong>SAVEQUERIES</strong></td>
                            <td><span style="color: <?php echo ($debug_info['SAVEQUERIES'] === 'Enabled') ? '#4CAF50' : '#FF5252'; ?>; font-weight: 600;"><?php echo $debug_info['SAVEQUERIES']; ?></span></td>
                            <td>Saves all SQL queries into a variable for analysis. Useful for optimizing database queries.</td>
                        </tr>
                        <tr>
                            <td><strong>display_errors</strong></td>
                            <td><span style="color: <?php echo ($debug_info['DISPLAY_ERRORS'] === 'Enabled') ? '#4CAF50' : '#FF5252'; ?>; font-weight: 600;"><?php echo $debug_info['DISPLAY_ERRORS']; ?></span></td>
                            <td>PHP setting that determines whether errors are displayed in the browser.</td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background-color: #f6f7f7; border-left: 4px solid #2271b1; font-size: 14px;">
                    <p><strong>How to configure:</strong> These directives can be set in the wp-config.php file. For example:</p>
                    <pre style="background-color: #f0f0f1; padding: 10px; border-radius: 3px; overflow-x: auto;">
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
define('SAVEQUERIES', true);</pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleTimestamps(element) {
            const timestamps = element.nextElementSibling;
            if (timestamps.style.display === 'block') {
                timestamps.style.display = 'none';
                element.textContent = '+ show all';
            } else {
                timestamps.style.display = 'block';
                element.textContent = '- hide';
            }
        }
    </script>
    <div style="text-align: center; padding-top: 20px; color: #646970; font-size: 13px;">
        IP Debug Log Viewer 1.0.0 &#8226; Development by <a href="https://inwebpress.com" target="_blank">InwebPress</a> &#8226; Script on <a href="https://github.com/pekarskyi/ip-debug-log-viewer" target="_blank">GitHub</a>
    </div>
</body>
</html> 