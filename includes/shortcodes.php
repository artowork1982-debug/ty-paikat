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
        return '<p>Ei työpaikkoja saatavilla.</p>';
    }

    // Dynaaminen inline-CSS, jotta värit päivittyvät asetuksista
    $output = "<style>
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
            color: {$opts['link_color']}; 
            text-decoration: none; 
            font-weight: bold; 
            font-size: 18px; 
        }
        .my-job-list a:hover { 
            color: {$opts['link_hover_color']}; 
            text-decoration: none; 
        }
        .my-job-list .description { 
            color: {$opts['description_text_color']}; 
            font-size: 0.8rem; 
            font-weight: 300; 
            margin-top: 5px; 
        }
    </style>";

    $output .= '<ul class="my-job-list">';
    while ($query->have_posts()) {
        $query->the_post();

        $title   = get_the_title();
        $link    = get_post_meta(get_the_ID(), 'original_rss_link', true);
        $excerpt = get_the_excerpt();

        $output .= '<li>';
        if ($link) {
            $output .= '<a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>';
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
