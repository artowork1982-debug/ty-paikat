<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Kaikki käännettävät merkkijonot
 * 
 * @return array Monidimensionaalinen array: [key][lang] => string
 */
function map_i18n_strings() {
    return array(
        // Modal texts
        'modal.loading' => array(
            'fi' => 'Ladataan...',
            'en' => 'Loading...',
            'sv' => 'Laddar...',
        ),
        'modal.load_error' => array(
            'fi' => 'Tietojen lataaminen epäonnistui. Yritä uudelleen.',
            'en' => 'Failed to load information. Please try again.',
            'sv' => 'Det gick inte att ladda information. Försök igen.',
        ),
        'modal.close' => array(
            'fi' => 'Sulje',
            'en' => 'Close',
            'sv' => 'Stäng',
        ),
        'modal.cta_apply' => array(
            'fi' => 'Siirry hakemaan →',
            'en' => 'Go to application →',
            'sv' => 'Gå till ansökan →',
        ),
        'modal.questions_heading' => array(
            'fi' => 'Kysymykset',
            'en' => 'Questions',
            'sv' => 'Frågor',
        ),
        'modal.contact_heading' => array(
            'fi' => 'Yhteyshenkilö',
            'en' => 'Contact Person',
            'sv' => 'Kontaktperson',
        ),
        'modal.info_badge' => array(
            'fi' => 'ℹ️ Lisätietoja',
            'en' => 'ℹ️ More info',
            'sv' => 'ℹ️ Mer info',
        ),
        
        // Tab names
        'tab.general' => array(
            'fi' => 'Yleistä',
            'en' => 'General',
            'sv' => 'Allmänt',
        ),
        'tab.videos' => array(
            'fi' => 'Videot',
            'en' => 'Videos',
            'sv' => 'Videor',
        ),
        'tab.questions' => array(
            'fi' => 'Kysymykset',
            'en' => 'Questions',
            'sv' => 'Frågor',
        ),
        
        // Question feedback
        'feedback.unsuitable_default' => array(
            'fi' => 'Tämä tehtävä ei välttämättä vastaa kaikkia toiveitasi, mutta voit silti jatkaa hakemista — kaikki hakemukset käsitellään!',
            'en' => 'This position may not match all your preferences, but you are still welcome to apply — all applications will be reviewed!',
            'sv' => 'Denna tjänst kanske inte matchar alla dina önskemål, men du är välkommen att söka — alla ansökningar behandlas!',
        ),
        'feedback.heading' => array(
            'fi' => 'Huomio',
            'en' => 'Please note',
            'sv' => 'Observera',
        ),
        
        // Question types
        'question.yes' => array(
            'fi' => 'Kyllä',
            'en' => 'Yes',
            'sv' => 'Ja',
        ),
        'question.no' => array(
            'fi' => 'Ei',
            'en' => 'No',
            'sv' => 'Nej',
        ),
        'question.select_placeholder' => array(
            'fi' => 'Valitse...',
            'en' => 'Select...',
            'sv' => 'Välj...',
        ),
        'question.text_placeholder' => array(
            'fi' => 'Kirjoita vastauksesi tähän',
            'en' => 'Write your answer here',
            'sv' => 'Skriv ditt svar här',
        ),
        'question.required' => array(
            'fi' => 'Pakollinen',
            'en' => 'Required',
            'sv' => 'Obligatorisk',
        ),
        
        // Jobs listing
        'jobs.no_jobs' => array(
            'fi' => 'Ei työpaikkoja saatavilla.',
            'en' => 'No jobs available.',
            'sv' => 'Inga jobb tillgängliga.',
        ),
        'jobs.application_ends' => array(
            'fi' => 'Hakuaika päättyy',
            'en' => 'Application deadline',
            'sv' => 'Ansökningstid slutar',
        ),
        
        // Builder
        'builder.placeholder' => array(
            'fi' => 'Avoimet työpaikat – esikatselu. Julkaisussa listaus näkyy normaalisti.',
            'en' => 'Job listings – preview. The list will display normally when published.',
            'sv' => 'Lediga jobb – förhandsvisning. Listan visas normalt vid publicering.',
        ),
        
        // Admin
        'admin.language_version' => array(
            'fi' => 'Kieliversio',
            'en' => 'Language Version',
            'sv' => 'Språkversion',
        ),
        'admin.automatic_selection' => array(
            'fi' => '— Automaattinen valinta —',
            'en' => '— Automatic selection —',
            'sv' => '— Automatiskt val —',
        ),
        'admin.automation_suggestion' => array(
            'fi' => '🤖 Automaation ehdotus',
            'en' => '🤖 Automation suggestion',
            'sv' => '🤖 Automationsförslag',
        ),
        'admin.available_languages' => array(
            'fi' => 'Saatavilla olevat kieliversiot',
            'en' => 'Available language versions',
            'sv' => 'Tillgängliga språkversioner',
        ),
        'admin.edit' => array(
            'fi' => 'Muokkaa',
            'en' => 'Edit',
            'sv' => 'Redigera',
        ),
    );
}

/**
 * Hae yksittäinen käännetty merkkijono
 * 
 * @param string $key Käännösavain (esim. 'modal.loading')
 * @param string $lang Kielikoodi (fi/en/sv), jos null, käytetään nykyistä kieltä
 * @return string Käännetty merkkijono tai avain jos käännöstä ei löydy
 */
function map_i18n( $key, $lang = null ) {
    if ( $lang === null ) {
        $lang = map_get_current_lang();
    }
    
    $strings = map_i18n_strings();
    
    if ( isset( $strings[$key][$lang] ) ) {
        return $strings[$key][$lang];
    }
    
    // Fallback suomeen
    if ( isset( $strings[$key]['fi'] ) ) {
        return $strings[$key]['fi'];
    }
    
    // Jos ei löydy mitään, palauta avain
    return $key;
}

/**
 * Tunnista kieli luotettavasti
 * Prioriteetti: REST ?lang= param → Polylang → WPML → WP locale
 * 
 * @return string Kielikoodi (fi/en/sv)
 */
function map_get_current_lang() {
    // 1. REST API ?lang= parametri
    if ( isset( $_GET['lang'] ) ) {
        $lang = sanitize_text_field( $_GET['lang'] );
        if ( in_array( $lang, array( 'fi', 'en', 'sv' ), true ) ) {
            return $lang;
        }
    }
    
    // 2. Polylang
    if ( function_exists( 'pll_current_language' ) ) {
        $pll_lang = pll_current_language();
        if ( $pll_lang ) {
            return map_normalize_lang_code( $pll_lang );
        }
    }
    
    // 3. WPML
    if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
        return map_normalize_lang_code( ICL_LANGUAGE_CODE );
    }
    
    // 4. WordPress locale
    $locale = get_locale();
    if ( strpos( $locale, 'en' ) === 0 ) {
        return 'en';
    }
    if ( strpos( $locale, 'sv' ) === 0 ) {
        return 'sv';
    }
    
    // Oletus: suomi
    return 'fi';
}

/**
 * Normalisoi kielikoodit (esim. en_US → en, fi_FI → fi)
 * 
 * @param string $code Kielikoodi
 * @return string Normalisoitu koodi
 */
function map_normalize_lang_code( $code ) {
    $code = strtolower( substr( $code, 0, 2 ) );
    
    if ( in_array( $code, array( 'fi', 'en', 'sv' ), true ) ) {
        return $code;
    }
    
    return 'fi'; // fallback
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
 * Hae saatavilla olevat kielet
 * 
 * @return array Kielikoodit arrayna
 */
function map_get_available_languages() {
    $languages = array();
    
    // Polylang
    if ( function_exists( 'pll_languages_list' ) ) {
        $pll_langs = pll_languages_list();
        if ( is_array( $pll_langs ) && ! empty( $pll_langs ) ) {
            foreach ( $pll_langs as $lang ) {
                $normalized = map_normalize_lang_code( $lang );
                if ( ! in_array( $normalized, $languages, true ) ) {
                    $languages[] = $normalized;
                }
            }
            return $languages;
        }
    }
    
    // WPML
    if ( function_exists( 'icl_get_languages' ) ) {
        $wpml_langs = icl_get_languages( 'skip_missing=0' );
        if ( is_array( $wpml_langs ) && ! empty( $wpml_langs ) ) {
            foreach ( $wpml_langs as $lang ) {
                if ( isset( $lang['code'] ) ) {
                    $normalized = map_normalize_lang_code( $lang['code'] );
                    if ( ! in_array( $normalized, $languages, true ) ) {
                        $languages[] = $normalized;
                    }
                }
            }
            return $languages;
        }
    }
    
    // Fallback: kaikki tuetut kielet
    return array( 'fi', 'en', 'sv' );
}

/**
 * Palauta käännöspaketti frontendille
 * 
 * @param string $lang Kielikoodi
 * @return array Käännökset objektina
 */
function map_get_js_translations( $lang = null ) {
    if ( $lang === null ) {
        $lang = map_get_current_lang();
    }
    
    $strings = map_i18n_strings();
    $translations = array();
    
    foreach ( $strings as $key => $lang_strings ) {
        if ( isset( $lang_strings[$lang] ) ) {
            $translations[$key] = $lang_strings[$lang];
        } elseif ( isset( $lang_strings['fi'] ) ) {
            // Fallback suomeen
            $translations[$key] = $lang_strings['fi'];
        } else {
            $translations[$key] = $key;
        }
    }
    
    return $translations;
}
