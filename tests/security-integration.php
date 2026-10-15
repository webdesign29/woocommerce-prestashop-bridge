<?php
/** Run only against the isolated native WordPress fixture. */
$root=getenv('WD29_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { throw new RuntimeException('Set WD29_WP_ROOT.'); }
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
require $root.'/wp-load.php';
if (DB_NAME!=='wd29woo' || parse_url(home_url(),PHP_URL_HOST)!=='woo.example.test') { throw new RuntimeException('Not a disposable fixture.'); }
wp_set_current_user(1);
$checks=0;
function secure_expect($condition,$message) { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
use WD29\Bridge\AdminInput;
use WD29\Bridge\Protocol;
$adapter=wd29_bridge()->adapter;
$attack="x' OR 1=1 --";
$row=$adapter->sql("SELECT ? AS marker, ? IS NULL AS empty_value, '100% literal' AS percent_value",[$attack,null])[0];
secure_expect($row['marker']===$attack && (int)$row['empty_value']===1 && $row['percent_value']==='100% literal','SQL values escaped incorrectly');
secure_expect((int)$adapter->sql('SELECT ? IS NULL AS empty_value',[null])[0]['empty_value']===1,'All NULL query failed');
try { $adapter->sql('SELECT ? AS value',[]); throw new RuntimeException('Unbound query accepted'); } catch (LogicException $expected) { ++$checks; }
foreach (['1<script>','1%00',"1\n",['1'],true] as $bad) { secure_expect(AdminInput::text(['id'=>$bad],'id')===null,'Malformed text repaired'); }
$secret="opaque'<>&\\signed-key-1234567890123456789";
secure_expect(AdminInput::opaque(['secret'=>wp_slash($secret)],'secret')===$secret,'Opaque secret bytes changed');
$fields=['wd29_bridge_config','wd29_bridge_custom_fields','wd29_bridge_source_statuses','wd29_bridge_status_mapping'];
$before=[]; foreach ($fields as $field) { $before[$field]=get_option($field,[]) ?? []; }
try {
    update_option('wd29_bridge_config',['mode'=>'disabled','peer'=>'','secret'=>$secret]);
    $body=Protocol::encode(['version'=>1,'source'=>'ps','op'=>'health']);
    $timestamp=(string)time(); $nonce=str_repeat('a',32);
    $request=new WP_REST_Request('POST','/wd29-bridge/v1/webhook');
    $request->set_body($body); $request->set_header('x-wd29-timestamp',$timestamp); $request->set_header('x-wd29-nonce',$nonce);
    $request->set_header('x-wd29-signature',Protocol::sign($body,$secret,$timestamp,$nonce));
    secure_expect(wd29_bridge_webhook_permission($request)===true,'Signed request rejected');
    $request->set_body($body.' ');
    $result=wd29_bridge_webhook_permission($request); secure_expect($result instanceof WP_Error && $result->get_error_data()['status']===401,'Tampered body accepted');
    $request->set_body($body); $request->set_header('x-wd29-timestamp',(string)(time()-600));
    secure_expect(wd29_bridge_webhook_permission($request) instanceof WP_Error,'Expired signature accepted');
    secure_expect(wd29_bridge_webhook_permission(new WP_REST_Request('POST')) instanceof WP_Error,'Missing authentication accepted');
    update_option('wd29_bridge_custom_fields', [['id'=>'"><script>alert(1)</script>','key'=>'"><img src=x onerror=alert(1)>','source'=>'meta','entities'=>['product']]]);
    ob_start(); WD29\Bridge\CustomFieldsAdmin::render(); $html=ob_get_clean();
    secure_expect(strpos($html,'<script>alert(1)</script>')===false && strpos($html,'<img src=x')===false && strpos($html,'&lt;img')!==false,'Stored field configuration injected HTML');
    update_option('wd29_bridge_source_statuses',['ps-x'=>'"><img src=x onerror=alert(1)>']);
    update_option('wd29_bridge_status_mapping',['ps-x'=>'"><script>alert(1)</script>']);
    $_GET=['page'=>'wd29-bridge','wd_view'=>'settings']; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
    ob_start(); wd29_bridge_admin(); $html=ob_get_clean();
    secure_expect(strpos($html,'<img src=x')===false && strpos($html,'<script>alert(1)</script>')===false,'Stored status configuration injected HTML');
    secure_expect(strpos($html,'name="_wpnonce"')!==false && strpos($html,'name="secret"')!==false,'Trusted renderer lost nonce/form controls');
} finally {
    foreach ($before as $field=>$value) { update_option($field,$value); }
}
echo "PASS: $checks native SQL binding, exact opaque keys, malformed input, signed authorization, stored HTML escaping and retained form assertions\n";
