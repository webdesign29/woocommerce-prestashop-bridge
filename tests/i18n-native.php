<?php
/** Run in the isolated native fixture; writes only proof HTML outside WordPress data. */
$root=getenv('WD29_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { throw new RuntimeException('Set WD29_WP_ROOT.'); }
define('WP_ADMIN',true);define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo'||parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Not an isolated fixture.'); }
wp_set_current_user(1);
$fr=getenv('INKLURA_REVIEW_LOCALE')==='fr_FR';$checks=0;
function expect_i18n($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
use WD29\Bridge\I18n;
expect_i18n(I18n::t('Settings')===($fr?'Réglages':'Settings'),'Locale did not load');
$messages=require dirname(__DIR__).'/includes/js-messages.php';
expect_i18n($messages['No items to display yet.']===($fr?'Aucun élément à afficher pour le moment.':'No items to display yet.'),'JavaScript locale not loaded');
$expected=['overview'=>$fr?'État du suivi':'Tracking status','settings'=>$fr?'Secret partagé':'Shared secret','sync'=>$fr?'Comparer et synchroniser':'Compare and synchronize','activity'=>$fr?'Échanges de':'Events for','reports'=>$fr?'Catalogue suivi sur':'Catalogue tracked on','licence'=>'Woo subscription and companion module'];
foreach($expected as $view=>$label){
 $_GET=['page'=>'wd29-bridge','wd_view'=>$view];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
 ob_start();wd29_bridge_admin();$html=ob_get_clean();
 expect_i18n(strpos($html,$label)!==false,$view.' missing expected section '.$label);
 expect_i18n(strpos($html,$fr?'Vue d’ensemble':'Overview')!==false,'Navigation locale wrong');
 expect_i18n(strpos($html,'name="_wpnonce"')!==false||in_array($view,['overview','reports','licence'],true),'Form nonce lost');
 expect_i18n(strpos($html,'name="licence_key"')===false,'Marketplace contains direct-sale key form');
 $proof=getenv('INKLURA_PROOF_DIR');
 if($proof){
  $head='<!doctype html><html lang="'.($fr?'fr':'en').'"><meta charset="utf-8"><title>Inklura Sync '.$view.'</title><style>body{font-family:system-ui;background:#f0f0f1;padding:20px;color:#1d2327}button,input,select{font:inherit}a{color:#2271b1}</style><script>window.InkluraSyncMessages='.wp_json_encode($messages).';</script><script>'.file_get_contents(dirname(__DIR__).'/includes/i18n.js').'</script>';
  file_put_contents($proof.'/'.($fr?'fr':'en').'-'.$view.'.html',$head.$html.'</html>');
 }
}
// Merchant data is deliberately never translated by the presentation layer.
$source='<div><h1>Sync</h1><h2>'.I18n::t('Customer contacts').'</h2><table><tr><th>'.I18n::t('Origin').'</th><th>'.I18n::t('Name').'</th></tr><tr><td>ps:customer:123</td><td>Réglages &amp; Commandes</td></tr></table></div>';
$_GET['wd_view']='reports';$html=WD29\Bridge\AdminDesign::render($source,wd29_bridge(),'woo');
expect_i18n(strpos($html,'Réglages &amp; Commandes')!==false,'Merchant content translated');
expect_i18n(strpos($html,'data-wd-origin="imported"')!==false,'Localized headers broke ownership');
echo 'PASS '.($fr?'French':'English').' native admin: '.$checks." checks\n";
