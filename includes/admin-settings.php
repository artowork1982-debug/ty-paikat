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

    // Lisää eksplisiittinen submenu Asetukset-linkki
    add_submenu_page(
        'my-agg-settings',
        'Aggregator Settings',
        'Asetukset',
        'manage_options',
        'my-agg-settings',
        'map_render_settings_page'
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

    // Tyhjennä HTML-välimuisti
    if (isset($_POST['my_agg_clear_cache'])) {
        check_admin_referer('my_agg_settings_nonce');
        update_option('my_agg_cache_bump', time());
        echo '<div class="notice notice-success is-dismissible"><p>HTML-välimuisti tyhjennetty!</p></div>';
    }

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

    <?php
    // Get sync stats
    $sync_stats = get_option('my_agg_last_sync_stats', array());
    $last_sync_time = isset($sync_stats['time']) ? $sync_stats['time'] : 'Ei vielä synkronoitu';
    $last_added = isset($sync_stats['added']) ? $sync_stats['added'] : 0;
    $last_removed = isset($sync_stats['removed']) ? $sync_stats['removed'] : 0;
    $last_updated = isset($sync_stats['updated']) ? $sync_stats['updated'] : 0;
    
    // Get next scheduled sync
    $next_scheduled = wp_next_scheduled('map_aggregator_cron_hook');
    $next_sync_time = $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Ei ajastettua';
    ?>
    <div class="wrap map-admin-wrap">
        <div class="map-admin-header">
            <span class="dashicons dashicons-rss" style="font-size: 28px; color: #2271b1;"></span>
            <h1>Aggregator Plugin Settings</h1>
        </div>

        <form method="post">
            <?php wp_nonce_field('my_agg_settings_nonce'); ?>
            
            <!-- Section 1: RSS Feed -->
            <div class="map-card">
                <div class="map-card__header">
                    <span class="map-card__icon dashicons dashicons-rss"></span>
                    <h2 class="map-card__title">📡 RSS-syöte</h2>
                    <p class="map-card__desc">Syötteen URL-osoite ja päivitystiheys</p>
                </div>
                <div class="map-card__body">
                    <div class="map-field">
                        <label class="map-field__label" for="feed_url">RSS Feed URL</label>
                        <input type="text" id="feed_url" name="feed_url" value="<?php echo esc_attr($opts['feed_url']); ?>" class="map-field__input map-field__input--wide">
                        <p class="map-field__help">Syötä RSS-syötteen osoite, josta työpaikat haetaan.</p>
                    </div>
                    <div class="map-field">
                        <label class="map-field__label" for="update_frequency">Päivitystiheys</label>
                        <select id="update_frequency" name="update_frequency" class="map-field__select">
                            <option value="hourly" <?php selected($opts['update_frequency'], 'hourly'); ?>>Tunnin välein</option>
                            <option value="3_hours" <?php selected($opts['update_frequency'], '3_hours'); ?>>Kolmen tunnin välein</option>
                            <option value="daily" <?php selected($opts['update_frequency'], 'daily'); ?>>Päivittäin</option>
                        </select>
                    </div>
                    <div class="map-field">
                        <button type="submit" name="my_agg_force_import" class="button button-secondary map-btn map-btn--secondary">
                            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                            Pakota tuonti
                        </button>
                    </div>
                </div>
            </div>

            <!-- Section 2: List Settings -->
            <div class="map-card">
                <div class="map-card__header">
                    <span class="map-card__icon dashicons dashicons-list-view"></span>
                    <h2 class="map-card__title">📋 Listausasetukset</h2>
                    <p class="map-card__desc">Listauksen määrä ja järjestys</p>
                </div>
                <div class="map-card__body">
                    <div class="map-field">
                        <label class="map-field__label" for="items_count">Näytettävien työpaikkojen määrä</label>
                        <input type="number" id="items_count" name="items_count" value="<?php echo esc_attr($opts['items_count']); ?>" min="1" class="map-field__input">
                    </div>
                    <div class="map-field">
                        <label class="map-field__label" for="order_by">Listauksen järjestys</label>
                        <select id="order_by" name="order_by" class="map-field__select">
                            <option value="date" <?php selected($opts['order_by'], 'date'); ?>>Päivämäärä</option>
                            <option value="title" <?php selected($opts['order_by'], 'title'); ?>>Aakkosjärjestys</option>
                        </select>
                    </div>
                    <div class="map-field">
                        <label class="map-field__label" for="order">Järjestyksen suunta</label>
                        <select id="order" name="order" class="map-field__select">
                            <option value="ASC" <?php selected($opts['order'], 'ASC'); ?>>Nouseva</option>
                            <option value="DESC" <?php selected($opts['order'], 'DESC'); ?>>Laskeva</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section 3: Filters -->
            <div class="map-card">
                <div class="map-card__header">
                    <span class="map-card__icon dashicons dashicons-filter"></span>
                    <h2 class="map-card__title">🚫 Suodattimet</h2>
                    <p class="map-card__desc">Kielletyt otsikot ja suodatussäännöt</p>
                </div>
                <div class="map-card__body">
                    <div class="map-field">
                        <label class="map-field__label" for="forbidden_titles">Kielletyt otsikot</label>
                        <textarea id="forbidden_titles" name="forbidden_titles" rows="4" class="map-field__textarea"><?php echo esc_textarea($opts['forbidden_titles']); ?></textarea>
                        <p class="map-field__help">Yksi per rivi. Työpaikat, joiden otsikko täsmää näihin, ohitetaan.</p>
                    </div>
                </div>
            </div>

            <!-- Section 4: Appearance -->
            <div class="map-card">
                <div class="map-card__header">
                    <span class="map-card__icon dashicons dashicons-admin-appearance"></span>
                    <h2 class="map-card__title">🎨 Ulkoasu</h2>
                    <p class="map-card__desc">Väriteemat ja visuaalinen tyyli</p>
                </div>
                <div class="map-card__body">
                    <div class="map-field">
                        <label class="map-field__label" for="link_color">Linkin väri</label>
                        <input type="text" id="link_color" name="link_color" value="<?php echo esc_attr($opts['link_color']); ?>" class="color-field map-field__input">
                    </div>
                    <div class="map-field">
                        <label class="map-field__label" for="link_hover_color">Hover-väri</label>
                        <input type="text" id="link_hover_color" name="link_hover_color" value="<?php echo esc_attr($opts['link_hover_color']); ?>" class="color-field map-field__input">
                    </div>
                    <div class="map-field">
                        <label class="map-field__label" for="description_text_color">Kuvaustekstin väri</label>
                        <input type="text" id="description_text_color" name="description_text_color" value="<?php echo esc_attr($opts['description_text_color']); ?>" class="color-field map-field__input">
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <p>
                <button type="submit" name="my_agg_save_settings" class="button button-primary map-btn map-btn--primary">
                    <span class="dashicons dashicons-saved" style="margin-top: 3px;"></span>
                    Tallenna asetukset
                </button>
            </p>
        </form>

        <!-- Section 5: Sync Status -->
        <div class="map-status-card">
            <div class="map-card__header" style="padding: 0 0 12px 0; border: none;">
                <span class="map-card__icon dashicons dashicons-chart-bar"></span>
                <h2 class="map-card__title">📊 Synkronoinnin tila</h2>
                <p class="map-card__desc">Viimeisimmän synkronoinnin tilastot</p>
            </div>
            <div class="map-status-card__grid">
                <div class="map-status-card__item">
                    <div class="map-status-card__value"><?php echo esc_html($last_added); ?></div>
                    <div class="map-status-card__label">Lisätty</div>
                </div>
                <div class="map-status-card__item">
                    <div class="map-status-card__value"><?php echo esc_html($last_removed); ?></div>
                    <div class="map-status-card__label">Poistettu</div>
                </div>
                <div class="map-status-card__item">
                    <div class="map-status-card__value"><?php echo esc_html($last_updated); ?></div>
                    <div class="map-status-card__label">Päivitetty</div>
                </div>
            </div>
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #c3dafe;">
                <p style="margin: 0 0 8px; font-size: 13px; color: #1d2327;">
                    <strong>Viimeisin synkronointi:</strong> <?php echo esc_html($last_sync_time); ?>
                </p>
                <p style="margin: 0 0 12px; font-size: 13px; color: #1d2327;">
                    <strong>Seuraava synkronointi:</strong> <?php echo esc_html($next_sync_time); ?>
                </p>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('my_agg_settings_nonce'); ?>
                    <button type="submit" name="my_agg_clear_cache" class="button button-secondary map-btn map-btn--secondary">
                        <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                        Tyhjennä HTML-välimuisti
                    </button>
                </form>
            </div>
        </div>

        <!-- Section 6: Import Log -->
        <div class="map-card">
            <div class="map-card__header">
                <span class="map-card__icon dashicons dashicons-media-text"></span>
                <h2 class="map-card__title">📝 Tuontiloki</h2>
                <p class="map-card__desc">Synkronointihistoria ja muutosloki</p>
            </div>
            <div class="map-card__body">
                <div style="margin-bottom: 16px;">
                    <label class="map-toggle">
                        <input type="checkbox" id="map-only-changes" <?php checked($only_changes, 1); ?>>
                        <span class="map-toggle__slider"></span>
                        <span class="map-toggle__label">Näytä vain muutokset (lisäykset/poistot ja virheet)</span>
                    </label>
                </div>

                <?php if (!empty($logs_to_display)): ?>
                    <table class="map-log-table">
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

                    <div class="tablenav" style="margin-top: 16px;">
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
                    <p style="color: #757575; font-style: italic;">Ei tuontilokeja saatavilla.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>


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