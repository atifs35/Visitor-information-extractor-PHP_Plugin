<?php
/**
* Plugin Name: Visitor Tracker
* Plugin URI: http://yourwebsite.com/
* Description: This plugin will track visitors' information.
* Version: 1.0.0
* Author: Your Name
* Author URI: http://yourwebsite.com/
* License: GPL2
*/

require_once('vendor/autoload.php'); // Assuming you have used composer to install the geoip2 library

// Handle AJAX requests for logged-in users and non-logged-in users
add_action('wp_ajax_gather_visitor_data', 'gather_visitor_data_callback');
add_action('wp_ajax_nopriv_gather_visitor_data', 'gather_visitor_data_callback');

function gather_visitor_data_callback() {
    // This function will now be called when an AJAX request is sent to 'admin-ajax.php' with the 'action' parameter set to 'gather_visitor_data'
    // We can now call the gather_visitor_data() function to process the request
    gather_visitor_data();

    // Always return a JSON response and exit
    wp_send_json_success();
    wp_die();
}


// Create database table on plugin activation
register_activation_hook( __FILE__, 'create_plugin_database_table' );
function create_plugin_database_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'visitor_tracker';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        ip_address varchar(100) NOT NULL,
        user_agent text NOT NULL,
        page_url varchar(255) DEFAULT '' NOT NULL,
        country_origin varchar(255) DEFAULT '' NOT NULL,
        time_spent int(11) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";


    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

function visitor_tracker_enqueue_scripts() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js');
    wp_register_script('visitor_tracking', plugins_url('visitor-tracking.js', __FILE__), array(), '1.0', true);

    $script_data_array = array(
        'ajax_url' => admin_url('admin-ajax.php')
    );

    wp_localize_script('visitor_tracking', 'visitor_tracking_data', $script_data_array);

    wp_enqueue_script('visitor_tracking');
}

add_action('admin_enqueue_scripts', 'visitor_tracker_enqueue_scripts');

// Gather and store visitor data on each page load
add_action( 'template_redirect', 'gather_visitor_data' );
function gather_visitor_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'visitor_tracker';

    // Get visitor IP Address
    $user_ip = $_SERVER['REMOTE_ADDR'];

    // Get visitor User Agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'];

    // Get the current page URL
    $page_url = home_url(add_query_arg(NULL, NULL));

    // Get country of origin from IP Address
    if (in_array($user_ip, ['127.0.0.1', '::1'])) {
        // If running on localhost, set country origin as 'localhost'
        $country_origin = 'localhost';
    } else {
        // If not running on localhost, perform GeoIP lookup
        try {
            $reader = new \GeoIp2\Database\Reader(plugin_dir_path(__FILE__) . 'geoip/GeoLite2-Country.mmdb');
            $record = $reader->country($user_ip);
            $country_origin = $record->country->name;
        } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
            $country_origin = 'Unknown';
        } catch (Exception $e) {
            $country_origin = 'Error';
        }
    }

    // Get time spent from POST request (sent by JavaScript)
    $time_spent = isset($_POST['time_spent']) ? intval($_POST['time_spent']) : 0;

    // Handle unload and interval requests differently
    $type = isset($_POST['type']) ? $_POST['type'] : 'interval';
    if ($type === 'unload') {
        // If type is 'unload', it means the user is leaving the page. We need to update the time_spent for the last time and stop tracking.
        // Insert final data into the database
        $wpdb->insert(
            $table_name,
            array(
                'time' => current_time('mysql'),
                'ip_address' => $user_ip,
                'user_agent' => $user_agent,
                'page_url' => $page_url,
                'country_origin' => $country_origin,
                'time_spent' => $time_spent, // This will be the final time_spent.
            )
        );
    } else {
        // If type is 'interval', it means this is a regular update while the user is still on the page.
        // Update the time_spent in the database regularly
        $wpdb->insert(
            $table_name,
            array(
                'time' => current_time('mysql'),
                'ip_address' => $user_ip,
                'user_agent' => $user_agent,
                'page_url' => $page_url,
                'country_origin' => $country_origin,
                'time_spent' => $time_spent, // This will be the updated time_spent.
            )
        );
    }
}




// Create a new admin page
add_action('admin_menu', 'visitor_tracker_admin_menu');
function visitor_tracker_admin_menu() {
    add_menu_page(
        'Visitor Tracker',
        'Visitor Tracker',
        'manage_options',
        'visitor-tracker',
        'visitor_tracker_admin_page',
        'dashicons-chart-line',
        6
    );

    add_submenu_page(
        null, // this makes it hidden from the menu
        'Visitor IP Details',
        'Visitor IP Details',
        'manage_options',
        'visitor-tracker-ip-details',
        'visitor_tracker_ip_details_page'
    );
}

function get_most_viewed_pages_for_ip() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'visitor_tracker';

    // Get the most viewed pages for each IP
    $most_viewed_pages = $wpdb->get_results("
        SELECT ip_address, page_url, COUNT(*) AS page_views
        FROM $table_name
        GROUP BY ip_address, page_url
        ORDER BY ip_address, page_views DESC
    ");

    $most_viewed = array();

    foreach ($most_viewed_pages as $page) {
        // Get the page title from the URL
        $page_title = get_the_title(url_to_postid($page->page_url));
        // Concatenate the URL and the title
        $most_viewed[$page->ip_address][] = $page->page_url . ' (' . $page_title . ')';
    }

    // Return the array of most viewed pages
    return $most_viewed;
}

// Output the HTML for the admin page
function visitor_tracker_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'visitor_tracker';

    // Get visitor data from database, retrieving only the latest visit for each IP address
    $visitor_data = $wpdb->get_results("SELECT DISTINCT ip_address, MAX(time) AS latest_time FROM $table_name GROUP BY ip_address ORDER BY latest_time DESC");

    // Get most viewed pages for each IP
    $most_viewed_pages = get_most_viewed_pages_for_ip();

    echo '<div class="wrap">';
    echo '<h1>Visitor Tracker</h1>';

    // Display visitor data in a table
    echo '<table class="widefat">';
    echo '<thead>';
    echo '<tr><th>Time</th><th>IP Address</th><th>User Agent</th><th>Page URL</th><th>Country of Origin</th><th>Time Spent</th></tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($visitor_data as $visitor) {
        // Get the latest visit for the IP address
        $latest_visit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE ip_address = %s ORDER BY time DESC LIMIT 1", $visitor->ip_address));

        // Continue to the next iteration if no latest visit found
        if (!$latest_visit) {
            continue;
        }

        // Retrieve additional details for the latest visit
        $most_viewed = isset($most_viewed_pages[$latest_visit->ip_address]) ? implode('<br>', $most_viewed_pages[$latest_visit->ip_address]) : '';
        $time_spent = strtotime('now') - strtotime($latest_visit->time);

        // Display the latest visit entry
        echo '<tr>';
        echo '<td>' . $latest_visit->time . '</td>';
        echo '<td><a href="' . admin_url('admin.php?page=visitor-tracker-ip-details&ip=' . urlencode($latest_visit->ip_address)) . '">' . $latest_visit->ip_address . '</a></td>';
        echo '<td>' . $latest_visit->user_agent . '</td>';
        echo '<td>' . $latest_visit->page_url . '</td>';
        echo '<td>' . $latest_visit->country_origin . '</td>';
        echo '<td>' . $time_spent . ' seconds</td>';
       // echo '<td>' . $most_viewed . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}

function visitor_tracker_ip_details_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'visitor_tracker';

    // Get IP from query string
    $ip_address = isset($_GET['ip']) ? sanitize_text_field($_GET['ip']) : '';

    // Get visitor data for this IP
    $visitor_data = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE ip_address = %s ORDER BY time DESC", $ip_address));

    echo '<div class="wrap">';
    echo '<h1>Visitor Details for IP: ' . $ip_address . '</h1>';

    // Display visitor data in a table
    echo '<table class="widefat">';
    echo '<thead>';
    echo '<tr><th>Time</th><th>User Agent</th><th>Page URL</th><th>Country of Origin</th><th>Time Spent</th></tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($visitor_data as $visitor) {
        $time_spent = strtotime('now') - strtotime($visitor->time);
        echo '<tr>';
        echo '<td>' . $visitor->time . '</td>';
        echo '<td>' . $visitor->user_agent . '</td>';
        echo '<td>' . $visitor->page_url . '</td>';
        echo '<td>' . $visitor->country_origin . '</td>';
        echo '<td>' . $time_spent . ' seconds</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    // Prepare data for the chart
    $labels = array(); // This will hold the page URLs
    $data = array(); // This will hold the time spent on each page

    foreach ($visitor_data as $visitor) {
        $labels[] = $visitor->page_url;
        $data[] = $visitor->time_spent;
    }

    // Encode data for use in JavaScript
    $labels_json = json_encode($labels);
    $data_json = json_encode($data);

    // Display the chart
    ?>
    <canvas id="visitorChart"></canvas>
    <script>
        var ctx = document.getElementById('visitorChart').getContext('2d');
        var myChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo $labels_json; ?>,
                datasets: [{
                    label: 'Time Spent on Page (in seconds)',
                    data: <?php echo $data_json; ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
    <?php

    echo '</div>';
}



