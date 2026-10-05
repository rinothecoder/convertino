<?php
/**
 * Plugin Name: Convertino
 * Description: Local page analytics, selected button clicks and conversion attribution.
 * Version: 0.1.0
 * Author: Rino
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Text Domain: convertino
 */
if (!defined('ABSPATH')) { exit; }
define('CONVERTINO_FILE', __FILE__);
require_once __DIR__ . '/includes-core.php';
require_once __DIR__ . '/includes-admin.php';
register_activation_hook(__FILE__, ['Convertino_Core', 'activate']);
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('convertino_cleanup'); });
Convertino_Core::boot();
Convertino_Admin::boot();
