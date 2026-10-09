<?php
namespace WD29\Bridge {
    // Isolated native field adapter: no WordPress, PrestaShop, network or customer writes.
    class CustomFields {
        public static function exportCustomer(int $id): array { return $GLOBALS['custom']; }
        public static function rules($rules): array { return $rules; }
    }
}
namespace {
require __DIR__.'/../includes/Protocol.php';
require __DIR__.'/../includes/Engine.php';
require_once __DIR__.'/../includes/CustomerRecordGuard.php';
use WD29\Bridge\CustomerRecordGuard;
use WD29\Bridge\Engine;
class GuardAdapter {
    public $db,$enabled=true,$queries=0,$throw=false;
    public function __construct() {
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE fixture_wd29_bridge_account_links(record_key TEXT,native_id INTEGER);CREATE TABLE fixture_wd29_bridge_contacts(record_key TEXT,data TEXT);CREATE TABLE ps_address(id_address INTEGER,id_customer INTEGER,alias TEXT,deleted INTEGER);CREATE TABLE ps_state(id_state INTEGER,id_country INTEGER,iso_code TEXT);');
    }
    public function prefix(){return 'fixture_';}
    public function site(){return $GLOBALS['guardSite'];}
    public function config(){return ['native_customers'=>$this->enabled];}
    public function sql($sql,$params=[]) {
        if (!preg_match('/^SELECT /',$sql))throw new RuntimeException('Unexpected guard write');
        $this->queries++;if($this->throw)throw new RuntimeException('private@example.test SQL secret');
        $q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
$count=0;
function check($ok,$message){global $count;$count++;if(!$ok)throw new RuntimeException($message);}
function inspect($expected,$reason=null,?array $incoming=null){global $engine,$key;$r=CustomerRecordGuard::inspect($engine,$key,$incoming);check($r['state']===$expected,'Unexpected state: '.json_encode($r));if($reason!==null)check($r['reason']===$reason,'Unexpected reason');check(array_keys($r)===['state','native_id','reason'],'Unexpected personal fields');check(strpos(json_encode($r),'private@')===false,'PII leaked');return $r;}
function resetFixture(){global $adapter,$engine,$key,$baseline;$adapter=new GuardAdapter();$engine=new Engine($adapter);$q=$adapter->db->prepare('INSERT INTO fixture_wd29_bridge_account_links VALUES(?,7)');$q->execute([$key]);$q=$adapter->db->prepare('INSERT INTO fixture_wd29_bridge_contacts VALUES(?,?)');$q->execute([$key,json_encode($baseline)]);nativeFixture();}

function get_userdata($id){return $GLOBALS['user'];}
function get_user_meta($id,$key,$single){return $GLOBALS['origin'];}
function user_can($user,$cap){return $GLOBALS['privileged'];}
function get_option($key,$default){return $GLOBALS['rules'];}
class WC_Customer {
 public $values=[];
 public function __construct($id=0){$this->values=$id?$GLOBALS['native']:[];}
 public function save(){throw new RuntimeException('Forbidden native write');}
 public function get_email($context="view"){return $this->values["email"]??"";}
 public function set_email($value){$this->values["email"]=(string)$value;}
 public function get_first_name($context="view"){return $this->values["first_name"]??"";}
 public function set_first_name($value){$this->values["first_name"]=(string)$value;}
 public function get_last_name($context="view"){return $this->values["last_name"]??"";}
 public function set_last_name($value){$this->values["last_name"]=(string)$value;}
 public function get_billing_email($context="view"){return $this->values["billing_email"]??"";}
 public function set_billing_email($value){$this->values["billing_email"]=(string)$value;}
 public function get_billing_first_name($context="view"){return $this->values["billing_first_name"]??"";}
 public function set_billing_first_name($value){$this->values["billing_first_name"]=(string)$value;}
 public function get_billing_last_name($context="view"){return $this->values["billing_last_name"]??"";}
 public function set_billing_last_name($value){$this->values["billing_last_name"]=(string)$value;}
 public function get_billing_company($context="view"){return $this->values["billing_company"]??"";}
 public function set_billing_company($value){$this->values["billing_company"]=(string)$value;}
 public function get_billing_address_1($context="view"){return $this->values["billing_address_1"]??"";}
 public function set_billing_address_1($value){$this->values["billing_address_1"]=(string)$value;}
 public function get_billing_address_2($context="view"){return $this->values["billing_address_2"]??"";}
 public function set_billing_address_2($value){$this->values["billing_address_2"]=(string)$value;}
 public function get_billing_city($context="view"){return $this->values["billing_city"]??"";}
 public function set_billing_city($value){$this->values["billing_city"]=(string)$value;}
 public function get_billing_postcode($context="view"){return $this->values["billing_postcode"]??"";}
 public function set_billing_postcode($value){$this->values["billing_postcode"]=(string)$value;}
 public function get_billing_country($context="view"){return $this->values["billing_country"]??"";}
 public function set_billing_country($value){$this->values["billing_country"]=(string)$value;}
 public function get_billing_state($context="view"){return $this->values["billing_state"]??"";}
 public function set_billing_state($value){$this->values["billing_state"]=(string)$value;}
 public function get_billing_phone($context="view"){return $this->values["billing_phone"]??"";}
 public function set_billing_phone($value){$this->values["billing_phone"]=(string)$value;}
 public function get_shipping_first_name($context="view"){return $this->values["shipping_first_name"]??"";}
 public function set_shipping_first_name($value){$this->values["shipping_first_name"]=(string)$value;}
 public function get_shipping_last_name($context="view"){return $this->values["shipping_last_name"]??"";}
 public function set_shipping_last_name($value){$this->values["shipping_last_name"]=(string)$value;}
 public function get_shipping_company($context="view"){return $this->values["shipping_company"]??"";}
 public function set_shipping_company($value){$this->values["shipping_company"]=(string)$value;}
 public function get_shipping_address_1($context="view"){return $this->values["shipping_address_1"]??"";}
 public function set_shipping_address_1($value){$this->values["shipping_address_1"]=(string)$value;}
 public function get_shipping_address_2($context="view"){return $this->values["shipping_address_2"]??"";}
 public function set_shipping_address_2($value){$this->values["shipping_address_2"]=(string)$value;}
 public function get_shipping_city($context="view"){return $this->values["shipping_city"]??"";}
 public function set_shipping_city($value){$this->values["shipping_city"]=(string)$value;}
 public function get_shipping_postcode($context="view"){return $this->values["shipping_postcode"]??"";}
 public function set_shipping_postcode($value){$this->values["shipping_postcode"]=(string)$value;}
 public function get_shipping_country($context="view"){return $this->values["shipping_country"]??"";}
 public function set_shipping_country($value){$this->values["shipping_country"]=(string)$value;}
 public function get_shipping_state($context="view"){return $this->values["shipping_state"]??"";}
 public function set_shipping_state($value){$this->values["shipping_state"]=(string)$value;}
 public function get_shipping_phone($context="view"){return $this->values["shipping_phone"]??"";}
 public function set_shipping_phone($value){$this->values["shipping_phone"]=(string)$value;}
}
$guardSite='woo';
$key='ps:customer:5';
$baseline=['key'=>$key,'email'=>'private@example.test','first_name'=>'Anne','last_name'=>'Le Roux','company'=>'Breizh','guest'=>false,'deleted'=>false,'billing'=>['address_1'=>'1 Rue Test','city'=>'Brest','phone'=>'0200000000'],'shipping'=>['city'=>'Quimper'],'custom_fields'=>['loyalty'=>['present'=>true,'value'=>12]]];
function nativeFixture(){global $baseline,$key;$GLOBALS['user']=(object)['roles'=>['customer']];$GLOBALS['origin']=$key;$GLOBALS['privileged']=false;$GLOBALS['native']=['email'=>$baseline['email'],'first_name'=>$baseline['first_name'],'last_name'=>$baseline['last_name'],'billing_email'=>$baseline['email']];foreach(['billing','shipping'] as $kind)foreach($baseline[$kind] as $field=>$value)$GLOBALS['native'][$kind.'_'.$field]=$value;$GLOBALS['rules']=[['id'=>'loyalty','entities'=>['customer'],'source'=>'meta']];$GLOBALS['custom']=['loyalty'=>['present'=>true,'value'=>'12']];}
resetFixture();inspect('linked');check($adapter->queries===3,'Unbounded base reads');
foreach(['email','first_name','last_name','billing_email','billing_address_1','billing_city','billing_phone','shipping_city'] as $field){resetFixture();$GLOBALS['native'][$field]='local edit';inspect('conflict');}
resetFixture();$GLOBALS['native']['shipping_phone']='local field not written by baseline';inspect('linked');
resetFixture();$GLOBALS['native']['billing_city']='Brest';$GLOBALS['custom']['loyalty']['value']='13';inspect('conflict','native_custom_fields_changed');
resetFixture();$GLOBALS['custom']['loyalty']['present']=false;inspect('conflict','native_custom_fields_changed');
resetFixture();$GLOBALS['rules'][0]['source']='acf';inspect('conflict','native_custom_fields_changed');
resetFixture();$GLOBALS['user']=false;inspect('conflict','native_account_missing');
resetFixture();$GLOBALS['user']->roles=['customer','administrator'];inspect('conflict','native_account_role_changed');
resetFixture();$GLOBALS['privileged']=true;inspect('conflict','native_account_role_changed');
resetFixture();$GLOBALS['origin']='ps:customer:99';inspect('conflict','native_account_origin_changed');

resetFixture();$adapter->enabled=false;check(inspect('directory_only')['native_id']===7,'Disabled guard lost linked ID');check($adapter->queries===1,'Disabled native guard queried more than link');
resetFixture();$adapter->db->exec('DELETE FROM fixture_wd29_bridge_account_links');inspect('not_linked','native_account_not_created');
resetFixture();$adapter->db->exec("INSERT INTO fixture_wd29_bridge_account_links VALUES('other:customer:5',7)");inspect('conflict','account_link_ambiguous');
resetFixture();$adapter->db->exec('DELETE FROM fixture_wd29_bridge_contacts');inspect('conflict','contact_baseline_missing');
resetFixture();$adapter->db->exec("UPDATE fixture_wd29_bridge_contacts SET data='{}'");inspect('conflict','contact_baseline_missing');
resetFixture();$adapter->throw=true;inspect('conflict','native_account_unavailable');
resetFixture();$originalKey=$key;$key='invalid';inspect('conflict','identity_invalid');check($adapter->queries===0,'Invalid key queried DB');
$key=str_replace(':customer:',':guest:',$originalKey);inspect('directory_only','guest_contact_only');$key=$originalKey;

// Incoming fields absent from the previous source snapshot must not erase native additions.
resetFixture();$incoming=$baseline;$incoming['shipping']['phone']='0299999999';$GLOBALS['native']['shipping_phone']='0288888888';inspect('linked');inspect('conflict','native_new_address_field_conflict',$incoming);
resetFixture();$incoming=$baseline;$incoming['shipping']['phone']='0299999999';$GLOBALS['native']['shipping_phone']='0299999999';inspect('linked',null,$incoming);
resetFixture();$incoming=$baseline;$incoming['shipping']['phone']='0299999999';inspect('linked',null,$incoming);
resetFixture();$incoming=$baseline;$incoming['shipping']['phone']='';$GLOBALS['native']['shipping_phone']='0299999999';inspect('conflict','native_new_address_field_conflict',$incoming);
resetFixture();$incoming=$baseline;$incoming['billing']['company']='New source';$GLOBALS['native']['billing_company']='Local company';inspect('conflict','native_new_address_field_conflict',$incoming);
foreach(['different','same','missing','delete'] as $case){
 resetFixture();$incoming=$baseline;$GLOBALS['rules'][]=['id'=>'new_field','entities'=>['customer'],'source'=>'meta'];$GLOBALS['custom']['new_field']=['present'=>$case!=='missing','value'=>$case==='same'?'12':'local-value'];$incoming['custom_fields']['new_field']=['present'=>$case!=='delete','value'=>12];
 inspect('linked');inspect(in_array($case,['same','missing'],true)?'linked':'conflict',in_array($case,['same','missing'],true)?null:'native_new_custom_field_conflict',$incoming);
}
resetFixture();$old=$baseline;unset($old['custom_fields']);$adapter->db->prepare('UPDATE fixture_wd29_bridge_contacts SET data=?')->execute([json_encode($old)]);$incoming=$baseline;$GLOBALS['custom']['loyalty']['value']='locally-changed';inspect('conflict','native_new_custom_field_conflict',$incoming);
resetFixture();$incoming=$baseline;$incoming['key']='ps:customer:999';inspect('conflict','incoming_contact_invalid',$incoming);

echo 'PASS: '.$count." native customer guard assertions; read-only, bounded, no personal data or real emails\n";
}
