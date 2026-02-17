<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Suora pääsy estetty
}

// === 1. CPT-rekisteröinti ===
function map_register_cpt_jobs() {
    register_post_type( 'avoimet_tyopaikat', array(
        'labels' => array(
            'name'          => __('Avoimet työpaikat', 'my-aggregator-plugin'),
            'singular_name' => __('Avoin työpaikka', 'my-aggregator-plugin'),
        ),
        'public'       => true,
        'has_archive'  => true,
        'supports'     => array('title', 'editor', 'excerpt', 'custom-fields'),
    ));
}
add_action( 'init', 'map_register_cpt_jobs' );

// === 2. Cron-ajastukset ===

// Lisätään mukautettu cron interval (3 hours)
function map_add_cron_intervals($schedules) {
    $schedules['3_hours'] = array(
        'interval' => 3 * HOUR_IN_SECONDS,
        'display'  => __('Kolmen tunnin välein')
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'map_add_cron_intervals' );

// Cronin aktivointi
function map_activate_cron() {
    // Asetetaan oletusajaksi '3_hours' (tai haluamasi)
    if ( ! wp_next_scheduled('map_aggregator_cron_hook') ) {
        wp_schedule_event(time(), '3_hours', 'map_aggregator_cron_hook');
    }
}

// Cronin deaktivointi
function map_deactivate_cron() {
    wp_clear_scheduled_hook('map_aggregator_cron_hook');
}

// Funktio päivittää cronin aikataulun (kutsutaan asetusten tallennuksen yhteydessä)
function map_update_cron_schedule($frequency = '3_hours') {
    // Poistetaan vanha ajo
    wp_clear_scheduled_hook('map_aggregator_cron_hook');

    // Asetetaan uusi
    wp_schedule_event(time(), $frequency, 'map_aggregator_cron_hook');
}

// Sidotaan cron-hook map_sync_feed()-funktioon
add_action('map_aggregator_cron_hook', 'map_sync_feed');
