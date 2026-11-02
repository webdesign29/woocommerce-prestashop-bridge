<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';

/** Displays the authoritative ledger without creating a second spendable balance. */
final class KerawenAccount
{
    public static function render(): void
    {
        $e=\wd29_bridge();
        if (empty($e->config()['kerawen_accounts']) || !$e->enabled() || !is_user_logged_in()) { return; }
        $key=OrderCustomers::source($e,(int)get_current_user_id());
        if (!$key) { return; }
        echo '<section class="wd29-kerawen-account"><h2>'.esc_html__(\WD29\Bridge\I18n::t('KerAwen loyalty and benefits'),'wd29-bridge').'</h2>';
        try {
            $data=$e->peer(['op'=>'commerce_account','customer_key'=>$key]);
            if (($data['authority']??'')!=='kerawen' || ($data['customer_key']??'')!==$key || !is_array($data['rules']??null)) { throw new \RuntimeException('Authoritative account response unavailable.'); }
            echo '<p>'.esc_html__(\WD29\Bridge\I18n::t('Information read from KerAwen in real time.'),'wd29-bridge').'</p>';
            if (isset($data['loyalty']['points']) && is_numeric($data['loyalty']['points'])) {
                echo '<p><strong>'.esc_html__(\WD29\Bridge\I18n::t('Loyalty points:'),'wd29-bridge').' '.esc_html((string)$data['loyalty']['points']).'</strong></p>';
            }
            $labels=['gift_card'=>__(\WD29\Bridge\I18n::t('Gift card'),'wd29-bridge'),'store_credit'=>__(\WD29\Bridge\I18n::t('Credit note'),'wd29-bridge'),'loyalty_voucher'=>__(\WD29\Bridge\I18n::t('Loyalty voucher'),'wd29-bridge'),'coupon'=>__(\WD29\Bridge\I18n::t('Discount voucher'),'wd29-bridge')];
            $rows=array_values(array_filter($data['rules'],static function($row){return is_array($row) && !empty($row['available']);}));
            if ($rows) {
                echo '<table class="shop_table"><thead><tr>';
                foreach ([\WD29\Bridge\I18n::t('Benefit'),'Code',\WD29\Bridge\I18n::t('Value'),\WD29\Bridge\I18n::t('Valid until')] as $label) { echo '<th>'.esc_html($label).'</th>'; }
                echo '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $label=$labels[$row['kind']??'']??__(\WD29\Bridge\I18n::t('Discount voucher'),'wd29-bridge');
                    $value=(float)($row['reduction_percent']??0)>0?((string)$row['reduction_percent'].' %'):((string)($row['reduction_amount']??'').' '.(string)($row['currency']??''));
                    echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)($row['code']??'')).'</td><td>'.esc_html($value).'</td><td>'.esc_html((string)($row['date_to']??'')).'</td></tr>';
                }
                echo '</tbody></table><p>'.esc_html__(\WD29\Bridge\I18n::t('The terms of each benefit are checked when it is redeemed in KerAwen.'),'wd29-bridge').'</p>';
            }
        } catch (\Throwable $error) {
            echo '<p>'.esc_html__(\WD29\Bridge\I18n::t('KerAwen information is temporarily unavailable. Please try again later.'),'wd29-bridge').'</p>';
        }
        echo '</section>';
    }
}
