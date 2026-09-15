<?php
/**
 * Add-on Base Class
 * Abstract base class for all add-ons
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class RR_Addon_Base {

    protected $slug = '';
    protected $name = '';
    protected $description = '';
    protected $icon = 'dashicons-admin-plugins';

    public function __construct() {
        $this->init();
        $this->register();
    }

    abstract protected function init();
    abstract public function render_page();
    abstract public function get_stats();

    /**
     * Meld deze add-on aan bij de plugin.
     *
     * Rechtstreeks, niet via 'plugins_loaded': de add-ons worden sinds 1.9.0 op
     * 'init' geladen, en dan is die hook allang voorbij.
     */
    protected function register() {
        RankRepair::get_instance()->register_addon($this->slug, $this);
    }

    public function enqueue_assets($hook) {
        // Override in child classes
    }

    public function get_slug() {
        return $this->slug;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_description() {
        return $this->description;
    }

    public function get_icon() {
        return $this->icon;
    }

    protected function render_header($title = null, $subtitle = null) {
        ?>
        <div class="wrap rr-wrap">
            <div class="rr-header">
                <div class="rr-header-content">
                    <a href="<?php echo admin_url('admin.php?page=rankrepair'); ?>" class="rr-back-link">
                        <span class="dashicons dashicons-arrow-left-alt2"></span> <?php _e('Terug naar Dashboard', 'rankrepair'); ?>
                    </a>
                    <h1>
                        <span class="dashicons <?php echo esc_attr($this->icon); ?>"></span>
                        <?php echo esc_html($title ?? $this->name); ?>
                    </h1>
                    <?php if ($subtitle): ?>
                        <p class="rr-subtitle"><?php echo esc_html($subtitle); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php
    }

    protected function render_footer() {
        echo '</div>';
    }

    protected function render_notice($message, $type = 'info') {
        ?>
        <div class="rr-notice rr-notice-<?php echo esc_attr($type); ?>">
            <span class="dashicons dashicons-<?php echo $type === 'success' ? 'yes' : ($type === 'error' ? 'no' : ($type === 'warning' ? 'warning' : 'info')); ?>"></span>
            <?php echo wp_kses_post($message); ?>
        </div>
        <?php
    }
}
