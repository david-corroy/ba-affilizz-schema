<?php
/**
 * Plugin Name: BA Affilizz Schema
 * Description: Genere le JSON-LD ItemList / Product / AggregateOffer des blocs Affilizz, a partir de l'endpoint de rendu public — la source meme dont le widget se sert, donc un balisage qui decrit toujours ce que le lecteur voit. Generation par cron, stockage en post_meta, aucun appel reseau au rendu de la page. Aucune cle API requise.
 * Version: 1.4.1
 * Author: Buzzarena
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BA_AFSC_VERSION', '1.4.1' );
define( 'BA_AFSC_META', '_ba_afsc_jsonld' );
define( 'BA_AFSC_META_DATE', '_ba_afsc_generated' );
define( 'BA_AFSC_META_BLOCS', '_ba_afsc_blocs' );
define( 'BA_AFSC_META_VIDES', '_ba_afsc_vides' );
define( 'BA_AFSC_RENDER', 'https://render.api.affilizz.com/api/v1/render/' );

/* =========================================================================
   REGLAGES
   ====================================================================== */

function ba_afsc_settings() {
	return wp_parse_args( get_option( 'ba_afsc_settings', array() ), array(
		'actif'        => 0,   // desactive par defaut : on verifie une page d'abord
		'lot'          => 20,  // articles traites par passage du cron
		'fraicheur'    => 24,  // heures avant regeneration d'un article
		'types'        => 'post',
		'in_stock_only'=> 1,   // ignorer les offres en rupture
	) );
}

function ba_afsc_opt( $nom, $defaut = 0 ) {
	$s = ba_afsc_settings();
	return isset( $s[ $nom ] ) ? $s[ $nom ] : $defaut;
}

/* =========================================================================
   REPERAGE DES BLOCS DANS UN ARTICLE
   Gutenberg stocke le bloc dans post_content ; Elementor le range dans
   _elementor_data, ou les guillemets sont echappes. Le motif accepte les
   deux formes plutot que de supposer laquelle est utilisee.
   ====================================================================== */

/**
 * Les trois endroits ou un identifiant peut se trouver. Le shortcode est
 * execute parce que le bloc Gutenberg ne stocke qu'un code court —
 * [affilizz-publication id="uoz4yrtw8"] — et que la resolution vers
 * l'identifiant 24-hex n'existe que cote serveur d'Affilizz.
 */
function ba_afsc_sources( $post_id ) {
	static $cache = array();
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$contenu = get_post_field( 'post_content', $post_id );
	$sources = array( $contenu );

	if ( is_string( $contenu ) && false !== stripos( $contenu, 'affilizz' ) ) {
		$sources[] = do_shortcode( $contenu );
	}

	$elementor = get_post_meta( $post_id, '_elementor_data', true );
	if ( ! empty( $elementor ) ) {
		$sources[] = is_string( $elementor ) ? $elementor : wp_json_encode( $elementor );
	}

	$cache[ $post_id ] = $sources;
	return $sources;
}

/**
 * Ancres reelles des titres de la page. Un titre s'appelle
 * « TCL 65C9K, la meilleure TV TCL » et son id vaut donc
 * tcl-65c9k-la-meilleure-tv-tcl : le nom du produit n'en est que le debut.
 */
function ba_afsc_ancres( $post_id ) {
	$ids = array();
	foreach ( ba_afsc_sources( $post_id ) as $source ) {
		if ( is_string( $source ) && preg_match_all( '/<h[1-6][^>]*\\sid=["\']([^"\']+)["\']/i', $source, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
	}
	return array_values( array_unique( $ids ) );
}

function ba_afsc_content_ids( $post_id ) {
	$sources = ba_afsc_sources( $post_id );

	$ids = array();
	foreach ( $sources as $source ) {
		if ( ! is_string( $source ) || '' === $source ) {
			continue;
		}
		// Le HTML rendu porte publication-content-id="..." mais Gutenberg stocke
		// ses attributs en JSON : "publicationContentId":"...". Elementor, lui,
		// echappe les guillemets. Un seul motif couvre les trois formes.
		if ( preg_match_all( '/publication[-_]?content[-_]?id["\'\\\\\\s:=]{1,8}([a-f0-9]{24})/i', $source, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
	}

	// array_values pour repartir d'un tableau indexe : l'ordre du document
	// porte le classement du comparatif, on le conserve.
	return array_values( array_unique( $ids ) );
}

/* =========================================================================
   APPEL DE L'ENDPOINT DE RENDU
   Pas de cle API : c'est l'endpoint public que le widget interroge lui-meme.
   ====================================================================== */

function ba_afsc_fetch( $content_id, $page_url ) {
	$reponse = wp_remote_post( BA_AFSC_RENDER . rawurlencode( $content_id ), array(
		'timeout' => 15,
		'headers' => array(
			'content-type' => 'application/x-www-form-urlencoded',
			'accept'       => 'application/json',
		),
		'body'    => array(
			'cacheDisabled' => 'false',
			'location'      => $page_url,
			'sessionId'     => wp_generate_uuid4(),
		),
	) );

	if ( is_wp_error( $reponse ) ) {
		return false;
	}

	$code = wp_remote_retrieve_response_code( $reponse );
	// 204 = le bloc ne renvoie plus rien. Ce n'est pas une erreur mais un
	// contenu vide : plus aucun produit n'a d'offre achetable. On le
	// distingue, c'est cette information que voient les redacteurs.
	if ( 204 === $code ) {
		return 204;
	}
	if ( 200 !== $code ) {
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $reponse ), true );
	return is_array( $data ) ? $data : false;
}

/* =========================================================================
   CONSTRUCTION DU JSON-LD
   ====================================================================== */

// Avantages / inconvenients au format attendu par Google.
function ba_afsc_notes( $points ) {
	$liste = array();
	foreach ( $points as $n => $point ) {
		$texte = trim( wp_strip_all_tags( (string) $point ) );
		if ( '' === $texte ) {
			continue;
		}
		$liste[] = array(
			'@type'    => 'ListItem',
			'position' => count( $liste ) + 1,
			'name'     => $texte,
		);
	}
	return $liste ? array( '@type' => 'ItemList', 'itemListElement' => $liste ) : null;
}

// Retient le titre reel qui commence par le nom du produit. Aucune
// correspondance : pas d'ancre du tout plutot qu'une ancre inventee.
function ba_afsc_ancre( $titre, $ancres ) {
	$slug = sanitize_title( $titre );
	if ( ! $slug ) {
		return '';
	}
	foreach ( $ancres as $id ) {
		if ( 0 === strpos( $id, $slug ) ) {
			return '#' . $id;
		}
	}
	return '';
}

function ba_afsc_product( $carte, $page_url, $ancres = array() ) {
	$nom = $carte['productName'] ?? ( $carte['title'] ?? '' );
	if ( '' === trim( (string) $nom ) ) {
		return null;
	}

	$produit = array( '@type' => 'Product', 'name' => trim( $nom ) );

	if ( ! empty( $carte['productImage'] ) ) {
		$produit['image'] = esc_url_raw( $carte['productImage'] );
	}
	$produit['url'] = $page_url;
	if ( ! empty( $carte['title'] ) ) {
		$produit['url'] .= ba_afsc_ancre( $carte['title'], $ancres );
	}

	// Une offre en rupture annoncee comme disponible fait rejeter la fiche
	// entiere : on ne garde que ce qui est reellement achetable.
	$offres = array();
	foreach ( (array) ( $carte['offers'] ?? array() ) as $o ) {
		if ( ba_afsc_opt( 'in_stock_only', 1 ) && empty( $o['stock'] ) ) {
			continue;
		}
		if ( ! isset( $o['price'] ) ) {
			continue;
		}
		$offre = array(
			'@type'         => 'Offer',
			'price'         => (float) $o['price'],
			'priceCurrency' => $o['currency'] ?? 'EUR',
			'availability'  => 'https://schema.org/InStock',
		);
		if ( isset( $o['condition'] ) && 'NEW' === $o['condition'] ) {
			$offre['itemCondition'] = 'https://schema.org/NewCondition';
		}
		if ( ! empty( $o['url'] ) ) {
			$offre['url'] = esc_url_raw( $o['url'] );
		}
		if ( ! empty( $o['shopName'] ) ) {
			$offre['seller'] = array( '@type' => 'Organization', 'name' => $o['shopName'] );
		}
		$offres[] = $offre;
	}

	// Un produit sans offre achetable n'est pas declare. Affilizz renvoie
	// bien la fiche — le lecteur voit « Actuellement en rupture de stock » —
	// mais annoncer a Google un produit d'un comparatif sans prix, sans
	// marchand et sans disponibilite serait du balisage creux.
	if ( ! $offres && ba_afsc_opt( 'in_stock_only', 1 ) ) {
		return null;
	}

	if ( $offres ) {
		$prix = wp_list_pluck( $offres, 'price' );
		sort( $prix, SORT_NUMERIC );
		$produit['offers'] = array(
			'@type'         => 'AggregateOffer',
			'priceCurrency' => $offres[0]['priceCurrency'],
			'lowPrice'      => $prix[0],
			'highPrice'     => end( $prix ),
			'offerCount'    => count( $offres ),
			'availability'  => 'https://schema.org/InStock',
			'offers'        => $offres,
		);
	}

	$plus  = ba_afsc_notes( (array) ( $carte['positivePoints'] ?? array() ) );
	$moins = ba_afsc_notes( (array) ( $carte['negativePoints'] ?? array() ) );
	if ( $plus ) {
		$produit['positiveNotes'] = $plus;
	}
	if ( $moins ) {
		$produit['negativeNotes'] = $moins;
	}

	// Volontairement pas d'aggregateRating : le testeur de Google le signale
	// comme facultatif manquant, mais aucune note n'existe sur ces pages.
	// L'inventer serait un faux avis.
	return $produit;
}

/**
 * Un article porte plusieurs blocs, et le carrousel « Top 3 » reprend des
 * produits qui ont aussi leur propre encadre plus bas. On dedoublonne par nom
 * pour ne pas declarer trois fois le meme produit.
 */
function ba_afsc_build( $post_id ) {
	$page_url = get_permalink( $post_id );
	$ids      = ba_afsc_content_ids( $post_id );
	if ( ! $ids || ! $page_url ) {
		return null;
	}

	$ancres   = ba_afsc_ancres( $post_id );
	$produits = array();
	$vus      = array();
	$vides = 0;
	foreach ( $ids as $content_id ) {
		$data = ba_afsc_fetch( $content_id, $page_url );
		if ( 204 === $data ) {
			$vides++;
			continue;
		}
		if ( ! $data ) {
			continue;
		}
		$cartes = isset( $data['contents'] ) ? (array) $data['contents'] : array( $data );
		$retenus = 0;
		foreach ( $cartes as $carte ) {
			$produit = ba_afsc_product( $carte, $page_url, $ancres );
			if ( ! $produit ) {
				continue;
			}
			$retenus++;
			$cle = mb_strtolower( $produit['name'] );
			if ( isset( $vus[ $cle ] ) ) {
				continue;
			}
			$vus[ $cle ] = true;
			$produits[]  = $produit;
		}

		// Le bloc s'affiche mais aucun de ses produits n'est achetable :
		// pour le lecteur, l'encadre ne sert a rien. C'est ce que doit
		// voir la redaction, pas seulement le cas du 204.
		if ( 0 === $retenus ) {
			$vides++;
		}
	}

	update_post_meta( $post_id, BA_AFSC_META_VIDES, $vides );

	if ( ! $produits ) {
		return null;
	}

	$elements = array();
	foreach ( $produits as $n => $produit ) {
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $n + 1,
			'item'     => $produit,
		);
	}

	return array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
		'numberOfItems'   => count( $produits ),
		'itemListElement' => $elements,
	);
}

/* =========================================================================
   GENERATION ET STOCKAGE
   ====================================================================== */

function ba_afsc_generate( $post_id ) {
	// Le nombre de blocs est memorise : un article qui n'en porte aucun n'a
	// pas besoin d'etre reexamine toutes les 24 h. Sur 4 500 articles dont
	// un tiers seulement porte des blocs, c'est ce qui rend la file tenable.
	$blocs = count( ba_afsc_content_ids( $post_id ) );
	update_post_meta( $post_id, BA_AFSC_META_BLOCS, $blocs );

	if ( ! $blocs ) {
		delete_post_meta( $post_id, BA_AFSC_META_VIDES );
	}
	$schema = $blocs ? ba_afsc_build( $post_id ) : null;
	update_post_meta( $post_id, BA_AFSC_META_DATE, time() );

	if ( ! $schema ) {
		delete_post_meta( $post_id, BA_AFSC_META );
		return false;
	}

	$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	// wp_slash() est indispensable : update_post_meta() applique wp_unslash()
	// sur la valeur, ce qui mangeait l'antislash des guillemets echappes du
	// JSON. Un nom de produit contenant un pouce — « ecran 6,67" » — suffisait
	// a produire un JSON-LD casse, servi tel quel dans la page.
	update_post_meta( $post_id, BA_AFSC_META, wp_slash( $json ) );
	return true;
}

/* =========================================================================
   SORTIE DANS LA PAGE
   Lecture d'une meta, rien d'autre. Aucun appel reseau ici : sur un article
   a douze blocs, ce serait douze requetes a chaque visite.
   ====================================================================== */

add_action( 'wp_head', function() {
	if ( ! is_singular() || empty( ba_afsc_settings()['actif'] ) ) {
		return;
	}
	$json = get_post_meta( get_the_ID(), BA_AFSC_META, true );
	if ( empty( $json ) ) {
		return;
	}
	// Filet de securite : mieux vaut aucun balisage qu'un balisage casse,
	// que Google rejetterait sans rien dire.
	if ( null === json_decode( $json, true ) ) {
		return;
	}
	// Le JSON vient de wp_json_encode : la seule sequence a neutraliser est
	// celle qui fermerait le <script> par anticipation.
	echo "\n<script type=\"application/ld+json\">"
		. str_replace( '</', '<\\/', $json )
		. "</script>\n";
}, 20 );

/* =========================================================================
   CRON — regeneration par lots
   ====================================================================== */

add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['ba_afsc_horaire'] = array( 'interval' => HOUR_IN_SECONDS, 'display' => 'BA Affilizz — toutes les heures' );
	return $schedules;
} );

/**
 * Marque en une passe les articles susceptibles de porter un bloc. Sans ca,
 * la file part par date et broute des milliers de breves avant d'atteindre
 * un comparatif : constate en production, 20 articles traites, 0 avec
 * produits.
 *
 * -1 = candidat jamais examine, 0 = aucun bloc, >0 = nombre de blocs.
 */
function ba_afsc_reperer() {
	global $wpdb;

	$types = array_filter( array_map( 'trim', explode( ',', (string) ba_afsc_opt( 'types', 'post' ) ) ) );
	$types = $types ? $types : array( 'post' );
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$like  = '%' . $wpdb->esc_like( 'affilizz' ) . '%';

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", BA_AFSC_META_BLOCS ) );

	// Candidats : le contenu mentionne affilizz. Marques -1, donc en tete de file.
	$candidats = $wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT ID, %s, '-1' FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type IN ($in) AND post_content LIKE %s",
		array_merge( array( BA_AFSC_META_BLOCS ), $types, array( $like ) )
	) );

	// Les autres sont marques a 0 et horodates : ils tombent directement dans
	// le balayage hebdomadaire au lieu d'encombrer la file utile.
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT ID, %s, '0' FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type IN ($in) AND post_content NOT LIKE %s",
		array_merge( array( BA_AFSC_META_BLOCS ), $types, array( $like ) )
	) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", BA_AFSC_META_DATE ) );
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT post_id, %s, %s FROM {$wpdb->postmeta}
		 WHERE meta_key = %s AND meta_value = '0'",
		BA_AFSC_META_DATE, (string) time(), BA_AFSC_META_BLOCS
	) );

	update_option( 'ba_afsc_repere', array( 'date' => time(), 'candidats' => (int) $candidats ), false );
	return (int) $candidats;
}

function ba_afsc_a_traiter( $limite ) {
	$fraicheur = max( 1, (int) ba_afsc_opt( 'fraicheur', 24 ) ) * HOUR_IN_SECONDS;
	$longue    = WEEK_IN_SECONDS; // articles sans bloc : une verification par semaine suffit
	$types     = array_filter( array_map( 'trim', explode( ',', (string) ba_afsc_opt( 'types', 'post' ) ) ) );
	$types     = $types ? $types : array( 'post' );

	$base = array(
		'post_type'        => $types,
		'post_status'      => 'publish',
		'fields'           => 'ids',
		'orderby'          => 'meta_value_num',
		'meta_key'         => BA_AFSC_META_DATE,
		'order'            => 'ASC',
		'suppress_filters' => true,
	);

	$ids = array();

	// 1. Jamais examines. 2. Porteurs de blocs et perimes. 3. Sans bloc et
	// perimes depuis longtemps. L'ordre garantit que la file utile passe
	// avant le balayage de fond.
	$passes = array(
		// Candidats reperes, jamais examines.
		array( array( 'key' => BA_AFSC_META_BLOCS, 'value' => -1, 'compare' => '=', 'type' => 'NUMERIC' ) ),
		array( array( 'key' => BA_AFSC_META_DATE, 'compare' => 'NOT EXISTS' ) ),
		array(
			'relation' => 'AND',
			array( 'key' => BA_AFSC_META_BLOCS, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
			array( 'key' => BA_AFSC_META_DATE, 'value' => time() - $fraicheur, 'compare' => '<', 'type' => 'NUMERIC' ),
		),
		array(
			'relation' => 'AND',
			array( 'key' => BA_AFSC_META_BLOCS, 'value' => 0, 'compare' => '=', 'type' => 'NUMERIC' ),
			array( 'key' => BA_AFSC_META_DATE, 'value' => time() - $longue, 'compare' => '<', 'type' => 'NUMERIC' ),
		),
	);

	foreach ( $passes as $n => $meta_query ) {
		$reste = $limite - count( $ids );
		if ( $reste < 1 ) {
			break;
		}
		$args = $base;
		$args['posts_per_page'] = $reste;
		$args['meta_query']     = $meta_query;
		$args['post__not_in']   = $ids;
		if ( $n <= 1 ) {
			// Sans date de generation, il n'y a rien a trier dessus.
			$args['orderby'] = 'date';
			unset( $args['meta_key'] );
		}
		$ids = array_merge( $ids, get_posts( $args ) );
	}

	return $ids;
}

function ba_afsc_traiter_lot() {
	if ( empty( ba_afsc_settings()['actif'] ) ) {
		return;
	}

	$lot = max( 1, min( 100, (int) ba_afsc_opt( 'lot', 20 ) ) );
	$ids = ba_afsc_a_traiter( $lot );
	$ok  = 0;

	foreach ( $ids as $post_id ) {
		if ( ba_afsc_generate( $post_id ) ) {
			$ok++;
		}
		// Un appel par bloc : on ne martele pas l'API d'Affilizz.
		usleep( 300000 );
	}

	update_option( 'ba_afsc_dernier_passage', array(
		'date'    => time(),
		'traites' => count( $ids ),
		'avec'    => $ok,
	), false );
}
add_action( 'ba_afsc_cron', 'ba_afsc_traiter_lot' );

/* =========================================================================
   DECLENCHEUR CRON SERVEUR
   WP-Cron ne se declenche que sur une visite. Sur un hebergement ou il est
   capricieux, une tache cron systeme appelle cette URL et le lot part
   directement, sans dependre du trafic.
   ====================================================================== */

function ba_afsc_tick_key() {
	$cle = get_option( 'ba_afsc_tick_key' );
	if ( ! $cle ) {
		$cle = wp_generate_password( 24, false, false );
		update_option( 'ba_afsc_tick_key', $cle, false );
	}
	return $cle;
}

function ba_afsc_tick() {
	$fournie = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	// Comparaison a temps constant : la cle ne doit pas pouvoir etre devinee
	// caractere par caractere en mesurant le temps de reponse.
	if ( ! hash_equals( ba_afsc_tick_key(), $fournie ) ) {
		status_header( 403 );
		exit( 'cle invalide' );
	}

	ba_afsc_traiter_lot();

	$der = get_option( 'ba_afsc_dernier_passage' );
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	exit( $der ? sprintf( "%d article(s) traites, %d avec produits\n", $der['traites'], $der['avec'] ) : "rien a faire\n" );
}
add_action( 'admin_post_nopriv_ba_afsc_tick', 'ba_afsc_tick' );
add_action( 'admin_post_ba_afsc_tick', 'ba_afsc_tick' );

// Un article modifie est regenere au prochain passage, pas pendant la sauvegarde.
add_action( 'save_post', function( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	delete_post_meta( $post_id, BA_AFSC_META_DATE );
} );

/* =========================================================================
   ACTIVATION / DESACTIVATION
   ====================================================================== */

register_activation_hook( __FILE__, function() {
	if ( ! wp_next_scheduled( 'ba_afsc_cron' ) ) {
		wp_schedule_event( time() + 300, 'ba_afsc_horaire', 'ba_afsc_cron' );
	}
} );

register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'ba_afsc_cron' );
} );

/* =========================================================================
   ADMINISTRATION
   ====================================================================== */

/**
 * Progression rapportee aux seuls articles qui portent des blocs. Compter
 * « 275 sur 3438 examines » melangeait les guides et les breves marquees par
 * le reperage : le chiffre n'avancait pas visiblement.
 */
function ba_afsc_couverture() {
	global $wpdb;

	$avec = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
		BA_AFSC_META
	) );
	// Candidats : ceux qui restent a -1 plus ceux dont le compte est connu.
	$candidats = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '0'",
		BA_AFSC_META_BLOCS
	) );
	$restants = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '-1'",
		BA_AFSC_META_BLOCS
	) );

	return array( 'avec' => $avec, 'candidats' => $candidats, 'restants' => $restants );
}

/**
 * Quand aucun bloc n'est repere, extrait le contexte autour de « affilizz »
 * dans le contenu et dans les metas Elementor. Sert a voir le format reel
 * plutot qu'a le supposer.
 */
function ba_afsc_diagnostic( $post_id ) {
	$contenu = get_post_field( 'post_content', $post_id );
	$sources = array(
		'post_content'          => $contenu,
		'shortcode execute'     => is_string( $contenu ) ? do_shortcode( $contenu ) : '',
		'_elementor_data'       => get_post_meta( $post_id, '_elementor_data', true ),
	);

	foreach ( $sources as $nom => $source ) {
		if ( ! is_string( $source ) || '' === $source ) {
			continue;
		}
		// On cherche d'abord l'attribut resolu, plus parlant que le mot seul.
		$pos = stripos( $source, 'publication-content-id' );
		if ( false === $pos ) {
			$pos = stripos( $source, 'affilizz' );
		}
		if ( false === $pos ) {
			continue;
		}
		$extrait = substr( $source, max( 0, $pos - 60 ), 220 );
		return sprintf( 'Diagnostic — %s : %s', $nom, $extrait );
	}

	return 'Diagnostic — le mot « affilizz » n\'apparait ni dans post_content ni dans _elementor_data. Le bloc vient peut-etre d\'un shortcode, d\'un champ personnalise, ou du theme.';
}

add_action( 'admin_menu', function() {
	add_menu_page( 'BA Affilizz Schema', 'BA Affilizz', 'manage_options', 'ba-afsc', 'ba_afsc_page', 'dashicons-tag', 81 );
} );

function ba_afsc_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$avis   = array();
	$apercu = '';

	if ( isset( $_POST['ba_afsc_save'] ) && check_admin_referer( 'ba_afsc_admin' ) ) {
		$s = ba_afsc_settings();
		$s['actif']         = empty( $_POST['actif'] ) ? 0 : 1;
		$s['in_stock_only'] = empty( $_POST['in_stock_only'] ) ? 0 : 1;
		$s['lot']           = min( 100, max( 1, (int) ( $_POST['lot'] ?? 20 ) ) );
		$s['fraicheur']     = min( 720, max( 1, (int) ( $_POST['fraicheur'] ?? 24 ) ) );
		$s['types']         = sanitize_text_field( wp_unslash( $_POST['types'] ?? 'post' ) );
		update_option( 'ba_afsc_settings', $s, false );
		$avis[] = 'Reglages enregistres.';
	}

	if ( isset( $_POST['ba_afsc_test'] ) && check_admin_referer( 'ba_afsc_admin' ) ) {
		$id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $id && get_post( $id ) ) {
			$blocs = count( ba_afsc_content_ids( $id ) );
			$ok    = ba_afsc_generate( $id );
			$avis[] = sprintf(
				'Article %d : %d bloc(s) Affilizz reperes, balisage %s.',
				$id, $blocs, $ok ? 'genere' : 'vide (aucun produit renvoye)'
			);
			// Aucun bloc trouve : plutot que de laisser deviner, on montre ce
			// que le contenu contient reellement autour du mot « affilizz ».
			if ( 0 === $blocs ) {
				$avis[] = ba_afsc_diagnostic( $id );
			}
			// Le balisage doit etre verifiable avant d'etre publie : sans ca,
			// « testez puis activez » demande de verifier l'invisible.
			if ( $ok ) {
				$apercu = get_post_meta( $id, BA_AFSC_META, true );
			}
		} else {
			$avis[] = 'Identifiant d\'article inconnu.';
		}
	}

	if ( isset( $_POST['ba_afsc_reperer'] ) && check_admin_referer( 'ba_afsc_admin' ) ) {
		$n = ba_afsc_reperer();
		$avis[] = sprintf( '%d article(s) contiennent un bloc Affilizz. Ils passent en tete de file ; les autres basculent en balayage hebdomadaire.', $n );
	}

	if ( isset( $_POST['ba_afsc_purge'] ) && check_admin_referer( 'ba_afsc_admin' ) ) {
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => BA_AFSC_META ) );
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => BA_AFSC_META_DATE ) );
		$avis[] = 'Balisage purge. Le cron regenerera tout par lots.';
	}

	$s   = ba_afsc_settings();
	$cov = ba_afsc_couverture();
	$der = get_option( 'ba_afsc_dernier_passage' );
	$suiv = wp_next_scheduled( 'ba_afsc_cron' );
	?>
	<div class="wrap">
		<h1>BA Affilizz Schema <span style="font-size:13px;color:#666">v<?php echo esc_html( BA_AFSC_VERSION ); ?></span></h1>

		<?php foreach ( $avis as $a ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $a ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( empty( $s['actif'] ) ) : ?>
			<div class="notice notice-warning"><p><strong>Inactif.</strong> Le balisage n'est pas envoye dans les pages. Testez un article ci-dessous, verifiez-le dans le testeur de resultats enrichis de Google, puis activez.</p></div>
		<?php endif; ?>

		<?php if ( $apercu ) : ?>
			<h2>Balisage genere</h2>
			<p>Copiez ce JSON-LD dans l'onglet <strong>Code</strong> du
				<a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">testeur de resultats enrichis</a>
				avant d'activer. Clic dans le champ = tout selectionner.</p>
			<textarea readonly onclick="this.select();" style="width:100%;height:260px;font-family:monospace;font-size:12px;white-space:pre;"><?php
				echo esc_textarea( wp_json_encode( json_decode( $apercu, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			?></textarea>
		<?php endif; ?>

		<h2>Etat</h2>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Guides avec balisage</td><td><?php
				$pct = $cov['candidats'] ? round( 100 * $cov['avec'] / $cov['candidats'] ) : 0;
				printf( '<strong>%d</strong> sur %d guides &nbsp;<span style="color:#666">(%d %%)</span>',
					(int) $cov['avec'], (int) $cov['candidats'], (int) $pct );
			?></td></tr>
			<tr><td>Dernier passage du cron</td><td><?php
				echo $der ? esc_html( sprintf( '%s — %d article(s), %d avec produits',
					wp_date( 'j M Y H:i', $der['date'] ), $der['traites'], $der['avec'] ) ) : 'jamais';
			?></td></tr>
			<tr><td>Articles avec bloc Affilizz</td><td><?php
				$rep = get_option( 'ba_afsc_repere' );
				echo $rep ? esc_html( sprintf( '%d reperes le %s', $rep['candidats'], wp_date( 'j M H:i', $rep['date'] ) ) ) : 'reperage jamais lance';
			?></td></tr>
			<tr><td>Guides restant a examiner</td><td><?php
				$lot = max( 1, (int) $s['lot'] );
				printf( '%d &nbsp;<span style="color:#666">(~%s au rythme actuel)</span>',
					(int) $cov['restants'],
					esc_html( $cov['restants'] ? ceil( $cov['restants'] / $lot ) . ' passage(s)' : 'termine' )
				);
			?></td></tr>
			<tr><td>Prochain passage</td><td><?php echo $suiv ? esc_html( wp_date( 'j M Y H:i', $suiv ) ) : 'non planifie'; ?></td></tr>
		</table>

		<form method="post" style="margin-top:24px">
			<?php wp_nonce_field( 'ba_afsc_admin' ); ?>
			<h2>Reglages</h2>
			<table class="form-table">
				<tr><th>Activation</th><td>
					<label><input type="checkbox" name="actif" value="1" <?php checked( 1, $s['actif'] ); ?> /> Envoyer le balisage dans les pages</label>
				</td></tr>
				<tr><th>Offres</th><td>
					<label><input type="checkbox" name="in_stock_only" value="1" <?php checked( 1, $s['in_stock_only'] ); ?> /> Ignorer les offres en rupture</label>
					<p class="description">Recommande. Annoncer un prix indisponible fait rejeter la fiche produit entiere.</p>
				</td></tr>
				<tr><th>Articles par passage</th><td>
					<input type="number" name="lot" min="1" max="100" value="<?php echo (int) $s['lot']; ?>" />
					<p class="description">Le cron passe toutes les heures. 20 par passage couvre 480 articles par jour.</p>
				</td></tr>
				<tr><th>Fraicheur</th><td>
					<input type="number" name="fraicheur" min="1" max="720" value="<?php echo (int) $s['fraicheur']; ?>" /> heures
					<p class="description">Age au-dela duquel un article est regenere. Les prix bougent : 24 h est un bon compromis.</p>
				</td></tr>
				<tr><th>Declencheur cron serveur</th><td>
					<input type="text" readonly onclick="this.select();" style="width:100%;font-family:monospace;font-size:12px;"
						value='wget "<?php echo esc_url( admin_url( 'admin-post.php?action=ba_afsc_tick&key=' . ba_afsc_tick_key() ) ); ?>" -q -O /dev/null -t 1 -T 300' />
					<p class="description">A coller dans une tache cron cPanel, toutes les heures. WP-Cron ne se declenche que sur une visite : sur cet hebergement, le declencheur direct est plus sur. Clic dans le champ = tout selectionner.</p>
				</td></tr>
				<tr><th>Types de contenu</th><td>
					<input type="text" name="types" value="<?php echo esc_attr( $s['types'] ); ?>" class="regular-text" />
					<p class="description">Separes par des virgules.</p>
				</td></tr>
			</table>
			<p><button class="button button-primary" name="ba_afsc_save" value="1">Enregistrer</button></p>

			<h2>Tester un article</h2>
			<p>
				<input type="number" name="post_id" placeholder="ID de l'article" />
				<button class="button" name="ba_afsc_test" value="1">Generer maintenant</button>
			</p>
			<p class="description">Genere le balisage immediatement et affiche le nombre de blocs trouves. Utile pour verifier avant d'activer.</p>

			<h2>Reperer les articles concernes</h2>
			<p><button class="button button-primary" name="ba_afsc_reperer" value="1">Reperer maintenant</button></p>
			<p class="description">Une requete marque les articles dont le contenu mentionne Affilizz et les place en tete de file. Les autres passent en balayage hebdomadaire. A lancer une fois apres l'installation, puis apres toute purge.</p>

		<h2>Purger</h2>
			<p><button class="button" name="ba_afsc_purge" value="1" onclick="return confirm('Supprimer tout le balisage stocke ? Le cron le regenerera par lots.');">Tout supprimer et regenerer</button></p>
		</form>
	</div>
	<?php
}
