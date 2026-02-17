<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rekisteröi REST API endpoint
 */
function map_register_rest_api() {
    register_rest_route( 'map/v1', '/job-info/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'map_rest_get_job_info',
        'permission_callback' => '__return_true',
        'args'                => array(
            'id' => array(
                'validate_callback' => function( $param ) {
                    return is_numeric( $param );
                },
            ),
            'lang' => array(
                'required' => false,
                'default'  => null,
            ),
        ),
    ) );
}
add_action( 'rest_api_init', 'map_register_rest_api' );

/**
 * REST API callback: hae työpaikan info + infopaketti
 */
function map_rest_get_job_info( $request ) {
    $job_id = absint( $request['id'] );
    $lang   = $request->get_param( 'lang' );

    // Aseta kieli jos annettu
    if ( $lang ) {
        $_GET['lang'] = sanitize_text_field( $lang );
    }

    // Validoi post
    $post = get_post( $job_id );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Job not found', array( 'status' => 404 ) );
    }

    if ( $post->post_type !== 'avoimet_tyopaikat' ) {
        return new WP_Error( 'invalid_type', 'Invalid post type', array( 'status' => 400 ) );
    }

    if ( $post->post_status !== 'publish' ) {
        return new WP_Error( 'not_published', 'Job not published', array( 'status' => 403 ) );
    }

    // Hae perustiedot
    $current_lang = map_get_current_lang();
    $title        = get_the_title( $job_id );
    $excerpt      = get_the_excerpt( $job_id );
    $apply_url    = get_post_meta( $job_id, 'original_rss_link', true );

    // Hae infopaketti
    $package_id   = map_resolve_infopackage( $job_id, $current_lang );
    $package_data = null;

    if ( $package_id ) {
        $package_post = get_post( $package_id );
        if ( $package_post && $package_post->post_status === 'publish' ) {
            // Hae metadata
            $intro         = get_post_meta( $package_id, '_map_info_intro', true );
            $highlights    = get_post_meta( $package_id, '_map_info_highlights', true );
            $questions     = get_post_meta( $package_id, '_map_info_questions', true );
            $contact_name  = get_post_meta( $package_id, '_map_info_contact_name', true );
            $contact_email = get_post_meta( $package_id, '_map_info_contact_email', true );
            $contact_phone = get_post_meta( $package_id, '_map_info_contact_phone', true );

            // Tarkista saatavilla olevat kieliversiot
            $available_langs = map_get_available_languages();
            $available_languages_map = array();
            
            // Hae alkuperäisen paketin ID (oletuskielinen)
            $original_package_id = $package_id;
            
            // Jos Polylang käytössä, hae oletuskielinen versio
            if ( function_exists( 'pll_get_post' ) ) {
                $default_lang = map_get_default_lang();
                $default_id = pll_get_post( $package_id, $default_lang );
                if ( $default_id ) {
                    $original_package_id = $default_id;
                }
            }
            
            foreach ( $available_langs as $check_lang ) {
                $translated_id = map_get_translated_package_id( $original_package_id, $check_lang );
                $available_languages_map[ $check_lang ] = (bool) ( $translated_id && get_post_status( $translated_id ) === 'publish' );
            }

            $package_data = array(
                'id'                  => $package_id,
                'title'               => $package_post->post_title,
                'intro'               => $intro ? $intro : '',
                'highlights'          => is_array( $highlights ) ? $highlights : array(),
                'questions'           => is_array( $questions ) ? $questions : array(),
                'contact'             => array(
                    'name'  => $contact_name ? $contact_name : '',
                    'email' => $contact_email ? $contact_email : '',
                    'phone' => $contact_phone ? $contact_phone : '',
                ),
                'available_languages' => $available_languages_map,
            );
        }
    }

    // Rakenna vastaus
    $response = array(
        'id'          => $job_id,
        'title'       => $title,
        'excerpt'     => $excerpt,
        'apply_url'   => $apply_url ? $apply_url : '',
        'lang'        => $current_lang,
        'infopackage' => $package_data,
        'i18n'        => map_get_js_translations( $current_lang ),
    );

    return rest_ensure_response( $response );
}
