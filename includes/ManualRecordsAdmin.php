<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminInput.php';
require_once __DIR__.'/AdminContext.php';
final class ManualRecordsAdmin
{
    private static function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function handle(Engine $engine,array $input): ?string
    {
        $action=$input['bridge_action']??'';if($action==='manual_records_scan')return \WD29\Bridge\I18n::t('Comparison refreshed.');
        if($action!=='manual_record_sync')return null;
        $r=$engine->manualRecordSync((string)($input['manual_kind']??''),(string)($input['manual_direction']??''),(string)($input['manual_key']??''),(string)($input['manual_hash']??''),(string)($input['manual_destination']??''));
        $target=$input['manual_direction']==='in'?$engine->adapter->site():($engine->adapter->site()==='woo'?'ps':'woo');
        return \WD29\Bridge\I18n::t('Record synchronized to ').AdminContext::name($target).' · #'.$r['local_id'].\WD29\Bridge\I18n::t('. Automatic mode unchanged.').(($r['ack']??'')==='pending'?\WD29\Bridge\I18n::t(' Source confirmation pending; refresh before retrying.'):'');
    }
    public static function batch(Engine $engine,array $input): array
    {
        return $engine->manualRecordBatch((string)($input['manual_scope']??''),(string)($input['manual_direction']??''),(int)($input['manual_family']??0),(int)($input['manual_offset']??0),(int)($input['manual_index']??0));
    }
    public static function render(Engine $engine,string $token,array $input,string $batchUrl=''): string
    {
        if(class_exists(AdminDesign::class)&&AdminDesign::currentView()!=='sync'&&!in_array($input['bridge_action']??'',['manual_records_scan','manual_record_sync'],true))return '';
        $kind=in_array($input['manual_kind']??'',['product','order','customer'],true)?$input['manual_kind']:'product';$direction=($input['manual_direction']??'out')==='in'?'in':'out';$offset=max(0,min(10000000,(int)($input['manual_offset']??0)));
        $local=$engine->adapter->site();$source=$direction==='out'?$local:($local==='woo'?'ps':'woo');$target=$source==='woo'?'ps':'woo';$targetName=AdminContext::name($target);
        $hidden=static function(array $fields)use($token){$html=$token;foreach($fields as $key=>$value)$html.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';return $html;};
        $html=\WD29\Bridge\I18n::t('<h2>Compare and synchronize</h2><form method="post" class="wd-compare-controls">').$token.'<label>Fiches <select name="manual_kind">';
        foreach(['product'=>\WD29\Bridge\I18n::t('Products, variations and metadata'),'order'=>\WD29\Bridge\I18n::t('Orders'),'customer'=>\WD29\Bridge\I18n::t('Customer contacts')] as $value=>$label)$html.='<option value="'.$value.'"'.($value===$kind?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>Source → destination <select name="manual_direction">';
        foreach(['woo','ps'] as $origin){$d=$origin===$local?'out':'in';$html.='<option value="'.$d.'"'.($origin===$source?' selected':'').'>'.self::e(AdminContext::name($origin).' → '.AdminContext::name($origin==='woo'?'ps':'woo')).'</option>';}
        $html.=\WD29\Bridge\I18n::t('</select></label><button name="bridge_action" value="manual_records_scan" class="button button-primary btn btn-primary">Compare / actualiser</button></form>');
        $html.='<div class="wd-bulk"><form method="post" data-full-sync data-sync-endpoint="'.self::e($batchUrl).'">'.$hidden(['manual_direction'=>$direction,'manual_scope'=>$kind]).\WD29\Bridge\I18n::t('<button type="button" data-sync-start disabled class="button btn btn-default">Synchronize this entire record type → ').self::e($targetName).'</button></form><form method="post" data-full-sync data-sync-endpoint="'.self::e($batchUrl).'">'.$hidden(['manual_direction'=>$direction,'manual_scope'=>'all']).\WD29\Bridge\I18n::t('<button type="button" data-sync-start disabled class="button button-primary btn btn-primary">Full synchronization → ').self::e($targetName).\WD29\Bridge\I18n::t('</button></form></div><details class="wd-sync-scope"><summary>Synchronization scope and stock</summary><p class="wd-manual-note">Full synchronization processes products, contacts and then orders, only in the selected direction. Identical copies are skipped; conflicts are flagged without overwriting. Variations, images and allowed fields follow their products. Login accounts remain optional. Existing product quantities continue to use stock events; this action does not force an inventory count.</p></details><noscript>JavaScript is required to run full batches. Individual actions remain available.</noscript><section id="wd-sync-progress" hidden aria-label="Synchronization progress"><strong data-sync-status role="status"></strong><progress></progress><button type="button" data-sync-stop class="button btn btn-default">Stop after the current record</button><button type="button" data-sync-copy class="button btn btn-default">Copy log</button><pre data-sync-log></pre><p>Keep this page open. Stopping retains processed records; restarting skips records already synchronized.</p></section>');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison selector; mutation handlers verify nonces separately.
        $active=in_array($input['bridge_action']??'',['manual_records_scan','manual_record_sync'],true)||AdminInput::text($_GET,'wd_view','')==='sync';if(!$active)return $html;
        try{$delta=$engine->manualRecordDelta($kind,$direction,$offset);}catch(\Throwable $e){return $html.'<p role="alert">Comparaison indisponible : '.self::e($e->getMessage()).\WD29\Bridge\I18n::t(' Check the connection and versions of both plugins.</p>');}
        $modes=['disabled'=>\WD29\Bridge\I18n::t('stopped'),'audit'=>'audit','live'=>'live'];$html.='<div class="wd-comparison-direction"><strong>'.self::e(AdminContext::name($source).' → '.$targetName).'</strong><small>Source : '.self::e($modes[$delta['mode']]??$delta['mode']).' · destination : '.self::e($modes[$delta['destination_mode']]??$delta['destination_mode']).\WD29\Bridge\I18n::t(' · batch ').((int)($offset/20)+1).'</small></div>';
        $counts=['missing'=>0,'changed'=>0,'same'=>0,'conflict'=>0,'unavailable'=>0];foreach($delta['rows'] as $row)$counts[$row['state']]++;
        $html.='<p>'.$counts['missing'].\WD29\Bridge\I18n::t(' to create · ').$counts['changed'].\WD29\Bridge\I18n::t(' to update · ').$counts['same'].\WD29\Bridge\I18n::t(' identical · ').($counts['conflict']+$counts['unavailable']).\WD29\Bridge\I18n::t(' to review (this batch).</p><label class="wd-difference-filter"><input type="checkbox" data-differences-only> Show differences only</label><table class="table wd-order-comparison"><thead><tr><th class="wd-side-woo">WordPress / WooCommerce</th><th class="wd-side-ps">PrestaShop</th><th>Differences</th><th>Action</th></tr></thead><tbody>');
        $labels=['missing'=>\WD29\Bridge\I18n::t('Copy missing'),'changed'=>\WD29\Bridge\I18n::t('Changes available'),'same'=>\WD29\Bridge\I18n::t('Already synchronized'),'conflict'=>\WD29\Bridge\I18n::t('Local change or conflict to review'),'unavailable'=>\WD29\Bridge\I18n::t('Source unavailable')];
        foreach($delta['rows'] as $row){$html.='<tr data-manual-record="'.self::e($row['key']).'" data-delta-state="'.self::e(!empty($row['stock_difference'])?'stock':$row['state']).'">';foreach(['woo','ps'] as $side){$original=$side===$source;$summary=$original?($row['summary']??null):($row['destination_summary']??null);$id=$original?substr($row['key'],strrpos($row['key'],':')+1):($row['local_id']??null);$html.='<td class="wd-order-side wd-side-'.$side.'"><span class="wd-origin wd-side-'.$side.'">'.($original?'Original':\WD29\Bridge\I18n::t('Copy')).'</span><strong>'.self::e(AdminContext::name($side)).($id?' · #'.self::e($id):\WD29\Bridge\I18n::t(' · missing')).'</strong>';if($summary){$html.='<span>'.self::e($summary['label']??$summary['number']??'').'</span><small>'.self::e($summary['detail']??$summary['status_label']??$summary['status']??'').'</small>';if(isset($summary['total']))$html.='<span>'.self::e($summary['total'].' '.$summary['currency']).'</span>';}$html.='</td>';}
            $html.='<td>'.self::e($labels[$row['state']]).(!empty($row['stock_difference'])?\WD29\Bridge\I18n::t('<small>Quantities differ: see stock events in Activity.</small>'):'').'<small>'.self::e(implode(', ',$row['state']==='same'?[]:($row['changes']??[]))).'</small>'.(isset($row['error'])?'<small>'.self::e($row['error']).'</small>':'').'<code class="wd-record-key">'.self::e($row['key']).'</code></td><td>';
            if(in_array($row['state'],['missing','changed'],true))$html.='<form method="post">'.$hidden(['manual_kind'=>$kind,'manual_direction'=>$direction,'manual_offset'=>$offset,'manual_key'=>$row['key'],'manual_hash'=>$row['hash'],'manual_destination'=>$row['destination']]).'<button name="bridge_action" value="manual_record_sync" class="button btn btn-primary">'.self::e(($row['state']==='missing'?\WD29\Bridge\I18n::t('Create on '):\WD29\Bridge\I18n::t('Update on ')).$targetName).'</button></form>';
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';if(!$delta['rows'])$html.=\WD29\Bridge\I18n::t('<p>No original records in this batch.</p>');
        foreach([\WD29\Bridge\I18n::t('Previous')=>$offset>0?max(0,$offset-20):null,\WD29\Bridge\I18n::t('Next')=>$delta['next']] as $label=>$next)if($next!==null)$html.='<form method="post">'.$hidden(['manual_kind'=>$kind,'manual_direction'=>$direction,'manual_offset'=>$next]).'<button name="bridge_action" value="manual_records_scan" class="button btn btn-default">'.$label.'</button></form>';
        return $html;
    }
}
