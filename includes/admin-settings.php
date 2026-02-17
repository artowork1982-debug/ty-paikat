<?php
if (!defined('ABSPATH')) {
    exit; // Suora pääsy estetty
}

// Lisää asetussivu
function map_add_admin_menu() {
    add_menu_page(
        'Aggregator Settings',
        'Aggregator',
        'manage_options',
        'my-agg-settings',
        'map_render_settings_page',
        'dashicons-rss',
        80
    );
}
add_action('admin_menu', 'map_add_admin_menu');

// Hae oletusasetukset
function my_agg_get_settings() {
    $defaults = array(
        'feed_url'               => '',
        'items_count'            => 10,
        'forbidden_titles'       => "Avoin hakemus\nOpen application\nÖppen ansökan",
        'order_by'               => 'date',
        'order'                  => 'DESC',
        'update_frequency'       => 'hourly',
        'link_color'             => '#000000',
        'description_text_color' => '#666666',
        'link_hover_color'       => '#ff0000',
    );
    return wp_parse_args(get_option('my_agg_settings', array()), $defaults);
}

// Asetussivun näyttö ja käsittely
function map_render_settings_page() {
    $opts = my_agg_get_settings();
    $import_log = get_option('my_agg_import_log', array());
    if (!is_array($import_log)) { $import_log = array(); }

    // --- MUUTOS: luetaan "näytä vain muutokset" -filtteri URL:sta ---
    // 1 = näytä vain lisäykset/poistot/virheet, 0 tai puuttuu = näytä kaikki
    $only_changes = isset($_GET['only_changes']) ? (int) $_GET['only_changes'] : 0;

    // Pakota tuonti
    if (isset($_POST['my_agg_force_import'])) {
        check_admin_referer('my_agg_settings_nonce');
        $sync_result = map_sync_feed();
        echo '<div class="notice notice-success is-dismissible"><p>Synkronointi suoritettu! '
            . 'Lisätty: ' . count($sync_result['added'])
            . ', Poistettu: ' . count($sync_result['removed'])
            . ', Päivitetty: ' . count($sync_result['updated'])
            . '</p></div>';
    }

    // Tallenna asetukset
    if (isset($_POST['my_agg_save_settings'])) {
        check_admin_referer('my_agg_settings_nonce');
        $new_settings = array(
            'feed_url'               => sanitize_text_field($_POST['feed_url']),
            'items_count'            => absint($_POST['items_count']),
            'forbidden_titles'       => sanitize_textarea_field($_POST['forbidden_titles']),
            'order_by'               => sanitize_text_field($_POST['order_by']),
            'order'                  => sanitize_text_field($_POST['order']),
            'update_frequency'       => sanitize_text_field($_POST['update_frequency']),
            'link_color'             => sanitize_hex_color($_POST['link_color']),
            'description_text_color' => sanitize_hex_color($_POST['description_text_color']),
            'link_hover_color'       => sanitize_hex_color($_POST['link_hover_color']),
        );
        update_option('my_agg_settings', $new_settings);
        echo '<div class="notice notice-success is-dismissible"><p>Asetukset tallennettu!</p></div>';
    }

    // --- MUUTOS: suodata loki palvelinpuolella jos only_changes=1 ---
    if ($only_changes === 1) {
        $import_log = array_values(array_filter($import_log, function($row){
            $added   = isset($row['added'])   ? ( is_array($row['added'])   ? count($row['added'])   : (int)$row['added'] )   : 0;
            $removed = isset($row['removed']) ? ( is_array($row['removed']) ? count($row['removed']) : (int)$row['removed'] ) : 0;
            $error   = !empty($row['error']);
            return ($added > 0 || $removed > 0 || $error);
        }));
    }

    // Sivutus (tehdään suodatuksen jälkeen, jotta numerot täsmäävät)
    $logs_per_page = 10;
    $total_logs    = count($import_log);
    $current_page  = isset($_GET['log_page']) ? max(1, absint($_GET['log_page'])) : 1;
    $offset        = ($current_page - 1) * $logs_per_page;
    $total_pages   = ( $total_logs > 0 ) ? (int) ceil($total_logs / $logs_per_page) : 1;

    // Näytetään tuoreimmat ensin
    $logs_to_display = array_slice(array_reverse($import_log), $offset, $logs_per_page);

    // Rakennetaan base-URL, joka säilyttää only_changes-parametrin sivutuksessa
    $base_url = admin_url('admin.php?page=my-agg-settings');
    if ($only_changes === 1) {
        $base_url = add_query_arg('only_changes', '1', $base_url);
    }

    ?>
    <div class="wrap">
        <h1>Aggregator Plugin Settings</h1>
        <form method="post">
            <?php wp_nonce_field('my_agg_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="feed_url">RSS Feed URL</label></th>
                    <td><input type="text" id="feed_url" name="feed_url" value="<?php echo esc_attr($opts['feed_url']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="items_count">Näytettävien jobien määrä</label></th>
                    <td><input type="number" id="items_count" name="items_count" value="<?php echo esc_attr($opts['items_count']); ?>" min="1"></td>
                </tr>
                <tr>
                    <th><label for="forbidden_titles">Kielletyt otsikot</label></th>
                    <td>
                        <textarea id="forbidden_titles" name="forbidden_titles" rows="4" class="large-text"><?php echo esc_textarea($opts['forbidden_titles']); ?></textarea>
                        <p class="description">Yksi per rivi.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="order_by">Listauksen järjestys</label></th>
                    <td>
                        <select id="order_by" name="order_by">
                            <option value="date" <?php selected($opts['order_by'], 'date'); ?>>Päivämäärä</option>
                            <option value="title" <?php selected($opts['order_by'], 'title'); ?>>Aakkosjärjestys</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="order">Järjestyksen suunta</label></th>
                    <td>
                        <select id="order" name="order">
                            <option value="ASC" <?php selected($opts['order'], 'ASC'); ?>>Nouseva</option>
                            <option value="DESC" <?php selected($opts['order'], 'DESC'); ?>>Laskeva</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="update_frequency">Päivitystiheys</label></th>
                    <td>
                        <select id="update_frequency" name="update_frequency">
                            <option value="hourly" <?php selected($opts['update_frequency'], 'hourly'); ?>>Tunnin välein</option>
                            <option value="3_hours" <?php selected($opts['update_frequency'], '3_hours'); ?>>Kolmen tunnin välein</option>
                            <option value="daily" <?php selected($opts['update_frequency'], 'daily'); ?>>Päivittäin</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="link_color">Linkin väri</label></th>
                    <td><input type="text" id="link_color" name="link_color" value="<?php echo esc_attr($opts['link_color']); ?>" class="color-field"></td>
                </tr>
                <tr>
                    <th><label for="description_text_color">Kuvaustekstin väri</label></th>
                    <td><input type="text" id="description_text_color" name="description_text_color" value="<?php echo esc_attr($opts['description_text_color']); ?>" class="color-field"></td>
                </tr>
                <tr>
                    <th><label for="link_hover_color">Hover-väri</label></th>
                    <td><input type="text" id="link_hover_color" name="link_hover_color" value="<?php echo esc_attr($opts['link_hover_color']); ?>" class="color-field"></td>
                </tr>
            </table>
            <p>
                <button type="submit" name="my_agg_save_settings" class="button button-primary">Tallenna</button>
                <button type="submit" name="my_agg_force_import" class="button button-secondary">Pakota tuonti</button>
            </p>
        </form>

        <!-- Tuontiloki -->
        <h2>Tuontiloki</h2>

        <!-- MUUTOS: Näytä vain muutokset -kytkin -->
        <label style="display:inline-block;margin:8px 0 12px;">
            <input type="checkbox" id="map-only-changes" <?php checked($only_changes, 1); ?>>
            Näytä vain muutokset (lisäykset/poistot ja virheet)
        </label>

        <?php if (!empty($logs_to_display)): ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Aika</th>
                        <th>Lisätyt</th>
                        <th>Poistetut</th>
                        <th>Päivitetyt</th>
                        <th>Virhe</th>
                        <th>Muutokset</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs_to_display as $log): ?>
                        <?php
                        $addedCount   = !empty($log['added'])   ? (is_array($log['added'])   ? count($log['added'])   : (int)$log['added'])   : 0;
                        $removedCount = !empty($log['removed']) ? (is_array($log['removed']) ? count($log['removed']) : (int)$log['removed']) : 0;
                        $updatedCount = !empty($log['updated']) ? (is_array($log['updated']) ? count($log['updated']) : (int)$log['updated']) : 0;
                        $hasError     = !empty($log['error']);
                        $isChange     = ($addedCount > 0 || $removedCount > 0 || $hasError);
                        ?>
                        <tr class="<?php echo $isChange ? 'is-change' : 'is-nochange'; ?>">
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><?php echo $addedCount   ? esc_html($addedCount).' lisätty'   : '-'; ?></td>
                            <td><?php echo $removedCount ? esc_html($removedCount).' poistettu' : '-'; ?></td>
                            <td><?php echo $updatedCount ? esc_html($updatedCount).' päivitetty' : '-'; ?></td>
                            <td><?php echo $hasError ? esc_html($log['error']) : '-'; ?></td>
                            <td>
                                <?php 
                                // Tulostetaan changes-taulukko (jos rakenteessa on edelleen mukana)
                                if (!empty($log['changes'])) {
                                    if (is_array($log['changes'])) {
                                        foreach ($log['changes'] as $one_change) {
                                            echo '<div>'.esc_html($one_change).'</div>';
                                        }
                                    } else {
                                        echo esc_html($log['changes']);
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base'      => add_query_arg('log_page', '%#%', $base_url),
                        'format'    => '',
                        'current'   => max(1, $current_page),
                        'total'     => max(1, $total_pages),
                        'prev_text' => '&laquo; Edellinen',
                        'next_text' => 'Seuraava &raquo;',
                    ));
                    ?>
                </div>
            </div>
        <?php else: ?>
            <p>Ei tuontilokeja saatavilla.</p>
        <?php endif; ?>
    </div>

    <style>
        /* Vähennä “ei muutosta” -rivien visuaalista painoa */
        .is-nochange td { opacity:.7; }
        .is-change td { font-weight: 600; }
    </style>

    <script>
    (function(){
        var cb = document.getElementById('map-only-changes');
        if(!cb) return;
        cb.addEventListener('change', function(){
            var url = new URL('<?php echo esc_js($base_url); ?>', window.location.origin);
            // Jos checkbox päällä, lisätään only_changes=1, muuten poistetaan parametri (tai asetetaan 0)
            if (cb.checked) {
                url.searchParams.set('only_changes', '1');
            } else {
                // Voit valita: poista parametri tai aseta 0
                url.searchParams.delete('only_changes');
                // url.searchParams.set('only_changes', '0');
            }
            // Sivutus takaisin alkuun
            url.searchParams.delete('log_page');
            window.location.href = url.toString();
        });
    })();
    </script>
    <?php
}
?>