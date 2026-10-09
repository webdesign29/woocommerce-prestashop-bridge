<?php
namespace WD29\Bridge {
    final class Engine {
        public $maps=[], $links=[], $contacts=[], $queries=[];
        public function sql($sql,$args=[]) {
            $this->queries[]=$sql;
            if(strpos($sql,'SELECT ')!==0)throw new \RuntimeException('Unexpected write');
            if(strpos($sql,'account_links')!==false){foreach($this->links as $key=>$id)if($id===$args[0])return [['record_key'=>$key]];return [];}
            if(strpos($sql,'contacts')!==false)return isset($this->contacts[$args[0]])?[['record_key'=>$this->contacts[$args[0]]]]:[];
            if(strpos($sql,'WHERE kind=?')!==false){foreach($this->maps as $key=>$map)if($map['kind']===$args[0]&&$map['local_id']===$args[1])return [['record_key'=>$key]+$map];return [];}
            return isset($this->maps[$args[0]])?[$this->maps[$args[0]]]:[];
        }
    }
    final class RecordPanel {
        public static $locals=0,$compares=0,$syncs=0,$fail=false,$input=[],$nativeCalls=0;
        static function nativeId($e,$kind,$key){self::$nativeCalls++;if(!preg_match('/^(woo|ps):'.$kind.':[1-9][0-9]*$/D',$key))throw new \RuntimeException('Invalid');return 5;}
        static function local($e,$k,$id){self::$locals++;return ['state'=>'ready','reason'=>'Comparez les données enregistrées.'];}
        static function compare($e,$k,$id){self::$compares++;return ['state'=>'changed','can_sync'=>true];}
        static function sync($e,$k,$id,$input){self::$syncs++;self::$input=$input;if(self::$fail)throw new \RuntimeException('PRIVATE SECRET');return ['ok'=>true,'state'=>'applied'];}
    }
    final class ProductLinksAdmin {static function render($post){echo '<div>Public link retained</div>';}}
}
namespace {
require __DIR__.'/../includes/Protocol.php';
require __DIR__.'/../includes/WooAdapter.php';
require __DIR__.'/../includes/RecordPanelAdmin.php';
use WD29\Bridge\RecordPanelAdmin;
class NavigationStop extends RuntimeException {}
$loggedIn=true;
function is_user_logged_in(){global $loggedIn;return $loggedIn;}
function auth_redirect(){throw new NavigationStop('login');}
function wp_safe_redirect($url){throw new NavigationStop($url);}
function wp_die($message,$title='',$args=[]){throw new NavigationStop('denied:'.($args['response']??0));}
function get_edit_post_link($id,$context){return 'https://woo.example/wp-admin/post.php?post='.$id.'&action=edit';}
function get_edit_user_link($id){return 'https://woo.example/wp-admin/user-edit.php?user_id='.$id;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function navigate($kind,$method='GET') {$_GET=['kind'=>$kind,'key'=>'woo:'.$kind.':5'];$_SERVER['REQUEST_METHOD']=$method;try{\WD29\Bridge\RecordPanelAdmin::openRecord();}catch(NavigationStop $e){return $e->getMessage();}throw new RuntimeException('No redirect');}
class JsonReply extends RuntimeException {public $payload,$status;function __construct($payload,$status){$this->payload=$payload;$this->status=$status;}}
$checks=0;$caps=['edit_post'=>true,'edit_shop_order'=>true,'list_users'=>true,'edit_user'=>true];$type='product';$status='publish';$roles=['customer'];$customerOrigin='';$orderOrigin='';$orderSnapshot=[];$orderType='shop_order';$hooks=[];$boxes=[];$engine=new WD29\Bridge\Engine();
function check($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function refuses($fn,$m){$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}check($failed,$m);}
function wd29_bridge(){global $engine;return $engine;}
function current_user_can($cap,$id=null){global $caps;return !empty($caps[$cap]);}
function get_post_type($id){global $type;return $type;}
function get_post_status($id){global $status;return $status;}
function wc_get_product($id){return $id>0?(object)['id'=>$id]:false;}
function get_post($id){return (object)['ID'=>$id];}
function get_userdata($id){global $roles;return $id>0?(object)['ID'=>$id,'roles'=>$roles]:false;}
function get_user_meta($id,$key,$single){global $customerOrigin;return $customerOrigin;}
function wc_get_order($id){return new class($id){private $id;function __construct($id){$this->id=$id;}function get_type(){global $orderType;return $orderType;}function get_status(){global $status;return $status;}function get_meta($key){global $orderOrigin,$orderSnapshot;return $key==='_wd29_bridge_origin'?$orderOrigin:$orderSnapshot;}function get_id(){return $this->id;}function get_edit_order_url(){return 'https://woo.example/wp-admin/admin.php?page=wc-orders&action=edit&id='.$this->id;}};}
function add_action($name,$cb,...$args){global $hooks;$hooks[$name]=$cb;}
function add_meta_box($id,$title,$callback,$screen,...$args){global $boxes;$boxes[$screen]=$id;}
function wc_get_page_screen_id($name){return 'woocommerce_page_wc-orders';}
function wp_enqueue_script(...$args){}
function plugins_url($path,$file){return 'https://woo.example/'.$path;}
function admin_url($path){return 'https://woo.example/wp-admin/'.$path;}
function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}
function wp_create_nonce($action){return 'nonce-'.$action;}
function wp_verify_nonce($nonce,$action){return $nonce==='nonce-'.$action;}
function wp_unslash($v){return stripslashes($v);}
function nocache_headers(){}
function wp_send_json($data,$status=200){throw new JsonReply($data,$status);}
function request($kind,$overrides=[],$method='POST'){$_SERVER['REQUEST_METHOD']=$method;$_POST=array_merge(['kind'=>$kind,'id'=>'5','op'=>'compare','nonce'=>'nonce-wd29_record_tools_'.$kind.'_5'],$overrides);try{RecordPanelAdmin::ajax();}catch(JsonReply $r){return $r;}throw new RuntimeException('No response');}
RecordPanelAdmin::register('/plugin/main.php');RecordPanelAdmin::orderBox();
check(isset($hooks['add_meta_boxes_product'],$hooks['edit_user_profile'],$hooks['show_user_profile'],$hooks['woocommerce_product_after_variable_attributes'],$hooks['wp_ajax_wd29_record_tools']),'Native hooks missing');
check(isset($boxes['shop_order'],$boxes['woocommerce_page_wc-orders']),'Classic or HPOS order box missing');
check(!isset($hooks['wp_ajax_nopriv_wd29_record_tools']),'Anonymous action registered');
foreach(['product','order','customer'] as $kind){
 ob_start();RecordPanelAdmin::render($kind,5);$html=ob_get_clean();check(strpos($html,'data-wd29-record-panel')!==false,'Native staff panel missing');
 check(request($kind)->status===200,'Compare rejected native permission');
 check(request($kind,['nonce'=>'bad'])->status===403,'CSRF accepted');
 check(request($kind,['nonce'=>['bad']])->status===403,'Array nonce accepted');
 check(request($kind,['nonce'=>'nonce-wd29_record_tools_'.$kind.'_4'])->status===403,'Other-record nonce accepted');
}
check(WD29\Bridge\RecordPanel::$locals===3&&WD29\Bridge\RecordPanel::$compares===3,'Rendering contacted peer');
$caps['edit_post']=false;check(request('product')->status===403,'Product permission bypass');$caps['edit_post']=true;
$caps['edit_shop_order']=false;check(request('order')->status===403,'Order permission bypass');$caps['edit_shop_order']=true;
$caps['list_users']=false;check(request('customer')->status===403,'Customer self-profile permission bypass');$caps['list_users']=true;
$caps['edit_user']=false;check(request('customer')->status===403,'Customer edit permission bypass');$caps['edit_user']=true;
check(request('product',[],'GET')->status===405,'GET accepted');check(request('product',['id'=>['5']])->status===400,'Array ID accepted');
$mutation=['op'=>'sync','key'=>'woo:product:5','direction'=>'out','hash'=>str_repeat('a',64),'destination'=>'','confirm'=>'1'];
check(request('product',$mutation)->payload['data']['state']==='applied','Explicit mutation failed');
check(WD29\Bridge\RecordPanel::$input['destination']==='','Missing-copy empty destination lost');
check(request('product',array_merge($mutation,['confirm'=>'0']))->status===400,'Unconfirmed write accepted');
check(request('product',array_merge($mutation,['hash'=>['bad']]))->status===400,'Array hash accepted');
WD29\Bridge\RecordPanel::$fail=true;$r=request('product',$mutation);check($r->status===409&&strpos(json_encode($r->payload),'PRIVATE')===false,'Raw mutation exception exposed');
$adapter=new WD29\Bridge\WooAdapter();$adapter->engine=$engine;
foreach(['product'=>'post.php','order'=>'page=wc-orders','customer'=>'user-edit.php'] as $kind=>$path){check(strpos(navigate($kind),$path)!==false,'Wrong native edit redirect');}
check(navigate('product','POST')==='denied:405','Navigation accepted POST');
$loggedIn=false;$before=WD29\Bridge\RecordPanel::$nativeCalls;check(navigate('product')==='login','Anonymous navigation did not log in');check(WD29\Bridge\RecordPanel::$nativeCalls===$before,'Anonymous navigation resolved record');$loggedIn=true;
$caps['edit_post']=false;check(navigate('product')==='denied:403','Navigation bypassed native edit permission');$caps['edit_post']=true;
$link=$adapter->recordPanelAdminUrl('product','woo:product:5');check(strpos($link,'action=wd29_open_record')!==false&&strpos($link,'nonce')===false,'Native redirect URL invalid');

check($adapter->recordPanelIdentity('product',5)==='woo:product:5','Unmapped original identity wrong');
check($adapter->recordPanelIdentity('order',5)==='woo:order:5','Unmapped order identity wrong');
check($adapter->recordPanelIdentity('customer',5)==='woo:customer:5','Native user confused with private contact');
$engine->maps['ps:product:99']=['kind'=>'product','local_id'=>5];check($adapter->recordPanelIdentity('product',5)==='ps:product:99','Imported product key wrong');
$engine->maps['ps:order:44']=['kind'=>'order','local_id'=>5];$orderOrigin='ps';$orderSnapshot=['key'=>'ps:order:44'];check($adapter->recordPanelIdentity('order',5)==='ps:order:44','Imported order key wrong');
$orderSnapshot=['key'=>'ps:order:45'];refuses(fn()=>$adapter->recordPanelIdentity('order',5),'Wrong order snapshot accepted');$orderSnapshot=[];
$engine->maps=[];refuses(fn()=>$adapter->recordPanelIdentity('order',5),'Orphan imported order became original');$orderOrigin='';
$engine->maps['woo:product:7']=['kind'=>'product','local_id'=>5];refuses(fn()=>$adapter->recordPanelIdentity('product',5),'Other local original ID accepted');$engine->maps=[];
$customerOrigin='ps:customer:90';$engine->links[$customerOrigin]=5;$engine->maps[$customerOrigin]=['kind'=>'customer','local_id'=>700];$engine->contacts[700]=$customerOrigin;
check($adapter->recordPanelIdentity('customer',5)==='ps:customer:90','Private contact/native user distinction failed');
$engine->contacts[700]='ps:customer:91';refuses(fn()=>$adapter->recordPanelIdentity('customer',5),'Wrong private contact ownership accepted');$engine->contacts[700]=$customerOrigin;
$customerOrigin='ps:customer:91';refuses(fn()=>$adapter->recordPanelIdentity('customer',5),'Wrong native account origin accepted');
$customerOrigin='';refuses(fn()=>$adapter->recordPanelIdentity('customer',5),'Orphan account link became original');$engine->links=[];
$roles=['administrator'];refuses(fn()=>$adapter->recordPanelIdentity('customer',5),'Noncustomer account accepted');$roles=['customer'];
$type='product_variation';refuses(fn()=>$adapter->recordPanelIdentity('product',5),'Standalone variant accepted');$type='product';
$status='trash';refuses(fn()=>$adapter->recordPanelIdentity('product',5),'Trashed product accepted');
$status='checkout-draft';refuses(fn()=>$adapter->recordPanelIdentity('order',5),'Checkout draft accepted');
check(count(array_filter($engine->queries,fn($q)=>strpos($q,'SELECT ')!==0))===0,'Identity lookup wrote storage');
echo "PASS: $checks native record panel hooks, capabilities, CSRF, safe dispatch and identity assertions\n";
}
