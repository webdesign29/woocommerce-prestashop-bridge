<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';

/** One-record editor workspace. Rendering is local; all remote reads and writes are explicit. */
final class RecordPanel
{
    public static function validKind(string $kind): void
    {
        if (!in_array($kind, ['product','order','customer'], true)) { throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid record type.')); }
    }

    public static function local(Engine $engine, string $kind, int $id): array
    {
        self::validKind($kind);
        $side = $engine->adapter->site(); $peerSide = $side === 'woo' ? 'ps' : 'woo';
        $out = ['state'=>'unavailable','kind'=>$kind,'key'=>'','native_id'=>$id,'origin'=>true,'direction'=>'out','source'=>$side,'target'=>$peerSide,'peer_host'=>'','can_sync'=>false,'reason'=>\WD29\Bridge\I18n::t('This record cannot be synchronized.'),'changes'=>[],'pending_count'=>0,'last_event'=>null];
        try {
            if ($id < 1) { return $out; }
            $key = $engine->adapter->recordPanelIdentity($kind, $id);
            $pattern = $kind === 'customer' ? '(?:customer|guest)' : $kind;
            if (!preg_match('/^(woo|ps):'.$pattern.':[1-9][0-9]{0,14}$/D', $key)) { return $out; }
            $out['key'] = $key; $out['origin'] = strpos($key, $side.':') === 0;
            $out['direction'] = $out['origin'] ? 'out' : 'in';
            $out['source'] = $out['origin'] ? $side : $peerSide; $out['target'] = $out['origin'] ? $peerSide : $side;
            $config = $engine->config(); $peer = (string)($config['peer'] ?? '');
            try { Protocol::publicEndpoint($peer); }
            catch (\Throwable $e) { $out['state']='disconnected'; $out['reason']=\WD29\Bridge\I18n::t('No partner store connected.'); return $out; }
            $out['peer_host'] = (string)parse_url($peer, PHP_URL_HOST);
            if (strlen((string)($config['secret'] ?? '')) < 32) { $out['state']='disconnected'; $out['reason']=\WD29\Bridge\I18n::t('Partner connection is incomplete.'); return $out; }
            $out = array_replace($out, self::activity($engine, $key));
            $out['state']='ready'; $out['reason']=\WD29\Bridge\I18n::t('Compare saved data before synchronizing.');
        } catch (\Throwable $e) { /* Ineligible records never expose native errors or identities. */ }
        return $out;
    }

    /** Resolve a canonical identity to this shop's native editor ID, never a private contact ID. */
    public static function nativeId(Engine $engine,string $kind,string $key): int
    {
        self::validKind($kind);$pattern=$kind==='customer'?'(?:customer|guest)':$kind;
        if(!preg_match('/^(woo|ps):'.$pattern.':([1-9][0-9]{0,14})$/D',$key,$m))return 0;
        if($m[1]===$engine->adapter->site())$id=(int)$m[2];
        elseif($kind==='customer')$id=(int)($engine->sql('SELECT native_id FROM {b}account_links WHERE record_key=? LIMIT 1',[$key])[0]['native_id']??0);
        else $id=(int)($engine->sql('SELECT local_id FROM {b}map WHERE record_key=? AND kind=? LIMIT 1',[$key,$kind])[0]['local_id']??0);
        if($id<1)return 0;
        try{return $engine->adapter->recordPanelIdentity($kind,$id)===$key?$id:0;}catch(\Throwable $e){return 0;}
    }

    private static function activity(Engine $engine, string $key): array
    {
        $counts=$engine->sql("SELECT state,COUNT(*) total FROM {b}queue WHERE record_key=? AND state IN ('pending','audit','failed','conflict') GROUP BY state",[$key]);
        $pending=0;$blocked=0;
        foreach($counts as $row){if(in_array($row['state'],['failed','conflict'],true))$blocked+=(int)$row['total'];else $pending+=(int)$row['total'];}
        $last=$engine->sql('SELECT direction,kind,state,created_at FROM {b}queue WHERE record_key=? ORDER BY seq DESC LIMIT 1',[$key])[0]??null;
        return ['pending_count'=>$pending,'blocked_count'=>$blocked,'last_event'=>$last];
    }

    /** Signed peer operation, one original descriptor only, never an export or scan. */
    public static function source(Engine $engine, string $kind, string $key): array
    {
        self::validKind($kind);
        $pattern=$kind==='customer'?'(?:customer|guest)':$kind;
        if(!preg_match('/^'.preg_quote($engine->adapter->site(),'/').':'.$pattern.':[1-9][0-9]{0,14}$/D',$key))throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid source identity.'));
        $result=$engine->manualRecordSource($kind,$key);
        $result['activity']=self::activity($engine,$key);
        $result['admin_url']=method_exists($engine->adapter,'recordPanelAdminUrl')?$engine->adapter->recordPanelAdminUrl($kind,$key):'';
        return $result;
    }

    /** Destination-side read, shared by local and remote editor comparisons. */
    public static function target(Engine $engine, string $kind, array $rows): array
    {
        self::validKind($kind);
        if(count($rows)!==1 || !is_array($rows[0]) || !is_string($rows[0]['key']??null)){throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Exactly one record is required.'));}
        if(!is_string($rows[0]['hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$rows[0]['hash'])||!is_array($rows[0]['fields']??null))throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid descriptor.'));
        foreach($rows[0]['fields'] as $value)if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid descriptor.'));
        if(isset($rows[0]['variant_review'])&&(!is_array($rows[0]['variant_review'])||!is_array($rows[0]['variant_review']['rows']??null)||count($rows[0]['variant_review']['rows'])>200))throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid variation preview.'));
        $result=$engine->manualRecordCompare($kind,$rows);
        $result['writes_allowed']=$engine->licence()->allowsLive();
        $result['activity']=self::activity($engine,$rows[0]['key']);
        $result['admin_url']=method_exists($engine->adapter,'recordPanelAdminUrl')?$engine->adapter->recordPanelAdminUrl($kind,$rows[0]['key']):'';
        return $result;
    }

    public static function compare(Engine $engine, string $kind, int $id): array
    {
        $out=self::local($engine,$kind,$id);
        if($out['state']!=='ready')return $out;
        try{
            $source=$out['origin']?self::source($engine,$kind,$out['key']):$engine->peer(['op'=>'record_panel_source','kind'=>$kind,'key'=>$out['key']],6);
            $row=$source['row']??null;
            if(!is_array($row)||($row['key']??'')!==$out['key']||!is_string($row['hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['hash']))throw new \RuntimeException(\WD29\Bridge\I18n::t('Source unavailable.'));
            $target=$out['origin']?$engine->peer(['op'=>'record_panel_compare','kind'=>$kind,'rows'=>[$row]],6):self::target($engine,$kind,[$row]);
            $comparison=$target['rows'][0]??null;
            if(!is_array($comparison)||($comparison['key']??'')!==$out['key']||!in_array($comparison['state']??'', ['same','missing','changed','conflict'],true)||!is_string($comparison['destination']??null)||($comparison['destination']!==''&&!preg_match('/^[a-f0-9]{64}$/D',$comparison['destination'])))throw new \RuntimeException('Comparaison indisponible.');
            $out=array_replace($out,array_intersect_key($comparison,array_flip(['state','changes','destination','local_id','destination_summary','stock_difference','variation_changes','native_account','blocker','review_reason'])));
            $remoteUrl=$out['origin']?($target['admin_url']??''):($source['admin_url']??'');
            $out['remote_admin_url']='';
            if(is_string($remoteUrl)&&$remoteUrl!=='') { try{$out['remote_admin_url']=ProductLinks::safeUrl($remoteUrl,(string)$engine->config()['peer']);}catch(\Throwable $ignored){} }
            $out['hash']=$row['hash'];$out['source_summary']=$row['summary']??null;
            if($kind==='order'&&is_array($out['destination_summary']??null)&&!isset($out['destination_summary']['label'])){
                $d=$out['destination_summary'];$out['destination_summary']=['label'=>\WD29\Bridge\I18n::t('Order #').(string)($d['number']??''),'detail'=>(string)($d['total']??'').' '.(string)($d['currency']??''),'status'=>(string)($d['status_label']??$d['status']??'')];
            }
            $out['source_mode']=$source['mode']??'unknown';$out['destination_mode']=$target['mode']??'unknown';
            $out['remote_activity']=$out['origin']?($target['activity']??null):($source['activity']??null);
            $out['can_sync']=in_array($out['state'],['missing','changed'],true)&&!empty($target['writes_allowed']);
            $reasons=['same'=>\WD29\Bridge\I18n::t('Synchronized data is up to date.'),'missing'=>\WD29\Bridge\I18n::t('This record can be created on the destination store.'),'changed'=>\WD29\Bridge\I18n::t('Saved changes need synchronization.'),'conflict'=>\WD29\Bridge\I18n::t('The copy changed or a conflict exists. Check reports.')];
            $out['reason']=$reasons[$out['state']];
            if(($out['blocker']??'')==='variation_removal')$out['reason']=\WD29\Bridge\I18n::t('Variations were removed from the source. Check their removal in the destination admin before synchronizing.');
            if(empty($target['writes_allowed'])&&in_array($out['state'],['missing','changed'],true))$out['reason']=\WD29\Bridge\I18n::t('The destination store requires permission to synchronize.');
            if($kind==='product'){
                $out['variation_changes']=$comparison['variation_changes']??['total'=>0,'rows'=>[]];
                $out['stock_difference']=!empty($comparison['stock_difference']);
                $out['stock_note']=\WD29\Bridge\I18n::t('Saved variations synchronize with their product. Existing quantities follow stock events and are not forced by this action.');
            }
            if($kind==='customer'){
                $out['native_account']=$comparison['native_account']??['state'=>'directory_only','native_id'=>0];
                $out['customer_note']=\WD29\Bridge\I18n::t('The status compares the Sync contact directory. Native customer accounts are only updated if that option is enabled at the destination.');
            }
        }catch(\Throwable $e){$out['state']='unavailable';$out['can_sync']=false;$out['reason']=\WD29\Bridge\I18n::t('Comparison unavailable. Check the connection, update both connectors, then try again.');}
        return $out;
    }

    public static function sync(Engine $engine, string $kind, int $id, array $input): array
    {
        $context=self::local($engine,$kind,$id);
        if($context['state']!=='ready'||($input['confirm']??'')!=='1')throw new \RuntimeException(\WD29\Bridge\I18n::t('Confirm synchronization of this record.'));
        foreach(['key','direction','hash','destination'] as $field)if(!is_string($input[$field]??null))throw new \InvalidArgumentException(\WD29\Bridge\I18n::t('Invalid comparison.'));
        if($input['key']!==$context['key']||$input['direction']!==$context['direction']||!preg_match('/^[a-f0-9]{64}$/D',$input['hash'])||($input['destination']!==''&&!preg_match('/^[a-f0-9]{64}$/D',$input['destination'])))throw new \RuntimeException(\WD29\Bridge\I18n::t('The mapping changed. Refresh the comparison.'));
        // Re-read both sides after confirmation: never replace a newer review with a fresh silent snapshot.
        $review=self::compare($engine,$kind,$id);
        if(($review['hash']??'')!==$input['hash']||($review['destination']??'')!==$input['destination'])throw new \RuntimeException(\WD29\Bridge\I18n::t('The data changed. Refresh and confirm again.'));
        if($review['state']==='same')return ['ok'=>true,'state'=>'same','message'=>\WD29\Bridge\I18n::t('This record is already up to date.')];
        if(empty($review['can_sync']))throw new \RuntimeException(\WD29\Bridge\I18n::t('Synchronization unavailable: check status and reports.'));
        $result=$engine->manualRecordSync($kind,$context['direction'],$context['key'],$input['hash'],$input['destination']);
        $result['message']=in_array($result['ack']??'', ['pending','source_changed'],true)?\WD29\Bridge\I18n::t('Record applied; check confirmation or new source changes.'):\WD29\Bridge\I18n::t('Record synchronized. Refresh the comparison.');
        return $result;
    }
}
