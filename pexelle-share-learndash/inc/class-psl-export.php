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
            'permission_callback' => function(\WP_REST_Request $req){
                if ( is_user_logged_in() ) return true;

                $token = sanitize_text_field((string)$req->get_param('token'));
                $cid   = (int) $req->get_param('course_id');
                if (!$token || !$cid) return false;

                $rec = get_transient('psl_json_token_' . $token);
                if (!$rec) return false;
                if ((int)($rec['course_id'] ?? 0) !== $cid) return false;

                return true;
            },
            'callback' => [__CLASS__, 'handle_cert_json'],
            'args' => [
                'course_id' => [
                    'required'          => true,
                    'type'              => 'string', 
                    'sanitize_callback' => function($value){ return (string) preg_replace('/\D+/', '', (string) $value); },
                    'validate_callback' => function($value){ return (bool) absint($value); }, // >0
                ],
                'token' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ]
        ]);
    }

    public static function handle_cert_json($request) {
        nocache_headers();

        $course_id = absint( (string) $request->get_param('course_id') );
        if ( !$course_id || get_post_type($course_id) !== 'sfwd-courses' ) {
            return new \WP_REST_Response(['error' => 'Invalid course_id'], 400);
        }
        $token = sanitize_text_field((string) $request->get_param('token'));

        $effective_user_id = 0;
        $token_rec = null;
        if (is_user_logged_in()) {
            $effective_user_id = get_current_user_id();
        } elseif ($token) {
            $token_rec = get_transient('psl_json_token_' . $token);
            if (is_array($token_rec)
                && !empty($token_rec['user_id'])
                && (int)$token_rec['course_id'] === $course_id) {
                $effective_user_id = (int) $token_rec['user_id'];
            }
        }

        $impersonated = false;
        $prev_uid = get_current_user_id();
        if ($effective_user_id > 0 && $prev_uid !== $effective_user_id) {
            wp_set_current_user($effective_user_id);
            $impersonated = true;
        }
        $user_id = $effective_user_id;

        $course_post = get_post($course_id);
        $course = [
            'id'          => $course_id,
            'title'       => get_the_title($course_id),
            'excerpt'     => wp_strip_all_tags( get_the_excerpt($course_id) ),
            'description' => wp_strip_all_tags( $course_post ? $course_post->post_content : '' ),
        ];

        $cert_url = '';
        if ($user_id > 0 && function_exists('learndash_get_course_certificate_link')) {
            $cert_url = (string) learndash_get_course_certificate_link($course_id, $user_id);
            if (empty($cert_url) && function_exists('learndash_get_course_certificate_id')) {
                $cid = (int) learndash_get_course_certificate_id($course_id);
                if ($cid) {
                    $cert_url = (string) learndash_get_course_certificate_link($course_id, $user_id);
                }
            }
        }

        $progress = null;
        if ($user_id > 0 && function_exists('learndash_user_get_course_progress')) {
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
            try {
                $ld_args = [
                    'posts_per_page' => -1,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                ];
                $res = learndash_get_lesson_list($course_id, $user_id, $ld_args);
                if (is_wp_error($res) || $res === null) {
                    throw new \Exception('LD lesson list returned null/WP_Error');
                }
                $lessons = (array) $res;
            } catch (\Throwable $e) {
                $lessons = get_posts([
                    'post_type'   => 'sfwd-lessons',
                    'numberposts' => -1,
                    'orderby'     => 'menu_order',
                    'order'       => 'ASC',
                    'meta_query'  => [
                        [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ]
                    ]
                ]);
            }
        } else {
            $lessons = get_posts([
                'post_type'   => 'sfwd-lessons',
                'numberposts' => -1,
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
                'meta_query'  => [
                    [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ]
                ]
            ]);
        }

        foreach ($lessons as $lesson) {
            $lesson_id    = is_object($lesson) ? $lesson->ID : (int)$lesson;
            $lesson_title = get_the_title($lesson_id);

            $lesson_completed = false;
            if ($user_id > 0 && function_exists('learndash_is_lesson_complete')) {
                $lesson_completed = (bool) learndash_is_lesson_complete($user_id, $lesson_id, $course_id);
            }

            // topics
            $topics = [];
            $topics_raw = [];
            if (function_exists('learndash_get_topic_list')) {
                try {
                    $targs = [
                        'posts_per_page' => -1,
                        'orderby'        => 'menu_order',
                        'order'          => 'ASC',
                    ];
                    $res = learndash_get_topic_list($lesson_id, $course_id, $user_id, $targs);
                    if (is_wp_error($res) || $res === null) {
                        throw new \Exception('LD topic list returned null/WP_Error');
                    }
                    $topics_raw = (array) $res;
                } catch (\Throwable $e) {
                    $topics_raw = get_posts([
                        'post_type'   => 'sfwd-topic',
                        'numberposts' => -1,
                        'orderby'     => 'menu_order',
                        'order'       => 'ASC',
                        'meta_query'  => [
                            [ 'key' => 'lesson_id', 'value' => $lesson_id, 'compare' => '=' ],
                            [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ],
                        ]
                    ]);
                }
            } else {
                $topics_raw = get_posts([
                    'post_type'   => 'sfwd-topic',
                    'numberposts' => -1,
                    'orderby'     => 'menu_order',
                    'order'       => 'ASC',
                    'meta_query'  => [
                        [ 'key' => 'lesson_id', 'value' => $lesson_id, 'compare' => '=' ],
                        [ 'key' => 'course_id', 'value' => $course_id, 'compare' => '=' ],
                    ]
                ]);
            }

            foreach ($topics_raw as $topic) {
                $topic_id = is_object($topic) ? $topic->ID : (int)$topic;
                $topic_completed = false;
                if ($user_id > 0 && function_exists('learndash_is_topic_complete')) {
                    $topic_completed = (bool) learndash_is_topic_complete($user_id, $topic_id, $course_id);
                }
                $topics[] = [
                    'id'        => $topic_id,
                    'title'     => get_the_title($topic_id),
                    'completed' => $topic_completed,
                ];
            }

            // quizzes
            $quizzes = [];
            $lesson_quizzes = [];
            $course_quizzes = [];

            try {
                if (function_exists('learndash_get_lesson_quiz_list')) {
                    $lesson_quizzes = (array) learndash_get_lesson_quiz_list($lesson_id, $user_id, $course_id);
                }
                if (function_exists('learndash_get_course_quiz_list')) {
                    $course_quizzes = (array) learndash_get_course_quiz_list($course_id, $user_id);
                }
            } catch (\Throwable $e) {
                $lesson_quizzes = [];
                $course_quizzes = [];
            }

            $quiz_posts = [];
            $normalize_quiz_item = function($item) {
                if (is_array($item) && isset($item['post']) && is_object($item['post'])) return $item['post'];
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
                if ($user_id > 0 && function_exists('learndash_get_user_quiz_attempts')) {
    $attempts = learndash_get_user_quiz_attempts($user_id, $quiz_id, $course_id);

    if (is_array($attempts) && !empty($attempts)) {
        $last = end($attempts);

        $get = function($objOrArr, $key) {
            if (is_array($objOrArr)) {
                return array_key_exists($key, $objOrArr) ? $objOrArr[$key] : null;
            }
            if (is_object($objOrArr)) {
                return isset($objOrArr->{$key}) ? $objOrArr->{$key} : null;
            }
            return null;
        };

        $scoreVal   = $get($last, 'score');
        $percentVal = $get($last, 'percentage');
        $passVal    = $get($last, 'pass');

        $score   = is_numeric($scoreVal)   ? (float) $scoreVal   : null;
        $percent = is_numeric($percentVal) ? (float) $percentVal : null;
        $passed  = !is_null($passVal)      ? (bool)  $passVal    : null;
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

        $display_name = false;
        if ($user_id > 0) {
            $u = get_userdata($user_id);
            if ($u) $display_name = $u->display_name ?: ($u->user_login ?: $user_id);
        }

        $payload = [
            'user' => [
                'id'           => $user_id,
                'display_name' => $display_name,
            ],
            'certificate' => [
                'url' => $cert_url ?: null,
            ],
            'course' => $course + [
                'modules' => $modules,
            ],
        ];

        if ($impersonated) {
            if ($prev_uid > 0) {
                wp_set_current_user($prev_uid);
            } else {
                wp_set_current_user(0);
            }
        }

        if ($token) {
            delete_transient('psl_json_token_' . $token);
        }

        return new \WP_REST_Response($payload, 200);
    }
}
