<?php
/**
 * Plugin Name: My Aggregator Plugin
 * Description: RSS-syötteen synkronointi ja työpaikkojen hallinta.
 * Version: 1.3.1
 * Author: Arto Huhta
 * Text Domain: my-aggregator-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ladataan muut tiedostot
require_once plugin_dir_path( __FILE__ ) . 'includes/admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cpt-and-cron.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sync-functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';

// === Aktivointi / deaktivointi -hookit ===
// Huom! On tärkeää, että nämä hookit ovat pluginin pääkooditiedostossa
register_activation_hook( __FILE__, 'map_activate_cron' );
register_deactivation_hook( __FILE__, 'map_deactivate_cron' );

// === Rekisteröi CSS ja JS fronttiin ===
function map_register_assets() {
    // Ladataan julkisen puolen CSS (my-job-list-tyylit), jos haluat
    $css_path = plugin_dir_path(__FILE__) . 'css/minun-aggregator-plugin.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style(
            'my-aggregator-css',
            plugins_url( 'css/minun-aggregator-plugin.css', __FILE__ ),
            array(),
            filemtime( $css_path )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'map_register_assets' );

// === Rekisteröi CSS ja JS admin-puolelle ===
function map_register_admin_assets( $hook ) {
    // Ladataan adminin CSS/JS vain, jos ollaan pluginin asetussivulla
    if ( 'toplevel_page_my-agg-settings' === $hook ) {
        // Admin-CSS
        $admin_css = plugin_dir_path(__FILE__) . 'css/admin-minun-aggregator-plugin.css';
        if ( file_exists( $admin_css ) ) {
            wp_enqueue_style(
                'admin-minun-aggregator-plugin-css',
                plugins_url( 'css/admin-minun-aggregator-plugin.css', __FILE__ ),
                array('wp-color-picker'),
                filemtime( $admin_css )
            );
        }

        // Admin-JS (valinnainen, jos haluat värivalitsimen yms.)
        $admin_js = plugin_dir_path(__FILE__) . 'js/admin-minun-aggregator-plugin.js';
        if ( file_exists( $admin_js ) ) {
            wp_enqueue_script(
                'admin-minun-aggregator-plugin-js',
                plugins_url( 'js/admin-minun-aggregator-plugin.js', __FILE__ ),
                array('wp-color-picker'),
                filemtime( $admin_js ),
                true
            );
        }
    }
}
add_action( 'admin_enqueue_scripts', 'map_register_admin_assets' );


// ============================================================================
// ===============  U U D E T  LISÄYKSET: BUILDER-SUOJA & CACHE  =============
// ============================================================================

/**
 * Tunnista sivunrakentajan (Elementor/Gutenberg tms.) editointitila.
 * Palauttaa true, jos ollaan builderissa (tällöin vältetään raskaat haut).
 */
if ( ! function_exists( 'map_is_builder_request' ) ) {
    function map_is_builder_request() {
        // WP admin -alueessa ei yleensä renderöidä fronttia, mutta Elementor voi käyttää admin-iframeä.
        if ( is_admin() ) {
            // Sallitaan kuitenkin pluginin oma asetussivu normaaliin käyttöön
            if ( isset($_GET['page']) && $_GET['page'] === 'my-agg-settings' ) {
                return false;
            }
            return true;
        }

        // Elementor-parametrit URL:ssa tai editorin tila
        if ( defined('ELEMENTOR_VERSION') ) {
            if ( isset($_GET['elementor-preview']) || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'elementor') ) {
                return true;
            }
            if ( class_exists('\Elementor\Plugin') ) {
                try {
                    $plugin = \Elementor\Plugin::$instance;
                    if ( $plugin && isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') ) {
                        if ( $plugin->editor->is_edit_mode() ) {
                            return true;
                        }
                    }
                } catch ( \Throwable $e ) {
                    // ignooraa – palataan oletukseen
                }
            }
        }

        // AJAX-esikatseluja on paljon, ei oleteta automaattisesti builderiksi
        return false;
    }
}

/**
 * Kohdelyhytkoodit, joita suojataan builderissa ja joille tehdään HTML-välimuisti.
 * Lisää tänne lyhytkoodit, joita lisäosa käyttää listauksen näyttämiseen.
 */
function map_target_shortcodes() {
    // Skannauksen perusteella lisäosa käyttää tagia 'my_jobs_list'
    return array( 'my_jobs_list' );
}

/**
 * Generoi välimuistin avaimen lyhytkoodin tulosteelle.
 * Sisältää myös "cache bump" -option, jonka sync päivittää (get_option('my_agg_cache_bump',0)).
 */
function map_jobs_cache_key( $tag, $atts ) {
    if ( is_array( $atts ) ) {
        ksort( $atts );
    }
    $bump = (int) get_option( 'my_agg_cache_bump', 0 );
    return 'map_jobs_html_' . md5( $tag . '|' . wp_json_encode( $atts ) . '|' . $bump );
}

/**
 * Ennen kuin WordPress suorittaa lyhytkoodin:
 * - Builderissa palautetaan kevyt placeholder (ei raskaita hakuja eikä ulkoisia pyyntöjä).
 * - Julkisella puolella kokeillaan ensin välimuistia.
 */
function map_prevent_heavy_shortcodes_in_builder( $return, $tag, $atts, $m ) {
    // Vain omat lyhytkoodit
    if ( ! in_array( $tag, map_target_shortcodes(), true ) ) {
        return $return;
    }

    // Builder: näytä kevyt esikatselu
    if ( function_exists( 'map_is_builder_request' ) && map_is_builder_request() ) {
        return '<div class="my-job-list my-job-list--placeholder" style="opacity:.7;">Avoimet työpaikat – esikatselu. Julkaisussa listaus näkyy normaalisti.</div>';
    }

    // Julkinen puoli: kokeile välimuistia ennen varsinaista renderöintiä
    $key     = map_jobs_cache_key( $tag, (array) $atts );
    $cached  = get_transient( $key );
    if ( false !== $cached ) {
        return $cached; // Palauta suoraan välimuistista
    }

    return $return; // Anna jatkua normaalille lyhytkoodille
}
add_filter( 'pre_do_shortcode_tag', 'map_prevent_heavy_shortcodes_in_builder', 10, 4 );

/**
 * Kun lyhytkoodi on ajettu: tallenna HTML välimuistiin (vain julkisessa näkymässä).
 */
function map_cache_shortcode_output( $output, $tag, $atts, $m ) {
    if ( ! in_array( $tag, map_target_shortcodes(), true ) ) {
        return $output;
    }
    if ( function_exists( 'map_is_builder_request' ) && map_is_builder_request() ) {
        return $output; // builderissa ei cacheteta (palautettiin jo placeholder)
    }

    $key = map_jobs_cache_key( $tag, (array) $atts );
    set_transient( $key, $output, 5 * MINUTE_IN_SECONDS ); // 5 min HTML-cache
    return $output;
}
add_filter( 'do_shortcode_tag', 'map_cache_shortcode_output', 10, 4 );

/**
 * RSS/HTTP-ajoasetukset: lyhyempi timeout ja kohtuullinen feed-välimuisti.
 * Tämä auttaa sekä julkisessa näkymässä että editorissa, jos feedi on hidas.
 */
function map_feed_set_timeout( $feed ) {
    if ( is_object( $feed ) && method_exists( $feed, 'set_timeout' ) ) {
        $feed->set_timeout( 7 ); // sekuntia
    }
}
add_action( 'wp_feed_options', 'map_feed_set_timeout' );

function map_feed_cache_lifetime( $seconds ) {
    // 30 min feed-välimuisti (SimplePie). Huom: ei sama kuin HTML-outputin transient.
    return 30 * MINUTE_IN_SECONDS;
}
add_filter( 'wp_feed_cache_transient_lifetime', 'map_feed_cache_lifetime' );