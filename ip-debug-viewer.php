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

// Initialize variables for standalone mode
$is_wp_loaded = false;

// Set error handler to catch fatal errors
set_error_handler(function($severity, $message, $file, $line) {
    // Log the error
    error_log("Error: $message in $file on line $line");
    return false; // Allow standard error handler to run as well
}, E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

// Disable error display on screen
ini_set('display_errors', 0);

// Try to load WordPress
if (file_exists(dirname(__FILE__) . '/wp-load.php')) {
    try {
        // Block error output to prevent them from showing on screen
        ob_start();
        
        // Try to load WordPress
        define('WP_USE_THEMES', false);
        @require_once(dirname(__FILE__) . '/wp-load.php');
        
        // Clear output buffer, ignoring any errors
        ob_end_clean();
        
        // Check if WordPress actually loaded
        if (function_exists('wp_get_current_user')) {
            $is_wp_loaded = true;
        }
    } catch (Exception $e) {
        // Ignore errors and continue in standalone mode
        ob_end_clean();
    } catch (Error $e) {
        // Ignore fatal errors and continue in standalone mode
        ob_end_clean();
    }
}

// Restore standard error handler
restore_error_handler();

// Switch error display back on
ini_set('display_errors', 1);

// Path to debug.log file
$debug_file = dirname(__FILE__) . '/wp-content/debug.log';

// Handle file clearing
if (isset($_POST['clear_log']) && (!$is_wp_loaded || check_admin_referer('clear_debug_log'))) {
    file_put_contents($debug_file, '');
    header("Location: " . $_SERVER['REQUEST_URI']);
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

// Functions stubs for standalone mode
if (!$is_wp_loaded) {
    function is_plugin_active($plugin) {
        return false;
    }
    
    function get_plugins() {
        return [];
    }
    
    function wp_get_themes() {
        return [];
    }
    
    function wp_get_theme() {
        return new stdClass();
    }
    
    function admin_url() {
        return '#';
    }
    
    function esc_url($url) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    function wp_kses_post($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    function wp_nonce_field($action) {
        echo '<input type="hidden" name="_wpnonce" value="standalone_mode">';
    }
    
    function get_bloginfo($show = '') {
        switch ($show) {
            case 'version':
                return 'N/A (standalone mode)';
            case 'url':
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                return $protocol . '://' . $_SERVER['HTTP_HOST'];
            default:
                return 'N/A';
        }
    }
    
    function get_home_url() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'];
    }
    
    function is_multisite() {
        return false;
    }
    
    function check_admin_referer() {
        return true; // In standalone mode, allow all nonces
    }
}

if (!empty($log_content) && $view_mode == 'errors') {
    // Collect multi-line error blocks by looking for timestamps
    $error_blocks = [];
    $current_block = '';
    $lines = explode("\n", $log_content);
    $current_timestamp = '';
    
    foreach ($lines as $line) {
        if (empty(trim($line))) {
            continue;
        }
        
        // Check if line starts with a timestamp
        if (preg_match('/^\[([^\]]+)\]/', $line, $timestamp_match)) {
            // If it's a new timestamp, start a new block
            if (!empty($current_block)) {
                $error_blocks[] = $current_block;
            }
            
            $current_timestamp = $timestamp_match[1];
            $current_block = $line;
        } else {
            // Line continues the current error block
            if (!empty($current_block)) {
                $current_block .= "\n" . $line;
            }
        }
    }
    
    // Don't forget the last block
    if (!empty($current_block)) {
        $error_blocks[] = $current_block;
    }
    
    foreach ($error_blocks as $block) {
        // First line contains the timestamp and main error information
        $lines = explode("\n", $block);
        $main_line = $lines[0];
        
        // Check if this is a standard PHP error format
        if (preg_match($pattern, $main_line, $matches)) {
            $timestamp = $matches[1];
            $error_type = $matches[2];
            $message = $matches[3];
            $file = $matches[4];
            $line_num = $matches[5];
            
            // Clean the message
            $clean_message = strip_tags(html_entity_decode($message));
            
            // Визначимо, чи є ця помилка критичною
            $is_critical = (strpos($error_type, 'Fatal') !== false || 
                           strpos($error_type, 'Parse error') !== false);
            
            if ($is_critical) {
                // Для критичних помилок, зберігаємо весь блок та stack trace
                if (count($lines) > 1) {
                    $stack_trace = implode("\n", array_slice($lines, 1));
                    $clean_message .= "\n" . $stack_trace;
                }
                
                // Для критичних помилок використовуємо ту ж логіку групування, що й для звичайних
                $group_key = $error_type . '|' . $clean_message . '|' . $file . '|' . $line_num;
            } else {
                // Для некритичних помилок, об'єднуємо однакові помилки
                $group_key = $error_type . '|' . $clean_message . '|' . $file . '|' . $line_num;
            }
            
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
        } 
        // Check for fatal errors with a different pattern
        elseif (preg_match($fatal_pattern, $main_line, $matches)) {
            $timestamp = $matches[1];
            $error_type = $matches[2];
            $message = $matches[3];
            
            // Try to extract file and line info
            $file = '';
            $line_num = '';
            if (preg_match('/in (.*?) on line (\d+)/', $message, $file_matches)) {
                $file = $file_matches[1];
                $line_num = $file_matches[2];
                // Remove file info from message to avoid duplication
                $message = preg_replace('/in (.*?) on line (\d+)/', '', $message);
            }
            
            // Add stack trace to the message if available
            if (count($lines) > 1) {
                $message .= "\n" . implode("\n", array_slice($lines, 1));
            }
            
            // Clean the message
            $clean_message = strip_tags(html_entity_decode($message));
            
            // Fatal errors always show individually with full stack trace
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
        }
        // Handle unknown formats
        else {
            // Try to extract timestamp from the first line
            if (preg_match('/\[(.*?)\]/', $main_line, $timestamp_match)) {
                $timestamp = $timestamp_match[1];
                $error_type = 'Unknown';
                
                // Try to determine if it's a PHP error
                if (strpos($main_line, 'PHP') !== false) {
                    if (strpos($main_line, 'Fatal error') !== false) {
                        $error_type = 'PHP Fatal error';
                    } elseif (strpos($main_line, 'Warning') !== false) {
                        $error_type = 'PHP Warning';
                    } elseif (strpos($main_line, 'Notice') !== false) {
                        $error_type = 'PHP Notice';
                    } elseif (strpos($main_line, 'Deprecated') !== false) {
                        $error_type = 'PHP Deprecated';
                    } elseif (strpos($main_line, 'Parse error') !== false) {
                        $error_type = 'PHP Parse error';
                    }
                }
                
                // Визначимо, чи є ця помилка критичною
                $is_critical = (strpos($error_type, 'Fatal') !== false || 
                               strpos($error_type, 'Parse error') !== false);
                
                if ($is_critical) {
                    // Критичні помилки також групуємо за текстом помилки
                    $message = $block;
                    $group_key = $error_type . '|' . $message;
                } else {
                    // Для некритичних - групуємо за текстом помилки
                    $message = $main_line;
                    $group_key = $error_type . '|' . $message;
                }
                
                if (!isset($error_groups[$group_key])) {
                    $error_groups[$group_key] = [
                        'type' => $error_type,
                        'message' => $message,
                        'file' => '',
                        'line' => '',
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
    
    // Sort error groups by the most recent timestamp
    if (!empty($error_groups)) {
        uasort($error_groups, function($a, $b) {
            return strtotime(end($b['timestamps'])) - strtotime(end($a['timestamps']));
        });
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
    global $is_wp_loaded;
    
    $component = [
        'name' => 'WordPress Core',
        'type' => 'core',
        'status' => 'active',
    ];
    
    if (!$is_wp_loaded) {
        // Simplified component analysis in standalone mode
        $plugins_dir = 'wp-content/plugins/';
        $themes_dir = 'wp-content/themes/';
        
        // Check if the error is related to a plugin
        if (strpos($file, $plugins_dir) !== false) {
            $parts = explode($plugins_dir, $file);
            if (isset($parts[1])) {
                $plugin_parts = explode('/', $parts[1]);
                $plugin_slug = $plugin_parts[0];
                
                $component['name'] = ucfirst(str_replace('-', ' ', $plugin_slug));
                $component['type'] = 'plugin';
                $component['status'] = 'unknown';
            }
        } 
        // Check if the error is related to a theme
        elseif (strpos($file, $themes_dir) !== false) {
            $parts = explode($themes_dir, $file);
            if (isset($parts[1])) {
                $theme_parts = explode('/', $parts[1]);
                $theme_slug = $theme_parts[0];
                
                $component['name'] = ucfirst(str_replace('-', ' ', $theme_slug));
                $component['type'] = 'theme';
                $component['status'] = 'unknown';
            }
        }
        
        return $component;
    }
    
    // Original function code for WordPress environment
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
    global $is_wp_loaded;
    
    if (!$is_wp_loaded) {
        // In standalone mode just return formatted slug
        return ucfirst(str_replace('-', ' ', $plugin_slug));
    }
    
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
    global $is_wp_loaded;
    
    if (!$is_wp_loaded) {
        // In standalone mode just return formatted slug
        return ucfirst(str_replace('-', ' ', $theme_slug));
    }
    
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
    global $is_wp_loaded;
    
    if (!$is_wp_loaded) {
        return [
            'WP_DEBUG' => 'Unknown (Standalone mode)',
            'WP_DEBUG_LOG' => 'Unknown (Standalone mode)',
            'WP_DEBUG_DISPLAY' => 'Unknown (Standalone mode)',
            'SCRIPT_DEBUG' => 'Unknown (Standalone mode)',
            'SAVEQUERIES' => 'Unknown (Standalone mode)',
            'DISPLAY_ERRORS' => ini_get('display_errors') ? 'Enabled' : 'Disabled',
        ];
    }
    
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
    global $is_wp_loaded, $wpdb;
    
    $server_info = [];
    
    // WordPress Information
    if ($is_wp_loaded) {
        $server_info['WordPress'] = [
            'Version' => get_bloginfo('version'),
            'Site URL' => get_bloginfo('url'),
            'Home URL' => get_home_url(),
            'Is Multisite' => is_multisite() ? 'Yes' : 'No',
            'WP Memory Limit' => WP_MEMORY_LIMIT,
        ];
    } else {
        $server_info['WordPress'] = [
            'Status' => 'WordPress not loaded (standalone mode)',
        ];
    }
    
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
    if ($is_wp_loaded && isset($wpdb)) {
        $mysql_version = $wpdb->get_var('SELECT VERSION()');
        $server_info['Database'] = [
            'MySQL/MariaDB Version' => $mysql_version,
            'MySQL/MariaDB Character Set' => $wpdb->charset,
            'MySQL/MariaDB Collation' => $wpdb->collate,
            'Table Prefix' => $wpdb->prefix,
            'Table Prefix Length' => strlen($wpdb->prefix),
        ];
    } else {
        // Simplified database information in standalone mode
        $server_info['Database'] = [
            'Status' => 'Information not available (standalone mode)',
        ];
    }
    
    return $server_info;
}

/**
 * Get formatted plugins list with status information
 * 
 * @return array Formatted plugins data
 */
function get_plugins_list() {
    $plugins = array();
    
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', array());
    
    // Get the active plugin in a multisite setup
    if (is_multisite()) {
        $network_active_plugins = get_site_option('active_sitewide_plugins', array());
        $network_active_plugins = array_keys($network_active_plugins);
    } else {
        $network_active_plugins = array();
    }
    
    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $is_active = in_array($plugin_path, $active_plugins);
        $is_network_active = in_array($plugin_path, $network_active_plugins);
        
        // Determine parent/child plugin
        $is_parent = false;
        $is_child = false;
        
        if (strpos($plugin_path, '/') !== false) {
            $plugin_folder = dirname($plugin_path);
            
            foreach ($all_plugins as $other_path => $other_data) {
                if ($plugin_path === $other_path) {
                    continue;
                }
                
                if (strpos($other_path, $plugin_folder . '/') === 0) {
                    $is_parent = true;
                    break;
                }
                
                if (strpos($plugin_path, dirname($other_path) . '/') === 0) {
                    $is_child = true;
                    break;
                }
            }
        }
        
        // Set status text
        if ($is_network_active) {
            $status = 'active';
            $status_text = 'Network Active';
        } elseif ($is_active) {
            if ($is_parent) {
                $status = 'parent';
                $status_text = 'Active (Parent)';
            } elseif ($is_child) {
                $status = 'active_child';
                $status_text = 'Active (Child)'; 
            } else {
                $status = 'active';
                $status_text = 'Active';
            }
        } else {
            $status = 'inactive';
            $status_text = 'Inactive';
        }
        
        $plugin_data['Status'] = $status;
        
        // Format the plugin data
        $plugins[] = array(
            'name' => $plugin_data['Name'],
            'version' => $plugin_data['Version'],
            'status' => $status,
            'status_text' => $status_text,
            'author' => strip_tags($plugin_data['Author']),
            'path' => $plugin_path
        );
    }
    
    // Sort plugins by status (active first) and then by name
    usort($plugins, function($a, $b) {
        // First sort by status
        if ($a['status'] === 'active' && $b['status'] !== 'active') {
            return -1;
        }
        if ($a['status'] !== 'active' && $b['status'] === 'active') {
            return 1;
        }
        
        // Then by name
        return strcmp($a['name'], $b['name']);
    });
    
    return $plugins;
}

/**
 * Get formatted themes list with status information
 * 
 * @return array Formatted themes data
 */
function get_themes_list() {
    $themes = array();
    
    $all_themes = wp_get_themes();
    $current_theme = wp_get_theme();
    $current_theme_parent = $current_theme->parent();
    
    foreach ($all_themes as $theme_name => $theme_data) {
        // Determine the theme status
        if ($theme_data->get_stylesheet() === $current_theme->get_stylesheet()) {
            $status = 'active';
            $status_text = 'Active';
        } elseif ($current_theme_parent && $theme_data->get_stylesheet() === $current_theme_parent->get_stylesheet()) {
            $status = 'parent';
            $status_text = 'Active (Parent)';
        } elseif ($theme_data->parent() && $theme_data->parent()->get_stylesheet() === $current_theme->get_stylesheet()) {
            $status = 'active_child';
            $status_text = 'Child Theme';
        } else {
            $status = 'inactive';
            $status_text = 'Inactive';
        }
        
        // Format the theme data
        $themes[] = array(
            'name' => $theme_data->get('Name'),
            'version' => $theme_data->get('Version'),
            'status' => $status,
            'status_text' => $status_text,
            'author' => strip_tags($theme_data->get('Author')),
            'path' => $theme_data->get_stylesheet_directory()
        );
    }
    
    // Sort themes by status (active first) and then by name
    usort($themes, function($a, $b) {
        // First sort by status
        if ($a['status'] === 'active' && $b['status'] !== 'active') {
            return -1;
        }
        if ($a['status'] !== 'active' && $b['status'] === 'active') {
            return 1;
        }
        if ($a['status'] === 'parent' && $b['status'] !== 'parent') {
            return -1;
        }
        if ($a['status'] !== 'parent' && $b['status'] === 'parent') {
            return 1;
        }
        
        // Then by name
        return strcmp($a['name'], $b['name']);
    });
    
    return $themes;
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

/**
 * Parse debug.log file and extract error information
 * 
 * @param string $log_file Path to the debug.log file
 * @return array Parsed errors with additional metadata
 */
function parse_debug_log($log_file) {
    if (!file_exists($log_file) || !is_readable($log_file)) {
        return array();
    }
    
    $log_content = file_get_contents($log_file);
    
    if (empty($log_content)) {
        return array();
    }
    
    // Define regex pattern to match WordPress error log entries
    $pattern = '/\[([^\]]+)\] (PHP )?(\w+)( \(\d+\))?: (.+) in (.+) on line (\d+)/';
    
    preg_match_all($pattern, $log_content, $matches, PREG_SET_ORDER);
    
    $errors = array();
    $unique_errors = array();
    
    foreach ($matches as $match) {
        $timestamp = $match[1];
        $type = $match[3];
        $message = $match[5];
        $file = $match[6];
        $line = $match[7];
        
        // Create a unique key for this error
        $error_key = md5($type . $message . $file . $line);
        
        // Check if we've already seen this error
        if (isset($unique_errors[$error_key])) {
            $unique_errors[$error_key]['all_timestamps'][] = $timestamp;
            continue;
        }
        
        // Determine component (WordPress core, plugin, or theme)
        $component = '';
        $component_type = '';
        
        // Check for ABSPATH to ensure WordPress is loaded
        if (defined('ABSPATH')) {
            $normalized_file = wp_normalize_path($file);
            $normalized_abspath = wp_normalize_path(ABSPATH);
            
            if (strpos($normalized_file, $normalized_abspath) === 0) {
                $file_rel_path = substr($normalized_file, strlen($normalized_abspath));
                
                // WordPress core files
                if (strpos($file_rel_path, 'wp-admin/') === 0 || 
                    strpos($file_rel_path, 'wp-includes/') === 0 || 
                    preg_match('/^wp-[^\/]+\.php$/', $file_rel_path)) {
                    $component = 'WordPress Core';
                    $component_type = 'Core';
                }
                
                // Plugin files
                elseif (strpos($file_rel_path, 'wp-content/plugins/') === 0) {
                    $plugin_path = substr($file_rel_path, strlen('wp-content/plugins/'));
                    $plugin_dir = explode('/', $plugin_path)[0];
                    
                    if (!empty($plugin_dir)) {
                        $component = $plugin_dir;
                        $component_type = 'Plugin';
                        
                        // Try to get the actual plugin name if WP is loaded
                        if (function_exists('get_plugins')) {
                            $all_plugins = get_plugins();
                            foreach ($all_plugins as $path => $data) {
                                if (strpos($path, $plugin_dir . '/') === 0 || $path === $plugin_dir . '.php') {
                                    $component = $data['Name'];
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // Theme files
                elseif (strpos($file_rel_path, 'wp-content/themes/') === 0) {
                    $theme_path = substr($file_rel_path, strlen('wp-content/themes/'));
                    $theme_dir = explode('/', $theme_path)[0];
                    
                    if (!empty($theme_dir)) {
                        $component = $theme_dir;
                        $component_type = 'Theme';
                        
                        // Try to get the actual theme name if WP is loaded
                        if (function_exists('wp_get_themes')) {
                            $all_themes = wp_get_themes();
                            if (isset($all_themes[$theme_dir])) {
                                $component = $all_themes[$theme_dir]->get('Name');
                            }
                        }
                    }
                }
            }
        }
        
        $error = array(
            'timestamp' => $timestamp,
            'type' => $type,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'component' => $component,
            'component_type' => $component_type,
            'all_timestamps' => array($timestamp)
        );
        
        $unique_errors[$error_key] = $error;
        $errors[] = $error;
    }
    
    // Sort errors by most recent timestamp
    usort($errors, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $errors;
}

/**
 * Fallback implementations for standalone mode
 * These functions provide minimal implementations of WordPress functions
 * when running the script without WordPress loaded
 */

// Fallback for wp_normalize_path
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path) {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('|/+|', '/', $path);
        return $path;
    }
}

// Fallback for size_format
if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $decimals) . ' ' . $units[$pow];
    }
}

// Fallback for wp_max_upload_size
if (!function_exists('wp_max_upload_size')) {
    function wp_max_upload_size() {
        $max_upload = (int)(ini_get('upload_max_filesize'));
        $max_post = (int)(ini_get('post_max_size'));
        
        return min($max_upload, $max_post) * 1024 * 1024;
    }
}

// Fallback for sanitize_html_class
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class, $fallback = '') {
        // Strip out any special characters
        $class = preg_replace('/[^A-Za-z0-9_-]/', '', $class);
        
        if ('' === $class) {
            $class = $fallback;
        }
        
        return $class;
    }
}

// Fallback for esc_html
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// Fallback for esc_url
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
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
        .status-inactive, .status-unknown {
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
        .standalone-notice {
            padding: 10px 15px;
            margin-bottom: 20px;
            background-color: #FCF8E3;
            border-left: 4px solid #F0AD4E;
            color: #8A6D3B;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>IP Debug Log Viewer</h1>
        
        <?php if (!$is_wp_loaded): ?>
        <div class="standalone-notice">
            <p><strong>Standalone mode activated:</strong> WordPress could not be loaded, but you can still view the error log. Some functions may be unavailable.</p>
        </div>
        <?php endif; ?>
        
        <div class="controls">
            <div class="button-group">
                <?php if ($is_wp_loaded): ?>
                <a href="<?php echo admin_url(); ?>" class="button">← Admin Panel</a>
                <?php else: ?>
                <a href="/" class="button">← Home</a>
                <?php endif; ?>
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
                <?php if ($is_wp_loaded): ?>
                    <?php wp_nonce_field('clear_debug_log'); ?>
                <?php else: ?>
                    <input type="hidden" name="_wpnonce" value="standalone_mode">
                <?php endif; ?>
                <button type="submit" name="clear_log" class="button danger" onclick="return confirm('Are you sure you want to clear the debug.log file?');">Clear Log</button>
            </form>
            <?php endif; ?>
        </div>
        
        <?php if ($view_mode == 'errors'): ?>
            <?php if (empty($error_groups)): ?>
                <div class="no-errors">
                    <p>No errors found in the debug log.</p>
                    <p>If you're experiencing issues but don't see errors here, check that <code>WP_DEBUG</code> is enabled in your configuration.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Error</th>
                            <th>Location</th>
                            <th>Component</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($error_groups as $error): ?>
                        <tr>
                            <td>
                                <div class="error-type <?php echo strtolower($error['type']); ?>"><?php echo esc_html($error['type']); ?></div>
                                <div><?php echo esc_html($error['message']); ?></div>
                                <?php if ($error['count'] > 1): ?>
                                    <span class="error-count"><?php echo esc_html($error['count']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="file-info">
                                <?php if (!empty($error['file'])): ?>
                                    <?php echo esc_html($error['file']); ?>:<?php echo esc_html($error['line']); ?>
                                <?php else: ?>
                                    Unknown location
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="component">
                                    <?php if (!empty($error['component'])): ?>
                                        <span class="component-badge component-<?php echo sanitize_html_class(strtolower($error['component']['type'])); ?>">
                                            <?php echo esc_html($error['component']['type']); ?>
                                        </span>
                                        <?php echo esc_html($error['component']['name']); ?>
                                    <?php else: ?>
                                        Unknown
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="timestamp-preview">
                                    <?php echo esc_html($error['timestamps'][0]); ?>
                                </div>
                                <?php if (!empty($error['timestamps']) && count($error['timestamps']) > 1): ?>
                                    <a class="toggle-timestamps" onclick="toggleTimestamps(this)">+ <?php echo count($error['timestamps']) - 1; ?> more occurrences</a>
                                    <div class="timestamps">
                                        <?php foreach ($error['timestamps'] as $timestamp): ?>
                                            <div><?php echo esc_html($timestamp); ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
        <?php elseif ($view_mode == 'server'): ?>
            <div class="section">
                <div class="info-group">
                    <h3>Server Information</h3>
                    <dl>
                        <dt>PHP Version</dt>
                        <dd><?php echo esc_html(phpversion()); ?></dd>
                        
                        <dt>Server Software</dt>
                        <dd><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></dd>
                        
                        <dt>Database</dt>
                        <dd>
                            <?php if ($is_wp_loaded && function_exists('mysqli_get_server_info')): ?>
                                MySQL: <?php echo esc_html(mysqli_get_server_info($GLOBALS['wpdb']->dbh)); ?>
                            <?php else: ?>
                                MySQL: (Information unavailable)
                            <?php endif; ?>
                        </dd>
                        
                        <dt>Operating System</dt>
                        <dd><?php echo esc_html(PHP_OS); ?></dd>
                        
                        <dt>PHP SAPI</dt>
                        <dd><?php echo esc_html(php_sapi_name()); ?></dd>
                        
                        <dt>Max Upload Size</dt>
                        <dd><?php echo esc_html(size_format(wp_max_upload_size())); ?></dd>
                        
                        <dt>Max Execution Time</dt>
                        <dd><?php echo esc_html(ini_get('max_execution_time')); ?> seconds</dd>
                        
                        <dt>Memory Limit</dt>
                        <dd><?php echo esc_html(ini_get('memory_limit')); ?></dd>
                        
                        <dt>Post Max Size</dt>
                        <dd><?php echo esc_html(ini_get('post_max_size')); ?></dd>
                        
                        <dt>Max Input Vars</dt>
                        <dd><?php echo esc_html(ini_get('max_input_vars')); ?></dd>
                        
                        <dt>Display Errors</dt>
                        <dd><?php echo ini_get('display_errors') ? 'On' : 'Off'; ?></dd>
                    </dl>
                </div>
                
                <?php if ($is_wp_loaded): ?>
                <div class="info-group">
                    <h3>WordPress Information</h3>
                    <dl>
                        <dt>WordPress Version</dt>
                        <dd><?php echo esc_html($wp_version); ?></dd>
                        
                        <dt>Site URL</dt>
                        <dd><?php echo esc_html(get_site_url()); ?></dd>
                        
                        <dt>Home URL</dt>
                        <dd><?php echo esc_html(get_home_url()); ?></dd>
                        
                        <dt>Multisite</dt>
                        <dd><?php echo is_multisite() ? 'Yes' : 'No'; ?></dd>
                        
                        <dt>ABSPATH</dt>
                        <dd><?php echo esc_html(ABSPATH); ?></dd>
                        
                        <dt>Content Directory</dt>
                        <dd><?php echo esc_html(WP_CONTENT_DIR); ?></dd>
                    </dl>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($view_mode == 'plugins'): ?>
            <?php if (!$is_wp_loaded): ?>
                <div class="no-errors">
                    <p>Plugins list is unavailable in standalone mode.</p>
                    <p>WordPress must be loaded to access this information.</p>
                </div>
            <?php else: ?>
                <?php $plugins_list = get_plugins_list(); ?>
                <table>
                    <thead>
                        <tr>
                            <th>Plugin</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Author</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plugins_list as $plugin): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($plugin['name']); ?></strong>
                                <div class="file-info"><?php echo esc_html($plugin['path']); ?></div>
                            </td>
                            <td><?php echo esc_html($plugin['version']); ?></td>
                            <td>
                                <div>
                                    <span class="status-badge status-<?php echo sanitize_html_class($plugin['status']); ?>"></span>
                                    <?php echo esc_html($plugin['status_text']); ?>
                                </div>
                            </td>
                            <td><?php echo esc_html($plugin['author']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
        <?php elseif ($view_mode == 'themes'): ?>
            <?php if (!$is_wp_loaded): ?>
                <div class="no-errors">
                    <p>Themes list is unavailable in standalone mode.</p>
                    <p>WordPress must be loaded to access this information.</p>
                </div>
            <?php else: ?>
                <?php $themes_list = get_themes_list(); ?>
                <table>
                    <thead>
                        <tr>
                            <th>Theme</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Author</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($themes_list as $theme): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($theme['name']); ?></strong>
                                <div class="file-info"><?php echo esc_html(basename(dirname($theme['path'])) . '/' . basename($theme['path'])); ?></div>
                            </td>
                            <td><?php echo esc_html($theme['version']); ?></td>
                            <td>
                                <div>
                                    <span class="status-badge status-<?php echo sanitize_html_class($theme['status']); ?>"></span>
                                    <?php echo esc_html($theme['status_text']); ?>
                                </div>
                            </td>
                            <td><?php echo esc_html($theme['author']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
        <?php elseif ($view_mode == 'debug'): ?>
            <div class="section">
                <div class="info-group">
                    <h3>Debug Configuration</h3>
                    <dl>
                        <dt>WP_DEBUG</dt>
                        <dd><?php echo defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled'; ?></dd>
                        
                        <dt>WP_DEBUG_LOG</dt>
                        <dd>
                            <?php if (defined('WP_DEBUG_LOG')): ?>
                                <?php echo WP_DEBUG_LOG === true ? 'Enabled (default path)' : (is_string(WP_DEBUG_LOG) ? 'Custom: ' . esc_html(WP_DEBUG_LOG) : esc_html(var_export(WP_DEBUG_LOG, true))); ?>
                            <?php else: ?>
                                Not defined
                            <?php endif; ?>
                        </dd>
                        
                        <dt>WP_DEBUG_DISPLAY</dt>
                        <dd>
                            <?php if (defined('WP_DEBUG_DISPLAY')): ?>
                                <?php echo WP_DEBUG_DISPLAY ? 'Enabled' : 'Disabled'; ?>
                            <?php else: ?>
                                Not defined (defaults to true)
                            <?php endif; ?>
                        </dd>
                        
                        <dt>SCRIPT_DEBUG</dt>
                        <dd>
                            <?php if (defined('SCRIPT_DEBUG')): ?>
                                <?php echo SCRIPT_DEBUG ? 'Enabled' : 'Disabled'; ?>
                            <?php else: ?>
                                Not defined (defaults to false)
                            <?php endif; ?>
                        </dd>
                        
                        <dt>SAVEQUERIES</dt>
                        <dd>
                            <?php if (defined('SAVEQUERIES')): ?>
                                <?php echo SAVEQUERIES ? 'Enabled' : 'Disabled'; ?>
                            <?php else: ?>
                                Not defined (defaults to false)
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
                
                <div class="info-group">
                    <h3>Debug Log</h3>
                    <dl>
                        <dt>Log File Location</dt>
                        <dd><?php echo esc_html($debug_file); ?></dd>
                        
                        <dt>Log File Size</dt>
                        <dd><?php echo esc_html(size_format(filesize($debug_file))); ?></dd>
                        
                        <dt>Last Modified</dt>
                        <dd><?php echo esc_html(date('Y-m-d H:i:s', filemtime($debug_file))); ?></dd>
                        
                        <dt>Is Writable</dt>
                        <dd><?php echo is_writable($debug_file) ? 'Yes' : 'No'; ?></dd>
                    </dl>
                </div>
                
                <?php if ($is_wp_loaded): ?>
                <div class="info-group">
                    <h3>PHP Error Reporting</h3>
                    <dl>
                        <dt>Current Error Reporting</dt>
                        <dd><?php echo esc_html(error_reporting()); ?></dd>
                        
                        <dt>Display Errors</dt>
                        <dd><?php echo ini_get('display_errors') ? 'On' : 'Off'; ?></dd>
                        
                        <dt>Error Log Path</dt>
                        <dd><?php echo esc_html(ini_get('error_log')); ?></dd>
                    </dl>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleTimestamps(el) {
            const timestamps = el.nextElementSibling;
            if (timestamps.style.display === 'block') {
                timestamps.style.display = 'none';
                el.textContent = el.textContent.replace('Hide', '+ ') + ' more occurrences';
            } else {
                timestamps.style.display = 'block';
                el.textContent = el.textContent.replace('+ ', 'Hide ').replace(' more occurrences', '');
            }
        }
    </script>
    <div style="text-align: center; padding-top: 20px; color: #646970; font-size: 13px;">
        IP Debug Log Viewer 1.0.0 &#8226; Development <a href="https://inwebpress.com" target="_blank">InwebPress</a> &#8226; Script on <a href="https://github.com/pekarskyi/ip-debug-log-viewer" target="_blank">GitHub</a>
    </div>
</body>
</html> 