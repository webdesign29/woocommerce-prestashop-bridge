<?php
namespace WD29\Bridge {
    final class Engine {}
    final class ProductLinks {
        public static $localCalls = 0, $remoteCalls = 0, $throw = false;
        public static function local(Engine $engine, int $id): array { self::$localCalls++; return ['state'=>'ready','key'=>'woo:product:1','peer_host'=>'presta.example']; }
        public static function remote(Engine $engine, int $id): array { self::$remoteCalls++; if(self::$throw)throw new \RuntimeException('SECRET transport internals'); return ['state'=>'linked','url'=>'https://presta.example/product','peer_host'=>'presta.example']; }
    }
}
namespace {
require __DIR__.'/../includes/ProductLinksAdmin.php';
require __DIR__.'/../includes/RecordPanelAdmin.php';
require __DIR__.'/../includes/WooAdapter.php';
class JsonReply extends RuntimeException { public $payload, $status; function __construct($data,$status){$this->payload=$data;$this->status=$status;} }
$capable=true;$type='product';$status='publish';$password='';$hooks=[];$checks=0;
function check($condition,$message){global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function wd29_bridge(){return new \WD29\Bridge\Engine();}
function current_user_can($cap,$id=null){global $capable;return $cap==='edit_post'&&$capable&&$id>0;}
function get_post_type($id){global $type;return $type;}
function get_post_status($id){global $status;return $status;}
function get_post_field($field,$id){global $password;return $password;}
function wc_get_product($id){return new class {function get_status(){return get_post_status(1);}};}
function get_permalink($id){return 'https://woo.example/product/native-slug/';}
function add_action($name,$handler){global $hooks;$hooks[$name]=$handler;}
function add_meta_box(...$args){}
function wp_enqueue_script(...$args){}
function plugins_url($path,$file){return 'https://woo.example/plugins/'.$path;}
function admin_url($path){return 'https://woo.example/wp-admin/'.$path;}
function esc_attr($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_url($value){return htmlspecialchars($value,ENT_QUOTES);}
function wp_create_nonce($action){return 'valid-'.$action;}
function wp_verify_nonce($nonce,$action){return hash_equals('valid-'.$action,$nonce);}
function wp_unslash($value){return stripslashes($value);}
function nocache_headers(){}
function wp_send_json($data,$status=200){throw new JsonReply($data,$status);}
function request($data,$method='POST') {$_POST=$data;$_SERVER['REQUEST_METHOD']=$method;try{\WD29\Bridge\ProductLinksAdmin::ajax();}catch(JsonReply $reply){return $reply;}throw new RuntimeException('No JSON response');}
\WD29\Bridge\ProductLinksAdmin::register('/plugin/main.php');
\WD29\Bridge\RecordPanelAdmin::register('/plugin/main.php');
check(isset($hooks['add_meta_boxes_product'],$hooks['wp_ajax_wd29_product_link']),'Native editor/AJAX hooks missing');
check(!isset($hooks['wp_ajax_nopriv_wd29_product_link']),'Unauthenticated handler registered');
ob_start();\WD29\Bridge\ProductLinksAdmin::render((object)['ID'=>1]);$html=ob_get_clean();
check(\WD29\Bridge\ProductLinks::$localCalls===1&&\WD29\Bridge\ProductLinks::$remoteCalls===0,'Editor rendering queried the peer');
check(strpos($html,'data-wd29-product-link')!==false&&strpos($html,'noopener noreferrer')!==false,'Native sidebar/public-link attributes missing');
check(strpos($html,'Original WooCommerce')!==false,'Product origin missing');
$valid=['product'=>'1','nonce'=>'valid-wd29_product_link_1'];
$capable=false;check(request($valid)->status===403,'Unauthorised product edit accepted');
ob_start();\WD29\Bridge\ProductLinksAdmin::render((object)['ID'=>1]);check(ob_get_clean()==='','Sidebar leaked to unauthorized user');$capable=true;
check(request($valid,'GET')->status===405,'GET accepted');
check(request(['product'=>['1'],'nonce'=>'x'])->status===400,'Array product accepted');
check(request(['product'=>'1','nonce'=>['x']])->status===403,'Array nonce accepted');
check(request(['product'=>'1','nonce'=>'bad'])->status===403,'Invalid nonce accepted');
check(request(['product'=>'2','nonce'=>$valid['nonce']])->status===403,'Other-product nonce accepted');
$type='post';check(request($valid)->status===404,'Non-product lookup accepted');$type='product';
check(\WD29\Bridge\ProductLinks::$remoteCalls===0,'Rejected request queried peer');
check(request($valid)->payload['data']['state']==='linked','Product editor capability alone should suffice without manage_options');
\WD29\Bridge\ProductLinks::$throw=true;$reply=request($valid);check($reply->payload['data']['state']==='unavailable'&&strpos(json_encode($reply->payload),'SECRET')===false,'Transport error exposed');
$adapter=new \WD29\Bridge\WooAdapter();check($adapter->productPublicUrl(1)==='https://woo.example/product/native-slug/','Native permalink not returned');
foreach(['draft','private','trash','pending','future'] as $status){check($adapter->productPublicUrl(1)==='','Nonpublic product URL exposed');}$status='publish';
$password='protected';check($adapter->productPublicUrl(1)==='','Password-protected product URL exposed');$password='';$type='product_variation';check($adapter->productPublicUrl(1)==='','Variation accidentally linked as product');
echo "PASS: $checks product editor permission, nonce, read-only rendering and public URL assertions\n";
}
