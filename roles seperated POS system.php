<?php
/*
Plugin Name: roles seperated POS system
Description: A full POS and Invoice management system with WP Dashboard settings, dynamic currency, customer auto-suggest, exact timezone matching, mobile responsiveness, live active user indicators, and a Bulk Product Management System.
Version: 1.20.0
Author: Vidusha Chathuranga
*/

if (!defined('ABSPATH')) exit;

// ==========================================
// 1. DATABASE SETUP & ROLES
// ==========================================
register_activation_hook(__FILE__, 'SimpleBill_create_db');
function SimpleBill_create_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Invoices Table
    $table_invoices = $wpdb->prefix . 'SimpleBill_invoices';
    $sql_invoices = "CREATE TABLE $table_invoices (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        admin_id bigint(20) NOT NULL DEFAULT 0,
        customer_name varchar(100) NOT NULL,
        customer_phone varchar(20),
        customer_sr_no varchar(50),
        customer_address text,
        total_amount decimal(10,2) NOT NULL,
        payment_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        balance_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        items text NOT NULL,
        is_deleted tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Customers Table
    $table_customers = $wpdb->prefix . 'SimpleBill_customers';
    $sql_customers = "CREATE TABLE $table_customers (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        admin_id bigint(20) NOT NULL DEFAULT 0,
        sr_no varchar(50),
        name varchar(100) NOT NULL,
        phone varchar(20),
        address text,
        is_deleted tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Products Table (NEW)
    $table_products = $wpdb->prefix . 'SimpleBill_products';
    $sql_products = "CREATE TABLE $table_products (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        admin_id bigint(20) NOT NULL DEFAULT 0,
        sr_no varchar(50),
        hsn_code varchar(50),
        name varchar(255) NOT NULL,
        unit varchar(50),
        price decimal(10,2) NOT NULL DEFAULT 0.00,
        is_deleted tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Activity Logs Table
    $table_logs = $wpdb->prefix . 'SimpleBill_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        user_login varchar(100) NOT NULL,
        action varchar(100) NOT NULL,
        target_type varchar(100) NOT NULL,
        target_detail text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Live Chat Table
    $table_chat = $wpdb->prefix . 'SimpleBill_chat';
    $sql_chat = "CREATE TABLE $table_chat (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        sender_id bigint(20) NOT NULL,
        sender_name varchar(100) NOT NULL,
        recipient_role varchar(50) NOT NULL,
        message text NOT NULL,
        is_announcement tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY sender_id (sender_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    // Admin Plans Table
    $table_plans = $wpdb->prefix . 'SimpleBill_admin_plans';
    $sql_plans = "CREATE TABLE $table_plans (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        admin_id bigint(20) NOT NULL,
        plan_type varchar(50) NOT NULL,
        start_date datetime NOT NULL,
        end_date datetime NOT NULL,
        auto_renew tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY admin_id (admin_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_invoices);
    dbDelta($sql_customers);
    dbDelta($sql_products);
    dbDelta($sql_logs);
    dbDelta($sql_chat);
    dbDelta($sql_plans);

    add_role('SimpleBill_admin', 'SimpleBill Admin', ['read' => true, 'SimpleBill_admin_cap' => true, 'SimpleBill_view_history' => true]);
    add_role('SimpleBill_shop', 'SimpleBill Shop', ['read' => true, 'SimpleBill_shop_cap' => true, 'SimpleBill_view_history' => true]);

    if(!get_option('SimpleBill_currency')) update_option('SimpleBill_currency', 'LKR');
    if(!get_option('SimpleBill_business_name')) update_option('SimpleBill_business_name', 'Simple Bill');
    if(!get_option('SimpleBill_timezone')) update_option('SimpleBill_timezone', 'Asia/Colombo');
    if(!get_option('SimpleBill_chat_enabled')) update_option('SimpleBill_chat_enabled', '1');
}

// Database patch helper for updating to the new payment structure
add_action('init', 'SimpleBill_update_db_for_payments');
function SimpleBill_update_db_for_payments() {
    // Use standard WordPress dbDelta for cross-database compatibility (MySQL/SQLite)
    if (get_option('SimpleBill_db_version') !== '1.20.0') {
        SimpleBill_create_db();
        update_option('SimpleBill_db_version', '1.20.0');
    }
}

// ==========================================
// ACTIVITY LOG HELPER
// ==========================================
function SimpleBill_log($action, $target_type, $target_detail = '') {
    global $wpdb;
    // Ensure table exists (for sites that haven't re-run activation)
    $table = $wpdb->prefix . 'SimpleBill_logs';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if (!$table_exists) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_login varchar(100) NOT NULL,
            action varchar(100) NOT NULL,
            target_type varchar(100) NOT NULL,
            target_detail text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    $user = wp_get_current_user();
    $wpdb->insert($table, [
        'user_id'       => $user->ID,
        'user_login'    => $user->user_login ?: 'system',
        'action'        => sanitize_text_field($action),
        'target_type'   => sanitize_text_field($target_type),
        'target_detail' => sanitize_textarea_field($target_detail),
        'created_at'    => current_time('mysql'),
    ]);
}

add_action('init', 'SimpleBill_ensure_roles_exist');
function SimpleBill_ensure_roles_exist() {
    if (!get_role('SimpleBill_admin')) {
        add_role('SimpleBill_admin', 'SimpleBill Admin', ['read' => true, 'SimpleBill_admin_cap' => true, 'SimpleBill_view_history' => true]);
    }
    if (!get_role('SimpleBill_shop')) {
        add_role('SimpleBill_shop', 'SimpleBill Shop', ['read' => true, 'SimpleBill_shop_cap' => true, 'SimpleBill_view_history' => true]);
    }
}

// ==========================================
// PLAN MANAGEMENT FUNCTIONS
// ==========================================
function SimpleBill_get_admin_active_plan($admin_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_admin_plans';
    $plan = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE admin_id = %d AND end_date > NOW() ORDER BY end_date DESC LIMIT 1",
        $admin_id
    ));
    return $plan;
}

function SimpleBill_create_admin_plan($admin_id, $plan_type, $auto_renew = 0, $start_date = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_admin_plans';
    
    $start_date = $start_date ? date('Y-m-d H:i:s', strtotime($start_date)) : current_time('mysql');
    $days = ($plan_type === 'yearly') ? 365 : 30;
    $end_date = date('Y-m-d H:i:s', strtotime("+$days days", strtotime($start_date)));
    
    $wpdb->insert($table, [
        'admin_id'   => $admin_id,
        'plan_type'  => $plan_type,
        'start_date' => $start_date,
        'end_date'   => $end_date,
        'auto_renew' => $auto_renew,
    ], ['%d', '%s', '%s', '%s', '%d']);
    
    return $wpdb->insert_id;
}

function SimpleBill_renew_admin_plan($admin_id, $plan_type = null) {
    $current_plan = SimpleBill_get_admin_active_plan($admin_id);
    if (!$plan_type) {
        $plan_type = $current_plan ? $current_plan->plan_type : 'monthly';
    }
    
    $start_date = $current_plan ? $current_plan->end_date : null;
    return SimpleBill_create_admin_plan($admin_id, $plan_type, 1, $start_date);
}

function SimpleBill_change_admin_plan($admin_id, $new_plan_type) {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_admin_plans';
    
    $current_plan = SimpleBill_get_admin_active_plan($admin_id);
    if ($current_plan) {
        $wpdb->update(
            $table,
            ['end_date' => current_time('mysql')],
            ['id' => $current_plan->id],
            ['%s'],
            ['%d']
        );
    }
    
    return SimpleBill_create_admin_plan($admin_id, $new_plan_type, 1, current_time('mysql'));
}

function SimpleBill_get_invoice_number($id, $user_id, $admin_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_invoices';
    
    // Count how many invoices THIS specific user has created up to the current invoice
    $user_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND id <= %d", $user_id, $id));

    $user = get_userdata($user_id);
    $user_prefix = $user ? strtoupper(substr($user->user_login, 0, 3)) : 'USR';
    
    $admin_prefix = 'ADM';
    if ($admin_id > 0) {
        $admin = get_userdata($admin_id);
        if ($admin) {
            $admin_prefix = strtoupper(substr($admin->user_login, 0, 3));
        }
    }
    
    return $admin_prefix . '-' . $user_prefix . '-' . $user_count;
}

function SimpleBill_get_currency($admin_id = 0) {
    if (!$admin_id) {
        $user_id = get_current_user_id();
        if ($user_id) {
             if (current_user_can('SimpleBill_admin_cap')) {
                 $admin_id = $user_id;
             } elseif (current_user_can('SimpleBill_shop_cap')) {
                 $admin_id = get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0;
             }
        }
    }
    if ($admin_id > 0) {
        $meta_curr = get_user_meta($admin_id, 'SimpleBill_currency', true);
        if ($meta_curr) return $meta_curr;
    }
    return get_option('SimpleBill_currency', 'LKR');
}

// ==========================================
// 2. WP DASHBOARD SETTINGS (SUPER ADMIN)
// ==========================================
add_action('admin_menu', 'SimpleBill_register_settings_page');
function SimpleBill_register_settings_page() {
    add_menu_page('Simple Bill Settings', 'Simple Bill', 'administrator', 'SimpleBill-pos-settings', 'SimpleBill_render_wp_settings', 'dashicons-store', 56);
}

add_action('admin_enqueue_scripts', 'SimpleBill_admin_settings_scripts');
function SimpleBill_admin_settings_scripts($hook) {
    if (strpos($hook, 'SimpleBill-pos-settings') !== false) {
        wp_enqueue_media();
    }
}

function SimpleBill_render_wp_settings() {
    if (isset($_POST['SimpleBill_save_wp_settings']) && current_user_can('administrator')) {
        update_option('SimpleBill_business_name', sanitize_text_field($_POST['business_name']));
        update_option('SimpleBill_currency', sanitize_text_field($_POST['currency']));
        update_option('SimpleBill_timezone', sanitize_text_field($_POST['timezone']));
        update_option('SimpleBill_logo_url', sanitize_url($_POST['logo_url'] ?? ''));
        update_option('SimpleBill_chat_enabled', isset($_POST['chat_enabled']) ? '1' : '0');
        update_option('SimpleBill_auto_clear_logs_enabled', isset($_POST['auto_clear_logs_enabled']) ? '1' : '0');
        update_option('SimpleBill_auto_clear_logs_days', intval($_POST['auto_clear_logs_days'] ?? 30));
        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
    }

    if (isset($_POST['SimpleBill_send_reset_link']) && current_user_can('administrator')) {
        $reset_email = sanitize_email($_POST['reset_email']);
        $user = get_user_by('email', $reset_email);
        if ($user) {
            $reset_key = get_password_reset_key($user);
            if (!is_wp_error($reset_key)) {
                $reset_link = network_site_url("wp-login.php?action=rp&key=" . $reset_key . "&login=" . rawurlencode($user->user_login), 'login');
                $message = "Someone has requested a password reset for the following account:\r\n\r\n";
                $message .= network_home_url('/') . "\r\n\r\n";
                $message .= sprintf('Username: %s', $user->user_login) . "\r\n\r\n";
                $message .= "If this was a mistake, just ignore this email and nothing will happen.\r\n\r\n";
                $message .= "To reset your password, visit the following address:\r\n\r\n";
                $message .= $reset_link . "\r\n";
                
                wp_mail($user->user_email, 'Password Reset', $message);
                echo '<div class="updated"><p>Password reset link sent to ' . esc_html($reset_email) . '.</p></div>';
            } else {
                echo '<div class="error"><p>Error generating reset key.</p></div>';
            }
        } else {
            echo '<div class="error"><p>User with that email not found.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Simple Bill - Global Settings</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr><th scope="row">Global Business Name</th><td><input type="text" name="business_name" value="<?php echo esc_attr(get_option('SimpleBill_business_name', 'Simple Bill')); ?>" class="regular-text" required></td></tr>
                <tr>
                    <th scope="row">Global Logo</th>
                    <td>
                        <input type="hidden" name="logo_url" id="global_logo_url" value="<?php echo esc_attr(get_option('SimpleBill_logo_url', '')); ?>">
                        <div id="global_logo_preview" style="margin-bottom: 10px;">
                            <?php if(get_option('SimpleBill_logo_url')): ?>
                                <img src="<?php echo esc_url(get_option('SimpleBill_logo_url')); ?>" style="max-height: 80px; max-width: 100%; display: block;">
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="upload_global_logo">Upload / Select Logo</button>
                        <button type="button" class="button" id="remove_global_logo" style="<?php echo get_option('SimpleBill_logo_url') ? '' : 'display:none;'; ?>">Remove Logo</button>
                        <script>
                        jQuery(document).ready(function($){
                            var mediaUploader;
                            $('#upload_global_logo').click(function(e) {
                                e.preventDefault();
                                if (mediaUploader) { mediaUploader.open(); return; }
                                mediaUploader = wp.media.frames.file_frame = wp.media({ title: 'Choose Logo', button: { text: 'Choose Logo' }, multiple: false });
                                mediaUploader.on('select', function() {
                                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                                    $('#global_logo_url').val(attachment.url);
                                    $('#global_logo_preview').html('<img src="'+attachment.url+'" style="max-height: 80px; max-width: 100%; display: block;">');
                                    $('#remove_global_logo').show();
                                });
                                mediaUploader.open();
                            });
                            $('#remove_global_logo').click(function(e) { e.preventDefault(); $('#global_logo_url').val(''); $('#global_logo_preview').html(''); $(this).hide(); });
                        });
                        </script>
                    </td>
                </tr>
                <tr><th scope="row">Currency</th><td><input type="text" name="currency" value="<?php echo esc_attr(get_option('SimpleBill_currency', 'LKR')); ?>" class="regular-text" required></td></tr>
                <tr><th scope="row">Timezone</th><td><select name="timezone" class="regular-text"><?php echo wp_timezone_choice(get_option('SimpleBill_timezone', 'Asia/Colombo')); ?></select></td></tr>
                <tr><th scope="row">Enable Live Chat</th><td><label><input type="checkbox" name="chat_enabled" value="1" <?php checked(get_option('SimpleBill_chat_enabled', '1'), '1'); ?>> Enable live chat system for all users</label></td></tr>
                <tr><th colspan="2"><hr style="margin: 20px 0;"></th></tr>
                <tr><th scope="row">Auto-Clear Activity Logs</th><td><label><input type="checkbox" name="auto_clear_logs_enabled" value="1" <?php checked(get_option('SimpleBill_auto_clear_logs_enabled', '0'), '1'); ?>> Automatically clear logs older than X days</label></td></tr>
                <tr><th scope="row">Days to Keep</th><td><input type="number" name="auto_clear_logs_days" value="<?php echo esc_attr(get_option('SimpleBill_auto_clear_logs_days', 30)); ?>" min="1" max="365" class="regular-text" placeholder="30"> <p style="color: #666; font-size: 12px; margin-top: 5px;">Logs older than this many days will be automatically removed. (Minimum 1 day, Maximum 365 days)</p></td></tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'SimpleBill_save_wp_settings'); ?>
        </form>

        <hr>
        <h2>Send Password Reset Link</h2>
        <form method="post" action="">
            <table class="form-table">
                <tr><th scope="row">User Email</th><td><input type="email" name="reset_email" placeholder="user@example.com" class="regular-text" required></td></tr>
            </table>
            <?php submit_button('Send Reset Link', 'secondary', 'SimpleBill_send_reset_link'); ?>
        </form>
    </div>
    <?php
}

// ==========================================
// 3. ACCESS CONTROL, PRESENCE & ROUTING
// ==========================================

// Track Active Users
add_action('init', 'SimpleBill_update_user_activity');
function SimpleBill_update_user_activity() {
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'SimpleBill_last_active', current_time('timestamp'));
    }
}

function SimpleBill_get_active_users_count($roles) {
    $valid_roles = array();
    foreach ((array)$roles as $role) {
        if (get_role($role)) {
            $valid_roles[] = $role;
        }
    }
    if (empty($valid_roles)) return 0;

    $threshold = current_time('timestamp') - (15 * 60); // 15 minutes window
    $args = [
        'role__in' => $valid_roles,
        'meta_query' => [['key' => 'SimpleBill_last_active', 'value' => $threshold, 'compare' => '>', 'type' => 'NUMERIC']],
        'fields' => 'ID'
    ];
    $users = get_users($args);
    return count($users);
}

// Check admin toggle block logic for login 
add_filter('authenticate', 'SimpleBill_check_admin_disabled', 30, 3);
function SimpleBill_check_admin_disabled($user, $username, $password) {
    if (is_a($user, 'WP_User')) {
        $is_disabled = false;
        if (in_array('SimpleBill_admin', (array)$user->roles)) {
            $is_disabled = get_user_meta($user->ID, 'SimpleBill_admin_disabled', true);
        } elseif (in_array('SimpleBill_shop', (array)$user->roles)) {
            // Check if the shop user itself is disabled
            $is_disabled = get_user_meta($user->ID, 'SimpleBill_admin_disabled', true);
            // Check if the parent admin is disabled
            if (!$is_disabled) {
                $parent_id = get_user_meta($user->ID, '_SimpleBill_parent_admin', true);
                if ($parent_id) {
                    $is_disabled = get_user_meta($parent_id, 'SimpleBill_admin_disabled', true);
                }
            }
        }
        
        if ($is_disabled) {
            return new WP_Error('account_disabled', 'This account has been disabled.');
        }
    }
    return $user;
}

// Fix Login Redirects
add_action('wp_login_failed', 'SimpleBill_login_failed', 10, 2);
function SimpleBill_login_failed($username, $error = null) {
    $reason = 'failed';
    if (is_wp_error($error) && $error->get_error_code() === 'account_disabled') {
        $reason = 'disabled';
    }
    wp_redirect(home_url('?SimpleBill_login=' . $reason));
    exit;
}

add_filter('login_redirect', 'SimpleBill_login_redirect', 10, 3);
function SimpleBill_login_redirect($redirect_to, $request, $user) {
    return home_url();
}

add_action('template_redirect', 'SimpleBill_access_control');
function SimpleBill_access_control() {
    if (isset($_GET['SimpleBill_logout'])) {
        wp_logout();
        wp_redirect(home_url('?SimpleBill_login=1'));
        exit;
    }
    
    $is_login_page = isset($_GET['SimpleBill_login']) || isset($_GET['SimpleBill_forgot_password']) || $GLOBALS['pagenow'] === 'wp-login.php';
    
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $is_disabled = get_user_meta($user->ID, 'SimpleBill_admin_disabled', true);
        if (!$is_disabled && in_array('SimpleBill_shop', (array)$user->roles)) {
            $parent_id = get_user_meta($user->ID, '_SimpleBill_parent_admin', true);
            if ($parent_id) {
                $is_disabled = get_user_meta($parent_id, 'SimpleBill_admin_disabled', true);
            }
        }
        
        // Force logout if disabled while actively logged in
        if ($is_disabled && !current_user_can('administrator')) {
            wp_logout();
            wp_redirect(home_url('?SimpleBill_login=disabled'));
            exit;
        }
    } elseif (!$is_login_page) {
        wp_redirect(home_url('?SimpleBill_login=1'));
        exit;
    }
}

// Schedule automatic logs cleanup
add_action('init', 'SimpleBill_schedule_logs_cleanup');
function SimpleBill_schedule_logs_cleanup() {
    if (!wp_next_scheduled('SimpleBill_auto_clear_logs')) {
        wp_schedule_event(current_time('timestamp') + 3600, 'daily', 'SimpleBill_auto_clear_logs');
    }
}

// Perform automatic logs cleanup
add_action('SimpleBill_auto_clear_logs', 'SimpleBill_execute_auto_clear_logs');
function SimpleBill_execute_auto_clear_logs() {
    if (!get_option('SimpleBill_auto_clear_logs_enabled', '0')) return;
    
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_logs';
    $days = intval(get_option('SimpleBill_auto_clear_logs_days', 30));
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
}

// Register cleanup on deactivation
register_deactivation_hook(__FILE__, 'SimpleBill_unschedule_logs_cleanup');
function SimpleBill_unschedule_logs_cleanup() {
    wp_clear_scheduled_hook('SimpleBill_auto_clear_logs');
}

add_action('init', 'SimpleBill_handle_chat_requests');
function SimpleBill_handle_chat_requests() {
    if (!is_user_logged_in()) return;
    if (!get_option('SimpleBill_chat_enabled', '1')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_chat';

    // Get chat messages with role info
    if (isset($_GET['SimpleBill_chat_action']) && $_GET['SimpleBill_chat_action'] === 'get_messages') {
        header('Content-Type: application/json');
        
        // Get messages (limit to last 50)
        $messages = $wpdb->get_results(
            "SELECT sender_id, sender_name, message, is_announcement, created_at FROM $table 
            ORDER BY created_at DESC LIMIT 50"
        );

        // Add role info to each message
        $messages_with_roles = [];
        foreach ($messages ?? [] as $msg) {
            $user = get_user_by('id', $msg->sender_id);
            $role = 'User';
            if ($user) {
                if (in_array('administrator', $user->roles)) {
                    $role = 'Super Admin';
                } elseif (in_array('SimpleBill_admin', $user->roles)) {
                    $role = 'Admin';
                } elseif (in_array('SimpleBill_shop', $user->roles)) {
                    $role = 'Staff';
                }
            }
            $msg->sender_role = $role;
            $messages_with_roles[] = $msg;
        }

        // Reverse to show oldest first
        echo json_encode(array_reverse($messages_with_roles));
        exit;
    }

    // Send chat message
    if (isset($_POST['SimpleBill_chat_action']) && $_POST['SimpleBill_chat_action'] === 'send_message') {
        header('Content-Type: application/json');
        $user = wp_get_current_user();
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $is_announcement = current_user_can('administrator') && isset($_POST['is_announcement']) && $_POST['is_announcement'] === '1' ? 1 : 0;

        if (!empty($message)) {
            $wpdb->insert($table, [
                'sender_id'      => $user->ID,
                'sender_name'    => $user->user_login,
                'recipient_role' => 'all',
                'message'        => $message,
                'is_announcement' => $is_announcement,
                'created_at'     => current_time('mysql')
            ]);

            SimpleBill_log('CHAT', 'Message', ($is_announcement ? 'ANNOUNCEMENT: ' : '') . $message);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    // Clear all chat messages (super admin only)
    if (isset($_POST['SimpleBill_chat_action']) && $_POST['SimpleBill_chat_action'] === 'clear_all') {
        header('Content-Type: application/json');
        if (!current_user_can('administrator')) {
            echo json_encode(['success' => false]);
            exit;
        }

        $wpdb->query("TRUNCATE TABLE $table");
        SimpleBill_log('CHAT', 'Action', 'All chat messages cleared');
        echo json_encode(['success' => true]);
        exit;
    }
}

add_filter('template_include', 'SimpleBill_render_page');
function SimpleBill_render_page($template) {
    if ((isset($_GET['SimpleBill_login']) || isset($_GET['SimpleBill_forgot_password'])) && !is_user_logged_in()) {
        SimpleBill_render_login_page();
        exit;
    }
    if (is_front_page() && is_user_logged_in()) {
        SimpleBill_handle_history_actions();
        SimpleBill_render_dashboard_layout();
        exit;
    }
    return $template;
}

function SimpleBill_handle_history_actions() {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_invoices';
    $user_id = get_current_user_id();

    if (isset($_GET['del_inv']) && (current_user_can('administrator') || current_user_can('SimpleBill_admin_cap'))) {
        $inv_id = intval($_GET['del_inv']);
        if (current_user_can('administrator')) {
            $wpdb->update($table, ['is_deleted' => 1], ['id' => $inv_id]);
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $table SET is_deleted = 1 WHERE id = %d AND admin_id = %d", $inv_id, $user_id));
        }
        SimpleBill_log('DELETE', 'Invoice', 'Invoice ID: ' . $inv_id);
        wp_redirect(remove_query_arg(['del_inv']));
        exit;
    }

    if (isset($_GET['clear_all_sales']) && (current_user_can('administrator') || current_user_can('SimpleBill_admin_cap'))) {
        if (current_user_can('administrator')) {
            $admin_to_clear = isset($_GET['filter_admin']) ? intval($_GET['filter_admin']) : 0;
            if ($admin_to_clear) {
                $wpdb->update($table, ['is_deleted' => 1], ['admin_id' => $admin_to_clear, 'is_deleted' => 0]);
                SimpleBill_log('BULK DELETE', 'Invoices', 'All invoices for admin ID: ' . $admin_to_clear);
            }
        } else {
            $wpdb->update($table, ['is_deleted' => 1], ['admin_id' => $user_id, 'is_deleted' => 0]);
            SimpleBill_log('BULK DELETE', 'Invoices', 'All invoices for own admin ID: ' . $user_id);
        }
        wp_redirect(remove_query_arg('clear_all_sales'));
        exit;
    }

    if (current_user_can('administrator')) {
        if (isset($_GET['restore_inv'])) {
            $wpdb->update($table, ['is_deleted' => 0], ['id' => intval($_GET['restore_inv'])]);
            SimpleBill_log('RESTORE', 'Invoice', 'Invoice ID: ' . intval($_GET['restore_inv']));
            wp_redirect(remove_query_arg('restore_inv')); exit;
        }
        if (isset($_GET['perm_del_inv'])) {
            $wpdb->delete($table, ['id' => intval($_GET['perm_del_inv'])]);
            SimpleBill_log('PERMANENT DELETE', 'Invoice', 'Invoice ID: ' . intval($_GET['perm_del_inv']));
            wp_redirect(remove_query_arg('perm_del_inv')); exit;
        }
        if (isset($_GET['purge_archive'])) {
            $wpdb->delete($table, ['is_deleted' => 1]);
            SimpleBill_log('PURGE', 'Archive', 'All archived invoices permanently deleted');
            wp_redirect(remove_query_arg('purge_archive')); exit;
        }
    }

    // Admin-level restore: admins can restore deleted invoices/customers/products belonging to their users
    if (current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')) {
        $admin_user_id = get_current_user_id();

        if (isset($_GET['admin_restore_inv'])) {
            $inv_id = intval($_GET['admin_restore_inv']);
            $wpdb->query($wpdb->prepare("UPDATE $table SET is_deleted = 0 WHERE id = %d AND admin_id = %d", $inv_id, $admin_user_id));
            SimpleBill_log('RESTORE', 'Invoice', 'Invoice ID: ' . $inv_id);
            wp_redirect(remove_query_arg('admin_restore_inv')); exit;
        }

        if (isset($_GET['admin_restore_customer'])) {
            $cust_id = intval($_GET['admin_restore_customer']);
            $table_cust = $wpdb->prefix . 'SimpleBill_customers';
            $wpdb->query($wpdb->prepare("UPDATE $table_cust SET is_deleted = 0 WHERE id = %d AND admin_id = %d", $cust_id, $admin_user_id));
            SimpleBill_log('RESTORE', 'Customer', 'Customer ID: ' . $cust_id);
            wp_redirect(remove_query_arg('admin_restore_customer')); exit;
        }

        if (isset($_GET['admin_restore_product'])) {
            $prod_id = intval($_GET['admin_restore_product']);
            $table_prod = $wpdb->prefix . 'SimpleBill_products';
            $wpdb->query($wpdb->prepare("UPDATE $table_prod SET is_deleted = 0 WHERE id = %d AND admin_id = %d", $prod_id, $admin_user_id));
            SimpleBill_log('RESTORE', 'Product', 'Product ID: ' . $prod_id);
            wp_redirect(remove_query_arg('admin_restore_product')); exit;
        }

        // Admin permanent delete single record
        if (isset($_GET['admin_perm_del_inv'])) {
            $inv_id = intval($_GET['admin_perm_del_inv']);
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id = %d AND admin_id = %d AND is_deleted = 1", $inv_id, $admin_user_id));
            SimpleBill_log('PERMANENT DELETE', 'Invoice', 'Invoice ID: ' . $inv_id);
            wp_redirect(remove_query_arg('admin_perm_del_inv')); exit;
        }

        if (isset($_GET['admin_perm_del_customer'])) {
            $cust_id = intval($_GET['admin_perm_del_customer']);
            $table_cust = $wpdb->prefix . 'SimpleBill_customers';
            $wpdb->query($wpdb->prepare("DELETE FROM $table_cust WHERE id = %d AND admin_id = %d AND is_deleted = 1", $cust_id, $admin_user_id));
            SimpleBill_log('PERMANENT DELETE', 'Customer', 'Customer ID: ' . $cust_id);
            wp_redirect(remove_query_arg('admin_perm_del_customer')); exit;
        }

        if (isset($_GET['admin_perm_del_product'])) {
            $prod_id = intval($_GET['admin_perm_del_product']);
            $table_prod = $wpdb->prefix . 'SimpleBill_products';
            $wpdb->query($wpdb->prepare("DELETE FROM $table_prod WHERE id = %d AND admin_id = %d AND is_deleted = 1", $prod_id, $admin_user_id));
            SimpleBill_log('PERMANENT DELETE', 'Product', 'Product ID: ' . $prod_id);
            wp_redirect(remove_query_arg('admin_perm_del_product')); exit;
        }

        // Admin clear all deleted records (bulk permanent delete)
        if (isset($_GET['admin_clear_inv'])) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE admin_id = %d AND is_deleted = 1", $admin_user_id));
            SimpleBill_log('PURGE', 'Invoices', 'All deleted invoices permanently cleared');
            wp_redirect(remove_query_arg('admin_clear_inv')); exit;
        }

        if (isset($_GET['admin_clear_customers'])) {
            $table_cust = $wpdb->prefix . 'SimpleBill_customers';
            $wpdb->query($wpdb->prepare("DELETE FROM $table_cust WHERE admin_id = %d AND is_deleted = 1", $admin_user_id));
            SimpleBill_log('PURGE', 'Customers', 'All deleted customers permanently cleared');
            wp_redirect(remove_query_arg('admin_clear_customers')); exit;
        }

        if (isset($_GET['admin_clear_products'])) {
            $table_prod = $wpdb->prefix . 'SimpleBill_products';
            $wpdb->query($wpdb->prepare("DELETE FROM $table_prod WHERE admin_id = %d AND is_deleted = 1", $admin_user_id));
            SimpleBill_log('PURGE', 'Products', 'All deleted products permanently cleared');
            wp_redirect(remove_query_arg('admin_clear_products')); exit;
        }
    }
}

// ==========================================
// 4. LAYOUT & NAVIGATION
// ==========================================
function SimpleBill_render_dashboard_layout() {
    $user = wp_get_current_user();
    $business_name = get_option('SimpleBill_business_name', 'SimpleBill');
    $logo_url = get_option('SimpleBill_logo_url');
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

    if (current_user_can('SimpleBill_admin_cap') || current_user_can('SimpleBill_shop_cap')) {
        $admin_id = current_user_can('SimpleBill_admin_cap') ? $user->ID : get_user_meta($user->ID, '_SimpleBill_parent_admin', true);
        if ($admin_id) {
            $custom_logo = get_user_meta($admin_id, 'SimpleBill_logo_url', true);
            if ($custom_logo) $logo_url = $custom_logo;
        }
    }
    
    $active_shops_count = SimpleBill_get_active_users_count(['SimpleBill_shop']);
    $active_admins_count = SimpleBill_get_active_users_count(['SimpleBill_admin', 'administrator']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo esc_html($business_name); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
        <script>
            tailwind.config = { darkMode: 'class' };
            if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>
        <style>
            /* ==== LIGHT MODE VISIBILITY FIXES ==== */
            html:not(.dark) input:not(.border-none):not([type="submit"]):not([type="hidden"]),
            html:not(.dark) select:not(.border-none),
            html:not(.dark) textarea:not(.border-none),
            html:not(.dark) .ts-control:not(.border-none) {
                border: 1px solid #cbd5e1 !important;
                background-color: #ffffff;
            }
            
            html:not(.dark) .item-row .ts-control,
            html:not(.dark) input.border-none {
                border: none !important;
                background-color: #f9fafb !important;
            }

            html:not(.dark) input:focus:not(.border-none):not([type="submit"]):not([type="hidden"]),
            html:not(.dark) select:focus:not(.border-none),
            html:not(.dark) textarea:focus:not(.border-none),
            html:not(.dark) .ts-control.focus:not(.border-none) {
                border-color: #3b82f6 !important;
                box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important;
            }

            /* Fix TomSelect inputs globally for light mode */
            .ts-control { border-color: #cbd5e1; }
            .ts-control, .ts-control input, .ts-control .ts-input {
                border-radius: 0.75rem !important;
            }
            .ts-control input, .ts-control .ts-input {
                outline: none !important;
            }
            .ts-control:focus-within, .ts-control.focus,
            .ts-wrapper.single.focus .ts-control,
            .ts-wrapper.single.input-active .ts-control,
            .ts-wrapper.single.dropdown-active .ts-control,
            .ts-wrapper.single.focus .ts-control input,
            .ts-wrapper.single.input-active .ts-control input,
            .ts-wrapper.single.dropdown-active .ts-control input {
                outline: none !important;
                box-shadow: none !important;
                border-color: transparent !important;
            }
            .ts-control input:focus, .ts-control .ts-input:focus {
                outline: none !important;
                box-shadow: none !important;
                border-color: transparent !important;
            }
            
            /* ==== LOADING BAR ==== */
            #loading-bar {
                position: fixed;
                top: 0;
                left: 0;
                height: 3px;
                background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899);
                width: 0%;
                z-index: 9999;
                transition: width 0.3s ease;
            }

            @media print { .hidden-print { display: none !important; } .print-only { display: block !important; } }
            .nav-link.active { background: rgba(59, 130, 246, 0.1); border-right: 4px solid #3b82f6; color: #3b82f6; }

            /* ==== MODERN FAST ANIMATIONS ==== */
            /* Sidebar entry animation for desktop */
            @media (min-width: 768px) {
                #sidebar { animation: sidebarSlideIn 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; }
                @keyframes sidebarSlideIn {
                    from { transform: translateX(-20px); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            }

            /* Staggered Nav Links & Hover */
            nav.flex-1 .nav-link {
                opacity: 0;
                animation: navLinkFade 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
                position: relative;
                overflow: hidden;
            }
            @keyframes navLinkFade {
                from { opacity: 0; transform: translateX(-10px); }
                to { opacity: 1; transform: translateX(0); }
            }
            nav.flex-1 .nav-link:nth-child(1) { animation-delay: 0.05s; }
            nav.flex-1 .nav-link:nth-child(2) { animation-delay: 0.1s; }
            nav.flex-1 .nav-link:nth-child(3) { animation-delay: 0.15s; }
            nav.flex-1 .nav-link:nth-child(4) { animation-delay: 0.2s; }
            nav.flex-1 .nav-link:nth-child(5) { animation-delay: 0.25s; }
            nav.flex-1 .nav-link:nth-child(6) { animation-delay: 0.3s; }
            nav.flex-1 .nav-link:nth-child(7) { animation-delay: 0.35s; }
            nav.flex-1 .nav-link:nth-child(8) { animation-delay: 0.4s; }

            nav.flex-1 .nav-link i { transition: transform 0.2s ease; }
            nav.flex-1 .nav-link:hover i { transform: scale(1.15) translateY(-1px); }
            
            
            /* ==== TOMSELECT DARK MODE FIXES ==== */
            .ts-control { border-radius: 0.75rem; padding: 0.9rem 1rem; border-color: #e5e7eb; font-size: 1.15rem; background-color: #f9fafb; }
            .ts-dropdown { font-size: 1.15rem; }
            .dark .ts-control { 
                background-color: #1f2937 !important; 
                border-color: #374151 !important; 
                color: #f3f4f6 !important; 
            }
            .dark .ts-wrapper.single .ts-control, .dark .ts-wrapper.single .ts-control input {
                background-color: #1f2937 !important; 
                color: #f3f4f6 !important; 
            }
            .dark .ts-dropdown { 
                background-color: #1f2937 !important; 
                border-color: #374151 !important; 
            }
            .dark .ts-dropdown .option { 
                color: #f3f4f6 !important; 
                background-color: #111827 !important; 
            }
            .dark .ts-dropdown .option.selected,
            .dark .ts-dropdown .option:hover { 
                background-color: #374151 !important; 
                color: #f3f4f6 !important; 
            }
            .dark .ts-dropdown .option:hover {
                background-color: #4b5563 !important;
            }
            .ts-control input::placeholder { color: #9ca3af; }
            .dark .ts-control input { 
                color: #f3f4f6 !important; 
            }
            .dark .ts-control input::placeholder { 
                color: #6b7280 !important; 
            }
            
            /* TomSelect Custom Styles for POS */
            .item-row .ts-control { padding: 0.5rem; border: none; box-shadow: none; border-radius: 0.5rem; background: transparent; }
            .dark .item-row .ts-control { background: transparent; border: none; }
            .item-row .ts-dropdown { text-align: left; }

            /* ==== DESCRIPTION FIELD FIX ==== */
            .item-desc-cell {
                background-color: transparent !important;
                color: inherit !important;
            }
            .dark .item-desc-cell {
                background-color: transparent !important;
                color: #f3f4f6 !important;
            }
            .light .item-desc-cell {
                background-color: transparent !important;
                color: #111827 !important;
            }

            /* =============================================
               MOBILE RESPONSIVE: POS Item Table → Cards
               ============================================= */
            @media (max-width: 767px) {
                /* Hide the POS table header on mobile */
                #pos-form table thead { display: none; }

                /* Each row becomes a card */
                #pos-form table tbody { display: block; }
                #pos-form table .item-row {
                    display: block;
                    background: white;
                    border: 1px solid #e5e7eb;
                    border-radius: 0.75rem;
                    padding: 0.75rem;
                    margin-bottom: 0.75rem;
                    position: relative;
                }
                .dark #pos-form table .item-row {
                    background: #111827;
                    border-color: #374151;
                }
                /* Each cell becomes full-width with label */
                #pos-form table .item-row td {
                    display: flex;
                    align-items: center;
                    padding: 0.3rem 0;
                    font-size: 0.85rem;
                    gap: 0.5rem;
                }
                #pos-form table .item-row td::before {
                    content: attr(data-label);
                    font-size: 0.65rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    color: #9ca3af;
                    min-width: 52px;
                    letter-spacing: 0.05em;
                    flex-shrink: 0;
                }
                #pos-form table .item-row td:last-child {
                    position: absolute;
                    top: 0.5rem;
                    right: 0.5rem;
                    padding: 0;
                }
                #pos-form table .item-row td:last-child::before { display: none; }

                /* Full-width inputs inside cards */
                #pos-form table .item-row td input,
                #pos-form table .item-row td .ts-wrapper {
                    flex: 1;
                    min-width: 0;
                }
                /* Item total styling */
                #pos-form table .item-row .item-total {
                    font-size: 1rem;
                    font-weight: 700;
                    color: #3b82f6;
                }

                /* Override table min-width constraints */
                #pos-form table { min-width: 0 !important; width: 100%; }

                /* Action buttons in history/archive tables — icon-only on mobile */
                .mobile-action-text { display: none; }

                /* History table: make rows wrap */
                .history-table-wrap table { min-width: 0 !important; }
                .history-table-wrap thead { display: none; }
                .history-table-wrap tbody tr {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    padding: 0.75rem 1rem;
                    border-bottom: 1px solid #e5e7eb;
                    gap: 0.25rem 0.75rem;
                }
                .dark .history-table-wrap tbody tr { border-color: #374151; }
                .history-table-wrap tbody tr td {
                    display: inline-flex;
                    align-items: center;
                    padding: 0;
                    font-size: 0.8rem;
                    border: none !important;
                }
                .history-table-wrap tbody tr td.hist-date { color: #9ca3af; font-size: 0.7rem; width: 100%; }
                .history-table-wrap tbody tr td.hist-bill { font-family: monospace; font-size: 0.7rem; color: #6b7280; }
                .history-table-wrap tbody tr td.hist-customer { font-weight: 700; flex: 1; }
                .history-table-wrap tbody tr td.hist-amount { font-weight: 900; color: #3b82f6; }
                .history-table-wrap tbody tr td.hist-actions { margin-left: auto; }

                /* Archive table same treatment */
                .archive-table-wrap table { min-width: 0 !important; }
                .archive-table-wrap thead { display: none; }
                .archive-table-wrap tbody tr {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    padding: 0.75rem 1rem;
                    border-bottom: 1px solid #fee2e2;
                    gap: 0.25rem 0.75rem;
                }
                .archive-table-wrap tbody tr td {
                    display: inline-flex;
                    align-items: center;
                    padding: 0;
                    font-size: 0.78rem;
                    border: none !important;
                }
                .archive-table-wrap tbody tr td.arch-date { color: #f87171; font-size: 0.68rem; width: 100%; font-family: monospace; }
                .archive-table-wrap tbody tr td.arch-inv { font-family: monospace; font-size: 0.68rem; color: #f87171; }
                .archive-table-wrap tbody tr td.arch-customer { font-weight: 700; flex: 1; }
                .archive-table-wrap tbody tr td.arch-amount { font-weight: 700; color: #ef4444; }
                .archive-table-wrap tbody tr td.arch-actions { margin-left: auto; }

                /* Users/Admins table on mobile */
                .users-table-wrap table { min-width: 0 !important; }
                .users-table-wrap thead tr { display: none; }
                .users-table-wrap tbody tr, .users-table-wrap tr:not(:first-child) {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    padding: 0.75rem 1rem;
                    gap: 0.5rem;
                    border-bottom: 1px solid #e5e7eb;
                }
                .dark .users-table-wrap tbody tr { border-color: #374151; }
                .users-table-wrap td {
                    display: inline-flex !important;
                    align-items: center;
                    padding: 0 !important;
                    font-size: 0.8rem;
                    border: none !important;
                }
                .users-table-wrap td.ut-user { width: 100%; }
                .users-table-wrap td.ut-email { color: #9ca3af; font-size: 0.72rem; flex: 1; }
                .users-table-wrap td.ut-actions { margin-left: auto; }

                /* Invoice toolbar buttons */
                .toolbar { flex-wrap: wrap; gap: 8px !important; padding: 10px !important; justify-content: center !important; }
                .toolbar select, .toolbar button { font-size: 12px !important; padding: 5px 10px !important; }
                .print-area { margin-top: 120px !important; }

                /* General tap target minimum */
                button, a[href], select, input[type="submit"] { min-height: 36px; }

                /* Header date text — hide on very small screens */
                .header-datetime { display: none !important; }
            }

            @media (max-width: 400px) {
                /* Extra small: single-column layout everywhere */
                .print-area { margin-top: 140px !important; }
            }
        </style>
    </head>
    <body class="bg-gray-50 dark:bg-gray-950 min-h-screen flex text-gray-900 dark:text-gray-100 transition-colors duration-200">
        <!-- LOADING BAR -->
        <div id="loading-bar"></div>
        <!-- Mobile Sidebar Backdrop -->
        <div id="mobile-backdrop" class="fixed inset-0 bg-black/60 z-40 hidden md:hidden transition-opacity" onclick="toggleMobileMenu()"></div>

        <!-- Sidebar Navigation -->
        <aside id="sidebar" class="w-64 bg-white dark:bg-gray-900 border-r dark:border-gray-800 flex-shrink-0 flex flex-col fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition-transform duration-300 z-50 h-screen hidden-print">
            <button onclick="toggleMobileMenu()" class="md:hidden absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none">
                <i class="fa-solid fa-xmark text-2xl"></i>
            </button>
            <div class="p-6 flex flex-col items-center border-b dark:border-gray-800 mt-4 md:mt-0">
                <?php if (current_user_can('administrator')): ?>
                    <?php if($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" class="h-16 w-auto mb-4 object-contain">
                    <?php endif; ?>
                    <div class="text-center">
                        <span class="font-bold text-lg block"><?php echo esc_html($business_name); ?></span>
                    </div>
                <?php else: ?>
                    <div class="text-center w-full mt-2">
                        <div class="w-16 h-16 mx-auto rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold text-2xl mb-3 shadow-sm border border-blue-200 dark:border-blue-800">
                            <?php echo strtoupper(substr($user->user_login, 0, 1)); ?>
                        </div>
                        <span class="font-bold text-lg block truncate text-gray-800 dark:text-gray-100"><?php echo esc_html($user->user_login); ?></span>
                        
                        <?php if (current_user_can('SimpleBill_admin_cap')): ?>
                            <span class="text-[10px] uppercase bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300 px-2 py-0.5 rounded-full font-bold mt-1 inline-block border border-blue-200 dark:border-blue-800">Admin</span>
                        <?php elseif (current_user_can('SimpleBill_shop_cap')): ?>
                            <span class="text-[10px] uppercase bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300 px-2 py-0.5 rounded-full font-bold mt-1 inline-block mb-3 border border-green-200 dark:border-green-800">User</span>
                            <?php 
                                $parent_admin_id = get_user_meta($user->ID, '_SimpleBill_parent_admin', true);
                                if ($parent_admin_id) {
                                    $parent_admin = get_userdata($parent_admin_id);
                                    if ($parent_admin) {
                                        echo '<div class="text-xs text-gray-500 mt-2 border-t dark:border-gray-800 pt-3 flex flex-col gap-1 bg-gray-50 dark:bg-gray-800/50 -mx-6 px-6 pb-2">';
                                        echo '<span class="uppercase tracking-wider text-[9px] font-bold">Assigned Business</span>';
                                        echo '<strong class="text-gray-700 dark:text-gray-300 truncate"><i class="fa-solid fa-building mr-1"></i> ' . esc_html($parent_admin->user_login) . '</strong>';
                                        echo '</div>';
                                    }
                                }
                            ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="flex-1 mt-6 overflow-y-auto">
                <a href="?tab=dashboard" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-line w-6"></i> <span>Dashboard</span>
                </a>
                <?php if(current_user_can('SimpleBill_shop_cap')): ?>
                <a href="?tab=pos" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'pos' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-cash-register w-6"></i> <span>New Sale</span>
                </a>
                <?php endif; ?>
                
                <?php if(!current_user_can('administrator')): ?>
                <a href="?tab=history" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'history' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-receipt w-6"></i> <span>Sales History</span>
                </a>
                <?php endif; ?>

                <?php if(current_user_can('administrator')): ?>
                <a href="?tab=admins" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'admins' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-tie w-6"></i> <span>Admins</span>
                </a>
                <?php endif; ?>

                <?php if(current_user_can('administrator') || current_user_can('SimpleBill_admin_cap')): ?>
                <a href="?tab=logs" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'logs' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clipboard-list w-6"></i> <span>Activity Logs</span>
                </a>
                <?php endif; ?>

                <?php if(!current_user_can('administrator')): ?>
                <a href="?tab=products" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'products' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-box-open w-6"></i> <span>Products</span>
                </a>
                <?php endif; ?>

                <?php if(!current_user_can('administrator')): ?>
                <a href="?tab=customers" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'customers' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users w-6"></i> <span>Customers</span>
                </a>
                <?php endif; ?>
                
                <?php if(current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')): ?>
                <a href="?tab=settings" class="nav-link flex items-center px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition <?php echo $current_tab == 'settings' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-gear w-6"></i> <span>Store Settings</span>
                </a>
                <?php endif; ?>
            </nav>

            <div class="p-4 border-t dark:border-gray-800">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold">
                        <?php echo strtoupper(substr($user->user_login, 0, 1)); ?>
                    </div>
                    <div class="text-xs">
                        <p class="font-bold truncate w-32"><?php echo esc_html($user->user_login); ?></p>
                        <p class="text-gray-500"><?php echo implode(', ', $user->roles); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col h-screen overflow-hidden">
            <header class="bg-white dark:bg-gray-900 h-16 border-b dark:border-gray-800 flex items-center justify-between px-4 md:px-8 flex-shrink-0 hidden-print">
                <div class="flex items-center">
                    <button onclick="toggleMobileMenu()" class="md:hidden mr-4 text-gray-500 hover:text-blue-600 dark:hover:text-blue-400 focus:outline-none transition">
                        <i class="fa-solid fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl font-bold capitalize truncate max-w-[150px] sm:max-w-none"><?php echo str_replace('-', ' ', $current_tab); ?></h1>
                </div>
                
                <div class="flex items-center space-x-4">
                    <?php if (current_user_can('administrator')): ?>
                    <div class="hidden lg:flex items-center gap-3 border-r border-gray-200 dark:border-gray-700 pr-4 mr-2">
                        <div class="flex items-center gap-2 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 px-3 py-1.5 rounded-full border border-emerald-100 dark:border-emerald-800" title="Active Users (Last 15 mins)">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            <span class="text-[10px] font-bold uppercase tracking-wider"><?php echo $active_shops_count; ?> Active Users</span>
                        </div>
                        <div class="flex items-center gap-2 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 px-3 py-1.5 rounded-full border border-blue-100 dark:border-blue-800" title="Active Admins (Last 15 mins)">
                            <i class="fa-solid fa-shield-halved text-[10px]"></i>
                            <span class="text-[10px] font-bold uppercase tracking-wider"><?php echo $active_admins_count; ?> Admins Online</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="text-sm text-gray-500 hidden sm:block header-datetime">
                        <?php 
                            $tz_str = get_option('SimpleBill_timezone', 'Asia/Colombo');
                            $date = new DateTime('now', new DateTimeZone($tz_str));
                            echo $date->format('l, F j, Y | H:i');
                        ?>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button onclick="window.location.href='?tab=<?php echo esc_js($current_tab); ?>';" class="text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 px-3 py-1.5 rounded-lg flex items-center space-x-2 text-sm font-medium" title="Refresh Page">
                            <i class="fa-solid fa-rotate-right"></i>
                            <span class="hidden sm:inline">Refresh</span>
                        </button>
                        <?php if(get_option('SimpleBill_chat_enabled', '1')): ?>
                        <button onclick="toggleChatWindow()" class="text-gray-500 hover:text-green-600 dark:text-gray-400 dark:hover:text-green-400 transition bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 px-3 py-1.5 rounded-lg flex items-center text-sm font-medium" title="Open Live Chat">
                            <i class="fa-solid fa-message"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="toggleDarkMode()" class="text-gray-500 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 transition bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 px-3 py-1.5 rounded-lg flex items-center text-sm font-medium" title="Toggle Theme">
                            <i class="fa-solid fa-moon dark:hidden"></i>
                            <i class="fa-solid fa-sun hidden dark:block"></i>
                        </button>
                        <a href="?SimpleBill_logout=1" class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 px-3 py-1.5 rounded-lg flex items-center text-sm font-bold" title="Logout">
                            <i class="fa-solid fa-power-off"></i>
                            <span class="hidden sm:inline ml-2">Logout</span>
                        </a>
                    </div>
                </div>
            </header>
            
            <div class="flex-1 overflow-y-auto p-4 md:p-8">
                <?php 
                    switch($current_tab) {
                        case 'dashboard':
                            if(current_user_can('administrator')) SimpleBill_render_superadmin_dashboard_tab();
                            elseif(current_user_can('SimpleBill_admin_cap')) SimpleBill_render_admin_dashboard_tab();
                            else SimpleBill_render_shop_dashboard_tab();
                            break;
                        case 'pos':
                            if(current_user_can('SimpleBill_shop_cap')) SimpleBill_render_shop_pos_form();
                            break;
                        case 'history':
                            if(!current_user_can('administrator')) SimpleBill_render_history_table();
                            break;
                        case 'admins':
                            if(current_user_can('administrator')) SimpleBill_render_admins_section();
                            break;
                        case 'products':
                            if(!current_user_can('administrator')) SimpleBill_render_products_section();
                            break;
                        case 'customers':
                            if(!current_user_can('administrator')) SimpleBill_render_customers_section();
                            break;
                        case 'archives':
                            if(current_user_can('administrator')) SimpleBill_render_deleted_history_table();
                            break;
                        case 'logs':
                            if(current_user_can('administrator') || current_user_can('SimpleBill_admin_cap')) SimpleBill_render_logs_section();
                            break;
                        case 'settings':
                            if(current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')) SimpleBill_render_admin_settings_tab();
                            break;
                        default:
                            echo "<p>Section not found.</p>";
                    }
                ?>
            </div>
            
            <!-- Attribution Footer -->
            <div class="text-center py-4 text-xs text-gray-500 dark:text-gray-400 border-t dark:border-gray-800">
                &copy; <?php echo date('Y'); ?> <?php echo esc_html($business_name); ?>. 
                System by <a href="https://www.linkedin.com/in/rowsul-ilahi-b4611a19b" target="_blank" class="font-bold hover:text-blue-600 dark:hover:text-blue-400 transition">Ilahi</a> &bull; 
                Designed by <a href="https://www.facebook.com/share/1BADKhBsJM/" target="_blank" class="font-bold hover:text-blue-600 dark:hover:text-blue-400 transition">PearlWaves</a>
            </div>
        </main>
        
        <?php 
        $SimpleBill_modal_uid = get_current_user_id();
        $SimpleBill_already_on_settings = (isset($_GET['tab']) && $_GET['tab'] === 'settings');
        $SimpleBill_has_filled_fields = (
            get_user_meta($SimpleBill_modal_uid, 'SimpleBill_business_name', true) ||
            get_user_meta($SimpleBill_modal_uid, 'SimpleBill_contact', true) ||
            get_user_meta($SimpleBill_modal_uid, 'SimpleBill_address', true) ||
            get_user_meta($SimpleBill_modal_uid, 'SimpleBill_logo_url', true)
        );
        if (current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator') && !get_user_meta($SimpleBill_modal_uid, 'SimpleBill_settings_configured', true) && !$SimpleBill_already_on_settings && !$SimpleBill_has_filled_fields): ?>
        <!-- First-Login Store Setup Modal -->
        <div id="SimpleBill-setup-modal" class="fixed inset-0 bg-black/70 z-[200] flex items-center justify-center backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-6 md:p-8 max-w-sm w-full mx-4 border dark:border-gray-800 text-center">
                <div class="flex items-center justify-center w-16 h-16 mx-auto bg-blue-100 dark:bg-blue-900/30 rounded-full mb-5">
                    <i class="fa-solid fa-store text-3xl text-blue-600 dark:text-blue-400"></i>
                </div>
                <h3 class="text-xl font-black text-gray-900 dark:text-white mb-2">Welcome! Set Up Your Store</h3>
                <p class="text-gray-500 dark:text-gray-400 mb-7 font-medium text-sm">Please configure your store details before you start invoicing. This includes your business name, contact, and address that appear on invoices.</p>
                <div class="flex flex-col gap-3">
                    <a href="?tab=settings" onclick="document.getElementById('SimpleBill-setup-modal').remove()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-lg shadow-blue-600/30">Set Up Store Now</a>
                    <button onclick="document.getElementById('SimpleBill-setup-modal').remove()" class="w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 font-bold py-3 px-4 rounded-xl transition text-sm">Skip for Now</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Live Chat Modal -->
        <?php if(get_option('SimpleBill_chat_enabled', '1')): ?>
        <div id="SimpleBill-chat-modal" class="hidden fixed bottom-20 right-4 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-xl shadow-2xl w-80 h-96 flex flex-col z-[110]">
            <div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-4 flex justify-between items-center rounded-t-xl">
                <h3 class="font-bold flex items-center"><i class="fa-solid fa-message mr-2"></i> Live Chat</h3>
                <div class="flex gap-2">
                    <?php if(current_user_can('administrator')): ?>
                    <button onclick="clearAllChat()" class="text-white hover:bg-white/20 px-2 py-1 rounded transition" title="Clear all chat">
                        <i class="fa-solid fa-trash text-sm"></i>
                    </button>
                    <?php endif; ?>
                    <button onclick="toggleChatWindow()" class="text-white hover:bg-white/20 px-2 py-1 rounded transition">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-2 bg-gray-50 dark:bg-gray-800"></div>
            <div class="p-4 border-t dark:border-gray-700 bg-white dark:bg-gray-900 space-y-2">
                <?php $current_user = wp_get_current_user(); if(current_user_can('administrator')): ?>
                <label class="flex items-center text-xs text-gray-600 dark:text-gray-400">
                    <input type="checkbox" id="chat-announcement-check" class="mr-2"> Send as announcement to all
                </label>
                <?php endif; ?>
                <div class="flex gap-2">
                    <input type="text" id="chat-input" placeholder="Type message..." class="flex-1 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-green-500" onkeypress="if(event.key==='Enter') sendChatMessage()">
                    <button onclick="sendChatMessage()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold transition text-sm">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Chat Notification -->
        <div id="SimpleBill-chat-notification" class="hidden fixed top-4 right-4 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg shadow-xl p-4 max-w-sm z-[120] animate-bounce">
            <div class="flex justify-between items-start">
                <div>
                    <p class="font-bold text-sm"><span id="notif-sender-name"></span> <span id="notif-sender-role" class="text-xs bg-white/20 px-2 py-0.5 rounded inline-block ml-1"></span></p>
                    <p class="text-sm mt-1" id="notif-message"></p>
                </div>
                <button onclick="document.getElementById('SimpleBill-chat-notification').classList.add('hidden')" class="text-white hover:bg-white/20 px-2 rounded transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Custom Alert/Confirm Modal -->
        <div id="SimpleBill-custom-modal" class="fixed inset-0 bg-black/60 z-[100] hidden flex items-center justify-center backdrop-blur-sm transition-opacity opacity-0">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-6 md:p-8 max-w-sm w-full mx-4 transform scale-95 transition-transform duration-200 border dark:border-gray-800">
                <div id="SimpleBill-modal-icon-bg" class="flex items-center justify-center w-16 h-16 mx-auto bg-red-100 dark:bg-red-900/30 rounded-full mb-6">
                    <i id="SimpleBill-modal-icon" class="fa-solid fa-triangle-exclamation text-3xl text-red-600 dark:text-red-500"></i>
                </div>
                <h3 id="SimpleBill-modal-title" class="text-xl font-black text-center mb-2 text-gray-900 dark:text-white">Confirm Action</h3>
                <p id="SimpleBill-modal-message" class="text-center text-gray-500 dark:text-gray-400 mb-8 font-medium"></p>
                <div class="flex gap-3 justify-center">
                    <button id="SimpleBill-modal-cancel" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold py-3 px-4 rounded-xl transition">Cancel</button>
                    <button id="SimpleBill-modal-confirm" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-lg shadow-red-600/30">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Plan Management Modal -->
        <div id="SimpleBill-plan-modal" class="fixed inset-0 bg-black/50 z-[105] hidden flex items-center justify-center backdrop-blur-sm transition-opacity opacity-0">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-6 md:p-8 max-w-lg w-full mx-4 transform scale-95 transition-transform duration-200 border dark:border-gray-800">
                <h3 class="text-xl font-black text-gray-900 dark:text-white mb-4">Manage Admin Plan</h3>
                <form method="post" id="SimpleBill-plan-form" class="space-y-4">
                    <input type="hidden" name="admin_id" id="SimpleBill-plan-admin-id" value="">
                    <div>
                        <label class="block text-sm font-bold mb-2">Current Plan</label>
                        <p id="SimpleBill-plan-current" class="text-sm text-gray-500">Loading...</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2">Plan Type</label>
                        <select name="new_plan_type" id="SimpleBill-plan-type" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                            <option value="monthly">Monthly Plan (30 days)</option>
                            <option value="yearly">Yearly Plan (365 days)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2">Plan Range</label>
                        <p id="SimpleBill-plan-range" class="text-sm text-gray-500">No active plan.</p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <button type="submit" name="SimpleBill_renew_admin_plan" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-3 px-4 rounded-xl font-bold transition">Renew Plan</button>
                        <button type="submit" name="SimpleBill_change_admin_plan" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-3 px-4 rounded-xl font-bold transition">Change Plan</button>
                    </div>
                    <div class="text-right">
                        <button type="button" onclick="closePlanModal()" class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            let modalConfirmCallback = null;

            function openModal(message, isAlert = false, callback = null) {
                const modal = document.getElementById('SimpleBill-custom-modal');
                const msgEl = document.getElementById('SimpleBill-modal-message');
                const cancelBtn = document.getElementById('SimpleBill-modal-cancel');
                const confirmBtn = document.getElementById('SimpleBill-modal-confirm');
                const titleEl = document.getElementById('SimpleBill-modal-title');
                const icon = document.getElementById('SimpleBill-modal-icon');
                const iconBg = document.getElementById('SimpleBill-modal-icon-bg');
                
                msgEl.innerText = message;
                modal.classList.remove('hidden');
                
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modal.querySelector('div').classList.remove('scale-95');
                }, 10);

                if (isAlert) {
                    cancelBtn.classList.add('hidden');
                    confirmBtn.innerText = 'OK';
                    confirmBtn.className = 'w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-lg shadow-blue-600/30';
                    titleEl.innerText = 'Notice';
                    icon.className = 'fa-solid fa-circle-info text-3xl text-blue-600 dark:text-blue-500';
                    iconBg.className = 'flex items-center justify-center w-16 h-16 mx-auto bg-blue-100 dark:bg-blue-900/30 rounded-full mb-6';
                    modalConfirmCallback = () => { closeModal(); if(callback) callback(); };
                } else {
                    cancelBtn.classList.remove('hidden');
                    confirmBtn.innerText = 'Yes, Proceed';
                    confirmBtn.className = 'flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-lg shadow-red-600/30';
                    titleEl.innerText = 'Warning!';
                    icon.className = 'fa-solid fa-triangle-exclamation text-3xl text-red-600 dark:text-red-500';
                    iconBg.className = 'flex items-center justify-center w-16 h-16 mx-auto bg-red-100 dark:bg-red-900/30 rounded-full mb-6';
                    modalConfirmCallback = () => { closeModal(); if(callback) callback(); };
                }
            }

            function closeModal() {
                const modal = document.getElementById('SimpleBill-custom-modal');
                modal.classList.add('opacity-0');
                modal.querySelector('div').classList.add('scale-95');
                setTimeout(() => modal.classList.add('hidden'), 200);
            }

            document.getElementById('SimpleBill-custom-modal').addEventListener('click', (e) => {
                if (e.target.id === 'SimpleBill-custom-modal') closeModal();
            });
            document.getElementById('SimpleBill-modal-cancel').addEventListener('click', closeModal);
            document.getElementById('SimpleBill-modal-confirm').addEventListener('click', () => {
                if(modalConfirmCallback) modalConfirmCallback();
            });

            function customAlert(msg) {
                openModal(msg, true);
            }

            function customConfirmLink(e, msg, callback = null) {
                e.preventDefault();
                const targetUrl = e.currentTarget.href;
                if (callback) {
                    openModal(msg, false, callback);
                } else {
                    openModal(msg, false, () => window.location.href = targetUrl);
                }
            }

            function openPlanModal(button) {
                const planModal = document.getElementById('SimpleBill-plan-modal');
                const adminIdInput = document.getElementById('SimpleBill-plan-admin-id');
                const currentPlan = document.getElementById('SimpleBill-plan-current');
                const planRange = document.getElementById('SimpleBill-plan-range');
                const planTypeSelect = document.getElementById('SimpleBill-plan-type');

                const adminId = button.dataset.adminId;
                const planType = button.dataset.planType || 'monthly';
                const planStart = button.dataset.planStart || 'N/A';
                const planEnd = button.dataset.planEnd || 'N/A';

                adminIdInput.value = adminId;
                planTypeSelect.value = planType;
                currentPlan.textContent = planType ? planType.charAt(0).toUpperCase() + planType.slice(1) + ' plan' : 'No active plan';
                planRange.textContent = planStart !== 'N/A' ? planStart + ' - ' + planEnd : 'No active plan.';

                planModal.classList.remove('hidden');
                setTimeout(() => {
                    planModal.classList.remove('opacity-0');
                    planModal.querySelector('div').classList.remove('scale-95');
                }, 10);
            }

            function closePlanModal() {
                const planModal = document.getElementById('SimpleBill-plan-modal');
                planModal.classList.add('opacity-0');
                planModal.querySelector('div').classList.add('scale-95');
                setTimeout(() => planModal.classList.add('hidden'), 200);
            }

            document.getElementById('SimpleBill-plan-modal').addEventListener('click', (e) => {
                if (e.target.id === 'SimpleBill-plan-modal') closePlanModal();
            });

            // ─── CONNECTION ERROR MODAL ───
            function showConnectionError() {
                const modal = document.getElementById('SimpleBill-custom-modal');
                const msgEl = document.getElementById('SimpleBill-modal-message');
                const titleEl = document.getElementById('SimpleBill-modal-title');
                const icon = document.getElementById('SimpleBill-modal-icon');
                const iconBg = document.getElementById('SimpleBill-modal-icon-bg');
                const cancelBtn = document.getElementById('SimpleBill-modal-cancel');
                const confirmBtn = document.getElementById('SimpleBill-modal-confirm');

                msgEl.innerText = 'Network connection error. Please check your internet and try again.';
                titleEl.innerText = 'Connection Error';
                icon.className = 'fa-solid fa-triangle-exclamation text-3xl text-red-600 dark:text-red-500';
                iconBg.className = 'flex items-center justify-center w-16 h-16 mx-auto bg-red-100 dark:bg-red-900/30 rounded-full mb-6';
                cancelBtn.classList.add('hidden');
                confirmBtn.innerText = 'OK';
                confirmBtn.className = 'w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-xl transition shadow-lg shadow-red-600/30';

                modal.classList.remove('hidden');
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modal.querySelector('div').classList.remove('scale-95');
                }, 10);

                modalConfirmCallback = closeModal;
            }

            // ─── LOADING BAR ───
            window.addEventListener('beforeunload', () => {
                const bar = document.getElementById('loading-bar');
                if (bar) bar.style.width = '100%';
            });

            window.addEventListener('load', () => {
                const bar = document.getElementById('loading-bar');
                if (bar) bar.style.width = '0%';
            });

            function toggleDarkMode() {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                }
                updateThemeUI();
            }

            function updateThemeUI() {
                const icon = document.getElementById('theme-icon');
                const text = document.getElementById('theme-text');
                if (icon && text) {
                    if (document.documentElement.classList.contains('dark')) {
                        icon.className = 'fa-solid fa-sun';
                        text.textContent = 'Light Mode';
                    } else {
                        icon.className = 'fa-solid fa-moon';
                        text.textContent = 'Dark Mode';
                    }
                }
            }

            // Initialize theme on page load
            document.addEventListener('DOMContentLoaded', () => {
                updateThemeUI();
            });
            function toggleMobileMenu() {
                document.getElementById('sidebar').classList.toggle('-translate-x-full');
                document.getElementById('mobile-backdrop').classList.toggle('hidden');
            }
            function filterTable(inputId, tableId) {
                let input = document.getElementById(inputId);
                let filter = input.value.toLowerCase();
                let table = document.getElementById(tableId);
                let tr = table.getElementsByTagName('tr');
                for (let i = 1; i < tr.length; i++) {
                    let txtValue = tr[i].textContent || tr[i].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) { tr[i].style.display = ""; } 
                    else { tr[i].style.display = "none"; }
                }
            }
            function filterGrid(inputId, gridId) {
                let input = document.getElementById(inputId);
                let filter = input.value.toLowerCase();
                let container = document.getElementById(gridId);
                let items = container.getElementsByClassName('grid-item');
                for (let i = 0; i < items.length; i++) {
                    let txtValue = items[i].textContent || items[i].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) { items[i].style.display = ""; } 
                    else { items[i].style.display = "none"; }
                }
            }

            // ──── LIVE CHAT ────
            let lastChatCount = 0;
            let currentUser = '<?php echo wp_get_current_user()->user_login; ?>';

            function toggleChatWindow() {
                const chatModal = document.getElementById('SimpleBill-chat-modal');
                if (chatModal && chatModal.classList.contains('hidden')) {
                    chatModal.classList.remove('hidden');
                    setTimeout(() => loadChatMessages(), 100);
                } else if (chatModal) {
                    chatModal.classList.add('hidden');
                }
            }

            function loadChatMessages() {
                fetch('<?php echo esc_url(home_url('?SimpleBill_chat_action=get_messages')); ?>')
                    .then(response => response.json())
                    .then(data => {
                        const chatBox = document.getElementById('chat-messages');
                        if (!chatBox) return;
                        
                        // Check for new messages and show notifications
                        if (data && data.length > lastChatCount && !document.getElementById('SimpleBill-chat-modal').classList.contains('hidden') === false) {
                            const newMessages = data.slice(lastChatCount);
                            newMessages.forEach(msg => {
                                if (msg.sender_name !== currentUser) {
                                    showChatNotification(msg);
                                }
                            });
                        }
                        
                        lastChatCount = data ? data.length : 0;
                        
                        chatBox.innerHTML = '';
                        (data || []).forEach(msg => {
                            const msgEl = document.createElement('div');
                            msgEl.className = 'mb-3 p-3 rounded-lg ' + (msg.is_announcement ? 'bg-blue-100 dark:bg-blue-900/30 border-l-4 border-blue-500' : 'bg-gray-100 dark:bg-gray-800');
                            const time = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                            const roleText = msg.sender_role ? ' (' + escapeHtml(msg.sender_role) + ')' : '';
                            msgEl.innerHTML = '<strong class="text-sm">' + escapeHtml(msg.sender_name) + '<span class="text-xs text-gray-600 dark:text-gray-400">' + roleText + '</span></strong><br>' + escapeHtml(msg.message) + '<br><small class="text-gray-500 text-xs">' + time + '</small>';
                            chatBox.appendChild(msgEl);
                        });
                        chatBox.scrollTop = chatBox.scrollHeight;
                    })
                    .catch(err => console.log('Chat load error:', err));
            }

            function showChatNotification(msg) {
                const notif = document.getElementById('SimpleBill-chat-notification');
                if (!notif) return;
                
                document.getElementById('notif-sender-name').textContent = msg.sender_name;
                document.getElementById('notif-sender-role').textContent = msg.sender_role || 'User';
                document.getElementById('notif-message').textContent = msg.message.substring(0, 80) + (msg.message.length > 80 ? '...' : '');
                
                notif.classList.remove('hidden');
                setTimeout(() => {
                    notif.classList.add('hidden');
                }, 5000);
            }

            function sendChatMessage() {
                const input = document.getElementById('chat-input');
                const announcementCheckbox = document.getElementById('chat-announcement-check');
                const message = input.value.trim();
                
                if (!message) return;

                const formData = new FormData();
                formData.append('SimpleBill_chat_action', 'send_message');
                formData.append('message', message);
                formData.append('is_announcement', announcementCheckbox && announcementCheckbox.checked ? '1' : '0');

                fetch('<?php echo esc_url(home_url()); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        input.value = '';
                        loadChatMessages();
                    }
                })
                .catch(err => console.log('Send error:', err));
            }

            function clearAllChat() {
                customConfirmLink({preventDefault: () => {}, currentTarget: {href: '#'}}, 'Are you sure you want to clear all chat messages? This cannot be undone.', () => {
                    fetch('<?php echo esc_url(home_url()); ?>', {
                        method: 'POST',
                        body: new URLSearchParams({SimpleBill_chat_action: 'clear_all'})
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            lastChatCount = 0;
                            loadChatMessages();
                            customAlert('All chat messages cleared successfully');
                        }
                    })
                    .catch(err => console.log('Clear error:', err));
                });
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Auto-refresh chat every 2 seconds
            setInterval(() => {
                const chatModal = document.getElementById('SimpleBill-chat-modal');
                if (chatModal && !chatModal.classList.contains('hidden')) {
                    loadChatMessages();
                }
            }, 2000);
        </script>

    </body>
    </html>
    <?php
}

// ==========================================
// 5. PRODUCTS SECTION 
// ==========================================
function SimpleBill_render_products_section() {
    global $wpdb;
    $table_prod = $wpdb->prefix . 'SimpleBill_products';
    $user_id = get_current_user_id();
    $admin_id = current_user_can('SimpleBill_admin_cap') ? $user_id : (get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0);
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);

    if (current_user_can('SimpleBill_admin_cap')) {
        // Handle single product add/edit
        if (isset($_POST['SimpleBill_add_product']) || isset($_POST['SimpleBill_edit_product'])) {
            $data = [
                'admin_id' => $user_id,
                'hsn_code' => sanitize_text_field($_POST['p_hsn']),
                'name'     => sanitize_text_field($_POST['p_name']),
                'unit'     => sanitize_text_field($_POST['p_unit']),
                'price'    => floatval($_POST['p_price'])
            ];
            if (isset($_POST['edit_id']) && intval($_POST['edit_id']) > 0) {
                $wpdb->update($table_prod, $data, ['id' => intval($_POST['edit_id'])]);
                SimpleBill_log('EDIT', 'Product', 'Product: ' . sanitize_text_field($_POST['p_name']) . ' | ID: ' . intval($_POST['edit_id']));
                echo "<script>window.location.href='?tab=products&msg=updated';</script>";
            } else {
                $data['user_id'] = $user_id;
                $wpdb->insert($table_prod, $data);
                SimpleBill_log('ADD', 'Product', 'Product: ' . sanitize_text_field($_POST['p_name']));
                echo "<script>window.location.href='?tab=products&msg=added';</script>";
            }
        }

        // Handle Delete
        if (isset($_GET['delete_product_id'])) {
            $del_id = intval($_GET['delete_product_id']);
            $del_prod = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_prod WHERE id = %d", $del_id));
            $wpdb->query($wpdb->prepare("UPDATE $table_prod SET is_deleted = 1 WHERE id = %d AND admin_id = %d", $del_id, $user_id));
            SimpleBill_log('DELETE', 'Product', 'Product: ' . ($del_prod ? $del_prod->name : 'ID ' . $del_id));
            echo "<script>window.location.href='?tab=products';</script>";
        }

        // Handle CSV Upload
        if (isset($_POST['import_products']) && isset($_FILES['product_csv'])) {
            $file = $_FILES['product_csv']['tmp_name'];
            if (($handle = fopen($file, "r")) !== FALSE) {
                fgetcsv($handle); // Skip header row
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) < 5) continue;
                    $sr_no = sanitize_text_field($data[0] ?? '');
                    $hsn = sanitize_text_field($data[1] ?? '');
                    $name = sanitize_text_field($data[2] ?? '');
                    $unit = sanitize_text_field($data[3] ?? '');
                    $price = floatval(str_replace(',', '', $data[4] ?? 0));
                    
                    if (!empty($name)) {
                        $wpdb->insert($table_prod, [
                            'user_id' => $user_id,
                            'admin_id' => $user_id,
                            'sr_no' => $sr_no,
                            'hsn_code' => $hsn,
                            'name' => $name,
                            'unit' => $unit,
                            'price' => $price
                        ]);
                    }
                }
                fclose($handle);
                SimpleBill_log('BULK IMPORT', 'Products', 'CSV import completed');
                echo "<script>window.location.href='?tab=products&msg=imported';</script>";
            }
        }
    } // End of admin capabilities check

    $where = "is_deleted = 0";
    $where .= $wpdb->prepare(" AND admin_id = %d", $admin_id);

    $products = $wpdb->get_results("SELECT * FROM $table_prod WHERE $where ORDER BY name ASC");
    $distinct_units = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT unit FROM $table_prod WHERE is_deleted = 0 AND admin_id = %d AND unit != '' ORDER BY unit ASC", $admin_id));

    $edit_id = isset($_GET['edit_product_id']) ? intval($_GET['edit_product_id']) : 0;
    $edit_prod = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_prod WHERE id = %d", $edit_id)) : null;
    ?>
    
    <?php if(current_user_can('SimpleBill_admin_cap')): ?>
    <!-- Add / Edit Single Product Form -->
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6 mb-8 hidden-print">
        <h3 class="font-bold mb-4 flex items-center">
            <i class="fa-solid fa-box-open mr-2 text-blue-500"></i> 
            <?php echo $edit_prod ? 'Edit Product' : 'Add New Product'; ?>
        </h3>

        <datalist id="unit-list">
            <?php foreach($distinct_units as $u): ?>
                <option value="<?php echo esc_attr($u); ?>">
            <?php endforeach; ?>
        </datalist>

        <form method="post" class="flex flex-col md:flex-row gap-4 items-center">
            <?php if($edit_prod): ?><input type="hidden" name="edit_id" value="<?php echo $edit_prod->id; ?>"><?php endif; ?>
            <input type="text" name="p_hsn" value="<?php echo $edit_prod ? esc_attr($edit_prod->hsn_code) : ''; ?>" placeholder="HSN/SKU Code" class="w-full md:flex-1 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none">
            <input type="text" name="p_name" value="<?php echo $edit_prod ? esc_attr($edit_prod->name) : ''; ?>" placeholder="Product Name" class="w-full md:flex-[2] bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none" required>
            <input type="text" name="p_unit" list="unit-list" value="<?php echo $edit_prod ? esc_attr($edit_prod->unit) : ''; ?>" placeholder="Unit (e.g. PCS, Box)" class="w-full md:flex-1 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none" autocomplete="off">
            <input type="number" step="0.01" name="p_price" value="<?php echo $edit_prod ? esc_attr($edit_prod->price) : ''; ?>" placeholder="Sale Rate (<?php echo esc_attr($currency); ?>)" class="w-full md:flex-1 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none" required>
            <button type="submit" name="<?php echo $edit_prod ? 'SimpleBill_edit_product' : 'SimpleBill_add_product'; ?>" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-bold transition shadow whitespace-nowrap">
                <?php echo $edit_prod ? 'Update' : 'Add Product'; ?>
            </button>
            <?php if($edit_prod): ?>
                <a href="?tab=products" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Import/Export Tools -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8 hidden-print">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6">
            <h3 class="font-bold mb-4 flex items-center justify-between">
                <span><i class="fa-solid fa-file-csv mr-2 text-green-500"></i> Bulk Import Products</span>
            </h3>
            <p class="text-sm text-gray-500 mb-4">Upload a CSV file with 5 columns: <strong>Sr No, HSN Code, Product Name, Measurement Units, Sale Rate</strong>.</p>
            <p class="text-sm mb-4"><a href="data:text/csv;charset=utf-8,Sr No,HSN Code,Product Name,Measurement Units,Sale Rate" download="product_template.csv" class="text-blue-500 underline uppercase">Download Template</a></p>
            <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-4">
                <input type="file" name="product_csv" accept=".csv" required class="flex-1 w-full sm:w-auto border dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg text-sm outline-none">
                <button type="submit" name="import_products" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-bold transition shadow">Import</button>
            </form>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6 flex flex-col justify-center">
            <h3 class="font-bold mb-2 flex items-center"><i class="fa-solid fa-file-export mr-2 text-blue-500"></i> Data Export</h3>
            <p class="text-sm text-gray-500 mb-4">Download your entire product inventory as a CSV file for backup or reports.</p>
            <a href="?SimpleBill_export_products=1" class="w-full text-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-bold transition shadow flex items-center justify-center gap-2">
                <i class="fa-solid fa-download"></i> Export Products to CSV
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 overflow-hidden">
        <div class="p-6 border-b dark:border-gray-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-xl font-bold">Product Inventory</h2>
                <p class="text-sm text-gray-500">Manage all store items.</p>
            </div>
            <div class="relative w-full md:w-64 hidden-print">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="product-search" onkeyup="filterProducts()" placeholder="Search products..." class="w-full pl-10 pr-4 py-2 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
            </div>
        </div>
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left min-w-[600px]" id="product-table">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase text-gray-500 font-bold whitespace-nowrap">
                    <tr>
                        <th class="px-6 py-4">HSN/SKU Code</th>
                        <th class="px-6 py-4">Product Name</th>
                        <th class="px-6 py-4">Unit</th>
                        <th class="px-6 py-4">Sale Rate</th>
                        <?php if(current_user_can('SimpleBill_admin_cap')): ?>
                        <th class="px-6 py-4 text-right hidden-print">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-800">
                    <?php if(empty($products)): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 italic">No products found in your inventory.</td></tr>
                    <?php endif; ?>
                    <?php foreach($products as $p): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                            <td class="px-6 py-4 text-sm font-mono whitespace-nowrap text-gray-500"><?php echo esc_html($p->hsn_code); ?></td>
                            <td class="px-6 py-4 font-bold text-gray-800 dark:text-gray-200"><?php echo esc_html($p->name); ?></td>
                            <td class="px-6 py-4 text-sm uppercase whitespace-nowrap text-gray-500"><?php echo esc_html($p->unit); ?></td>
                            <td class="px-6 py-4 font-bold text-blue-600 dark:text-blue-400 whitespace-nowrap"><?php echo esc_html($currency); ?> <?php echo number_format($p->price, 2); ?></td>
                            <?php if(current_user_can('SimpleBill_admin_cap')): ?>
                            <td class="px-6 py-4 text-right whitespace-nowrap hidden-print">
                                <a href="?tab=products&edit_product_id=<?php echo $p->id; ?>" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition p-2 rounded-lg mr-1"><i class="fa-solid fa-pen-to-square"></i></a>
                                <a href="?tab=products&delete_product_id=<?php echo $p->id; ?>" onclick="customConfirmLink(event, 'WARNING: Delete this product from inventory? This action cannot be undone.')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition p-2 rounded-lg"><i class="fa-solid fa-trash-can"></i></a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        function filterProducts() {
            let input = document.getElementById('product-search');
            let filter = input.value.toLowerCase();
            let table = document.getElementById('product-table');
            let tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let hsn = tr[i].getElementsByTagName('td')[0];
                let name = tr[i].getElementsByTagName('td')[1];
                if (name || hsn) {
                    let txtValue = (hsn.textContent || hsn.innerText) + (name.textContent || name.innerText);
                    if (txtValue.toLowerCase().indexOf(filter) > -1) { tr[i].style.display = ""; } 
                    else { tr[i].style.display = "none"; }
                }
            }
        }
    </script>
    <?php
}

// ==========================================
// 6. CUSTOMERS SECTION
// ==========================================
function SimpleBill_render_customers_section() {
    global $wpdb;
    $table_cust = $wpdb->prefix . 'SimpleBill_customers';
    $table_inv = $wpdb->prefix . 'SimpleBill_invoices';
    $user_id = get_current_user_id();
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    $admin_id = current_user_can('SimpleBill_admin_cap') ? $user_id : (get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0);

    if (current_user_can('SimpleBill_admin_cap')) {
        // Handle Single Customer Add/Edit
        if (isset($_POST['SimpleBill_add_customer']) || isset($_POST['SimpleBill_edit_customer'])) {
            $data = [
                'admin_id' => $admin_id,
                'sr_no'    => sanitize_text_field($_POST['c_sr_no']),
                'name'     => sanitize_text_field($_POST['c_name']),
                'phone'    => sanitize_text_field($_POST['c_phone']),
                'address'  => sanitize_text_field($_POST['c_address'])
            ];
            
            if (isset($_POST['edit_id']) && intval($_POST['edit_id']) > 0) {
                $wpdb->update($table_cust, $data, ['id' => intval($_POST['edit_id'])]);
                SimpleBill_log('EDIT', 'Customer', 'Customer: ' . sanitize_text_field($_POST['c_name']) . ' | ID: ' . intval($_POST['edit_id']));
                echo "<script>window.location.href='?tab=customers&msg=updated';</script>";
            } else {
                $data['user_id'] = $user_id;
                $wpdb->insert($table_cust, $data);
                SimpleBill_log('ADD', 'Customer', 'Customer: ' . sanitize_text_field($_POST['c_name']));
                echo "<script>window.location.href='?tab=customers&msg=added';</script>";
            }
        }

        // Handle CSV Upload
        if (isset($_POST['import_customers']) && isset($_FILES['csv_file'])) {
            $file = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($file, "r")) !== FALSE) {
                fgetcsv($handle); // Skip header row
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $sr_no = sanitize_text_field($data[0] ?? '');
                    $name = sanitize_text_field($data[1] ?? '');
                    $phone = sanitize_text_field($data[2] ?? '');
                    $address = sanitize_textarea_field($data[3] ?? '');
                    
                    if (!empty($name)) {
                        $wpdb->insert($table_cust, [
                            'user_id' => $user_id,
                            'admin_id' => $admin_id,
                            'sr_no' => $sr_no,
                            'name' => $name,
                            'phone' => $phone,
                            'address' => $address
                        ]);
                    }
                }
                fclose($handle);
                SimpleBill_log('BULK IMPORT', 'Customers', 'CSV import completed');
                echo "<script>window.location.href='?tab=customers&msg=imported';</script>";
            }
        }
    } // End Admin Check

    if (isset($_GET['delete_customer_id'])) {
        $del_id = intval($_GET['delete_customer_id']);
        $cust_to_del = $wpdb->get_row($wpdb->prepare("SELECT name, phone FROM $table_cust WHERE id = %d", $del_id));
        
        $wpdb->update($table_cust, ['is_deleted' => 1], ['id' => $del_id]);
        SimpleBill_log('DELETE', 'Customer', 'Customer: ' . ($cust_to_del ? $cust_to_del->name : 'ID ' . $del_id));
        if ($cust_to_del) {
            $wpdb->query($wpdb->prepare("UPDATE $table_inv SET is_deleted = 1 WHERE customer_name = %s AND customer_phone = %s AND admin_id = %d", $cust_to_del->name, $cust_to_del->phone, $admin_id));
        }
        echo "<script>window.location.href='?tab=customers';</script>";
    }

    $where = "is_deleted = 0";
    if (!current_user_can('administrator')) {
        $where .= $wpdb->prepare(" AND admin_id = %d", $admin_id);
    }
    
    $saved_customers = $wpdb->get_results("SELECT * FROM $table_cust WHERE $where ORDER BY id DESC");
    
    $edit_id = isset($_GET['edit_customer_id']) ? intval($_GET['edit_customer_id']) : 0;
    $edit_cust = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_cust WHERE id = %d", $edit_id)) : null;
    ?>
    
    <?php if(current_user_can('SimpleBill_admin_cap')): ?>
    
    <!-- Add / Edit Single Customer Form -->
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6 mb-8 hidden-print">
        <h3 class="font-bold mb-4 flex items-center">
            <i class="fa-solid fa-user-plus mr-2 text-blue-500"></i> 
            <?php echo $edit_cust ? 'Edit Customer' : 'Add New Customer'; ?>
        </h3>

        <form method="post" class="flex flex-col md:flex-row gap-4 items-center">
            <?php if($edit_cust): ?><input type="hidden" name="edit_id" value="<?php echo $edit_cust->id; ?>"><?php endif; ?>
            <input type="text" name="c_sr_no" value="<?php echo $edit_cust ? esc_attr($edit_cust->sr_no) : ''; ?>" placeholder="Sr No" class="w-full md:w-32 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none">
            <input type="text" name="c_name" value="<?php echo $edit_cust ? esc_attr($edit_cust->name) : ''; ?>" placeholder="Customer Name" class="w-full md:flex-[2] bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none" required>
            <input type="text" name="c_phone" value="<?php echo $edit_cust ? esc_attr($edit_cust->phone) : ''; ?>" placeholder="Phone Number" class="w-full md:flex-[1.5] bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none">
            <input type="text" name="c_address" value="<?php echo $edit_cust ? esc_attr($edit_cust->address) : ''; ?>" placeholder="Address" class="w-full md:flex-[2] bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg text-sm outline-none">
            
            <button type="submit" name="<?php echo $edit_cust ? 'SimpleBill_edit_customer' : 'SimpleBill_add_customer'; ?>" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-bold transition shadow whitespace-nowrap">
                <?php echo $edit_cust ? 'Update' : 'Add Customer'; ?>
            </button>
            <?php if($edit_cust): ?>
                <a href="?tab=customers" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Import/Export Tools -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8 hidden-print">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6">
            <h3 class="font-bold mb-4 flex items-center justify-between">
                <span><i class="fa-solid fa-file-csv mr-2 text-green-500"></i> Import Customers</span>
                <a href="data:text/csv;charset=utf-8,Sr No,Name,Phone,Address" download="customer_template.csv" class="text-[10px] text-blue-500 underline uppercase">Download Template</a>
            </h3>
            <p class="text-sm text-gray-500 mb-4">Upload a CSV file with 4 columns: <strong>Sr No, Name, Phone, Address</strong>.</p>
            <form method="post" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-4">
                <input type="file" name="csv_file" accept=".csv" required class="flex-1 w-full sm:w-auto border dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg text-sm outline-none">
                <button type="submit" name="import_customers" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-bold transition shadow">Import</button>
            </form>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 p-6 flex flex-col justify-center">
            <h3 class="font-bold mb-2 flex items-center"><i class="fa-solid fa-file-export mr-2 text-blue-500"></i> Data Export</h3>
            <p class="text-sm text-gray-500 mb-4">Download your entire customer directory as a CSV file for backup or reports.</p>
            <a href="?SimpleBill_export_customers=1" class="w-full text-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-bold transition shadow flex items-center justify-center gap-2">
                <i class="fa-solid fa-download"></i> Export Customers to CSV
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 overflow-hidden">
        <div class="p-6 border-b dark:border-gray-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-xl font-bold">Customer Directory</h2>
                <p class="text-sm text-gray-500">Manage all saved customer profiles shared across the platform.</p>
            </div>
            <div class="relative w-full md:w-64 hidden-print">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="customer-search" onkeyup="filterCustomers()" placeholder="Search customers..." class="w-full pl-10 pr-4 py-2 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
            </div>
        </div>
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left min-w-[600px]" id="customer-table">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase text-gray-500 font-bold whitespace-nowrap">
                    <tr>
                        <th class="px-6 py-4">Sr No</th>
                        <th class="px-6 py-4">Customer Name</th>
                        <th class="px-6 py-4">Phone</th>
                        <th class="px-6 py-4">Address</th>
                        <th class="px-6 py-4 text-right hidden-print">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-800">
                    <?php if(empty($saved_customers)): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-400 italic">No customers found in your records.</td></tr>
                    <?php endif; ?>
                    <?php foreach($saved_customers as $c): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                            <td class="px-6 py-4 text-sm font-mono whitespace-nowrap"><?php echo esc_html($c->sr_no); ?></td>
                            <td class="px-6 py-4 font-bold text-blue-600 dark:text-blue-400 whitespace-nowrap"><?php echo esc_html($c->name); ?></td>
                            <td class="px-6 py-4 text-sm whitespace-nowrap"><?php echo $c->phone ? esc_html($c->phone) : '<span class="text-gray-400 italic">No phone</span>'; ?></td>
                            <td class="px-6 py-4 text-sm max-w-[200px] truncate" title="<?php echo esc_attr($c->address); ?>"><?php echo esc_html($c->address); ?></td>
                            <td class="px-6 py-4 text-right whitespace-nowrap hidden-print">
                                <a href="tel:<?php echo esc_attr($c->phone); ?>" class="text-green-500 hover:text-green-600 transition p-2"><i class="fa-solid fa-phone"></i></a>
                                <button onclick="copyToClipboard('<?php echo esc_js($c->phone); ?>')" class="text-blue-500 hover:text-blue-600 transition p-2"><i class="fa-solid fa-copy"></i></button>
                                <?php if(current_user_can('SimpleBill_admin_cap')): ?>
                                    <a href="?tab=customers&edit_customer_id=<?php echo $c->id; ?>" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition p-2 rounded-lg ml-1"><i class="fa-solid fa-pen-to-square"></i></a>
                                    <?php $del_url = '?tab=customers&delete_customer_id=' . $c->id; ?>
                                    <a href="<?php echo esc_url($del_url); ?>" onclick="customConfirmLink(event, 'WARNING: Delete this customer profile and archive their invoices? This action cannot be undone.')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition p-2 rounded-lg ml-1"><i class="fa-solid fa-trash-can"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        function filterCustomers() {
            let input = document.getElementById('customer-search');
            let filter = input.value.toLowerCase();
            let table = document.getElementById('customer-table');
            let tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let sr = tr[i].getElementsByTagName('td')[0];
                let name = tr[i].getElementsByTagName('td')[1];
                let phone = tr[i].getElementsByTagName('td')[2];
                let address = tr[i].getElementsByTagName('td')[3];
                if (name || phone || sr || address) {
                    let txtValue = (sr.textContent || sr.innerText) + (name.textContent || name.innerText) + (phone.textContent || phone.innerText) + (address.textContent || address.innerText);
                    if (txtValue.toLowerCase().indexOf(filter) > -1) { tr[i].style.display = ""; } 
                    else { tr[i].style.display = "none"; }
                }
            }
        }
        function copyToClipboard(text) {
            const el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
        }
    </script>
    <?php
}

// ==========================================
// 7. DASHBOARD TAB RENDERERS
// ==========================================

function SimpleBill_render_superadmin_dashboard_tab() {
    if (isset($_GET['toggle_admin'])) {
        $toggle_id = intval($_GET['toggle_admin']);
        $current_status = get_user_meta($toggle_id, 'SimpleBill_admin_disabled', true);
        update_user_meta($toggle_id, 'SimpleBill_admin_disabled', !$current_status);
        $toggled_user = get_userdata($toggle_id);
        SimpleBill_log($current_status ? 'ENABLE ADMIN' : 'DISABLE ADMIN', 'Admin Account', 'Admin: ' . ($toggled_user ? $toggled_user->user_login : 'ID ' . $toggle_id));
        echo "<script>window.location.href='?tab=dashboard';</script>";
    }

    if (isset($_POST['SimpleBill_wipe_all_data']) && isset($_POST['wipe_confirm']) && $_POST['wipe_confirm'] === 'DELETE') {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}SimpleBill_invoices");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}SimpleBill_customers");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}SimpleBill_products");
        SimpleBill_log('FACTORY RESET', 'Platform', 'All Invoices, Customers, and Products wiped');
        echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6 font-bold border border-red-200 shadow-sm"><i class="fa-solid fa-triangle-exclamation mr-2"></i> All POS databases (Invoices, Customers, Products) have been permanently cleared.</div>';
    } elseif (isset($_POST['SimpleBill_wipe_all_data'])) {
        echo '<div class="bg-orange-100 text-orange-700 p-4 rounded-lg mb-6 font-bold border border-orange-200 shadow-sm">Wipe failed: You must type DELETE exactly to confirm.</div>';
    }

    if (isset($_POST['SimpleBill_add_admin'])) {
        $user_id = wp_create_user($_POST['username'], $_POST['password'], $_POST['email']);
        if (!is_wp_error($user_id)) {
            (new WP_User($user_id))->set_role('SimpleBill_admin');
            $plan_type = sanitize_text_field($_POST['plan_type'] ?? 'monthly');
            SimpleBill_create_admin_plan($user_id, $plan_type, 1);
            SimpleBill_log('CREATE ADMIN', 'Admin Account', 'New admin: ' . sanitize_text_field($_POST['username']) . ' | Plan: ' . $plan_type);
        }
    }
    
    if (isset($_POST['SimpleBill_edit_admin'])) {
        $user_id = intval($_POST['edit_user_id']);
        $update_data = ['ID' => $user_id, 'user_email' => sanitize_email($_POST['email'])];
        if (!empty($_POST['password'])) { $update_data['user_pass'] = $_POST['password']; }
        wp_update_user($update_data);
        
        if (isset($_POST['plan_type'])) {
            $new_plan = sanitize_text_field($_POST['plan_type']);
            $current_plan = SimpleBill_get_admin_active_plan($user_id);
            if (!$current_plan) {
                SimpleBill_create_admin_plan($user_id, $new_plan, 1);
            } elseif ($current_plan->plan_type !== $new_plan) {
                SimpleBill_change_admin_plan($user_id, $new_plan);
            }
        }
        
        $edited_user = get_userdata($user_id);
        SimpleBill_log('EDIT ADMIN', 'Admin Account', 'Admin: ' . ($edited_user ? $edited_user->user_login : 'ID ' . $user_id));
        echo "<script>window.location.href='?tab=dashboard';</script>";
    }
    
    if (isset($_POST['SimpleBill_renew_admin_plan'])) {
        $admin_id = intval($_POST['admin_id']);
        $new_plan = isset($_POST['new_plan_type']) ? sanitize_text_field($_POST['new_plan_type']) : null;
        if ($new_plan) {
            SimpleBill_change_admin_plan($admin_id, $new_plan);
        } else {
            SimpleBill_renew_admin_plan($admin_id);
        }
        SimpleBill_log('RENEW PLAN', 'Admin Plan', 'Admin ID: ' . $admin_id);
    }
    
    if (isset($_POST['SimpleBill_change_admin_plan'])) {
        $admin_id = intval($_POST['admin_id']);
        $new_plan = sanitize_text_field($_POST['new_plan_type']);
        SimpleBill_change_admin_plan($admin_id, $new_plan);
        SimpleBill_log('CHANGE PLAN', 'Admin Plan', 'Admin ID: ' . $admin_id . ' | New Plan: ' . $new_plan);
    }
    
    $edit_admin_id = isset($_GET['edit_admin']) ? intval($_GET['edit_admin']) : 0;
    ?>
    <h2 class="text-2xl font-bold mb-6">Platform Management Dashboard</h2>
    
    <!-- ADMIN MANAGEMENT -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border dark:border-gray-800 shadow-sm h-fit">
            <h3 class="font-bold mb-4 flex items-center"><i class="fa-solid fa-user-plus mr-2 text-blue-500"></i> Admin / Business Mgt</h3>
            <?php if($edit_admin_id && $edit_user = get_userdata($edit_admin_id)): ?>
                <form method="post" class="space-y-4" onsubmit="if(this.password.value && this.password.value !== this.confirm_password.value) { event.preventDefault(); customAlert('Passwords do not match!'); return false; }">
                    <input type="hidden" name="edit_user_id" value="<?php echo $edit_admin_id; ?>">
                    <input type="email" name="email" value="<?php echo esc_attr($edit_user->user_email); ?>" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                    <?php $edit_plan = SimpleBill_get_admin_active_plan($edit_admin_id); ?>
                    <div>
                        <label class="block text-sm font-bold mb-2">Plan Type</label>
                        <select name="plan_type" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                            <option value="monthly" <?php selected($edit_plan && $edit_plan->plan_type === 'monthly'); ?>>Monthly Plan (30 days)</option>
                            <option value="yearly" <?php selected($edit_plan && $edit_plan->plan_type === 'yearly'); ?>>Yearly Plan (365 days)</option>
                        </select>
                        <?php if ($edit_plan): ?>
                            <p class="text-xs text-gray-500 mt-2">Current plan: <?php echo ucfirst($edit_plan->plan_type); ?> (<?php echo date('M d, Y', strtotime($edit_plan->start_date)); ?> - <?php echo date('M d, Y', strtotime($edit_plan->end_date)); ?>)</p>
                        <?php endif; ?>
                    </div>
                    <input type="password" name="password" placeholder="New Password (Optional)" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                    <input type="password" name="confirm_password" placeholder="Confirm New Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                    <button type="submit" name="SimpleBill_edit_admin" class="w-full bg-blue-600 text-white p-3 rounded-lg font-bold">Update Admin</button>
                    <a href="?tab=dashboard" class="block text-center text-gray-500 text-sm hover:text-blue-500">Cancel Edit</a>
                </form>
            <?php else: ?>
                <form method="post" class="space-y-4" onsubmit="if(this.password.value !== this.confirm_password.value) { event.preventDefault(); customAlert('Passwords do not match!'); return false; }">
                    <input type="text" name="username" placeholder="Business or Shop Name" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                    <input type="email" name="email" placeholder="Email Address" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                    <select name="plan_type" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                        <option value="">Select Plan Type</option>
                        <option value="monthly">Monthly Plan (30 days)</option>
                        <option value="yearly">Yearly Plan (365 days)</option>
                    </select>
                    <input type="password" name="password" placeholder="Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                    <input type="password" name="confirm_password" placeholder="Confirm Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                    <button type="submit" name="SimpleBill_add_admin" class="w-full bg-blue-600 text-white p-3 rounded-lg font-bold">Create New Admin</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 shadow-sm overflow-hidden w-full">
            <div class="p-6 border-b dark:border-gray-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <h3 class="font-bold">Active System Admins</h3>
                <div class="relative w-full md:w-64">
                    <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="search-admins-dashboard" onkeyup="filterTable('search-admins-dashboard', 'admins-dashboard-table')" placeholder="Search admins..." class="w-full pl-9 pr-4 py-1.5 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                </div>
            </div>
            <div class="overflow-x-auto w-full users-table-wrap">
                <table class="w-full text-left min-w-[500px]" id="admins-dashboard-table">
                    <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500 whitespace-nowrap"><th class="px-6 py-3">Business / Admin User</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Active Plan</th><th class="px-6 py-3 text-right">Control</th></tr>
                    <?php 
                    if (isset($_GET['delete_admin'])) { 
                        require_once(ABSPATH.'wp-admin/includes/user.php');
                        $del_admin_user = get_userdata(intval($_GET['delete_admin']));
                        SimpleBill_log('DELETE ADMIN', 'Admin Account', 'Admin: ' . ($del_admin_user ? $del_admin_user->user_login : 'ID ' . intval($_GET['delete_admin'])));
                        wp_delete_user(intval($_GET['delete_admin'])); 
                        echo "<script>window.location.href='?tab=dashboard';</script>";
                    }
                    foreach(get_users(['role' => 'SimpleBill_admin']) as $u) {
                        $last_active = get_user_meta($u->ID, 'SimpleBill_last_active', true);
                        $is_disabled = get_user_meta($u->ID, 'SimpleBill_admin_disabled', true);
                        $admin_plan = SimpleBill_get_admin_active_plan($u->ID);

                        $is_online = $last_active && ($last_active > current_time('timestamp') - (15 * 60));
                        $status_html = '';
                        if ($is_online) {
                            $status_html = '<span class="flex items-center gap-1.5 text-[10px] text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-400 px-2 py-0.5 rounded-full border border-emerald-100 dark:border-emerald-800"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>Online</span>';
                        } else {
                            $time_diff = $last_active ? human_time_diff($last_active, current_time('timestamp')) . ' ago' : 'Never';
                            $status_html = '<span class="text-[10px] text-gray-400 font-normal">Last seen: ' . $time_diff . '</span>';
                        }

                        if ($is_disabled) {
                            $status_html .= ' <span class="flex items-center gap-1.5 text-[10px] text-red-600 font-bold bg-red-50 dark:bg-red-900/30 dark:text-red-400 px-2 py-0.5 rounded-full border border-red-100 dark:border-red-800">Disabled</span>';
                        }

                        // Plan display
                        $plan_html = '<span class="text-xs text-gray-400">No Plan</span>';
                        $data_plan_type = 'monthly';
                        $data_plan_start = 'N/A';
                        $data_plan_end = 'N/A';
                        if ($admin_plan) {
                            $plan_type = ucfirst($admin_plan->plan_type);
                            $start_date = date('M d, Y', strtotime($admin_plan->start_date));
                            $end_date = date('M d, Y', strtotime($admin_plan->end_date));
                            $plan_html = '<div class="text-xs space-y-1">
                                <span class="inline-block bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-1 rounded font-bold">'. $plan_type .'</span>
                                <div class="text-gray-500">' . $start_date . ' - ' . $end_date . '</div>
                            </div>';
                            $data_plan_type = $admin_plan->plan_type;
                            $data_plan_start = $start_date;
                            $data_plan_end = $end_date;
                        }

                        $toggle_class = $is_disabled ? 'text-gray-400 hover:text-green-500' : 'text-green-500 hover:text-gray-400';
                        $toggle_icon = $is_disabled ? 'fa-toggle-off' : 'fa-toggle-on';
                        $toggle_title = $is_disabled ? 'Turn On Admin' : 'Turn Off Admin';

                        echo "<tr class='border-b dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition'>
                            <td class='px-6 py-4 whitespace-nowrap ut-user'>
                                <div class='flex items-center gap-3'>
                                    <span class='font-bold'>{$u->user_login}</span>
                                    <div class='flex items-center gap-2'>{$status_html}</div>
                                </div>
                            </td>
                            <td class='px-6 py-4 whitespace-nowrap text-sm ut-email'>{$u->user_email}</td>
                            <td class='px-6 py-4 whitespace-nowrap text-sm ut-plan'>{$plan_html}</td>
                            <td class='px-6 py-4 text-right space-x-3 whitespace-nowrap ut-actions'>
                            <button type=\"button\" data-admin-id=\"{$u->ID}\" data-plan-type=\"{$data_plan_type}\" data-plan-start=\"{$data_plan_start}\" data-plan-end=\"{$data_plan_end}\" onclick=\"openPlanModal(this)\" class='bg-purple-50 text-purple-600 hover:bg-purple-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs' title='Manage Plan'><i class='fa-solid fa-calendar-days'></i> <span class='mobile-action-text'>Plan</span></button>
                            <a href='?tab=dashboard&toggle_admin={$u->ID}' class='{$toggle_class} text-lg align-middle' title='{$toggle_title}' onclick='customConfirmLink(event, \"Change access for this admin?\")'><i class='fa-solid {$toggle_icon}'></i></a>
                            <a href='?tab=dashboard&edit_admin={$u->ID}' class='bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs'><i class='fa-solid fa-pen-to-square'></i> <span class='mobile-action-text'>Edit</span></a>
                            <a href='?tab=dashboard&delete_admin={$u->ID}' class='bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs' onclick='customConfirmLink(event, \"WARNING: Are you sure you want to completely delete this admin account? This action cannot be undone.\")'><i class='fa-solid fa-trash'></i> <span class='mobile-action-text'>Delete</span></a>
                        </td></tr>"; 
                    } ?>
                </table>
            </div>
        </div>
    </div>

    <!-- DANGER ZONE -->
    <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border border-red-200 dark:border-red-900 shadow-sm mb-8">
        <h3 class="font-bold text-red-600 mb-4 flex items-center"><i class="fa-solid fa-triangle-exclamation mr-2"></i> Danger Zone: Factory Reset</h3>
        <p class="text-sm text-gray-500 mb-4">Warning: This action will completely erase all <strong>Invoices, Customers, and Products</strong> across the entire platform. Admin and User accounts will remain, but all their data will be permanently lost. This action cannot be undone.</p>
        <form method="post" class="flex flex-col sm:flex-row gap-4 items-center" onsubmit="event.preventDefault(); const f = this; openModal('Are you absolutely sure? This will permanently delete ALL data in the POS system across all stores.', false, () => f.submit());">
            <!-- HIDDEN INPUT ADDED TO ENSURE POST VALUE IS SENT -->
            <input type="hidden" name="SimpleBill_wipe_all_data" value="1">
            <input type="text" name="wipe_confirm" placeholder="Type DELETE to confirm" class="w-full sm:w-auto bg-gray-50 dark:bg-gray-800 border border-red-200 dark:border-red-800 p-3 rounded-lg outline-none text-red-600 focus:ring-2 focus:ring-red-500" required autocomplete="off">
            <button type="submit" class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white px-8 py-3 rounded-lg font-bold transition shadow whitespace-nowrap">
                <i class="fa-solid fa-trash-can mr-2"></i> Wipe All Data
            </button>
        </form>
    </div>
    <?php
}

function SimpleBill_render_admins_section() {
    if (!current_user_can('administrator')) return;

    if (isset($_GET['toggle_shop'])) {
        $toggle_id = intval($_GET['toggle_shop']);
        $current_status = get_user_meta($toggle_id, 'SimpleBill_admin_disabled', true);
        update_user_meta($toggle_id, 'SimpleBill_admin_disabled', !$current_status);
        $toggled_shop = get_userdata($toggle_id);
        SimpleBill_log($current_status ? 'ENABLE USER' : 'DISABLE USER', 'Shop Account', 'User: ' . ($toggled_shop ? $toggled_shop->user_login : 'ID ' . $toggle_id));
        $parent = get_user_meta($toggle_id, '_SimpleBill_parent_admin', true);
        echo "<script>window.location.href='?tab=admins&view_admin=" . intval($parent) . "';</script>";
        exit;
    }

    if (isset($_POST['SimpleBill_add_shop'])) {
        $user_id = wp_create_user($_POST['username'], $_POST['password'], $_POST['email']);
        if (!is_wp_error($user_id)) {
            (new WP_User($user_id))->set_role('SimpleBill_shop');
            update_user_meta($user_id, '_SimpleBill_parent_admin', intval($_POST['parent_admin']));
            SimpleBill_log('CREATE USER', 'Shop Account', 'New user: ' . sanitize_text_field($_POST['username']) . ' under admin ID: ' . intval($_POST['parent_admin']));
        }
    }
    if (isset($_POST['SimpleBill_edit_shop'])) {
        $user_id = intval($_POST['edit_shop_id']);
        $update_data = ['ID' => $user_id, 'user_email' => sanitize_email($_POST['email'])];
        if (!empty($_POST['password'])) { $update_data['user_pass'] = $_POST['password']; }
        wp_update_user($update_data);
        if(isset($_POST['parent_admin'])) { update_user_meta($user_id, '_SimpleBill_parent_admin', intval($_POST['parent_admin'])); }
        $edited_shop = get_userdata($user_id);
        SimpleBill_log('EDIT USER', 'Shop Account', 'User: ' . ($edited_shop ? $edited_shop->user_login : 'ID ' . $user_id));
        echo "<script>window.location.href='?tab=admins&view_admin=" . intval($_POST['parent_admin']) . "';</script>";
    }
    if (isset($_GET['delete_shop'])) { 
        require_once(ABSPATH.'wp-admin/includes/user.php');
        $parent = get_user_meta(intval($_GET['delete_shop']), '_SimpleBill_parent_admin', true);
        $del_shop = get_userdata(intval($_GET['delete_shop']));
        SimpleBill_log('DELETE USER', 'Shop Account', 'User: ' . ($del_shop ? $del_shop->user_login : 'ID ' . intval($_GET['delete_shop'])));
        wp_delete_user(intval($_GET['delete_shop'])); 
        echo "<script>window.location.href='?tab=admins&view_admin=" . intval($parent) . "';</script>";
    }

    if (isset($_GET['view_admin'])) {
        $admin_id = intval($_GET['view_admin']);
        $admin_user = get_userdata($admin_id);
        echo "<div class='mb-6'><a href='?tab=admins' class='bg-gray-200 hover:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 px-4 py-2 rounded-lg text-sm font-bold transition'><i class='fa-solid fa-arrow-left mr-2'></i> Back to Admins</a></div>";
        
        if ($admin_user) {
            // Summary of Users
            $shops = get_users(['role' => 'SimpleBill_shop', 'meta_key' => '_SimpleBill_parent_admin', 'meta_value' => $admin_id]);
            $total_shops = count($shops);
            $active_shops = 0;
            $threshold = current_time('timestamp') - (15 * 60);
            foreach ($shops as $s) {
                $last_act = get_user_meta($s->ID, 'SimpleBill_last_active', true);
                if ($last_act && $last_act > $threshold) {
                    $active_shops++;
                }
            }

            echo "<h3 class='text-xl font-bold mb-4'>Summary of Users</h3>
            <div class='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8'>
                <div class='bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-blue-500'>
                    <p class='text-xs text-gray-500 uppercase font-bold tracking-wider mb-2'>Total Users</p>
                    <p class='text-3xl font-bold'>{$total_shops}</p>
                </div>
                <div class='bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-emerald-500'>
                    <p class='text-xs text-gray-500 uppercase font-bold tracking-wider mb-2'>Active Now</p>
                    <p class='text-3xl font-bold text-emerald-600'>{$active_shops}</p>
                </div>
            </div>";

            // Shop Management Section
            $edit_shop_id = isset($_GET['edit_shop']) ? intval($_GET['edit_shop']) : 0;
            ?>
            <h3 class="text-xl font-bold mb-4">User Management</h3>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border dark:border-gray-800 shadow-sm h-fit">
                    <h3 class="font-bold mb-4 flex items-center"><i class="fa-solid fa-store mr-2 text-green-500"></i> <?php echo $edit_shop_id ? 'Edit User' : 'Register User'; ?></h3>
                    <?php if($edit_shop_id && $edit_shop = get_userdata($edit_shop_id)): ?>
                        <form method="post" class="space-y-4" onsubmit="if(this.password.value && this.password.value !== this.confirm_password.value) { event.preventDefault(); customAlert('Passwords do not match!'); return false; }">
                            <input type="hidden" name="edit_shop_id" value="<?php echo $edit_shop_id; ?>">
                            <input type="hidden" name="parent_admin" value="<?php echo $admin_id; ?>">
                            <input type="email" name="email" value="<?php echo esc_attr($edit_shop->user_email); ?>" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                            <input type="password" name="password" placeholder="New Password (Optional)" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                            <input type="password" name="confirm_password" placeholder="Confirm New Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                            <button type="submit" name="SimpleBill_edit_shop" class="w-full bg-green-600 text-white p-3 rounded-lg font-bold">Update User</button>
                            <a href="?tab=admins&view_admin=<?php echo $admin_id; ?>" class="block text-center text-gray-500 text-sm hover:text-blue-500">Cancel Edit</a>
                        </form>
                    <?php else: ?>
                        <form method="post" class="space-y-4" onsubmit="if(this.password.value !== this.confirm_password.value) { event.preventDefault(); customAlert('Passwords do not match!'); return false; }">
                            <input type="text" name="username" placeholder="Username" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                            <input type="email" name="email" placeholder="Email Address" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                            <input type="password" name="password" placeholder="Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                            <input type="password" name="confirm_password" placeholder="Confirm Password" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg" required>
                            <input type="hidden" name="parent_admin" value="<?php echo $admin_id; ?>">
                            <button type="submit" name="SimpleBill_add_shop" class="w-full bg-green-600 text-white p-3 rounded-lg font-bold">Create User</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 shadow-sm overflow-hidden w-full">
                    <div class="p-6 border-b dark:border-gray-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <h3 class="font-bold">Active System Users</h3>
                        <div class="relative w-full md:w-64">
                            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" id="search-terminals-table" onkeyup="filterTable('search-terminals-table', 'terminals-table')" placeholder="Search users..." class="w-full pl-9 pr-4 py-1.5 bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                        </div>
                    </div>
                    <div class="overflow-x-auto w-full users-table-wrap">
                        <table class="w-full text-left min-w-[500px]" id="terminals-table">
                            <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500 whitespace-nowrap"><th class="px-6 py-3">User</th><th class="px-6 py-3">Email</th><th class="px-6 py-3 text-right">Control</th></tr>
                            <?php 
                            foreach($shops as $u) {
                                $last_active = get_user_meta($u->ID, 'SimpleBill_last_active', true);
                                $is_disabled = get_user_meta($u->ID, 'SimpleBill_admin_disabled', true);
                                $is_online = $last_active && ($last_active > current_time('timestamp') - (15 * 60));
                                $status_html = '';
                                if ($is_online) {
                                    $status_html = '<span class="flex items-center gap-1.5 text-[10px] text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-400 px-2 py-0.5 rounded-full border border-emerald-100 dark:border-emerald-800"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>Online</span>';
                                } else {
                                    $time_diff = $last_active ? human_time_diff($last_active, current_time('timestamp')) . ' ago' : 'Never';
                                    $status_html = '<span class="text-[10px] text-gray-400 font-normal">Last seen: ' . $time_diff . '</span>';
                                }

                                if ($is_disabled) {
                                    $status_html .= ' <span class="flex items-center gap-1.5 text-[10px] text-red-600 font-bold bg-red-50 dark:bg-red-900/30 dark:text-red-400 px-2 py-0.5 rounded-full border border-red-100 dark:border-red-800">Disabled</span>';
                                }

                                $toggle_class = $is_disabled ? 'text-gray-400 hover:text-green-500' : 'text-green-500 hover:text-gray-400';
                                $toggle_icon = $is_disabled ? 'fa-toggle-off' : 'fa-toggle-on';
                                $toggle_title = $is_disabled ? 'Turn On User' : 'Turn Off User';

                                echo "<tr class='border-b dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition'>
                                    <td class='px-6 py-4 whitespace-nowrap text-green-600 ut-user'>
                                        <div class='flex items-center gap-3'>
                                            <span class='font-bold'>{$u->user_login}</span>
                                            {$status_html}
                                        </div>
                                    </td>
                                    <td class='px-6 py-4 whitespace-nowrap text-sm ut-email'>{$u->user_email}</td>
                                    <td class='px-6 py-4 text-right space-x-3 whitespace-nowrap ut-actions'>
                                    <a href='?tab=admins&view_admin={$admin_id}&toggle_shop={$u->ID}' class='{$toggle_class} text-lg align-middle' title='{$toggle_title}' onclick='customConfirmLink(event, \"Change access for this user?\")'><i class='fa-solid {$toggle_icon}'></i></a>
                                    <a href='?tab=admins&view_admin={$admin_id}&edit_shop={$u->ID}' class='bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs'><i class='fa-solid fa-pen-to-square'></i> <span class='mobile-action-text'>Edit</span></a>
                                    <a href='?tab=admins&view_admin={$admin_id}&delete_shop={$u->ID}' class='bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs' onclick='customConfirmLink(event, \"WARNING: Are you sure you want to completely delete this user account? This action cannot be undone.\")'><i class='fa-solid fa-trash'></i> <span class='mobile-action-text'>Delete</span></a>
                                </td></tr>"; 
                            } ?>
                        </table>
                    </div>
                </div>
            </div>
            <?php

            $_GET['filter_admin'] = $admin_id;
            SimpleBill_render_history_table();
        }
        return;
    }

    global $wpdb;
    $admins = get_users(['role' => 'SimpleBill_admin']);
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    ?>
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold">Registered Admins (Businesses)</h2>
        <div class="relative w-full md:w-64">
            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" id="search-admins-grid" onkeyup="filterGrid('search-admins-grid', 'admins-grid-container')" placeholder="Search admins..." class="w-full pl-9 pr-4 py-2 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition text-sm shadow-sm">
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="admins-grid-container">
        <?php foreach($admins as $admin): 
            $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM {$wpdb->prefix}SimpleBill_invoices WHERE admin_id = %d AND is_deleted = 0", $admin->ID));
            $admin_plan = SimpleBill_get_admin_active_plan($admin->ID);
        ?>
            <div class="bg-white dark:bg-gray-900 p-6 rounded-xl border dark:border-gray-800 shadow-sm flex flex-col justify-between hover:border-blue-500 transition grid-item">
                <div class="mb-4">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold text-xl">
                            <?php echo strtoupper(substr($admin->user_login, 0, 1)); ?>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg leading-tight text-gray-900 dark:text-white"><?php echo esc_html($admin->user_login); ?></h3>
                            <p class="text-xs text-gray-500"><?php echo esc_html($admin->user_email); ?></p>
                        </div>
                    </div>
                    
                    <div class="border-t border-b dark:border-gray-800 py-3 mb-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Total Valid Sales:</span>
                            <span class="font-bold bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded text-sm"><?php echo $stats->count; ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Total Revenue:</span>
                            <span class="font-bold text-green-600"><?php echo esc_html($currency); ?> <?php echo number_format($stats->total ?: 0, 2); ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Active Plan:</span>
                            <?php if ($admin_plan): ?>
                                <span class="font-semibold text-blue-600"><?php echo ucfirst($admin_plan->plan_type); ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">No Plan</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($admin_plan): ?>
                        <div class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($admin_plan->start_date)); ?> - <?php echo date('M d, Y', strtotime($admin_plan->end_date)); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="space-y-3">
                    <a href="?tab=admins&view_admin=<?php echo $admin->ID; ?>" class="text-center w-full bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400 py-2.5 rounded-lg font-bold hover:bg-blue-100 dark:hover:bg-blue-900/40 transition">View Users & Sales History</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if(empty($admins)): ?>
            <div class="col-span-full p-8 text-center bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 text-gray-500">No admins have been created yet.</div>
        <?php endif; ?>
    </div>
    <?php
}

function SimpleBill_render_admin_dashboard_tab() {
    global $wpdb;
    $user_id = get_current_user_id();
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    $admin_plan = SimpleBill_get_admin_active_plan($user_id);
    
    $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM {$wpdb->prefix}SimpleBill_invoices WHERE admin_id = %d AND is_deleted = 0", $user_id));
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-blue-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-2">Total Invoices</p>
            <p class="text-3xl font-bold"><?php echo $stats->count; ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-green-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-2">Total Sales</p>
            <p class="text-3xl font-bold"><?php echo esc_html($currency); ?> <?php echo number_format($stats->total ?: 0, 2); ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-purple-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-2">Active Plan</p>
            <?php if ($admin_plan): ?>
                <div class="space-y-2">
                    <p class="text-lg font-bold text-purple-600"><?php echo ucfirst($admin_plan->plan_type); ?></p>
                    <p class="text-xs text-gray-500">Expires: <?php echo date('M d, Y', strtotime($admin_plan->end_date)); ?></p>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-400">No Active Plan</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 shadow-sm overflow-hidden w-full mb-8">
        <div class="p-6 border-b dark:border-gray-800 flex justify-between items-center"><h3 class="font-bold">Active System Users (Assigned to You)</h3></div>
        <div class="overflow-x-auto w-full users-table-wrap">
            <table class="w-full text-left min-w-[500px]">
                <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500 whitespace-nowrap"><th class="px-6 py-3">User</th><th class="px-6 py-3">Email</th></tr>
                <?php 
                $my_shops = get_users(['role' => 'SimpleBill_shop', 'meta_key' => '_SimpleBill_parent_admin', 'meta_value' => $user_id]);
                if(empty($my_shops)): ?>
                    <tr><td colspan="2" class="px-6 py-8 text-center text-gray-400 italic">No users assigned to your business yet.</td></tr>
                <?php endif;
                foreach($my_shops as $u) {
                    $last_active = get_user_meta($u->ID, 'SimpleBill_last_active', true);
                    $is_online = $last_active && ($last_active > current_time('timestamp') - (15 * 60));
                    $status_html = '';
                    if ($is_online) {
                        $status_html = '<span class="flex items-center gap-1.5 text-[10px] text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-400 px-2 py-0.5 rounded-full border border-emerald-100 dark:border-emerald-800"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>Online</span>';
                    } else {
                        $time_diff = $last_active ? human_time_diff($last_active, current_time('timestamp')) . ' ago' : 'Never';
                        $status_html = '<span class="text-[10px] text-gray-400 font-normal">Last seen: ' . $time_diff . '</span>';
                    }

                    echo "<tr class='border-b dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition'>
                        <td class='px-6 py-4 whitespace-nowrap text-green-600 ut-user'>
                            <div class='flex items-center gap-3'>
                                <span class='font-bold'>{$u->user_login}</span>
                                {$status_html}
                            </div>
                        </td>
                        <td class='px-6 py-4 whitespace-nowrap text-sm ut-email'>{$u->user_email}</td>
                    </tr>"; 
                } ?>
            </table>
        </div>
    </div>
    <?php
}

function SimpleBill_render_admin_settings_tab() {
    $current_admin_id = get_current_user_id();
    if (isset($_POST['SimpleBill_save_admin_settings'])) {
        $logo_url = sanitize_url($_POST['logo']);
        
        // Handle direct file upload to WordPress Media Library
        if (!empty($_FILES['logo_file']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            $uploaded_file = wp_handle_upload($_FILES['logo_file'], array('test_form' => false));
            if (isset($uploaded_file['url'])) {
                $logo_url = $uploaded_file['url'];
                
                // Attach to media library for future use
                $attachment = array(
                    'post_mime_type' => $uploaded_file['type'],
                    'post_title'     => sanitize_file_name($_FILES['logo_file']['name']),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );
                $attach_id = wp_insert_attachment($attachment, $uploaded_file['file']);
                if (!is_wp_error($attach_id)) {
                    $attach_data = wp_generate_attachment_metadata($attach_id, $uploaded_file['file']);
                    wp_update_attachment_metadata($attach_id, $attach_data);
                }
            } else {
                echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg mb-4">Error uploading logo: ' . esc_html($uploaded_file['error']) . '</div>';
            }
        }
        
        update_user_meta($current_admin_id, 'SimpleBill_business_name', sanitize_text_field($_POST['business_name']));
        update_user_meta($current_admin_id, 'SimpleBill_logo_url', $logo_url);
        update_user_meta($current_admin_id, 'SimpleBill_contact', sanitize_textarea_field($_POST['contact']));
        update_user_meta($current_admin_id, 'SimpleBill_address', sanitize_textarea_field($_POST['address']));
        update_user_meta($current_admin_id, 'SimpleBill_website', sanitize_text_field($_POST['website']));
        update_user_meta($current_admin_id, 'SimpleBill_receipt_size', sanitize_text_field($_POST['receipt_size']));
        update_user_meta($current_admin_id, 'SimpleBill_currency', sanitize_text_field($_POST['currency']));
        update_user_meta($current_admin_id, 'SimpleBill_settings_configured', 1);
        echo '<div class="bg-green-100 text-green-700 p-4 rounded-lg mb-4">Settings updated for your store.</div>';
    }
    $current_receipt_size = get_user_meta($current_admin_id, 'SimpleBill_receipt_size', true) ?: '80mm';
    $current_currency = get_user_meta($current_admin_id, 'SimpleBill_currency', true) ?: get_option('SimpleBill_currency', 'LKR');
    
    $currencies = [
        'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'JPY' => 'Japanese Yen', 'AUD' => 'Australian Dollar', 
        'CAD' => 'Canadian Dollar', 'CHF' => 'Swiss Franc', 'CNY' => 'Chinese Yuan', 'HKD' => 'Hong Kong Dollar', 'NZD' => 'New Zealand Dollar', 'INR' => 'Indian Rupee', 
        'LKR' => 'Sri Lankan Rupee', 'SGD' => 'Singapore Dollar', 'AED' => 'United Arab Emirates Dirham', 'MYR' => 'Malaysian Ringgit', 
        'ZAR' => 'South African Rand', 'THB' => 'Thai Baht', 'SAR' => 'Saudi Riyal', 'QAR' => 'Qatari Riyal', 'KWD' => 'Kuwaiti Dinar', 'BHD' => 'Bahraini Dinar', 
        'BND' => 'Brunei Dollar', 'OMR' => 'Omani Rial', 'PKR' => 'Pakistani Rupee', 'BDT' => 'Bangladeshi Taka', 'NPR' => 'Nepalese Rupee', 'MVR' => 'Maldivian Rufiyaa', 
        'RUB' => 'Russian Ruble', 'IDR' => 'Indonesian Rupiah', 'PHP' => 'Philippine Peso', 'VND' => 'Vietnamese Dong', 'KRW' => 'South Korean Won', 
        'TRY' => 'Turkish Lira', 'MXN' => 'Mexican Peso', 'BRL' => 'Brazilian Real', 'NGN' => 'Nigerian Naira', 
        'KES' => 'Kenyan Shilling', 'GHS' => 'Ghanaian Cedi', 'UGX' => 'Ugandan Shilling', 'TZS' => 'Tanzanian Shilling', 'EGP' => 'Egyptian Pound', 
        'DZD' => 'Algerian Dinar', 'MAD' => 'Moroccan Dirham', 'TND' => 'Tunisian Dinar'
    ];
    asort($currencies);
    ?>
    <div class="w-full bg-white dark:bg-gray-900 p-6 md:p-8 rounded-xl border dark:border-gray-800 shadow-sm">
        <h3 class="font-bold text-xl mb-6">Invoicing Customization</h3>
        <form method="post" class="space-y-6" enctype="multipart/form-data">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold mb-2">Display Business Name</label>
                    <input type="text" name="business_name" value="<?php echo esc_attr(get_user_meta($current_admin_id, 'SimpleBill_business_name', true)); ?>" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-2">Store Currency</label>
                    <select name="currency" id="store-currency" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                        <?php foreach($currencies as $code => $name): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($current_currency, $code); ?>><?php echo esc_html($code . ' - ' . $name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold mb-2">Store Logo</label>
                    <div class="flex items-center gap-4">
                        <?php $current_logo = get_user_meta($current_admin_id, 'SimpleBill_logo_url', true); ?>
                        <?php if($current_logo): ?>
                            <img src="<?php echo esc_url($current_logo); ?>" alt="Logo" class="h-16 w-auto object-contain bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 rounded-lg p-2">
                        <?php endif; ?>
                        <div class="flex-1">
                            <input type="file" name="logo_file" accept="image/*" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-2 rounded-lg text-sm mb-2 outline-none cursor-pointer">
                            <input type="text" name="logo" value="<?php echo esc_attr($current_logo); ?>" placeholder="Or enter existing logo URL..." class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-2 rounded-lg text-sm outline-none">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-2">Default Receipt Format</label>
                    <select name="receipt_size" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                        <option value="80mm" <?php selected($current_receipt_size, '80mm'); ?>>Thermal Printer (80mm)</option>
                        <option value="58mm" <?php selected($current_receipt_size, '58mm'); ?>>Thermal Printer (58mm)</option>
                        <option value="A4" <?php selected($current_receipt_size, 'A4'); ?>>Standard A4 (21x29.7cm)</option>
                        <option value="A5" <?php selected($current_receipt_size, 'A5'); ?>>Standard A5 (14.8x21cm)</option>
                        <option value="Letter" <?php selected($current_receipt_size, 'Letter'); ?>>Letter (8.5x11in)</option>
                        <option value="Legal" <?php selected($current_receipt_size, 'Legal'); ?>>Legal (8.5x14in)</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold mb-2">Website</label>
                    <input type="text" name="website" value="<?php echo esc_attr(get_user_meta($current_admin_id, 'SimpleBill_website', true)); ?>" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-bold mb-2">Contact Details</label>
                    <textarea name="contact" rows="4" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg"><?php echo esc_textarea(get_user_meta($current_admin_id, 'SimpleBill_contact', true)); ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-2">Address</label>
                    <textarea name="address" rows="4" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-3 rounded-lg"><?php echo esc_textarea(get_user_meta($current_admin_id, 'SimpleBill_address', true)); ?></textarea>
                </div>
            </div>
            <button type="submit" name="SimpleBill_save_admin_settings" class="w-full sm:w-auto bg-blue-600 text-white px-8 py-3 rounded-lg font-bold hover:bg-blue-700 transition shadow-lg">Save User Settings</button>
        </form>
    </div>
    <?php
}

function SimpleBill_render_shop_dashboard_tab() {
    global $wpdb;
    $user_id = get_current_user_id();
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    
    $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM {$wpdb->prefix}SimpleBill_invoices WHERE user_id = %d AND is_deleted = 0", $user_id));
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-blue-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-2">Active Sales</p>
            <p class="text-3xl font-bold"><?php echo $stats->count; ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-6 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-green-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-2">Total Revenue</p>
            <p class="text-3xl font-bold"><?php echo esc_html($currency); ?> <?php echo number_format($stats->total ?: 0, 2); ?></p>
        </div>
    </div>
    <?php
}

function SimpleBill_render_shop_pos_form() {
    global $wpdb;
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    $user_id = get_current_user_id();
    $admin_id = current_user_can('SimpleBill_admin_cap') ? $user_id : (get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0);
    
    // Check if Edit Mode is Active
    $edit_invoice_id = isset($_GET['edit_invoice_id']) ? intval($_GET['edit_invoice_id']) : 0;
    $edit_inv = null;
    if ($edit_invoice_id > 0) {
        $edit_inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}SimpleBill_invoices WHERE id = %d AND is_deleted = 0", $edit_invoice_id));
        if ($edit_inv && !current_user_can('administrator')) {
            if (current_user_can('SimpleBill_admin_cap') && $edit_inv->admin_id != $user_id) $edit_inv = null;
            if (current_user_can('SimpleBill_shop_cap') && $edit_inv->user_id != $user_id) $edit_inv = null;
        }
    }

    // Fetch Customers (Restricted per admin)
    $customers = $wpdb->get_results($wpdb->prepare("SELECT MAX(sr_no) as sr_no, name, phone, MAX(address) as address FROM {$wpdb->prefix}SimpleBill_customers WHERE is_deleted = 0 AND admin_id = %d GROUP BY name, phone ORDER BY name ASC", $admin_id));
    
    // Fetch Products
    $products = $wpdb->get_results($wpdb->prepare("SELECT hsn_code, name, unit, price FROM {$wpdb->prefix}SimpleBill_products WHERE is_deleted = 0 AND admin_id = %d ORDER BY name ASC", $admin_id));
    
    // Fetch Units
    $distinct_units = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT unit FROM {$wpdb->prefix}SimpleBill_products WHERE is_deleted = 0 AND admin_id = %d AND unit != '' ORDER BY unit ASC", $admin_id));
    ?>
    <div class="w-full bg-white dark:bg-gray-900 p-5 md:p-8 rounded-xl shadow-lg border dark:border-gray-800">
        <h2 class="text-2xl font-bold mb-6 flex items-center"><i class="fa-solid fa-cart-plus mr-3 text-blue-500"></i> <?php echo $edit_inv ? 'Edit Sale' : 'New Sale'; ?></h2>
        <form id="pos-form" onsubmit="submitInvoice(event)">
            
            <input type="hidden" id="edit_invoice_id" value="<?php echo $edit_inv ? esc_attr($edit_inv->id) : ''; ?>">

            <datalist id="pos-unit-list">
                <?php foreach($distinct_units as $u): ?>
                    <option value="<?php echo esc_attr($u); ?>">
                <?php endforeach; ?>
            </datalist>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="md:col-span-2">
                    <select id="cust_name" placeholder="Select or type Customer Name (Auto-fill available)" class="w-full" required>
                        <option value=""></option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?php echo esc_attr($c->name); ?>" data-phone="<?php echo esc_attr($c->phone); ?>" data-address="<?php echo esc_attr($c->address); ?>"><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="text" id="cust_phone" placeholder="Phone Number" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-4 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none md:col-span-1 text-lg">
                <input type="text" id="cust_address" placeholder="Address" class="w-full bg-gray-50 dark:bg-gray-800 border dark:border-gray-700 p-4 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none md:col-span-1 text-lg">
            </div>
            
            <div class="overflow-x-auto w-full mb-6">
                <table class="w-full min-w-[750px]">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-400 whitespace-nowrap">
                            <th class="pb-3 px-2">Description</th>
                            <th class="pb-3 w-20">Unit</th>
                            <th class="pb-3 w-20">Qty</th>
                            <th class="pb-3 w-24">Price</th>
                            <th class="pb-3 w-24">Disc.</th>
                            <th class="pb-3 w-28">Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="items-body"></tbody>
                </table>
            </div>

            <div class="flex flex-col sm:flex-row justify-between items-center mb-6 bg-gray-50 dark:bg-gray-800/50 p-4 md:p-6 rounded-xl gap-3">
                <button type="button" onclick="addItemRow()" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white border border-blue-600 dark:border-blue-500 px-6 py-3 rounded-lg font-bold hover:shadow-sm transition text-sm">+ Add Line Item</button>
                <div class="flex flex-col items-end gap-2 w-full sm:w-auto">
                    <div class="flex items-center gap-3 w-full justify-end">
                        <label class="font-bold text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">Net Total:</label>
                        <div class="text-xl md:text-2xl font-black text-blue-600 dark:text-blue-400 whitespace-nowrap"><?php echo esc_html($currency); ?> <span id="grand-total">0.00</span></div>
                    </div>
                    <div class="flex items-center gap-3 w-full justify-end mt-1">
                        <label class="font-bold text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">Payment:</label>
                        <input type="number" id="payment_received" step="0.01" min="0" class="w-32 bg-white dark:bg-gray-900 border dark:border-gray-700 p-2 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-right font-bold text-lg" oninput="calculateBalance()">
                    </div>
                    <div class="flex items-center gap-3 w-full justify-end mt-1">
                        <label class="font-bold text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">Balance/Change:</label>
                        <div id="payment_balance_wrapper" class="text-xl md:text-2xl font-black text-green-600 dark:text-green-400 whitespace-nowrap"><?php echo esc_html($currency); ?> <span id="payment_balance">0.00</span></div>
                    </div>
                </div>
            </div>

            <!-- Bulk Import / Export Toggle Button -->
            <div class="mb-4 hidden-print">
                <button type="button" onclick="toggleBulkSection()" class="flex items-center gap-2 bg-indigo-100 dark:bg-indigo-900/30 hover:bg-indigo-200 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 px-4 py-2 rounded-lg font-bold transition text-sm">
                    <i id="bulk-toggle-icon" class="fa-solid fa-chevron-down"></i>
                    <span id="bulk-toggle-text">Show Bulk Options</span>
                </button>
            </div>

            <!-- Bulk Import / Export Bar (Hidden by default) -->
            <div id="bulk-products-section" class="hidden flex flex-wrap items-center gap-2 mb-8 p-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl hidden-print">
                <span class="text-xs font-black uppercase text-indigo-500 tracking-wider mr-1"><i class="fa-solid fa-table mr-1"></i> Bulk Items:</span>
                <!-- Export button -->
                <button type="button" onclick="exportItemsCSV()" class="flex items-center gap-1.5 bg-white dark:bg-gray-800 border dark:border-gray-700 hover:border-green-400 hover:text-green-600 dark:hover:text-green-400 px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm">
                    <i class="fa-solid fa-file-arrow-down text-green-500"></i> Export CSV
                </button>
                <!-- Import trigger -->
                <label class="flex items-center gap-1.5 bg-white dark:bg-gray-800 border dark:border-gray-700 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400 px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm cursor-pointer">
                    <i class="fa-solid fa-file-arrow-up text-blue-500"></i> Import CSV / Excel
                    <input type="file" id="bulk-import-file" accept=".csv,.xls,.xlsx" class="hidden" onchange="importItemsFile(this)">
                </label>
                <!-- Download template -->
                <a href="data:text/csv;charset=utf-8,Description,Unit,Qty,Price,Disc%0AItem%20Example,PCS,1,100.00,0" download="pos_items_template.csv" class="flex items-center gap-1.5 bg-white dark:bg-gray-800 border dark:border-gray-700 hover:border-gray-400 px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                    <i class="fa-solid fa-download text-gray-400"></i> Template
                </a>
                <span class="text-[10px] text-gray-400 ml-1">Columns: <strong>Description, Unit, Qty, Price, Disc</strong></span>
            </div>

            <button type="submit" id="submit-btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-5 rounded-2xl shadow-xl transition transform active:scale-95 text-lg disabled:opacity-50">Process & Print Invoice</button>
        </form>
    </div>
    <script>
        const currency = "<?php echo esc_js($currency); ?>";
        const posProducts = <?php echo json_encode($products); ?>;
        const editInvoiceData = <?php echo $edit_inv ? json_encode([
            'customer_name' => $edit_inv->customer_name,
            'customer_phone' => $edit_inv->customer_phone,
            'customer_address' => $edit_inv->customer_address,
            'payment_amount' => $edit_inv->payment_amount,
            'balance_amount' => $edit_inv->balance_amount,
            'items' => json_decode($edit_inv->items, true)
        ]) : 'null'; ?>;

        // Initialize Customer Auto-fill with TomSelect
        const custTomSelect = new TomSelect("#cust_name", {
            create: true,
            sortField: { field: "text", direction: "asc" },
            dropdownParent: "body",
            onChange: function(val) {
                if(!val) return;
                const opt = Array.from(this.input.options).find(o => o.value === val);
                if(opt) {
                    document.getElementById('cust_phone').value = opt.getAttribute('data-phone') || '';
                    document.getElementById('cust_address').value = opt.getAttribute('data-address') || '';
                }
            }
        });

        function addItemRow(presetData = null) {
            const row = document.createElement('tr');
            row.className = 'item-row border-b dark:border-gray-800';
            
            let selectOptions = `<option value=""></option>`;
            posProducts.forEach(p => {
                let label = p.hsn_code ? p.hsn_code + ' - ' + p.name : p.name;
                let nameEscaped = p.name.replace(/"/g, '&quot;');
                selectOptions += `<option value="${nameEscaped}" data-price="${p.price}" data-unit="${p.unit || ''}">${label}</option>`;
            });

            row.innerHTML = `
                <td class="py-3 pr-2" data-label="Item">
                    <select class="item-desc w-full bg-transparent border-none p-2 focus:ring-1 focus:ring-blue-500 rounded min-w-[150px] text-lg" required placeholder="Search SKU or Item name...">
                        ${selectOptions}
                    </select>
                </td>
                <td class="py-3 pr-2" data-label="Unit"><input type="text" class="item-unit w-full bg-gray-50 dark:bg-gray-800 border-none p-2 focus:ring-1 focus:ring-blue-500 rounded text-sm text-gray-500 uppercase font-bold" list="pos-unit-list" placeholder="PCS" autocomplete="off"></td>
                <td class="py-3 pr-2" data-label="Qty"><input type="number" step="any" min="0" class="item-qty w-full bg-gray-50 dark:bg-gray-800 border-none p-2 focus:ring-1 focus:ring-blue-500 rounded text-lg font-bold" value="1" oninput="calc(this)"></td>
                <td class="py-3 pr-2" data-label="Price"><input type="number" step="0.01" min="0" class="item-price w-full bg-gray-50 dark:bg-gray-800 border-none p-2 focus:ring-1 focus:ring-blue-500 rounded text-lg font-bold text-blue-600 dark:text-blue-400" value="0" oninput="calc(this)"></td>
                <td class="py-3 pr-2" data-label="Disc."><input type="text" class="item-disc w-full bg-gray-50 dark:bg-gray-800 border-none p-2 focus:ring-1 focus:ring-blue-500 rounded text-lg font-bold text-green-600 dark:text-green-400" value="0" placeholder="e.g. 10 or 10%" oninput="calc(this)"></td>
                <td class="py-3 font-bold whitespace-nowrap text-xl text-blue-600 dark:text-blue-400" data-label="Total"><span class="item-total">0.00</span></td>
                <td class="text-right whitespace-nowrap"><button type="button" onclick="this.closest('tr').remove();updateGrand();" class="text-red-400 hover:text-red-600 transition text-2xl px-2"><i class="fa-solid fa-circle-xmark"></i></button></td>
            `;
            document.getElementById('items-body').appendChild(row);

            if(presetData) {
                let optEscaped = presetData.desc.replace(/"/g, '&quot;');
                row.querySelector('.item-desc').innerHTML += `<option value="${optEscaped}" selected>${presetData.desc}</option>`;
                row.querySelector('.item-price').value = presetData.price;
                row.querySelector('.item-qty').value = presetData.qty;
                row.querySelector('.item-unit').value = presetData.unit || '';
                row.querySelector('.item-disc').value = presetData.discount || 0;
            }

            // Initialize TomSelect for the dynamic Product search dropdown
            new TomSelect(row.querySelector('.item-desc'), {
                create: true,
                sortField: { field: "text", direction: "asc" },
                dropdownParent: "body",
                onChange: function(val) {
                    if(!val) return;
                    const opt = Array.from(this.input.options).find(o => o.value === val);
                    if(opt && opt.hasAttribute('data-price')) {
                        row.querySelector('.item-price').value = opt.getAttribute('data-price');
                        row.querySelector('.item-unit').value = opt.getAttribute('data-unit') || '';
                        calc(row.querySelector('.item-price'));
                    }
                }
            });

            if(presetData) {
                calc(row.querySelector('.item-price'));
            }
        }

        function calc(el) {
            const row = el.closest('tr');
            const price = Math.max(0, parseFloat(row.querySelector('.item-price').value) || 0);
            const qty = Math.max(0, parseFloat(row.querySelector('.item-qty').value) || 0);
            
            let discRaw = row.querySelector('.item-disc').value.trim();
            let disc = 0;
            if (discRaw.endsWith('%')) {
                let pct = parseFloat(discRaw) || 0;
                disc = (price * qty) * (pct / 100);
            } else {
                let unitDisc = parseFloat(discRaw) || 0;
                disc = unitDisc * qty;
            }
            disc = Math.max(0, disc);
            
            const total = Math.max(0, (price * qty) - disc);
            row.querySelector('.item-total').innerText = total.toFixed(2);
            updateGrand();
        }

        function updateGrand() {
            let total = 0; document.querySelectorAll('.item-total').forEach(s => total += parseFloat(s.innerText) || 0);
            document.getElementById('grand-total').innerText = total.toFixed(2);
            calculateBalance();
        }

        function calculateBalance() {
            const total = parseFloat(document.getElementById('grand-total').innerText) || 0;
            const payment = parseFloat(document.getElementById('payment_received').value) || 0;
            const balance = payment - total;
            document.getElementById('payment_balance').innerText = balance.toFixed(2);
            
            const wrapper = document.getElementById('payment_balance_wrapper');
            if (balance < 0 && payment > 0) {
                wrapper.classList.remove('text-green-600', 'dark:text-green-400');
                wrapper.classList.add('text-red-500', 'dark:text-red-400');
            } else {
                wrapper.classList.remove('text-red-500', 'dark:text-red-400');
                wrapper.classList.add('text-green-600', 'dark:text-green-400');
            }
        }

        function submitInvoice(e) {
            e.preventDefault(); 
            const items = [];
            document.querySelectorAll('.item-row').forEach(row => { 
                const desc = row.querySelector('.item-desc').value.trim();
                const unit = row.querySelector('.item-unit').value.trim();
                const price = Math.max(0, parseFloat(row.querySelector('.item-price').value) || 0);
                const qty = Math.max(0, parseFloat(row.querySelector('.item-qty').value) || 0);
                
                let discRaw = row.querySelector('.item-disc').value.trim();
                let discount = 0;
                if (discRaw.endsWith('%')) {
                    let pct = parseFloat(discRaw) || 0;
                    discount = (price * qty) * (pct / 100);
                } else {
                    let unitDisc = parseFloat(discRaw) || 0;
                    discount = unitDisc * qty;
                }
                discount = Math.max(0, discount);
                
                if(desc) items.push({ desc, unit, price, qty, discount }); 
            });
            if(items.length === 0) { customAlert('Please add an item'); return; }

            document.getElementById('submit-btn').disabled = true;

            const data = new FormData();
            data.append('action', 'SimpleBill_create_invoice'); 
            data.append('edit_invoice_id', document.getElementById('edit_invoice_id').value); 
            data.append('customer_sr_no', ''); // Sr No field removed entirely from POS
            data.append('customer_name', document.getElementById('cust_name').value);
            data.append('customer_phone', document.getElementById('cust_phone').value); 
            data.append('customer_address', document.getElementById('cust_address').value); 
            data.append('payment_amount', document.getElementById('payment_received').value || 0);
            data.append('balance_amount', document.getElementById('payment_balance').innerText || 0);
            data.append('items_json', JSON.stringify(items));
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: data }).then(res => res.json()).then(res => { 
                if(res.success) {
                    window.open('?SimpleBill_print_invoice=' + res.data.invoice_id, '_blank'); 
                    window.location.href='?tab=pos'; 
                } else {
                    customAlert('Error creating/updating invoice: ' + (res.data || 'Unknown error'));
                    document.getElementById('submit-btn').disabled = false;
                }
            });
        }
        
        // Auto-fill values if we are editing an invoice
        if (editInvoiceData) {
            document.getElementById('cust_phone').value = editInvoiceData.customer_phone;
            document.getElementById('cust_address').value = editInvoiceData.customer_address;
            document.getElementById('payment_received').value = editInvoiceData.payment_amount;
            setTimeout(() => {
                custTomSelect.addOption({value: editInvoiceData.customer_name, text: editInvoiceData.customer_name});
                custTomSelect.setValue(editInvoiceData.customer_name);
            }, 50);

            if (editInvoiceData.items && editInvoiceData.items.length > 0) {
                editInvoiceData.items.forEach(item => addItemRow(item));
            } else {
                addItemRow();
            }
            document.getElementById('submit-btn').innerText = "Update & Print Invoice";
        } else {
            addItemRow();
        }

        // ─── TOGGLE BULK SECTION ───────────────────────────────────────────────
        function toggleBulkSection() {
            const section = document.getElementById('bulk-products-section');
            const icon = document.getElementById('bulk-toggle-icon');
            const text = document.getElementById('bulk-toggle-text');
            
            if (section.classList.contains('hidden')) {
                section.classList.remove('hidden');
                icon.className = 'fa-solid fa-chevron-up';
                text.textContent = 'Hide Bulk Options';
            } else {
                section.classList.add('hidden');
                icon.className = 'fa-solid fa-chevron-down';
                text.textContent = 'Show Bulk Options';
            }
        }

        // ─── BULK EXPORT ───────────────────────────────────────────────
        function exportItemsCSV() {
            const rows = document.querySelectorAll('#items-body .item-row');
            if (!rows.length) { customAlert('No items to export.'); return; }

            const lines = ['Description,Unit,Qty,Price,Disc'];
            rows.forEach(row => {
                const desc  = (row.querySelector('.item-desc')?.tomselect?.getValue()  || row.querySelector('.item-desc')?.value  || '').replace(/"/g,'""');
                const unit  = (row.querySelector('.item-unit')?.value  || '').replace(/"/g,'""');
                const qty   = row.querySelector('.item-qty')?.value   || '1';
                const price = row.querySelector('.item-price')?.value || '0';
                const disc  = row.querySelector('.item-disc')?.value  || '0';
                lines.push(`"${desc}","${unit}","${qty}","${price}","${disc}"`);
            });

            const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'pos_items.csv';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 2000);
        }

        // ─── BULK IMPORT ───────────────────────────────────────────────
        function importItemsFile(input) {
            const file = input.files[0];
            if (!file) return;
            input.value = '';

            const ext = file.name.split('.').pop().toLowerCase();

            if (ext === 'csv') {
                const reader = new FileReader();
                reader.onload = e => _processBulkCSV(e.target.result);
                reader.readAsText(file);
            } else if (ext === 'xls' || ext === 'xlsx') {
                function _runXLS(wb) {
                    const ws   = wb.Sheets[wb.SheetNames[0]];
                    const data = XLSX.utils.sheet_to_json(ws, { header:1, defval:'' });
                    _loadBulkRows(data, true);
                }
                if (typeof XLSX !== 'undefined') {
                    const reader = new FileReader();
                    reader.onload = e => _runXLS(XLSX.read(new Uint8Array(e.target.result), { type:'array' }));
                    reader.readAsArrayBuffer(file);
                } else {
                    const s = document.createElement('script');
                    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
                    s.onload = () => {
                        const reader = new FileReader();
                        reader.onload = e => _runXLS(XLSX.read(new Uint8Array(e.target.result), { type:'array' }));
                        reader.readAsArrayBuffer(file);
                    };
                    s.onerror = () => customAlert('Failed to load Excel parser. Please use CSV format.');
                    document.head.appendChild(s);
                }
            } else {
                customAlert('Unsupported file type. Please use .csv, .xls, or .xlsx');
            }
        }

        function _processBulkCSV(text) {
            const rows = [];
            let cur = '', inQ = false, currentRow = [];
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (ch === '"') {
                    if (inQ && text[i+1] === '"') { cur += '"'; i++; }
                    else inQ = !inQ;
                } else if (ch === ',' && !inQ) {
                    currentRow.push(cur); cur = '';
                } else if ((ch === '\n' || ch === '\r') && !inQ) {
                    currentRow.push(cur); cur = '';
                    rows.push(currentRow); currentRow = [];
                    if (ch === '\r' && text[i+1] === '\n') i++;
                } else {
                    cur += ch;
                }
            }
            if (cur !== '' || currentRow.length > 0) {
                currentRow.push(cur);
                rows.push(currentRow);
            }
            while (rows.length && rows[rows.length-1].join('').trim() === '') rows.pop();
            _loadBulkRows(rows, true);
        }

        function _loadBulkRows(rows, hasHeader) {
            const start = hasHeader ? 1 : 0;
            let added = 0;
            document.querySelectorAll('#items-body .item-row').forEach(r => r.remove());
            updateGrand();
            for (let i = start; i < rows.length; i++) {
                const row = rows[i];
                const desc  = (row[0] || '').toString().trim();
                const unit  = (row[1] || '').toString().trim();
                const qty   = parseFloat((row[2] || '1').toString().replace(/,/g,'')) || 1;
                const price = parseFloat((row[3] || '0').toString().replace(/,/g,'')) || 0;
                const disc  = (row[4] || '0').toString().trim();
                if (!desc) continue;
                addItemRow({ desc, unit, qty, price, discount: disc });
                added++;
            }
            if (added === 0) {
                customAlert('No valid rows found in the file. Make sure columns are: Description, Unit, Qty, Price, Disc');
            } else {
                customAlert(added + ' item(s) loaded successfully from file.');
            }
        }
    </script>
    <?php
}

function SimpleBill_render_history_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_invoices';
    $user_id = get_current_user_id();
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    
    $where = "is_deleted = 0";
    if (current_user_can('administrator')) {
        $admin_id_filter = isset($_GET['filter_admin']) ? intval($_GET['filter_admin']) : 0;
        if ($admin_id_filter > 0) {
            $where .= $wpdb->prepare(" AND admin_id = %d", $admin_id_filter);
            $admin_user = get_userdata($admin_id_filter);
            $title = "Sales History: " . ($admin_user ? esc_html($admin_user->user_login) : "Selected Admin");
        } else {
            $title = "Platform Active Sales History";
        }
    } elseif (current_user_can('SimpleBill_admin_cap')) {
        $where .= $wpdb->prepare(" AND admin_id = %d", $user_id);
        if (!empty($_GET['filter_shop'])) {
            $where .= $wpdb->prepare(" AND user_id = %d", intval($_GET['filter_shop']));
            $title = "Sales History for selected Shop";
        } else {
            $title = "Sales History";
        }
    } else {
        $where .= $wpdb->prepare(" AND user_id = %d", $user_id);
        $title = "My Recent Sales";
    }
    
    $search_query = isset($_GET['search_invoice']) ? sanitize_text_field($_GET['search_invoice']) : '';
    $filter_date = isset($_GET['filter_date']) ? sanitize_text_field($_GET['filter_date']) : '';

    if (!empty($search_query)) {
        $like_query = '%' . $wpdb->esc_like($search_query) . '%';
        if (is_numeric($search_query)) {
            $where .= $wpdb->prepare(" AND (customer_name LIKE %s OR customer_phone LIKE %s OR customer_sr_no LIKE %s OR id = %d)", $like_query, $like_query, $like_query, intval($search_query));
        } else {
            $where .= $wpdb->prepare(" AND (customer_name LIKE %s OR customer_phone LIKE %s OR customer_sr_no LIKE %s)", $like_query, $like_query, $like_query);
        }
    }

    if (!empty($filter_date)) {
        $where .= $wpdb->prepare(" AND DATE(created_at) = %s", $filter_date);
    }
    
    $invoices = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 100");
    
    $clear_url = "?clear_all_sales=1";
    if(current_user_can('administrator') && isset($_GET['filter_admin'])) {
        $clear_url .= "&filter_admin=" . intval($_GET['filter_admin']);
    }
    ?>
    <div class="bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 shadow-sm overflow-hidden mb-8">
        <div class="p-6 border-b dark:border-gray-800 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <h3 class="font-bold whitespace-nowrap"><?php echo esc_html($title); ?></h3>
            <form method="GET" class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto items-stretch sm:items-center hidden-print">
                <?php if(isset($_GET['tab'])): ?>
                    <input type="hidden" name="tab" value="<?php echo esc_attr($_GET['tab']); ?>">
                <?php endif; ?>
                <?php if (current_user_can('administrator') && isset($_GET['filter_admin'])): ?>
                    <input type="hidden" name="view_admin" value="<?php echo esc_attr($_GET['filter_admin'] ?? 0); ?>">
                    <input type="hidden" name="filter_admin" value="<?php echo esc_attr($_GET['filter_admin'] ?? 0); ?>">
                <?php endif; ?>
                
                <input type="text" name="search_invoice" value="<?php echo esc_attr($search_query); ?>" placeholder="Search..." class="border dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg text-sm outline-none w-full sm:w-auto min-w-[120px]">
                <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>" class="border dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg text-sm outline-none w-full sm:w-auto">
                
                <?php if (current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')): ?>
                    <select name="filter_shop" onchange="this.form.submit()" class="border dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg text-sm outline-none w-full sm:w-auto">
                        <option value="">All My Shops</option>
                        <?php $my_shops = get_users(['role' => 'SimpleBill_shop', 'meta_key' => '_SimpleBill_parent_admin', 'meta_value' => $user_id]); ?>
                        <?php foreach($my_shops as $s): ?>
                            <option value="<?php echo $s->ID; ?>" <?php selected(isset($_GET['filter_shop']) ? $_GET['filter_shop'] : '', $s->ID); ?>><?php echo esc_html($s->user_login); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                
                <div class="flex gap-2 w-full sm:w-auto">
                    <button type="submit" class="flex-1 sm:flex-none bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Filter</button>
                    <button type="button" onclick="window.print()" class="flex-1 sm:flex-none bg-gray-100 dark:bg-gray-800 px-4 py-2 rounded-lg text-sm font-bold whitespace-nowrap"><i class="fa-solid fa-print mr-1"></i> Print</button>
                </div>
            </form>
        </div>
        
        <?php if(current_user_can('administrator') || current_user_can('SimpleBill_admin_cap')): ?>
        <div class="px-6 py-3 bg-gray-50 dark:bg-gray-800/50 border-b dark:border-gray-800 flex justify-end hidden-print">
            <a href="<?php echo esc_url($clear_url); ?>" onclick="customConfirmLink(event, 'WARNING: Are you sure you want to clear/delete all these sales records? This action cannot be undone.')" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition hover:bg-red-700 flex items-center shadow-sm"><i class="fa-solid fa-trash-can mr-2"></i> Delete All Invoices</a>
        </div>
        <?php endif; ?>

        <div class="overflow-x-auto w-full history-table-wrap">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs text-gray-500 uppercase font-bold whitespace-nowrap">
                    <tr><th class="px-6 py-4">Date</th><th class="px-6 py-4">Bill #</th><th class="px-6 py-4">Customer</th><th class="px-6 py-4">Amount</th><th class="px-6 py-4 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-800">
                    <?php if(empty($invoices)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400 italic">No transactions found.</td></tr>
                    <?php endif; ?>
                    <?php foreach($invoices as $inv): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                        <td class="px-6 py-4 text-xs text-gray-500 whitespace-nowrap hist-date"><?php echo date('M d, H:i', strtotime($inv->created_at)); ?></td>
                        <td class="px-6 py-4 font-mono text-xs whitespace-nowrap hist-bill"><?php echo esc_html(SimpleBill_get_invoice_number($inv->id, $inv->user_id, $inv->admin_id)); ?></td>
                        <td class="px-6 py-4 font-bold whitespace-nowrap hist-customer"><?php echo esc_html($inv->customer_name); ?></td>
                        <td class="px-6 py-4 font-black whitespace-nowrap hist-amount"><?php echo esc_html($currency); ?> <?php echo number_format($inv->total_amount, 2); ?></td>
                        <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap hist-actions">
                            <a href="?tab=pos&edit_invoice_id=<?php echo $inv->id; ?>" class="text-green-500 hover:bg-green-50 dark:hover:bg-green-900/30 p-2 rounded-lg transition" title="Edit Invoice"><i class="fa-solid fa-pen-to-square"></i></a>
                            <a href="?SimpleBill_print_invoice=<?php echo $inv->id; ?>" target="_blank" class="text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-2 rounded-lg transition"><i class="fa-solid fa-eye"></i></a>
                            <?php if(current_user_can('SimpleBill_admin_cap') || current_user_can('administrator')): ?>
                            <?php 
                            $del_url_single = "?del_inv=" . $inv->id;
                            if(current_user_can('administrator') && isset($_GET['filter_admin'])) {
                                $del_url_single .= "&tab=admins&view_admin=" . intval($_GET['filter_admin']);
                            }
                            ?>
                            <a href="<?php echo esc_url($del_url_single); ?>" onclick="customConfirmLink(event, 'WARNING: Are you sure you want to delete this invoice?')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition p-2 rounded-lg ml-1"><i class="fa-solid fa-trash-can"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function SimpleBill_render_deleted_history_table() {
    if(!current_user_can('administrator')) return;
    
    global $wpdb; $table = $wpdb->prefix . 'SimpleBill_invoices'; $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    
    $where = "is_deleted = 1";
    $search_del = isset($_GET['search_del']) ? sanitize_text_field($_GET['search_del']) : '';
    $filter_del_date = isset($_GET['filter_del_date']) ? sanitize_text_field($_GET['filter_del_date']) : '';

    if (!empty($search_del)) {
        $like_query = '%' . $wpdb->esc_like($search_del) . '%';
        if (is_numeric($search_del)) {
            $where .= $wpdb->prepare(" AND (customer_name LIKE %s OR customer_phone LIKE %s OR id = %d)", $like_query, $like_query, intval($search_del));
        } else {
            $where .= $wpdb->prepare(" AND (customer_name LIKE %s OR customer_phone LIKE %s)", $like_query, $like_query);
        }
    }
    if (!empty($filter_del_date)) {
        $where .= $wpdb->prepare(" AND DATE(created_at) = %s", $filter_del_date);
    }

    $invoices = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 200");
    ?>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-red-100 dark:border-red-900 shadow-sm overflow-hidden mb-8">
        <div class="p-6 border-b border-red-50 dark:border-red-900/30 bg-red-50/30 dark:bg-red-900/10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <h3 class="font-bold text-red-600 whitespace-nowrap">Archived Deletions</h3>
            <form method="GET" class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto items-stretch sm:items-center hidden-print">
                <?php if(isset($_GET['tab'])): ?>
                    <input type="hidden" name="tab" value="<?php echo esc_attr($_GET['tab']); ?>">
                <?php endif; ?>
                <input type="text" name="search_del" value="<?php echo esc_attr($search_del); ?>" placeholder="Search..." class="border border-red-200 dark:border-red-800 bg-white dark:bg-gray-800 p-2 rounded-lg text-sm outline-none w-full sm:w-auto text-red-900 dark:text-red-100 min-w-[120px]">
                <input type="date" name="filter_del_date" value="<?php echo esc_attr($filter_del_date); ?>" class="border border-red-200 dark:border-red-800 bg-white dark:bg-gray-800 p-2 rounded-lg text-sm outline-none w-full sm:w-auto text-red-900 dark:text-red-100">
                <div class="flex gap-2 w-full sm:w-auto">
                    <button type="submit" class="flex-1 sm:flex-none bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold">Filter</button>
                    <a href="?purge_archive=1" class="flex-1 sm:flex-none text-xs bg-red-800 text-white px-3 py-2 rounded-lg font-bold hover:bg-red-900 transition whitespace-nowrap text-center flex items-center justify-center" onclick="customConfirmLink(event, 'Are you sure you want to purge the entire archive? This cannot be undone.')">Purge Entire Archive</a>
                </div>
            </form>
        </div>
        <div class="overflow-x-auto w-full archive-table-wrap">
            <table class="w-full text-left text-sm min-w-[600px]">
                <thead>
                    <tr class="border-b border-red-200 dark:border-red-900 text-red-700 whitespace-nowrap">
                        <th class="px-6 py-3">Deleted Date & Time</th>
                        <th class="px-6 py-3">INV #</th>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Total</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($invoices)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-red-400 italic">No archived records found.</td></tr>
                    <?php endif; ?>
                    <?php foreach($invoices as $inv): ?>
                    <tr class="border-b dark:border-gray-800 opacity-70 hover:opacity-100 transition">
                        <td class="px-6 py-3 font-mono text-xs whitespace-nowrap arch-date"><?php echo date('Y-m-d H:i', strtotime($inv->created_at)); ?></td>
                        <td class="px-6 py-3 font-mono text-xs whitespace-nowrap arch-inv"><?php echo esc_html(SimpleBill_get_invoice_number($inv->id, $inv->user_id, $inv->admin_id)); ?></td>
                        <td class="px-6 py-3 whitespace-nowrap arch-customer"><?php echo esc_html($inv->customer_name); ?></td>
                        <td class="px-6 py-3 font-bold whitespace-nowrap arch-amount"><?php echo esc_html($currency); ?> <?php echo number_format($inv->total_amount, 2); ?></td>
                        <td class="px-6 py-3 text-right whitespace-nowrap arch-actions">
                            <a href="?restore_inv=<?php echo $inv->id; ?>" class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs mr-2"><i class="fa-solid fa-rotate-left"></i> <span class="mobile-action-text">Restore</span></a>
                            <a href="?perm_del_inv=<?php echo $inv->id; ?>" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs" onclick="customConfirmLink(event, 'WARNING: Are you sure you want to permanently delete this record? This action cannot be undone.')"><i class="fa-solid fa-trash"></i> <span class="mobile-action-text">Delete</span></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// ==========================================
// 8. LOGIN PAGE RENDERER
// ==========================================
function SimpleBill_render_login_page() {
    $logo_url = get_option('SimpleBill_logo_url');
    $business_name = get_option('SimpleBill_business_name', 'Simple BIll');
    
    $reset_msg = '';
    if (isset($_POST['SimpleBill_send_frontend_reset'])) {
        $reset_email = sanitize_email($_POST['reset_email']);
        $user = get_user_by('email', $reset_email);
        if ($user) {
            $reset_key = get_password_reset_key($user);
            if (!is_wp_error($reset_key)) {
                $reset_link = network_site_url("wp-login.php?action=rp&key=" . $reset_key . "&login=" . rawurlencode($user->user_login), 'login');
                $message = "Someone has requested a password reset for the following account:\r\n\r\n";
                $message .= network_home_url('/') . "\r\n\r\n";
                $message .= sprintf('Username: %s', $user->user_login) . "\r\n\r\n";
                $message .= "If this was a mistake, just ignore this email and nothing will happen.\r\n\r\n";
                $message .= "To reset your password, visit the following address:\r\n\r\n";
                $message .= $reset_link . "\r\n";
                
                wp_mail($user->user_email, 'Password Reset', $message);
                $reset_msg = 'sent';
            } else {
                $reset_msg = 'error';
            }
        } else {
            $reset_msg = 'not_found';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - <?php echo esc_html($business_name); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-900 h-screen flex items-center justify-center bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1557683316-973673baf926?q=80&w=2000&auto=format&fit=crop');">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-md"></div>
        <div class="relative z-10 bg-white p-6 sm:p-10 rounded-3xl shadow-2xl w-full max-w-md border border-gray-100 mx-4">
            <div class="text-center mb-10">
                <?php if($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" class="mx-auto max-h-24 mb-6"><?php endif; ?>
                <h1 class="text-4xl font-black text-gray-900 mb-2"><?php echo esc_html($business_name); ?></h1>
                <p class="text-gray-400 font-medium tracking-wide">Enter your credentials to access the system</p>
            </div>
            
            <?php if(isset($_GET['SimpleBill_login']) && $_GET['SimpleBill_login'] === 'failed'): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-600 rounded-xl text-center font-bold text-sm shadow-sm">
                Invalid username or password. Please try again.
            </div>
            <?php elseif(isset($_GET['SimpleBill_login']) && $_GET['SimpleBill_login'] === 'disabled'): ?>
            <div class="mb-6 p-4 bg-orange-50 border border-orange-100 text-orange-700 rounded-xl text-center font-bold text-sm shadow-sm">
                This account has been disabled by the system administrator.
            </div>
            <?php elseif(isset($_GET['SimpleBill_msg']) && $_GET['SimpleBill_msg'] === 'password_reset'): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-100 text-green-700 rounded-xl text-center font-bold text-sm shadow-sm">
                ✓ Password reset successfully. You can now sign in with your new password.
            </div>
            <?php elseif(isset($_GET['SimpleBill_msg']) && $_GET['SimpleBill_msg'] === 'link_expired'): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-600 rounded-xl text-center font-bold text-sm shadow-sm">
                Your reset link has expired. Please request a new one.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['SimpleBill_forgot_password'])): ?>
                <?php if ($reset_msg === 'sent'): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-100 text-green-700 rounded-xl text-center font-bold text-sm shadow-sm">
                    Password reset link sent to your email.
                </div>
                <?php elseif ($reset_msg === 'not_found' || $reset_msg === 'error'): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-600 rounded-xl text-center font-bold text-sm shadow-sm">
                    Error sending reset link or email not found.
                </div>
                <?php endif; ?>
                <form method="post" class="space-y-6">
                    <p class="text-sm text-gray-500 mb-2">Enter your email address to receive a password reset link.</p>
                    <input type="email" name="reset_email" placeholder="Email Address" class="w-full px-6 py-4 bg-gray-50 border-none rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-lg" required>
                    <button type="submit" name="SimpleBill_send_frontend_reset" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-2xl transition shadow-xl shadow-blue-500/30 text-xl">Send Reset Link</button>
                    <div class="text-center pt-2">
                        <a href="<?php echo esc_url(home_url('?SimpleBill_login=1')); ?>" class="text-gray-400 hover:text-blue-500 text-sm font-medium transition">Back to Login</a>
                    </div>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" class="space-y-6">
                    <input type="text" name="log" placeholder="Username" class="w-full px-6 py-4 bg-gray-50 border-none rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-lg" required>
                    <input type="password" name="pwd" placeholder="Password" class="w-full px-6 py-4 bg-gray-50 border-none rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-lg" required>
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url()); ?>">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-2xl transition shadow-xl shadow-blue-500/30 text-xl">Sign In</button>
                    <div class="text-center pt-2">
                        <a href="<?php echo esc_url(home_url('?SimpleBill_forgot_password=1')); ?>" class="text-gray-400 hover:text-blue-500 text-sm font-medium transition">Forgot password?</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <div class="fixed bottom-0 w-full text-center py-4 text-xs text-gray-400 z-20">
            &copy; <?php echo date('Y'); ?> <?php echo esc_html($business_name); ?>. 
            System by <a href="https://www.linkedin.com/in/rowsul-ilahi-b4611a19b" target="_blank" class="font-bold text-gray-300 hover:text-white transition">Ilahi</a> &bull; 
            Designed by <a href="https://www.facebook.com/share/1BADKhBsJM/" target="_blank" class="font-bold text-gray-300 hover:text-white transition">PearlWaves</a>
        </div>
    </body>
    </html>
    <?php
}

// ==========================================
// ACTIVITY LOGS SECTION (SUPER ADMIN + ADMINS)
// ==========================================
function SimpleBill_render_logs_section() {
    if (!current_user_can('administrator') && !current_user_can('SimpleBill_admin_cap')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'SimpleBill_logs';
    $is_super_admin = current_user_can('administrator');
    $current_admin_id = get_current_user_id();

    // Ensure table exists for installs that haven't re-activated
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if (!$table_exists) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_login varchar(100) NOT NULL,
            action varchar(100) NOT NULL,
            target_type varchar(100) NOT NULL,
            target_detail text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Handle delete single log (super admin only)
    if ($is_super_admin && isset($_GET['del_log'])) {
        $wpdb->delete($table, ['id' => intval($_GET['del_log'])]);
        echo "<script>window.location.href='?tab=logs';</script>"; exit;
    }

    // Handle clear all logs (super admin only) — also purges all soft-deleted records globally
    if ($is_super_admin && isset($_GET['clear_logs'])) {
        $wpdb->query("TRUNCATE TABLE $table");
        $wpdb->delete($wpdb->prefix . 'SimpleBill_invoices',  ['is_deleted' => 1]);
        $wpdb->delete($wpdb->prefix . 'SimpleBill_customers', ['is_deleted' => 1]);
        $wpdb->delete($wpdb->prefix . 'SimpleBill_products',  ['is_deleted' => 1]);
        echo "<script>window.location.href='?tab=logs';</script>"; exit;
    }

    // For admin: get list of their assigned shop users
    $admin_user_logins = [];
    if (!$is_super_admin) {
        $assigned_users = get_users(['role' => 'SimpleBill_shop', 'meta_key' => '_SimpleBill_parent_admin', 'meta_value' => $current_admin_id]);
        foreach ($assigned_users as $u) {
            $admin_user_logins[] = $u->user_login;
        }
        // Also include admin's own actions
        $current_user_obj = wp_get_current_user();
        $admin_user_logins[] = $current_user_obj->user_login;
    }

    // Filters
    $search = isset($_GET['log_search']) ? sanitize_text_field($_GET['log_search']) : '';
    $filter_action = isset($_GET['log_action']) ? sanitize_text_field($_GET['log_action']) : '';
    $filter_type   = isset($_GET['log_type'])   ? sanitize_text_field($_GET['log_type'])   : '';
    $filter_user   = isset($_GET['log_user'])   ? sanitize_text_field($_GET['log_user'])   : '';
    $filter_date   = isset($_GET['log_date'])   ? sanitize_text_field($_GET['log_date'])   : '';

    $where = '1=1';

    // Admins can only see logs from their assigned users + themselves
    if (!$is_super_admin) {
        if (empty($admin_user_logins)) {
            $where .= " AND 1=0"; // no users assigned, show nothing
        } else {
            $placeholders = implode(', ', array_fill(0, count($admin_user_logins), '%s'));
            $where .= $wpdb->prepare(" AND user_login IN ($placeholders)", $admin_user_logins);
        }
    }

    if (!empty($search)) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(" AND (user_login LIKE %s OR action LIKE %s OR target_type LIKE %s OR target_detail LIKE %s)", $like, $like, $like, $like);
    }
    if (!empty($filter_action)) $where .= $wpdb->prepare(" AND action = %s", $filter_action);
    if (!empty($filter_type))   $where .= $wpdb->prepare(" AND target_type = %s", $filter_type);
    if (!empty($filter_user))   $where .= $wpdb->prepare(" AND user_login LIKE %s", '%' . $wpdb->esc_like($filter_user) . '%');
    if (!empty($filter_date))   $where .= $wpdb->prepare(" AND DATE(created_at) = %s", $filter_date);

    $logs = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 500");
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");

    // Distinct filter values for dropdowns (scoped to visible logs)
    $distinct_actions = $wpdb->get_col("SELECT DISTINCT action FROM $table WHERE $where ORDER BY action ASC");
    $distinct_types   = $wpdb->get_col("SELECT DISTINCT target_type FROM $table WHERE $where ORDER BY target_type ASC");

    // Get archived (deleted) invoices/customers/products for admin restore view
    $archived_invoices = [];
    $archived_customers = [];
    $archived_products = [];
    if (!$is_super_admin) {
        $table_inv  = $wpdb->prefix . 'SimpleBill_invoices';
        $table_cust = $wpdb->prefix . 'SimpleBill_customers';
        $table_prod = $wpdb->prefix . 'SimpleBill_products';
        $archived_invoices  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_inv WHERE is_deleted = 1 AND admin_id = %d ORDER BY created_at DESC", $current_admin_id));
        $archived_customers = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_cust WHERE is_deleted = 1 AND admin_id = %d ORDER BY created_at DESC", $current_admin_id));
        $archived_products  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_prod WHERE is_deleted = 1 AND admin_id = %d ORDER BY created_at DESC", $current_admin_id));
    }

    // Action badge colors
    $action_colors = [
        'CREATE'         => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'ADD'            => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'EDIT'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'DELETE'         => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'BULK DELETE'    => 'bg-red-200 text-red-800 dark:bg-red-900/60 dark:text-red-300',
        'PERMANENT DELETE'=> 'bg-red-300 text-red-900 dark:bg-red-900/80 dark:text-red-200',
        'RESTORE'        => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'PURGE'          => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        'BULK IMPORT'    => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
        'FACTORY RESET'  => 'bg-red-900 text-white dark:bg-red-950 dark:text-red-200',
        'CREATE ADMIN'   => 'bg-blue-200 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
        'EDIT ADMIN'     => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'DELETE ADMIN'   => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'ENABLE ADMIN'   => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'DISABLE ADMIN'  => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
        'CREATE USER'    => 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
        'EDIT USER'      => 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
        'DELETE USER'    => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'ENABLE USER'    => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'DISABLE USER'   => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
    ];
    $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
    ?>

    <!-- Stats Bar -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 p-4 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-indigo-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-1">Total Logs</p>
            <p class="text-2xl font-black"><?php echo number_format($total_count); ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-green-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-1">Showing</p>
            <p class="text-2xl font-black"><?php echo count($logs); ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-red-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-1">Delete Actions</p>
            <p class="text-2xl font-black"><?php echo $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where AND action LIKE '%DELETE%'"); ?></p>
        </div>
        <div class="bg-white dark:bg-gray-900 p-4 rounded-xl shadow-sm border dark:border-gray-800 border-l-4 border-l-blue-500">
            <p class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-1">Create Actions</p>
            <p class="text-2xl font-black"><?php echo $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where AND action IN ('CREATE','ADD','CREATE ADMIN','CREATE USER','BULK IMPORT')"); ?></p>
        </div>
    </div>

    <!-- Filters + Actions Bar -->
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 overflow-hidden mb-6">
        <div class="p-4 border-b dark:border-gray-800 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <h2 class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-clipboard-list text-indigo-500"></i> Activity Logs<?php if(!$is_super_admin): ?> <span class="text-sm font-normal text-gray-500">(Your Users)</span><?php endif; ?></h2>
            <?php if($is_super_admin): ?>
            <a href="?tab=logs&clear_logs=1" onclick="customConfirmLink(event, 'WARNING: This will permanently clear ALL activity logs AND permanently delete all soft-deleted Invoices, Customers, and Products across all admins. This cannot be undone!')" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 whitespace-nowrap shadow-sm"><i class="fa-solid fa-trash-can"></i> Clear All Logs & Deleted Records</a>
            <?php endif; ?>
        </div>

        <!-- Filter Form -->
        <form method="GET" class="p-4 bg-gray-50 dark:bg-gray-800/50 border-b dark:border-gray-800 flex flex-wrap gap-3 items-end">
            <input type="hidden" name="tab" value="logs">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold uppercase text-gray-500">Search</label>
                <div class="relative">
                    <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input type="text" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="Search logs..." class="pl-8 pr-3 py-2 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-sm outline-none focus:ring-2 focus:ring-indigo-400 w-full sm:w-48">
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold uppercase text-gray-500">Action</label>
                <select name="log_action" class="py-2 px-3 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-sm outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">All Actions</option>
                    <?php foreach($distinct_actions as $a): ?>
                        <option value="<?php echo esc_attr($a); ?>" <?php selected($filter_action, $a); ?>><?php echo esc_html($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold uppercase text-gray-500">Target Type</label>
                <select name="log_type" class="py-2 px-3 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-sm outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">All Types</option>
                    <?php foreach($distinct_types as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected($filter_type, $t); ?>><?php echo esc_html($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold uppercase text-gray-500">User</label>
                <input type="text" name="log_user" value="<?php echo esc_attr($filter_user); ?>" placeholder="Username..." class="py-2 px-3 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-sm outline-none focus:ring-2 focus:ring-indigo-400 w-36">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-bold uppercase text-gray-500">Date</label>
                <input type="date" name="log_date" value="<?php echo esc_attr($filter_date); ?>" class="py-2 px-3 bg-white dark:bg-gray-700 border dark:border-gray-600 rounded-lg text-sm outline-none focus:ring-2 focus:ring-indigo-400">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition">Filter</button>
                <a href="?tab=logs" class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg text-sm font-bold transition">Reset</a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border dark:border-gray-800 overflow-hidden mb-8">
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left min-w-[700px]">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase text-gray-500 font-bold whitespace-nowrap">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">Date & Time</th>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Target</th>
                        <th class="px-4 py-3">Details</th>
                        <?php if($is_super_admin): ?><th class="px-4 py-3 text-right">Delete</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-800">
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="<?php echo $is_super_admin ? 7 : 6; ?>" class="px-6 py-12 text-center text-gray-400 italic">No activity logs found. Logs will appear here once actions are performed in the system.</td></tr>
                    <?php endif; ?>
                    <?php foreach($logs as $log): 
                        $badge_class = $action_colors[$log->action] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                        $log_date = new DateTime($log->created_at);
                        $formatted_log_date = $log_date->format('M d, Y H:i:s');
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition text-sm">
                        <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?php echo $log->id; ?></td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs font-mono"><?php echo esc_html($formatted_log_date); ?></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 font-black text-xs flex-shrink-0"><?php echo strtoupper(substr($log->user_login, 0, 1)); ?></span>
                                <span class="font-bold truncate max-w-[100px]"><?php echo esc_html($log->user_login); ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="text-[11px] font-black uppercase px-2 py-1 rounded-full <?php echo $badge_class; ?>"><?php echo esc_html($log->action); ?></span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400 font-medium"><?php echo esc_html($log->target_type); ?></td>
                        <td class="px-4 py-3 text-gray-500 max-w-[220px] truncate" title="<?php echo esc_attr($log->target_detail); ?>"><?php echo esc_html($log->target_detail); ?></td>
                        <?php if($is_super_admin): ?>
                        <td class="px-4 py-3 text-right">
                            <a href="?tab=logs&del_log=<?php echo $log->id; ?>" onclick="customConfirmLink(event, 'Delete this log entry?')" class="text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded-lg transition"><i class="fa-solid fa-trash-can text-xs"></i></a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if(count($logs) >= 500): ?>
        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border-t dark:border-gray-800 text-sm text-yellow-700 dark:text-yellow-300 text-center font-medium">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Showing the latest 500 log entries. Use filters to find specific records.
        </div>
        <?php endif; ?>
    </div>

    <?php if(!$is_super_admin): ?>
    <!-- ADMIN RESTORE SECTION -->
    <?php if(!empty($archived_invoices) || !empty($archived_customers) || !empty($archived_products)): ?>
    <div class="mb-6">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-2"><i class="fa-solid fa-rotate-left text-emerald-500"></i> Restore Deleted Records</h2>

        <?php if(!empty($archived_invoices)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-red-100 dark:border-red-900/50 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/30 bg-red-50/30 dark:bg-red-900/10 flex items-center justify-between gap-4 flex-wrap">
                <h3 class="font-bold text-red-600 flex items-center gap-2"><i class="fa-solid fa-receipt"></i> Deleted Invoices</h3>
                <a href="?admin_clear_inv=1" onclick="customConfirmLink(event, 'WARNING: This will permanently delete ALL deleted invoices. This cannot be undone!')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 shadow-sm"><i class="fa-solid fa-trash-can"></i> Clear All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm min-w-[500px]">
                    <thead><tr class="border-b border-red-100 dark:border-red-900 text-red-700 text-xs uppercase font-bold">
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3">Invoice #</th>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Total</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($archived_invoices as $inv): ?>
                    <tr class="border-b dark:border-gray-800 opacity-75 hover:opacity-100 transition">
                        <td class="px-6 py-3 font-mono text-xs text-gray-500"><?php echo date('Y-m-d H:i', strtotime($inv->created_at)); ?></td>
                        <td class="px-6 py-3 font-mono text-xs text-red-500"><?php echo esc_html(SimpleBill_get_invoice_number($inv->id, $inv->user_id, $inv->admin_id)); ?></td>
                        <td class="px-6 py-3 font-bold"><?php echo esc_html($inv->customer_name); ?></td>
                        <td class="px-6 py-3 font-bold text-red-600"><?php echo esc_html($currency); ?> <?php echo number_format($inv->total_amount, 2); ?></td>
                        <td class="px-6 py-3 text-right flex items-center justify-end gap-2">
                            <a href="?admin_restore_inv=<?php echo $inv->id; ?>" onclick="customConfirmLink(event, 'Restore this invoice?')" class="bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-rotate-left"></i> Restore</a>
                            <a href="?admin_perm_del_inv=<?php echo $inv->id; ?>" onclick="customConfirmLink(event, 'Permanently delete this invoice? This cannot be undone.')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-trash"></i> Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if(!empty($archived_customers)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-red-100 dark:border-red-900/50 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/30 bg-red-50/30 dark:bg-red-900/10 flex items-center justify-between gap-4 flex-wrap">
                <h3 class="font-bold text-red-600 flex items-center gap-2"><i class="fa-solid fa-users"></i> Deleted Customers</h3>
                <a href="?admin_clear_customers=1" onclick="customConfirmLink(event, 'WARNING: This will permanently delete ALL deleted customers. This cannot be undone!')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 shadow-sm"><i class="fa-solid fa-trash-can"></i> Clear All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm min-w-[400px]">
                    <thead><tr class="border-b border-red-100 dark:border-red-900 text-red-700 text-xs uppercase font-bold">
                        <th class="px-6 py-3">Name</th>
                        <th class="px-6 py-3">Phone</th>
                        <th class="px-6 py-3">Address</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($archived_customers as $cust): ?>
                    <tr class="border-b dark:border-gray-800 opacity-75 hover:opacity-100 transition">
                        <td class="px-6 py-3 font-bold"><?php echo esc_html($cust->name); ?></td>
                        <td class="px-6 py-3 text-gray-500"><?php echo esc_html($cust->phone); ?></td>
                        <td class="px-6 py-3 text-gray-500"><?php echo esc_html($cust->address); ?></td>
                        <td class="px-6 py-3 text-right flex items-center justify-end gap-2">
                            <a href="?admin_restore_customer=<?php echo $cust->id; ?>" onclick="customConfirmLink(event, 'Restore this customer?')" class="bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-rotate-left"></i> Restore</a>
                            <a href="?admin_perm_del_customer=<?php echo $cust->id; ?>" onclick="customConfirmLink(event, 'Permanently delete this customer? This cannot be undone.')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-trash"></i> Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if(!empty($archived_products)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-red-100 dark:border-red-900/50 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/30 bg-red-50/30 dark:bg-red-900/10 flex items-center justify-between gap-4 flex-wrap">
                <h3 class="font-bold text-red-600 flex items-center gap-2"><i class="fa-solid fa-box-open"></i> Deleted Products</h3>
                <a href="?admin_clear_products=1" onclick="customConfirmLink(event, 'WARNING: This will permanently delete ALL deleted products. This cannot be undone!')" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 shadow-sm"><i class="fa-solid fa-trash-can"></i> Clear All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm min-w-[400px]">
                    <thead><tr class="border-b border-red-100 dark:border-red-900 text-red-700 text-xs uppercase font-bold">
                        <th class="px-6 py-3">Product Name</th>
                        <th class="px-6 py-3">HSN/SKU</th>
                        <th class="px-6 py-3">Unit</th>
                        <th class="px-6 py-3">Price</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($archived_products as $prod): ?>
                    <tr class="border-b dark:border-gray-800 opacity-75 hover:opacity-100 transition">
                        <td class="px-6 py-3 font-bold"><?php echo esc_html($prod->name); ?></td>
                        <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo esc_html($prod->hsn_code); ?></td>
                        <td class="px-6 py-3 text-gray-500"><?php echo esc_html($prod->unit); ?></td>
                        <td class="px-6 py-3 font-bold"><?php echo esc_html($currency); ?> <?php echo number_format($prod->price, 2); ?></td>
                        <td class="px-6 py-3 text-right flex items-center justify-end gap-2">
                            <a href="?admin_restore_product=<?php echo $prod->id; ?>" onclick="customConfirmLink(event, 'Restore this product?')" class="bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-rotate-left"></i> Restore</a>
                            <a href="?admin_perm_del_product=<?php echo $prod->id; ?>" onclick="customConfirmLink(event, 'Permanently delete this product? This cannot be undone.')" class="bg-red-100 text-red-600 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1 font-bold text-xs"><i class="fa-solid fa-trash"></i> Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php else: ?>
    <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-100 dark:border-emerald-900/40 p-6 text-center text-emerald-700 dark:text-emerald-400 font-medium">
        <i class="fa-solid fa-circle-check text-2xl mb-2 block"></i>
        No deleted records to restore. Everything is up to date.
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
}

// ==========================================
// 9. AJAX, PRINT & EXPORT LOGIC
// ==========================================

// EXPORT CUSTOMERS LOGIC
add_action('init', function() {
    if (isset($_GET['SimpleBill_export_customers']) && is_user_logged_in()) {
        global $wpdb;
        $table = $wpdb->prefix . 'SimpleBill_customers';
        
        $where = "is_deleted = 0";
        if (!current_user_can('administrator')) {
            $user_id = get_current_user_id();
            $admin_id = current_user_can('SimpleBill_admin_cap') ? $user_id : (get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0);
            $where .= $wpdb->prepare(" AND admin_id = %d", $admin_id);
        }

        $results = $wpdb->get_results("SELECT sr_no, name, phone, address FROM $table WHERE $where ORDER BY name ASC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customers_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Sr No', 'Customer Name', 'Phone', 'Address'));
        
        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    }
});

// EXPORT PRODUCTS LOGIC
add_action('init', function() {
    if (isset($_GET['SimpleBill_export_products']) && is_user_logged_in() && current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'SimpleBill_products';
        
        $where = "is_deleted = 0";
        $where .= $wpdb->prepare(" AND admin_id = %d", $user_id);

        $results = $wpdb->get_results("SELECT sr_no, hsn_code, name, unit, price FROM $table WHERE $where ORDER BY name ASC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=products_export_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Sr No', 'HSN Code', 'Product Name', 'Measurement Units', 'Sale Rate'));
        
        if (!empty($results)) {
            foreach ($results as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    }
});

add_action('wp_ajax_SimpleBill_create_invoice', function() {
    if (!current_user_can('SimpleBill_shop_cap') && !current_user_can('SimpleBill_admin_cap') && !current_user_can('administrator')) {
        wp_send_json_error('Unauthorized');
    }
    
    global $wpdb; 
    $items = json_decode(stripslashes($_POST['items_json']), true); 
    $edit_invoice_id = isset($_POST['edit_invoice_id']) ? intval($_POST['edit_invoice_id']) : 0;
    
    $sanitized_items = [];
    $total = 0; 
    foreach($items as $i) {
        $price = max(0, floatval($i['price']));
        $qty = max(0, floatval($i['qty']));
        $discount = max(0, floatval($i['discount'] ?? 0));
        
        $line_total = max(0, ($price * $qty) - $discount);
        $total += $line_total;
        
        $sanitized_items[] = [
            'desc'  => sanitize_text_field($i['desc']),
            'unit'  => sanitize_text_field($i['unit'] ?? ''),
            'price' => $price,
            'qty'   => $qty,
            'discount' => $discount,
            'total' => $line_total
        ];
    }
    
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $balance_amount = floatval($_POST['balance_amount'] ?? 0);

    $user_id = get_current_user_id(); 
    $admin_id = current_user_can('SimpleBill_admin_cap') ? $user_id : (get_user_meta($user_id, '_SimpleBill_parent_admin', true) ?: 0);
    if(current_user_can('administrator')) $admin_id = 0;
    
    $sr_no = sanitize_text_field($_POST['customer_sr_no'] ?? '');
    $name = sanitize_text_field($_POST['customer_name']);
    $phone = sanitize_text_field($_POST['customer_phone']);
    $address = sanitize_textarea_field($_POST['customer_address']);

    // Check if Edit Mode
    if ($edit_invoice_id > 0) {
        // Validate ownership/authorization before updating
        $existing = $wpdb->get_row($wpdb->prepare("SELECT admin_id, user_id FROM {$wpdb->prefix}SimpleBill_invoices WHERE id = %d", $edit_invoice_id));
        if (!$existing) {
            wp_send_json_error('Invoice not found');
        }
        if (!current_user_can('administrator')) {
            if (current_user_can('SimpleBill_admin_cap') && $existing->admin_id != $admin_id) wp_send_json_error('Unauthorized access to update this invoice');
            if (current_user_can('SimpleBill_shop_cap') && $existing->user_id != $user_id) wp_send_json_error('Unauthorized access to update this invoice');
        }

        $updated = $wpdb->update($wpdb->prefix . 'SimpleBill_invoices', [
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_address' => $address,
            'total_amount' => $total,
            'payment_amount' => $payment_amount,
            'balance_amount' => $balance_amount,
            'items' => json_encode($sanitized_items)
        ], ['id' => $edit_invoice_id]);

        if ($updated === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }

        SimpleBill_log('EDIT', 'Invoice', 'Invoice ID: ' . $edit_invoice_id . ' | Customer: ' . $name . ' | Total: ' . $total);
        wp_send_json_success(['invoice_id' => $edit_invoice_id]);
    } else {
        // Normal Creation Mode
        $tz_str = get_option('SimpleBill_timezone', 'Asia/Colombo');
        $tz = new DateTimeZone($tz_str);
        $date = new DateTime('now', $tz);
        $exact_created_at = $date->format('Y-m-d H:i:s');

        // Admin Separated Customer Check
        $cust_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}SimpleBill_customers WHERE phone = %s AND name = %s AND admin_id = %d", $phone, $name, $admin_id));
        if (!$cust_exists && !empty($name)) {
            $wpdb->insert($wpdb->prefix . 'SimpleBill_customers', [
                'user_id' => $user_id,
                'admin_id' => $admin_id, 
                'sr_no' => $sr_no,
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
                'created_at' => $exact_created_at 
            ]);
        }
        
        // Admin Separated Product Check (Auto Save Missing Products)
        foreach($sanitized_items as $si) {
            if (!empty($si['desc'])) {
                $prod_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}SimpleBill_products WHERE name = %s AND admin_id = %d", $si['desc'], $admin_id));
                if (!$prod_exists) {
                    $wpdb->insert($wpdb->prefix . 'SimpleBill_products', [
                        'user_id' => $user_id,
                        'admin_id' => $admin_id,
                        'name' => $si['desc'],
                        'unit' => $si['unit'],
                        'price' => $si['price'],
                        'created_at' => $exact_created_at 
                    ]);
                }
            }
        }

        $inserted = $wpdb->insert($wpdb->prefix . 'SimpleBill_invoices', [
            'user_id' => $user_id,
            'admin_id' => $admin_id,
            'customer_sr_no' => $sr_no,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_address' => $address,
            'total_amount' => $total,
            'payment_amount' => $payment_amount,
            'balance_amount' => $balance_amount,
            'items' => json_encode($sanitized_items),
            'created_at' => $exact_created_at 
        ]);
        
        if ($inserted === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }
        
        $new_invoice_id = $wpdb->insert_id;
        SimpleBill_log('CREATE', 'Invoice', 'Invoice ID: ' . $new_invoice_id . ' | Customer: ' . $name . ' | Total: ' . $total);
        wp_send_json_success(['invoice_id' => $new_invoice_id]);
    }
});

// MULTI-FORMAT PRINT RENDERING
add_action('init', function() {
    if (isset($_GET['SimpleBill_print_invoice']) && is_user_logged_in()) {
        global $wpdb; $inv = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "SimpleBill_invoices WHERE id = " . intval($_GET['SimpleBill_print_invoice']));
        if (!$inv) wp_die('Not found');
        
        $user = wp_get_current_user();
        if (!current_user_can('administrator')) {
            if (current_user_can('SimpleBill_admin_cap') && $inv->admin_id != $user->ID) wp_die('Access Denied');
            if (current_user_can('SimpleBill_shop_cap') && $inv->user_id != $user->ID) wp_die('Access Denied');
        }

        $items = json_decode($inv->items, true);
        $admin_id = $inv->admin_id;
        $currency = SimpleBill_get_currency(isset($admin_id) ? $admin_id : 0);
        
        $business_name = '';
        if ($admin_id > 0) {
            $business_name = get_user_meta($admin_id, 'SimpleBill_business_name', true);
        } else {
            // Only pull the global fallback if it's the actual superadmin creating it 
            $business_name = get_option('SimpleBill_business_name', '');
        }

        $logo = get_user_meta($admin_id, 'SimpleBill_logo_url', true);
        $contact = nl2br(esc_html(get_user_meta($admin_id, 'SimpleBill_contact', true)));
        $address = nl2br(esc_html(get_user_meta($admin_id, 'SimpleBill_address', true)));
        $website = get_user_meta($admin_id, 'SimpleBill_website', true);
        
        $default_receipt_size = get_user_meta($admin_id, 'SimpleBill_receipt_size', true) ?: '80mm';
        
        $date_obj = new DateTime($inv->created_at);
        $formatted_date = $date_obj->format(get_option('date_format'));

        $cashier = get_userdata($inv->user_id);
        $cashier_name = $cashier ? esc_html($cashier->user_login) : 'Unknown';
        $inv_number = SimpleBill_get_invoice_number($inv->id, $inv->user_id, $inv->admin_id);
        
        $total_items_count = 0;
        $subtotal = 0;
        $total_discount = 0;
        
        $payment_amount = floatval($inv->payment_amount);
        $balance_amount = floatval($inv->balance_amount);

        foreach($items as $i) { 
            $total_items_count += floatval($i['qty']); 
            $subtotal += (floatval($i['price']) * floatval($i['qty']));
            $total_discount += floatval($i['discount'] ?? 0);
        }
        ?>
        <!DOCTYPE html>
        <html><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Invoice <?php echo esc_html($inv_number); ?></title>
        <!-- Modern PDF Generation Libraries -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
        <style>
            * { box-sizing: border-box; }
            body { background: #e2e8f0; margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

            /* ── TOOLBAR ── */
            .toolbar {
                position: fixed; top: 0; width: 100%; background: #1e293b; color: white;
                padding: 10px 12px; display: flex; flex-wrap: wrap; gap: 8px;
                z-index: 1000; align-items: center; justify-content: center;
                box-sizing: border-box; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.15);
            }
            .toolbar-group { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
            .toolbar label { font-size: 12px; font-weight: 700; white-space: nowrap; opacity: 0.8; }
            .toolbar select {
                padding: 7px 10px; border-radius: 6px; border: 1px solid #475569;
                background: #334155; color: white; font-family: inherit; font-size: 13px;
                min-height: 36px;
            }
            .toolbar button {
                padding: 7px 14px; border-radius: 6px; border: 1px solid #3b82f6;
                background: #3b82f6; color: white; font-family: inherit; font-size: 13px;
                font-weight: 700; cursor: pointer; transition: 0.2s; min-height: 36px;
                white-space: nowrap;
            }
            .toolbar button:hover { background: #2563eb; }
            .toolbar .btn-share { background: #10b981; border-color: #10b981; }
            .toolbar .btn-share:hover { background: #059669; }

            /* ── PRINT AREA ── */
            .print-area { margin-top: 80px; display: flex; justify-content: center; padding: 16px 8px 40px; }

            /* ── BOTH LAYOUTS ── */
            #thermal-layout, #standard-layout { display: none; background: white; color: #000; }

            /* ── THERMAL ── */
            #thermal-layout, #thermal-layout * { color: #000 !important; }
            #thermal-layout {
                font-family: 'Courier New', Courier, monospace; line-height: 1.4;
                max-width: 100%; /* never wider than screen */
                font-weight: bold;
                /* Base font size set by JS based on paper width */
            }
            #thermal-layout .t-center { text-align: center; }
            #thermal-layout .t-right  { text-align: right; }
            #thermal-layout .t-bold   { font-weight: bold; }
            #thermal-layout .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
            #thermal-layout .thermal-logo { max-width: 60%; max-height: 60px; margin: 0 auto 5px; display: block; }
            #thermal-layout h2 { margin: 0 0 5px 0; font-size: 1.6em; }
            #thermal-layout p  { margin: 4px 0; }
            #thermal-layout table { width: 100%; border-collapse: collapse; font-size: 1em; }
            #thermal-layout th { padding-bottom: 4px; font-size: 1.05em; }
            #thermal-layout td { vertical-align: top; }
            #thermal-layout .item-row td { padding-bottom: 4px; }
            #thermal-layout .meta-grid { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 1em; }

            /* ── STANDARD (A4 / Letter etc.) ── */
            #standard-layout { width: 100%; max-width: 210mm; }
            #standard-layout .box { padding: 28px; }
            #standard-layout table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            #standard-layout th { background: #f8f8f8; text-align: left; font-weight: bold; font-size: 14px; }
            #standard-layout th, #standard-layout td { padding: 6px 8px; border-bottom: 1px solid #eee; font-size: 14px; }
            #standard-layout .header-flex {
                display: flex; justify-content: space-between; align-items: flex-start;
                margin-bottom: 20px; border-bottom: 3px solid #3b82f6; padding-bottom: 10px;
                gap: 12px;
            }
            #standard-layout .logo { max-height: 80px; width: auto; max-width: 140px; }
            #standard-layout .company-details { text-align: right; }
            #standard-layout .company-details h2 { margin: 0 0 6px 0; font-size: 20px; font-weight: bold; }
            #standard-layout .company-details p  { margin: 3px 0; font-size: 14px; }
            #standard-layout .meta-info {
                display: flex; justify-content: space-between; gap: 12px;
                background: #fdfdfd; padding: 14px; border-radius: 5px;
                border: 1px solid #f0f0f0; margin-bottom: 15px;
            }
            #standard-layout .meta-info p { font-size: 14px; margin: 4px 0; }
            #standard-layout .total-row td { font-size: 16px; padding: 8px; background: #f9fafb; }

            /* ── MOBILE OVERRIDES (≤ 600px) ── */
            @media (max-width: 600px) {
                /* Toolbar: two rows of controls */
                .toolbar { padding: 8px 10px; gap: 6px; }
                .toolbar-group { flex: 1 1 45%; min-width: 0; }
                .toolbar select { width: 100%; font-size: 12px; }
                .toolbar button { flex: 1; font-size: 12px; padding: 7px 8px; }

                /* Print area: no side padding so invoice touches edges */
                .print-area { margin-top: 100px; padding: 10px 0 30px; }

                /* Standard layout: fluid, no fixed mm width */
                #standard-layout { width: 100% !important; max-width: 100% !important; }
                #standard-layout .box { padding: 14px 12px; }

                /* Header: stack logo + company details vertically */
                #standard-layout .header-flex { flex-direction: column; align-items: flex-start; }
                #standard-layout .company-details { text-align: left; margin-top: 8px; }
                #standard-layout .company-details h2 { font-size: 16px; }

                /* Meta info: stack invoice-to + invoice-no vertically */
                #standard-layout .meta-info { flex-direction: column; gap: 8px; }
                #standard-layout .meta-info > div:last-child { text-align: left; }

                /* Items table: scrollable horizontally */
                #standard-layout .table-scroll-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
                #standard-layout table { min-width: 480px; }

                /* Thermal: always full available width on screen */
                #thermal-layout { width: 100% !important; max-width: 100% !important; padding: 14px !important; }
            }

            @media (max-width: 380px) {
                .print-area { margin-top: 120px; }
                .toolbar-group { flex: 1 1 100%; }
            }

            /* ── PRINT MEDIA ── */
            @media print {
                @page { margin: 10mm; }
                body { margin: 0; padding: 0; background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .toolbar, .no-print { display: none !important; }
                .print-area { margin-top: 0 !important; padding: 0 !important; }
                #thermal-layout { box-shadow: none !important; margin: 0 auto !important; width: 100% !important; max-width: 100% !important; border: none !important; }
                #standard-layout { box-shadow: none !important; margin: 0 auto !important; width: 100% !important; max-width: 100% !important; border: none !important; padding: 0 !important; }
                #standard-layout .box { padding: 0; }
                #standard-layout .table-scroll-wrap { overflow: visible; }
            }
        </style>
        </head>
        <body onload="initFormat()">
            <div class="toolbar no-print">
                <div class="toolbar-group">
                    <label>Language:</label>
                    <select id="invoice-language" onchange="changeLanguage(this.value)">
                        <option value="en">English</option>
                        <option value="si">සිංහල</option>
                        <option value="ta">தமிழ்</option>
                    </select>
                </div>
                <div class="toolbar-group">
                    <label>Format:</label>
                    <select id="receipt-format" onchange="changeFormat(this.value)">
                        <option value="80mm" <?php selected($default_receipt_size, '80mm'); ?>>Thermal 80mm</option>
                        <option value="58mm" <?php selected($default_receipt_size, '58mm'); ?>>Thermal 58mm</option>
                        <option value="A4"   <?php selected($default_receipt_size, 'A4'); ?>>A4</option>
                        <option value="A5"   <?php selected($default_receipt_size, 'A5'); ?>>A5</option>
                        <option value="Letter" <?php selected($default_receipt_size, 'Letter'); ?>>Letter</option>
                        <option value="Legal"  <?php selected($default_receipt_size, 'Legal'); ?>>Legal</option>
                    </select>
                </div>
                <div class="toolbar-group">
                    <button onclick="window.print()">🖨 Print</button>
                    <button class="btn-share" onclick="shareInvoice()">⬆ Share PDF</button>
                </div>
            </div>

            <div class="print-area">
                
                <!-- THERMAL RECEIPT LAYOUT -->
                <div id="thermal-layout">
                    <div class="t-center">
                        <?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" class="thermal-logo"><?php endif; ?>
                        <?php if($business_name): ?><h2><?php echo esc_html($business_name); ?></h2><?php endif; ?>
                        <p><?php echo $address; ?></p>
                        <p><?php echo $contact; ?></p>
                    </div>
                    
                    <div class="dashed-line" style="margin-top: 10px;"></div>
                    <table style="width: 100%; margin: 8px 0; font-size: 0.95em; text-align: left;">
                        <tr><td style="width: 35%; vertical-align: top;"><span data-i18n="inv_no">Invoice No</span></td><td style="vertical-align: top;">: <strong><?php echo esc_html($inv_number); ?></strong></td></tr>
                        <tr><td style="vertical-align: top;"><span data-i18n="date_st">Date</span></td><td style="vertical-align: top;">: <?php echo date('Y-m-d', strtotime($inv->created_at)); ?></td></tr>
                        <?php if($inv->customer_name): ?>
                        <tr><td style="vertical-align: top;"><span data-i18n="customer">Customer</span></td><td style="vertical-align: top;">: <?php echo esc_html($inv->customer_name); ?></td></tr>
                        <?php endif; ?>
                        <?php if($inv->customer_address): ?>
                        <tr><td style="vertical-align: top;">Address</td><td style="vertical-align: top;">: <?php echo esc_html($inv->customer_address); ?></td></tr>
                        <?php endif; ?>
                        <?php if($inv->customer_phone): ?>
                        <tr><td style="vertical-align: top;">Tel.No</td><td style="vertical-align: top;">: <?php echo esc_html($inv->customer_phone); ?></td></tr>
                        <?php endif; ?>
                        <tr><td style="vertical-align: top;"><span data-i18n="cashier">Salesman</span></td><td style="vertical-align: top;">: <?php echo $cashier_name; ?></td></tr>
                    </table>
                    
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
                        <tr>
                            <th style="text-align:left; width: 10%; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0;">#</th>
                            <th style="text-align:left; width: 35%; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0;"><span data-i18n="rate">Rate</span></th>
                            <th style="text-align:center; width: 20%; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0;"><span data-i18n="qty">Qty</span></th>
                            <th style="text-align:right; width: 35%; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0;"><span data-i18n="amount">Value</span></th>
                        </tr>
                        <?php foreach($items as $idx => $i): 
                            $has_discount = !empty($i['discount']) && floatval($i['discount']) > 0;
                            $line_gross = floatval($i['price']) * floatval($i['qty']);
                            $unit_discount = $has_discount ? (floatval($i['discount']) / max(0.00001, floatval($i['qty']))) : 0;
                        ?>
                            <tr>
                                <td style="padding-top:6px; vertical-align:top; font-weight:bold;"><?php echo ($idx + 1); ?>.</td>
                                <td colspan="3" style="padding-top:6px; font-weight:bold;">
                                    <?php echo esc_html($i['desc']); ?><?php if(!empty($i['unit'])): ?> (<?php echo esc_html($i['unit']); ?>)<?php endif; ?>
                                    <?php if($has_discount): ?><br><span style="font-size:0.8em;"><?php echo number_format($i['price'], 2); ?> (<?php echo number_format($unit_discount, 2); ?>)</span><?php endif; ?>
                                </td>
                            </tr>
                            <tr class="item-row">
                                <td></td>
                                <td style="text-align:left; padding-bottom: 4px;"><?php if(!$has_discount): ?><?php echo number_format($i['price'], 2); ?><?php endif; ?></td>
                                <td style="text-align:center; padding-bottom: 4px;"><?php echo esc_html($i['qty']); ?></td>
                                <td style="text-align:right; padding-bottom: 4px; font-weight:bold;"><?php echo number_format(floatval($i['total']), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <div class="dashed-line"></div>
                    <table style="width: 100%;">
                        <tr>
                            <td class="t-bold" style="font-size: 1.1em;" data-i18n="sub_total">Sub Total</td>
                            <td class="t-right t-bold" style="font-size: 1.1em;"><?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                        <?php if ($total_discount > 0): ?>
                        <tr>
                            <td class="t-bold" style="font-size: 1.1em;" data-i18n="discount">Discount</td>
                            <td class="t-right t-bold" style="font-size: 1.1em;">-<?php echo number_format($total_discount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="t-bold" style="font-size: 1.2em;" data-i18n="net_total">Net Total</td>
                            <td class="t-right t-bold" style="font-size: 1.2em; white-space: nowrap;"><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($inv->total_amount, 2); ?></td>
                        </tr>
                        <?php if($payment_amount > 0): ?>
                        <tr><td colspan="2"><div class="dashed-line" style="margin:2px 0;"></div></td></tr>
                        <tr>
                            <td class="t-bold" style="font-size: 1.1em;" data-i18n="payment">Payment</td>
                            <td class="t-right t-bold" style="font-size: 1.1em; white-space: nowrap;"><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($payment_amount, 2); ?></td>
                        </tr>
                        <tr>
                            <td class="t-bold" style="font-size: 1.1em;" data-i18n="balance">Balance</td>
                            <td class="t-right t-bold" style="font-size: 1.1em; white-space: nowrap;"><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($balance_amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr><td colspan="2"><div class="dashed-line" style="margin:2px 0;"></div></td></tr>
                        <tr>
                            <td data-i18n="no_of_items">No of Items</td>
                            <td class="t-right"><?php echo $total_items_count; ?></td>
                        </tr>
                    </table>
                    
                    <div class="t-center" style="margin-top: 15px;">
                        <p data-i18n="thank_you">Thank You!</p>
                    </div>
                </div>

                <!-- STANDARD INVOICE LAYOUT -->
                <div id="standard-layout">
                    <div class="box">
                        <div class="header-flex">
                            <div><?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" class="logo"><?php endif; ?></div>
                            <div class="company-details">
                                <?php if($business_name): ?><h2><?php echo esc_html($business_name); ?></h2><?php endif; ?>
                                <p><?php echo $address; ?></p>
                                <p><?php echo $contact; ?></p>
                                <?php if($website): ?><p style="color: #3b82f6;"><?php echo esc_html($website); ?></p><?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="meta-info">
                            <div>
                                <p style="margin:0;"><strong data-i18n="customer_st">INVOICE TO:</strong></p>
                                <?php if($inv->customer_sr_no): ?><p style="margin:0; color: #666; font-size:0.9em;">Sr No: <?php echo esc_html($inv->customer_sr_no); ?></p><?php endif; ?>
                                <h3 style="margin:5px 0; font-size: 14px;"><?php echo esc_html($inv->customer_name); ?></h3>
                                <p style="margin:0; color: #666;"><?php echo esc_html($inv->customer_phone); ?></p>
                                <?php if($inv->customer_address): ?><p style="margin:0; color: #666;"><?php echo esc_html($inv->customer_address); ?></p><?php endif; ?>
                            </div>
                            <div style="text-align: right;">
                                <p style="margin:0;"><strong data-i18n="inv_no_st">INVOICE NO:</strong> <?php echo esc_html($inv_number); ?></p>
                                <p style="margin:0;"><strong data-i18n="date_st">DATE:</strong> <?php echo $formatted_date; ?></p>
                                <p style="margin:0;"><strong data-i18n="cashier_st">SALESMAN:</strong> <?php echo $cashier_name; ?></p>
                            </div>
                        </div>

                        <div class="table-scroll-wrap">
                        <table>
                            <thead><tr>
                                <th style="width:4%;">#</th>
                                <th style="width:36%;"><span data-i18n="desc">Description</span></th>
                                <th style="text-align:center; width:8%;"><span data-i18n="unit">Unit</span></th>
                                <th style="text-align:center; width:7%;"><span data-i18n="qty">Qty</span></th>
                                <th style="text-align:right; width:15%;"><span data-i18n="unit_price">Unit Price</span></th>
                                <th style="text-align:right; width:15%;"><span data-i18n="discount">Discount</span></th>
                                <th style="text-align:right; width:15%;"><span data-i18n="total">Total</span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach($items as $idx => $i): 
                                $has_discount = floatval($i['discount'] ?? 0) > 0;
                                $unit_discount = $has_discount ? (floatval($i['discount']) / max(0.00001, floatval($i['qty']))) : 0;
                            ?>
                                <tr>
                                    <td><?php echo ($idx + 1); ?>.</td>
                                    <td><?php echo esc_html($i['desc']); ?></td>
                                    <td style="text-align:center"><?php echo esc_html($i['unit'] ?? '-'); ?></td>
                                    <td style="text-align:center"><?php echo esc_html($i['qty']); ?></td>
                                    <td style="text-align:right"><?php echo number_format($i['price'], 2); ?></td>
                                    <td style="text-align:right"><?php echo $has_discount ? number_format($unit_discount, 2) : '-'; ?></td>
                                    <td style="text-align:right; font-weight:bold;"><?php echo esc_html($currency); ?> <?php echo number_format(floatval($i['total']), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="6" align="right"><strong data-i18n="sub_total_st">Sub Total:</strong></td>
                                    <td style="text-align:right; white-space: nowrap;"><strong><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($subtotal, 2); ?></strong></td>
                                </tr>
                                <?php if ($total_discount > 0): ?>
                                <tr class="total-row">
                                    <td colspan="6" align="right"><strong data-i18n="discount_st">Total Discount:</strong></td>
                                    <td style="text-align:right; color: #dc2626; white-space: nowrap;"><strong>-<?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($total_discount, 2); ?></strong></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="total-row">
                                    <td colspan="6" align="right"><strong data-i18n="net_total_st">Net Total:</strong></td>
                                    <td style="text-align:right; color: #3b82f6; white-space: nowrap;"><strong><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($inv->total_amount, 2); ?></strong></td>
                                </tr>
                                <?php if($payment_amount > 0): ?>
                                <tr class="total-row">
                                    <td colspan="6" align="right"><strong data-i18n="payment_st">Payment Received:</strong></td>
                                    <td style="text-align:right; white-space: nowrap;"><strong><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($payment_amount, 2); ?></strong></td>
                                </tr>
                                <tr class="total-row">
                                    <td colspan="6" align="right"><strong data-i18n="balance_st">Balance/Change:</strong></td>
                                    <td style="text-align:right; white-space: nowrap;"><strong><?php echo esc_html($currency); ?>&nbsp;<?php echo number_format($balance_amount, 2); ?></strong></td>
                                </tr>
                                <?php endif; ?>
                            </tfoot>
                        </table>
                        </div>
                        <div style="margin-top: 30px; text-align: right; color: #999; font-size: 11px; border-top: 1px solid #eee; padding-top: 15px;">
                            <span data-i18n="thank_you_st">Thank you for your business!</span>
                        </div>
                    </div>
                </div>

            </div>

            <script>
                // Language Translations Mapping
                const i18n = {
                    en: {
                        inv_no: "Invoice No",
                        inv_no_st: "INVOICE NO:",
                        date_st: "Date",
                        cashier: "Salesman",
                        cashier_st: "CASHIER:",
                        customer: "Customer",
                        customer_st: "INVOICE TO:",
                        item: "Item",
                        desc: "Description",
                        unit: "Unit",
                        qty: "Qty",
                        rate: "Rate",
                        unit_price: "Unit Price",
                        amount: "Value",
                        total: "Total",
                        sub_total: "Sub Total",
                        sub_total_st: "Sub Total:",
                        discount: "Discount",
                        discount_st: "Total Discount:",
                        net_total: "Net Total",
                        net_total_st: "Net Total:",
                        payment: "Payment",
                        payment_st: "Payment Received:",
                        balance: "Balance",
                        balance_st: "Balance/Change:",
                        no_of_items: "No of Items",
                        thank_you: "Thank You!",
                        thank_you_st: "Thank you for your business!"
                    },
                    si: {
                        inv_no: "බිල්පත් අංකය",
                        inv_no_st: "බිල්පත් අංකය:",
                        date_st: "දිනය:",
                        cashier: "අයකැමි",
                        cashier_st: "අයකැමි:",
                        customer: "පාරිභෝගිකයා",
                        customer_st: "පාරිභෝගිකයා",
                        item: "අයිතමය",
                        desc: "විස්තරය",
                        unit: "ඒකකය",
                        qty: "ප්‍රමාණය",
                        rate: "මිල",
                        unit_price: "ඒකක මිල",
                        amount: "වටිනාකම",
                        total: "එකතුව",
                        sub_total: "අනු එකතුව",
                        sub_total_st: "අනු එකතුව:",
                        discount: "වට්ටම",
                        discount_st: "මුළු වට්ටම:",
                        net_total: "ශුද්ධ එකතුව",
                        net_total_st: "මුළු එකතුව:",
                        payment: "ගෙවීම",
                        payment_st: "ලැබුණු මුදල:",
                        balance: "ඉතිරිය",
                        balance_st: "ඉතිරිය/මාරු මුදල:",
                        no_of_items: "අයිතම ගණන",
                        thank_you: "ස්තූතියි!",
                        thank_you_st: "අප සමඟ ගනුදෙනු කළාට ස්තූතියි!"
                    },
                    ta: {
                        inv_no: "பட்டியல் எண்",
                        inv_no_st: "பட்டியல் எண்:",
                        date_st: "தேதி:",
                        cashier: "காசாளர்",
                        cashier_st: "காசாளர்:",
                        customer: "வாடிக்கையாளர்",
                        customer_st: "வாடிக்கையாளர்:",
                        item: "பொருள்",
                        desc: "விளக்கம்",
                        unit: "அலகு",
                        qty: "அளவு",
                        rate: "விலை",
                        unit_price: "அலகு விலை",
                        amount: "தொகை",
                        total: "மொத்தம்",
                        sub_total: "உப மொத்தம்",
                        sub_total_st: "உப மொத்தம்:",
                        discount: "தள்ளுபடி",
                        discount_st: "மொத்த தள்ளுபடி:",
                        net_total: "நிகர மொத்தம்",
                        net_total_st: "முழு மொத்தம்:",
                        payment: "கட்டணம்",
                        payment_st: "பெறப்பட்ட தொகை:",
                        balance: "மீதி",
                        balance_st: "மீதி தொகை:",
                        no_of_items: "பொருட்களின் எண்ணிக்கை",
                        thank_you: "நன்றி!",
                        thank_you_st: "எங்களுடன் வியாபாரம் செய்ததற்கு நன்றி!"
                    }
                };

                // Apply dynamic translation
                function changeLanguage(lang) {
                    const elements = document.querySelectorAll('[data-i18n]');
                    elements.forEach(el => {
                        const key = el.getAttribute('data-i18n');
                        if (i18n[lang] && i18n[lang][key]) {
                            el.innerText = i18n[lang][key];
                        }
                    });
                }

                function initFormat() {
                    const selector = document.getElementById('receipt-format');
                    changeFormat(selector.value);
                }

                // Re-apply format on orientation/resize so mobile layout stays correct
                window.addEventListener('resize', () => {
                    const selector = document.getElementById('receipt-format');
                    changeFormat(selector.value);
                });

                function changeFormat(size) {
                    const thermalLayout = document.getElementById('thermal-layout');
                    const standardLayout = document.getElementById('standard-layout');
                    const isMobile = window.innerWidth <= 600;
                    
                    thermalLayout.style.display = 'none';
                    standardLayout.style.display = 'none';
                    
                    if (size === '80mm' || size === '58mm') {
                        thermalLayout.style.display = 'block';
                        // On mobile, fill available width; on desktop use actual thermal width
                        thermalLayout.style.width = isMobile ? '100%' : size;
                        thermalLayout.style.maxWidth = '100%';
                        // Match device and browser size automatically for user friendliness
                        thermalLayout.style.width = '100%';
                        thermalLayout.style.maxWidth = '800px';
                        thermalLayout.style.padding = '15px';
                        thermalLayout.style.fontSize = size === '58mm' ? '12px' : '15px';
                        thermalLayout.style.fontSize = size === '58mm' ? '14px' : '16px';
                        thermalLayout.style.boxShadow = '0 0 10px rgba(0,0,0,0.1)';
                    } else {
                        standardLayout.style.display = 'block';
                        // On mobile, always fluid; on desktop use paper width
                        if (isMobile) {
                            standardLayout.style.width = '100%';
                            standardLayout.style.maxWidth = '100%';
                        } else {
                            let width = '210mm'; // A4
                            if (size === 'A5')     width = '148mm';
                            if (size === 'Letter') width = '8.5in';
                            if (size === 'Legal')  width = '8.5in';
                            standardLayout.style.width = width;
                            standardLayout.style.maxWidth = '';
                        }
                        // Match device and browser size automatically for user friendliness
                        standardLayout.style.width = '100%';
                        standardLayout.style.maxWidth = '1000px';
                        standardLayout.style.boxShadow = '0 0 10px rgba(0,0,0,0.1)';
                    }
                }

                // Modern Share as PDF — using jsPDF + html2canvas directly (reliable & cross-browser)
                async function shareInvoice() {
                    const size = document.getElementById('receipt-format').value;
                    const isThermal = size === '80mm' || size === '58mm';
                    const layoutId = isThermal ? 'thermal-layout' : 'standard-layout';
                    const element = document.getElementById(layoutId);
                    const filename = 'Invoice_<?php echo esc_js($inv_number); ?>.pdf';

                    const btn = document.querySelector('button[onclick="shareInvoice()"]');
                    const origText = btn.innerText;
                    btn.innerText = 'Generating PDF...';
                    btn.disabled = true;

                    // Save original styles
                    const savedStyles = {
                        display:   element.style.display,
                        width:     element.style.width,
                        maxWidth:  element.style.maxWidth,
                        boxShadow: element.style.boxShadow,
                        margin:    element.style.margin,
                        position:  element.style.position,
                        left:      element.style.left,
                        top:       element.style.top,
                    };

                    // Determine pixel width for capture
                    let pxWidth = 794; // A4 default
                    if (size === '80mm')   pxWidth = 302;
                    else if (size === '58mm')  pxWidth = 219;
                    else if (size === 'A5')    pxWidth = 559;
                    else if (size === 'Letter' || size === 'Legal') pxWidth = 816;

                    // Prepare element off-screen for accurate capture
                    element.style.display   = 'block';
                    element.style.width     = pxWidth + 'px';
                    element.style.maxWidth  = 'none';
                    element.style.boxShadow = 'none';
                    element.style.margin    = '0';
                    element.style.position  = 'fixed';
                    element.style.left      = '-9999px';
                    element.style.top       = '0';

                    // Allow browser to reflow layout
                    await new Promise(r => setTimeout(r, 120));

                    try {
                        // Step 1: Render element to canvas
                        const canvas = await html2canvas(element, {
                            scale: 2,
                            useCORS: true,
                            allowTaint: true,
                            backgroundColor: '#ffffff',
                            windowWidth: pxWidth,
                            width: pxWidth,
                            height: element.scrollHeight,
                        });

                        // Restore element styles immediately after capture
                        Object.assign(element.style, savedStyles);

                        // Step 2: Build jsPDF document
                        const { jsPDF } = window.jspdf;

                        let pdfW_mm, pdfH_mm, orientation = 'p';

                        if (isThermal) {
                            const mmWidth = size === '80mm' ? 80 : 58;
                            const mmHeight = Math.ceil((canvas.height / canvas.width) * mmWidth) + 4;
                            pdfW_mm = mmWidth;
                            pdfH_mm = mmHeight;
                        } else {
                            const fmtMap = { A4: [210, 297], A5: [148, 210], Letter: [215.9, 279.4], Legal: [215.9, 355.6] };
                            [pdfW_mm, pdfH_mm] = fmtMap[size] || [210, 297];
                        }

                        const pdf = new jsPDF({ orientation, unit: 'mm', format: [pdfW_mm, pdfH_mm] });

                        const margin_mm = isThermal ? 1 : 8;
                        const contentW = pdfW_mm - margin_mm * 2;

                        // For standard pages: paginate if content is taller than one page
                        const imgData = canvas.toDataURL('image/jpeg', 0.97);
                        const imgRatio = canvas.height / canvas.width;
                        const imgH_mm = contentW * imgRatio;

                        if (!isThermal && imgH_mm > (pdfH_mm - margin_mm * 2)) {
                            // Multi-page: slice canvas into page-sized chunks
                            const pageContentH_mm = pdfH_mm - margin_mm * 2;
                            const pageContentH_px = (pageContentH_mm / contentW) * canvas.width;
                            let yOffset = 0;
                            let isFirstPage = true;

                            while (yOffset < canvas.height) {
                                if (!isFirstPage) pdf.addPage([pdfW_mm, pdfH_mm]);

                                const sliceH = Math.min(pageContentH_px, canvas.height - yOffset);
                                const sliceCanvas = document.createElement('canvas');
                                sliceCanvas.width  = canvas.width;
                                sliceCanvas.height = sliceH;
                                sliceCanvas.getContext('2d').drawImage(canvas, 0, -yOffset);

                                const sliceData = sliceCanvas.toDataURL('image/jpeg', 0.97);
                                const sliceH_mm = (sliceH / canvas.width) * contentW;
                                pdf.addImage(sliceData, 'JPEG', margin_mm, margin_mm, contentW, sliceH_mm);

                                yOffset += sliceH;
                                isFirstPage = false;
                            }
                        } else {
                            // Single page (thermal or short content)
                            pdf.addImage(imgData, 'JPEG', margin_mm, margin_mm, contentW, isThermal ? (pdfH_mm - margin_mm * 2) : imgH_mm);
                        }

                        // Step 3: Get PDF as Blob
                        const pdfBlob = pdf.output('blob');
                        const pdfFile = new File([pdfBlob], filename, { type: 'application/pdf' });

                        // Step 4: Try Web Share API first (mobile), fallback to download
                        let shared = false;
                        if (navigator.canShare && navigator.canShare({ files: [pdfFile] })) {
                            try {
                                await navigator.share({
                                    title: 'Invoice <?php echo esc_js($inv_number); ?>',
                                    text: 'Invoice from <?php echo esc_js($business_name); ?>',
                                    files: [pdfFile],
                                });
                                shared = true;
                            } catch (shareErr) {
                                if (shareErr.name !== 'AbortError') {
                                    console.warn('Share failed, falling back to download:', shareErr);
                                } else {
                                    // User cancelled share — still offer download
                                }
                            }
                        }

                        // Always download as well (or as primary if share not supported)
                        const blobUrl = URL.createObjectURL(pdfBlob);
                        const a = document.createElement('a');
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);

                    } catch (err) {
                        // Restore styles on error too
                        Object.assign(element.style, savedStyles);
                        console.error('PDF generation failed:', err);
                        alert('Failed to generate PDF. Please try again or use the Print button.');
                    } finally {
                        btn.innerText = origText;
                        btn.disabled = false;
                    }
                }
            </script>
        </body></html>
        <?php exit;
    }
});
                              