<?php
/**
 * Plugin Name: WD29 WooCommerce PrestaShop Bridge
 * Description: Direct signed webhooks, initial catalog reconciliation and durable synchronization with PrestaShop.
 * Version: 0.6.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Webdesign29
 * License: GPL-2.0-or-later
 * Text Domain: wd29-bridge
 */
defined('ABSPATH') || exit;
const WD29_BRIDGE_VERSION = '0.6.1';
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Licence.php';
require_once __DIR__ . '/includes/LicenceAdmin.php';
require_once __DIR__ . '/includes/Updater.php';
require_once __DIR__ . '/includes/Engine.php';
require_once __DIR__ . '/includes/ManualOrdersAdmin.php';
require_once __DIR__ . '/includes/ManualRecordsAdmin.php';
require_once __DIR__ . '/includes/AdminDesign.php';
require_once __DIR__ . '/includes/OrderConflicts.php';
require_once __DIR__ . '/includes/CustomerAccounts.php';
require_once __DIR__ . '/includes/Refunds.php';
require_once __DIR__ . '/includes/Suppliers.php';
require_once __DIR__ . '/includes/Gallery.php';
require_once __DIR__ . '/includes/DiagnosticsAdmin.php';
require_once __DIR__ . '/includes/WooAdapter.php';
require_once __DIR__ . '/includes/CustomFields.php';
require_once __DIR__ . '/includes/CustomFieldsAdmin.php';

function wd29_bridge(): \WD29\Bridge\Engine {
    static $engine;
    if (!$engine) {
        $adapter = new \WD29\Bridge\WooAdapter();
        $engine = new \WD29\Bridge\Engine($adapter);
        $adapter->engine = $engine;
    }
    return $engine;
}
add_action('init', ['\WD29\Bridge\WooAdapter','registerSourceStatuses']);
(new \WD29\Bridge\Updater('wd29_bridge', __FILE__))->register();
// One admin notice while live mode is paused or the grace period is running (reads one option).
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wd29-bridge')) . '">Réglages</a>');
    return $links;
});
add_action('admin_notices', function () {
    if (!current_user_can('manage_options') || (($_GET['page'] ?? '') === 'wd29-bridge')) { return; }
    $engine = wd29_bridge();
    if (($engine->config()['mode'] ?? 'disabled') === 'disabled') { return; }
    $s = $engine->licence()->summary();
    if ($s['tone'] === 'ok') { return; }
    echo '<div class="notice notice-' . ($s['live'] ? 'warning' : 'error') . '"><p><strong>Inklura Sync : ' . esc_html($s['label']) . '.</strong> ' . esc_html($s['text']) . ' <a href="' . esc_url(admin_url('admin.php?page=wd29-bridge#wd-licence')) . '">Licence</a></p></div>';
});
add_filter('wc_order_statuses', ['\WD29\Bridge\WooAdapter','statusList']);
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce') || !extension_loaded('curl')) { wp_die('WooCommerce et l\'extension PHP cURL sont nécessaires.'); }
    wd29_bridge()->install();
    add_option('wd29_bridge_config', ['mode' => 'disabled', 'peer' => '', 'secret' => ''], '', false);
    if (!wp_next_scheduled('wd29_bridge_tick')) { wp_schedule_event(time() + 60, 'wd29_minute', 'wd29_bridge_tick'); }
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('wd29_bridge_tick'); });
add_filter('cron_schedules', function ($schedules) { $schedules['wd29_minute'] = ['interval' => 60, 'display' => 'Every minute (WD29 bridge)']; return $schedules; });
add_action('plugins_loaded', function () {
    if (get_option('wd29_bridge_schema') !== '6') { wd29_bridge()->install(); update_option('wd29_bridge_schema','6',false); }
}, 30);
add_action('wd29_bridge_tick', function () { if (function_exists('wc_get_product')) { wd29_bridge()->tick(); } });
function wd29_bridge_capture_later(string $kind, int $id): void {
    static $pending = [], $registered = false;
    $engine = wd29_bridge();
    if ($id < 1 || !$engine->enabled() || ($kind === 'product' && ($engine->catalogApplying || $engine->stockApplying)) || ($kind === 'order' && $engine->orderApplying)) { return; }
    $pending[$kind][$id] = $id;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () use (&$pending) {
            foreach (['product','order'] as $kind) { foreach ($pending[$kind] ?? [] as $id) { wd29_bridge()->capture($kind, $id); } }
        });
    }
}
add_action('woocommerce_after_product_object_save', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id() ?: $product->get_id()); }, 100);
add_action('woocommerce_product_set_stock', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id() ?: $product->get_id()); }, 100);
add_action('woocommerce_variation_set_stock', function ($product) { wd29_bridge_capture_later('product', $product->get_parent_id()); }, 100);
add_action('woocommerce_after_order_object_save', function ($order) { if ($order->get_type() === 'shop_order') { wd29_bridge_capture_later('order', $order->get_id()); } }, 100);
foreach (['woocommerce_can_reduce_order_stock', 'woocommerce_can_restore_order_stock'] as $filter) {
    add_filter($filter, function ($allowed, $order) { return $order && $order->get_meta('_wd29_bridge_origin') ? false : $allowed; }, 100, 2);
}
foreach (['new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note'] as $email) {
    add_filter('woocommerce_email_enabled_' . $email, function ($enabled, $order) {
        return wd29_bridge()->orderApplying || ($order instanceof \WC_Order && $order->get_meta('_wd29_bridge_origin')) ? false : $enabled;
    }, 100, 2);
}
add_action('rest_api_init', function () {
    register_rest_route('wd29-bridge/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => function ($request) {
            try {
                \WD29\Bridge\Protocol::verify($request->get_body(), wd29_bridge()->config()['secret'] ?? '', [
                    'timestamp' => $request->get_header('x-wd29-timestamp'), 'nonce' => $request->get_header('x-wd29-nonce'), 'signature' => $request->get_header('x-wd29-signature')]);
                return true;
            } catch (\Throwable $e) { return new \WP_Error('bridge_auth', 'Invalid signed request.', ['status' => 401]); }
        },
        'callback' => function ($request) {
            try { return rest_ensure_response(wd29_bridge()->receive($request->get_json_params())); }
            catch (\Throwable $e) { return new \WP_Error('bridge_request', 'Request could not be processed.', ['status' => 400]); }
        },
    ]);
});
add_action('admin_menu', function () { add_submenu_page('woocommerce', 'PrestaShop Bridge', 'PrestaShop Bridge', 'manage_options', 'wd29-bridge', 'wd29_bridge_admin'); });

add_action('admin_post_wd29_sync_batch',function(){
    if(!current_user_can('manage_options')){wp_send_json(['ok'=>false,'error'=>'Accès refusé.'],403);}
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){wp_send_json(['ok'=>false,'error'=>'POST requis.'],405);}
    check_admin_referer('wd29_bridge_admin');
    try{$result=\WD29\Bridge\ManualRecordsAdmin::batch(wd29_bridge(),wp_unslash($_POST));}
    catch(\Throwable $error){$result=['ok'=>false,'error'=>$error->getMessage()];}
    nocache_headers();wp_send_json($result);
});

function wd29_bridge_admin(): void {
    if (!current_user_can('manage_options')) { return; }
    $engine = wd29_bridge();
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('wd29_bridge_admin');
        try {
            $action = sanitize_key($_POST['bridge_action'] ?? '');
            $recordMessage=\WD29\Bridge\ManualRecordsAdmin::handle($engine,wp_unslash($_POST));
            $licenceMessage = \WD29\Bridge\LicenceAdmin::handle($engine, $action, (string) wp_unslash($_POST['licence_key'] ?? ''));
            $manualMessage = \WD29\Bridge\ManualOrdersAdmin::handle($engine, wp_unslash($_POST));
            if ($recordMessage !== null) { $message=$recordMessage; }
            elseif ($manualMessage !== null) { $message=$manualMessage; }
            elseif ($licenceMessage !== null) { $message = $licenceMessage; \WD29\Bridge\Updater::forget(); }
            elseif ($action === 'save') {
                $config = $engine->config();
                $mode = sanitize_key($_POST['mode'] ?? 'disabled');
                if (!in_array($mode, ['disabled', 'audit', 'live'], true)) { throw new \RuntimeException('Mode invalide.'); }
                $peer = trim(wp_unslash($_POST['peer'] ?? ''));
                if ($peer !== '') { \WD29\Bridge\Protocol::publicEndpoint($peer); }
                $secret = trim(wp_unslash($_POST['secret'] ?? ''));
                if ($secret !== '' && strlen($secret) < 32) { throw new \RuntimeException('Le secret partagé doit compter au moins 32 caractères.'); }
                $policy = sanitize_key($_POST['conflict_policy'] ?? 'review');
                if (!in_array($policy, ['review','woo','ps'], true)) { throw new \RuntimeException('Règle de conflit invalide.'); }
                $engine->validateSettings($mode, $peer, $secret !== '' ? $secret : ($config['secret'] ?? ''));
                update_option('wd29_bridge_config', ['mode' => $mode, 'peer' => $peer, 'secret' => $secret !== '' ? $secret : ($config['secret'] ?? ''), 'conflict_policy' => $policy, 'native_customers'=>!empty($_POST['native_customers']), 'sync_gallery_removals'=>!empty($_POST['sync_gallery_removals'])], false);
                $message = 'Réglages enregistrés.';
            } elseif ($action === 'restore_gallery') {
                $engine->adapter->restoreGallery(trim(wp_unslash($_POST['gallery_record']??''))); $message='Images de galerie rattachées de nouveau ; produit capturé.';
            } elseif ($action === 'save_field_rows') {
                update_option('wd29_bridge_custom_fields', \WD29\Bridge\CustomFieldsAdmin::submitted(wp_unslash($_POST['field_rows']??[])),false);
                $message='Correspondances des champs personnalisés enregistrées.';
            } elseif ($action === 'save_custom_fields') {
                $rules=json_decode(wp_unslash($_POST['custom_fields']??'[]'),true,32,JSON_THROW_ON_ERROR);
                update_option('wd29_bridge_custom_fields', \WD29\Bridge\CustomFields::rules($rules),false);
                $message='Liste des champs enregistrée. Capturez les catalogues pour synchroniser les champs choisis.';
            } elseif ($action === 'save_statuses') {
                $mapping=[]; $submitted=wp_unslash($_POST['status_mapping']??[]);
                if (!is_array($submitted)) { throw new \RuntimeException('Correspondances de statuts invalides.'); }
                foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
                    $target=(string)($submitted[$key]??$key);
                    if ($target!==$key && !in_array($target,['pending','on-hold','processing','completed','cancelled','refunded','failed'],true)) { throw new \RuntimeException('Statut de destination invalide.'); }
                    $mapping[$key]=$target;
                }
                update_option('wd29_bridge_status_mapping',$mapping,false);
                $message='Correspondances de statuts enregistrées ; miroirs mis à jour : '.$engine->adapter->applyStatusMappings();
            } elseif ($action === 'normalize_stock') { $message=wp_json_encode($engine->adapter->normalizeUnknownVariantStock(max(0,(int)($_POST['offset']??0)))); } elseif ($action === 'health') { $message = wp_json_encode($engine->peer(['op' => 'health'])); }
            elseif ($action === 'tick') { $engine->tick(); $message = 'File traitée : consultez le résultat ci-dessous.'; }
            elseif ($action === 'retry') { $engine->retry(); $message = 'Événements en échec remis en file.'; }
            elseif ($action === 'resolve_order_upgrades') { $message = 'Mises à jour de commandes équivalentes remises en file : ' . $engine->retryEquivalentOrderConflicts(); }
                elseif ($action === 'resolve_catalog') { $engine->retryCatalogConflicts(); $message = 'Conflits de catalogue remis en file avec la priorité choisie.'; }
            elseif (in_array($action, ['seed_products','seed_orders','seed_customers'], true)) {
                $kind = $action === 'seed_customers' ? 'customer' : ($action === 'seed_orders' ? 'order' : 'product');
                $offset = max(0, (int) ($_POST['offset'] ?? 0));
                $count = $engine->seed($kind, $offset);
                $peer = $engine->peer(['op' => 'seed', 'kind' => $kind, 'offset' => $offset]);
                $message = 'Lot capturé : ' . $count . ' sur WordPress, ' . $peer['count'] . ' sur PrestaShop. Offset suivant : ' . ($offset + 10);
            }
        } catch (\Throwable $e) { $message = $e->getMessage(); }
    }
    $config = $engine->config();
    ob_start();
    echo '<div class="wrap"><h1>PrestaShop Bridge</h1><p>Connexion directe. En mode audit, les modifications reçues sont mises en file sans être appliquées. Chaque fiche garde son identité d\'origine ; une UGS vide ne sert jamais à rapprocher deux fiches.</p>';
    if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; }
    echo '<p><strong>Webhook de cette boutique :</strong> <code>' . esc_html(rest_url('wd29-bridge/v1/webhook')) . '</code></p>';
    echo '<form method="post">'; wp_nonce_field('wd29_bridge_admin');
    echo '<p><label>Mode <select name="mode">';
    foreach (['disabled' => 'Arrêtée', 'audit' => 'Audit : réception sans écriture', 'live' => 'Synchronisation live'] as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($config['mode'] ?? 'disabled', $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p><p><label>Webhook PrestaShop <input class="large-text" type="url" name="peer" value="' . esc_attr($config['peer'] ?? '') . '"></label></p>';
    echo '<p><label>Modifications simultanées du catalogue <select name="conflict_policy">';
    foreach (['review'=>'Mettre en pause pour examen','woo'=>'Priorité à WooCommerce','ps'=>'Priorité à PrestaShop'] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($config['conflict_policy'] ?? 'review',$value,false).'>'.esc_html($label).'</option>'; }
    echo '</select></label> Choisissez la même règle sur les deux boutiques.</p>';
    echo '<p><label>Secret partagé <input type="password" name="secret" autocomplete="new-password" value=""></label> Laissez vide pour conserver le secret enregistré.</p>';
    echo '<p><label><input type="checkbox" name="native_customers" value="1" '.checked(!empty($config['native_customers']),true,false).'> Créer des comptes clients natifs pour les clients inscrits de l\'autre boutique (mots de passe indépendants, aucune fusion par e-mail)</label></p>';
    echo '<p><label><input type="checkbox" name="sync_gallery_removals" value="1" '.checked(!empty($config['sync_gallery_removals']),true,false).'> Détacher les images importées retirées chez le partenaire (fichiers et images ajoutées à la main conservés)</label></p>';
    echo '<button class="button button-primary" name="bridge_action" value="save">Enregistrer les réglages</button></form><hr><form method="post">';
    wp_nonce_field('wd29_bridge_admin');
    echo '<p><label>Identité du produit ou de la déclinaison <input name="gallery_record" placeholder="ps:product:123"></label> <button class="button" name="bridge_action" value="restore_gallery">Rattacher les images détachées</button></p><p>Rattache les images conservées pour cette fiche ; le produit est ensuite capturé.</p>';
    echo '<p><label>Offset du lot <input type="number" min="0" name="offset" value="0"></label> Chaque lot contient jusqu\'à 10 fiches par boutique.</p>';
    foreach (['health' => 'Tester la connexion', 'seed_products' => 'Catalogues : WordPress ↔ PrestaShop', 'seed_orders' => 'Commandes : WordPress ↔ PrestaShop', 'seed_customers' => 'Contacts : WordPress ↔ PrestaShop', 'tick' => 'Traiter les échanges de WordPress', 'retry' => 'Relancer les échecs', 'resolve_order_upgrades'=>'Relancer les mises à jour de commandes équivalentes', 'resolve_catalog'=>'Relancer les conflits de catalogue avec la priorité choisie', 'normalize_stock'=>'Mettre à zéro les quantités inconnues des déclinaisons Woo'] as $value => $label) {
        echo '<button class="button" name="bridge_action" value="' . esc_attr($value) . '">' . esc_html($label) . '</button> ';
    }
    echo '</form>';
    \WD29\Bridge\CustomFieldsAdmin::render();
    echo '<h2>Statuts des commandes</h2><p>Les statuts PrestaShop inconnus sont créés automatiquement avec leur libellé d\'origine. Associez-les si besoin à un statut WooCommerce existant : seul l\'affichage du miroir change, le statut PrestaShop d\'origine reste identique.</p><form method="post">';
    wp_nonce_field('wd29_bridge_admin');
    $statusMappings=(array)get_option('wd29_bridge_status_mapping',[]);
    foreach ((array)get_option('wd29_bridge_source_statuses',[]) as $key=>$label) {
        echo '<p><label>'.esc_html($label).' <select name="status_mapping['.esc_attr($key).']">';
        foreach ([$key=>'Automatique (PrestaShop : '.$label,'pending'=>'Attente de paiement','on-hold'=>'En attente','processing'=>'En cours','completed'=>'Terminée','cancelled'=>'Annulée','refunded'=>'Remboursée','failed'=>'Échouée'] as $value=>$text) {
            echo '<option value="'.esc_attr($value).'" '.selected($statusMappings[$key]??$key,$value,false).'>'.esc_html($text).'</option>';
        }
        echo '</select></label></p>';
    }
    echo '<button class="button" name="bridge_action" value="save_statuses">Enregistrer les statuts</button>';
    echo '</form>'.\WD29\Bridge\DiagnosticsAdmin::render($engine).'<p>Dernier message enregistré (l\'état actuel est dans les diagnostics) : ' . esc_html(get_option('wd29_bridge_notice', '')) . '</p><h2>Journal des événements</h2><table class="widefat"><thead><tr>';
    foreach (['N°','Sens','Type','Identité','État','Essais','Erreur','Créé le'] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->report() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string) $cell) . '</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Catalogue</h2><p>Derniers instantanés transmis ; un stock inconnu n\'est pas un stock nul. Jusqu\'à 200 produits.</p><table class="widefat"><thead><tr>';
    foreach (['Origine','ID local','Nom','Marques','Étiquettes','Type','Prix','Promo','Taxe','Base','Stock initial','Identifiants','Dimensions (cm)','Caractéristiques','Images des déclinaisons','Archivé','Achat HT','Fournisseur','SEO'] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->catalogAudit() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>' . esc_html((string)$cell) . '</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Commandes</h2><p>Les lignes historiques sans lien gardent leurs détails d\'origine ; le lien au catalogue se fait dès que le produit existe.</p><table class="widefat"><thead><tr>';
    foreach (['Origine','ID local','Total','Devise','Statut','Lignes','Lignes sans lien'] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->orderReport() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo '</tbody></table><h2>Contacts clients</h2><p>Copies en lecture seule, à modifier sur leur boutique d\'origine. Aucun compte, mot de passe ni consentement marketing n\'est copié ; l\'e-mail ne sert jamais à fusionner deux fiches. Jusqu\'à 200 contacts.</p><table class="widefat"><thead><tr>';
    foreach (['Origine','Nom','E-mail','Téléphone','Société','Facturation','Adresses','Livraison','Type'] as $heading) { echo '<th>'.esc_html($heading).'</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($engine->customerReport() as $row) { echo '<tr>'; foreach ($row as $cell) { echo '<td>'.esc_html((string)$cell).'</td>'; } echo '</tr>'; }
    echo '</tbody></table>';
    $update = $engine->licence()->updateAvailable();
    echo \WD29\Bridge\LicenceAdmin::render($engine, wp_nonce_field('wd29_bridge_admin', '_wpnonce', true, false),
        $update ? '<p>Version ' . esc_html($update) . ' disponible. <a href="' . esc_url(admin_url('plugins.php')) . '">Mettre à jour depuis Extensions</a>.</p>' : '');
    echo '</div>';
    $html=ob_get_clean();$end=strrpos($html,'</div>');
    $panel=\WD29\Bridge\ManualRecordsAdmin::render($engine,wp_nonce_field('wd29_bridge_admin','_wpnonce',true,false),wp_unslash($_POST),admin_url('admin-post.php?action=wd29_sync_batch')).\WD29\Bridge\ManualOrdersAdmin::render($engine,wp_nonce_field('wd29_bridge_admin','_wpnonce',true,false),wp_unslash($_POST));
    if($end!==false){$html=substr_replace($html,$panel,$end,0);}
    echo \WD29\Bridge\AdminDesign::render($html, $engine, 'woo');
}

add_action('woocommerce_product_options_general_product_data',function(){
    echo '<div class="options_group">';
    woocommerce_wp_text_input(['id'=>'_wd29_purchase_net','label'=>'Prix d’achat HT','description'=>'Coût unitaire hors taxes synchronisé avec PrestaShop. Laisser vide si inconnu.','desc_tip'=>true,'type'=>'number','custom_attributes'=>['step'=>'0.000001','min'=>'0']]);
    woocommerce_wp_text_input(['id'=>'_wd29_supplier_name','label'=>'Fournisseur principal']);
    woocommerce_wp_text_input(['id'=>'_wd29_supplier_reference','label'=>'Référence fournisseur']);
    echo '</div>';
});
add_action('woocommerce_admin_process_product_object',function($product){
    foreach (['_wd29_purchase_net','_wd29_supplier_name','_wd29_supplier_reference'] as $field) {
        if (!isset($_POST[$field])) { continue; }
        $value=sanitize_text_field(wp_unslash($_POST[$field]));
        if ($field==='_wd29_purchase_net' && $value==='') { $product->delete_meta_data($field); continue; }
        if ($field==='_wd29_purchase_net' && (!is_numeric($value)||(float)$value<0)) { continue; }
        $product->update_meta_data($field,$value);
    }
});
