<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hae oletuskieli
 * 
 * @return string Oletuskielikoodi
 */
function map_get_default_lang() {
    // Polylang
    if ( function_exists( 'pll_default_language' ) ) {
        $lang = pll_default_language();
        if ( $lang ) {
            return map_normalize_lang_code( $lang );
        }
    }
    
    // WPML
    if ( function_exists( 'icl_get_default_language' ) ) {
        $lang = icl_get_default_language();
        if ( $lang ) {
            return map_normalize_lang_code( $lang );
        }
    }
    
    // Fallback
    return 'fi';
}

/**
 * Hae infopaketin oikea kieliversio
 * 
 * @param int $pkg_id Infopaketin ID
 * @param string $lang Haluttu kielikoodi
 * @return int Käännetyn infopaketin ID tai alkuperäinen jos käännöstä ei löydy
 */
function map_get_translated_package_id( $pkg_id, $lang ) {
    if ( empty( $pkg_id ) || empty( $lang ) ) {
        return $pkg_id;
    }

    // Polylang
    if ( function_exists( 'pll_get_post' ) ) {
        $translated_id = pll_get_post( $pkg_id, $lang );
        if ( $translated_id ) {
            return $translated_id;
        }
    }

    // WPML
    if ( function_exists( 'icl_object_id' ) ) {
        $translated_id = icl_object_id( $pkg_id, 'map_infopackage', false, $lang );
        if ( $translated_id ) {
            return $translated_id;
        }
    }

    // Fallback: palauta alkuperäinen
    return $pkg_id;
}

/**
 * Ratkaise mikä infopaketti näytetään työpaikalle
 * 
 * @param int $job_post_id Työpaikan post ID
 * @param string $lang Kielikoodi (null = nykyinen kieli)
 * @return int|null Infopaketin ID tai null jos ei löydy
 */
function map_resolve_infopackage( $job_post_id, $lang = null ) {
    if ( ! $job_post_id ) {
        return null;
    }

    if ( $lang === null ) {
        $lang = map_get_current_lang();
    }

    // 1. Tarkista manuaalinen liitos
    $manual_link = get_post_meta( $job_post_id, '_map_linked_infopackage', true );
    if ( $manual_link && is_numeric( $manual_link ) ) {
        $manual_link = absint( $manual_link );
        // Tarkista että paketti on julkaistu
        if ( get_post_status( $manual_link ) === 'publish' ) {
            // Palauta oikea kieliversio
            return map_get_translated_package_id( $manual_link, $lang );
        }
    }

    // 2. Automaattinen valinta pisteytyksen perusteella
    $default_lang = map_get_default_lang();
    
    // Hae kaikki julkaistut infopaketit oletuskielellä (välttää duplikaatit)
    $packages_query = new WP_Query( array(
        'post_type'      => 'map_infopackage',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'lang'           => function_exists( 'pll_default_language' ) ? $default_lang : '',
    ) );

    if ( ! $packages_query->have_posts() ) {
        return null;
    }

    $job_post   = get_post( $job_post_id );
    $job_title  = strtolower( $job_post->post_title );
    $job_content = strtolower( strip_tags( $job_post->post_content ) );
    $job_excerpt = strtolower( strip_tags( $job_post->post_excerpt ) );
    $job_text   = $job_title . ' ' . $job_content . ' ' . $job_excerpt;

    $best_score     = 0;
    $best_package_id = null;

    while ( $packages_query->have_posts() ) {
        $packages_query->the_post();
        $pkg_id = get_the_ID();
        $score  = 0;

        // Sijainti-match (paino 10)
        $auto_location = get_post_meta( $pkg_id, '_map_info_auto_location', true );
        if ( ! empty( $auto_location ) ) {
            $locations = array_map( 'trim', explode( ',', strtolower( $auto_location ) ) );
            foreach ( $locations as $location ) {
                if ( ! empty( $location ) && strpos( $job_text, $location ) !== false ) {
                    $score += 10;
                }
            }
        }

        // Avainsana-match (paino 5)
        $auto_keywords = get_post_meta( $pkg_id, '_map_info_auto_keywords', true );
        if ( ! empty( $auto_keywords ) ) {
            $keywords = array_map( 'trim', explode( ',', strtolower( $auto_keywords ) ) );
            foreach ( $keywords as $keyword ) {
                if ( ! empty( $keyword ) && strpos( $job_text, $keyword ) !== false ) {
                    $score += 5;
                }
            }
        }

        // Päivitä paras match
        if ( $score > $best_score ) {
            $best_score      = $score;
            $best_package_id = $pkg_id;
        }
    }

    wp_reset_postdata();

    // Jos löytyi match, palauta sen kieliversio
    if ( $best_package_id ) {
        return map_get_translated_package_id( $best_package_id, $lang );
    }

    return null;
}

/**
 * Lisää meta box työpaikka-CPT:lle infopaketin manuaaliseen valintaan
 */
function map_add_job_infopackage_meta_box() {
    add_meta_box(
        'map_job_infopackage_link',
        __( 'Infopaketti', 'my-aggregator-plugin' ),
        'map_render_job_infopackage_meta_box',
        'avoimet_tyopaikat',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'map_add_job_infopackage_meta_box' );

/**
 * Renderöi infopaketti-valinta meta box
 */
function map_render_job_infopackage_meta_box( $post ) {
    wp_nonce_field( 'map_save_job_infopackage', 'map_job_infopackage_nonce' );

    $linked_package = get_post_meta( $post->ID, '_map_linked_infopackage', true );

    // Hae kaikki julkaistut infopaketit
    $packages = get_posts( array(
        'post_type'      => 'map_infopackage',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );

    // Hae automaation ehdotus
    $auto_suggestion = map_resolve_infopackage( $post->ID );
    $auto_suggestion_title = '';
    if ( $auto_suggestion ) {
        $auto_post = get_post( $auto_suggestion );
        if ( $auto_post ) {
            $auto_suggestion_title = $auto_post->post_title;
        }
    }

    ?>
    <p>
        <label for="map_linked_infopackage"><strong><?php _e( 'Valitse infopaketti', 'my-aggregator-plugin' ); ?></strong></label><br>
        <select id="map_linked_infopackage" name="map_linked_infopackage" style="width:100%;">
            <option value=""><?php echo esc_html( map_i18n( 'admin.automatic_selection' ) ); ?></option>
            <?php foreach ( $packages as $pkg ) : ?>
                <option value="<?php echo esc_attr( $pkg->ID ); ?>" <?php selected( $linked_package, $pkg->ID ); ?>>
                    <?php echo esc_html( $pkg->post_title ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <?php if ( $auto_suggestion_title ) : ?>
        <p style="padding:8px; background:#f0f0f0; border-left:3px solid #2271b1; font-size:12px;">
            <?php echo esc_html( map_i18n( 'admin.automation_suggestion' ) ); ?>:<br>
            <strong><?php echo esc_html( $auto_suggestion_title ); ?></strong>
        </p>
    <?php endif; ?>

    <?php
    // Näytä kieliversioiden saatavuus
    if ( $linked_package || $auto_suggestion ) {
        $check_package_id = $linked_package ? $linked_package : $auto_suggestion;
        $available_langs  = map_get_available_languages();
        
        echo '<div style="margin-top:15px; padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">';
        echo '<p style="margin:0 0 8px 0; font-weight:bold;">' . esc_html( map_i18n( 'admin.available_languages' ) ) . ':</p>';
        
        foreach ( $available_langs as $lang ) {
            $translated_id = map_get_translated_package_id( $check_package_id, $lang );
            $is_available  = $translated_id && get_post_status( $translated_id ) === 'publish';
            $icon          = $is_available ? '✅' : '❌';
            $edit_link     = '';
            
            if ( $is_available ) {
                $edit_url  = admin_url( 'post.php?post=' . $translated_id . '&action=edit' );
                $edit_link = ' <a href="' . esc_url( $edit_url ) . '" target="_blank" style="font-size:11px;">(' . esc_html( map_i18n( 'admin.edit' ) ) . ')</a>';
            }
            
            echo '<div style="margin-bottom:4px;">' . $icon . ' <strong>' . strtoupper( $lang ) . '</strong>' . $edit_link . '</div>';
        }
        
        echo '</div>';
    }
}

/**
 * Tallenna työpaikan infopaketti-linkitys
 */
function map_save_job_infopackage_meta( $post_id ) {
    // Tarkista nonce
    if ( ! isset( $_POST['map_job_infopackage_nonce'] ) || ! wp_verify_nonce( $_POST['map_job_infopackage_nonce'], 'map_save_job_infopackage' ) ) {
        return;
    }

    // Tarkista autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // Tarkista oikeudet
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Tallenna linkitys
    if ( isset( $_POST['map_linked_infopackage'] ) ) {
        $package_id = sanitize_text_field( $_POST['map_linked_infopackage'] );
        if ( empty( $package_id ) ) {
            delete_post_meta( $post_id, '_map_linked_infopackage' );
        } else {
            update_post_meta( $post_id, '_map_linked_infopackage', absint( $package_id ) );
        }
    }
}
add_action( 'save_post_avoimet_tyopaikat', 'map_save_job_infopackage_meta' );
