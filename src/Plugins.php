<?php

declare(strict_types=1);

namespace TheFrosty\WpMultiNetworkPlugins;

use Symfony\Component\HttpFoundation\Request;
use function __;
use function _n;
use function _x;
use function add_action;
use function add_filter;
use function apply_filters;
use function constant;
use function defined;
use function delete_site_transient;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function filter_var;
use function get_admin_url;
use function get_blog_details;
use function get_blog_option;
use function get_site_transient;
use function get_sites;
use function in_array;
use function is_plugin_active_for_network;
use function printf;
use function sanitize_html_class;
use function sanitize_key;
use function set_site_transient;
use function sprintf;
use function wp_add_inline_script;
use function wp_enqueue_script;
use function wp_kses;
use function wp_register_script;
use function wp_unslash;
use const FILTER_VALIDATE_BOOLEAN;
use const WEEK_IN_SECONDS;

/**
 * Class Plugins
 * @package TheFrosty\WpMultiNetworkPlugins
 */
class Plugins
{
    public const array ALLOWED_PLUGIN_STATUS = ['all', 'active', 'inactive'];
    public const string COLUMN = 'active_sites';
    public const string TRANSIENT = 'blogs_plugins';
    public const array WP_KSES_ALLOWED_HTML = [
        'br' => [],
        'span' => [
            'class' => [],
        ],
        'ul' => [
            'class' => [],
            'id' => [],
            'style' => [],
        ],
        'li' => [
            'class' => [],
            'title' => [],
        ],
        'a' => [
            'href' => [],
            'onclick' => [],
            'title' => [],
        ],
        'p' => [
            'data-toggle-id' => [],
            'onclick' => [],
            'style' => [],
        ],
    ];
    /**
     * Value to get sites in the Network.
     * @var int $sites_limit
     */
    private int $sites_limit = 10000;
    /**
     * Member variable to store data about active plugins for each blog.
     * @var array<int, array<string, mixed>> $blogs_plugins
     */
    private array $blogs_plugins;

    /**
     * Plugins constructor.
     * @param Request $request
     */
    public function __construct(protected Request $request)
    {
        $this->sites_limit = (int)apply_filters('wp_multi_network_sites_limit', $this->sites_limit);
    }

    /**
     * Clears the site transient.
     */
    public static function deleteSiteTransient(): void
    {
        delete_site_transient(self::TRANSIENT);
    }

    /**
     * Initialize the class.
     */
    public function addHooks(): void
    {
        add_action('activated_plugin', [$this, 'activatedDeactivated']);
        add_action('deactivated_plugin', [$this, 'activatedDeactivated']);
        add_action('load-plugins.php', function (): void {
            if (!$this->isDebug()) {
                return;
            }

            self::deleteSiteTransient();
            add_action('network_admin_notices', [$this, 'noticeAboutClearCache']);
        });
        add_filter('manage_plugins-network_columns', [$this, 'addPluginsColumn']);
        add_action('manage_plugins_custom_column', [$this, 'managePluginsCustomColumn'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
    }

    /**
     * On plugin Activation/Deactivation, force refresh the cache.
     */
    public function activatedDeactivated(): void
    {
        add_action('shutdown', function (): void {
            $this->getSitesPlugins(true);
        });
    }

    /**
     * Print Network Admin Notices to inform, that the transient are deleted.
     */
    public function noticeAboutClearCache(): void
    {
        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html__(
                'WP Multi Network: plugin usage information is not cached while `WP_DEBUG` is true.',
                'wp-multi-network-plugins'
            )
        );
    }

    /**
     * Add in a column header.
     * @param array<string, string> $columns An array of displayed site columns.
     * @return array<string, string>
     */
    public function addPluginsColumn(array $columns): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ($this->request->query->has('plugin_status')) {
            $status = esc_attr(wp_unslash(sanitize_key($this->request->query->get('plugin_status'))));
        }

        if (
            !$this->request->query->has('plugin_status') ||
            (isset($status) && in_array($status, self::ALLOWED_PLUGIN_STATUS, true))
        ) {
            $columns[self::COLUMN] = _x('Usage', 'column name', 'wp-multi-network-plugins');
        } // phpcs:enable

        return $columns;
    }

    /**
     * Get data for each row on each plugin.
     * @param string $column_name Name of the column.
     * @param string $plugin_file Path to the plugin file.
     */
    public function managePluginsCustomColumn(string $column_name, string $plugin_file): void
    {
        if ($column_name !== self::COLUMN) {
            return;
        }

        $output = '';
        if (is_plugin_active_for_network($plugin_file)) {
            $output .= sprintf(
                '<span style="white-space:nowrap">%s</span>',
                esc_html__('Network Active', 'wp-multi-network-plugins')
            );
        } else {
            // Is this plugin active on any sites in this network.
            $active_on_blogs = $this->isPluginActiveOnSites($plugin_file);
            if (empty($active_on_blogs)) {
                $output .= sprintf(
                    '<span style="white-space:nowrap">%s</span>',
                    esc_html__('Not Active', 'wp-multi-network-plugins')
                );
            } else {
                $active_count = count($active_on_blogs);

                $output .= sprintf(
                    // phpcs:disable Generic.Files.LineLength.TooLong
                    '<p data-toggle-id="siteslist_%2$s" onclick="toggleSiteList(this);" style="cursor: pointer"><span class="dashicons dashicons-arrow-right">&nbsp;</span>%1$s</p>',
                    sprintf(
                    // Translators: The placeholder will be replaced by the count of sites there use that plugin.
                        _n('Active on %d site', 'Active on %d sites', $active_count, 'wp-multi-network-plugins'),
                        $active_count
                    ),
                    esc_attr($plugin_file)
                );
                $output .= sprintf(
                    '<ul id="siteslist_%s" class="siteslist" style="display: %s">',
                    esc_attr($plugin_file),
                    $active_count >= 4 ? 'none' : 'block'
                );
                foreach ($active_on_blogs as $site_id => $site) {
                    if ($this->isArchived($site_id)) {
                        $class = 'site-archived';
                        $hint = ', ' . __('Archived', 'wp-multi-network-plugins');
                    }
                    if ($this->isDeleted($site_id)) {
                        $class = 'site-deleted';
                        $hint = ', ' . __('Deleted', 'wp-multi-network-plugins');
                    }
                    $output .= sprintf(
                    // phpcs:disable Generic.Files.LineLength.TooLong
                        '<li class="%1$s" title="Blog ID: %2$s"><span class="non-breaking"><a href="%3$s">%4$s%5$s</a></span></li>',
                        sanitize_html_class($class ?? ''),
                        esc_attr((string)$site_id),
                        esc_url(get_admin_url($site_id, 'plugins.php')),
                        esc_html($site['name']),
                        esc_html($hint ?? '')
                    );
                }
                $output .= '</ul>';
            }
        }

        echo wp_kses($output, self::WP_KSES_ALLOWED_HTML);
    }

    /**
     * Enqueue our script(s).
     * @param string $hook_suffix The current admin page.
     */
    public function adminEnqueueScripts(string $hook_suffix): void
    {
        if ($hook_suffix !== 'plugins.php') {
            return;
        }

        wp_register_script('wp-multi-network-plugins', '', args: ['in_footer' => true]); // phpcs:ignore
        wp_enqueue_script('wp-multi-network-plugins');
        $script = <<<'SCRIPT'
document.addEventListener( 'DOMContentLoaded', function() {
  const sitesLists = document.querySelectorAll(`[id^="siteslist_"]`);
  if (sitesLists) {
    sitesLists.forEach(sitesList => {
      sitesList.style.display = 'none';
    })
  }
});
function toggleSiteList(plugin) {
  const id = plugin.getAttribute('data-toggle-id');
  if (!id) return;
  const element = document.getElementById(id);
  if (!element) return;
  const child = plugin.firstElementChild;
  if (child.classList.contains('dashicons-arrow-right')) {
    child.classList.remove('dashicons-arrow-right');
    child.classList.add('dashicons-arrow-down');
  } else {
    child.classList.remove('dashicons-arrow-down');
    child.classList.add('dashicons-arrow-right');
  }
  if (element.style.display === 'none') {
    element.style.display = 'block';
  } else {
    element.style.display = 'none';
  }
}
SCRIPT;
        wp_add_inline_script('wp-multi-network-plugins', $script);
    }

    /**
     * Is plugin active in site(s).
     * @param string $plugin_file The plugin file.
     * @return array<int, array<string, string>>
     */
    protected function isPluginActiveOnSites(string $plugin_file): array
    {
        $blogs_plugins_data = $this->getSitesPlugins();
        $active_in_plugins = [];
        foreach ($blogs_plugins_data as $blog_id => $data) {
            if (!in_array($plugin_file, $data['active_plugins'], true)) {
                continue;
            }
            $active_in_plugins[$blog_id] = [
                'name' => $data['blogname'],
                'path' => $data['path'],
            ];
        }

        return $active_in_plugins;
    }

    /**
     * Gets an array of site data including active plugins.
     * @param bool $force Force re-cache.
     * @return array<int, array<string, mixed>>
     */
    protected function getSitesPlugins(bool $force = false): array
    {
        if (!empty($this->blogs_plugins)) {
            return $this->blogs_plugins;
        }

        $blogs_plugins = get_site_transient(self::TRANSIENT);
        if ($force || $blogs_plugins === false) {
            $blogs_plugins = [];

            $blogs = get_sites(['number' => $this->sites_limit]);

            /**
             * Data to each site of the network, blogs.
             * @var \WP_Site $blog
             */
            foreach ($blogs as $blog) {
                $blog_id = (int)$blog->blog_id;

                $blogs_plugins[$blog_id] = $blog->to_array();
                // Add dynamic properties.
                $blogs_plugins[$blog_id]['blogname'] = $blog->blogname;
                $blogs_plugins[$blog_id]['active_plugins'] = [];
                // Get active plugins.
                $plugins = get_blog_option($blog_id, 'active_plugins');
                if ($plugins) {
                    foreach ($plugins as $plugin_file) {
                        $blogs_plugins[$blog_id]['active_plugins'][] = $plugin_file;
                    }
                }
            }

            if (!$this->isDebug()) {
                if ($force) {
                    self::deleteSiteTransient();
                }
                set_site_transient(self::TRANSIENT, $blogs_plugins, WEEK_IN_SECONDS);
            }
            $this->blogs_plugins = $blogs_plugins;
        }

        // Data should be here, if loaded from transient or DB.
        return $this->blogs_plugins;
    }

    /**
     * Check, if the status of the site archived.
     * @param int|string $site_id ID of the site.
     * @return bool
     */
    private function isArchived(int|string $site_id): bool
    {
        return (bool)get_blog_details((int)$site_id)->archived;
    }

    /**
     * Check, if the status of the site deleted.
     * @param int|string $site_id ID of the site.
     * @return bool
     */
    private function isDeleted(int|string $site_id): bool
    {
        return (bool)get_blog_details((int)$site_id)->deleted;
    }

    /**
     * Is WP_DEBUG enabled or are we filtering debug?
     */
    private function isDebug(): bool
    {
        $debug = (
            defined('WP_DEBUG') &&
            filter_var(constant('WP_DEBUG'), FILTER_VALIDATE_BOOLEAN) === true
        );
        return apply_filters('wp_multi_network_debug', $debug) === true;
    }
}
