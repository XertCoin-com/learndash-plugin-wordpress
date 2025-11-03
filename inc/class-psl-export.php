<?php
namespace PSL;
if (!defined('ABSPATH')) { exit; }

final class Psl_Export {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
    }

    public static function register_rest() {
        // Legacy: single course
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
                'use_html' => [
                    'required' => false,
                    'type'     => 'boolean',
                ],
            ]
        ]);

        // NEW: all courses for the effective user
        register_rest_route('psl/v1', '/cert-json-all', [
            'methods'  => 'GET',
            'permission_callback' => function(\WP_REST_Request $req){
                if ( is_user_logged_in() ) return true;

                $token = sanitize_text_field((string)$req->get_param('token'));
                if (!$token) return false;

                $rec = get_transient('psl_json_token_' . $token);
                if (!$rec) return false;
                // only check that user_id is present; course_id may be 0 for "all"
                if (empty($rec['user_id'])) return false;

                return true;
            },
            'callback' => [__CLASS__, 'handle_all_json'],
            'args' => [
                'token' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'use_html' => [
                    'required' => false,
                    'type'     => 'boolean',
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
        $token    = sanitize_text_field((string) $request->get_param('token'));
        $use_html = (bool) $request->get_param('use_html');

        $effective_user_id = 0;
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

        $payload = self::build_course_payload($course_id, $user_id, $use_html);

        if ($impersonated) {
            if ($prev_uid > 0) { wp_set_current_user($prev_uid); } else { wp_set_current_user(0); }
        }

        if ($token) {
            delete_transient('psl_json_token_' . $token);
        }

        return new \WP_REST_Response($payload, 200);
    }

    public static function handle_all_json($request) {
        nocache_headers();

        $token    = sanitize_text_field((string) $request->get_param('token'));
        $use_html = (bool) $request->get_param('use_html');

        $effective_user_id = 0;
        if (is_user_logged_in()) {
            $effective_user_id = get_current_user_id();
        } elseif ($token) {
            $token_rec = get_transient('psl_json_token_' . $token);
            if (is_array($token_rec) && !empty($token_rec['user_id'])) {
                $effective_user_id = (int) $token_rec['user_id'];
            }
        }
        if ($effective_user_id <= 0) {
            return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        $impersonated = false;
        $prev_uid = get_current_user_id();
        if ($prev_uid !== $effective_user_id) {
            wp_set_current_user($effective_user_id);
            $impersonated = true;
        }
        $user_id = $effective_user_id;

        // Get all enrolled course IDs for the user
        $course_ids = [];
        if (function_exists('learndash_user_get_enrolled_courses')) {
            $course_ids = (array) learndash_user_get_enrolled_courses($user_id);
        }
        if (empty($course_ids)) {
            // fallback: query authored/enrolled courses heuristically
            $course_ids = get_posts([
                'post_type'   => 'sfwd-courses',
                'numberposts' => -1,
                'fields'      => 'ids',
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
        }

        $courses_payload = [];
        $modules_total = 0;
        $topics_total  = 0;

        foreach ($course_ids as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0 || get_post_type($cid) !== 'sfwd-courses') continue;

            $one = self::build_course_payload($cid, $user_id, $use_html);
            if (is_array($one)) {
                $courses_payload[] = $one;
                // aggregate totals
                $meta = $one['meta'] ?? [];
                $modules_total += (int)($meta['modules_count'] ?? 0);
                $topics_total  += (int)($meta['topics_count'] ?? 0);
            }
        }

        // user display name
        $display_name = false;
        $u = get_userdata($user_id);
        if ($u) $display_name = $u->display_name ?: ($u->user_login ?: $user_id);

        if ($impersonated) {
            if ($prev_uid > 0) { wp_set_current_user($prev_uid); } else { wp_set_current_user(0); }
        }

        if ($token) {
            delete_transient('psl_json_token_' . $token);
        }

        $out = [
            'meta' => [
                'courses_count' => count($courses_payload),
                'modules_count' => $modules_total,
                'topics_count'  => $topics_total,
                'generated_at'  => gmdate('c'),
            ],
            'user' => [
                'id'           => $user_id,
                'display_name' => $display_name,
            ],
            'courses' => $courses_payload,
        ];

        return new \WP_REST_Response($out, 200);
    }

    /* ---------- Helpers ---------- */

    /** Build payload for a single course, preserving existing logic */
    private static function build_course_payload(int $course_id, int $user_id, bool $use_html) {
        $course_post = get_post($course_id);
        if (!$course_post) return null;

        $course = [
            'id'          => $course_id,
            'title'       => get_the_title($course_id),
            'excerpt'     => $use_html ? get_the_excerpt($course_id) : wp_strip_all_tags( get_the_excerpt($course_id) ),
            'description' => $use_html
                ? ( $course_post ? (string)$course_post->post_content : '' )
                : wp_strip_all_tags( $course_post ? $course_post->post_content : '' ),
        ];

        $course_created = self::iso_gmt( get_post_field('post_date_gmt', $course_id) );
        $course_updated = self::iso_gmt( get_post_field('post_modified_gmt', $course_id) );

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

        // lessons
        $lessons = [];
        if (function_exists('learndash_get_lesson_list')) {
            try {
                $ld_args = [
                    'posts_per_page' => -1,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                ];
                $res = learndash_get_lesson_list($course_id, $user_id, $ld_args);
                if (is_wp_error($res) || $res === null) throw new \Exception('LD lesson list returned null/WP_Error');
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

        $modules = [];
        foreach ($lessons as $lesson) {
            $lesson_id    = is_object($lesson) ? $lesson->ID : (int)$lesson;
            $lesson_title = get_the_title($lesson_id);
            $lesson_raw   = (string) get_post_field('post_content', $lesson_id);
            $lesson_desc  = $use_html ? $lesson_raw : wp_strip_all_tags( $lesson_raw );

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
                    if (is_wp_error($res) || $res === null) throw new \Exception('LD topic list returned null/WP_Error');
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
                $topic_id    = is_object($topic) ? $topic->ID : (int)$topic;
                $topic_title = get_the_title($topic_id);
                $topic_raw   = (string) get_post_field('post_content', $topic_id);
                $topic_desc  = $use_html ? $topic_raw : wp_strip_all_tags( $topic_raw );

                $topic_completed = false;
                if ($user_id > 0 && function_exists('learndash_is_topic_complete')) {
                    $topic_completed = (bool) learndash_is_topic_complete($user_id, $topic_id, $course_id);
                }
                $topics[] = [
                    'id'          => $topic_id,
                    'title'       => $topic_title,
                    'description' => $topic_desc,
                    'completed'   => $topic_completed,
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
                $score = null; $percent = null; $passed = null;

                if ($user_id > 0 && function_exists('learndash_get_user_quiz_attempts')) {
                    $attempts = learndash_get_user_quiz_attempts($user_id, $quiz_id, $course_id);
                    if (is_array($attempts) && !empty($attempts)) {
                        $last = end($attempts);
                        $s = self::extract_quiz_stats($last);
                        $score = $s['score']; $percent = $s['percent']; $passed = $s['passed'];
                    }
                }

                if ($score === null && $percent === null && $passed === null) {
                    $user_quizzes = is_user_logged_in() ? get_user_meta($user_id, '_sfwd-quizzes', true) : [];
                    if (is_array($user_quizzes) && !empty($user_quizzes)) {
                        krsort($user_quizzes);
                        foreach ($user_quizzes as $uq) {
                            if (isset($uq['quiz']) && (int)$uq['quiz'] === (int)$quiz_id) {
                                $score   = isset($uq['score']) ? (float)$uq['score'] : (isset($uq['points']) ? (float)$uq['points'] : null);
                                $percent = isset($uq['percentage']) ? (float)$uq['percentage'] : null;
                                $passed  = array_key_exists('pass', $uq) ? (bool)$uq['pass'] : null;

                                $points_max = isset($uq['total_points']) ? (float)$uq['total_points'] : (isset($uq['points_max']) ? (float)$uq['points_max'] : null);
                                $correct    = isset($uq['count']) ? (float)$uq['count'] : null;
                                $total      = isset($uq['question_show_count']) ? (float)$uq['question_show_count'] : (isset($uq['total']) ? (float)$uq['total'] : null);

                                if ($percent === null) {
                                    if ($score !== null && $points_max && $points_max > 0) {
                                        $percent = 100.0 * $score / $points_max;
                                    } elseif ($correct !== null && $total && $total > 0) {
                                        $percent = 100.0 * $correct / $total;
                                        if ($score === null) $score = $correct;
                                    }
                                }
                                break;
                            }
                        }
                    }
                }

                $quizzes[] = [
                    'id'       => (int)$quiz_id,
                    'title'    => get_the_title($quiz_id),
                    'score'    => is_numeric($score) ? (float)$score : null,
                    'percent'  => is_numeric($percent) ? (float)$percent : null,
                    'passed'   => is_null($passed) ? null : (bool)$passed,
                ];
            }

            $modules[] = [
                'id'            => $lesson_id,
                'title'         => $lesson_title,
                'description'   => $lesson_desc,
                'completed'     => $lesson_completed,
                'topics_count'  => count($topics),
                'topics'        => $topics,
                'quizzes'       => array_values($quizzes),
            ];
        }

        $modules_count = count($modules);
        $topics_count  = 0;
        foreach ($modules as $m) {
            $topics_count += (int)($m['topics_count'] ?? 0);
        }

        // Keep the previous “truncate to first module/topic” behavior if present in original
        // if (!empty($modules)) {
        //     $firstModule = $modules[0];
        //     if (!empty($firstModule['topics'])) {
        //         $firstModule['topics'] = [$firstModule['topics'][0]];
        //         $firstModule['topics_count'] = 1;
        //     }
        //     $modules = [$firstModule];
        //     $modules_count = 1;
        //     $topics_count  = !empty($firstModule['topics']) ? 1 : 0;
        // }

        return [
            'meta' => [
                'course_id'     => $course_id,
                'modules_count' => $modules_count,
                'topics_count'  => $topics_count,
                'created_at'    => self::iso_gmt( get_post_field('post_date_gmt', $course_id) ),
                'updated_at'    => self::iso_gmt( get_post_field('post_modified_gmt', $course_id) ),
            ],
            'certificate' => [
                'url' => $cert_url ?: null,
            ],
            'course' => $course + [
                'modules' => $modules,
            ],
        ];
    }

    private static function iso_gmt($post_field_gmt) {
        $v = (string) $post_field_gmt;
        if (!$v) return null;
        try {
            $ts = strtotime($v);
            return $ts ? gmdate('c', $ts) : null; // ISO-8601 in GMT
        } catch (\Throwable $e) { return null; }
    }

    private static function extract_quiz_stats($attempt) {
        $get = function($src, $key) {
            if (is_array($src))   return array_key_exists($key, $src) ? $src[$key] : null;
            if (is_object($src))  return isset($src->{$key}) ? $src->{$key} : null;
            return null;
        };

        $score   = $get($attempt, 'score');
        $percent = $get($attempt, 'percentage');
        $passed  = $get($attempt, 'pass');

        $points      = $get($attempt, 'points')       ?? $get($attempt, 'earned_points') ?? $get($attempt, 'quiz_score');
        $points_max  = $get($attempt, 'points_max')   ?? $get($attempt, 'total_points')  ?? $get($attempt, 'quiz_mark');
        $correct     = $get($attempt, 'count');
        $total       = $get($attempt, 'question_count') ?? $get($attempt, 'total');

        if (!is_numeric($percent)) {
            if (is_numeric($score) && is_numeric($points_max) && $points_max > 0) {
                $percent = 100.0 * ((float)$score) / ((float)$points_max);
            } elseif (is_numeric($points) && is_numeric($points_max) && $points_max > 0) {
                $percent = 100.0 * ((float)$points) / ((float)$points_max);
                if (!is_numeric($score)) $score = (float)$points;
            } elseif (is_numeric($correct) && is_numeric($total) && $total > 0) {
                $percent = 100.0 * ((float)$correct) / ((float)$total);
                if (!is_numeric($score)) $score = (float)$correct;
            }
        }

        if (!is_bool($passed) && !is_null($passed)) {
            $passed = (bool) $passed;
        } elseif (is_null($passed)) {
            $passed = null;
        }

        return [
            'score'   => is_numeric($score)   ? (float)$score   : null,
            'percent' => is_numeric($percent) ? (float)$percent : null,
            'passed'  => is_null($passed) ? null : (bool)$passed,
        ];
    }
}
