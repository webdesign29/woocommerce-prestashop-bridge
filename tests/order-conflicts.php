<?php
if (!class_exists('WD29\\Bridge\\Protocol',false)) { require_once __DIR__.'/../includes/Protocol.php'; }
if (!class_exists('WD29\\Bridge\\OrderConflicts',false)) { require_once __DIR__.'/../includes/OrderConflicts.php'; }
use WD29\Bridge\OrderConflicts;
function checkConflict(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$legacy=['key'=>'ps:order:2','number'=>'TEST','status'=>'ps-state-14','currency'=>'EUR','total'=>'24.00','tax'=>'4.00','shipping_net'=>'0.00','shipping_tax'=>'0.00','discount'=>'0.00','billing'=>['city'=>'Synthetic A'],'shipping'=>['city'=>'Synthetic B'],'items'=>[['product'=>'ps:product:1','name'=>'Synthetic item','quantity'=>1,'net'=>'20.00','tax'=>'4.00']],'created'=>'2026-09-15T12:00:00+00:00'];
$upgrade=$legacy; $upgrade['refunds']=[]; $upgrade['custom_fields']=[]; $upgrade['items'][0]['line_id']='12';
checkConflict(OrderConflicts::equivalentAugmentation($legacy,$upgrade),'Safe empty-field/line-id augmentation not recognized');
checkConflict(OrderConflicts::equivalentAugmentation($upgrade,$legacy),'Equivalence is not symmetric');
checkConflict(OrderConflicts::equivalentAugmentation($upgrade,$upgrade),'Identical full order rejected');
checkConflict(!OrderConflicts::equivalentAugmentation([],[]),'Incomplete orders accepted');
foreach (['status'=>'completed','total'=>'25.00','tax'=>'5.00','shipping_net'=>'1.00','shipping_tax'=>'1.00','discount'=>'1.00','currency'=>'USD','number'=>'CHANGED','created'=>null] as $key=>$value) {
 $changed=$upgrade; $changed[$key]=$value;
 checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Meaningful order difference ignored: '.$key);
}
foreach (['product'=>'ps:product:2','name'=>'Changed','quantity'=>2,'net'=>'21.00','tax'=>'5.00'] as $key=>$value) {
 $changed=$upgrade; $changed['items'][0][$key]=$value;
 checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Meaningful line difference ignored: '.$key);
}
foreach ([null,false,['note'=>['present'=>false,'value'=>null]],['note'=>['present'=>true,'value'=>'private']]] as $value) {
 $changed=$upgrade; $changed['custom_fields']=$value;
 checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Meaningful custom fields ignored');
}
$changed=$upgrade; $changed['refunds']=[['key'=>'ps:refund:1','amount'=>'1.00']];
checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Real refund ignored');
$changed=$upgrade; $changed['billing']['city']='Different';
checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Address edit ignored');
$changed=$upgrade; $changed['items'][0]['line_id']='13';
checkConflict(!OrderConflicts::equivalentAugmentation($upgrade,$changed),'Changed known line identity ignored');
foreach (['',0,'0','bad',null] as $lineId) {
 $changed=$upgrade; $changed['items'][0]['line_id']=$lineId;
 checkConflict(!OrderConflicts::equivalentAugmentation($legacy,$changed),'Malformed augmented line identity accepted');
}
$legacyTwo=$legacy; $legacyTwo['items'][]=array_merge($legacy['items'][0],['name'=>'Second item']);
$upgradeTwo=$legacyTwo; $upgradeTwo['items'][0]['line_id']='12'; $upgradeTwo['items'][1]['line_id']='12';
checkConflict(!OrderConflicts::equivalentAugmentation($legacyTwo,$upgradeTwo),'Duplicate source lines accepted');
$upgradeTwo['items'][1]['line_id']='13'; $upgradeTwo['items']=array_reverse($upgradeTwo['items']);
checkConflict(!OrderConflicts::equivalentAugmentation($legacyTwo,$upgradeTwo),'Positional line reordering accepted');
$review=OrderConflicts::review($legacy,$upgrade);
checkConflict($review['classification']==='schema_augmentation_only' && $review['native_check_required']===true,'Upgrade review classification missing native guard');
checkConflict(strpos(json_encode($review),'Synthetic A')===false && strpos(json_encode($review),'Synthetic item')===false,'Review summary exposed contact/item data');
$changed=$upgrade; $changed['custom_fields']=['note'=>['present'=>true,'value'=>'very private']];
$review=OrderConflicts::review($legacy,$changed);
checkConflict($review['classification']==='review_required' && in_array('custom_fields',$review['changed_fields'],true) && strpos(json_encode($review),'very private')===false,'Review summary exposed custom value or missed conflict');
echo "PASS: safe order augmentation equivalence; status/financial/address/custom/refund/line identity differences preserved; private review summaries\n";
