<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';

final class CustomFieldsAdmin
{
    public static function submitted(array $rows): array
    {
        $rules=[];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \RuntimeException(\WD29\Bridge\I18n::t('Invalid mapping row.')); }
            if (trim($row['id']??'')==='' && trim($row['key']??'')==='') { continue; }
            $rules[]=['id'=>trim($row['id']??''),'source'=>$row['source']??'meta','key'=>trim($row['key']??''),'entities'=>array_values($row['entities']??[])];
        }
        return CustomFields::rules($rules);
    }
    public static function render(): void
    {
        echo \WD29\Bridge\I18n::t('<h2>Custom fields</h2><p>Select only the business fields to synchronize. ACF definitions must exist on this store. Native prices, stock, identifiers and consent fields are excluded. On PrestaShop, product values are stored privately; no KerAwen field is created.</p><form method="post">');
        wp_nonce_field('wd29_bridge_admin');
        echo \WD29\Bridge\I18n::t('<table class="widefat" id="wd29-field-rows"><thead><tr><th>Exchange name</th><th>Source</th><th>Local metadata or ACF key</th><th>Applies to</th><th></th></tr></thead><tbody>');
        $rules=(array)get_option('wd29_bridge_custom_fields',[]); $rules[]=['id'=>'','source'=>'meta','key'=>'','entities'=>['product','variation']];
        foreach ($rules as $index=>$rule) { self::row($index,$rule); }
        echo \WD29\Bridge\I18n::t('</tbody></table><p><button type="button" class="button" id="wd29-add-field">Add field</button> <button class="button button-primary" name="bridge_action" value="save_field_rows">Save fields</button></p></form>');
        echo '<script>(function(){const table=document.getElementById("wd29-field-rows"),body=table.querySelector("tbody");let next=body.rows.length;table.addEventListener("click",function(e){if(e.target.matches(".wd29-remove-field")){e.target.closest("tr").remove();}});document.getElementById("wd29-add-field").addEventListener("click",function(){const row=document.createElement("tr");row.innerHTML=document.getElementById("wd29-field-template").innerHTML.replaceAll("__INDEX__",String(next++));body.append(row);});})();</script><template id="wd29-field-template">';
        self::cells('__INDEX__',['id'=>'','source'=>'meta','key'=>'','entities'=>['product','variation']]); echo '</template>';
    }
    private static function row($index,array $rule): void { echo '<tr>'; self::cells($index,$rule); echo '</tr>'; }
    private static function cells($index,array $rule): void
    {
        $prefix='field_rows['.$index.']';
        echo \WD29\Bridge\I18n::t('<td><input aria-label="Exchange name" name="').esc_attr($prefix.'[id]').'" value="'.esc_attr($rule['id']).\WD29\Bridge\I18n::t('"></td><td><select aria-label="Field source" name="').esc_attr($prefix.'[source]').'">';
        foreach (['meta'=>\WD29\Bridge\I18n::t('Woo metadata'),'acf'=>'ACF'] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($rule['source'],$value,false).'>'.esc_html($label).'</option>'; }
        echo \WD29\Bridge\I18n::t('</select></td><td><input aria-label="Local key" name="').esc_attr($prefix.'[key]').'" value="'.esc_attr($rule['key']).'"></td><td>';
        foreach (['product','variation','order','customer'] as $entity) { echo '<label style="display:inline-block;margin-right:10px"><input type="checkbox" name="'.esc_attr($prefix.'[entities][]').'" value="'.esc_attr($entity).'" '.checked(in_array($entity,$rule['entities']??['product','variation'],true),true,false).'>'.esc_html(['product'=>\WD29\Bridge\I18n::t('Product'),'variation'=>\WD29\Bridge\I18n::t('Variation'),'order'=>\WD29\Bridge\I18n::t('Order'),'customer'=>\WD29\Bridge\I18n::t('Customer')][$entity]).'</label>'; }
        echo '</td><td><button type="button" class="button wd29-remove-field">Retirer</button></td>';
    }
}
