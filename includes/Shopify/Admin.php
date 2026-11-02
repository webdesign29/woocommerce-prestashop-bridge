<?php
namespace WD29\Bridge\Shopify;
require_once __DIR__ . '/../I18n.php';
require_once __DIR__.'/Client.php';
require_once __DIR__.'/Model.php';
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/Exports.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Promotions.php';
require_once __DIR__.'/Connector.php';
require_once __DIR__.'/Orders.php';
require_once __DIR__.'/OrderDetails.php';
require_once __DIR__.'/Coupons.php';
require_once __DIR__.'/Woo.php';
require_once __DIR__.'/Account.php';

final class Admin
{
    private static $applying=false;
    public static function config(): array { return (array)get_option('inklura_shopify_config',['mode'=>'disabled']); }
    public static function repo(): Repository { return new Repository(new \WD29\Bridge\WooAdapter()); }
    public static function connector(): Connector
    {
        $config=self::config();
        $api=new Client($config,null,static function($token,$expires){
            $current=self::config(); $current['access_token']=$token; $current['token_expires_at']=$expires; update_option('inklura_shopify_config',$current,false);
        });
        return new Connector(self::repo(),$api,new Woo(),$config);
    }
    public static function register(): void
    {
        add_action('admin_menu',static function(){add_submenu_page('woocommerce','Shopify — Inklura Sync','Shopify Sync','manage_options','inklura-shopify',[self::class,'render']);});
        add_action('init',static function(){
            if (get_option('inklura_shopify_schema')!=='1') { self::repo()->install(); update_option('inklura_shopify_schema','1',false); }
            foreach (self::statuses([]) as $key=>$label) { register_post_status($key,['label'=>$label,'public'=>false,'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true]); }
        });
        add_filter('wc_order_statuses',[self::class,'statuses']);
        add_action('wd29_bridge_tick',[self::class,'tick'],20);
        add_action('rest_api_init',static function(){register_rest_route('inklura-sync/v1','/shopify/webhook',['methods'=>'POST','permission_callback'=>[self::class,'permission'],'callback'=>[self::class,'receive']]);});
        add_action('rest_api_init',static function(){register_rest_route('inklura-sync/v1','/shopify/account',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>[Account::class,'response']]);});
        add_filter('rest_pre_serve_request',[Account::class,'serve'],10,4);
        add_action('woocommerce_after_product_object_save',static function($p){self::capture('product',(int)($p->get_parent_id()?:$p->get_id()));},200);
        foreach (['woocommerce_product_set_stock','woocommerce_variation_set_stock'] as $hook) { add_action($hook,static function($p){self::capture('product',(int)($p->get_parent_id()?:$p->get_id()));},200); }
        add_action('profile_update',static function($id){self::capture('customer',(int)$id);},200);
        add_action('user_register',static function($id){self::capture('customer',(int)$id);},200);
        add_action('woocommerce_customer_object_updated_props',static function($c){self::capture('customer',(int)$c->get_id());},200);
        foreach(['woocommerce_new_coupon','woocommerce_update_coupon']as$hook){add_action($hook,static function($id){self::capture('coupon',(int)$id);},200);}
        add_filter('woocommerce_coupon_is_valid',static function($valid,$coupon){if($coupon->get_meta('_inklura_shopify_coupon_held'))throw new \Exception(\WD29\Bridge\I18n::t('This Shopify code is paused while its rules are reviewed.'));return $valid;},1,2);
        foreach (['woocommerce_can_reduce_order_stock','woocommerce_can_restore_order_stock'] as $filter) {
            add_filter($filter,static function($allowed,$order){return $order && $order->get_meta('_inklura_shopify_order')?false:$allowed;},999,2);
        }
        add_action('woocommerce_admin_order_data_after_order_details',static function($order){
            if (!$order->get_meta('_inklura_shopify_order')) { return; }
            echo '<p><strong>Shopify</strong> '.esc_html($order->get_meta('_inklura_shopify_number')).'<br>'.esc_html($order->get_meta('_inklura_shopify_financial_status')).' · '.esc_html($order->get_meta('_inklura_shopify_fulfillment_status')).\WD29\Bridge\I18n::t('<br>Read-only copy: manage payments, refunds and shipments on Shopify.</p>');
        });
        add_action('woocommerce_admin_order_data_after_order_details',static function($order){if($order->get_meta('_inklura_shopify_order'))OrderDetails::render($order);});
    }
    public static function statuses(array $statuses): array
    {
        return $statuses+['wc-shopify-paid'=>\WD29\Bridge\I18n::t('Shopify: paid'),'wc-shopify-pending'=>\WD29\Bridge\I18n::t('Shopify: pending'),'wc-shopify-cancel'=>\WD29\Bridge\I18n::t('Shopify: cancelled'),'wc-shopify-refund'=>\WD29\Bridge\I18n::t('Shopify: refunded')];
    }
    private static function headers($request): array
    {
        return ['hmac'=>$request->get_header('x-shopify-hmac-sha256'),'shop'=>$request->get_header('x-shopify-shop-domain'),'id'=>$request->get_header('x-shopify-webhook-id'),'topic'=>$request->get_header('x-shopify-topic')];
    }
    public static function permission($request)
    {
        try { Model::verifyWebhook($request->get_body(),self::headers($request),self::config()); return true; }
        catch (\Throwable $error) { return new \WP_Error('shopify_auth','Invalid Shopify signature.',['status'=>401]); }
    }
    public static function receive($request)
    {
        try { self::connector()->webhook($request->get_body(),self::headers($request)); return ['ok'=>true]; }
        catch (Failure $error) {
            if ($error->reason==='app_uninstalled') {
                $config=self::config(); $config['mode']='disabled'; $config['access_token']=''; $config['token_expires_at']=0; update_option('inklura_shopify_config',$config,false); return ['ok'=>true];
            }
            return new \WP_Error('shopify_receive','Shopify delivery could not be queued.',['status'=>400]);
        } catch (\Throwable $error) { return new \WP_Error('shopify_storage','Shopify delivery could not be stored.',['status'=>503]); }
    }
    public static function capture(string $kind, int $id): void
    {
        if (self::$applying || $id<1 || (self::config()['mode'] ?? 'disabled')==='disabled') { return; }
        try {
            $map=self::repo()->local($kind,$id);
            if ($map) { self::repo()->enqueue($kind,$map['remote_id']); }
            elseif (!empty(self::config()['export_new_'.$kind]) && (self::config()[$kind==='product'?'catalog_direction':($kind==='coupon'?'coupon_direction':'customer_direction')] ?? '')==='both') {
                self::repo()->enqueue('export_'.$kind,'woo:'.$kind.':'.$id);
            }
        } catch (\Throwable $error) { update_option('inklura_shopify_notice','native_capture_deferred',false); }
    }
    public static function tick(): void
    {
        $config=self::config();
        if (!in_array($config['mode'] ?? 'disabled',['audit','live'],true) || !function_exists('wc_get_product')) { return; }
        $repo=self::repo(); if (!$repo->lock()) { return; }
        $suppress=static function(){return true;};
        self::$applying=true; add_filter('pre_wp_mail',$suppress,PHP_INT_MAX);
        try {
            if (($config['mode'] ?? '')==='live' && !\wd29_bridge()->licence()->allowsLive()) { throw new Failure('sync_licence_paused'); }
            $connector=self::connector(); $connector->check();
            $cursors=$connector->poll((array)get_option('inklura_shopify_cursors',[])); update_option('inklura_shopify_cursors',$cursors,false);
            $done=$connector->work(); update_option('inklura_shopify_worker',['at'=>gmdate('c'),'processed'=>$done,'state'=>'completed'],false);
        } catch (Failure $error) { update_option('inklura_shopify_worker',['at'=>gmdate('c'),'state'=>'failed','error'=>$error->reason],false); }
        catch (\Throwable $error) { update_option('inklura_shopify_worker',['at'=>gmdate('c'),'state'=>'failed','error'=>'native_operation_failed'],false); }
        finally { remove_filter('pre_wp_mail',$suppress,PHP_INT_MAX); self::$applying=false; $repo->unlock(); }
    }
    public static function settings(array $input, array $old, bool $hasHistory): array
    {
        $config=$old; $mode=$input['mode'] ?? 'disabled';
        if (!is_string($mode) || !in_array($mode,['disabled','audit','live'],true)) { throw new Failure('invalid_sync_mode'); }
        $shop=trim((string)($input['shop'] ?? '')); $shop=preg_replace('~^https://~i','',$shop); $shop=rtrim($shop,'/');
        if ($shop!=='') { $shop=Client::shop($shop); }
        $location=trim((string)($input['location'] ?? '')); if ($location!=='') { $location=Client::gid('Location',$location); }
        if ($hasHistory && (($old['shop'] ?? '')!==$shop || ($old['location'] ?? '')!==$location)) { throw new Failure('connection_migration_required'); }
        $config['shop']=$shop; $config['location']=$location; $config['mode']=$mode;
        $auth=$input['auth'] ?? 'token'; if (!in_array($auth,['token','client_credentials'],true)) { throw new Failure('invalid_authentication_mode'); }
        $config['auth']=$auth;
        if (empty($config['source_uuid'])) { $config['source_uuid']=bin2hex(random_bytes(16)); }
        foreach (['access_token','client_id','client_secret'] as $key) {
            if (!is_string($input[$key] ?? '')) { throw new Failure('invalid_credentials'); }
            $value=trim($input[$key] ?? '');
            if (preg_match('/[\x00-\x20\x7f]/',$value) || strlen($value)>4096) { throw new Failure('invalid_credentials'); }
            if ($value!=='') { $config[$key]=$value; }
        }
        if (($old['client_id'] ?? '')!==($config['client_id'] ?? '') || ($old['client_secret'] ?? '')!==($config['client_secret'] ?? '') || ($old['shop'] ?? '')!==$shop || ($old['auth'] ?? '')!==$auth) { $config['token_expires_at']=0; if ($auth==='client_credentials') { $config['access_token']=''; } }
        foreach (['catalog_direction','customer_direction','customer_address_direction','stock_direction','coupon_direction'] as $key) {
            $value=$input[$key] ?? ($key==='stock_direction'?'both':'import');
            if (!in_array($value,['import','both'],true)) { throw new Failure('invalid_sync_direction'); } $config[$key]=$value;
        }
        foreach (['shipping_flags','customers','orders','order_details','publish_imports','export_new_product','export_new_customer','sync_tags','import_media','export_media','import_collections','coupons','export_new_coupon','account_proxy'] as $key) { $config[$key]=!empty($input[$key]); }
        $path=(string)($input['account_proxy_path']??($old['account_proxy_path']??'/apps/inklura-benefits'));
        if(!preg_match('~^/(apps|a|community|tools)/[a-z0-9-]{1,64}$~D',$path))throw new Failure('invalid_account_proxy_path');$config['account_proxy_path']=$path;
        if ($mode!=='disabled' && ($shop==='' || $location==='' || strlen($config['client_secret'] ?? '')<16 || ($auth==='token' && empty($config['access_token'])) || ($auth==='client_credentials' && empty($config['client_id'])))) { throw new Failure('connection_credentials_required'); }
        return $config;
    }
    private static function subscriptions(): int
    {
        $config=self::config(); $api=new Client($config,null,static function($token,$expires){$current=self::config();$current['access_token']=$token;$current['token_expires_at']=$expires;update_option('inklura_shopify_config',$current,false);});
        $url=rest_url('inklura-sync/v1/shopify/webhook'); \WD29\Bridge\Protocol::publicEndpoint($url);
        $value=$api->query('query InkluraSubscriptions { webhookSubscriptions(first:100) { nodes { topic uri } pageInfo { hasNextPage } } }');
        if (!empty($value['webhookSubscriptions']['pageInfo']['hasNextPage'])) { throw new Failure('subscription_page_needs_review'); }
        $existing=[];
        foreach ($value['webhookSubscriptions']['nodes'] ?? [] as $row) { if ($row['uri']===$url) { $existing[]=$row['topic']; } }
        $topics=['PRODUCTS_CREATE','PRODUCTS_UPDATE','PRODUCTS_DELETE','INVENTORY_LEVELS_UPDATE','APP_UNINSTALLED'];
        if (!empty($config['customers'])) { $topics=array_merge($topics,['CUSTOMERS_CREATE','CUSTOMERS_UPDATE','CUSTOMERS_DELETE']); }
        if (!empty($config['orders'])) { $topics=array_merge($topics,['ORDERS_CREATE','ORDERS_UPDATED','ORDERS_PAID','ORDERS_CANCELLED','REFUNDS_CREATE']); }
        if (!empty($config['coupons'])) { $topics=array_merge($topics,['DISCOUNTS_CREATE','DISCOUNTS_UPDATE','DISCOUNTS_DELETE']); }
        $count=0;
        foreach ($topics as $topic) {
            if (in_array($topic,$existing,true)) { continue; }
            $api->mutation('webhookSubscriptionCreate','mutation InkluraSubscribe($topic:WebhookSubscriptionTopic!,$subscription:WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic:$topic,webhookSubscription:$subscription) { webhookSubscription { id } userErrors { field message } } }',['topic'=>$topic,'subscription'=>['uri'=>$url,'format'=>'JSON']]); $count++;
        }
        return $count;
    }
    public static function render(): void
    {
        if (!current_user_can('manage_options')) { return; } $repo=self::repo(); $message='';
        if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
            check_admin_referer('inklura_shopify_settings'); $input=wp_unslash($_POST);
            try {
                $action=is_string($input['shopify_action'] ?? null)?$input['shopify_action']:'';
                if ($action==='save') {
                    if (!$repo->lock()) { throw new Failure('worker_busy'); }
                    try {
                        $history=(bool)$repo->sql('SELECT remote_id FROM {s}map LIMIT 1') || (bool)$repo->sql('SELECT seq FROM {s}queue LIMIT 1');
                        $old=self::config(); $config=self::settings($input,$old,$history); update_option('inklura_shopify_config',$config,false);
                        if (($old['mode'] ?? '')!==$config['mode'] && $config['mode']==='live') { $repo->sql("UPDATE {s}queue SET state='pending',next_try=0 WHERE state='audit'"); }
                    } finally { $repo->unlock(); }
                    $message=\WD29\Bridge\I18n::t('Settings saved.');
                } elseif ($action==='check') {
                    $result=self::connector()->check(false); update_option('inklura_shopify_connection',['at'=>gmdate('c'),'locations'=>$result['locations'],'scopes'=>$result['scopes']],false); $message=\WD29\Bridge\I18n::t('Connection verified. Choose a location before enabling synchronization.');
                } elseif ($action==='subscribe') { self::connector()->check(); $count=self::subscriptions(); $message=\WD29\Bridge\I18n::t('Webhooks configured: ').$count.\WD29\Bridge\I18n::t(' new.'); }
                elseif ($action==='run') { self::tick(); $message=\WD29\Bridge\I18n::t('Processing finished. See the worker result below.'); }
                elseif ($action==='retry') { $repo->retry(absint($input['seq'] ?? 0)); $message=\WD29\Bridge\I18n::t('Event requeued; conflict checks remain active.'); }
                elseif ($action==='export_product' || $action==='export_customer' || $action==='export_coupon') {
                    $kind=substr($action,7);$id=absint($input['native_id'] ?? 0);if ($id<1) { throw new Failure('invalid_export_identity'); }
                    $repo->enqueue($action,'woo:'.$kind.':'.$id);$message=\WD29\Bridge\I18n::t('Record added to the Shopify export queue.');
                }
                else { throw new Failure('invalid_admin_action'); }
            } catch (Failure $error) { $message=\WD29\Bridge\I18n::t('Action not completed: ').$error->reason; }
            catch (\Throwable $error) { $message=\WD29\Bridge\I18n::t('Action not completed: native_operation_failed'); }
        }
        $config=self::config();
        echo \WD29\Bridge\I18n::t('<div class="wrap"><h1>Shopify — Inklura Sync</h1><p>Connection independent of the WooCommerce / PrestaShop pair. Imported products and customers use WooCommerce as a relay; Shopify orders remain read-only copies in WooCommerce.</p>');
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wd29-bridge')).'">WooCommerce ↔ PrestaShop</a> <a class="button" href="https://plugins.inklura.fr/docs/shopify" target="_blank" rel="noopener noreferrer">Guide Shopify</a></p>';
        if ($message!=='') { echo '<div class="notice notice-info"><p>'.esc_html($message).'</p></div>'; }
        echo '<form method="post">'; wp_nonce_field('inklura_shopify_settings'); echo '<input type="hidden" name="shopify_action" value="save"><table class="form-table"><tbody>';
        self::input('shop',\WD29\Bridge\I18n::t('Store *.myshopify.com'),$config['shop'] ?? '');
        self::select('mode','Mode',$config['mode'] ?? 'disabled',['disabled'=>\WD29\Bridge\I18n::t('Disabled'),'audit'=>\WD29\Bridge\I18n::t('Audit: read and queue only'),'live'=>\WD29\Bridge\I18n::t('Synchronization active')]);
        self::select('auth',\WD29\Bridge\I18n::t('Authentication'),$config['auth'] ?? 'token',['client_credentials'=>\WD29\Bridge\I18n::t('App in your own Shopify organization (token renewed automatically)'),'token'=>\WD29\Bridge\I18n::t('Existing offline Admin API token')]);
        self::input('client_id','Client ID',$config['client_id'] ?? '');
        self::input('client_secret',\WD29\Bridge\I18n::t('App client secret'),'',true); self::input('access_token',\WD29\Bridge\I18n::t('Admin API token'),'',true);
        self::input('location',\WD29\Bridge\I18n::t('Shopify location (ID or gid://shopify/Location/…)'),$config['location'] ?? '');
        foreach (['catalog_direction'=>'Catalogue','customer_direction'=>\WD29\Bridge\I18n::t('Customer records'),'customer_address_direction'=>\WD29\Bridge\I18n::t('Billing address / default Shopify address'),'coupon_direction'=>\WD29\Bridge\I18n::t('Ordinary discount codes'),'stock_direction'=>'Stock'] as $key=>$label) { self::select($key,$label,$config[$key] ?? ($key==='stock_direction'?'both':'import'),['import'=>'Shopify → WooCommerce','both'=>\WD29\Bridge\I18n::t('Both directions')]); }
        foreach (['account_proxy'=>\WD29\Bridge\I18n::t('Show KerAwen benefits for the signed-in Shopify customer (read only)'),'coupons'=>\WD29\Bridge\I18n::t('Synchronize ordinary discount codes without usage limits (discounts permissions required)'),'export_new_coupon'=>\WD29\Bridge\I18n::t('Create new compatible WooCommerce codes on Shopify'),'customers'=>\WD29\Bridge\I18n::t('Import registered customers'),'orders'=>\WD29\Bridge\I18n::t('Import orders as read-only copies'),'order_details'=>\WD29\Bridge\I18n::t('Import Shopify shipments, refunds and payments as read-only copies'),'publish_imports'=>\WD29\Bridge\I18n::t('Publish new active products (otherwise: draft)'),'shipping_flags'=>\WD29\Bridge\I18n::t('Synchronize physical / virtual variation type (catalogue direction)'),'sync_tags'=>\WD29\Bridge\I18n::t('Synchronize product tags (catalogue direction)'),'import_media'=>\WD29\Bridge\I18n::t('Import Shopify images (add without removal)'),'import_collections'=>\WD29\Bridge\I18n::t('Import Shopify collections as categories (add without removal)'),'export_new_product'=>\WD29\Bridge\I18n::t('Create new simple and variable WooCommerce products on Shopify (draft)'),'export_new_customer'=>\WD29\Bridge\I18n::t('Create new WooCommerce customers on Shopify')] as $key=>$label) {
            echo '<tr><th>'.esc_html($label).'</th><td><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($config[$key]),true,false).'></td></tr>';
        }
        self::input('account_proxy_path',\WD29\Bridge\I18n::t('Shopify benefits page path'),$config['account_proxy_path']??'/apps/inklura-benefits');
        echo \WD29\Bridge\I18n::t('<tr><th>Images for newly exported products</th><td><label><input type="checkbox" name="export_media" value="1" ').checked(!empty($config['export_media']),true,false).\WD29\Bridge\I18n::t('> Copy images when first creating a product on Shopify (up to 20 public JPEG, PNG or WebP images from this store; existing galleries retained)</label></td></tr>');
        echo \WD29\Bridge\I18n::t('</tbody></table><p>Blank secret fields retain their saved value. The app secret also verifies webhooks. The own-organization flow requires an app installed on a store in that same organization.</p><p>Only one location is synchronized. Currency and tax-inclusive/exclusive pricing must match. The catalogue imports titles, descriptions, options, references, barcodes and prices. Simultaneous changes, removals and structural changes are flagged for review. Linked customer names, email addresses and phone numbers are synchronized. The default Shopify address maps to WooCommerce billing; its export is configured separately. Additional addresses and the WooCommerce shipping address remain separate. Exporting new records requires both directions mode; new records use a unique identifier, without matching by email or reference.</p><p>Compatible ordinary discount codes synchronize separately: percentage, fixed cart or fixed product amount, all products and customers, without usage limits, stacking or additional conditions. A rule that becomes incompatible pauses its copy; shared usage limits must not be duplicated. Shopify gift cards, credit notes and points are not linked to the KerAwen balance. New simple and variable products can be exported; variations must have explicit options, without an any value.</p>');
        echo '<p>Proxy Shopify : <code>'.esc_html(rest_url('inklura-sync/v1/shopify/account')).\WD29\Bridge\I18n::t('</code>. Enable the proxy in your Shopify app and configure the same path. Signed-in customers can only view their own linked account; balances are read from KerAwen through Commerce Pro.</p>');
        submit_button(\WD29\Bridge\I18n::t('Save')); echo '</form><p>Webhook : <code>'.esc_html(rest_url('inklura-sync/v1/shopify/webhook')).'</code></p>';
        foreach (['check'=>\WD29\Bridge\I18n::t('Check connection'),'subscribe'=>\WD29\Bridge\I18n::t('Configure webhooks'),'run'=>\WD29\Bridge\I18n::t('Synchronize a batch')] as $action=>$label) { echo '<form method="post" style="display:inline-block;margin-right:12px">';wp_nonce_field('inklura_shopify_settings');echo '<input type="hidden" name="shopify_action" value="'.esc_attr($action).'">';submit_button($label,'secondary','submit',false);echo '</form>'; }
        echo \WD29\Bridge\I18n::t('<h2>Export an existing record</h2><p>Choose a WooCommerce record by its native ID. Products are created as drafts on Shopify; no order or payment is created.</p>');
        foreach (['export_product'=>\WD29\Bridge\I18n::t('Export a simple or variable product'),'export_customer'=>\WD29\Bridge\I18n::t('Export a customer'),'export_coupon'=>\WD29\Bridge\I18n::t('Export a compatible discount code')] as $action=>$label) { echo '<form method="post" style="display:inline-block;margin-right:12px">';wp_nonce_field('inklura_shopify_settings');echo '<input type="hidden" name="shopify_action" value="'.esc_attr($action).'"><input type="number" min="1" name="native_id" required aria-label="ID WooCommerce"><button class="button">'.esc_html($label).'</button></form>'; }
        $connection=(array)get_option('inklura_shopify_connection',[]);
        if (!empty($connection['locations'])) { echo '<h2>Emplacements disponibles</h2><ul>'; foreach ($connection['locations'] as $location) { echo '<li>'.esc_html($location['name']).' — <code>'.esc_html($location['id']).'</code></li>'; } echo '</ul>'; }
        echo '<h2>Worker</h2><pre>'.esc_html(wp_json_encode(get_option('inklura_shopify_worker',[]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><h2>File Shopify</h2><p>'.esc_html(wp_json_encode($repo->summary())).\WD29\Bridge\I18n::t('</p><table class="widefat"><thead><tr><th>ID</th><th>Type</th><th>Resource</th><th>State</th><th>Diagnostic</th><th></th></tr></thead><tbody>');
        foreach ($repo->sql('SELECT seq,kind,remote_id,state,error FROM {s}queue ORDER BY seq DESC LIMIT 30') as $job) {
            echo '<tr>'; foreach (['seq','kind','remote_id','state','error'] as $key) { echo '<td>'.esc_html($job[$key]).'</td>'; } echo '<td>';
            if (in_array($job['state'],['failed','conflict','audit'],true)) { echo '<form method="post">';wp_nonce_field('inklura_shopify_settings');echo '<input type="hidden" name="shopify_action" value="retry"><input type="hidden" name="seq" value="'.(int)$job['seq'].\WD29\Bridge\I18n::t('"><button class="button">Retry</button></form>'); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function input(string $name,string $label,string $value,bool $secret=false): void
    {
        echo '<tr><th><label for="shopify-'.esc_attr($name).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="shopify-'.esc_attr($name).'" name="'.esc_attr($name).'" type="'.($secret?'password':'text').'" value="'.esc_attr($value).'" autocomplete="off"></td></tr>';
    }
    private static function select(string $name,string $label,string $value,array $choices): void
    {
        echo '<tr><th><label for="shopify-'.esc_attr($name).'">'.esc_html($label).'</label></th><td><select id="shopify-'.esc_attr($name).'" name="'.esc_attr($name).'">';
        foreach ($choices as $key=>$text) { echo '<option value="'.esc_attr($key).'" '.selected($value,$key,false).'>'.esc_html($text).'</option>'; } echo '</select></td></tr>';
    }
}
