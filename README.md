# BA Affilizz Schema — données structurées produit pour les blocs Affilizz

**Plugin WordPress qui génère le JSON-LD `ItemList` / `Product` / `AggregateOffer` de vos comparatifs Affilizz, à partir de la source même dont le widget se sert pour l'affichage.** Développé par [Bernard David Corroy](https://www.david-corroy.com/) pour [BuzzArena](https://www.buzzarena.com/).

## Le problème

Le widget Affilizz injecte les encadrés produits en JavaScript. Vérification faite sur une page réelle, rendue dans un Chromium complet : le DOM grossit de 97 Ko, et le compte de `Product`, `Offer`, `Review` et `ItemList` reste à **zéro**. Les liens marchands n'apparaissent même pas comme `href`.

Autrement dit, le cœur commercial de la page — les produits, les prix, les marchands — est invisible pour les moteurs. Sur un site de comparatifs, c'est précisément ce qui devrait être lisible.

## Comment ça marche

Le plugin appelle l'endpoint de rendu public d'Affilizz, **celui-là même que le widget interroge** :

```
POST https://render.api.affilizz.com/api/v1/render/{publication-content-id}
```

Aucune clé API n'est nécessaire. Et comme la source est identique à celle de l'affichage, le balisage décrit toujours ce que le lecteur voit — ce que Google exige, obtenu par construction plutôt que par discipline.

```
Article WordPress
   ↓  repérage des publication-content-id (Gutenberg et Elementor)
Endpoint de rendu Affilizz
   ↓  produits, images, offres, prix, marchands, avantages/inconvénients
JSON-LD ItemList / Product / AggregateOffer
   ↓  stocké en post_meta par le cron
<head> de la page
```

**Le réseau n'est jamais sollicité au rendu d'une page.** Un article porte souvent une douzaine de blocs : autant de requêtes à chaque visite serait intenable. La génération se fait par lots, une fois par heure, et l'affichage se contente de lire une meta.

## Ce que le balisage contient

- `ItemList` ordonnée, dans l'ordre du comparatif
- `Product` : nom, image, ancre vers la section de la page
- `AggregateOffer` : prix mini, prix maxi, nombre d'offres, puis chaque `Offer` avec son prix, son marchand et son lien
- `positiveNotes` / `negativeNotes` : les avantages et inconvénients, au format « pros and cons » de Google

## Deux décisions de conception

**Les offres en rupture sont exclues.** Une offre `stock: false` annoncée comme disponible fait rejeter la fiche produit entière. Le plugin ne retient que ce qui est réellement achetable, et la fourchette de prix est calculée sur ce reliquat.

**Pas de `aggregateRating`.** Le testeur de Google le signale comme champ facultatif manquant. Il le restera : aucune note n'existe sur ces pages, et en inventer une serait un faux avis. Si la rédaction se met un jour à noter réellement, `Review` + `reviewRating` deviendra légitime — c'est une décision éditoriale, pas un ajout de balise.

## Installation

1. Déposez le dossier dans `wp-content/plugins/` et activez le plugin
2. **Réglages → BA Affilizz** : saisissez l'ID d'un article de comparatif et cliquez « Générer maintenant »
3. Vérifiez le résultat dans le [testeur de résultats enrichis](https://search.google.com/test/rich-results), onglet **Code**
4. Cochez « Envoyer le balisage dans les pages »

Le plugin est **désactivé par défaut** : rien ne sort dans les pages tant que vous n'avez pas vérifié.

## Réglages

| Réglage | Défaut | Rôle |
|---|---|---|
| Activation | désactivé | Envoyer ou non le balisage dans les pages |
| Ignorer les offres en rupture | activé | Recommandé — voir plus haut |
| Articles par passage | 20 | Le cron passe toutes les heures, soit 480 articles par jour |
| Fraîcheur | 24 h | Âge au-delà duquel un article est régénéré. Les prix bougent. |
| Types de contenu | `post` | Séparés par des virgules |

Un article modifié voit sa date de génération effacée : il repasse au prochain lot, sans ralentir la sauvegarde.

## Prérequis et limites

- **WordPress 5.6+**, PHP 7.4+
- Les blocs doivent porter l'attribut `publication-content-id` — c'est le cas du bloc Gutenberg Affilizz. Le plugin cherche aussi dans `_elementor_data`.
- Un bloc vide côté Affilizz renvoie `204` : aucun balisage n'est produit pour lui, ce qui est le comportement correct. Pour repérer ces blocs, voir l'endpoint `GET /v1/contents?empty=true` de l'API Affilizz — il demande, lui, une clé.
- Le balisage valide ne garantit pas l'affichage d'un résultat enrichi : Google en décide. Ce que ce plugin supprime, c'est le fait d'être structurellement illisible.

## Licence

GPL-2.0-or-later — comme WordPress.
