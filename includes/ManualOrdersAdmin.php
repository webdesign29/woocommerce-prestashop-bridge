<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/AdminContext.php';
final class ManualOrdersAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
    public static function handle(Engine $engine,array $input): ?string
    {
        $action=$input['bridge_action']??'';
        if(!in_array($action,['manual_orders_scan','manual_order_sync'],true)){return null;}
        if($action==='manual_orders_scan'){return \WD29\Bridge\I18n::t('Order comparison refreshed.');}
        $direction=(string)($input['manual_direction']??'in');
        $r=$engine->manualOrderSync($direction,(string)($input['manual_key']??''),(string)($input['manual_hash']??''),(string)($input['manual_destination']??''));
        $local=$engine->adapter->site();$target=$direction==='in'?$local:($local==='woo'?'ps':'woo');
        return \WD29\Bridge\I18n::t('Order synchronized to ').AdminContext::name($target).' · ID #'.$r['local_id'].\WD29\Bridge\I18n::t('. Automatic mode is unchanged.').(($r['ack']??'')==='pending'?\WD29\Bridge\I18n::t(' Source confirmation pending; refresh before retrying.'):'');
    }
    private static function amount($total, $currency): string
    {
        $value=(string)$total;
        if(preg_match('/^(-?[0-9]+)(?:\.([0-9]+))?$/D',$value,$parts)){
            $fraction=rtrim($parts[2]??'','0');$value=$parts[1].','.str_pad($fraction,2,'0');
        }
        return self::e($value.' '.$currency);
    }
    private static function orderCell(string $side,bool $original,array $row): string
    {
        $name=AdminContext::name($side);$summary=$original?($row['summary']??null):($row['destination_summary']??null);
        if(!$original && !$row['local_id']){return '<td class="wd-order-side wd-side-'.$side.\WD29\Bridge\I18n::t('"><strong>No copy on ').self::e($name).\WD29\Bridge\I18n::t('</strong><small>This order exists only on its source store.</small></td>');}
        $id=$original?substr($row['key'],strrpos($row['key'],':')+1):(string)$row['local_id'];
        $number=$summary['number']??($original?$row['number']:'');
        $html='<td class="wd-order-side wd-side-'.$side.'"><span class="wd-origin wd-side-'.$side.'">'.($original?'Original':\WD29\Bridge\I18n::t('Imported copy')).'</span><strong>'.self::e($name).' · #'.self::e($id).'</strong>';
        if($number!=='' && (string)$number!==$id){$html.=\WD29\Bridge\I18n::t('<small>Reference: ').self::e($number).'</small>';}
        if($summary){$html.='<span>'.self::e($summary['status_label']??$summary['status']).'</span><span>'.self::amount($summary['total'],$summary['currency']).'</span>';}
        elseif($original){$html.=\WD29\Bridge\I18n::t('<span>Synchronized status: ').self::e($row['status']).'</span><span>'.self::amount($row['total'],$row['currency']).'</span>';}
        else{$html.=\WD29\Bridge\I18n::t('<small>Copy could not be read: review on ').self::e($name).'.</small>';}
        return $html.'</td>';
    }
    public static function render(Engine $engine,string $token,array $input): string
    {
        if(class_exists(AdminDesign::class)&&AdminDesign::currentView()!=='orders'&&!in_array($input['bridge_action']??'',['manual_orders_scan','manual_order_sync'],true))return '';
        $direction=($input['manual_direction']??'in')==='out'?'out':'in';$offset=max(0,min(10000000,(int)($input['manual_offset']??0)));
        $local=$engine->adapter->site();$remote=$local==='woo'?'ps':'woo';
        $source=$direction==='in'?$remote:$local;$target=$source==='woo'?'ps':'woo';
        $sourceName=AdminContext::name($source);$targetName=AdminContext::name($target);
        $active=in_array($input['bridge_action']??'',['manual_orders_scan','manual_order_sync'],true);
        $hidden=static function(array $values)use($token){$s=$token;foreach($values as $k=>$v){$s.='<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';}return $s;};
        $html=\WD29\Bridge\I18n::t('<h2>Orders to synchronize</h2><p>Choose the store where the orders were placed. WordPress stays on the left and PrestaShop on the right: the original and copy are identified in each column.</p><div class="wd-manual-directions">');
        foreach(['woo','ps'] as $origin){$dest=$origin==='woo'?'ps':'woo';$d=$origin===$local?'out':'in';$selected=$active&&$source===$origin;
            $html.='<form method="post" class="wd-direction-choice wd-side-'.$origin.'" data-selected="'.($selected?'true':'false').'">'.$hidden(['manual_direction'=>$d,'manual_offset'=>0]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan" aria-pressed="'.($selected?'true':'false').\WD29\Bridge\I18n::t('">Compare orders ').self::e($origin==='woo'?'WordPress':'PrestaShop').'</button><small>Originaux '.self::e(AdminContext::name($origin)).' → copies '.self::e(AdminContext::name($dest)).'</small></form>';
        }
        $html.=\WD29\Bridge\I18n::t('</div><p class="wd-manual-note">Comparing changes nothing. Manual synchronization applies only the selected order and its contact details to the indicated store, even in stopped or audit mode, without enabling automatic tracking. A configured connection and write permission at the destination are required. Checkout drafts are excluded.</p>');
        if(!$active){return $html;}
        try{$delta=$engine->manualOrderDelta($direction,$offset);}catch(\Throwable $e){return $html.'<p role="alert">Comparaison indisponible : '.self::e($e->getMessage()).\WD29\Bridge\I18n::t(' Check both plugin connections and versions.</p>');}
        $modes=['disabled'=>\WD29\Bridge\I18n::t('stopped'),'audit'=>'audit','live'=>'live'];
        $html.=\WD29\Bridge\I18n::t('<div class="wd-comparison-direction"><strong>Orders created on ').self::e($sourceName).\WD29\Bridge\I18n::t(' → copy on ').self::e($targetName).'</strong><small>'.self::e($sourceName).' : '.self::e($modes[$delta['mode']]??$delta['mode']).' · '.self::e($targetName).' : '.self::e($modes[$delta['destination_mode']]??$delta['destination_mode']).\WD29\Bridge\I18n::t(' · batch ').(int)($offset/20+1).'</small></div>';
        $counts=['missing'=>0,'changed'=>0,'same'=>0,'conflict'=>0];foreach($delta['rows'] as $r){$counts[$r['state']]++;}
        $html.='<p>'.$counts['missing'].\WD29\Bridge\I18n::t(' to create on ').self::e($targetName).' · '.$counts['changed'].\WD29\Bridge\I18n::t(' to update · ').$counts['same'].\WD29\Bridge\I18n::t(' already synchronized · ').$counts['conflict'].\WD29\Bridge\I18n::t(' conflict(s). This batch only; Next shows other orders.</p><table class="table wd-order-comparison"><thead><tr><th class="wd-side-woo">WordPress / WooCommerce<br><small>').($local==='woo'?\WD29\Bridge\I18n::t('This admin'):\WD29\Bridge\I18n::t('Partner store')).'</small></th><th class="wd-side-ps">PrestaShop<br><small>'.($local==='ps'?\WD29\Bridge\I18n::t('This admin'):\WD29\Bridge\I18n::t('Partner store')).\WD29\Bridge\I18n::t('</small></th><th>Difference between stores</th><th>Action on ').self::e($targetName).'</th></tr></thead><tbody>';
        $labels=['missing'=>\WD29\Bridge\I18n::t('Copy missing on ').$targetName,'changed'=>\WD29\Bridge\I18n::t('Update available on ').$targetName,'same'=>\WD29\Bridge\I18n::t('Already synchronized'),'conflict'=>\WD29\Bridge\I18n::t('Copy on ').$targetName.\WD29\Bridge\I18n::t(' : local change or conflict to review')];
        foreach($delta['rows'] as $r){
            $html.='<tr data-manual-order="'.self::e($r['key']).'">'.self::orderCell('woo',$source==='woo',$r).self::orderCell('ps',$source==='ps',$r).'<td>'.self::e($labels[$r['state']]).($r['changes']?'<br><small>'.self::e(implode(', ',$r['changes'])).'</small>':'').'<small class="wd-record-key">'.self::e($r['key']).'</small></td><td>';
            if(in_array($r['state'],['missing','changed'],true)){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$offset,'manual_key'=>$r['key'],'manual_hash'=>$r['hash'],'manual_destination'=>$r['destination']]).'<button class="button button-primary btn btn-primary" name="bridge_action" value="manual_order_sync">'.self::e(($r['state']==='missing'?\WD29\Bridge\I18n::t('Create on '):\WD29\Bridge\I18n::t('Update on ')).$targetName).'</button></form>';}
            $html.='</td></tr>';
        }
        $html.='</tbody></table>';
        if(!$delta['rows']){$html.=\WD29\Bridge\I18n::t('<p>No original orders from ').self::e($sourceName).\WD29\Bridge\I18n::t(' in this batch.</p>');}
        foreach([\WD29\Bridge\I18n::t('Previous')=>$offset>0?max(0,$offset-20):null,\WD29\Bridge\I18n::t('Next')=>$delta['next']] as $label=>$page){if($page!==null){$html.='<form method="post">'.$hidden(['manual_direction'=>$direction,'manual_offset'=>$page]).'<button class="button btn btn-default" name="bridge_action" value="manual_orders_scan">'.self::e($label).'</button></form>';}}
        return $html;
    }
}
