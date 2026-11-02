<?php
namespace WD29\Bridge;
require_once __DIR__ . '/I18n.php';

/** Read-only labels for the administration being viewed and record ownership. */
final class AdminContext
{
    public static function name(string $side): string { return $side === 'woo' ? 'WordPress / WooCommerce' : 'PrestaShop'; }
    private static function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    public static function stores(Engine $engine, string $local): string
    {
        $remote = $local === 'woo' ? 'ps' : 'woo';
        $urls = [$local => $engine->adapter->siteUrl(), $remote => $engine->config()['peer'] ?? ''];
        $html = \WD29\Bridge\I18n::t('<div class="wd-store-context" aria-label="Stores in this connection">');
        foreach (['woo', 'ps'] as $side) {
            $host = parse_url($urls[$side], PHP_URL_HOST) ?: \WD29\Bridge\I18n::t('Partner store not configured');
            $html .= '<div class="wd-store wd-side-'.$side.'" data-store-platform="'.$side.'" data-store-local="'.($side === $local ? 'true' : 'false').'"><span class="wd-store-role">'.($side === $local ? \WD29\Bridge\I18n::t('You are here · this admin') : \WD29\Bridge\I18n::t('Configured partner store')).'</span><strong>'.self::name($side).'</strong><span class="wd-store-host">'.self::e($host).'</span></div>';
        }
        return $html.'</div>';
    }
    public static function table(\DOMElement $table, string $section, string $local): void
    {
        $doc = $table->ownerDocument; $remote = $local === 'woo' ? 'ps' : 'woo';
        $localName = self::name($local); $remoteName = self::name($remote);
        $heads = iterator_to_array($table->getElementsByTagName('th')); $identity = null; $localId = null; $direction = null;
        foreach ($heads as $i => $th) {
            $name = trim($th->textContent);
            if (in_array($name, [\WD29\Bridge\I18n::t('Origin'),\WD29\Bridge\I18n::t('Identity'),\WD29\Bridge\I18n::t('Source customer'),\WD29\Bridge\I18n::t('Order')], true)) { $identity = $i; }
            if ($name === \WD29\Bridge\I18n::t('Origin')) { $th->textContent = \WD29\Bridge\I18n::t('Record creation'); }
            if ($name === \WD29\Bridge\I18n::t('Local ID')) { $localId = $i; $th->textContent = \WD29\Bridge\I18n::t('Record on ').$localName; }
            if ($name === \WD29\Bridge\I18n::t('Local account (ID)')) { $th->textContent = \WD29\Bridge\I18n::t('Account on ').$localName.' (ID)'; }
            if ($name === \WD29\Bridge\I18n::t('Direction')) { $direction = $i; $th->textContent = \WD29\Bridge\I18n::t('From → to'); }
            if ($name === \WD29\Bridge\I18n::t('Local total / status / lines')) { $th->textContent = \WD29\Bridge\I18n::t('Copy on ').$localName; }
            if ($name === \WD29\Bridge\I18n::t('Received total / status / lines')) { $th->textContent = \WD29\Bridge\I18n::t('Version received from ').$remoteName; }
        }
        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = iterator_to_array($row->getElementsByTagName('td')); if (!$cells) { continue; }
            if ($direction !== null && isset($cells[$direction])) {
                $value = trim($cells[$direction]->textContent);
                if (in_array($value, ['in','out'], true)) { $cells[$direction]->textContent = $value === 'in' ? $remoteName.' → '.$localName : $localName.' → '.$remoteName; }
            }
            if ($identity === null || !isset($cells[$identity])) { continue; }
            $key = trim($cells[$identity]->textContent);
            if (!preg_match('/^(woo|ps):(product|variant|order|customer|guest):([0-9]+)$/D', $key, $match)) { continue; }
            $origin = $match[1]; $own = $origin === $local;
            $row->setAttribute('data-wd-origin', $own ? 'original' : 'imported');
            $cell = $cells[$identity]; $cell->textContent = '';
            $badge = $doc->createElement('span'); $badge->setAttribute('class', 'wd-origin wd-side-'.$origin); $badge->textContent = \WD29\Bridge\I18n::t('Created on ').self::name($origin); $cell->appendChild($badge);
            $code = $doc->createElement('small'); $code->setAttribute('class', 'wd-record-key'); $code->textContent = $key; $cell->appendChild($code);
            if ($localId !== null && isset($cells[$localId])) {
                $id = trim($cells[$localId]->textContent);
                $cells[$localId]->textContent = ctype_digit($id) ? ($own ? 'Original' : \WD29\Bridge\I18n::t('Imported copy')).' · #'.$id : \WD29\Bridge\I18n::t('Not yet applied on ').$localName;
            }
        }
        if (!in_array($section, ['Catalogue',\WD29\Bridge\I18n::t('Orders'),\WD29\Bridge\I18n::t('Customer contacts')], true)) { return; }
        $table->setAttribute('data-wd-origin-table', 'true');
        $label = $doc->createElement('label'); $label->setAttribute('class', 'wd-origin-filter'); $label->appendChild($doc->createTextNode(\WD29\Bridge\I18n::t('Show on ').$localName.' : '));
        $select = $doc->createElement('select'); $select->setAttribute('data-wd-origin-filter', 'true');
        foreach (['all'=>\WD29\Bridge\I18n::t('All tracked records'),'original'=>\WD29\Bridge\I18n::t('Originals created on ').$localName,'imported'=>\WD29\Bridge\I18n::t('Copies from ').$remoteName] as $value=>$text) { $option=$doc->createElement('option');$option->setAttribute('value',$value);$option->textContent=$text;$select->appendChild($option); }
        $label->appendChild($select); $table->parentNode->insertBefore($label,$table);
    }
}
