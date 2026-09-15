<?php
namespace WD29\Bridge;

final class CustomFieldsAdmin
{
    public static function submitted(array $rows): array
    {
        $rules=[];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \RuntimeException('Invalid mapping row.'); }
            if (trim($row['id']??'')==='' && trim($row['key']??'')==='') { continue; }
            $rules[]=['id'=>trim($row['id']??''),'source'=>$row['source']??'meta','key'=>trim($row['key']??''),'entities'=>array_values($row['entities']??[])];
        }
        return CustomFields::rules($rules);
    }
    public static function render(): void
    {
        echo '<h2>Custom field mappings</h2><p>Select only business fields to synchronize. ACF definitions must exist locally. Native prices, stock, credentials and consent fields are excluded. Product values are retained privately in PrestaShop; this does not create KerAwen fields.</p><form method="post">';
        wp_nonce_field('wd29_bridge_admin');
        echo '<table class="widefat" id="wd29-field-rows"><thead><tr><th>Transport name</th><th>Source</th><th>Local metadata / ACF key</th><th>Applies to</th><th></th></tr></thead><tbody>';
        $rules=(array)get_option('wd29_bridge_custom_fields',[]); $rules[]=['id'=>'','source'=>'meta','key'=>'','entities'=>['product','variation']];
        foreach ($rules as $index=>$rule) { self::row($index,$rule); }
        echo '</tbody></table><p><button type="button" class="button" id="wd29-add-field">Add field</button> <button class="button button-primary" name="bridge_action" value="save_field_rows">Save custom field mappings</button></p></form>';
        echo '<script>(function(){const table=document.getElementById("wd29-field-rows"),body=table.querySelector("tbody");let next=body.rows.length;table.addEventListener("click",function(e){if(e.target.matches(".wd29-remove-field")){e.target.closest("tr").remove();}});document.getElementById("wd29-add-field").addEventListener("click",function(){const row=document.createElement("tr");row.innerHTML=document.getElementById("wd29-field-template").innerHTML.replaceAll("__INDEX__",String(next++));body.append(row);});})();</script><template id="wd29-field-template">';
        self::cells('__INDEX__',['id'=>'','source'=>'meta','key'=>'','entities'=>['product','variation']]); echo '</template>';
    }
    private static function row($index,array $rule): void { echo '<tr>'; self::cells($index,$rule); echo '</tr>'; }
    private static function cells($index,array $rule): void
    {
        $prefix='field_rows['.$index.']';
        echo '<td><input aria-label="Transport name" name="'.esc_attr($prefix.'[id]').'" value="'.esc_attr($rule['id']).'"></td><td><select aria-label="Field source" name="'.esc_attr($prefix.'[source]').'">';
        foreach (['meta'=>'Woo custom metadata','acf'=>'ACF'] as $value=>$label) { echo '<option value="'.$value.'" '.selected($rule['source'],$value,false).'>'.$label.'</option>'; }
        echo '</select></td><td><input aria-label="Local field key" name="'.esc_attr($prefix.'[key]').'" value="'.esc_attr($rule['key']).'"></td><td>';
        foreach (['product','variation','order','customer'] as $entity) { echo '<label style="display:inline-block;margin-right:10px"><input type="checkbox" name="'.esc_attr($prefix.'[entities][]').'" value="'.$entity.'" '.checked(in_array($entity,$rule['entities']??['product','variation'],true),true,false).'>'.esc_html(ucfirst($entity)).'</label>'; }
        echo '</td><td><button type="button" class="button wd29-remove-field">Remove</button></td>';
    }
}
