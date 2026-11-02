<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';
final class DiagnosticsAdmin
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
    public static function render(Engine $engine,string $view='all'): string
    {
        $html='';
        if(in_array($view,['all','overview','activity'],true)){
        $d=$engine->diagnostics();
        $html='<h2>Diagnostics</h2><p>'.($d['ok']?\WD29\Bridge\I18n::t('Nothing needs attention.'):\WD29\Bridge\I18n::t('Action required: see the items below.')).'</p><table class="widefat table"><tr><th>Code</th><th>Fiche / sens</th><th>Que faire</th></tr>';
        foreach ($d['issues'] as $row) { $html.='<tr><td>'.self::e($row['code']).'</td><td>'.self::e($row['record']??$row['direction']??'worker').'</td><td>'.self::e($row['action']).'</td></tr>'; }
        $html.=\WD29\Bridge\I18n::t('</table><p>Run the supplied worker every minute. The health command exit code can be used by hosting monitoring. Stock synchronizes asynchronously: two simultaneous sales of the last item require a shared reservation service.</p>');
        }
        if(in_array($view,['all','activity'],true)){
        $html.=\WD29\Bridge\I18n::t('<h2>Order conflicts</h2><p>Retry equivalent order updates only accepts new empty fields or line identities when native values still match. Other differences remain paused.</p><table class="widefat table"><tr><th>Event</th><th>Order</th><th>Local total / status / lines</th><th>Received total / status / lines</th><th>Resolution</th></tr>');
        foreach ($engine->orderConflictReport() as $row) { $html.='<tr>'; foreach ($row as $value) { $html.='<td>'.self::e($value).'</td>'; } $html.='</tr>'; }
        $html.='</table>';
        }
        if(in_array($view,['all','reports'],true)){
        $html.=\WD29\Bridge\I18n::t('<h2>Refunds and credit notes</h2><p>Read-only copies from the source store. No second payment, restocking or fiscal document is created.</p><table class="widefat table"><tr><th>Order</th><th>Refund</th><th>Amount</th><th>Currency</th></tr>');
        foreach ($engine->sql("SELECT record_key,local_id FROM {b}map WHERE kind='order' ORDER BY record_key LIMIT 200") as $row) {
            try { $order=$engine->adapter->order((int)$row['local_id']); }
            catch (\Throwable $e) { continue; }
            foreach ($order['refunds']??[] as $refund) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($refund['source_id']??'').'</td><td>'.self::e($refund['amount']??\WD29\Bridge\I18n::t('Historical tax basis unknown')).'</td><td>'.self::e($order['currency']).'</td></tr>'; }
        }
        $html.=\WD29\Bridge\I18n::t('</table><h2>Linked customer accounts</h2><p>Optional native accounts have their own password. An email address already in use requires manual review. Guests, passwords and marketing consent are never copied.</p><table class="widefat table"><tr><th>Source customer</th><th>Local account (ID)</th></tr>');
        foreach ($engine->sql('SELECT record_key,native_id FROM {b}account_links ORDER BY record_key LIMIT 200') as $row) { $html.='<tr><td>'.self::e($row['record_key']).'</td><td>'.self::e($row['native_id']).'</td></tr>'; }
        $html.='</table>';
        }
        return $html;
    }
}
