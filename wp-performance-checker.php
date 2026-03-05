<?php
/**
 * Plugin Name: WP Performance Checker
 * Description: Helps debug slow WordPress actions (like saving posts) by logging timings and query stats.
 * Version: 0.1.0
 * Author: Zante Times Tools
 */

if (!defined('ABSPATH')) {
    exit;
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

        // Global request timer.
        $this->start_timer('request');
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
        $key = "save_post_{$post_ID}";
        $this->start_timer($key);
    }

    /**
     * Called at the very end of save_post to log timing & query info.
     */
    public function after_save_post(int $post_ID, \WP_Post $post, bool $update): void {
        $key     = "save_post_{$post_ID}";
        $elapsed = $this->stop_timer($key);

        $context = [
            'time_ms'   => (int) round($elapsed * 1000),
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
        $context = [
            'time_ms' => (int) round($elapsed * 1000),
            'script'  => $script,
            'method'  => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        ];

        $this->log('request', $context);
    }

    /**
     * Append structured log line.
     *
     * @param string               $event
     * @param array<string,mixed>  $context
     */
    private function log(string $event, array $context): void {
        $line = sprintf(
            "[%s] %s %s\n",
            gmdate('Y-m-d H:i:s'),
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

        $log_file   = $this->log_file;
        $exists     = file_exists($log_file);
        $filesize   = $exists ? filesize($log_file) : 0;
        $human_size = size_format((float) $filesize);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WP Performance Checker', 'wp-performance-checker') . '</h1>';
        echo '<p>' . esc_html__('This plugin logs how long post saves and admin requests take, so you can see where the slowness comes from.', 'wp-performance-checker') . '</p>';

        echo '<h2>' . esc_html__('Log file', 'wp-performance-checker') . '</h2>';
        echo '<p><code>' . esc_html($log_file) . '</code></p>';

        if ($exists) {
            echo '<p>' . sprintf(
                /* translators: %s: human-readable file size */
                esc_html__('Current log size: %s', 'wp-performance-checker'),
                esc_html($human_size)
            ) . '</p>';
        } else {
            echo '<p>' . esc_html__('Log file does not exist yet. It will be created after the first logged request.', 'wp-performance-checker') . '</p>';
        }

        echo '<h2>' . esc_html__('How to use', 'wp-performance-checker') . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__('Install and activate this plugin on the site that feels slow.', 'wp-performance-checker') . '</li>';
        echo '<li>' . esc_html__('Optional: in wp-config.php, enable SAVEQUERIES to also log DB query stats:', 'wp-performance-checker') . '</li>';
        echo '<li><code>define( \'SAVEQUERIES\', true );</code></li>';
        echo '<li>' . esc_html__('Edit a post and click Save a few times.', 'wp-performance-checker') . '</li>';
        echo '<li>' . esc_html__('Download and inspect the performance.log file to see timings for save_post and the whole request.', 'wp-performance-checker') . '</li>';
        echo '</ol>';

        echo '</div>';
    }
}

add_action('plugins_loaded', ['WP_Performance_Checker', 'init']);

