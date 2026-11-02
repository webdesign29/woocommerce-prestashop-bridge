<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminInput.php';
require_once __DIR__ . '/AdminContext.php';
require_once __DIR__ . '/Distribution.php';

/** Shared presentation layer: retains existing forms, names, nonces and handlers. */
final class AdminDesign
{
    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    /** Use the host admin's components without changing form actions or security fields. */
    private static function nativeControls(\DOMDocument $doc, string $platform): void
    {
        $xp=new \DOMXPath($doc);
        foreach($xp->query('//button | //a[contains(concat(" ",normalize-space(@class)," ")," button ")] | //table | //input | //select | //textarea') as $node){
            $classes=preg_split('/\s+/',trim($node->getAttribute('class')),-1,PREG_SPLIT_NO_EMPTY);
            $primary=in_array('button-primary',$classes,true)||in_array('btn-primary',$classes,true);
            $classes=array_values(array_diff($classes,['button','button-primary','button-secondary','btn','btn-primary','btn-default','btn-secondary','widefat','striped','table','table-striped','form-control']));
            if(in_array($node->tagName,['button','a'],true)){
                $classes=array_merge($classes,$platform==='ps'?['btn',$primary?'btn-primary':'btn-default']:['button',$primary?'button-primary':'button-secondary']);
            }elseif($node->tagName==='table'){
                $classes=array_merge($classes,$platform==='ps'?['table']:['widefat','striped']);
            }elseif($platform==='ps'&&!in_array($node->getAttribute('type'),['hidden','checkbox','radio'],true)){
                $classes[]='form-control';
            }
            $node->setAttribute('class',implode(' ',array_unique($classes)));
        }
    }
    public static function viewUrl(string $view): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation URL; all output of the URL is escaped by e().
        $query=$_GET;unset($query['inklura_demo_section']);$query['wd_view']=$view;
        foreach(array_keys($query) as $key)if(strpos($key,'manual_')===0||$key==='bridge_action')unset($query[$key]);
        return '?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }
    public static function currentView(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- Presentation selector only; mutation handlers verify nonces separately.
        $view=AdminInput::text($_POST,'wd_view')??AdminInput::text($_GET,'wd_view','overview');$action=(AdminInput::text($_POST,'bridge_action','')??'');
        if(strpos($action,'manual_record')===0)$view='sync';elseif(strpos($action,'manual_order')===0)$view='orders';
        return in_array($view,['overview','sync','orders','activity','reports','settings','licence'],true)?$view:'overview';
    }
    public static function render(string $html, Engine $engine, string $platform): string
    {
        $doc=new \DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        $loaded=$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) { return $html; }
        $body=$doc->getElementsByTagName('body')->item(0); $root=null;
        foreach($body->childNodes as $node){if($node instanceof \DOMElement){$root=$node;break;}}
        if(!$root){return $html;}
        self::nativeControls($doc,$platform);
        $view=self::currentView();
        $panelClass=$platform==='ps'?'panel':'postbox';
        $primaryButton=$platform==='ps'?'btn btn-primary':'button button-primary';
        $secondaryButton=$platform==='ps'?'btn btn-default':'button button-secondary';
        $forms=new \DOMXPath($doc);foreach($forms->query('//form') as $form){$hidden=$doc->createElement('input');$hidden->setAttribute('type','hidden');$hidden->setAttribute('name','wd_view');$hidden->setAttribute('value',$view);$form->appendChild($hidden);}
        $local=$platform==='ps'?'ps':'woo';$localName=AdminContext::name($local);$remoteName=AdminContext::name($local==='woo'?'ps':'woo');
        // Decorate ownership before serializing sections, preserving forms and tokens.
        $section='';foreach(iterator_to_array($root->childNodes) as $node){if($node instanceof \DOMElement){if(in_array($node->tagName,['h1','h2','h3'],true)){$section=trim($node->textContent);}elseif($node->tagName==='table'){AdminContext::table($node,$section,$local);}}}
        $nodes=iterator_to_array($root->childNodes); $sections=[]; $key='settings';
        $titles=['settings'=>[\WD29\Bridge\I18n::t('Settings'),\WD29\Bridge\I18n::t('Connection and synchronization rules')],'tools'=>['Actions & maintenance',\WD29\Bridge\I18n::t('Queue processing, captures and retries')],\WD29\Bridge\I18n::t('Custom fields')=>[\WD29\Bridge\I18n::t('Custom fields'),\WD29\Bridge\I18n::t('WooCommerce and ACF metadata mappings')],\WD29\Bridge\I18n::t('Order statuses')=>[\WD29\Bridge\I18n::t('Order statuses'),\WD29\Bridge\I18n::t('Mappings between PrestaShop and WooCommerce')],'Diagnostics'=>['Diagnostics',\WD29\Bridge\I18n::t('Processing status and items to review')],\WD29\Bridge\I18n::t('Order conflicts')=>[\WD29\Bridge\I18n::t('Order conflicts'),\WD29\Bridge\I18n::t('Compare versions before retrying')],\WD29\Bridge\I18n::t('Refunds and credit notes')=>[\WD29\Bridge\I18n::t('Refunds and credit notes'),\WD29\Bridge\I18n::t('Track records from the source store')],\WD29\Bridge\I18n::t('Linked customer accounts')=>[\WD29\Bridge\I18n::t('Linked customer accounts'),\WD29\Bridge\I18n::t('Native account mappings')],\WD29\Bridge\I18n::t('Event log')=>[\WD29\Bridge\I18n::t('Event log'),\WD29\Bridge\I18n::t('The latest 100 events and their results')],'Catalogue'=>['Catalogue',\WD29\Bridge\I18n::t('Latest transmitted snapshots · displays up to 200 products')],\WD29\Bridge\I18n::t('Orders')=>[\WD29\Bridge\I18n::t('Orders'),\WD29\Bridge\I18n::t('Amounts, statuses and line mappings')],\WD29\Bridge\I18n::t('Customer contacts')=>[\WD29\Bridge\I18n::t('Customer contacts'),\WD29\Bridge\I18n::t('Copies to edit on their source store')],\WD29\Bridge\I18n::t('Edit custom fields')=>[\WD29\Bridge\I18n::t('Edit custom fields'),\WD29\Bridge\I18n::t('Values received from WooCommerce')]];
        $titles[\WD29\Bridge\I18n::t('Compare and synchronize')]=[\WD29\Bridge\I18n::t('Compare and synchronize'),\WD29\Bridge\I18n::t('Products, orders and contacts · both directions')];
        $titles[\WD29\Bridge\I18n::t('Orders to synchronize')]=[\WD29\Bridge\I18n::t('Orders to synchronize'),\WD29\Bridge\I18n::t('Compare differences and catch up an order even when stopped')];
        $titles['Catalogue']=[\WD29\Bridge\I18n::t('Catalogue tracked on ').$localName,\WD29\Bridge\I18n::t('Exchanged snapshots · originals and imported copies · displays up to 200 products')];
        $titles[\WD29\Bridge\I18n::t('Orders')]=[\WD29\Bridge\I18n::t('Orders tracked on ').$localName,\WD29\Bridge\I18n::t('Orders on this store · original and copy identified')];
        $titles[\WD29\Bridge\I18n::t('Customer contacts')]=[\WD29\Bridge\I18n::t('Contacts received on ').$localName,\WD29\Bridge\I18n::t('Data retained here · edit on the source store')];
        $titles[\WD29\Bridge\I18n::t('Event log')]=[\WD29\Bridge\I18n::t('Events for ').$localName,\WD29\Bridge\I18n::t('Explicit direction: WordPress → PrestaShop or PrestaShop → WordPress')];
        $top=''; $firstHeading=true; $forms=0;
        foreach($nodes as $node){
            if($node instanceof \DOMElement && in_array(strtolower($node->tagName),['h1','h2','h3'],true)){
                if($firstHeading){$firstHeading=false;continue;}
                $key=trim($node->textContent);continue;
            }
            if($node instanceof \DOMElement && $node->tagName==='hr'){continue;}
            if($node instanceof \DOMElement && $node->tagName==='form'){$forms++;if($forms===2&&$key==='settings'){$key='tools';}}
            if($node instanceof \DOMElement && (strpos($node->getAttribute('class'),'notice')!==false || strpos($node->getAttribute('class'),'alert')!==false)){$top.=$doc->saveHTML($node);continue;}
            $sections[$key]=($sections[$key]??'').$doc->saveHTML($node);
        }
        $licence=$sections[\WD29\Bridge\I18n::t('License')]??'';unset($sections[\WD29\Bridge\I18n::t('License')]);$ls=$engine->licence()->summary();
        $d=$view==='overview'?$engine->diagnostics():['queue'=>[]];$pending=0;$blocked=0;
        foreach($d['queue'] as $group){if($group['state']==='pending'){$pending+=(int)$group['total'];}else{$blocked+=(int)$group['total'];}}
        $modes=['live'=>\WD29\Bridge\I18n::t('Synchronization active'),'audit'=>\WD29\Bridge\I18n::t('Audit mode'),'disabled'=>\WD29\Bridge\I18n::t('Synchronization stopped')];$mode=$modes[$engine->mode()]??\WD29\Bridge\I18n::t('Unknown state');
        if(($engine->config()['mode']??'')==='live'&&$engine->mode()!=='live'){$mode=\WD29\Bridge\I18n::t('Audit mode · license');}
        $out='<style>'.file_get_contents(__DIR__.'/admin-design.css').'</style><div id="wd29-admin" data-wd-view="'.self::e($view).'" class="wd-platform-'.self::e($platform).($platform==='ps'?'':' wrap').'"><header class="wd-hero"><h1>Inklura Sync</h1><span class="wd-badge">'.self::e($mode).'</span></header>'.AdminContext::stores($engine,$local).$top;
        $out.='<nav class="wd-nav'.($platform==='ps'?'':' nav-tab-wrapper').\WD29\Bridge\I18n::t('" aria-label="Connector pages">').($platform==='ps'?'<ul class="nav nav-tabs">':'');
        foreach(['overview'=>\WD29\Bridge\I18n::t('Overview'),'sync'=>\WD29\Bridge\I18n::t('Compare and synchronize'),'activity'=>\WD29\Bridge\I18n::t('Activity'),'reports'=>\WD29\Bridge\I18n::t('Reports'),'settings'=>\WD29\Bridge\I18n::t('Settings'),'licence'=>Distribution::isMarketplace()?'Updates & support':\WD29\Bridge\I18n::t('License and updates')] as $page=>$label){
            $active=($view==='orders'?'sync':$view)===$page;
            if($platform==='ps')$out.='<li class="nav-item'.($active?' active':'').'">';
            $out.='<a class="'.($platform==='ps'?'nav-link'.($active?' active':''):'nav-tab'.($active?' nav-tab-active':'')).'" href="'.self::e(self::viewUrl($page)).'"'.($active?' aria-current="page"':'').'>'.self::e($label).'</a>';
            if($platform==='ps')$out.='</li>';
        }
        if ($platform==='woo' && class_exists('WD29\\Bridge\\Shopify\\Admin')) {
            $out.='<a class="nav-tab" href="'.self::e(admin_url('admin.php?page=inklura-shopify')).'">Shopify</a>';
        }
        $out.=($platform==='ps'?'</ul>':'').'</nav>';
        if($view==='overview'){$out.='<div class="wd-stats">';
        foreach([[\WD29\Bridge\I18n::t('Tracking status'),$d['ok']?\WD29\Bridge\I18n::t('Up to date'):\WD29\Bridge\I18n::t('Check required'),$d['ok']?\WD29\Bridge\I18n::t('No issues detected'):count($d['issues']).\WD29\Bridge\I18n::t(' point(s) to review')],[\WD29\Bridge\I18n::t('On hold'),$pending,\WD29\Bridge\I18n::t('Events to process')],[\WD29\Bridge\I18n::t('To resolve'),$blocked,\WD29\Bridge\I18n::t('Failures and conflicts')],[\WD29\Bridge\I18n::t('Partner configured'),$remoteName,\WD29\Bridge\I18n::t('The reports below concern ').$localName]] as $stat){$out.='<article class="wd-card wd-stat '.$panelClass.'"><span>'.$stat[0].'</span><strong>'.self::e($stat[1]).'</strong><small>'.self::e($stat[2]).'</small></article>';}
        $out.='</div><p><a class="'.$primaryButton.'" href="'.self::e(self::viewUrl('sync')).\WD29\Bridge\I18n::t('">Compare stores</a> <a class="').$secondaryButton.'" href="'.self::e(self::viewUrl('activity')).\WD29\Bridge\I18n::t('">View events</a></p>');}$index=0;
        if(isset($sections[\WD29\Bridge\I18n::t('Orders to synchronize')])){$sections=[\WD29\Bridge\I18n::t('Orders to synchronize')=>$sections[\WD29\Bridge\I18n::t('Orders to synchronize')]]+$sections;}
        if($view==='licence'&&$licence!==''){$out.='<details id="wd-licence" class="wd-card '.$panelClass.' wd-section wd-section-licence"'.' open'.'><summary><span><strong>'.(Distribution::isMarketplace()?'':\WD29\Bridge\I18n::t('License · ')).self::e($ls['label']).'</strong><small>'.self::e(Distribution::isMarketplace()?'Woo subscription and companion module':($ls['hint']!==''?\WD29\Bridge\I18n::t('Key ').$ls['hint'].($ls['expires']!==''?\WD29\Bridge\I18n::t(' · expiry ').$ls['expires']:''):\WD29\Bridge\I18n::t('Key, status and updates'))).'</small></span><span class="wd-chevron" aria-hidden="true">⌄</span></summary><div class="wd-section-body">'.$licence.'</div></details>';}
        foreach($sections as $name=>$content){
            $index++;$page=in_array($name,['settings',\WD29\Bridge\I18n::t('Custom fields'),\WD29\Bridge\I18n::t('Order statuses')],true)?'settings':($name===\WD29\Bridge\I18n::t('Compare and synchronize')?'sync':($name===\WD29\Bridge\I18n::t('Orders to synchronize')?'orders':(in_array($name,['tools','Diagnostics',\WD29\Bridge\I18n::t('Order conflicts'),\WD29\Bridge\I18n::t('Event log')],true)?'activity':'reports')));
            if($page!==$view&&!($view==='overview'&&$name==='Diagnostics'))continue;
            $title=$titles[$name]??[$name,\WD29\Bridge\I18n::t('Advanced connector configuration')];$id=$name===\WD29\Bridge\I18n::t('Compare and synchronize')?'wd-compare':($name===\WD29\Bridge\I18n::t('Orders to synchronize')?'wd-orders-delta':($name==='settings'?'wd-settings':($name==='tools'?'wd-tools':'wd-report-'.$index)));
            // phpcs:ignore WordPress.Security.NonceVerification -- Presentation selector only; mutation handlers verify nonces separately.
            $open=in_array($name,['settings','Diagnostics',\WD29\Bridge\I18n::t('Orders to synchronize'),\WD29\Bridge\I18n::t('Compare and synchronize'),'tools',\WD29\Bridge\I18n::t('Event log')],true)||($name===\WD29\Bridge\I18n::t('Edit custom fields')&&in_array((AdminInput::text($_POST,'bridge_action','')??''),['load_mirror_fields','save_mirror_fields'],true))||($name===\WD29\Bridge\I18n::t('Custom fields')&&AdminInput::text($_POST,'bridge_action','')==='save_field_rows');
            if($view==='reports'&&strpos($out,'id="wd-reports"')===false)$out.=\WD29\Bridge\I18n::t('<div id="wd-reports" class="wd-section-title"><h2>Reports</h2><p>Originals and imported copies on ').self::e($localName).'.</p></div>';
            $out.='<details id="'.$id.'" class="wd-card '.$panelClass.' wd-section wd-section-'.($name==='settings'?'settings':($name==='tools'?'tools':'report')).'"'.($open?' open':'').'><summary><span><strong>'.self::e($title[0]).'</strong><small>'.self::e($title[1]).'</small></span><span class="wd-chevron" aria-hidden="true">⌄</span></summary><div class="wd-section-body">'.$content.'</div></details>';
        }
        $out.=\WD29\Bridge\I18n::t('<footer class="wd-footnote">Inklura Sync · Reports show data tracked by the connector.</footer></div><script>').file_get_contents(__DIR__.'/admin-design.js').'</script>';
        return $out;
    }
}
