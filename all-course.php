<?php
/**
 * Plugin Name: LD Courses List (LearnDash)
 * Description: Learn Dash Courses
 * Version: 1.0.0
 * Author: Pexelle
 */

if (!defined('ABSPATH')) { exit; }

class LD_Courses_List_Plugin {
    const MENU_SLUG = 'ld-courses-list';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu() {
        add_menu_page(
            __('LD Courses', 'ld-courses-list'),
            __('LD Courses', 'ld-courses-list'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-welcome-learn-more',
            58
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ld-courses-list'));
        }

        $courses = $this->get_all_courses();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('LearnDash Courses', 'ld-courses-list') . '</h1>';

        if (empty($courses)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No courses found. Make sure LearnDash is active and you have created courses.', 'ld-courses-list') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:10px;">';
        echo '<thead><tr>';
        echo '<th style="width:90px;">' . esc_html__('ID', 'ld-courses-list') . '</th>';
        echo '<th>' . esc_html__('Title', 'ld-courses-list') . '</th>';
        echo '<th style="width:220px;">' . esc_html__('Actions', 'ld-courses-list') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($courses as $c) {
            $edit_link = get_edit_post_link($c->ID, '');
            $view_link = get_permalink($c->ID);
            printf(
                '<tr>
                    <td><code>%d</code></td>
                    <td><strong>%s</strong></td>
                    <td>
                        <a class="button button-secondary" href="%s">%s</a>
                        <a class="button" target="_blank" rel="noopener" href="%s">%s</a>
                    </td>
                </tr>',
                intval($c->ID),
                esc_html(get_the_title($c)),
                esc_url($edit_link),
                esc_html__('Edit', 'ld-courses-list'),
                esc_url($view_link),
                esc_html__('View', 'ld-courses-list')
            );
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function get_all_courses() {
        $args = [
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => -1,
            'post_status'    => ['publish','draft','pending','private'],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'all',
            'suppress_filters' => false,
        ];
        $q = new WP_Query($args);
        return $q->posts;
    }
}

new LD_Courses_List_Plugin();
