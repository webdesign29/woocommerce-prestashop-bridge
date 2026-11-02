## 0.8.13 — 2026-11-02

- English-default WooCommerce administration with French translations.
- Clarify display limits separately from Marketplace catalogue capacity.
- Coordinated companion version; PrestaShop runtime is unchanged.

# 0.8.12 — 2026-10-21

- Coordinated direct release with PrestaShop 0.8.12. Includes Shopify catalogue, customers/default address, inventory and initial-photo exchange; optional promotions/account consultation retain their explicit permissions and configuration requirements.
- Create and update ordinary Shopify discounts without subscription-only input flags rejected by stores that do not use subscriptions. Keep one-time-only eligibility verification.
- Ordinary Shopify discounts omit subscription-only input fields, so stores without subscriptions accept creation and updates; source eligibility checks remain mandatory.
- Existing connection settings, history and default-off features are preserved. Marketplace distribution remains separate.

# 0.8.11 — 2026-10-21

- Match ready created media to the product-image view by exact CDN URL before recording native attachment references. Shopify MediaImage.image returns an ImageSource ID, distinct from the ProductImage ID used by catalogue imports.

# 0.8.10 — 2026-10-21

- Defer source notifications for app-owned, unfinished product/customer exports until the explicit export binds the original native record. This covers webhook arrival during image processing and lost creation responses without duplicate native imports or SKU/email collisions.

# 0.8.9 — 2026-10-21

- Optionally copy up to 20 public native JPEG/PNG/WebP product images on initial Woo-to-Shopify creation, with source snapshots and deterministic names. Keep existing mapped Shopify galleries unchanged.
- Recover lost product creation responses by unique source identity, wait for file processing, and require review on failed/ambiguous media or changed source snapshots. Reuse original native attachments on the following Shopify image import.

# 0.8.8 — 2026-10-21

- Link the independent Shopify connection from the main Sync navigation and plugin actions, with a return link and public setup guide.
- Align installation, service disclosure and KerAwen/Shopify scope documentation with the current connector. Keep the public stable pair distinct from the demo candidate.
- Translate the Marketplace package description regardless of changes to the direct-edition header.

# 0.8.7 — 2026-10-20

- Add optional source fulfillment/tracking, refund and transaction history to native Shopify consultation orders, without creating a second payment, refund or stock movement. Preserve source record IDs and stop on disappearing or locally modified history.
- Preserve physical/virtual shipping requirements for new imports/exports and add optional mapped flag reconciliation with independent baselines. Non-downloadable virtual services can be exported.
- Reject Shopify gift-card, subscription-only and component-bundle products rather than converting their financial or fulfillment behavior. When Commerce Pro gift-card features are enabled, verify native product flags with the KerAwen authority before new Woo exports.

# 0.8.6 — 2026-10-20

- Add optional ordinary coupon synchronization and explicit/opt-in creation: unlimited single-code, all-customer/all-product, non-stacking percent/fixed-cart/fixed-item promotions. Preserve code identity and recover ambiguous creates using a persisted random provenance marker.
- Suspend mapped copies when source rules become incompatible, scheduled or deleted. Deactivate a linked Shopify discount after an unsupported native restriction change; retain the proven baseline for safe repair. Never independently clone shared quotas, KerAwen-origin entitlements, gift cards, credits or loyalty balances.
- Add an optional signed Shopify app-proxy account page backed by the existing Commerce Pro/KerAwen account reader. Verify raw-query HMAC, shop, timestamp, configured path and exact native customer ownership before any authority read. Return no-store HTML; no spending, balance copy or consent inference.

# 0.8.5 — 2026-10-20

- Treat missing tag metadata as an empty baseline rather than a one-element array. Cover initial native tags on an exported variable product.
- Defer media writes from workers without access to the native upload directory, allowing the native HTTP worker to retry.

# 0.8.4 — 2026-10-20

- Seed full address baselines for unchanged pre-0.8.3 customers before their next native edits. Changed legacy profiles still require review.

# 0.8.3 — 2026-10-20

- Export simple and variable Woo products with exact option-tuple binding, per-variant stock journals and recovery after lost create responses. Reject ambiguous wildcard or disabled variations.
- Add configurable customer default-address updates and creation; include recipient names in address baselines without silently upgrading old edits.
- Add optional two-way product tags, additive Shopify CDN image imports and collection-to-category imports. Preserve existing native media/categories; isolate new feature baselines from existing core mappings.

# 0.8.2 — 2026-10-20

- Request only supported ProductUpdate UserError fields. Preserve native taxable/exempt flags on export and import, and reconcile flag-only changes separately from catalogue prices.

## 0.8.1 — 2026-10-20

- Use Shopify 2026-10 `changeFromQuantity` and `CHANGE_FROM_QUANTITY_STALE` for inventory comparisons, verified against the live store schema. Preserve pending intent keys and original counts when retrying.

## 0.8.0 — 2026-10-20

- Add an independent Shopify GraphQL connection, durable webhook queue, audit/live modes, native WooCommerce catalogue/customer imports and mapped updates in both directions.
- Add unique-ID creation of simple Woo products and customers on Shopify, explicit or opt-in automatic. Exported products remain drafts.
- Reconcile one inventory location with comparison checks, persisted idempotency keys, bounded retries and visible conflicts.
- Import Shopify orders as native consultation copies with linked lines, native tax checks and no second charge, email, stock deduction or KerAwen financial enrollment.
- Preserve the existing WooCommerce/PrestaShop pairing. Shopify financial copies are not forwarded as new Woo sales. See docs/SHOPIFY.md for supported fields and limits.

## 0.7.11 — 2026-10-20

- Route signed optional Commerce Pro operations and source order proofs through the existing authenticated pairing.

## 0.7.10

- Les clients inscrits d’origine PrestaShop restent dans les captures et comparaisons après un achat WooCommerce ; seuls les invités créés pour un miroir et les comptes importés sont exclus.
- Régression exécutant les requêtes de l’adaptateur réel : clients inscrits, désactivés, supprimés, invités, comptes liés, achats natifs/miroirs et pagination.

## 0.7.9

- Les archives utilisent la date du commit de publication (ou `SOURCE_DATE_EPOCH`) ; la mise à niveau PrestaShop invalide explicitement l’OPcache des fichiers du module afin de ne pas conserver d’anciens fichiers inclus après import.
- La protection des modifications locales du compte client s’applique aussi aux échanges automatiques : une identité, un rôle ou un profil local modifié reste en conflit pour examen.
- Les désactivations et réactivations de comptes sont transmises ; une désactivation PrestaShop bloque également les authentifications WooCommerce du compte importé, sans changer son mot de passe ni son identité historique.
- Les commandes conservent l’identité canonique du client inscrit et utilisent son compte natif lié ; les clients copiés ne deviennent pas de nouvelles identités sur l’autre boutique. Les contacts d’origine sont capturés avant leurs commandes.
- Un changement natif du miroir met l’échange de commande en conflit visible, sans répéter des tentatives qui ne peuvent pas résoudre une décision comptable.
- Une mise à jour de commande vérifie les données natives du miroir avant de remplacer son instantané. Les changements d’identité et de données financières nécessitent un examen.
- Lecture authentifiée des points et des règles du compte depuis le registre KerAwen installé, sans créer de second solde dépensable.

## 0.7.5

- Un import de catalogue accepté remplace aussi les anciens instantanés sortants de la même fiche, afin qu’un ancien prix ne reparte pas après une reprise fiscale ou une décision de priorité. Les événements de stock, commandes et autres produits restent conservés.
- Import et capture de produit partagent le verrou de capture. Régressions dans les deux sens : priorité distante/locale, conflit à examiner, échec natif, doublon et rejeu.

## 0.7.4

- WooCommerce : la reprise des échanges fiscaux conserve le résultat natif de la base de données. Une suppression de taux de TVA ne renvoie plus une fausse erreur HTTP 500 quand la file de reprise ne modifie aucune ligne. Les erreurs du connecteur ne font pas échouer les opérations fiscales natives.
- Compagnon PrestaShop aligné sur 0.7.4 ; son comportement reste inchangé.
- Régression REST native : création, lecture, mise à jour et suppression de taux synthétiques, avec file vide ou en attente, modes désactivé, audit et live.

## 0.7.3

- Les imports de produits bloqués par les taxes WooCommerce désactivées, une classe de TVA absente ou des prix source sans information fiscale affichent une action précise dans le tableau de bord et la supervision. Les liens ouvrent les vrais réglages WooCommerce concernés.
- Les erreurs de configuration fiscale attendent la correction sans épuiser huit tentatives. La sauvegarde des options ou des taux de TVA WooCommerce remet uniquement les échanges fiscaux en attente ; les conflits et les autres échecs sont conservés. Une reprise horaire reste disponible si un changement échappe aux hooks.
- Les stocks entiers écrits par WooCommerce sous forme décimale (par exemple « 3.000000 ») sont acceptés. Les fractions et les valeurs ambiguës restent refusées.
- Tests de création simultanée : deux produits indépendants gardent leurs identités sur les deux boutiques, même avec le même identifiant numérique et sans référence. La priorité de catalogue ne fusionne pas ces produits. Réception signée, reprise fiscale, prix HT/TTC et rejeu validés dans WooCommerce natif.

## 0.7.2 (unreleased)

- Reference the global WordPress WP_Error class in signed webhook authorization so static security checks recognize its accepted return type; authentication and HTTP 401 responses are unchanged.

## 0.7.1 (Marketplace resubmission)

- Validate admin input centrally without silently normalizing malformed identifiers; preserve opaque signing keys and validate custom-field schemas.
- Escape custom-field option values at output and make signed webhook authorization an explicit named callback returning only true or WP_Error.
- Bind SQL values to complete prepared templates, preserving NULL and literal percent signs.
- Use scoped classes for connector editor notes so native Woo refund descriptions remain unambiguous.
- Release cURL handles without the PHP 8.5 deprecated curl_close call.

## 0.7.0 (Marketplace submission)

- Add an independently packaged Woo Marketplace edition and matching PrestaShop companion. The installed edition requires no additional Inklura key, never makes Inklura license requests, and keeps synchronization working when the Woo subscription expires. Woo manages updates and support.
- Preserve direct-sale licensing, keys, settings, mappings and history. An administrator must deactivate the other WooCommerce edition before switching; uninstalling is not required.
- Bundle the matching PrestaShop module and expose its download to authorized WordPress administrators with a nonce. Companion updates come from the current Woo connector package, avoiding a second update/billing channel.

## 0.6.7

- Actions requises : quand un réglage ou une décision bloque la synchronisation (taux de TVA à confirmer, boutique prioritaire à choisir en cas de modification simultanée, conflits ou échecs à relancer, traitement automatique arrêté), un bandeau l’indique en haut de toutes les pages du connecteur, avec le bouton qui règle le point en un clic et le lien vers la page concernée.
- Le même avertissement apparaît dans le reste de l’administration (toutes les pages du back-office PrestaShop, notices WordPress) avec un lien direct « Traiter maintenant ».
- Taux de TVA suggéré : le taux le plus utilisé du catalogue (ou la correspondance unique déjà configurée) est proposé ; il n’est jamais appliqué sans confirmation.
- Les échanges bloqués par un réglage manquant repartent dès que le réglage est enregistré, sans attendre leurs reprises ni les consommer.
- La boutique prioritaire choisie d’un côté est appliquée automatiquement à la boutique partenaire (échange signé), et les conflits de catalogue sont relancés des deux côtés.
- WordPress : l’enregistrement des réglages conserve désormais les autres valeurs de configuration.

## 0.6.6

- Éditeur affiché : « Inklura » au lieu de « Webdesign29 » (gestionnaire de modules PrestaShop, liste des extensions WordPress). Aucun changement fonctionnel.

## 0.6.5

- Le connecteur s’affiche sous le nom « Inklura Sync » dans la liste des extensions et le menu WooCommerce, au lieu de « WD29 … Bridge ». Le dossier technique, les réglages, les clés de licence et le protocole entre boutiques sont inchangés : aucune action requise après la mise à jour.

## 0.6.4

- Panneaux natifs dans les fiches produit, commande et client : état de synchronisation, comparaison à la demande, activité récente et actions individuelles confirmées, sans changer le mode automatique.
- Liens vers la fiche publique du produit et vers l’administration distante de la fiche correspondante ; connexion et droits natifs exigés, aucun jeton distant partagé.
- Aperçu des variations enregistrées (ajouts, modifications, retraits). Le produit et ses variations sont synchronisés ensemble ; les retraits à examiner bloquent l’action, les quantités existantes conservent leur suivi par événements.
- Protections des modifications locales des comptes clients facultatifs, y compris adresses et nouveaux champs ; distinction explicite entre répertoire Sync et compte client natif.
- Correction des prix de déclinaisons PrestaShop : aperçu des nouvelles déclinaisons sans correspondance préalable et invalidation du cache de prix avant le calcul de l’empreinte après synchronisation.
- Lectures différées, sans polling ni scan de catalogue ; index d’activité par fiche ajoutés aux installations existantes. Mettre les deux connecteurs à jour.

## 0.6.3

- Add signed, read-only inspection of one mapped product and its stock for the opt-in Commerce Pro product workspace; no catalogue scans or customer information.
- Preserve original/copy identities, conflict protections and stock movement queues.

# Inklura Sync 0.6.2

- Les pages d’administration ne chargent plus les rapports et instantanés natifs des autres pages. La vue d’ensemble ne parcourt plus les commandes et remboursements masqués.
- Chaque étape d’une synchronisation complète ne relit qu’une fiche source au lieu d’une page entière. Les comparaisons conservent leurs lots de 20, et les partenaires plus anciens restent compatibles.
- Pagination vérifiée avec les copies importées exclues, les pages vides et les conflits ; protections contre les comparaisons périmées et les doublons conservées.

# Inklura Sync 0.6.1

- Présentation native de chaque administration : onglets, boutons, tableaux et panneaux WordPress ; onglets, composants de formulaire et panneaux PrestaShop. Les styles et la typographie de la plateforme sont conservés.
- En-tête compact, suppression de la bannière décorative, identification des deux boutiques et pages de comparaison conservées.
- Confirmations simples et centrées, contrôles lisibles sur mobile. Aucun changement du protocole, des modes ou des données synchronisées.

# Inklura Sync 0.6.0

- Administration répartie en pages : vue d’ensemble, comparaison et synchronisation, activité, rapports, réglages, licence et mises à jour. Navigation et liens directs conservent les protections natives de chaque plateforme.
- Comparaison en lecture seule des produits, commandes et contacts dans les deux sens, avec WordPress à gauche et PrestaShop à droite, identification original/copie, écarts par famille et filtre des fiches identiques.
- Création ou mise à jour individuelle des produits et contacts, y compris lorsque le suivi automatique est arrêté. Les comparaisons périmées, modifications locales et conflits ne sont pas écrasés.
- Synchronisation complète dans le sens choisi (produits, contacts, commandes) ou d’une seule famille, par requêtes bornées, avec progression, arrêt après la fiche courante et journal copiable. Gardez la page ouverte ; relancer reconnaît les fiches déjà appliquées.
- Les variantes, images et champs autorisés suivent les produits. Les différences de stock sont signalées séparément : les quantités existantes gardent leur suivi par événements, sans forçage d’inventaire. Les contacts ne créent des comptes natifs que si cette option est déjà activée.
- Les modes automatiques restent inchangés. Mettre les deux connecteurs à jour en 0.6.0 pour utiliser toutes les comparaisons et les transferts manuels.

# 0.5.1

- Les deux administrations affichent clairement la boutique consultée et le domaine du partenaire, avec des repères WordPress et PrestaShop constants.
- Comparaison des commandes côte à côte : WordPress à gauche, PrestaShop à droite, références et statuts natifs de chaque boutique, distinction original/copie et destination nommée sur les boutons.
- Rapports : origine lisible, identifiants de la copie locale, filtres originaux/imports et sens WordPress → PrestaShop ou PrestaShop → WordPress dans le journal.
- Libellés des captures précisant les boutiques concernées ; la présentation ne change pas les règles de synchronisation.

# 0.5.0

- Nouveau panneau « Commandes à synchroniser » dans les deux plugins : comparaison des commandes originales avec la boutique partenaire, dans les deux sens, par lots de 20.
- Import ou envoi manuel d’une commande absente ou modifiée, même lorsque le suivi automatique est arrêté ou en audit. Le mode automatique reste inchangé ; une licence autorisant les écritures est nécessaire sur la destination.
- Affichage des écarts de statut, montants, articles, coordonnées, métadonnées et remboursements ; protection contre les modifications concurrentes et les conflits locaux, sans doublon lors d’une nouvelle tentative.
- Exclusion des brouillons de checkout WooCommerce non validés.
- Compatibilité PrestaShop 9.1 : validation native des jetons CSRF aléatoires Symfony et remplacement de l’ancienne fonction de réécriture des URL. Vérifié sur PrestaShop 8.2.8 et 9.1.5.
- Mettez les deux plugins à jour en 0.5.0 avant d’utiliser la comparaison manuelle.

# 0.4.0

- Administration en français : réglages, actions, en-têtes des rapports, diagnostics et messages. Les valeurs techniques (codes de diagnostic, identités, commandes CLI) ne changent pas.
- PrestaShop : bandeau dans tout le back-office quand le mode live est suspendu ou que le délai de grâce de la licence court ; enregistré automatiquement, y compris après une mise à jour en un clic.

# 0.3.2

- Messages de licence : l’état n’est plus répété dans le texte du serveur.
- WordPress : lien « Réglages » dans la liste des extensions.
- README : section licence et mises à jour.

# 0.3.1

- WordPress : le canal de mise à jour est aussi disponible depuis WP-CLI et les tâches planifiées ; il n’est chargé que lorsque WordPress recherche des nouveautés.

# 0.3.0

- Panneau Licence (saisie, état, vérification, retrait de la clé) dans les deux plugins.
- Vérification quotidienne signée (Ed25519) depuis le worker ; échec réseau sans effet ; diagnostics `licence_*`.
- Mode live rabattu en audit quand la licence ne le permet pas ; reprise automatique.
- Mises à jour depuis l’administration (WordPress : écran Extensions ; PrestaShop : bouton dans le panneau Licence), SHA-256 contrôlé.

# 0.2.2

- Présentation commune des panneaux, indicateurs, rapports repliables, tableaux défilants et badges d’état.

# 0.1.4

- Import historical order lines independently of catalog availability; attach later without changing stock or totals.
- Add native order reconciliation totals and a private source-owned customer contact directory.
- Synchronize customer/guest contact snapshots with signed, deduplicated events; do not create login accounts or copy credentials/consents.
- Add automatic contact-table migration and customer capture controls.

# 0.1.3

- Synchronize native product brands/manufacturers and tags; show them in the catalog audit.
- Decode category HTML entities before PrestaShop validation.
- Record a declared source-wide order discount on WooCommerce mirrors when it reconciles the exact source total.

# 0.1.2

- PrestaShop: derive variable base prices from children, preserve tracked backorder policy and block incompatible mixed inventory modes before creating products.

# 0.1.1

- Add a read-only catalog audit with source identities, destination mappings, tax basis and unknown inventory.

# Changelog

## 0.1.0

- Appairage direct et webhooks signés.
- Réunion initiale des catalogues et identités d’origine persistantes.
- Stock par écarts, déduplication et reprise des livraisons.
- Produits simples, déclinaisons et commandes miroirs natives.
- Fiscalité explicitement configurée et conflits visibles.
- Tests de protocole et tests natifs sur bases isolées.
