<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Synkronointifunktio: hakee RSS-syötteen ja päivittää kohteet.
 *
 * @return array Lisättyjen, poistettujen ja päivitettyjen tiedot.
 */
function map_sync_feed() {
    // 1. Haetaan asetukset
    $opts     = my_agg_get_settings();
    $feed_url = isset($opts['feed_url']) ? $opts['feed_url'] : '';

    // Jos syöte-URL puuttuu asetuksista, ei tehdä mitään
    if (empty($feed_url)) {
        // Päivitä “viimeisin synkka” -tilastot tyhjänäkin
        update_option('my_agg_last_sync', current_time('timestamp'));
        update_option('my_agg_last_sync_stats', array(
            'time'    => current_time('mysql'),
            'added'   => 0,
            'removed' => 0,
            'updated' => 0,
        ), false);
        return array('added' => array(), 'removed' => array(), 'updated' => array());
    }

    // 2. Ladataan WordPressin feed-työkalut (SimplePie) ja yritetään hakea syöte
    include_once(ABSPATH . WPINC . '/feed.php');
    $feed = fetch_feed($feed_url);

    if (is_wp_error($feed)) {
        // Syötteen haku epäonnistui – kirjataan virhe (mutta ei "päivityslokia")
        $error_msg = $feed->get_error_message();

        // Päivitä tilastot (0/0/0, mutta virhe talteen logiin näkyvyyden vuoksi)
        update_option('my_agg_last_sync', current_time('timestamp'));
        update_option('my_agg_last_sync_stats', array(
            'time'    => current_time('mysql'),
            'added'   => 0,
            'removed' => 0,
            'updated' => 0,
        ), false);

        // Kirjaa virhe lokiin (sallittu poikkeus, vaikka added/removed olisi tyhjä)
        map_log_import(array(), array(), $error_msg, array(), array());

        return array('added' => array(), 'removed' => array(), 'updated' => array(), 'error' => $error_msg);
    }

    // 3. Haetaan jo olemassa olevat avoimet työpaikat (CPT: avoimet_tyopaikat)
    $existing_posts = array();
    $existing_query = new WP_Query(array(
        'post_type'      => 'avoimet_tyopaikat',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ));

    if ($existing_query->have_posts()) {
        foreach ($existing_query->posts as $p_id) {
            $meta_link = get_post_meta($p_id, 'original_rss_link', true);
            if (!empty($meta_link)) {
                $existing_posts[$meta_link] = $p_id;
            }
        }
    }

    // 4. Kielletyt otsikot (esimerkki: Avoin hakemus, Open application, Öppen ansökan)
    $forbidden_titles_raw = isset($opts['forbidden_titles']) ? $opts['forbidden_titles'] : '';
    $forbidden_titles = array_filter(array_map('trim', explode("\n", $forbidden_titles_raw)));

    // 5. Käydään syötteen itemit läpi
    $feed_items = $feed->get_items();
    $added   = array();
    $removed = array();
    $updated = array();
    $current_feed_links = array();

    foreach ($feed_items as $item) {
        $title  = $item->get_title();
        $desc   = $item->get_content();
        $link   = $item->get_link();

        // Parse Laura namespace fields
        $laura_ns = 'https://tapojarvi.rekrytointi.com/#';
        
        $laura_description_raw = $item->get_item_tags($laura_ns, 'description');
        $laura_description = '';
        if (!empty($laura_description_raw[0]['data'])) {
            $laura_description = wp_kses_post($laura_description_raw[0]['data']);
        }
        
        $laura_form_raw = $item->get_item_tags($laura_ns, 'form');
        $laura_form = '';
        if (!empty($laura_form_raw[0]['data'])) {
            $laura_form = esc_url_raw($laura_form_raw[0]['data']);
        }
        
        $laura_enddate_raw = $item->get_item_tags($laura_ns, 'enddate');
        $laura_enddate = '';
        if (!empty($laura_enddate_raw[0]['data'])) {
            $laura_enddate = sanitize_text_field($laura_enddate_raw[0]['data']);
        }
        
        $laura_region_raw = $item->get_item_tags($laura_ns, 'common_job_region');
        $laura_region = '';
        if (!empty($laura_region_raw[0]['data'])) {
            $laura_region = sanitize_text_field($laura_region_raw[0]['data']);
        }
        
        $laura_country_raw = $item->get_item_tags($laura_ns, 'common_job_country');
        $laura_country = '';
        if (!empty($laura_country_raw[0]['data'])) {
            $laura_country = sanitize_text_field($laura_country_raw[0]['data']);
        }
        
        $laura_worktime_raw = $item->get_item_tags($laura_ns, 'common_worktime');
        $laura_worktime = '';
        if (!empty($laura_worktime_raw[0]['data'])) {
            $laura_worktime = sanitize_text_field($laura_worktime_raw[0]['data']);
        }
        
        $laura_type_raw = $item->get_item_tags($laura_ns, 'common_type');
        $laura_type = '';
        if (!empty($laura_type_raw[0]['data'])) {
            $laura_type = sanitize_text_field($laura_type_raw[0]['data']);
        }
        
        $laura_category_raw = $item->get_item_tags($laura_ns, 'common_category');
        $laura_category = '';
        if (!empty($laura_category_raw[0]['data'])) {
            $laura_category = sanitize_text_field($laura_category_raw[0]['data']);
        }

        // Tarkista kielletyt otsikot
        $skip = false;
        foreach ($forbidden_titles as $bad_title) {
            if (!empty($bad_title) && stripos($title, $bad_title) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $current_feed_links[] = $link;

        // POLYLANG
        if (function_exists('pll_current_language')) {
            $lang_code = pll_current_language();
        } else {
            $lang_code = 'fi';
        }

        // Parse end date - prioritize laura:enddate
        $endDateTime = '';
        
        if (!empty($laura_enddate)) {
            // Laura enddate format: "2026-04-30 23:59:00"
            // Convert to Finnish format: "dd.mm.yyyy hh:mm"
            $timestamp = strtotime($laura_enddate);
            if ($timestamp) {
                $endDateTime = date('d.m.Y H:i', $timestamp);
            }
        } else {
            // Fallback: parse from description
            // (A) Poista valmiit "Hakuaika päättyy:" / "Application ends:"
            $desc = str_ireplace('Application ends:', '', $desc);
            $desc = str_ireplace('Hakuaika päättyy:', '', $desc);

            // (A2) Poista myös aloituspäivä (jottei fallback nappaa sitä loppuajaksi)
            $desc = preg_replace('/Hakuaika alkaa:\s*\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}/iu', '', $desc);
            $desc = preg_replace('/Application period starts:\s*\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}/iu', '', $desc);

            // 1) Yritä löytää "Hakuaika alkaa: ... - Hakuaika päättyy:" / "Application period starts: ... - Application period ends:"
            $desc = preg_replace(
                '/(Application period starts:[\s\S]*?\-\s*Application period ends:)|(Hakuaika alkaa:[\s\S]*?\-\s*Hakuaika päättyy:)/iu',
                'ENDLABEL:',
                $desc
            );

            // Jos löydettiin "ENDLABEL", otetaan sen jälkeiset merkinnät
            if (preg_match('/ENDLABEL\s*(.+)/i', $desc, $match)) {
                $endDateTime = trim($match[1]);
            } else {
                $endDateTime = '';
            }

            // 2) Fallback: jos endDateTime vielä tyhjä, etsi "dd.mm.yyyy hh:mm"
            if (empty($endDateTime)) {
                if (preg_match('/(\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{1,2})/u', $desc, $m2)) {
                    $endDateTime = trim($m2[1]);
                }
            }
        }

        // Kielikohtainen label
        $end_label = 'Application ends';
        switch ($lang_code) {
            case 'fi':
                $end_label = 'Hakuaika päättyy';
                break;
            case 'en':
                $end_label = 'Application ends';
                break;
            case 'sv':
                $end_label = 'Ansökan slutar';
                break;
            case 'it':
                $end_label = 'L\'applicazione termina';
                break;
            default:
                $end_label = 'Application ends';
        }

        // Rakennetaan lopullinen excerpt
        $desc_final = '';
        if (!empty($endDateTime)) {
            $desc_final = $end_label . ': ' . $endDateTime;
        }

        // -- Onko postaus jo olemassa? --
        if (isset($existing_posts[$link])) {
            $post_id = $existing_posts[$link];

            // ===== Vertailu: Onko otsikko tai excerpt muuttunut? =====
            $old_title   = get_the_title($post_id);
            $old_excerpt = get_post_field('post_excerpt', $post_id);
            $old_content = get_post_field('post_content', $post_id);

            $new_title   = wp_strip_all_tags($title);
            $new_excerpt = wp_strip_all_tags($desc_final);
            $new_content = !empty($laura_description) ? $laura_description : ''; // Already wp_kses_post sanitized

            // Päivitä vain jos on tarvetta
            if ($old_title !== $new_title || $old_excerpt !== $new_excerpt || $old_content !== $new_content) {
                wp_update_post(array(
                    'ID'           => $post_id,
                    'post_title'   => $new_title,
                    'post_excerpt' => $new_excerpt,
                    'post_content' => $new_content,
                ));
                $updated[] = $post_id;
            }

            // Update meta fields (always update to ensure they're in sync, delete if empty)
            if (!empty($laura_form)) {
                update_post_meta($post_id, '_map_apply_form_url', $laura_form);
            } else {
                delete_post_meta($post_id, '_map_apply_form_url');
            }
            if (!empty($laura_region)) {
                update_post_meta($post_id, '_map_job_region', $laura_region);
            } else {
                delete_post_meta($post_id, '_map_job_region');
            }
            if (!empty($laura_country)) {
                update_post_meta($post_id, '_map_job_country', $laura_country);
            } else {
                delete_post_meta($post_id, '_map_job_country');
            }
            if (!empty($laura_worktime)) {
                update_post_meta($post_id, '_map_job_worktime', $laura_worktime);
            } else {
                delete_post_meta($post_id, '_map_job_worktime');
            }
            if (!empty($laura_type)) {
                update_post_meta($post_id, '_map_job_type', $laura_type);
            } else {
                delete_post_meta($post_id, '_map_job_type');
            }
            if (!empty($laura_category)) {
                update_post_meta($post_id, '_map_job_category', $laura_category);
            } else {
                delete_post_meta($post_id, '_map_job_category');
            }

            // EI enää muutospohjaisia lokimerkintöjä (title/excerpt) — pidetään loki siistinä

        } else {
            // -- Luodaan uusi CPT-postaus --
            $new_post_id = wp_insert_post(array(
                'post_type'    => 'avoimet_tyopaikat',
                'post_status'  => 'publish',
                'post_title'   => wp_strip_all_tags($title),
                'post_excerpt' => wp_strip_all_tags($desc_final),
                'post_content' => !empty($laura_description) ? $laura_description : '', // Already wp_kses_post sanitized
            ));
            if (!is_wp_error($new_post_id)) {
                update_post_meta($new_post_id, 'original_rss_link', $link);
                
                // Save Laura namespace meta fields
                if (!empty($laura_form)) {
                    update_post_meta($new_post_id, '_map_apply_form_url', $laura_form);
                }
                if (!empty($laura_region)) {
                    update_post_meta($new_post_id, '_map_job_region', $laura_region);
                }
                if (!empty($laura_country)) {
                    update_post_meta($new_post_id, '_map_job_country', $laura_country);
                }
                if (!empty($laura_worktime)) {
                    update_post_meta($new_post_id, '_map_job_worktime', $laura_worktime);
                }
                if (!empty($laura_type)) {
                    update_post_meta($new_post_id, '_map_job_type', $laura_type);
                }
                if (!empty($laura_category)) {
                    update_post_meta($new_post_id, '_map_job_category', $laura_category);
                }
                
                $added[] = $new_post_id;
            }
        }
    }

    // 6. Poistetaan CPT-postaukset, joita ei enää ole syötteessä
    foreach ($existing_posts as $existing_link => $existing_post_id) {
        if (!in_array($existing_link, $current_feed_links, true)) {
            wp_trash_post($existing_post_id);
            $removed[] = $existing_post_id;
        }
    }

    // Päivitä tilastot (aina)
    update_option('my_agg_last_sync', current_time('timestamp'));
    update_option('my_agg_last_sync_stats', array(
        'time'    => current_time('mysql'),
        'added'   => count($added),
        'removed' => count($removed),
        'updated' => count($updated),
    ), false);

    // Lokitus: kirjaa vain jos lisäyksiä tai poistoja TAI jos virhe
    map_log_import($added, $removed, '', $updated, array());

    // Bumpataan mahdollinen HTML-välimuisti (jos käytössä frontissa)
    update_option('my_agg_cache_bump', time());

    return array(
        'added'   => $added,
        'removed' => $removed,
        'updated' => $updated,
    );
}

/**
 * Tallentaa tuontilokin
 *
 * Kirjaa merkinnän vain, jos:
 *  - on lisättyjä TAI poistettuja kohteita TAI
 *  - on virheviesti
 * Muussa tapauksessa päivitetään vain "viimeisin synkka" -tilastot (tehty jo map_sync_feedissä).
 *
 * @param array       $added
 * @param array       $removed
 * @param string      $error
 * @param array|int   $updated
 * @param array       $changes  (ei käytetä enää lokitukseen, jätetään taaksepäin yhteensopivuuden vuoksi)
 */
function map_log_import($added = array(), $removed = array(), $error = '', $updated = array(), $changes = array()) {
    $should_log = (!empty($added) || !empty($removed) || !empty($error));

    // Normalisoi updated count tilastoja varten (jos joku muu kutsuu tätä suoraan)
    $updated_count = is_array($updated) ? count($updated) : intval($updated);

    if (!$should_log) {
        // Ei tehdä varsinaista lokimerkintää
        return;
    }

    $import_log = get_option('my_agg_import_log', array());
    if (!is_array($import_log)) {
        $import_log = array();
    }

    $import_log[] = array(
        'timestamp' => current_time('mysql'),
        'added'     => $added,
        'removed'   => $removed,
        'updated'   => $updated, // pidetään mukana yhteensopivuuden vuoksi
        'error'     => $error,
        'changes'   => array(),  // ei käytössä – pidetään kenttä tyhjänä
    );

    // Rajoitetaan lokin pituus, esim. 200 merkintää
    if (count($import_log) > 200) {
        // Poista vanhin
        array_shift($import_log);
    }

    update_option('my_agg_import_log', $import_log);
}