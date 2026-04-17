<?php
/**
 * Plugin Name: RMG Elementor Templates
 * Plugin URI:  https://ripmediagroup.com
 * Description: Adds Rip Media Group templates directly to your Elementor library. No Pro required. Activate and find them under Templates → Saved Templates.
 * Version:     1.0.0
 * Author:      Rip Media Group
 * Author URI:  https://ripmediagroup.com
 * Text Domain: rmg-templates
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RMG_TPL_DIR',      plugin_dir_path( __FILE__ ) . 'templates/' );
define( 'RMG_TPL_META_KEY', '_rmg_template_slug' );

// ============================================================
// Activation hook — insert templates the moment plugin activates
// ============================================================
register_activation_hook( __FILE__, 'rmg_tpl_activate' );
function rmg_tpl_activate() {
    rmg_tpl_insert_all();
}

// ============================================================
// Admin menu — shows status + re-import button
// ============================================================
add_action( 'admin_menu', function () {
    add_submenu_page(
        'elementor',
        'RMG Templates',
        'RMG Templates',
        'manage_options',
        'rmg-templates',
        'rmg_tpl_admin_page'
    );
} );

function rmg_tpl_admin_page() {
    // Handle re-import action
    if (
        isset( $_POST['rmg_reimport'] ) &&
        check_admin_referer( 'rmg_reimport_action' )
    ) {
        rmg_tpl_delete_all();
        rmg_tpl_insert_all();
        echo '<div class="notice notice-success"><p><strong>RMG Templates re-imported successfully.</strong> Find them under Templates → Saved Templates.</p></div>';
    }

    $templates = rmg_tpl_list();
    ?>
    <div class="wrap">
        <h1>RMG Elementor Templates</h1>
        <p>These templates are registered in your Elementor library. Go to <strong>Templates → Saved Templates</strong> to insert them on any page.</p>

        <table class="widefat fixed striped" style="max-width:700px;margin-top:20px;">
            <thead>
                <tr>
                    <th>Template Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Edit in Elementor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $templates as $slug => $post_id ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $slug ); ?></strong></td>
                    <td>section</td>
                    <td><?php echo $post_id ? '<span style="color:green">&#10003; Imported</span>' : '<span style="color:red">&#10005; Missing</span>'; ?></td>
                    <td>
                        <?php if ( $post_id ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post_id . '&action=elementor' ) ); ?>" target="_blank">Open in Elementor &rarr;</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" style="margin-top:24px;">
            <?php wp_nonce_field( 'rmg_reimport_action' ); ?>
            <input type="hidden" name="rmg_reimport" value="1">
            <?php submit_button( 'Re-Import All Templates', 'secondary', 'submit', false ); ?>
        </form>

        <p style="color:#666;margin-top:12px;font-size:13px;">
            Re-importing will delete existing RMG templates and recreate them fresh.
        </p>
    </div>
    <?php
}

// ============================================================
// Core: insert all templates from /templates/*.json
// ============================================================
function rmg_tpl_insert_all() {
    if ( ! is_dir( RMG_TPL_DIR ) ) return;

    $files = glob( RMG_TPL_DIR . '*.json' );
    if ( empty( $files ) ) return;

    foreach ( $files as $file ) {
        $slug    = basename( $file, '.json' );
        $content = file_get_contents( $file );
        $data    = json_decode( $content, true );

        if ( empty( $data ) || ! isset( $data['content'] ) ) continue;

        // Skip if already exists
        $existing = rmg_tpl_get_by_slug( $slug );
        if ( $existing ) continue;

        $title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $slug;
        $type  = isset( $data['type'] )  ? sanitize_key( $data['type'] )          : 'section';

        $post_id = wp_insert_post( [
            'post_title'  => $title,
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
            'meta_input'  => [
                '_elementor_data'          => wp_slash( json_encode( $data['content'] ) ),
                '_elementor_template_type' => $type,
                '_elementor_edit_mode'     => 'builder',
                '_elementor_page_settings' => isset( $data['page_settings'] ) ? $data['page_settings'] : [],
                '_elementor_version'       => '0.4',
                RMG_TPL_META_KEY           => $slug,
            ],
        ] );

        // Set the Elementor library taxonomy term
        if ( ! is_wp_error( $post_id ) ) {
            $term = get_term_by( 'slug', $type, 'elementor_library_type' );
            if ( $term ) {
                wp_set_object_terms( $post_id, $term->term_id, 'elementor_library_type' );
            } else {
                wp_set_object_terms( $post_id, $type, 'elementor_library_type' );
            }
        }
    }
}

// ============================================================
// Core: delete all RMG-inserted templates
// ============================================================
function rmg_tpl_delete_all() {
    $posts = get_posts( [
        'post_type'      => 'elementor_library',
        'post_status'    => 'any',
        'meta_key'       => RMG_TPL_META_KEY,
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
}

// ============================================================
// Helper: get post ID for a given slug
// ============================================================
function rmg_tpl_get_by_slug( $slug ) {
    $posts = get_posts( [
        'post_type'      => 'elementor_library',
        'post_status'    => 'any',
        'meta_key'       => RMG_TPL_META_KEY,
        'meta_value'     => $slug,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    return ! empty( $posts ) ? $posts[0] : null;
}

// ============================================================
// Helper: list all template slugs and their post IDs
// ============================================================
function rmg_tpl_list() {
    $files  = glob( RMG_TPL_DIR . '*.json' );
    $result = [];

    foreach ( $files as $file ) {
        $slug            = basename( $file, '.json' );
        $result[ $slug ] = rmg_tpl_get_by_slug( $slug );
    }

    return $result;
}
