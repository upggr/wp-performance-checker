<?php
/**
 * Plugin Name: WP Performance Checker
 * Description: Helps debug slow WordPress actions (like saving posts) by logging timings and query stats.
 * Version: 0.3.3
 * Author: Ioannis Kokkinis
 * Author URI: https://buy-it.gr
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin metadata.
if (!defined('WPPC_PLUGIN_VERSION')) {
    define('WPPC_PLUGIN_VERSION', '0.3.3');
}

/**
 * GitHub repo used for updates, in the form "owner/repo".
 *
 * Override this in wp-config.php if your public repo uses a different path.
 */
if (!defined('WPPC_GITHUB_REPO')) {
    define('WPPC_GITHUB_REPO', 'upggr/wp-performance-checker');
}

if (!defined('WPPC_PLUGIN_FILE')) {
    define('WPPC_PLUGIN_FILE', __FILE__);
}

// Basic safety: only load in admin area.
if (!is_admin()) {
    return;
}

class WP_Performance_Checker {
    private static $instance = null;

    /** @var array<string,float> */
    private $timers = [];

    /** @var string */
    private $log_file;

    /** @var bool */
    private $save_timings_enabled = true;

    /** @var array<string,mixed>|null */
    private $last_save_context = null;

    /** @var int Time from request start until save_post began (ms). */
    private $time_before_save_ms = 0;

    /**
     * Singleton bootstrap.
     */
    public static function init(): void {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    private function __construct() {
        // Default log file under wp-content/uploads for portability.
        $upload_dir     = wp_upload_dir();
        $log_dir        = trailingslashit($upload_dir['basedir']) . 'wp-performance-checker';
        if (!wp_mkdir_p($log_dir)) {
            $log_dir = WP_CONTENT_DIR;
        }
        $this->log_file = trailingslashit($log_dir) . 'performance.log';

        // Feature flag: save post timings (default: enabled).
        $option = get_option('wppc_save_post_timings_enabled', null);
        if ($option === null) {
            $this->save_timings_enabled = true;
        } else {
            $this->save_timings_enabled = (bool) $option;
        }

        // Global request timer.
        $this->start_timer('request');
        $this->start_timer('before_save'); // same start; stopped when save_post begins
        add_action('shutdown', [$this, 'log_request_summary'], PHP_INT_MAX);

        // Post save timing.
        add_action('save_post', [$this, 'before_save_post'], 0, 3);
        add_action('save_post', [$this, 'after_save_post'], PHP_INT_MAX, 3);

        // Simple tools page in Tools → Performance Checker.
        add_action('admin_menu', [$this, 'register_tools_page']);
    }

    private function start_timer(string $name): void {
        $this->timers[$name] = microtime(true);
    }

    private function stop_timer(string $name): float {
        if (!isset($this->timers[$name])) {
            return 0.0;
        }
        $elapsed = microtime(true) - $this->timers[$name];
        unset($this->timers[$name]);
        return $elapsed;
    }

    /**
     * Called at the very beginning of save_post.
     */
    public function before_save_post(int $post_ID, \WP_Post $post, bool $update): void {
        if (!$this->save_timings_enabled) {
            return;
        }

        // Ignore autosaves and revisions – we care about real user saves.
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
            return;
        }

        $this->time_before_save_ms = (int) round($this->stop_timer('before_save') * 1000);

        $key = "save_post_{$post_ID}";
        $this->start_timer($key);
    }

    /**
     * Called at the very end of save_post to log timing & query info.
     */
    public function after_save_post(int $post_ID, \WP_Post $post, bool $update): void {
        if (!$this->save_timings_enabled) {
            return;
        }

        // Ignore autosaves and revisions to match before_save_post().
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
            return;
        }

        $key     = "save_post_{$post_ID}";
        $elapsed = $this->stop_timer($key);

        $hook_time_ms = (int) round($elapsed * 1000);

        $context = [
            'time_ms'   => $hook_time_ms,
            'post_id'   => $post_ID,
            'type'      => $post->post_type,
            'status'    => $post->post_status,
            'is_update' => $update,
        ];

        // If SAVEQUERIES is enabled in wp-config.php, also log DB query count & total time.
        if (defined('SAVEQUERIES') && SAVEQUERIES && isset($GLOBALS['wpdb']) && is_array($GLOBALS['wpdb']->queries ?? null)) {
            $total_time = 0.0;
            $count      = count($GLOBALS['wpdb']->queries);

            foreach ($GLOBALS['wpdb']->queries as $q) {
                if (!empty($q[1])) {
                    $total_time += (float) $q[1];
                }
            }

            $context['db_queries']      = $count;
            $context['db_time_ms']      = (int) round($total_time * 1000);
            $context['avg_query_ms']    = $count > 0 ? (int) round(($total_time * 1000) / $count) : 0;
        }

        $this->log('save_post', $context);

        // Remember context so we can log end-to-end request time at shutdown.
        $this->last_save_context = [
            'post_id'       => $post_ID,
            'post_title'    => get_the_title($post_ID),
            'type'          => $post->post_type,
            'status'        => $post->post_status,
            'is_update'     => $update,
            'hook_time_ms'  => $hook_time_ms,
            'before_save_ms'=> $this->time_before_save_ms,
        ];
    }

    /**
     * Logs total admin request time on shutdown.
     */
    public function log_request_summary(): void {
        // Only log for admin-ajax.php, post.php, post-new.php to avoid noise.
        $script = isset($_SERVER['SCRIPT_NAME']) ? wp_basename((string) $_SERVER['SCRIPT_NAME']) : '';
        if (!in_array($script, ['admin-ajax.php', 'post.php', 'post-new.php'], true)) {
            return;
        }

        $elapsed = $this->stop_timer('request');
        $time_ms = (int) round($elapsed * 1000);
        $context = [
            'time_ms' => $time_ms,
            'script'  => $script,
            'method'  => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        ];

        if ($script === 'admin-ajax.php') {
            $context['ajax_action']  = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
            $context['request_uri']  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $context['referer']      = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        }

        // Always log the raw request timing.
        $this->log('request', $context);

        // If a save_post happened during this request and the feature is enabled,
        // also log a dedicated save_request entry with full end-to-end timing.
        if (
            $this->save_timings_enabled
            && !empty($this->last_save_context)
            && isset($_SERVER['REQUEST_METHOD'])
            && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST'
        ) {
            $save_context            = $this->last_save_context;
            $save_context['time_ms'] = $time_ms;
            $save_context['after_save_ms'] = max(0, $time_ms - (int) ($save_context['before_save_ms'] ?? 0) - (int) ($save_context['hook_time_ms'] ?? 0));

            $this->log('save_request', $save_context);
        }

        $this->last_save_context = null;
        $this->time_before_save_ms = 0;
    }

    /**
     * Get basic stats for the last N save events from the log.
     *
     * Prefers save_request entries (full request timing). Falls back to
     * save_post entries for backward compatibility.
     *
     * @param  int                     $limit
     * @return array<string,mixed>|null
     */
    private function get_recent_save_stats(int $limit = 10): ?array {
        if (!file_exists($this->log_file) || !is_readable($this->log_file)) {
            return null;
        }

        $lines = @file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return null;
        }

        // Try full save_request entries first (end-to-end timing).
        [$times, $latest] = $this->collect_save_event_data($lines, 'save_request', $limit);

        // Fallback to legacy save_post entries if needed.
        if (empty($times)) {
            [$times, $latest] = $this->collect_save_event_data($lines, 'save_post', $limit);
        }

        if (empty($times)) {
            return null;
        }

        $count = count($times);
        $sum   = array_sum($times);

        return [
            'count'      => $count,
            'average_ms' => (int) round($sum / $count),
            'min_ms'     => (int) min($times),
            'max_ms'     => (int) max($times),
            'items'      => $latest,
        ];
    }

    /**
     * Helper to extract recent save events for a given log event name.
     *
     * @param  array<int,string>         $lines
     * @param  string                    $event
     * @param  int                       $limit
     * @return array{0:array<int,int>,1:array<int,array<string,mixed>>}
     */
    private function collect_save_event_data(array $lines, string $event, int $limit): array {
        $times  = [];
        $latest = [];
        $needle = ' ' . $event . ' ';

        for ($i = count($lines) - 1; $i >= 0 && count($latest) < $limit; $i--) {
            $line = $lines[$i];
            if (strpos($line, $needle) === false) {
                continue;
            }

            $partsPos = strpos($line, $needle);
            if ($partsPos === false) {
                continue;
            }

            $json = substr($line, $partsPos + strlen($needle));
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['time_ms'])) {
                continue;
            }

            $timeMs   = (int) $data['time_ms'];
            $times[]  = $timeMs;
            $latest[] = [
                'time_ms'   => $timeMs,
                'post_id'   => isset($data['post_id']) ? (int) $data['post_id'] : 0,
                'type'      => isset($data['type']) ? (string) $data['type'] : '',
                'status'    => isset($data['status']) ? (string) $data['status'] : '',
                'is_update' => isset($data['is_update']) ? (bool) $data['is_update'] : false,
                'post_title'=> isset($data['post_title']) ? (string) $data['post_title'] : '',
            ];
        }

        return [$times, $latest];
    }

    /**
     * Get structured data for the last N save requests.
     *
     * Each item contains:
     *  - time (formatted "HH:MM dd/mm/YYYY")
     *  - post_id
     *  - post_title
     *  - total_ms
     *  - before_ms, hook_ms, after_ms when available
     *
     * @param  int                 $limit
     * @return array<int,array<string,mixed>>
     */
    private function get_recent_save_events(int $limit = 20): array {
        if (!file_exists($this->log_file) || !is_readable($this->log_file)) {
            return [];
        }

        $lines = @file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $result = [];
        $needle = ' save_request ';

        for ($i = count($lines) - 1; $i >= 0 && count($result) < $limit; $i--) {
            $line = $lines[$i];
            $pos  = strpos($line, $needle);
            if ($pos === false) {
                continue;
            }

            // Extract timestamp (first 19 chars within brackets).
            $timestamp = '';
            if (strlen($line) > 22 && $line[0] === '[') {
                $timestamp = substr($line, 1, 19);
            }

            $json = substr($line, $pos + strlen($needle));
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['time_ms'])) {
                continue;
            }

            $post_id    = isset($data['post_id']) ? (int) $data['post_id'] : 0;
            $post_title = isset($data['post_title']) ? (string) $data['post_title'] : '';
            $time_ms    = (int) $data['time_ms'];
            $before_ms  = isset($data['before_save_ms']) ? (int) $data['before_save_ms'] : null;
            $hook_ms    = isset($data['hook_time_ms']) ? (int) $data['hook_time_ms'] : null;
            $after_ms   = isset($data['after_save_ms']) ? (int) $data['after_save_ms'] : null;

            $display_time = $timestamp;
            if ($timestamp !== '') {
                try {
                    $dt = new DateTime($timestamp, new DateTimeZone('UTC'));
                    // Format as "HH:MM dd/mm/YYYY".
                    $display_time = $dt->format('H:i d/m/Y');
                } catch (Exception $e) {
                    // Keep raw timestamp on failure.
                }
            }

            $result[] = [
                'time'       => $display_time,
                'post_id'    => $post_id,
                'post_title' => $post_title,
                'total_ms'   => $time_ms,
                'before_ms'  => $before_ms,
                'hook_ms'    => $hook_ms,
                'after_ms'   => $after_ms,
            ];
        }

        return $result;
    }

    /**
     * Get recent admin-ajax requests (not just saves).
     *
     * Used to see which background Ajax calls are slow after a save.
     *
     * @param  int                 $limit
     * @return array<int,array<string,mixed>>
     */
    private function get_recent_ajax_events(int $limit = 20): array {
        if (!file_exists($this->log_file) || !is_readable($this->log_file)) {
            return [];
        }

        $lines = @file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $result = [];
        $needle = ' request ';

        for ($i = count($lines) - 1; $i >= 0 && count($result) < $limit; $i--) {
            $line = $lines[$i];
            $pos  = strpos($line, $needle);
            if ($pos === false) {
                continue;
            }

            // Extract timestamp (first 19 chars within brackets).
            $timestamp = '';
            if (strlen($line) > 22 && $line[0] === '[') {
                $timestamp = substr($line, 1, 19);
            }

            $json = substr($line, $pos + strlen($needle));
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['time_ms'])) {
                continue;
            }

            if (!isset($data['script']) || $data['script'] !== 'admin-ajax.php') {
                continue;
            }

            $time_ms = (int) $data['time_ms'];
            $time_s  = $time_ms / 1000;

            // Skip very fast or noisy requests (e.g. heartbeat) – we care about slow ones.
            $ajax_action = isset($data['ajax_action']) ? (string) $data['ajax_action'] : '';
            if ($time_s <= 0.5 && ($ajax_action === '' || $ajax_action === 'heartbeat')) {
                continue;
            }

            $display_time = $timestamp;
            if ($timestamp !== '') {
                try {
                    $dt = new DateTime($timestamp, new DateTimeZone('UTC'));
                    $display_time = $dt->format('H:i d/m/Y');
                } catch (Exception $e) {
                    // Keep raw timestamp.
                }
            }

            $request_uri = isset($data['request_uri']) ? (string) $data['request_uri'] : '';
            $referer     = isset($data['referer']) ? (string) $data['referer'] : '';

            $result[] = [
                'time'        => $display_time,
                'method'      => isset($data['method']) ? (string) $data['method'] : '',
                'action'      => $ajax_action,
                'request_uri' => $request_uri,
                'referer'     => $referer,
                'total_ms'    => $time_ms,
                'total_s'     => $time_s,
            ];
        }

        return $result;
    }

    /**
     * Append structured log line.
     *
     * @param string               $event
     * @param array<string,mixed>  $context
     */
    private function log(string $event, array $context): void {
        if (function_exists('wp_date')) {
            $ts = wp_date('Y-m-d H:i:s');
        } else {
            // Fallback to server time if wp_date is unavailable.
            $ts = date('Y-m-d H:i:s');
        }

        $line = sprintf(
            "[%s] %s %s\n",
            $ts,
            $event,
            wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @file_put_contents($this->log_file, $line, FILE_APPEND);
    }

    /**
     * Simple Tools page explaining usage and showing log path.
     */
    public function register_tools_page(): void {
        add_management_page(
            __('WP Performance Checker', 'wp-performance-checker'),
            __('Performance Checker', 'wp-performance-checker'),
            'manage_options',
            'wp-performance-checker',
            [$this, 'render_tools_page']
        );
    }

    public function render_tools_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'save_post_timings';

        // Handle form submissions (enable/disable + tests).
        $results      = null;
        $db_analysis  = null;
        if (
            isset($_POST['wp_performance_checker_action'], $_POST['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field((string) $_POST['_wpnonce']), 'wp_performance_checker_tools')
        ) {
            $action = sanitize_text_field((string) $_POST['wp_performance_checker_action']);
            if ($action === 'run_save_to_production_test') {
                $results = $this->run_save_to_production_test();
            } elseif ($action === 'toggle_save_post_timings') {
                $enable = isset($_POST['wppc_enable_save_timings']) && (int) $_POST['wppc_enable_save_timings'] === 1;
                $this->save_timings_enabled = $enable;
                update_option('wppc_save_post_timings_enabled', $enable ? 1 : 0, false);
            } elseif ($action === 'run_db_analysis') {
                $db_analysis = $this->run_db_analysis();
            }
        }

        $log_file         = $this->log_file;
        $exists           = file_exists($log_file);
        $filesize         = $exists ? filesize($log_file) : 0;
        $human_size       = size_format((float) $filesize);
        $save_stats       = $this->get_recent_save_stats(10);
        $save_events      = $this->get_recent_save_events(20);
        $ajax_events      = $this->get_recent_ajax_events(20);
        $save_enabled     = $this->save_timings_enabled;

        $base_url = admin_url('tools.php?page=wp-performance-checker');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WP Performance Checker', 'wp-performance-checker') . '</h1>';

        // Tabs for current and future tools.
        echo '<h2 class="nav-tab-wrapper">';
        $save_tab_url = add_query_arg('tab', 'save_post_timings', $base_url);
        $save_class   = $active_tab === 'save_post_timings' ? ' nav-tab-active' : '';
        echo '<a href="' . esc_url($save_tab_url) . '" class="nav-tab' . esc_attr($save_class) . '">';
        echo esc_html__('Save post timings', 'wp-performance-checker');
        echo '</a>';
        $db_tab_url = add_query_arg('tab', 'db_recommendations', $base_url);
        $db_class   = $active_tab === 'db_recommendations' ? ' nav-tab-active' : '';
        echo '<a href="' . esc_url($db_tab_url) . '" class="nav-tab' . esc_attr($db_class) . '">';
        echo esc_html__('DB recommendations', 'wp-performance-checker');
        echo '</a>';
        echo '</h2>';

        // Only one tab for now: Save post timings.
        if ($active_tab === 'save_post_timings') {
            echo '<h2>' . esc_html__('Save post timings', 'wp-performance-checker') . '</h2>';
            echo '<p>' . esc_html__(
                'This measures how long it takes from clicking “Save” on a post until WordPress finishes the request (server-side), which includes plugins, database work, and cache clearing.',
                'wp-performance-checker'
            ) . '</p>';

            // Enable/disable toggle.
            echo '<p>';
            echo esc_html__('Status:', 'wp-performance-checker') . ' ';
            echo $save_enabled
                ? '<strong style="color:green;">' . esc_html__('Enabled', 'wp-performance-checker') . '</strong>'
                : '<strong style="color:#a00;">' . esc_html__('Disabled', 'wp-performance-checker') . '</strong>';
            echo '</p>';

            echo '<form method="post" style="margin-bottom:1em;">';
            wp_nonce_field('wp_performance_checker_tools');
            echo '<input type="hidden" name="wp_performance_checker_action" value="toggle_save_post_timings" />';
            echo '<input type="hidden" name="wppc_enable_save_timings" value="' . ($save_enabled ? '0' : '1') . '" />';
            submit_button(
                $save_enabled
                    ? esc_html__('Disable save post timings', 'wp-performance-checker')
                    : esc_html__('Enable save post timings', 'wp-performance-checker'),
                $save_enabled ? 'secondary' : 'primary',
                'wp_performance_checker_toggle_save_timings',
                false
            );
            echo '</form>';

            // Log file information.
            echo '<h3>' . esc_html__('Log file', 'wp-performance-checker') . '</h3>';
            echo '<p><code>' . esc_html($log_file) . '</code></p>';

            if ($exists) {
                echo '<p>' . sprintf(
                    /* translators: %s: human-readable file size */
                    esc_html__('Current log size: %s', 'wp-performance-checker'),
                    esc_html($human_size)
                ) . '</p>';
            } else {
                echo '<p>' . esc_html__('Log file does not exist yet. It will be created after the first logged save.', 'wp-performance-checker') . '</p>';
            }

            // Average of last 10 saves.
            echo '<h3>' . esc_html__('Average of last 10 saves', 'wp-performance-checker') . '</h3>';
            if ($save_stats) {
                $count      = (int) $save_stats['count'];
                $average_ms = (int) $save_stats['average_ms'];
                $min_ms     = (int) $save_stats['min_ms'];
                $max_ms     = (int) $save_stats['max_ms'];

                $average_s = $average_ms / 1000;
                $min_s     = $min_ms / 1000;
                $max_s     = $max_ms / 1000;

                echo '<p>';
                echo esc_html(
                    sprintf(
                        /* translators: 1: count, 2: average s, 3: min s, 4: max s */
                        __('Last %1$d saves: average %.2f s (min %.2f s, max %.2f s).', 'wp-performance-checker'),
                        $count,
                        $average_s,
                        $min_s,
                        $max_s
                    )
                );
                echo '</p>';
            } else {
                echo '<p>' . esc_html__('No save data logged yet. Edit a post and click Save to start collecting data.', 'wp-performance-checker') . '</p>';
            }

            // Table with last 20 log entries.
            echo '<h3>' . esc_html__('Latest save log (last 20 entries)', 'wp-performance-checker') . '</h3>';
            if (!empty($save_events)) {
                echo '<p>' . esc_html__(
                    'Columns: time (HH:MM dd/mm/YYYY), post ID, title, total seconds. When available, before/hook/after show time until save started, inside save_post, and after save until the response is sent.',
                    'wp-performance-checker'
                ) . '</p>';

                echo '<style>
                    .wppc-row-fast td { background-color: #e6ffed; }
                    .wppc-row-medium td { background-color: #fff9e6; }
                    .wppc-row-slow td { background-color: #ffecec; }
                </style>';

                echo '<table class="widefat striped" style="max-width:100%;margin-top:0.5em;">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Time', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Post', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Total (s)', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Before (s)', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Hook (s)', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('After (s)', 'wp-performance-checker') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($save_events as $event) {
                    $time      = isset($event['time']) ? (string) $event['time'] : '';
                    $post_id   = isset($event['post_id']) ? (int) $event['post_id'] : 0;
                    $title     = isset($event['post_title']) ? (string) $event['post_title'] : '';
                    $total_ms  = isset($event['total_ms']) ? (int) $event['total_ms'] : 0;
                    $before_ms = $event['before_ms'] !== null ? (int) $event['before_ms'] : null;
                    $hook_ms   = $event['hook_ms'] !== null ? (int) $event['hook_ms'] : null;
                    $after_ms  = $event['after_ms'] !== null ? (int) $event['after_ms'] : null;

                    $total_s  = $total_ms / 1000;
                    $before_s = $before_ms !== null ? $before_ms / 1000 : null;
                    $hook_s   = $hook_ms !== null ? $hook_ms / 1000 : null;
                    $after_s  = $after_ms !== null ? $after_ms / 1000 : null;

                    // Simple colour coding based on total time.
                    $row_class = 'wppc-row-fast';
                    if ($total_ms > 3000) {
                        $row_class = 'wppc-row-slow';
                    } elseif ($total_ms > 1000) {
                        $row_class = 'wppc-row-medium';
                    }

                    echo '<tr class="' . esc_attr($row_class) . '">';
                    echo '<td>' . esc_html($time) . '</td>';

                    echo '<td>';
                    if ($post_id > 0) {
                        $edit_link = get_edit_post_link($post_id);
                        if ($edit_link) {
                            echo '<a href="' . esc_url($edit_link) . '">';
                            echo esc_html('#' . $post_id . ' – ' . $title);
                            echo '</a>';
                        } else {
                            echo esc_html('#' . $post_id . ' – ' . $title);
                        }
                    } else {
                        echo esc_html($title);
                    }
                    echo '</td>';

                    echo '<td>' . esc_html(number_format($total_s, 2)) . '</td>';
                    echo '<td>' . esc_html($before_s !== null ? number_format($before_s, 2) : '') . '</td>';
                    echo '<td>' . esc_html($hook_s !== null ? number_format($hook_s, 2) : '') . '</td>';
                    echo '<td>' . esc_html($after_s !== null ? number_format($after_s, 2) : '') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p>' . esc_html__('No save requests logged yet.', 'wp-performance-checker') . '</p>';
            }

            // Table with recent admin-ajax requests.
            echo '<h3>' . esc_html__('Recent admin-ajax requests (background work)', 'wp-performance-checker') . '</h3>';
            if (!empty($ajax_events)) {
                echo '<p>' . esc_html__(
                    'These are recent admin-ajax.php requests (for example SEO analysis, link suggestions, or other background tasks) that run around saves. Times are total seconds per request.',
                    'wp-performance-checker'
                ) . '</p>';

                echo '<table class="widefat striped" style="max-width:100%;margin-top:0.5em;">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Time', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Method', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Ajax action', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Source page', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Request URI', 'wp-performance-checker') . '</th>';
                echo '<th>' . esc_html__('Total (s)', 'wp-performance-checker') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($ajax_events as $event) {
                    $time        = isset($event['time']) ? (string) $event['time'] : '';
                    $method      = isset($event['method']) ? (string) $event['method'] : '';
                    $action      = isset($event['action']) ? (string) $event['action'] : '';
                    $referer     = isset($event['referer']) ? (string) $event['referer'] : '';
                    $request_uri = isset($event['request_uri']) ? (string) $event['request_uri'] : '';
                    $total_s     = isset($event['total_s']) ? (float) $event['total_s'] : 0.0;

                    $row_class = '';
                    if ($total_s > 3.0) {
                        $row_class = 'wppc-row-slow';
                    } elseif ($total_s > 1.0) {
                        $row_class = 'wppc-row-medium';
                    } else {
                        $row_class = 'wppc-row-fast';
                    }

                    echo '<tr class="' . esc_attr($row_class) . '">';
                    echo '<td>' . esc_html($time) . '</td>';
                    echo '<td>' . esc_html($method) . '</td>';
                    echo '<td>' . esc_html($action !== '' ? $action : '—') . '</td>';
                    echo '<td>' . esc_html($referer !== '' ? $referer : '—') . '</td>';
                    echo '<td>' . esc_html($request_uri !== '' ? $request_uri : '—') . '</td>';
                    echo '<td>' . esc_html(number_format($total_s, 2)) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p>' . esc_html__('No admin-ajax requests logged yet.', 'wp-performance-checker') . '</p>';
            }

            // Synthetic test still belongs to this tool.
            echo '<h3>' . esc_html__('Save-to-production timing test', 'wp-performance-checker') . '</h3>';
            echo '<p>' . esc_html__(
                'This creates or updates a lightweight test post and measures how long a full save takes on this production site (including all hooks).',
                'wp-performance-checker'
            ) . '</p>';

            if (is_array($results)) {
                echo '<div class="notice notice-info"><p>';
                echo esc_html(
                    sprintf(
                        /* translators: 1: time in ms, 2: peak memory in MB */
                        __('Save-to-production test: %1$d ms, peak memory ~%2$.1f MB.', 'wp-performance-checker'),
                        (int) $results['time_ms'],
                        (float) $results['peak_memory_mb']
                    )
                );
                if (!empty($results['errors']) && is_array($results['errors'])) {
                    echo '<br>';
                    echo esc_html__('Warnings:', 'wp-performance-checker') . ' ';
                    foreach ($results['errors'] as $msg) {
                        echo esc_html($msg) . ' ';
                    }
                }
                echo '</p></div>';
            }

            echo '<form method="post">';
            wp_nonce_field('wp_performance_checker_tools');
            echo '<input type="hidden" name="wp_performance_checker_action" value="run_save_to_production_test" />';
            submit_button(
                esc_html__('Run save-to-production timing test', 'wp-performance-checker'),
                'secondary',
                'wp_performance_checker_run_save_test',
                false
            );
            echo '</form>';
        } elseif ($active_tab === 'db_recommendations') {
            echo '<h2>' . esc_html__('Database recommendations', 'wp-performance-checker') . '</h2>';
            echo '<p>' . esc_html__(
                'This tool inspects your current WordPress database size and MySQL configuration and suggests high-level tuning values (for example InnoDB buffer pool size). You still need server access to actually change MySQL settings.',
                'wp-performance-checker'
            ) . '</p>';

            echo '<form method="post" style="margin-bottom:1em;">';
            wp_nonce_field('wp_performance_checker_tools');
            echo '<input type="hidden" name="wp_performance_checker_action" value="run_db_analysis" />';
            submit_button(
                esc_html__('Run database analysis', 'wp-performance-checker'),
                'primary',
                'wp_performance_checker_run_db_analysis',
                false
            );
            echo '</form>';

            if (is_array($db_analysis)) {
                $db_size_human     = isset($db_analysis['db_size_human']) ? (string) $db_analysis['db_size_human'] : '';
                $innodb_size_human = isset($db_analysis['innodb_buffer_pool_human']) ? (string) $db_analysis['innodb_buffer_pool_human'] : '';

                echo '<h3>' . esc_html__('Summary', 'wp-performance-checker') . '</h3>';
                echo '<ul>';
                if ($db_size_human !== '') {
                    echo '<li>' . sprintf(
                        /* translators: %s: database size */
                        esc_html__('Estimated database size: %s', 'wp-performance-checker'),
                        esc_html($db_size_human)
                    ) . '</li>';
                }
                if ($innodb_size_human !== '') {
                    echo '<li>' . sprintf(
                        /* translators: %s: InnoDB buffer pool size */
                        esc_html__('Current InnoDB buffer pool size: %s', 'wp-performance-checker'),
                        esc_html($innodb_size_human)
                    ) . '</li>';
                }
                echo '</ul>';

                if (!empty($db_analysis['recommendations']) && is_array($db_analysis['recommendations'])) {
                    echo '<h3>' . esc_html__('Recommendations', 'wp-performance-checker') . '</h3>';
                    echo '<ol>';
                    foreach ($db_analysis['recommendations'] as $rec) {
                        echo '<li>' . esc_html($rec) . '</li>';
                    }
                    echo '</ol>';
                }

                if (!empty($db_analysis['raw']) && is_array($db_analysis['raw'])) {
                    echo '<h3>' . esc_html__('Details', 'wp-performance-checker') . '</h3>';
                    echo '<pre style="max-height:300px;overflow:auto;">';
                    foreach ($db_analysis['raw'] as $line) {
                        echo esc_html($line) . "\n";
                    }
                    echo '</pre>';
                }
            } else {
                echo '<p>' . esc_html__(
                    'Click “Run database analysis” to gather current size and configuration information.',
                    'wp-performance-checker'
                ) . '</p>';
            }
        }

        echo '</div>';
    }

    /**
     * Inspect database size and relevant MySQL variables and produce
     * coarse-grained tuning suggestions.
     *
     * @return array<string,mixed>
     */
    private function run_db_analysis(): array {
        global $wpdb;

        $db_name = $wpdb->dbname;
        $recs    = [];
        $raw     = [];

        // Database size from information_schema.
        $db_size_bytes        = 0.0;
        $innodb_size_bytes    = 0.0;
        $myisam_size_bytes    = 0.0;
        $other_size_bytes     = 0.0;

        $size_sql = $wpdb->prepare(
            "
            SELECT ENGINE, SUM(DATA_LENGTH + INDEX_LENGTH) AS size_bytes
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = %s
            GROUP BY ENGINE
            ",
            $db_name
        );

        $engines = $wpdb->get_results($size_sql, ARRAY_A);
        if (is_array($engines)) {
            foreach ($engines as $row) {
                $engine = isset($row['ENGINE']) ? (string) $row['ENGINE'] : '';
                $size   = isset($row['size_bytes']) ? (float) $row['size_bytes'] : 0.0;

                $db_size_bytes += $size;

                if (strcasecmp($engine, 'InnoDB') === 0) {
                    $innodb_size_bytes += $size;
                } elseif (strcasecmp($engine, 'MyISAM') === 0) {
                    $myisam_size_bytes += $size;
                } else {
                    $other_size_bytes += $size;
                }
            }
        }

        $raw[] = 'Database name: ' . $db_name;
        $raw[] = 'Total DB size (approx): ' . size_format($db_size_bytes);
        $raw[] = 'InnoDB size (approx): ' . size_format($innodb_size_bytes);
        if ($myisam_size_bytes > 0) {
            $raw[] = 'MyISAM size (approx): ' . size_format($myisam_size_bytes);
        }

        // MySQL / MariaDB variables of interest.
        $vars_to_check = [
            'innodb_buffer_pool_size',
            'innodb_log_file_size',
            'innodb_buffer_pool_instances',
            'innodb_flush_log_at_trx_commit',
            'max_connections',
            'tmp_table_size',
            'max_heap_table_size',
        ];

        $placeholders = implode(',', array_fill(0, count($vars_to_check), '%s'));
        $vars_sql     = $wpdb->prepare(
            "SHOW VARIABLES WHERE Variable_name IN ($placeholders)",
            ...$vars_to_check
        );

        $vars     = $wpdb->get_results($vars_sql, ARRAY_A);
        $var_hash = [];
        if (is_array($vars)) {
            foreach ($vars as $row) {
                $name              = (string) $row['Variable_name'];
                $value             = (string) $row['Value'];
                $var_hash[$name]   = $value;
                $raw[]             = $name . ' = ' . $value;
            }
        }

        // Convert bytes-like values where applicable.
        $innodb_buffer_pool_size = isset($var_hash['innodb_buffer_pool_size']) ? (float) $var_hash['innodb_buffer_pool_size'] : 0.0;

        // Recommendations based on heuristic ratios.
        if ($innodb_buffer_pool_size > 0 && $db_size_bytes > 0) {
            $ratio = $innodb_buffer_pool_size / max($innodb_size_bytes ?: $db_size_bytes, 1.0);

            if ($ratio < 1.0) {
                $recs[] = sprintf(
                    /* translators: 1: buffer pool size, 2: DB size */
                    __('InnoDB buffer pool (%1$s) is smaller than your InnoDB data size (%2$s). Consider increasing innodb_buffer_pool_size so most of your data fits in memory.', 'wp-performance-checker'),
                    size_format($innodb_buffer_pool_size),
                    size_format($innodb_size_bytes ?: $db_size_bytes)
                );
            } elseif ($ratio > 3.0) {
                $recs[] = sprintf(
                    __('InnoDB buffer pool (%1$s) is much larger than your InnoDB data size (%2$s). If this is not a dedicated DB server, you may be able to reduce innodb_buffer_pool_size to free RAM.', 'wp-performance-checker'),
                    size_format($innodb_buffer_pool_size),
                    size_format($innodb_size_bytes ?: $db_size_bytes)
                );
            } else {
                $recs[] = __('InnoDB buffer pool size looks reasonable relative to your data size.', 'wp-performance-checker');
            }
        } else {
            $recs[] = __('Could not determine innodb_buffer_pool_size or database size; check MySQL permissions and version.', 'wp-performance-checker');
        }

        if ($myisam_size_bytes > 0) {
            $recs[] = __('Some tables are using MyISAM. For better crash safety and performance, consider converting them to InnoDB if possible.', 'wp-performance-checker');
        }

        if (isset($var_hash['tmp_table_size'], $var_hash['max_heap_table_size'])) {
            $tmp_size  = (float) $var_hash['tmp_table_size'];
            $heap_size = (float) $var_hash['max_heap_table_size'];
            $min_tmp   = min($tmp_size, $heap_size);

            if ($min_tmp < 16 * 1024 * 1024) {
                $recs[] = sprintf(
                    __('Temporary table limits are quite low (%s). Increasing tmp_table_size and max_heap_table_size can reduce on-disk temporary tables for complex queries.', 'wp-performance-checker'),
                    size_format($min_tmp)
                );
            }
        }

        if (isset($var_hash['innodb_flush_log_at_trx_commit'])) {
            if ($var_hash['innodb_flush_log_at_trx_commit'] === '1') {
                $recs[] = __('innodb_flush_log_at_trx_commit is 1 (safest). For write-heavy but less strict workloads you might consider 2 for a performance boost at the cost of potential data loss on power failure.', 'wp-performance-checker');
            } elseif ($var_hash['innodb_flush_log_at_trx_commit'] === '2') {
                $recs[] = __('innodb_flush_log_at_trx_commit is 2. This is a good compromise between safety and performance for many WordPress sites.', 'wp-performance-checker');
            }
        }

        return [
            'db_size_bytes'            => $db_size_bytes,
            'db_size_human'            => size_format($db_size_bytes),
            'innodb_size_bytes'        => $innodb_size_bytes,
            'innodb_size_human'        => size_format($innodb_size_bytes),
            'innodb_buffer_pool_bytes' => $innodb_buffer_pool_size,
            'innodb_buffer_pool_human' => $innodb_buffer_pool_size > 0 ? size_format($innodb_buffer_pool_size) : '',
            'recommendations'          => $recs,
            'raw'                      => $raw,
        ];
    }

    /**
     * Synthetic "save to production" test.
     *
     * Creates (or reuses) a lightweight test post cloned from the most recent
     * published post (if available) and measures the time it takes to perform
     * a real wp_update_post() on this installation, including all save_post
     * hooks and DB writes. The test post always stays in draft status and does
     * not affect the live site.
     *
     * @return array<string,mixed>
     */
    private function run_save_to_production_test(): array {
        $errors = [];

        $post_id = (int) get_option('wp_performance_checker_test_post_id', 0);
        $post    = $post_id ? get_post($post_id) : null;

        // Find latest published post to clone from, if any.
        $source_post = null;
        $sources     = get_posts(
            [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'fields'         => 'all',
            ]
        );
        if (!empty($sources) && $sources[0] instanceof \WP_Post) {
            $source_post = $sources[0];
        }

        $base_title   = $source_post ? $source_post->post_title . ' [WPPC test]' : 'WP Performance Checker Test Post';
        $base_content = $source_post ? $source_post->post_content : 'This is a synthetic test post used only for performance measurements.';

        if (!$post instanceof \WP_Post || $post->post_type !== 'post') {
            $post_id = wp_insert_post(
                [
                    'post_title'   => $base_title,
                    'post_status'  => 'draft',
                    'post_type'    => 'post',
                    'post_content' => $base_content,
                    // Always attribute the test post to the currently logged-in user.
                    'post_author'  => get_current_user_id() ?: 0,
                ],
                true
            );

            if (is_wp_error($post_id) || !$post_id) {
                $errors[] = __('Failed to create test post.', 'wp-performance-checker');

                return [
                    'time_ms'        => 0,
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
                    'errors'         => $errors,
                ];
            }

            update_option('wp_performance_checker_test_post_id', (int) $post_id, false);
        } else {
            // Ensure existing test post stays draft, roughly matches the source, and belongs to the current user.
            $update_args = [
                'ID'          => (int) $post->ID,
                'post_status' => 'draft',
                'post_author'  => get_current_user_id() ?: $post->post_author,
            ];

            if ($source_post) {
                $update_args['post_title']   = $base_title;
                $update_args['post_content'] = $base_content;
            }

            wp_update_post($update_args);
        }

        $start         = microtime(true);
        $before_memory = memory_get_usage(true);

        $update_result = wp_update_post(
            [
                'ID'           => (int) $post_id,
                'post_title'   => 'WP Performance Checker Test Post ' . gmdate('Y-m-d H:i:s'),
                'post_status'  => 'draft',
            ],
            true
        );

        $end          = microtime(true);
        $after_memory = memory_get_peak_usage(true);

        if (is_wp_error($update_result)) {
            $errors[] = sprintf(
                /* translators: %s: WP_Error message */
                __('wp_update_post failed: %s', 'wp-performance-checker'),
                $update_result->get_error_message()
            );
        }

        $elapsed_ms = (int) round(($end - $start) * 1000);

        $result = [
            'time_ms'        => $elapsed_ms,
            'peak_memory_mb' => round($after_memory / 1024 / 1024, 1),
            'errors'         => $errors,
        ];

        $this->log('test_save_to_production', $result);

        return $result;
    }
}

add_action('plugins_loaded', ['WP_Performance_Checker', 'init']);

/**
 * Lightweight GitHub-based updater so the plugin can update itself
 * from its public repository.
 */
class WP_Performance_Checker_Updater {
    /** @var string */
    private $repo;

    /** @var string */
    private $plugin_basename;

    /** @var string */
    private $slug;

    public function __construct() {
        $this->repo           = (string) WPPC_GITHUB_REPO;
        $this->plugin_basename = plugin_basename(WPPC_PLUGIN_FILE);
        $this->slug           = dirname($this->plugin_basename);

        if (empty($this->repo)) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
    }

    /**
     * Check GitHub for a newer release and tell WordPress about it.
     *
     * @param  stdClass $transient
     * @return stdClass
     */
    public function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $current_version = (string) WPPC_PLUGIN_VERSION;
        $release         = $this->get_latest_tag();

        if (!$release || empty($release['tag_name'])) {
            return $transient;
        }

        $remote_version = ltrim((string) $release['tag_name'], 'v');
        if (version_compare($remote_version, $current_version, '<=')) {
            return $transient;
        }

        $package = sprintf(
            'https://github.com/%s/archive/refs/tags/%s.zip',
            rawurlencode($this->repo),
            rawurlencode($release['tag_name'])
        );

        $update              = new stdClass();
        $update->slug        = $this->slug;
        $update->plugin      = $this->plugin_basename;
        $update->new_version = $remote_version;
        $update->url         = sprintf('https://github.com/%s', $this->repo);
        $update->package     = $package;

        $transient->response[$this->plugin_basename] = $update;

        return $transient;
    }

    /**
     * Provide basic plugin information for the "View details" modal.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $info              = new stdClass();
        $info->name        = 'WP Performance Checker';
        $info->slug        = $this->slug;
        $info->version     = (string) WPPC_PLUGIN_VERSION;
        $info->author      = '<a href="https://buy-it.gr">Ioannis Kokkinis</a>';
        $info->homepage    = sprintf('https://github.com/%s', $this->repo);
        $info->download_link = sprintf(
            'https://github.com/%s/archive/refs/tags/v%s.zip',
            rawurlencode($this->repo),
            rawurlencode((string) WPPC_PLUGIN_VERSION)
        );
        $info->sections    = [
            'description' => __('Performance diagnostics plugin created by Ioannis Kokkinis (buy-it.gr).', 'wp-performance-checker'),
        ];

        return $info;
    }

    /**
     * Fetch latest tag metadata from GitHub.
     *
     * @return array<string,mixed>|null
     */
    private function get_latest_tag(): ?array {
        $url = sprintf('https://api.github.com/repos/%s/tags', $this->repo);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'wp-performance-checker',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data[0]) || !is_array($data[0])) {
            return null;
        }

        // Use the first tag in the list as the latest.
        return [
            'tag_name' => $data[0]['name'] ?? null,
        ];
    }
}

// Only run the updater in the admin on sites that support HTTP requests.
if (is_admin()) {
    add_action('admin_init', static function (): void {
        if (function_exists('wp_remote_get')) {
            new WP_Performance_Checker_Updater();
        }
    });
}

