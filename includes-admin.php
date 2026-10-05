<?php
if (!defined('ABSPATH')) { exit; }

final class Convertino_Admin {
    public static function boot() {
        add_action('admin_menu', function () { add_menu_page('Convertino','Convertino','manage_options','convertino',[__CLASS__,'screen'],'dashicons-chart-area',58); });
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'toplevel_page_convertino') { return; }
            wp_enqueue_style('convertino-admin',plugins_url('assets/admin.css',CONVERTINO_FILE),[],Convertino_Core::VERSION);
            wp_enqueue_script('convertino-admin',plugins_url('assets/admin.js',CONVERTINO_FILE),[],Convertino_Core::VERSION,true);
        });
        add_action('admin_post_convertino_save',[__CLASS__,'save']);
    }
    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('You cannot change Convertino settings.'); }
        check_admin_referer('convertino_save');
        $page = absint($_POST['page_id'] ?? 0);
        if ($page && (get_post_type($page) !== 'page' || get_post_status($page) !== 'publish')) { wp_die('Choose a published page.'); }
        $buttons = [];
        $labels = (array)($_POST['button_label'] ?? []); $selectors = (array)($_POST['button_selector'] ?? []);
        foreach (array_slice($labels,0,20,true) as $i=>$raw) {
            $label = Convertino_Core::text(wp_unslash($raw),100);
            $selector = Convertino_Core::text(wp_unslash($selectors[$i] ?? ''),300);
            if (!$label || !$selector) { continue; }
            $key = substr(hash('sha256',$label.'|'.$selector),0,32);
            $buttons[$key] = compact('key','label','selector');
        }
        $ids = function ($field) { return array_values(array_unique(array_filter(array_map('absint',preg_split('/[\s,]+/',(string)wp_unslash($_POST[$field] ?? '')))))); };
        update_option('convertino_settings',['page_id'=>$page,'enabled'=>$page && !empty($_POST['enabled']),'buttons'=>array_values($buttons),'form_ids'=>$ids('form_ids'),'product_ids'=>$ids('product_ids')],false);
        $names = get_option('convertino_button_names',[]);
        foreach ($buttons as $button) { $names[$button['key']]=$button['label']; }
        update_option('convertino_button_names',$names,false);
        wp_safe_redirect(admin_url('admin.php?page=convertino&saved=1')); exit;
    }
    private static function rate($value,$total) { return $total ? number_format_i18n(100*$value/$total,1).'%' : '0%'; }
    private static function money($amount,$currency) {
        $digits = in_array($currency,['JPY','KRW','VND','CLP','BIF','DJF','GNF','KMF','PYG','RWF','UGX','VUV','XAF','XOF','XPF'],true) ? 0 : (in_array($currency,['BHD','KWD','OMR','JOD','TND'],true) ? 3 : 2);
        return $currency.' '.number_format_i18n($amount/(10**$digits),$digits);
    }
    public static function screen() {
        if (!current_user_can('manage_options')) { return; }
        $s = Convertino_Core::settings(); $selected = isset($_GET['report_page']) ? absint($_GET['report_page']) : (int)$s['page_id'];
        $pages = get_posts(['post_type'=>'page','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        $report = Convertino_Core::report($selected); $totals = [];
        foreach ($report['sources'] as $row) { foreach ($row as $key=>$value) { if ($key !== 'source') { $totals[$key] = ($totals[$key] ?? 0)+(int)$value; } } }
        $visits = $totals['visits'] ?? 0;
        $names = get_option('convertino_button_names',[]);
        $active = get_option('active_plugins',[]);
        $network = array_keys(get_site_option('active_sitewide_plugins',[])); $active = array_merge($active,$network);
        $available = function ($prefix) use ($active) { foreach ($active as $p) { if (str_starts_with($p,$prefix.'/')) { return true; } } return false; };
        ?>
        <div class="wrap convertino">
            <header class="convertino-header"><div><p class="convertino-brand">Convertino</p><h1>See what happens after the visit.</h1><p>One page. Its buttons, videos and conversions, compared by traffic source.</p></div><a class="button" href="#convertino-settings">Page setup</a></header>
            <?php if (!empty($_GET['saved'])): ?><div class="convertino-notice" role="status">Page setup saved. Previous results are preserved.</div><?php endif; ?>
            <?php if (!$s['enabled']): ?><div class="convertino-notice">Choose a page below and enable tracking to get started.</div><?php else: ?><div class="convertino-notice">Tracking <?php echo esc_html(get_the_title($s['page_id'])); ?> after analytics consent. Administrators and common bots are excluded.</div><?php endif; ?>
            <section class="convertino-section" aria-labelledby="convertino-overview"><div class="convertino-section-heading"><h2 id="convertino-overview">Page overview</h2><form method="get"><input type="hidden" name="page" value="convertino"><label for="report-page" class="screen-reader-text">Results for page</label><select id="report-page" name="report_page"><option value="0">Choose a page</option><?php foreach ($pages as $page): ?><option value="<?php echo (int)$page->ID; ?>" <?php selected($selected,$page->ID); ?>><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?></select> <button class="button">View results</button></form></div>
            <p class="convertino-muted">Results for measured visits in the last 180 days. A visit ends after 30 minutes without tracked activity.</p>
            <div class="convertino-metrics"><?php
            foreach (['visits'=>'Visits','views'=>'Page views','click_visits'=>'Visits with a button click','form_visits'=>'Visits with a form submission','video_visits'=>'Visits with a video play','purchase_visits'=>'Visits with a purchase'] as $key=>$label): ?>
                <div class="convertino-metric"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(number_format_i18n($totals[$key] ?? 0)); ?></strong></div>
            <?php endforeach; ?></div></section>
            <section class="convertino-section"><h2>Traffic sources</h2><p class="convertino-muted">Form and purchase rates count each visit once, even when it converts more than once.</p><div class="convertino-table-wrap"><table class="widefat"><thead><tr><th>Source</th><th>Visits</th><th>Button visits</th><th>Video visits</th><th>Form visits</th><th>Form rate</th><th>Purchase visits</th><th>Purchase rate</th><th>Net revenue</th></tr></thead><tbody>
            <?php if (!$report['sources']): ?><tr><td colspan="9" class="convertino-empty">Your first measured visit will appear here. No sample numbers are mixed into your results.</td></tr><?php endif; ?>
            <?php foreach ($report['sources'] as $row): $revenue=[]; foreach ($report['revenue'] as $money) { if ($money['source']===$row['source']) { $revenue[]=self::money((int)$money['amount'],$money['currency']); } } ?>
                <tr><th><?php echo esc_html($row['source']); ?></th><?php foreach (['visits','click_visits','video_visits','form_visits'] as $key): ?><td><?php echo esc_html(number_format_i18n($row[$key])); ?></td><?php endforeach; ?><td><?php echo esc_html(self::rate($row['form_visits'],$row['visits'])); ?></td><td><?php echo esc_html(number_format_i18n($row['purchase_visits'])); ?></td><td><?php echo esc_html(self::rate($row['purchase_visits'],$row['visits'])); ?></td><td><?php echo esc_html(implode(' / ',$revenue) ?: 'No purchases'); ?></td></tr>
            <?php endforeach; ?></tbody></table></div><p class="convertino-muted">UTM source takes priority over referrer. Direct / unknown includes visits where apps or privacy tools remove the source. AI referrals reveal the platform when supplied, never the visitor’s prompt.</p></section>
            <div class="convertino-columns"><section class="convertino-section"><h2>Which buttons get used?</h2><table class="widefat"><thead><tr><th>Button</th><th>Clicks</th><th>Visits</th><th>Visit rate</th></tr></thead><tbody>
                <?php if (!$report['buttons']): ?><tr><td colspan="4" class="convertino-empty">Name the buttons you want to track in Page setup.</td></tr><?php endif; ?>
                <?php foreach ($report['buttons'] as $button): ?><tr><th><?php echo esc_html($names[$button['target']] ?? 'Previously selected button'); ?></th><td><?php echo esc_html(number_format_i18n($button['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n($button['visits'])); ?></td><td><?php echo esc_html(self::rate($button['visits'],$visits)); ?></td></tr><?php endforeach; ?>
            </tbody></table><p class="convertino-muted">Every real click counts. Retries of the same event count once.</p></section>
            <section class="convertino-section"><h2>Connections</h2><ul class="convertino-connections"><?php foreach (['fluentform'=>'Fluent Forms','fluent-cart'=>'FluentCart','fluent-player'=>'FluentPlayer'] as $prefix=>$label): ?><li><strong><?php echo esc_html($label); ?></strong><span><?php echo $available($prefix) ? 'Available · needs an end-to-end test' : 'Not active on this site'; ?></span></li><?php endforeach; ?></ul><p class="convertino-muted">Forms use successful saved submissions. Purchases use paid live orders, with refunds deducted. Video tracking covers play and finish events from FluentPlayer media-player elements.</p><p class="convertino-muted">Video visits that reached the end: <strong><?php echo (int)($totals['completed_visits'] ?? 0); ?></strong>. Reaching the end does not prove the entire video was watched.</p></section></div>
            <section id="convertino-settings" class="convertino-section"><h2>Page setup</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="convertino_save"><?php wp_nonce_field('convertino_save'); ?>
                <div class="convertino-field"><label for="convertino-page">Page to track</label><select id="convertino-page" name="page_id"><option value="0">Choose one page</option><?php foreach ($pages as $page): ?><option value="<?php echo (int)$page->ID; ?>" <?php selected($s['page_id'],$page->ID); ?>><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?></select><p class="convertino-muted">Changing the page preserves earlier results. Use the overview’s page selector to view them.</p></div>
                <div class="convertino-field"><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>> Enable tracking after analytics consent</label></div>
                <h3>Selected buttons</h3><p class="convertino-muted">Give each button a name and a CSS selector, such as “Get started” and <code>#get-started</code>. Convertino never changes the buttons.</p>
                <div id="convertino-button-rows"><?php foreach ($s['buttons'] ?: [['label'=>'','selector'=>'']] as $button): self::button_row($button); endforeach; ?></div>
                <button type="button" class="button" id="convertino-add-button">Add button</button>
                <div class="convertino-columns convertino-fields"><div class="convertino-field"><label for="convertino-forms">Fluent Forms IDs</label><input id="convertino-forms" name="form_ids" type="text" value="<?php echo esc_attr(implode(', ',$s['form_ids'])); ?>" placeholder="For example: 3, 7"><p class="convertino-muted">Only these forms count as conversions. Leave empty to disable form tracking.</p></div>
                <div class="convertino-field"><label for="convertino-products">FluentCart product IDs</label><input id="convertino-products" name="product_ids" type="text" value="<?php echo esc_attr(implode(', ',$s['product_ids'])); ?>" placeholder="For example: 12"><p class="convertino-muted">Live orders containing only these products count. Mixed baskets, test payments and renewals are excluded in this first version.</p></div></div>
                <div class="convertino-consent"><h3>Consent and privacy</h3><p>Tracking waits for an analytics-consent signal. Connect your consent banner to <code>Convertino.setConsent(true)</code> when allowed, and <code>Convertino.setConsent(false)</code> when withdrawn. Complianz statistics consent is recognized automatically.</p><p>Uses a first-party session cookie, up to 30 minutes after activity. Data stays on this server for 180 days. No raw IP addresses, form answers, emails or visitor fingerprints are stored. Do Not Track and Global Privacy Control are respected. Local storage alone does not settle consent requirements.</p></div>
                <button class="button button-primary convertino-save">Save page setup</button>
            </form></section>
        </div>
        <template id="convertino-button-template"><?php self::button_row(['label'=>'','selector'=>'']); ?></template>
        <?php
    }
    private static function button_row($button) { ?>
        <div class="convertino-button-row"><label>Button name<input type="text" name="button_label[]" maxlength="100" value="<?php echo esc_attr($button['label']); ?>" placeholder="Get started"></label><label>CSS selector<input type="text" name="button_selector[]" maxlength="300" value="<?php echo esc_attr($button['selector']); ?>" placeholder="#get-started"></label><button type="button" class="button convertino-remove-button">Remove button</button><span class="convertino-selector-error" role="status"></span></div>
    <?php }
}
