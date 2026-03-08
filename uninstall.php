<?php
/**
 * Uninstall script for Dummy Content Filler Pro
 * 
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It removes all dummy content, meta data, taxonomy terms, and attachments
 * created by the plugin.
 *
 * @package Dummy_Content_Filler_Pro
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Main uninstall function that handles all cleanup
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_uninstall() {
    global $wpdb;
    
    // Set time limit to avoid timeout for large sites
    set_time_limit(300);
    
    // Step 1: Delete all dummy posts (including products and all post types)
    mc_dummy_content_filler_pro_delete_all_posts();
    
    // Step 2: Delete all dummy taxonomy terms
    mc_dummy_content_filler_pro_delete_all_terms();
    
    // Step 3: Delete all dummy attachments (images)
    mc_dummy_content_filler_pro_delete_all_attachments();
    
    // Step 4: Delete all dummy users
    mc_dummy_content_filler_pro_delete_all_users();
    
    // Step 5: Clean up orphaned meta data
    mc_dummy_content_filler_pro_cleanup_orphaned_meta();
}

/**
 * Delete all dummy posts across all post types
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_delete_all_posts() {
    global $wpdb;
    
    // Get all post IDs that have our dummy meta key
    $post_ids = $wpdb->get_col($wpdb->prepare("
        SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = %s 
        AND meta_value = '1'
    ", '_mc_dummy_content_filler_pro'));
    
    if (empty($post_ids)) {
        return;
    }
    
    // Delete posts in batches to avoid memory issues
    $batches = array_chunk($post_ids, 50);
    
    foreach ($batches as $batch) {
        foreach ($batch as $post_id) {
            // Force delete the post (bypass trash)
            wp_delete_post($post_id, true);
        }
        
        // Sleep briefly to avoid server overload
        usleep(500);
    }
    
    // Also delete any posts directly (in case meta lookup missed some)
    $wpdb->delete(
        $wpdb->posts,
        ['post_type' => 'product'],
        ['%s']
    );
}

/**
 * Delete all dummy taxonomy terms
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_delete_all_terms() {
    global $wpdb;
    
    // Get all term IDs that have our dummy meta key
    $term_ids = $wpdb->get_col($wpdb->prepare("
        SELECT term_id 
        FROM {$wpdb->termmeta} 
        WHERE meta_key = %s 
        AND meta_value = '1'
    ", '_mc_dummy_content_filler_pro'));
    
    if (empty($term_ids)) {
        return;
    }
    
    // Get taxonomy for each term and delete
    foreach ($term_ids as $term_id) {
        $term = get_term($term_id);
        if ($term && !is_wp_error($term)) {
            wp_delete_term($term_id, $term->taxonomy);
        }
    }
    
    // Clean up orphaned term relationships
    $wpdb->query("
        DELETE tr FROM {$wpdb->term_relationships} tr
        LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
        WHERE p.ID IS NULL
    ");
}

/**
 * Delete all dummy attachments (images)
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_delete_all_attachments() {
    // Get all attachments that might be from our dummy content
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_query'     => [
            [
                'key'     => '_mc_dummy_content_filler_pro',
                'value'   => '1',
                'compare' => '='
            ]
        ],
        'fields'         => 'ids'
    ]);
    
    // Alternative: If we don't mark attachments, look for our naming pattern
    if (empty($attachments)) {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            's'              => 'dummy_content_filler_',
            'fields'         => 'ids'
        ]);
    }
    
    if (!empty($attachments)) {
        foreach ($attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }
    
    // Delete any uploaded dummy images from uploads folder
    $upload_dir = wp_upload_dir();
    $dummy_images = glob($upload_dir['basedir'] . '/*dummy_content_filler_*.{jpg,jpeg,png,gif}', GLOB_BRACE);
    
    foreach ($dummy_images as $image_path) {
        if (file_exists($image_path)) {
            unlink($image_path);
        }
    }
}

/**
 * Delete all dummy users
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_delete_all_users() {
    // Get all dummy users (except admin ID 1)
    $dummy_users = get_users([
        'meta_key'   => '_mc_dummy_content_filler_pro',
        'meta_value' => '1',
        'exclude'    => [1] // Exclude main admin
    ]);
    
    foreach ($dummy_users as $user) {
        wp_delete_user($user->ID);
    }
    
    // Also delete any users with dummy email patterns
    $potential_dummy_users = get_users([
        'search'         => '*@example.com',
        'search_columns' => ['user_email'],
        'exclude'        => [1]
    ]);
    
    foreach ($potential_dummy_users as $user) {
        // Double-check if it's likely a dummy user
        if (strpos($user->user_login, 'dummyuser_') === 0 || 
            strpos($user->user_email, '@example.com') !== false) {
            wp_delete_user($user->ID);
        }
    }
}

/**
 * Clean up orphaned meta data
 *
 * @since 1.0.0
 * @return void
 */
function mc_dummy_content_filler_pro_cleanup_orphaned_meta() {
    global $wpdb;
    
    // Delete orphaned post meta
    $wpdb->query("
        DELETE pm FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
        AND pm.meta_key = '_mc_dummy_content_filler_pro'
    ");
    
    // Delete orphaned user meta
    $wpdb->query("
        DELETE um FROM {$wpdb->usermeta} um
        LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
        WHERE u.ID IS NULL
        AND um.meta_key = '_mc_dummy_content_filler_pro'
    ");
    
    // Delete orphaned term meta
    $wpdb->query("
        DELETE tm FROM {$wpdb->termmeta} tm
        LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
        WHERE t.term_id IS NULL
        AND tm.meta_key = '_mc_dummy_content_filler_pro'
    ");
    
    // Delete orphaned comment meta (if any dummy comments exist)
    $wpdb->query("
        DELETE cm FROM {$wpdb->commentmeta} cm
        LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
        WHERE c.comment_ID IS NULL
        AND cm.meta_key = '_mc_dummy_content_filler_pro'
    ");
}

// Run the uninstall
mc_dummy_content_filler_pro_uninstall();