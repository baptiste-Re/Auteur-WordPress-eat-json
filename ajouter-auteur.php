<?php
/**
 * Plugin Name: ajouter-auteur
 * Description: Affiche automatiquement (1) avant le contenu : "Publié le (date) par (auteur) : date de mise à jour de l'article (date)"; (2) après le contenu : boîte auteur (nom + bio); (3) JSON-LD Article + auteur (forcé même si Yoast ou Rank Math sont actifs, sauf si vous le désactivez dans les réglages).
 * Version: 1.3.1
 * Author: baptiste rey (rc2i)
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** ===== Options & Helpers ===== */

// Valeurs par défaut (force JSON-LD = ON)
function aa_default_settings() {
    return array(
        'author_link'         => 0,   // 0 = sans lien /author/...
        'show_avatar'         => 1,   // 1 = afficher l’avatar
        'force_jsonld'        => 1,   // 1 = forcer JSON-LD même si SEO plugins actifs
        'priority_top'        => 5,   // injection haut
        'priority_bottom'     => 20,  // injection bas
    );
}

// Récupération d’une option (avec fallback constants + défauts)
function aa_get_setting( $key ) {
    $defs  = aa_default_settings();
    $opt   = get_option( 'aa_settings', array() );
    $value = isset( $opt[ $key ] ) ? $opt[ $key ] : ( isset( $defs[ $key ] ) ? $defs[ $key ] : null );

    // Fallback constants historiques si définies
    if ( $key === 'author_link' && defined('AA_AUTHOR_LINK') )              $value = AA_AUTHOR_LINK ? 1 : 0;
    if ( $key === 'show_avatar' && defined('AA_SHOW_AVATAR') )              $value = AA_SHOW_AVATAR ? 1 : 0;
    if ( $key === 'force_jsonld' && defined('AA_FORCE_JSONLD') )            $value = AA_FORCE_JSONLD ? 1 : 0;
    if ( $key === 'priority_top' && defined('AA_INJECT_PRIORITY_TOP') )     $value = (int) AA_INJECT_PRIORITY_TOP;
    if ( $key === 'priority_bottom' && defined('AA_INJECT_PRIORITY_BOTTOM') ) $value = (int) AA_INJECT_PRIORITY_BOTTOM;

    return $value;
}

/** ===== Frontend: Top meta line ===== */

function aa_build_meta_line_html( $post = null ) {
    $post = $post ? $post : get_post();
    if ( ! $post ) return '';

    $format       = get_option( 'date_format' );
    $published_ts = get_post_time( 'U', true, $post );
    $modified_ts  = get_post_modified_time( 'U', true, $post );
    if ( ! $published_ts ) return '';

    $published_str = date_i18n( $format, $published_ts );
    $modified_str  = $modified_ts ? date_i18n( $format, $modified_ts ) : '';

    $author_id   = (int) $post->post_author;
    $author_name = $author_id ? get_the_author_meta( 'display_name', $author_id ) : '';
    $author_url  = $author_id ? get_author_posts_url( $author_id ) : '';

    $author_link_opt = (int) aa_get_setting('author_link');

    if ( $author_link_opt && $author_url ) {
        $author_html = sprintf( '<a class="aa-author-link" href="%s">%s</a>', esc_url( $author_url ), esc_html( $author_name ) );
    } else {
        $author_html = esc_html( $author_name );
    }

    $line  = '<div class="ajouter-auteur__meta-line" style="margin:.5rem 0 1rem; font-size:0.95em; opacity:.9;">';
    $line .= sprintf( 'Publié le %s par %s', esc_html( $published_str ), $author_html );

    if ( $modified_ts && $modified_ts !== $published_ts ) {
        $line .= sprintf( ' : %s %s', esc_html__( "date de mise à jour de l'article", 'default' ), esc_html( $modified_str ) );
    }

    $line .= '</div>';

    return $line;
}

function aa_auto_inject_before_content( $content ) {
    if ( is_singular() && get_post_type() === 'post' ) {
        $bar = aa_build_meta_line_html();
        if ( $bar && strpos( $content, 'ajouter-auteur__meta-line' ) === false ) {
            $content = $bar . $content;
        }
    }
    return $content;
}

/** ===== Frontend: Bottom author box ===== */

function aa_build_author_box_html( $post = null ) {
    $post = $post ? $post : get_post();
    if ( ! $post ) return '';

    $author_id   = (int) $post->post_author;
    if ( ! $author_id ) return '';

    $author_name = get_the_author_meta( 'display_name', $author_id );
    $author_bio  = get_the_author_meta( 'description',  $author_id );
    if ( ! $author_name && ! $author_bio ) return '';

    $avatar_html = '';
    if ( (int) aa_get_setting('show_avatar') === 1 ) {
        $avatar_html = get_avatar( $author_id, 64, '', $author_name, array( 'class' => 'aa-author-avatar' ) );
    }

    $name_html = sprintf( '<div class="aa-author-name" style="font-weight:600;">%s</div>', esc_html( $author_name ) );
    $bio_text  = $author_bio ? esc_html( $author_bio ) : '';
    $bio_html  = $bio_text ? sprintf( '<div class="aa-author-bio">%s</div>', wpautop( $bio_text ) ) : '';

    $box  = '<div class="ajouter-auteur__author-box" style="margin-top:2rem; padding-top:1rem; border-top:1px solid rgba(0,0,0,.1); display:flex; gap:.75rem; align-items:flex-start;">';
    if ( $avatar_html ) {
        $box .= '<div class="aa-author-ava-wrap" style="flex:0 0 auto;">'.$avatar_html.'</div>';
    }
    $box .= '<div class="aa-author-text" style="flex:1 1 auto;">'.$name_html.$bio_html.'</div>';
    $box .= '</div>';

    return $box;
}

function aa_auto_inject_after_content( $content ) {
    if ( is_singular() && get_post_type() === 'post' ) {
        $box = aa_build_author_box_html();
        if ( $box && strpos( $content, 'ajouter-auteur__author-box' ) === false ) {
            $content = $content . $box;
        }
    }
    return $content;
}

/** ===== Frontend: JSON-LD ===== */

function aa_output_jsonld() {
    if ( ! is_singular() || get_post_type() !== 'post' ) return;

    // Détection Yoast & Rank Math (pour information / futur usage)
    $yoast_present    = defined('WPSEO_VERSION') || function_exists('wpseo_json_ld_output') || class_exists('WPSEO_Frontend');
    $rankmath_present = defined('RANK_MATH_VERSION') || class_exists('\RankMath\Runner') || function_exists('rank_math');

    // Par défaut on FORCE l'impression (réglage = 1)
    $force_jsonld  = (int) aa_get_setting('force_jsonld') === 1;

    // Si un jour tu veux conditionner, on pourrait: if (($yoast_present || $rankmath_present) && ! $force_jsonld) return;
    if ( ! $force_jsonld && ($yoast_present || $rankmath_present) ) {
        return;
    }

    $post_id      = get_the_ID();
    $headline     = get_the_title( $post_id );
    $permalink    = get_permalink( $post_id );
    $date_pub     = get_post_time( 'c', true, $post_id );
    $date_mod     = get_post_modified_time( 'c', true, $post_id );
    $author_id    = (int) get_post_field( 'post_author', $post_id );
    $author_name  = $author_id ? get_the_author_meta( 'display_name', $author_id ) : '';
    $author_bio   = $author_id ? get_the_author_meta( 'description',  $author_id ) : '';
    $author_url   = $author_id ? get_author_posts_url( $author_id ) : '';

    $sameAs = array();
    $social_keys = array('sab_twitter','sab_facebook','sab_linkedin','sab_instagram','twitter','facebook','linkedin','instagram','user_url');
    foreach ( $social_keys as $key ) {
        $val = trim( (string) get_user_meta( $author_id, $key, true ) );
        if ( filter_var( $val, FILTER_VALIDATE_URL ) ) {
            $sameAs[] = esc_url_raw( $val );
        }
    }
    $sameAs = array_values( array_unique( array_filter( $sameAs ) ) );

    $image = null;
    if ( has_post_thumbnail( $post_id ) ) {
        $img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
        if ( $img && is_array( $img ) ) {
            $image = array(
                '@type'  => 'ImageObject',
                'url'    => $img[0],
                'width'  => isset($img[1]) ? (int) $img[1] : null,
                'height' => isset($img[2]) ? (int) $img[2] : null,
            );
        }
    }

    $org_name = get_bloginfo( 'name' );
    $schema = array(
        '@context'        => 'https://schema.org',
        '@type'           => 'Article',
        'mainEntityOfPage'=> $permalink,
        'headline'        => $headline,
        'datePublished'   => $date_pub,
        'dateModified'    => $date_mod,
        'author'          => array(
            '@type'       => 'Person',
            'name'        => $author_name,
            'description' => $author_bio,
            'url'         => $author_url,
        ),
        'publisher'       => array(
            '@type' => 'Organization',
            'name'  => $org_name,
        ),
    );
    if ( $image ) {
        $schema['image'] = $image;
    }
    if ( ! empty( $sameAs ) ) {
        $schema['author']['sameAs'] = $sameAs;
    }

    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}

/** ===== Hooks front ===== */

add_filter( 'the_content', 'aa_auto_inject_before_content', (int) aa_get_setting('priority_top') );
add_filter( 'the_content', 'aa_auto_inject_after_content',  (int) aa_get_setting('priority_bottom') );
add_action( 'wp_head', 'aa_output_jsonld', 99 );

/** ===== Admin page ===== */

function aa_register_settings() {
    register_setting( 'aa_settings_group', 'aa_settings', 'aa_sanitize_settings' );
}
add_action( 'admin_init', 'aa_register_settings' );

function aa_sanitize_settings( $input ) {
    $defs = aa_default_settings();
    $out  = array();

    $out['author_link']     = isset($input['author_link']) ? (int) !! $input['author_link'] : 0;
    $out['show_avatar']     = isset($input['show_avatar']) ? (int) !! $input['show_avatar'] : 0;
    $out['force_jsonld']    = isset($input['force_jsonld']) ? (int) !! $input['force_jsonld'] : 0;
    $out['priority_top']    = isset($input['priority_top']) ? max(0, (int) $input['priority_top']) : $defs['priority_top'];
    $out['priority_bottom'] = isset($input['priority_bottom']) ? max(0, (int) $input['priority_bottom']) : $defs['priority_bottom'];

    return $out;
}

function aa_add_settings_page() {
    add_options_page(
        'Ajouter Auteur',
        'Ajouter Auteur',
        'manage_options',
        'ajouter-auteur',
        'aa_render_settings_page'
    );
}
add_action( 'admin_menu', 'aa_add_settings_page' );

function aa_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $opts = wp_parse_args( get_option('aa_settings', array()), aa_default_settings() );
    ?>
    <div class="wrap">
      <h1>Ajouter Auteur — Réglages</h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'aa_settings_group' ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Lien sur le nom de l’auteur</th>
            <td>
              <label>
                <input type="checkbox" name="aa_settings[author_link]" value="1" <?php checked( 1, (int) $opts['author_link'] ); ?> />
                Activer le lien vers la page auteur (/author/…)
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row">Afficher l’avatar</th>
            <td>
              <label>
                <input type="checkbox" name="aa_settings[show_avatar]" value="1" <?php checked( 1, (int) $opts['show_avatar'] ); ?> />
                Afficher l’avatar dans la boîte auteur
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row">JSON-LD</th>
            <td>
              <label>
                <input type="checkbox" name="aa_settings[force_jsonld]" value="1" <?php checked( 1, (int) $opts['force_jsonld'] ); ?> />
                Forcer l’impression du JSON-LD même si Yoast ou Rank Math sont actifs
              </label>
              <p class="description">Décocher si vous préférez laisser votre plugin SEO gérer le schéma.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Priorité — Insertion haut</th>
            <td>
              <input type="number" name="aa_settings[priority_top]" value="<?php echo esc_attr( (int) $opts['priority_top'] ); ?>" min="0" step="1" />
              <p class="description">Plus petit = affiché plus haut (défaut : 5)</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Priorité — Insertion bas</th>
            <td>
              <input type="number" name="aa_settings[priority_bottom]" value="<?php echo esc_attr( (int) $opts['priority_bottom'] ); ?>" min="0" step="1" />
              <p class="description">Plus grand = affiché plus bas (défaut : 20)</p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}
