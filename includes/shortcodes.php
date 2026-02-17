<?php
if (!defined('ABSPATH')) {
    exit;
}

// Rekisteröi lyhytkoodi
add_shortcode('my_jobs_list', 'map_jobs_list_shortcode');

/**
 * Lyhytkoodin logiikka
 *
 * @param array $atts Lyhytkoodin attribuutit
 * @return string Työpaikkalistaus HTML-muodossa
 */
function map_jobs_list_shortcode($atts) {
    // Lataa modal-tyylit ja skriptit
    map_enqueue_modal_assets();

    // Hae nykyinen kieli
    $current_lang = map_get_current_lang();

    // Hae asetukset
    $opts = my_agg_get_settings();

    // Lyhytkoodin attribuuttien oletukset
    $args = shortcode_atts(array(
        'import' => 'no', // Oletus: ei pakotettua tuontia
    ), $atts);

    // Pakota RSS-syötteen synkronointi, jos `import="yes"`
    if (strtolower($args['import']) === 'yes') {
        map_sync_feed();
    }

    // Hae työpaikat Custom Post Type -tietokannasta
    $query_args = array(
        'post_type'      => 'avoimet_tyopaikat',
        'post_status'    => 'publish',
        'posts_per_page' => $opts['items_count'], // Asetuksista haettu määrä
        'orderby'        => $opts['order_by'],    // Asetuksista haettu järjestyskenttä
        'order'          => $opts['order'],       // Asetuksista haettu järjestyssuunta
    );

    $query = new WP_Query($query_args);

    // Jos ei löydy yhtään työpaikkaa
    if (!$query->have_posts()) {
        return '<p>' . esc_html( map_i18n( 'jobs.no_jobs', $current_lang ) ) . '</p>';
    }

    // Validoi värit regex-tarkistuksella
    $link_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $opts['link_color']) ? $opts['link_color'] : '#000000';
    $link_hover_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $opts['link_hover_color']) ? $opts['link_hover_color'] : '#ff0000';
    $description_text_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $opts['description_text_color']) ? $opts['description_text_color'] : '#666666';

    // Käytä wp_add_inline_style inline-tyylien sijaan
    $inline_css = "
        .my-job-list { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
        }
        .my-job-list li { 
            margin-bottom: 20px; 
            padding-bottom: 10px; 
            border-bottom: 1px solid rgba(0, 0, 0, 0.25); 
        }
        .my-job-list a { 
            color: {$link_color}; 
            text-decoration: none; 
            font-weight: bold; 
            font-size: 18px; 
        }
        .my-job-list a:hover { 
            color: {$link_hover_color}; 
            text-decoration: none; 
        }
        .my-job-list .description { 
            color: {$description_text_color}; 
            font-size: 0.8rem; 
            font-weight: 300; 
            margin-top: 5px; 
        }
    ";
    wp_add_inline_style( 'my-aggregator-css', $inline_css );

    $output = '<ul class="my-job-list">';
    while ($query->have_posts()) {
        $query->the_post();

        $job_id  = get_the_ID();
        $title   = get_the_title();
        $link    = get_post_meta($job_id, 'original_rss_link', true);
        $excerpt = get_the_excerpt();

        // Tarkista onko infopaketti saatavilla
        $has_infopackage = map_resolve_infopackage($job_id, $current_lang);

        $output .= '<li>';
        if ($link) {
            // Add data-job-id to ALL links, badge only if infopackage exists
            $output .= '<a href="' . esc_url($link) . '" data-job-id="' . esc_attr($job_id) . '">';
            $output .= esc_html($title);
            if ($has_infopackage) {
                $output .= '<span class="map-info-badge">' . esc_html( map_i18n( 'modal.info_badge', $current_lang ) ) . '</span>';
            }
            $output .= '</a>';
        } else {
            // Jos linkkiä ei ole, näytetään pelkkä otsikko
            $output .= esc_html($title);
        }

        if ($excerpt) {
            $output .= '<div class="description">' . esc_html($excerpt) . '</div>';
        }

        $output .= '</li>';
    }
    $output .= '</ul>';

    wp_reset_postdata(); // Palautetaan WP:n query-tila

    return $output;
}

/**
 * Lataa modal-tyylit ja skriptit
 */
function map_enqueue_modal_assets() {
    // Varmista että perus-CSS on enqueued
    $base_css_path = plugin_dir_path( dirname( __FILE__ ) ) . 'css/minun-aggregator-plugin.css';
    if ( file_exists( $base_css_path ) && ! wp_style_is( 'my-aggregator-css', 'enqueued' ) ) {
        wp_enqueue_style(
            'my-aggregator-css',
            plugins_url( 'css/minun-aggregator-plugin.css', dirname( __FILE__ ) ),
            array(),
            filemtime( $base_css_path )
        );
    }

    // Lataa modal CSS
    $modal_css_path = plugin_dir_path( dirname( __FILE__ ) ) . 'css/modal-infopackage.css';
    if ( file_exists( $modal_css_path ) ) {
        wp_enqueue_style(
            'map-modal-infopackage',
            plugins_url( 'css/modal-infopackage.css', dirname( __FILE__ ) ),
            array(),
            filemtime( $modal_css_path )
        );
    }

    // Lataa modal JS
    $modal_js_path = plugin_dir_path( dirname( __FILE__ ) ) . 'js/frontend-modal.js';
    if ( file_exists( $modal_js_path ) ) {
        wp_enqueue_script(
            'map-modal-infopackage',
            plugins_url( 'js/frontend-modal.js', dirname( __FILE__ ) ),
            array(),
            filemtime( $modal_js_path ),
            true
        );

        // Injektoi konfiguraatio
        $current_lang = map_get_current_lang();
        wp_localize_script( 'map-modal-infopackage', 'mapModalConfig', array(
            'restUrl' => esc_url_raw( rest_url( 'map/v1' ) ),
            'lang'    => $current_lang,
            'i18n'    => map_get_js_translations( $current_lang ),
        ) );
    }
}
