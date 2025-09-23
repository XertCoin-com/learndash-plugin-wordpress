<?php
namespace PSL;
if (!defined('ABSPATH')) { exit; }

final class Psl_Export {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
    }

    public static function register_rest() {
        register_rest_route('psl/v1', '/cert-json', [
            'methods'  => 'GET',
            // 'permission_callback' => function() {
            //     return is_user_logged_in();
            // },
            'permission_callback' => '__return_true',
            'callback' => [__CLASS__, 'handle_cert_json'],
            'args' => [
                'course_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ]
            ]
        ]);
    }

    public static function handle_cert_json($request) {
        $user_id   = get_current_user_id();
        $course_id = (int) $request->get_param('course_id');
        if (!$course_id || get_post_type($course_id) !== 'sfwd-courses') {
            return new \WP_REST_Response(['error' => 'Invalid course_id'], 400);
        }

        $cert_url = '';
        if (function_exists('learndash_get_course_certificate_link')) {
            $cert_url = (string) learndash_get_course_certificate_link($course_id, $user_id);
        }

        $course_post = get_post($course_id);
        $course = [
            'id'          => $course_id,
            'title'       => get_the_title($course_id),
            'excerpt'     => wp_strip_all_tags( get_the_excerpt($course_id) ),
            'description' => wp_strip_all_tags( $course_post ? $course_post->post_content : '' ),
        ];

        $progress = null;
        if (function_exists('learndash_user_get_course_progress')) {
            $pg = learndash_user_get_course_progress($user_id, $course_id, true);
            if (is_array($pg)) {
                $progress = [
                    'percent'   => isset($pg['percentage']) ? (float) $pg['percentage'] : null,
                    'completed' => isset($pg['completed'])  ? (int) $pg['completed']  : null,
                    'total'     => isset($pg['total'])      ? (int) $pg['total']      : null,
                    'status'    => isset($pg['status'])     ? (string) $pg['status']  : null,
                ];
            }
        }
        $course['progress'] = $progress;

        $modules = [];
        $lessons = [];
        if (function_exists('learndash_get_lesson_list')) {
            $lessons = (array) learndash_get_lesson_list($course_id, $user_id);
        } else {
            $lessons = get_posts([
                'post_type' => 'sfwd-lessons',
                'numberposts' => -1,
                'orderby' => 'menu_order',
                'order'   => 'ASC',
                'meta_query' => [
                    [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ]
                ]
            ]);
        }

        foreach ($lessons as $lesson) {
            $lesson_id = is_object($lesson) ? $lesson->ID : (int)$lesson;
            $lesson_title = get_the_title($lesson_id);

            $lesson_completed = false;
            if (function_exists('learndash_is_lesson_complete')) {
                $lesson_completed = (bool) learndash_is_lesson_complete($user_id, $lesson_id, $course_id);
            }

            $topics = [];
            $topics_raw = [];
            if (function_exists('learndash_get_topic_list')) {
                $topics_raw = (array) learndash_get_topic_list($lesson_id, $course_id, $user_id);
            } else {
                $topics_raw = get_posts([
                    'post_type' => 'sfwd-topic',
                    'numberposts' => -1,
                    'orderby' => 'menu_order',
                    'order'   => 'ASC',
                    'meta_query' => [
                        [ 'key' => 'lesson_id', 'value' => $lesson_id, 'compare' => '=' ],
                        [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ],
                    ]
                ]);
            }

            foreach ($topics_raw as $topic) {
                $topic_id = is_object($topic) ? $topic->ID : (int)$topic;
                $topic_completed = false;
                if (function_exists('learndash_is_topic_complete')) {
                    $topic_completed = (bool) learndash_is_topic_complete($user_id, $topic_id, $course_id);
                }
                $topics[] = [
                    'id'        => $topic_id,
                    'title'     => get_the_title($topic_id),
                    'completed' => $topic_completed,
                ];
            }

            $quizzes = [];
            $lesson_quizzes = [];
            if (function_exists('learndash_get_lesson_quiz_list')) {
                $lesson_quizzes = (array) learndash_get_lesson_quiz_list($lesson_id, $user_id, $course_id);
            }
            $course_quizzes = [];
            if (function_exists('learndash_get_course_quiz_list')) {
                $course_quizzes = (array) learndash_get_course_quiz_list($course_id, $user_id);
            }
            $quiz_posts = [];

            $normalize_quiz_item = function($item) {
                if (is_array($item) && isset($item['post'])) return $item['post'];
                if (is_object($item) && isset($item->ID)) return $item;
                return null;
            };
            foreach ($lesson_quizzes as $it) {
                $p = $normalize_quiz_item($it);
                if ($p && get_post_type($p) === 'sfwd-quiz') $quiz_posts[$p->ID] = $p;
            }
            foreach ($course_quizzes as $it) {
                $p = $normalize_quiz_item($it);
                if ($p && get_post_type($p) === 'sfwd-quiz') $quiz_posts[$p->ID] = $p;
            }

            foreach ($quiz_posts as $quiz_id => $qp) {
                $score = null; $passed = null; $percent = null;
                if (function_exists('learndash_get_user_quiz_attempts')) {
                    $attempts = learndash_get_user_quiz_attempts($user_id, $quiz_id, $course_id);
                    if (is_array($attempts) && !empty($attempts)) {
                        $last = end($attempts);
                        $score   = isset($last['score'])   ? (float) $last['score']   : null;
                        $percent = isset($last['percentage']) ? (float) $last['percentage'] : null;
                        $passed  = isset($last['pass'])    ? (bool)  $last['pass']    : null;
                    }
                }
                $quizzes[] = [
                    'id'       => (int) $quiz_id,
                    'title'    => get_the_title($quiz_id),
                    'score'    => $score,
                    'percent'  => $percent,
                    'passed'   => $passed,
                ];
            }

            $modules[] = [
                'id'            => $lesson_id,
                'title'         => $lesson_title,
                'completed'     => $lesson_completed,
                'topics_count'  => count($topics),
                'topics'        => $topics,
                'quizzes'       => array_values($quizzes),
            ];
        }

        $payload = [
            'user' => [
                'id'           => $user_id,
                'display_name' => wp_get_current_user()->display_name,
            ],
            'certificate' => [
                'url' => $cert_url ?: null,
            ],
            'course' => $course + [
                'modules' => $modules,
            ],
        ];

        return new \WP_REST_Response($payload, 200);
    }
}
