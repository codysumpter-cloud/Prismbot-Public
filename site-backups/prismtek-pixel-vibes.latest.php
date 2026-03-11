<?php
/**
 * Plugin Name: Prismtek Pixel Vibes
 * Description: Pixel-art indie styling + day/night weather scene + arcade/chat/pixel-wall hub.
 */

if (!defined('ABSPATH')) {
    exit;
}

function prismtek_pixel_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return preg_replace('/[^0-9a-fA-F:\.]/', '', (string) $ip);
}

function prismtek_pixel_get_chat_messages() {
    $rows = get_option('prismtek_pixel_chat_messages', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_pixel_set_chat_messages($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_pixel_chat_messages', array_slice($rows, -160), false);
}

function prismtek_pixel_get_wall_items() {
    $rows = get_option('prismtek_pixel_wall_items', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_pixel_set_wall_items($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_pixel_wall_items', array_slice($rows, -180), false);
}

function prismtek_pixel_get_scores() {
    $rows = get_option('prismtek_pixel_scores', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_pixel_set_scores($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_pixel_scores', $rows, false);
}


function prismtek_pixel_get_game_meta() {
    $rows = get_option('prismtek_game_meta', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_pixel_set_game_meta($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_game_meta', $rows, false);
}


function prismtek_pet_default_state() {
    return [
        'name' => 'Prismo',
        'species' => 'blob',
        'skin' => 'default',
        'createdTs' => time(),
        'hunger' => 75,
        'happiness' => 70,
        'energy' => 80,
        'health' => 85,
        'lastTs' => time(),
    ];
}

function prismtek_pet_apply_decay($state) {
    if (!is_array($state)) $state = prismtek_pet_default_state();
    $now = time();
    $last = (int)($state['lastTs'] ?? $now);
    $elapsed = max(0, $now - $last);
    $hours = $elapsed / 3600.0;

    $state['hunger'] = max(0, min(100, (int)round(($state['hunger'] ?? 75) - 6 * $hours)));
    $state['happiness'] = max(0, min(100, (int)round(($state['happiness'] ?? 70) - 4 * $hours)));
    $state['energy'] = max(0, min(100, (int)round(($state['energy'] ?? 80) - 5 * $hours)));

    $baseHealth = (int)($state['health'] ?? 85);
    $penalty = 0;
    if (($state['hunger'] ?? 0) < 25) $penalty += 4;
    if (($state['energy'] ?? 0) < 20) $penalty += 4;
    if (($state['happiness'] ?? 0) < 20) $penalty += 3;
    $state['health'] = max(0, min(100, (int)round($baseHealth - $penalty * $hours)));

    $state['lastTs'] = $now;
    return $state;
}

function prismtek_pet_get_state($uid) {
    $state = get_user_meta((int)$uid, 'prismtek_pet_state', true);
    if (!is_array($state) || empty($state)) $state = prismtek_pet_default_state();
    $state = prismtek_pet_apply_decay($state);
    update_user_meta((int)$uid, 'prismtek_pet_state', $state);
    return $state;
}

function prismtek_pet_set_state($uid, $state) {
    if (!is_array($state)) $state = prismtek_pet_default_state();
    if (empty($state['createdTs'])) $state['createdTs'] = time();
    $state['lastTs'] = time();
    update_user_meta((int)$uid, 'prismtek_pet_state', $state);
}

function prismtek_pet_compute_stage($state) {
    $created = (int)($state['createdTs'] ?? time());
    $days = max(0, floor((time() - $created) / 86400));
    $care = ((int)($state['hunger'] ?? 0) + (int)($state['happiness'] ?? 0) + (int)($state['energy'] ?? 0) + (int)($state['health'] ?? 0)) / 4;
    if ($days >= 7 && $care >= 70) return 'adult';
    if ($days >= 2 && $care >= 45) return 'teen';
    return 'baby';
}

function prismtek_pet_get_unlocks($uid) {
    $scores = prismtek_pixel_get_scores();
    $max = 0;
    foreach ($scores as $game => $rows) {
        if (!is_array($rows)) continue;
        foreach ($rows as $r) {
            if ((int)($r['userId'] ?? 0) === (int)$uid) {
                $max = max($max, (int)($r['score'] ?? 0));
            }
        }
    }
    $skins = ['default'];
    if ($max >= 50) $skins[] = 'mint';
    if ($max >= 150) $skins[] = 'sunset';
    if ($max >= 300) $skins[] = 'galaxy';
    if ($max >= 600) $skins[] = 'neon';
    return ['maxScore' => $max, 'skins' => $skins];
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/session', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            nocache_headers();
            $uid = get_current_user_id();
            return rest_ensure_response([
                'ok' => true,
                'loggedIn' => (bool) $uid,
                'canModerate' => current_user_can('manage_options'),
                'nonce' => $uid ? wp_create_nonce('wp_rest') : '',
                'user' => $uid ? wp_get_current_user()->display_name : '',
                'userId' => $uid ? (int)$uid : 0,
            ]);
        },
    ]);

    register_rest_route('prismtek/v1', '/chat', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            return rest_ensure_response([
                'ok' => true,
                'messages' => array_slice(prismtek_pixel_get_chat_messages(), -80),
            ]);
        },
    ]);

    register_rest_route('prismtek/v1', '/chat', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $ip = prismtek_pixel_client_ip();
            $key = 'prismtek_chat_rate_' . md5($ip);
            if (get_transient($key)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            set_transient($key, '1', 2);

            $name = sanitize_text_field((string) $request->get_param('name'));
            $text = sanitize_textarea_field((string) $request->get_param('message'));
            $age13 = (bool) $request->get_param('age13');
            if (!$age13) return new WP_REST_Response(['ok' => false, 'error' => 'age_consent_required'], 400);
            $name = trim($name ?: 'Community Member');
            $text = trim($text);
            if ($text === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_message'], 400);

            $row = [
                'id' => wp_generate_uuid4(),
                'name' => mb_substr($name, 0, 24),
                'message' => mb_substr($text, 0, 280),
                'replyTo' => sanitize_text_field((string)$request->get_param('replyTo')),
                'reactions' => [],
                'userId' => get_current_user_id() ? (int)get_current_user_id() : 0,
                'ts' => time(),
            ];

            $rows = prismtek_pixel_get_chat_messages();
            $rows[] = $row;
            prismtek_pixel_set_chat_messages($rows);

            return rest_ensure_response(['ok' => true, 'message' => $row]);
        },
    ]);

    register_rest_route('prismtek/v1', '/pixel-wall', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            return rest_ensure_response([
                'ok' => true,
                'items' => array_reverse(array_slice(prismtek_pixel_get_wall_items(), -120)),
            ]);
        },
    ]);

    register_rest_route('prismtek/v1', '/chat/react', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $id = sanitize_text_field((string)$request->get_param('id'));
            $key = sanitize_key((string)$request->get_param('emoji'));
            $allowed = ['up','heart','fire'];
            if ($id === '' || !in_array($key, $allowed, true)) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_payload'],400);
            $uid = get_current_user_id();
            $reactor = $uid ? ('u:' . (int)$uid) : ('i:' . substr(md5(prismtek_pixel_client_ip()), 0, 16));

            $rows = prismtek_pixel_get_chat_messages();
            foreach ($rows as &$r) {
                if (($r['id'] ?? '') !== $id) continue;

                $re = is_array($r['reactions'] ?? null) ? $r['reactions'] : [];
                $ru = is_array($r['reactionUsers'] ?? null) ? $r['reactionUsers'] : [];
                $users = is_array($ru[$key] ?? null) ? $ru[$key] : [];

                if (in_array($reactor, $users, true)) {
                    return rest_ensure_response(['ok'=>true,'duplicate'=>true,'reactions'=>$re]);
                }

                $users[] = $reactor;
                $ru[$key] = $users;
                $re[$key] = count($users);

                $r['reactionUsers'] = $ru;
                $r['reactions'] = $re;
                prismtek_pixel_set_chat_messages($rows);
                return rest_ensure_response(['ok'=>true,'reactions'=>$re]);
            }
            unset($r);
            return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
        },
    ]);

    register_rest_route('prismtek/v1', '/chat/(?P<id>[a-zA-Z0-9\-]+)', [
        'methods' => 'DELETE',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $id = sanitize_text_field((string)$request['id']);
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

            $rows = prismtek_pixel_get_chat_messages();
            $next = [];
            $found = null;
            foreach ($rows as $r) {
                if (($r['id'] ?? '') === $id) { $found = $r; continue; }
                $next[] = $r;
            }
            if (!$found) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);

            $owner = (int)($found['userId'] ?? 0);
            $is_admin = current_user_can('manage_options');
            $is_owner = ($owner > 0 && $owner === (int)$uid);
            if (!$is_admin && !$is_owner) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);

            prismtek_pixel_set_chat_messages($next);
            return rest_ensure_response(['ok'=>true]);
        },
    ]);


    register_rest_route('prismtek/v1', '/pixel-wall', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $ip = prismtek_pixel_client_ip();
            $key = 'prismtek_wall_rate_' . md5($ip);
            if (get_transient($key)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            set_transient($key, '1', 6);

            if (empty($_FILES['image'])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'missing_file'], 400);
            }

            $name = sanitize_text_field((string) $request->get_param('name'));
            $caption = sanitize_text_field((string) $request->get_param('caption'));
            $age13 = (bool) $request->get_param('age13');
            if (!$age13) return new WP_REST_Response(['ok' => false, 'error' => 'age_consent_required'], 400);
            $name = trim($name ?: 'Community Member');
            $caption = trim($caption);

            $file = $_FILES['image'];
            if (!empty($file['size']) && (int)$file['size'] > 3 * 1024 * 1024) {
                return new WP_REST_Response(['ok' => false, 'error' => 'file_too_large'], 400);
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $overrides = [
                'test_form' => false,
                'mimes' => [
                    'png' => 'image/png',
                    'jpg|jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                ],
            ];

            $uploaded = wp_handle_upload($file, $overrides);
            if (!empty($uploaded['error'])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'upload_failed', 'detail' => $uploaded['error']], 400);
            }

            $uid = get_current_user_id();
            $row = [
                'id' => wp_generate_uuid4(),
                'name' => mb_substr($name, 0, 24),
                'caption' => mb_substr($caption, 0, 120),
                'url' => esc_url_raw((string) ($uploaded['url'] ?? '')),
                'tags' => array_values(array_filter(array_map('sanitize_title', explode(',', (string)$request->get_param('tags'))))),
                'featured' => false,
                'ts' => time(),
                'userId' => $uid ? (int)$uid : 0,
            ];

            $rows = prismtek_pixel_get_wall_items();
            $rows[] = $row;
            prismtek_pixel_set_wall_items($rows);

            return rest_ensure_response(['ok' => true, 'item' => $row]);
        },
    ]);

    register_rest_route('prismtek/v1', '/pixel-wall/(?P<id>[a-zA-Z0-9\-]+)', [
        'methods' => 'DELETE',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $id = sanitize_text_field((string) $request['id']);
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);

            $rows = prismtek_pixel_get_wall_items();
            $found = null;
            $next = [];
            foreach ($rows as $row) {
                if (($row['id'] ?? '') === $id) { $found = $row; continue; }
                $next[] = $row;
            }
            if (!$found) return new WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);

            $owner = (int) ($found['userId'] ?? 0);
            $is_admin = current_user_can('manage_options');
            $is_owner = ($owner > 0 && $owner === (int)$uid);
            if (!$is_admin && !$is_owner) return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);

            $url = (string) ($found['url'] ?? '');
            if ($url) {
                $up = wp_get_upload_dir();
                $base = rtrim((string)($up['baseurl'] ?? ''), '/');
                if ($base && str_starts_with($url, $base . '/')) {
                    $rel = ltrim(substr($url, strlen($base)), '/');
                    $path = trailingslashit((string)($up['basedir'] ?? '')) . $rel;
                    if (is_file($path)) @unlink($path);
                }
            }

            prismtek_pixel_set_wall_items($next);
            return rest_ensure_response(['ok' => true]);
        },
    ]);


    register_rest_route('prismtek/v1', '/pixel-wall/feature', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $request) {
            $id = sanitize_text_field((string)$request->get_param('id'));
            $featured = (bool)$request->get_param('featured');
            $rows = prismtek_pixel_get_wall_items();
            foreach ($rows as &$r) {
                if (($r['id'] ?? '') !== $id) continue;
                $r['featured'] = $featured;
                prismtek_pixel_set_wall_items($rows);
                return rest_ensure_response(['ok'=>true]);
            }
            unset($r);
            return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
        },
    ]);

    register_rest_route('prismtek/v1', '/games', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'callback' => function (WP_REST_Request $request) {
            if (empty($_FILES['gameZip'])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'missing_file'], 400);
            }

            $title = sanitize_text_field((string) $request->get_param('title'));
            $title = trim($title ?: pathinfo((string)($_FILES['gameZip']['name'] ?? 'game'), PATHINFO_FILENAME));
            $slug = sanitize_title($title ?: 'game-' . wp_generate_password(6, false, false));

            $baseDir = WP_CONTENT_DIR . '/uploads/pixel-games';
            if (!is_dir($baseDir)) wp_mkdir_p($baseDir);
            $targetDir = $baseDir . '/' . $slug;
            if (is_dir($targetDir)) {
                $it = new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
                }
                @rmdir($targetDir);
            }
            wp_mkdir_p($targetDir);

            $tmp = (string) ($_FILES['gameZip']['tmp_name'] ?? '');
            $name = strtolower((string) ($_FILES['gameZip']['name'] ?? ''));
            if (!$tmp || !is_uploaded_file($tmp)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'upload_failed'], 400);
            }

            if (str_ends_with($name, '.zip')) {
                if (!class_exists('ZipArchive')) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'zip_not_supported'], 500);
                }
                $zip = new ZipArchive();
                if ($zip->open($tmp) !== true) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'invalid_zip'], 400);
                }
                $zip->extractTo($targetDir);
                $zip->close();
            } elseif (str_ends_with($name, '.html') || str_ends_with($name, '.htm')) {
                $dest = $targetDir . '/index.html';
                if (!move_uploaded_file($tmp, $dest)) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'move_failed'], 400);
                }
            } else {
                return new WP_REST_Response(['ok' => false, 'error' => 'unsupported_file'], 400);
            }

            // Normalize common nested zip layout: single top folder
            $entries = array_values(array_filter(glob($targetDir . '/*') ?: [], fn($x) => basename($x) !== '__MACOSX'));
            if (count($entries) === 1 && is_dir($entries[0])) {
                $inner = $entries[0];
                foreach (glob($inner . '/*') ?: [] as $child) {
                    @rename($child, $targetDir . '/' . basename($child));
                }
                @rmdir($inner);
            }

            $htmlCandidates = glob($targetDir . '/index.html') ?: glob($targetDir . '/*.html');
            if (empty($htmlCandidates)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'no_html_found'], 400);
            }

            $entryPath = $htmlCandidates[0];
            $entryFile = basename($entryPath);
            $url = content_url('uploads/pixel-games/' . rawurlencode($slug) . '/' . rawurlencode($entryFile));
            $html = @file_get_contents($entryPath) ?: '';
            $checks = [
                ['name' => 'viewport-meta', 'ok' => (stripos($html, 'viewport') !== false)],
                ['name' => 'script-present', 'ok' => (stripos($html, '<script') !== false)],
                ['name' => 'mobile-input', 'ok' => (stripos($html, 'pointer') !== false || stripos($html, 'touch') !== false)],
                ['name' => 'score-hook', 'ok' => (stripos($html, '/scores') !== false)],
            ];
            return rest_ensure_response(['ok' => true, 'slug' => $slug, 'url' => $url, 'checks' => $checks]);
        },
    ]);


    

    register_rest_route('prismtek/v1', '/register', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $ip = prismtek_pixel_client_ip();
            $key = 'prismtek_register_rate_' . md5($ip);
            if (get_transient($key)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            set_transient($key, '1', 20);

            $username = sanitize_user((string) $request->get_param('username'), true);
            $email = sanitize_email((string) $request->get_param('email'));
            $password = (string) $request->get_param('password');
            $age13 = (bool) $request->get_param('age13');

            if (!$age13) return new WP_REST_Response(['ok' => false, 'error' => 'age_consent_required'], 400);
            if ($username === '' || strlen($username) < 3) return new WP_REST_Response(['ok' => false, 'error' => 'invalid_username'], 400);
            if (!is_email($email)) return new WP_REST_Response(['ok' => false, 'error' => 'invalid_email'], 400);
            if (strlen($password) < 8) return new WP_REST_Response(['ok' => false, 'error' => 'weak_password'], 400);
            if (username_exists($username)) return new WP_REST_Response(['ok' => false, 'error' => 'username_taken'], 409);
            if (email_exists($email)) return new WP_REST_Response(['ok' => false, 'error' => 'email_taken'], 409);

            $uid = wp_create_user($username, $password, $email);
            if (is_wp_error($uid)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'create_failed', 'detail' => $uid->get_error_message()], 400);
            }

            wp_update_user([
                'ID' => (int)$uid,
                'display_name' => $username,
                'nickname' => $username,
            ]);

            wp_set_current_user((int)$uid);
            wp_set_auth_cookie((int)$uid, true, is_ssl());

            return rest_ensure_response(['ok' => true, 'userId' => (int)$uid, 'username' => $username]);
        },
    ]);

    register_rest_route('prismtek/v1', '/games/meta', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $slug = sanitize_title((string)$request->get_param('slug'));
            $all = prismtek_pixel_get_game_meta();
            return rest_ensure_response(['ok'=>true, 'meta' => is_array($all[$slug] ?? null) ? $all[$slug] : []]);
        },
    ]);

    register_rest_route('prismtek/v1', '/games/meta', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $request) {
            $slug = sanitize_title((string)$request->get_param('slug'));
            if ($slug === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_slug'],400);
            $all = prismtek_pixel_get_game_meta();
            $all[$slug] = [
                'category' => sanitize_text_field((string)$request->get_param('category')),
                'difficulty' => sanitize_text_field((string)$request->get_param('difficulty')),
                'controls' => sanitize_text_field((string)$request->get_param('controls')),
                'description' => sanitize_text_field((string)$request->get_param('description')),
            ];
            prismtek_pixel_set_game_meta($all);
            return rest_ensure_response(['ok'=>true]);
        },
    ]);

    register_rest_route('prismtek/v1', '/scores', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $game = sanitize_title((string) $request->get_param('game'));
            if ($game === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_game'], 400);
            $all = prismtek_pixel_get_scores();
            $rows = is_array($all[$game] ?? null) ? $all[$game] : [];
            usort($rows, function ($a, $b) {
                return ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            });
            $dedup = [];
            $seen = [];
            foreach ($rows as $row) {
                $uid = (int)($row['userId'] ?? 0);
                $name = strtolower((string)($row['name'] ?? 'guest'));
                $k = $uid > 0 ? ('u:' . $uid) : ('n:' . $name);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $dedup[] = $row;
            }
            $top = array_slice($dedup, 0, 3);
            foreach ($top as &$row) {
                $ruid = (int)($row['userId'] ?? 0);
                $nm = (string)($row['name'] ?? 'Guest');
                $row['initial'] = strtoupper(mb_substr($nm, 0, 1));
                $row['avatar'] = $ruid > 0 ? get_avatar_url($ruid, ['size' => 48, 'default' => 'identicon']) : '';
            }
            unset($row);
            return rest_ensure_response(['ok' => true, 'game' => $game, 'top' => $top]);
        },
    ]);



    register_rest_route('prismtek/v1', '/scores/reset', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $request) {
            $game = sanitize_title((string)$request->get_param('game'));
            $all = prismtek_pixel_get_scores();
            if ($game !== '') {
                $all[$game] = [];
            } else {
                $all = [];
            }
            prismtek_pixel_set_scores($all);
            return rest_ensure_response(['ok' => true]);
        },
    ]);

    register_rest_route('prismtek/v1', '/scores', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $game = sanitize_title((string) $request->get_param('game'));
            $score = (int) $request->get_param('score');
            if ($game === '' || $score <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
            if ($score > 1000000) return new WP_REST_Response(['ok' => false, 'error' => 'score_too_high'], 400);
            $ip = prismtek_pixel_client_ip();
            $rk = 'prismtek_score_rate_' . md5($ip);
            if (get_transient($rk)) return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
            set_transient($rk, '1', 1);

            $uid = get_current_user_id();
            $name = sanitize_text_field((string) $request->get_param('name'));
            $playerKey = sanitize_text_field((string) $request->get_param('playerKey'));
            $playerKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$playerKey);
            if ($uid) {
                $u = wp_get_current_user();
                $disp = trim((string) ($u->display_name ?? ''));
                $login = (string) ($u->user_login ?? '');
                $name = $disp !== '' ? $disp : $login;
            }
            $name = trim($name ?: 'Guest');

            $all = prismtek_pixel_get_scores();
            $rows = is_array($all[$game] ?? null) ? $all[$game] : [];

            $updated = false;
            foreach ($rows as &$r) {
                $sameUser = ($uid && (int)($r['userId'] ?? 0) === (int)$uid);
                $sameKey = (!$uid && $playerKey !== '' && (string)($r['playerKey'] ?? '') === $playerKey);
                $sameNameGuest = (!$uid && (string)($r['name'] ?? '') === $name && ((string)($r['playerKey'] ?? '') === '' || $playerKey === ''));
                if ($sameUser || $sameKey || $sameNameGuest) {
                    $prev = (int)($r['score'] ?? 0);
                    if ($score > $prev + 5000) return new WP_REST_Response(['ok' => false, 'error' => 'suspicious_delta'], 400);
                    if ($score > $prev) {
                        $r['score'] = $score;
                    }
                    $r['ts'] = time();
                    if ($uid) $r['userId'] = (int)$uid;
                    if ($playerKey !== '') $r['playerKey'] = $playerKey;
                    $r['name'] = mb_substr($name, 0, 24);
                    $updated = true;
                    break;
                }
            }
            unset($r);

            if (!$updated) {
                $rows[] = [
                    'name' => mb_substr($name, 0, 24),
                    'score' => $score,
                    'userId' => $uid ? (int)$uid : 0,
                    'playerKey' => $playerKey,
                    'ts' => time(),
                ];
            }

            usort($rows, function ($a, $b) {
                return ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            });

            // Deduplicate by identity (userId > playerKey > name)
            $dedup = [];
            $seen = [];
            foreach ($rows as $row) {
                $k = '';
                $ru = (int)($row['userId'] ?? 0);
                $rn = (string)($row['name'] ?? 'Guest');
                if ($ru > 0) $k = 'u:' . $ru;
                else $k = 'n:' . strtolower($rn);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $dedup[] = $row;
            }

            $all[$game] = array_slice($dedup, 0, 50);
            prismtek_pixel_set_scores($all);

            return rest_ensure_response(['ok' => true, 'top' => array_slice($all[$game], 0, 3)]);
        },
    ]);


    register_rest_route('prismtek/v1', '/profile', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $u = wp_get_current_user();
            return rest_ensure_response([
                'ok' => true,
                'displayName' => (string)($u->display_name ?? ''),
                'bio' => (string)get_user_meta($uid, 'prismtek_bio', true),
                'favoriteGame' => (string)get_user_meta($uid, 'prismtek_favorite_game', true),
                'themeColor' => (string)get_user_meta($uid, 'prismtek_theme_color', true),
            ]);
        },
    ]);

    register_rest_route('prismtek/v1', '/profile', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $display = sanitize_text_field((string)$request->get_param('displayName'));
            $bio = sanitize_text_field((string)$request->get_param('bio'));
            $fav = sanitize_title((string)$request->get_param('favoriteGame'));
            $color = sanitize_hex_color((string)$request->get_param('themeColor'));

            if ($display !== '') {
                wp_update_user(['ID' => $uid, 'display_name' => mb_substr($display, 0, 24), 'nickname' => mb_substr($display, 0, 24)]);
            }
            update_user_meta($uid, 'prismtek_bio', mb_substr($bio, 0, 120));
            update_user_meta($uid, 'prismtek_favorite_game', mb_substr($fav, 0, 60));
            update_user_meta($uid, 'prismtek_theme_color', $color ?: '#59d9ff');

            return rest_ensure_response(['ok' => true]);
        },
    ]);

    

    register_rest_route('prismtek/v1', '/pet', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $state = prismtek_pet_get_state($uid);
            $unlock = prismtek_pet_get_unlocks($uid);
            $state['stage'] = prismtek_pet_compute_stage($state);
            $state['unlocks'] = $unlock;
            if (!in_array((string)($state['skin'] ?? 'default'), $unlock['skins'], true)) $state['skin'] = 'default';
            return rest_ensure_response(['ok' => true, 'pet' => $state]);
        },
    ]);

    register_rest_route('prismtek/v1', '/pet/action', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $action = sanitize_key((string)$request->get_param('action'));
            $state = prismtek_pet_get_state($uid);

            if ($action === 'feed') {
                $state['hunger'] = min(100, (int)$state['hunger'] + 22);
                $state['happiness'] = min(100, (int)$state['happiness'] + 4);
            } elseif ($action === 'play') {
                $state['happiness'] = min(100, (int)$state['happiness'] + 16);
                $state['energy'] = max(0, (int)$state['energy'] - 8);
                $state['hunger'] = max(0, (int)$state['hunger'] - 6);
            } elseif ($action === 'rest') {
                $state['energy'] = min(100, (int)$state['energy'] + 20);
                $state['happiness'] = min(100, (int)$state['happiness'] + 3);
            } elseif ($action === 'rename') {
                $name = sanitize_text_field((string)$request->get_param('name'));
                if ($name !== '') $state['name'] = mb_substr($name, 0, 20);
            } elseif ($action === 'setskin') {
                $skin = sanitize_key((string)$request->get_param('skin'));
                $unlock = prismtek_pet_get_unlocks($uid);
                if (in_array($skin, $unlock['skins'], true)) $state['skin'] = $skin;
            } else {
                return new WP_REST_Response(['ok' => false, 'error' => 'invalid_action'], 400);
            }

            // light health recovery when cared for
            if ((int)$state['hunger'] > 50 && (int)$state['energy'] > 40) {
                $state['health'] = min(100, (int)$state['health'] + 2);
            }

            prismtek_pet_set_state($uid, $state);
            $state = prismtek_pet_get_state($uid);
            $unlock = prismtek_pet_get_unlocks($uid);
            $state['stage'] = prismtek_pet_compute_stage($state);
            $state['unlocks'] = $unlock;
            return rest_ensure_response(['ok' => true, 'pet' => $state]);
        },
    ]);
register_rest_route('prismtek/v1', '/moderation/clear-chat', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function () {
            prismtek_pixel_set_chat_messages([]);
            return rest_ensure_response(['ok' => true]);
        },
    ]);

    register_rest_route('prismtek/v1', '/moderation/clear-wall', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function () {
            prismtek_pixel_set_wall_items([]);
            return rest_ensure_response(['ok' => true]);
        },
    ]);


});

function prismtek_pixel_scan_games() {
    $baseDir = WP_CONTENT_DIR . '/uploads/pixel-games';
    if (!is_dir($baseDir)) wp_mkdir_p($baseDir);

    $out = [];
    foreach (glob($baseDir . '/*') ?: [] as $entry) {
        if (!is_dir($entry)) continue;
        $slug = basename($entry);

        $index = $entry . '/index.html';
        $target = '';
        if (is_file($index)) {
            $target = $index;
        } else {
            $candidates = glob($entry . '/*.html') ?: [];
            if (!empty($candidates)) $target = $candidates[0];
        }
        if (!$target) continue;

        $fileName = basename($target);
        $url = content_url('uploads/pixel-games/' . rawurlencode($slug) . '/' . rawurlencode($fileName));
        $titleBase = $fileName === 'index.html' ? $slug : ($slug . ' - ' . pathinfo($fileName, PATHINFO_FILENAME));

        $out[] = [
            'slug' => sanitize_title($slug),
            'url' => $url,
            'title' => ucwords(str_replace(['-', '_'], ' ', $titleBase)),
        ];
    }

    usort($out, fn($a, $b) => strcmp($a['title'], $b['title']));
    return array_slice($out, 0, 24);
}


add_shortcode('prism_featured_games', function () {
    $games = array_slice(prismtek_pixel_scan_games(), 0, 8);
    if (empty($games)) return '<p>No featured games yet.</p>';
    ob_start(); ?>
    <div class="pph-featured" id="pph-featured-games">
      <button type="button" class="pph-fnav" data-dir="-1">◀</button>
      <div class="pph-ftrack" id="pph-ftrack">
        <?php foreach ($games as $g): ?>
          <a class="pph-fcard" href="/pixel-arcade/?game=<?php echo esc_attr($g['slug']); ?>"><?php echo esc_html($g['title']); ?></a>
        <?php endforeach; ?>
      </div>
      <button type="button" class="pph-fnav" data-dir="1">▶</button>
    </div>
    <style>
      .pph-featured{display:grid;grid-template-columns:40px 1fr 40px;gap:8px;align-items:center}
      .pph-ftrack{display:flex;gap:8px;overflow:auto;scroll-behavior:smooth;padding:4px}
      .pph-fcard{min-width:180px;padding:10px;border:1px solid #4c5498;background:#11173b;color:#fff;text-decoration:none}
      .pph-fnav{height:36px;background:#1b2458;color:#fff;border:1px solid #5e76ff;cursor:pointer}
    </style>
    <script>
      (()=>{const root=document.getElementById('pph-featured-games');if(!root)return;const t=root.querySelector('#pph-ftrack');root.querySelectorAll('.pph-fnav').forEach(b=>b.addEventListener('click',()=>t.scrollBy({left:(Number(b.dataset.dir)||1)*220,behavior:'smooth'})));})();
    </script>
    <?php return ob_get_clean();
});




add_shortcode('prism_pet_showcase', function () {
    $users = get_users(['number' => 12, 'orderby' => 'registered', 'order' => 'DESC']);
    $cards = [];
    foreach ($users as $u) {
        $uid = (int)$u->ID;
        $state = get_user_meta($uid, 'prismtek_pet_state', true);
        if (!is_array($state) || empty($state)) continue;
        $state = prismtek_pet_apply_decay($state);
        $stage = prismtek_pet_compute_stage($state);
        $cards[] = [
            'name' => (string)$u->display_name,
            'pet' => (string)($state['name'] ?? 'Prismo'),
            'stage' => $stage,
            'skin' => (string)($state['skin'] ?? 'default'),
            'health' => (int)($state['health'] ?? 0),
        ];
        if (count($cards) >= 8) break;
    }
    if (empty($cards)) return '<p>No creature profiles yet.</p>';
    ob_start(); ?>
    <div class="pph-showcase">
      <?php foreach ($cards as $c): ?>
        <div class="pph-scard">
          <strong><?php echo esc_html($c['name']); ?></strong><br>
          <?php echo esc_html($c['pet']); ?> · <?php echo esc_html($c['stage']); ?><br>
          Skin: <?php echo esc_html($c['skin']); ?> · HP <?php echo (int)$c['health']; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <style>
      .pph-showcase{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px}
      .pph-scard{padding:10px;border:1px solid #4c5498;background:#11173b;color:#fff}
    </style>
    <?php return ob_get_clean();
});

add_shortcode('prism_pixel_hub', function () {
    $games = prismtek_pixel_scan_games();
    $can_moderate = current_user_can('manage_options');
    $viewer_logged_in = is_user_logged_in();
    $viewer_id = get_current_user_id();
    $viewer_nonce = $viewer_logged_in ? wp_create_nonce('wp_rest') : '';
    $viewer_user = $viewer_logged_in ? wp_get_current_user() : null;
    $profile_bio = $viewer_logged_in ? (string)get_user_meta($viewer_id, 'prismtek_bio', true) : '';
    $profile_fav = $viewer_logged_in ? (string)get_user_meta($viewer_id, 'prismtek_favorite_game', true) : '';
    $profile_color = $viewer_logged_in ? (string)get_user_meta($viewer_id, 'prismtek_theme_color', true) : '#59d9ff';
    $selected_game_slug = isset($_GET['game']) ? sanitize_title((string) $_GET['game']) : '';
    $selected_game = null;
    if (!empty($games) && $selected_game_slug !== '') {
        foreach ($games as $g) {
            if (($g['slug'] ?? '') === $selected_game_slug) { $selected_game = $g; break; }
        }
    }
    ob_start();
    ?>
    <section class="pph-wrap">
      <h2>Pixel Arcade & Community</h2>
      <p>Create and share pixel artwork, chat with the community, and play browser mini-games. Uploads support PNG, JPG, WEBP, and GIF up to 3MB.</p><p><strong>Safety:</strong> Community features are for age 13+ (or younger with parent/guardian permission).</p>

      <article class="pph-card">
        <details class="pph-toggle" data-toggle-key="account" open><summary>Account</summary>
        <p>Sign in to manage your content. Visitors can create accounts to join the community.</p>
        <p>
          <a href="<?php echo esc_url(wp_login_url(home_url('/pixel-arcade/'))); ?>">Login</a> ·
          <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a> ·
          <a href="/wp-admin/">Admin</a> ·
          <a href="<?php echo esc_url(wp_logout_url(home_url('/pixel-arcade/'))); ?>">Logout</a>
          <span id="pph-account-status" class="pph-status">Checking session…</span>
        </p>
        <?php if (!$viewer_logged_in): ?>
        <form id="pph-register-form" class="pph-form">
          <input name="username" type="text" minlength="3" maxlength="24" placeholder="Username" required />
          <input name="email" type="email" placeholder="Email" required />
          <input name="password" type="password" minlength="8" placeholder="Password (min 8 chars)" required />
          <label><input type="checkbox" name="age13" required /> I am 13+ or have parent/guardian permission</label>
          <button type="submit">Create Account with Password</button>
        </form>
        <p id="pph-register-status" class="pph-status"></p>
        <?php endif; ?>
        <?php if ($viewer_logged_in): ?>
        <form id="pph-profile-form" class="pph-form">
          <input name="displayName" type="text" maxlength="24" placeholder="Display name" value="<?php echo esc_attr((string)($viewer_user->display_name ?? '')); ?>" />
          <input name="bio" type="text" maxlength="120" placeholder="Short bio" value="<?php echo esc_attr($profile_bio); ?>" />
          <input name="favoriteGame" type="text" maxlength="60" placeholder="Favorite game slug" value="<?php echo esc_attr($profile_fav); ?>" />
          <label>Theme Color <input name="themeColor" type="color" value="<?php echo esc_attr($profile_color ?: '#59d9ff'); ?>" /></label>
          <button type="submit">Save Profile</button>
        </form>
        <p id="pph-profile-status" class="pph-status"></p>
        <?php endif; ?>
                <?php if ($viewer_logged_in): ?>
        <article id="pph-pet-panel" class="pph-card" style="margin-top:10px;">
          <details class="pph-toggle" data-toggle-key="pet"><summary>My Pixel Creature</summary>
          <div id="pph-pet-view" class="pph-pet-view">Loading pet...</div>
          <div class="pph-tool-row">
            <button type="button" id="pph-pet-feed">Feed</button>
            <button type="button" id="pph-pet-play">Play</button>
          </div>
          <div class="pph-tool-row">
            <button type="button" id="pph-pet-rest">Rest</button>
            <input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" />
          </div>
          <div class="pph-tool-row"><select id="pph-pet-skin"><option value="default">default</option></select><button type="button" id="pph-pet-skin-save">Apply Skin</button></div><button type="button" id="pph-pet-rename">Save Name</button>
          <p id="pph-pet-status" class="pph-status"></p>
          </details>
        </article>
        <?php endif; ?>
<?php if ($can_moderate): ?>
        <div class="pph-tool-row">
          <button type="button" id="pph-clear-chat">Clear Chat</button>
          <button type="button" id="pph-clear-wall">Clear Wall</button>
        </div>
        <div class="pph-tool-row">
          <input id="pph-reset-game" type="text" placeholder="Game slug (optional)" />
          <button type="button" id="pph-reset-scores">Reset Scores</button>
        </div>
        <p id="pph-mod-status" class="pph-status"></p>
        <?php endif; ?>
        </details>
      </article>

      <div class="pph-grid">
        <article class="pph-card">
          <details class="pph-toggle" data-toggle-key="games" open><summary>Mini HTML Games</summary>
          <p>Place game files in <code>/wp-content/uploads/pixel-games/&lt;game-folder&gt;/</code>. Supported: <code>index.html</code> or any <code>.html</code> file.</p>
          <p><strong>Quick links:</strong> <a href="<?php echo esc_url(content_url('/uploads/pixel-games/templates/prism-arcade-starter-template.zip')); ?>" target="_blank" rel="noopener">Download starter template</a> · <a href="#pph-uploader-anchor">Go to uploader</a></p>

          <?php if (!$games): ?>
            <p>No games are published yet. Add a game folder and refresh.</p>
          <?php else: ?>
            <input id="pph-game-search" type="text" placeholder="Search games..." />
            <div class="pph-games-list">
              <?php foreach ($games as $g): ?>
                <a class="pph-game-chip<?php echo (($selected_game['slug'] ?? '') === ($g['slug'] ?? '')) ? ' active' : ''; ?>" href="?game=<?php echo esc_attr($g['slug']); ?>"><?php echo esc_html($g['title']); ?></a>
              <?php endforeach; ?>
            </div>
            <?php if ($selected_game): ?>
              <div class="pph-game-embed">
                <iframe id="pph-game-frame" src="<?php echo esc_url($selected_game['url']); ?>" title="Pixel game preview" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups allow-forms"></iframe>
                <p>
                  <a href="<?php echo esc_url($selected_game['url']); ?>" target="_blank" rel="noopener">Open game in new tab</a> ·
                  <a href="<?php echo esc_url(remove_query_arg('game')); ?>" id="pph-stop-game">Stop game</a>
                </p>
                <div id="pph-game-meta" class="pph-leaderboard" data-game="<?php echo esc_attr($selected_game['slug'] ?? ''); ?>">Loading game details...</div>
                <div id="pph-leaderboard" class="pph-leaderboard" data-game="<?php echo esc_attr($selected_game['slug'] ?? ''); ?>">Loading leaderboard...</div>
              </div>
            <?php else: ?>
              <div class="pph-game-embed"><p>Select a game above to play in-page.</p></div>
            <?php endif; ?>
          <?php endif; ?>

          <div id="pph-uploader-anchor" class="pph-uploader-block" style="margin-top:14px;padding-top:10px;border-top:1px solid #3f4a93;">
            <p><strong>Game Uploader</strong> · <a href="<?php echo esc_url(content_url('/uploads/pixel-games/templates/prism-arcade-starter-template.zip')); ?>" target="_blank" rel="noopener">Download starter template (.zip)</a></p>
            <details id="pph-upload-tools" class="pph-subtoggle" data-toggle-key="upload-tools">
              <summary>Tap to expand uploader</summary>
              <p>Upload a <code>.zip</code> or <code>.html</code> game package. Uploaded games appear in the list above.</p>
              <?php if ($viewer_logged_in): ?>
              <form id="pph-game-upload-form" class="pph-form" enctype="multipart/form-data">
                <input name="title" type="text" maxlength="60" placeholder="Game title" />
                <input name="gameZip" type="file" accept=".zip,.html,.htm" required />
                <button type="submit">Upload Game</button>
              </form>
              <p id="pph-game-upload-status" class="pph-status"></p>
              <?php if ($can_moderate): ?>
              <form id="pph-game-meta-form" class="pph-form">
                <input name="slug" type="text" placeholder="Game slug" />
                <input name="category" type="text" placeholder="Category (arcade/puzzle/racing)" />
                <input name="difficulty" type="text" placeholder="Difficulty (easy/normal/hard)" />
                <input name="controls" type="text" placeholder="Controls summary" />
                <input name="description" type="text" placeholder="Short description" />
                <button type="submit">Save Game Meta</button>
              </form>
              <p id="pph-game-meta-status" class="pph-status"></p>
              <?php endif; ?>
              <?php else: ?>
              <p><a href="<?php echo esc_url(wp_login_url(home_url('/arcade-games/'))); ?>">Log in to upload</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create account</a></p>
              <?php endif; ?>
            </details>
          </div>
          </details>
        </article>

        <article class="pph-card">
          <details class="pph-toggle" data-toggle-key="chat" open><summary>Community Chat</summary>
          <div id="pph-chat-log" class="pph-log">Loading chat...</div>
          <form id="pph-chat-form" class="pph-form">
            <input name="name" type="text" maxlength="24" placeholder="Name (optional)" />
            <input name="message" type="text" maxlength="280" placeholder="Share an update with the community..." required />
            <label><input type="checkbox" name="age13" required /> I am 13+ or have parent/guardian permission</label>
            <button type="submit">Send</button>
          </form>
          </details>
        </article>
      </div>



      <article class="pph-card">
        <details class="pph-toggle" data-toggle-key="studio"><summary>Pixel Studio</summary>
        <p>Draw directly in your browser, export as PNG, or publish to the Memory Wall scrapbook.</p>
        <div class="pph-studio-wrap">
          <div class="pph-canvas-shell"><canvas id="pph-studio-canvas" width="512" height="512" aria-label="Pixel drawing canvas"></canvas><canvas id="pph-grid-canvas" width="512" height="512" aria-hidden="true"></canvas></div>
          <div class="pph-studio-tools">
            <label>Tool
              <select id="pph-tool">
                <option value="pen" selected>Pen</option>
                <option value="line">Line</option>
                <option value="rect">Rectangle</option>
                <option value="circle">Circle</option>
                <option value="bucket">Paint Bucket</option>
                <option value="wand">Magic Wand</option>
                <option value="lasso">Lasso (rect)</option>
                <option value="eraser">Eraser</option>
                <option value="eyedropper">Eyedropper</option>
              </select>
            </label>
            <div class="pph-tool-row">
              <label>Color <input type="color" id="pph-color" value="#59d9ff" /></label>
              <label>Brush
                <select id="pph-brush">
                  <option value="1">1px</option>
                  <option value="2">2px</option>
                  <option value="3" selected>3px</option>
                  <option value="4">4px</option>
                  <option value="6">6px</option>
                </select>
              </label>
            </div>
            <div class="pph-tool-row">
              <label>Grid
                <select id="pph-grid-size">
                  <option value="32">32x32</option>
                  <option value="48">48x48</option>
                  <option value="64" selected>64x64</option>
                  <option value="96">96x96</option>
                  <option value="128">128x128</option>
                </select>
              </label>
              <label>Tolerance <input type="range" id="pph-tolerance" min="0" max="96" value="24" /></label>
              <label><input type="checkbox" id="pph-grid-toggle" checked /> Show Grid</label>
            </div>
            <div class="pph-tool-row">
              <button type="button" id="pph-undo">Undo</button>
              <button type="button" id="pph-redo">Redo</button>
              <button type="button" id="pph-clear">Clear</button>
            </div>
            <div class="pph-tool-row">
              <input id="pph-import-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif" />
              <button type="button" id="pph-import">Import Image</button>
              <button type="button" id="pph-strip-grid">Remove Grid Lines</button>
            </div>
            <button type="button" id="pph-download">Download PNG</button>
            <button type="button" id="pph-post-wall">Publish to Memory Wall</button>
          </div>
        </div>
        </details>
      </article>

      <article class="pph-card">
        <details class="pph-toggle" data-toggle-key="wall"><summary>Memory Wall / Visitor Scrapbook</summary>
        <form id="pph-wall-form" class="pph-form" enctype="multipart/form-data">
          <input name="name" type="text" maxlength="24" placeholder="Artist name" />
          <input name="caption" type="text" maxlength="120" placeholder="Caption" />
          <input name="tags" type="text" maxlength="120" placeholder="Tags (comma separated)" />
          <input name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif" required />
          <label><input type="checkbox" name="age13" required /> I am 13+ or have parent/guardian permission</label>
          <button type="submit">Upload Pixel Art</button>
        </form>
        <div id="pph-wall-grid" class="pph-wall">Loading gallery...</div>
        </details>
      </article>
    </section>

    <style>
      .pph-wrap{margin-top:1.5rem;display:grid;gap:16px}
      .pph-toggle,.pph-subtoggle{border:1px solid #4c5498;background:rgba(17,23,59,.35);padding:8px}
      .pph-toggle>summary,.pph-subtoggle>summary{cursor:pointer;list-style:none;font-family:"Press Start 2P",ui-monospace,monospace;font-size:.72rem;color:#fff;text-shadow:1px 1px 0 #000;padding:4px 2px}
      .pph-toggle[open]>summary,.pph-subtoggle[open]>summary{margin-bottom:8px}
      .pph-toggle>summary::-webkit-details-marker,.pph-subtoggle>summary::-webkit-details-marker{display:none}
      .pph-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
      .pph-card{background:rgba(24,25,58,.92);border:2px solid #7e85ff;box-shadow:6px 6px 0 rgba(61,68,138,.68);padding:16px}
      .pph-card h3{margin-top:0;font-size:1rem;letter-spacing:.02em;color:#fff;text-shadow:1px 1px 0 #000}
      .pph-card p,.pph-card li,.pph-card label,.pph-card span{color:#fff;text-shadow:1px 1px 0 #000}
      .pph-log{height:240px;overflow:auto;background:#0f1130;border:1px solid #3c4379;padding:10px;font-family:ui-monospace,monospace;white-space:normal;word-break:break-word;overflow-wrap:anywhere}
      .pph-form{display:grid;gap:8px;margin-top:10px;min-width:0}
      .pph-form input,.pph-form button{padding:10px;border:1px solid #40488a;background:#101330;color:#eef1ff;min-width:0;max-width:100%}
      .pph-form button{background:linear-gradient(135deg,#7f65ff,#59d9ff);border:2px solid #dfe3ff;color:#fff;font-weight:700;cursor:pointer}
      #pph-game-search{width:100%;padding:8px;border:1px solid #40488a;background:#101330;color:#fff;margin-bottom:8px}
      .pph-games-list{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 10px}
      .pph-game-chip{display:inline-block;padding:6px 10px;border:1px solid #4c5498;background:#11173b;color:#fff;text-decoration:none;font-size:11px}
      .pph-game-chip.active{background:#2a3272;border-color:#9fb1ff}
      .pph-game-embed{max-width:100%;overflow:hidden}
      .pph-game-embed iframe{margin-top:10px;width:100%;max-width:100%;height:min(78vh,680px);min-height:520px;border:1px solid #4b5395;background:#090b1f;display:block}
      .pph-leaderboard{margin-top:8px;padding:8px;border:1px solid #4c5498;background:#0f1130;font-size:11px;line-height:1.4}
      .pph-lrow{display:flex;align-items:center;gap:8px;margin:4px 0}
      .pph-ava{width:18px;height:18px;border-radius:50%;display:inline-grid;place-items:center;background:#2a3272;color:#fff;font-size:10px;overflow:hidden}
      .pph-wall{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-top:12px}
      .pph-wall a{display:block;border:1px solid #4c5498;background:#0f1130;padding:6px;text-decoration:none;color:#dde2ff;position:relative}
      .pph-del{margin-top:6px;padding:4px 6px;border:1px solid #c66;background:#431d1d;color:#fff;font-size:10px;cursor:pointer}
      .pph-status{display:block;margin-top:8px;font-size:11px;color:#ffe08a;text-shadow:1px 1px 0 #000}
      .pph-wall img{width:100%;height:96px;object-fit:cover;image-rendering:pixelated;display:block}
      .pph-cap{font-size:11px;line-height:1.3;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .pph-msg{margin:0 0 8px}
      .pph-msg b{color:#fff}
      .pph-react,.pph-reply{margin-top:2px;margin-right:3px;padding:0 3px !important;border:1px solid #4c5498 !important;background:#11173b !important;color:#fff !important;font-size:7px !important;line-height:1.1 !important;display:inline-flex;align-items:center;gap:2px;cursor:pointer;min-height:12px !important;text-transform:none !important;letter-spacing:0 !important;border-radius:0 !important;box-shadow:none !important;transform:none !important}
      .pph-react:hover,.pph-reply:hover{box-shadow:none !important;transform:none !important;opacity:.92}
      .pph-wrap,.pph-wrap *{box-sizing:border-box;max-width:100%}
      .pph-wrap{overflow-x:hidden}
      .pph-wrap code{word-break:break-all;overflow-wrap:anywhere}
      .pph-grid{grid-template-columns:repeat(auto-fit,minmax(300px,1fr));min-width:0}
      .pph-card{min-width:0;overflow:hidden}
      .pph-card ul{padding-left:18px;margin:0}
      .pph-game-embed iframe{max-width:100%}
      .pph-studio-wrap{display:grid;grid-template-columns:minmax(0,520px) minmax(0,1fr);gap:12px;align-items:start;min-width:0}
      .pph-canvas-shell{position:relative;width:100%;max-width:520px;aspect-ratio:1/1}
      #pph-studio-canvas,#pph-grid-canvas{position:absolute;inset:0;width:100%;height:100%;border:2px solid #6b74c7;background:#0e1026;image-rendering:pixelated;cursor:crosshair;display:block}
      #pph-grid-canvas{pointer-events:none;background:transparent}
      .pph-studio-tools{display:grid;gap:8px;min-width:0}
      .pph-tool-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;min-width:0}
      .pph-studio-tools label{display:grid;gap:4px;font-size:12px;color:#fff;text-shadow:1px 1px 0 #000}
      .pph-studio-tools select,.pph-studio-tools input[type="color"],.pph-studio-tools input[type="range"],.pph-studio-tools input[type="file"],.pph-studio-tools button{padding:8px;border:1px solid #40488a;background:#101330;color:#eef1ff;min-width:0}
      .pph-studio-tools button{cursor:pointer}
      @media (max-width:1100px){.pph-grid{grid-template-columns:1fr}.pph-studio-wrap{grid-template-columns:1fr}}
      @media (max-width:768px){
        .pph-wrap{gap:12px}
        .pph-card{padding:12px}
        .pph-log{height:200px}
        .pph-form input,.pph-form button,.pph-studio-tools select,.pph-studio-tools input[type="color"],.pph-studio-tools input[type="range"],.pph-studio-tools input[type="file"],.pph-studio-tools button{width:100%}
        .pph-tool-row{grid-template-columns:1fr}
        .pph-game-embed iframe{height:min(68vh,560px);min-height:420px}
      }
    </style>

    <script>
    (()=>{
      const API = '<?php echo esc_js(rest_url('prismtek/v1/')); ?>';
      function initTogglePersistence(){
        try{
          const toggles=[...document.querySelectorAll('.pph-toggle, .pph-subtoggle')];
          toggles.forEach((el,idx)=>{
            const k = (el.getAttribute('data-toggle-key') || ('idx-'+idx)).toLowerCase();
            const ls = localStorage.getItem('pph_toggle_'+k);
            if(ls==='0') el.open=false;
            if(ls==='1') el.open=true;
            el.addEventListener('toggle', ()=>{
              localStorage.setItem('pph_toggle_'+k, el.open ? '1' : '0');
            });
          });
        }catch{}
      }
      initTogglePersistence();
      let canModerate = <?php echo $can_moderate ? 'true' : 'false'; ?>;
      let restNonce = '<?php echo esc_js($viewer_nonce); ?>';
      let isLoggedIn = <?php echo $viewer_logged_in ? 'true' : 'false'; ?>;
      let sessionUserId = <?php echo (int) $viewer_id; ?>;
      const chatLog = document.getElementById('pph-chat-log');
      const chatForm = document.getElementById('pph-chat-form');
      const wallForm = document.getElementById('pph-wall-form');
      const wallGrid = document.getElementById('pph-wall-grid');
      const registerForm = document.getElementById('pph-register-form');
      const registerStatus = document.getElementById('pph-register-status');
      const gameUploadForm = document.getElementById('pph-game-upload-form');
      const gameUploadStatus = document.getElementById('pph-game-upload-status');
      const gameMetaForm = document.getElementById('pph-game-meta-form');
      const gameMetaStatus = document.getElementById('pph-game-meta-status');
      const gameSearch = document.getElementById('pph-game-search');
      const profileForm = document.getElementById('pph-profile-form');
      const profileStatus = document.getElementById('pph-profile-status');
      const clearChatBtn = document.getElementById('pph-clear-chat');
      const clearWallBtn = document.getElementById('pph-clear-wall');
      const modStatus = document.getElementById('pph-mod-status');
      const petView = document.getElementById('pph-pet-view');
      const petStatus = document.getElementById('pph-pet-status');
      const petFeedBtn = document.getElementById('pph-pet-feed');
      const petPlayBtn = document.getElementById('pph-pet-play');
      const petRestBtn = document.getElementById('pph-pet-rest');
      const petRenameBtn = document.getElementById('pph-pet-rename');
      const petNameInput = document.getElementById('pph-pet-name');
      const petSkinSelect = document.getElementById('pph-pet-skin');
      const petSkinSaveBtn = document.getElementById('pph-pet-skin-save');
      const resetGameInput = document.getElementById('pph-reset-game');
      const resetScoresBtn = document.getElementById('pph-reset-scores');
      const studioCanvas = document.getElementById('pph-studio-canvas');
      const gridCanvas = document.getElementById('pph-grid-canvas');
      const colorInput = document.getElementById('pph-color');
      const brushInput = document.getElementById('pph-brush');
      const gridInput = document.getElementById('pph-grid-size');
      const eraserBtn = document.getElementById('pph-eraser');
      const clearBtn = document.getElementById('pph-clear');
      const downloadBtn = document.getElementById('pph-download');
      const postWallBtn = document.getElementById('pph-post-wall');
      const toolInput = document.getElementById('pph-tool');
      const toleranceInput = document.getElementById('pph-tolerance');
      const gridToggle = document.getElementById('pph-grid-toggle');
      const undoBtn = document.getElementById('pph-undo');
      const redoBtn = document.getElementById('pph-redo');
      const importBtn = document.getElementById('pph-import');
      const importFile = document.getElementById('pph-import-file');
      const gameFrame = document.getElementById('pph-game-frame');
      const stopGameLink = document.getElementById('pph-stop-game');
      const leaderboard = document.getElementById('pph-leaderboard');
      const gameMetaBox = document.getElementById('pph-game-meta');
      const stripGridBtn = document.getElementById('pph-strip-grid');
      const accountStatus = document.getElementById('pph-account-status');
      const ago = (ts)=>{
        const s=Math.max(1,Math.floor(Date.now()/1000-ts));
        if(s<60)return s+'s'; if(s<3600)return Math.floor(s/60)+'m'; if(s<86400)return Math.floor(s/3600)+'h';
        return Math.floor(s/86400)+'d';
      };

      async function bootstrapSession(){
        try{
          const r = await fetch(API+'session?ts='+Date.now(), { credentials:'include', cache:'no-store', headers: restNonce ? {'X-WP-Nonce':restNonce} : {} });
          const j = await r.json();
          isLoggedIn = !!j.loggedIn;
          canModerate = !!j.canModerate;
          restNonce = j.nonce || '';
          sessionUserId = Number(j.userId || 0);
          try {
            if(sessionUserId>0 && j.user){ localStorage.setItem('pixel_player_name', String(j.user)); }
            if(!localStorage.getItem('pixel_player_key')){ localStorage.setItem('pixel_player_key', 'pk_'+Math.random().toString(36).slice(2)+Date.now().toString(36)); }
          } catch {}
          if(accountStatus){
            if(isLoggedIn){
              accountStatus.textContent = canModerate ? `Logged in as ${j.user || 'user'} (admin)` : `Logged in as ${j.user || 'user'}`;
            } else {
              accountStatus.textContent = 'Not logged in';
            }
          }
        }catch{}
      };


      async function loadChat(){
        try{
          const r=await fetch(API+'chat'); const j=await r.json();
          const rows=(j.messages||[]).slice(-60);
          chatLog.innerHTML = rows.map(m=>{
            const re=m.reactions||{};
            const rid=(m.id||'');
            const reply=m.replyTo?`<em>↪ ${String(m.replyTo).replace(/[<>]/g,'')}</em><br>`:'';
            const up = Number(re['up']||re['👍']||0);
            const heart = Number(re['heart']||re['❤️']||0);
            const fire = Number(re['fire']||re['🔥']||0);
            return `<p class="pph-msg"><b>${(m.name||'Community Member').replace(/[<>]/g,'')}</b> · ${ago(m.ts)}<br>${reply}${(m.message||'').replace(/[<>]/g,'')}<br><button class="pph-react" data-id="${rid}" data-e="up">👍 ${up}</button><button class="pph-react" data-id="${rid}" data-e="heart">❤️ ${heart}</button><button class="pph-react" data-id="${rid}" data-e="fire">🔥 ${fire}</button><button class="pph-reply" data-name="${(m.name||'Community Member').replace(/[<>]/g,'')}">Reply</button>${(canModerate || (isLoggedIn && Number(m.userId||0)===sessionUserId)) ? `<button class=\"pph-reply pph-msg-del\" data-id=\"${rid}\">Remove</button>` : ''}</p>`;
          }).join('') || 'No messages yet.';
          chatLog.querySelectorAll('.pph-react').forEach(btn=>btn.addEventListener('click', async ()=>{
            await fetch(API+'chat/react',{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify({id:btn.dataset.id,emoji:btn.dataset.e})});
            loadChat();
          }));
          chatLog.querySelectorAll('.pph-msg-del').forEach(btn=>btn.addEventListener('click', async ()=>{
            const id = btn.getAttribute('data-id');
            if(!id) return;
            const r = await fetch(API+'chat/'+encodeURIComponent(id), { method:'DELETE', credentials:'include', headers:{'X-WP-Nonce':restNonce} });
            if(!r.ok) return;
            loadChat();
          }));
          chatLog.querySelectorAll('.pph-reply').forEach(btn=>btn.addEventListener('click', ()=>{
            const nm=btn.dataset.name||'Community Member';
            const inp=chatForm?.querySelector('input[name=message]');
            if(inp){ inp.value=`@${nm} `; inp.focus(); }
          }));
          chatLog.scrollTop = chatLog.scrollHeight;
        }catch{ chatLog.textContent='Chat unavailable right now.'; }
      }


      
      async function loadGameMeta(){
        if(!gameMetaBox) return;
        const game = gameMetaBox.getAttribute('data-game') || '';
        if(!game){ gameMetaBox.textContent='No game selected.'; return; }
        try{
          const r=await fetch(API+'games/meta?slug='+encodeURIComponent(game)+'&ts='+Date.now(),{credentials:'include',cache:'no-store'});
          const j=await r.json();
          const m=j.meta||{};
          const cat=(m.category||'arcade').toString();
          const diff=(m.difficulty||'normal').toString();
          const ctl=(m.controls||'See game panel').toString();
          const desc=(m.description||'No description yet.').toString();
          gameMetaBox.innerHTML=`<strong>${game}</strong><br>Category: ${cat} · Difficulty: ${diff}<br>Controls: ${ctl}<br>${desc}`;
        }catch{ gameMetaBox.textContent='Game details unavailable right now.'; }
      }


      function renderPet(pet){
        if(!petView || !pet) return;
        const mood = (pet.happiness>70 && pet.energy>50) ? 'Happy' : (pet.health<35 ? 'Sickly' : 'Okay');
        const stage = (pet.stage||'baby');
        const skin = (pet.skin||'default');
        petView.innerHTML = `<strong>${(pet.name||'Prismo')}</strong> (${pet.species||'blob'}) · ${mood}<br>Stage ${stage} · Skin ${skin}<br>Hunger ${pet.hunger}% · Happiness ${pet.happiness}% · Energy ${pet.energy}% · Health ${pet.health}%`;
        if(petNameInput && !petNameInput.value) petNameInput.value = pet.name || '';
        if(petSkinSelect){
          const skins = (pet.unlocks && Array.isArray(pet.unlocks.skins)) ? pet.unlocks.skins : ['default'];
          petSkinSelect.innerHTML = skins.map(sk=>`<option value=\"${sk}\" ${sk===skin?'selected':''}>${sk}</option>`).join('');
        }
      }

      async function loadPet(){
        if(!petView) return;
        try{
          const r = await fetch(API+'pet?ts='+Date.now(), { credentials:'include', cache:'no-store' });
          if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; }
          const j = await r.json();
          renderPet(j.pet||null);
        }catch{ petView.textContent='Pet unavailable right now.'; }
      }

      async function petAction(action, extra={}){
        if(!petStatus) return;
        petStatus.textContent = 'Working...';
        const payload = Object.assign({ action }, extra||{});
        const r = await fetch(API+'pet/action', { method:'POST', credentials:'include', headers:{'content-type':'application/json','X-WP-Nonce':restNonce}, body:JSON.stringify(payload) });
        if(!r.ok){ petStatus.textContent='Action failed.'; return; }
        const j = await r.json();
        renderPet(j.pet||null);
        petStatus.textContent = 'Done.';
      }

async function loadLeaderboard(){
        if(!leaderboard) return;
        const game = leaderboard.getAttribute('data-game') || '';
        if(!game){ leaderboard.textContent='No game selected.'; return; }
        try {
          const r = await fetch(API+'scores?game='+encodeURIComponent(game)+'&ts='+Date.now(), { credentials:'include', cache:'no-store' });
          const j = await r.json();
          const top = Array.isArray(j.top) ? j.top : [];
          if(!top.length){ leaderboard.textContent='Top 3: no scores yet — be the first!'; return; }
          leaderboard.innerHTML = '<strong>Top 3</strong>' + top.map((e,i)=>{
            const nm=((e.name||'Guest')+'').replace(/[<>]/g,'');
            const init=(e.initial||nm.slice(0,1)||'G').toString().toUpperCase();
            const av = e.avatar ? `<span class=\"pph-ava\"><img src=\"${e.avatar}\" alt=\"avatar\" style=\"width:100%;height:100%;object-fit:cover\"></span>` : `<span class=\"pph-ava\">${init}</span>`;
            return `<div class=\"pph-lrow\">${av}<span>#${i+1} ${nm} (${Number(e.score||0)})</span></div>`;
          }).join('');
        } catch {
          leaderboard.textContent='Leaderboard unavailable right now.';
        }
      }

      async function loadWall(){
        try{
          const r=await fetch(API+'pixel-wall?ts='+Date.now(), { credentials:'include', cache:'no-store' }); const j=await r.json();
          const items=(j.items||[]);
          wallGrid.innerHTML = items.map(it=>{
            const who=(it.name||'Community Member').replace(/[<>]/g,'');
            const cap=(it.caption||'').replace(/[<>]/g,'');
            const canDelete = canModerate || (isLoggedIn && sessionUserId > 0 && Number(it.userId||0) === sessionUserId);
            const tags=Array.isArray(it.tags)?it.tags:[];
            const tagTxt=tags.length?(' #'+tags.join(' #')):'';
            const del = canDelete ? `<button class=\"pph-del\" data-id=\"${it.id}\">Remove</button>` : '';
            const feat = canModerate ? `<button class=\"pph-del pph-feature\" data-id=\"${it.id}\" data-f=\"${it.featured?0:1}\">${it.featured?'Unfeature':'Feature'}</button>` : '';
            const badge = it.featured ? `<div class=\"pph-cap\">⭐ Featured</div>` : '';
            return `<a href=\"${it.url}\" target=\"_blank\" rel=\"noopener\"><img src=\"${it.url}\" alt=\"pixel art\"/>${badge}<div class=\"pph-cap\">${who} · ${cap}${tagTxt}</div>${del}${feat}</a>`;
          }).join('') || 'No artwork has been published yet. Be the first to add to the scrapbook.';
          if (isLoggedIn) {
            wallGrid.querySelectorAll('.pph-del').forEach(btn=>btn.addEventListener('click', async (e)=>{
              e.preventDefault();
              const id = btn.getAttribute('data-id');
              if(!id) return;
              const r = await fetch(API+'pixel-wall/'+encodeURIComponent(id), { method:'DELETE', credentials:'include', headers:{'X-WP-Nonce':restNonce} });
              if(!r.ok){
                let msg='Delete failed.';
                if(r.status===401) msg='Please log in first.';
                if(r.status===403) msg='You can only remove your own images (or be admin).';
                alert(msg);
                return;
              }
              loadWall();
            }));
          }
        }catch{ wallGrid.textContent='Gallery unavailable right now.'; }
      }

      chatForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd=new FormData(chatForm);
        const msg=String(fd.get('message')||'');
        const m=msg.match(/^@([^\s]{1,24})\s+/);
        const payload={name:fd.get('name')||'', message:msg, replyTo:m?m[1]:'', age13: !!fd.get('age13')};
        if(!payload.message) return;
        const res=await fetch(API+'chat',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(payload)});
        if(res.ok){ chatForm.reset(); loadChat(); }
      });

      // --- Pixel Studio ---
      const studio = (() => {
        if(!studioCanvas) return null;
        const ctx = studioCanvas.getContext('2d', { alpha: false, willReadFrequently: true });
        const gctx = gridCanvas ? gridCanvas.getContext('2d') : null;
        let grid = Number(gridInput?.value || 64);
        let cell = studioCanvas.width / grid;
        const bg = '#0e1026';
        let drawing = false;
        let start = null;
        let snapshot = null;
        let tool = (toolInput?.value || 'pen');
        let showGrid = !!gridToggle?.checked;
        let history = [];
        let future = [];

        function clamp(v,min,max){ return Math.max(min,Math.min(max,v)); }
        function toPx(g){ return Math.round(g * cell); }
        function pushHistory(){ history.push(ctx.getImageData(0,0,studioCanvas.width,studioCanvas.height)); if(history.length>50) history.shift(); future=[]; }
        function undo(){ if(!history.length) return; future.push(ctx.getImageData(0,0,studioCanvas.width,studioCanvas.height)); ctx.putImageData(history.pop(),0,0); }
        function redo(){ if(!future.length) return; history.push(ctx.getImageData(0,0,studioCanvas.width,studioCanvas.height)); ctx.putImageData(future.pop(),0,0); }

        function drawGuides() {
          if(!gctx) return;
          gctx.clearRect(0,0,gridCanvas.width,gridCanvas.height);
          if(!showGrid) return;
          gctx.save();
          gctx.strokeStyle = 'rgba(255,255,255,0.10)';
          gctx.lineWidth = 1;
          for(let i=1;i<grid;i++){
            const p = Math.round(i*cell)+0.5;
            gctx.beginPath(); gctx.moveTo(p,0); gctx.lineTo(p,gridCanvas.height); gctx.stroke();
            gctx.beginPath(); gctx.moveTo(0,p); gctx.lineTo(gridCanvas.width,p); gctx.stroke();
          }
          gctx.restore();
        }

        function resetCanvas() {
          ctx.fillStyle = bg;
          ctx.fillRect(0,0,studioCanvas.width,studioCanvas.height);
          drawGuides();
          history=[]; future=[];
        }

        function pxFromEvent(e){
          const r=studioCanvas.getBoundingClientRect();
          const x=(e.clientX-r.left)*(studioCanvas.width/r.width);
          const y=(e.clientY-r.top)*(studioCanvas.height/r.height);
          const gx=clamp(Math.floor(x/cell),0,grid-1);
          const gy=clamp(Math.floor(y/cell),0,grid-1);
          return {gx,gy};
        }

        function currentColor(){ return tool==='eraser' ? bg : (colorInput?.value || '#59d9ff'); }

        function setCell(gx,gy,color){
          if(gx<0||gy<0||gx>=grid||gy>=grid) return;
          ctx.fillStyle = color;
          ctx.fillRect(toPx(gx),toPx(gy),Math.ceil(cell),Math.ceil(cell));
        }

        function getCellColor(gx,gy){
          const img = ctx.getImageData(toPx(gx)+1,toPx(gy)+1,1,1).data;
          return [img[0],img[1],img[2]];
        }

        function colorDist(a,b){ return Math.abs(a[0]-b[0])+Math.abs(a[1]-b[1])+Math.abs(a[2]-b[2]); }
        function hexToRgb(hex){ const h=hex.replace('#',''); return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)]; }

        function paint(gx,gy){
          const b=Math.max(1,Number(brushInput?.value||1));
          const col=currentColor();
          for(let yy=0;yy<b;yy++) for(let xx=0;xx<b;xx++) setCell(gx+xx,gy+yy,col);
          drawGuides();
        }

        function drawLine(a,b,col){
          let x0=a.gx,y0=a.gy,x1=b.gx,y1=b.gy;
          const dx=Math.abs(x1-x0), sx=x0<x1?1:-1;
          const dy=-Math.abs(y1-y0), sy=y0<y1?1:-1;
          let err=dx+dy;
          while(true){ setCell(x0,y0,col); if(x0===x1&&y0===y1) break; const e2=2*err; if(e2>=dy){err+=dy;x0+=sx;} if(e2<=dx){err+=dx;y0+=sy;} }
        }

        function drawRect(a,b,col){
          const x0=Math.min(a.gx,b.gx), y0=Math.min(a.gy,b.gy), x1=Math.max(a.gx,b.gx), y1=Math.max(a.gy,b.gy);
          for(let x=x0;x<=x1;x++){ setCell(x,y0,col); setCell(x,y1,col); }
          for(let y=y0;y<=y1;y++){ setCell(x0,y,col); setCell(x1,y,col); }
        }

        function drawCircle(a,b,col){
          const rx=Math.max(1,Math.abs(b.gx-a.gx));
          const ry=Math.max(1,Math.abs(b.gy-a.gy));
          for(let t=0;t<360;t+=2){
            const rad=t*Math.PI/180;
            const x=Math.round(a.gx + rx*Math.cos(rad));
            const y=Math.round(a.gy + ry*Math.sin(rad));
            setCell(x,y,col);
          }
        }

        function floodFill(startX,startY,replHex,smart=false){
          const tol = Number(toleranceInput?.value || 24);
          const target = getCellColor(startX,startY);
          const repl = hexToRgb(replHex);
          if(colorDist(target,repl)<3) return;
          const q=[[startX,startY]];
          const seen=new Set();
          while(q.length){
            const [x,y]=q.pop();
            const key=x+','+y; if(seen.has(key)) continue; seen.add(key);
            if(x<0||y<0||x>=grid||y>=grid) continue;
            const c=getCellColor(x,y);
            if(colorDist(c,target) > (smart ? tol : 8)) continue;
            setCell(x,y,replHex);
            q.push([x+1,y],[x-1,y],[x,y+1],[x,y-1]);
          }
          drawGuides();
        }

        function pickColor(gx,gy){
          const [r,g,b]=getCellColor(gx,gy);
          const hex='#'+[r,g,b].map(v=>v.toString(16).padStart(2,'0')).join('');
          if(colorInput) colorInput.value = hex;
        }

        function previewShape(pos){
          if(!snapshot || !start) return;
          ctx.putImageData(snapshot,0,0);
          const col=currentColor();
          if(tool==='line') drawLine(start,pos,col);
          if(tool==='rect' || tool==='lasso') drawRect(start,pos,col);
          if(tool==='circle') drawCircle(start,pos,col);
          drawGuides();
        }

        studioCanvas.addEventListener('pointerdown', (e)=>{
          const p=pxFromEvent(e);
          tool = toolInput?.value || tool;
          if(['bucket','wand','eyedropper'].includes(tool)){
            pushHistory();
            if(tool==='bucket') floodFill(p.gx,p.gy,currentColor(),false);
            if(tool==='wand') floodFill(p.gx,p.gy,currentColor(),true);
            if(tool==='eyedropper') pickColor(p.gx,p.gy);
            return;
          }
          drawing=true; start=p; pushHistory(); snapshot=ctx.getImageData(0,0,studioCanvas.width,studioCanvas.height);
          if(tool==='pen' || tool==='eraser'){ paint(p.gx,p.gy); }
        });

        window.addEventListener('pointerup', (e)=>{
          if(!drawing) return;
          drawing=false;
          const p=pxFromEvent(e);
          if(['line','rect','circle','lasso'].includes(tool)){
            previewShape(p);
          }
          snapshot=null;
        });

        studioCanvas.addEventListener('pointermove', (e)=>{
          if(!drawing) return;
          const p=pxFromEvent(e);
          if(tool==='pen' || tool==='eraser'){ paint(p.gx,p.gy); return; }
          previewShape(p);
        });

        gridInput?.addEventListener('change', ()=>{
          grid = Number(gridInput.value||64);
          cell = studioCanvas.width / grid;
          resetCanvas();
        });

        toolInput?.addEventListener('change', ()=>{ tool = toolInput.value || 'pen'; });
        gridToggle?.addEventListener('change', ()=>{ showGrid = !!gridToggle.checked; drawGuides(); });
        clearBtn?.addEventListener('click', ()=> resetCanvas());
        undoBtn?.addEventListener('click', ()=> undo());
        redoBtn?.addEventListener('click', ()=> redo());


        function removeImportedGridLines(autoRun=false){
          const img = ctx.getImageData(0,0,studioCanvas.width,studioCanvas.height);
          const d = img.data;
          const step = Math.max(2, Math.round(cell));
          const isDark = (r,g,b)=> (r+g+b) < (autoRun ? 180 : 120);
          const idx=(x,y)=> (y*studioCanvas.width + x)*4;

          // vertical lines
          for(let x=step; x<studioCanvas.width-1; x+=step){
            for(let y=1; y<studioCanvas.height-1; y++){
              const i=idx(x,y), l=idx(x-1,y), r=idx(x+1,y);
              if(isDark(d[i],d[i+1],d[i+2])){
                d[i]=(d[l]+d[r])>>1; d[i+1]=(d[l+1]+d[r+1])>>1; d[i+2]=(d[l+2]+d[r+2])>>1;
              }
            }
          }
          // horizontal lines
          for(let y=step; y<studioCanvas.height-1; y+=step){
            for(let x=1; x<studioCanvas.width-1; x++){
              const i=idx(x,y), u=idx(x,y-1), b=idx(x,y+1);
              if(isDark(d[i],d[i+1],d[i+2])){
                d[i]=(d[u]+d[b])>>1; d[i+1]=(d[u+1]+d[b+1])>>1; d[i+2]=(d[u+2]+d[b+2])>>1;
              }
            }
          }
          ctx.putImageData(img,0,0);
          drawGuides();
        }

        stripGridBtn?.addEventListener('click', ()=>{ pushHistory(); removeImportedGridLines(); });

        importBtn?.addEventListener('click', ()=>{
          const f = importFile?.files?.[0];
          if(!f) return;
          const img = new Image();
          img.onload = ()=>{
            pushHistory();
            ctx.fillStyle = bg; ctx.fillRect(0,0,studioCanvas.width,studioCanvas.height);
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(img,0,0,studioCanvas.width,studioCanvas.height);
            // auto-clean common imported grid lines immediately
            removeImportedGridLines(true);
            drawGuides();
          };
          img.src = URL.createObjectURL(f);
        });

        downloadBtn?.addEventListener('click', ()=>{
          const a=document.createElement('a');
          a.href=studioCanvas.toDataURL('image/png');
          a.download='pixel-art.png';
          a.click();
        });

        postWallBtn?.addEventListener('click', async ()=>{
          postWallBtn.disabled = true;
          postWallBtn.textContent = 'Publishing...';
          const blob = await new Promise(res=>studioCanvas.toBlob(res,'image/png'));
          const fd = new FormData();
          const ts = Date.now();
          fd.append('name','Artist');
          fd.append('caption','Created in Pixel Studio');
          fd.append('image', blob, `pixel-studio-${ts}.png`);
          const res = await fetch(API+'pixel-wall',{method:'POST',credentials:'include',body:fd});
          if(res.ok){ loadWall(); }
          postWallBtn.disabled = false;
          postWallBtn.textContent = 'Publish to Memory Wall';
        });

        resetCanvas();
        if(gridToggle){ showGrid = !!gridToggle.checked; drawGuides(); }
        return { resetCanvas };
      })();

      gameSearch?.addEventListener('input', ()=>{
        const q=(gameSearch.value||'').toLowerCase().trim();
        document.querySelectorAll('.pph-game-chip').forEach(el=>{
          const t=(el.textContent||'').toLowerCase();
          el.style.display = (!q || t.includes(q)) ? '' : 'none';
        });
      });

      stopGameLink?.addEventListener('click', (e)=>{
        if(gameFrame){
          e.preventDefault();
          gameFrame.src = 'about:blank';
          const clean = window.location.pathname;
          history.replaceState({}, '', clean);
        }
      });

            profileForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd = new FormData(profileForm);
        const payload = {
          displayName: String(fd.get('displayName')||'').trim(),
          bio: String(fd.get('bio')||'').trim(),
          favoriteGame: String(fd.get('favoriteGame')||'').trim(),
          themeColor: String(fd.get('themeColor')||'#59d9ff').trim()
        };
        if(profileStatus) profileStatus.textContent='Saving profile...';
        const r = await fetch(API+'profile', { method:'POST', credentials:'include', headers:{'content-type':'application/json','X-WP-Nonce':restNonce}, body:JSON.stringify(payload) });
        if(!r.ok){ if(profileStatus) profileStatus.textContent='Failed to save profile.'; return; }
        if(profileStatus) profileStatus.textContent='Profile updated.';
        await bootstrapSession();
      });

      clearChatBtn?.addEventListener('click', async ()=>{
        if(modStatus) modStatus.textContent='Clearing chat...';
        const r = await fetch(API+'moderation/clear-chat', { method:'POST', credentials:'include', headers:{'X-WP-Nonce':restNonce} });
        if(!r.ok){ if(modStatus) modStatus.textContent='Clear chat failed.'; return; }
        await loadChat();
        if(modStatus) modStatus.textContent='Chat cleared.';
      });

      resetScoresBtn?.addEventListener('click', async ()=>{
        const game = (resetGameInput?.value||'').trim();
        if(modStatus) modStatus.textContent='Resetting scores...';
        const r = await fetch(API+'scores/reset', { method:'POST', credentials:'include', headers:{'content-type':'application/json','X-WP-Nonce':restNonce}, body: JSON.stringify({game}) });
        if(!r.ok){ if(modStatus) modStatus.textContent='Reset scores failed.'; return; }
        await loadLeaderboard();
        if(modStatus) modStatus.textContent = game ? ('Scores reset for '+game) : 'All scores reset.';
      });

      petFeedBtn?.addEventListener('click', ()=>petAction('feed'));
      petPlayBtn?.addEventListener('click', ()=>petAction('play'));
      petRestBtn?.addEventListener('click', ()=>petAction('rest'));
      petRenameBtn?.addEventListener('click', ()=>petAction('rename', { name:(petNameInput?.value||'').trim() }));
      petSkinSaveBtn?.addEventListener('click', ()=>petAction('setskin', { skin:(petSkinSelect?.value||'default') }));

      clearWallBtn?.addEventListener('click', async ()=>{
        if(modStatus) modStatus.textContent='Clearing wall...';
        const r = await fetch(API+'moderation/clear-wall', { method:'POST', credentials:'include', headers:{'X-WP-Nonce':restNonce} });
        if(!r.ok){ if(modStatus) modStatus.textContent='Clear wall failed.'; return; }
        await loadWall();
        if(modStatus) modStatus.textContent='Wall cleared.';
      });

      registerForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd = new FormData(registerForm);
        const payload = {
          username: String(fd.get('username')||'').trim(),
          email: String(fd.get('email')||'').trim(),
          password: String(fd.get('password')||''),
          age13: !!fd.get('age13')
        };
        if(registerStatus) registerStatus.textContent = 'Creating account...';
        const r = await fetch(API+'register', {
          method:'POST',
          credentials:'include',
          headers:{'content-type':'application/json'},
          body: JSON.stringify(payload)
        });
        const j = await r.json().catch(()=>({}));
        if(!r.ok){
          const map = {
            invalid_username:'Invalid username',
            invalid_email:'Invalid email',
            weak_password:'Password too short (min 8)',
            username_taken:'Username already taken',
            email_taken:'Email already used',
            rate_limited:'Please wait and try again'
          };
          if(registerStatus) registerStatus.textContent = 'Signup failed: ' + (map[j.error] || j.error || r.status);
          return;
        }
        if(registerStatus) registerStatus.textContent = 'Account created. Reloading...';
        setTimeout(()=>window.location.reload(), 600);
      });

gameMetaForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd = new FormData(gameMetaForm);
        const payload = {
          slug:String(fd.get('slug')||'').trim(),
          category:String(fd.get('category')||'').trim(),
          difficulty:String(fd.get('difficulty')||'').trim(),
          controls:String(fd.get('controls')||'').trim(),
          description:String(fd.get('description')||'').trim(),
        };
        if(gameMetaStatus) gameMetaStatus.textContent='Saving game metadata...';
        const r = await fetch(API+'games/meta',{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':restNonce},body:JSON.stringify(payload)});
        if(!r.ok){ if(gameMetaStatus) gameMetaStatus.textContent='Failed to save metadata.'; return; }
        if(gameMetaStatus) gameMetaStatus.textContent='Game metadata saved.';
        loadGameMeta();
      });

      gameUploadForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        await bootstrapSession();
        if(!isLoggedIn){
          if(gameUploadStatus) gameUploadStatus.textContent = 'Please log in first, then try upload again.';
          return;
        }
        const fd = new FormData(gameUploadForm);
        if(gameUploadStatus) gameUploadStatus.textContent = 'Uploading game...';
        let r = await fetch(API+'games', { method:'POST', credentials:'include', headers:{'X-WP-Nonce':restNonce}, body:fd });
        let j = await r.json().catch(()=>({}));
        if((r.status===401 || r.status===403) && (j.error==='rest_cookie_invalid_nonce' || j.code==='rest_cookie_invalid_nonce')){
          await bootstrapSession();
          r = await fetch(API+'games', { method:'POST', credentials:'include', headers:{'X-WP-Nonce':restNonce}, body:fd });
          j = await r.json().catch(()=>({}));
        }
        if(!r.ok){
          const map={missing_file:'No file selected.',upload_failed:'Upload failed.',invalid_zip:'Invalid zip file.',zip_not_supported:'Zip support missing on server.',unsupported_file:'Use .zip or .html.',no_html_found:'No index.html or .html found in upload.',move_failed:'Server could not save file.'};
          if(gameUploadStatus) gameUploadStatus.textContent = 'Upload failed: ' + (map[j.error] || j.error || r.status);
          return;
        }
        const checks = Array.isArray(j.checks)?j.checks:[];
        const qa = checks.length ? (' QA: '+checks.map(c=>`${c.name}:${c.ok?'ok':'warn'}`).join(', ')) : '';
        if(gameUploadStatus) gameUploadStatus.textContent = 'Game uploaded.'+qa+' Reloading...';
        const slug = j.slug ? String(j.slug) : '';
        setTimeout(()=>{ window.location.href = slug ? ('?game='+encodeURIComponent(slug)) : window.location.href; }, 500);
      });

      wallForm?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const fd=new FormData(wallForm);
        const res=await fetch(API+'pixel-wall',{method:'POST',body:fd});
        if(res.ok){ wallForm.reset(); loadWall(); }
      });

      bootstrapSession().then(()=>{ loadChat(); loadWall(); loadGameMeta(); loadLeaderboard(); loadPet(); });
      setInterval(async ()=>{ await bootstrapSession(); loadChat(); loadWall(); loadGameMeta(); loadLeaderboard(); loadPet(); }, 20000);
    })();
    </script>
    <?php
    return ob_get_clean();
});

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    wp_enqueue_style('prismtek-pixel-font', 'https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap', [], null);

    $css = <<<'CSS'
:root {
  --pv-bg-soft: #191a38;
  --pv-line: #7e85ff;
  --pv-accent: #7f65ff;
  --pv-accent-2: #59d9ff;
  --pv-text: #f2f4ff;
  --pv-muted: #b7bee9;
}

body.home,
body.page {
  color: var(--pv-text) !important;
  background: #0f1023 !important;
  font-family: "Press Start 2P", ui-monospace, Menlo, Consolas, monospace !important;
}

html, body {
  width: 100%;
  min-height: 100%;
  overflow-x: hidden;
  background: #0f1023 !important;
}

.entry-content p,
.entry-content li,
.entry-content a,
.ast-footer-copyright,
.pph-wrap, .pph-wrap * {
  font-family: "Press Start 2P", ui-monospace, Menlo, Consolas, monospace !important;
}

#pv-scene {
  position: fixed;
  top: -24vh;
  right: -24vw;
  bottom: -24vh;
  left: -24vw;
  z-index: 0;
  pointer-events: none;
  overflow: hidden;
}

#pv-scene .pv-canvas {
  width: 100%;
  height: 100%;
  display: block;
  image-rendering: pixelated;
  image-rendering: crisp-edges;
  transform-origin: 50% 55%;
  animation: pvZoomQuilt 45s linear infinite, pvDayCycle 140s linear infinite;
}

#pv-scene .pv-cloud-back { animation: pvCloudPulse 20s ease-in-out infinite; }
#pv-scene .pv-cloud-front { animation: pvCloudPulse 14s ease-in-out infinite; }
#pv-scene .pv-stars { animation: pvStarCycle 140s linear infinite; }
#pv-scene .pv-rain { animation: pvRainCycle 20s linear infinite; }
#pv-scene .pv-water-shimmer { animation: pvWater 5.6s steps(6, end) infinite; }
#pv-scene .pv-firefly { animation: pvFirefly 3.9s steps(4, end) infinite; }
#pv-scene .pv-firefly.f2 { animation-delay: .8s; }
#pv-scene .pv-firefly.f3 { animation-delay: 1.4s; }
#pv-scene .pv-firefly.f4 { animation-delay: 2s; }
#pv-scene .pv-firefly.f5 { animation-delay: 2.7s; }
#pv-scene .pv-bird { animation: pvBird 6.2s steps(2, end) infinite; }
#pv-scene .pv-rabbit { animation: pvHop 2.8s steps(3, end) infinite; }
#pv-scene .pv-deer { animation: pvGraze 5.4s steps(2, end) infinite; }

body.home::before,
body.page::before {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 1;
  background: repeating-linear-gradient(
    to bottom,
    rgba(255,255,255,.03) 0,
    rgba(255,255,255,.03) 1px,
    transparent 1px,
    transparent 3px
  );
}

.site,
#page,
.site-content,
.ast-plain-container,
.ast-page-builder-template,
.ast-separate-container,
.ast-plain-container #content,
.ast-plain-container #content .ast-container,
.ast-plain-container #primary,
.ast-plain-container #main {
  background: transparent !important;
}

#page, #content, .site-content, .site-main, .entry-content, .ast-container, .site-primary-header-wrap, .site-below-footer-wrap {
  position: relative;
  z-index: 2;
}

.ast-main-header-wrap,
.ast-primary-header-bar,
.ast-mobile-header-wrap .ast-primary-header-bar {
  width: 100vw !important;
  max-width: 100vw !important;
  box-sizing: border-box !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
  left: 50%;
  transform: translateX(-50%);
  position: relative;
  padding-left: 12px !important;
  padding-right: 12px !important;
  border-left: 0 !important;
  border-right: 0 !important;
}

.site-primary-header-wrap.ast-container,
.ast-builder-grid-row-container.site-header-focus-item.ast-container,
.ast-builder-grid-row-container .ast-builder-grid-row {
  width: 100% !important;
  max-width: 100% !important;
  margin: 0 auto !important;
  box-sizing: border-box !important;
}

.main-header-menu {
  display: flex !important;
  flex-wrap: wrap !important;
  row-gap: 6px !important;
}

.site-header,
.ast-primary-header-bar,
.ast-mobile-header-wrap .ast-primary-header-bar,
.ast-mobile-popup-inner,
.ast-separate-container .ast-article-single,
.ast-separate-container .ast-article-post,
.ast-separate-container .comments-area,
.ast-separate-container .ast-archive-description,
.ast-separate-container.ast-two-container #secondary .widget {
  background: rgba(25, 26, 56, .78) !important;
  backdrop-filter: blur(1.2px);
  border: 2px solid var(--pv-line) !important;
  border-radius: 0 !important;
  box-shadow: 6px 6px 0 rgba(61, 68, 138, .8) !important;
}

/* Prevent top nav shadow from overlapping page headings */
.site-header,
.ast-primary-header-bar,
.ast-mobile-header-wrap .ast-primary-header-bar {
  box-shadow: 0 3px 0 rgba(61, 68, 138, .75) !important;
  margin-bottom: 8px !important;
}

.site-content,
#content,
.ast-container {
  padding-top: 6px !important;
}

.site-title a,
.main-header-menu > .menu-item > .menu-link,
h1,h2,h3,h4,h5,h6,
.entry-title,
.wp-block-button__link,
button, .ast-button, .ast-custom-button, input[type="submit"] {
  font-family: "Press Start 2P", ui-monospace, Menlo, Consolas, monospace !important;
  letter-spacing: .02em;
}

.main-header-menu > .menu-item > .menu-link {
  text-transform: uppercase;
  font-size: .72rem !important;
  color: #dbe0ff !important;
  white-space: normal !important;
  line-height: 1.2 !important;
}

.entry-title, .entry-content h2, .entry-content h3 {
  color: #ffffff !important;
  text-shadow: 2px 2px 0 rgba(0,0,0,.75);
}

.entry-content, .entry-content p, .entry-content li, .entry-content span, .site-footer, .ast-footer-copyright {
  color: #ffffff !important;
  text-shadow: 1px 1px 0 #000 !important;
}

a, a:visited {
  color: #ffffff !important;
  text-shadow: 1px 1px 0 #000 !important;
  text-decoration: underline;
}
a:hover, a:focus { color: #ffffff !important; }

.entry-content ol {
  list-style-position: outside !important;
  padding-left: 1.8rem !important;
  margin-left: 0 !important;
}
.entry-content ol li {
  padding-left: .2rem !important;
}
@media (max-width: 768px) {
  .entry-content ol { padding-left: 1.4rem !important; }
}

.page-grid{display:grid;gap:14px;margin-top:10px}
.page-card{background:rgba(24,25,58,.88);border:2px solid var(--pv-line);box-shadow:6px 6px 0 rgba(61,68,138,.68);padding:14px}
.page-card h3{margin-top:0}
.page-links{display:flex;gap:8px;flex-wrap:wrap}
.page-pill{display:inline-block;padding:6px 10px;border:1px solid #4c5498;background:#11173b;color:#fff;text-decoration:none}
.page-grid{max-width:980px}
.page-card{border-width:2px;transition:transform .12s ease, box-shadow .12s ease}
.page-card:hover{transform:translateY(-1px);box-shadow:8px 8px 0 rgba(61,68,138,.62)}
.page-card p{line-height:1.6}.entry-content h2,.entry-content h3{margin-bottom:.45rem}.entry-content p{margin:.5rem 0 1rem}.page-links{margin-top:.45rem}
.pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.45;min-height:52px}


.wp-block-button__link,
button,
.ast-button,
.ast-custom-button,
input[type="submit"],
.menu-toggle {
  border: 2px solid #dfe3ff !important;
  border-radius: 0 !important;
  box-shadow: 4px 4px 0 rgba(55, 63, 125, .9) !important;
  text-transform: uppercase;
  background: linear-gradient(135deg, var(--pv-accent) 0%, var(--pv-accent-2) 100%) !important;
  color: #fff !important;
}

.wp-block-button__link:hover,
button:hover,
.ast-button:hover,
.ast-custom-button:hover,
input[type="submit"]:hover,
.menu-toggle:hover {
  transform: translate(-1px, -1px);
  box-shadow: 6px 6px 0 rgba(55, 63, 125, .95) !important;
}

@keyframes pvZoomQuilt {
  0% { transform: scale(1); }
  100% { transform: scale(1.55); }
}
@keyframes pvCloudPulse {
  0%,100% { opacity: .72; }
  50% { opacity: .9; }
}
@keyframes pvWater {
  0%,100% { opacity: .22; }
  40% { opacity: .62; }
  65% { opacity: .35; }
}
@keyframes pvDayCycle {
  0%, 22% { filter: brightness(1) saturate(1); }
  35% { filter: brightness(1.05) saturate(1.05); }
  52% { filter: brightness(.9) saturate(1.08) hue-rotate(-6deg); }
  68% { filter: brightness(.62) saturate(.9) hue-rotate(12deg); }
  82% { filter: brightness(.52) saturate(.84) hue-rotate(18deg); }
  100% { filter: brightness(1) saturate(1); }
}
@keyframes pvStarCycle {
  0%, 58%, 100% { opacity: 0; }
  70%, 84% { opacity: .65; }
}
@keyframes pvRainCycle {
  0%, 72%, 100% { opacity: 0; }
  78%, 90% { opacity: .36; }
}
@keyframes pvFirefly { 0%,100% { opacity:.18; } 45% { opacity:.82; } }
@keyframes pvBird { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-1px); } }
@keyframes pvHop { 0%,100% { transform: translateY(0); } 40% { transform: translateY(-2px); } }
@keyframes pvGraze { 0%,100% { transform: translateY(0); } 50% { transform: translateY(1px); } }

@media (max-width:900px) {
  #pv-scene .pv-firefly, #pv-scene .pv-animal, #pv-scene .pv-bird { display:none; }
}
@media (max-width:768px) {
  .main-header-menu > .menu-item > .menu-link { font-size: .58rem !important; }
  .ast-primary-header-bar,
  .ast-mobile-header-wrap .ast-primary-header-bar {
    padding-left: 8px !important;
    padding-right: 8px !important;
  }
}
CSS;

    wp_register_style('prismtek-pixel-vibes', false, [], null);
    wp_enqueue_style('prismtek-pixel-vibes');
    wp_add_inline_style('prismtek-pixel-vibes', $css);
}, 9999);

function prismtek_pixel_scene_markup_once() {
    static $printed = false;
    if ($printed || is_admin()) return;
    $printed = true;

    echo <<<'HTML'
<div id="pv-scene" aria-hidden="true">
  <svg class="pv-canvas" viewBox="0 0 320 180" preserveAspectRatio="xMidYMid slice" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <linearGradient id="pvSky" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#5f8fe0"/>
        <stop offset="35%" stop-color="#8dbbff"/>
        <stop offset="65%" stop-color="#c3deff"/>
        <stop offset="100%" stop-color="#d6ebff"/>
      </linearGradient>
      <linearGradient id="pvHillBack" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#6fae66"/>
        <stop offset="100%" stop-color="#4f8548"/>
      </linearGradient>
      <linearGradient id="pvHillFront" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#7fc56d"/>
        <stop offset="100%" stop-color="#456f40"/>
      </linearGradient>
    </defs>

    <rect x="0" y="0" width="320" height="180" fill="url(#pvSky)"/>

    <g class="pv-cloud-back" fill="#eef7ff" opacity=".48">
      <rect x="22" y="22" width="30" height="6"/><rect x="40" y="18" width="32" height="8"/><rect x="65" y="24" width="26" height="6"/>
      <rect x="152" y="27" width="24" height="6"/><rect x="168" y="23" width="30" height="8"/><rect x="194" y="29" width="22" height="6"/>
      <rect x="254" y="19" width="22" height="6"/><rect x="270" y="15" width="28" height="8"/><rect x="294" y="22" width="24" height="6"/>
    </g>

    <g class="pv-cloud-front" fill="#ffffff" opacity=".74">
      <rect x="10" y="40" width="34" height="8"/><rect x="28" y="36" width="38" height="10"/><rect x="56" y="42" width="30" height="8"/>
      <rect x="200" y="48" width="36" height="8"/><rect x="222" y="44" width="40" height="10"/><rect x="252" y="50" width="28" height="8"/>
    </g>

    <rect x="258" y="26" width="26" height="26" fill="#ffe79a" opacity=".45"/><rect x="262" y="30" width="18" height="18" fill="#ffd261"/>

    <polygon points="0,98 38,64 62,86 96,58 126,90 166,54 198,88 236,60 272,92 320,64 320,120 0,120" fill="#7892be"/>
    <polygon points="0,110 30,82 54,98 90,72 120,102 150,76 188,104 224,78 256,106 290,82 320,102 320,126 0,126" fill="#617da8"/>

    <path d="M0 132 C32 118, 58 122, 88 132 C118 142, 146 136, 176 128 C210 120, 238 122, 270 132 C292 139, 307 138, 320 132 L320 180 L0 180 Z" fill="url(#pvHillBack)"/>
    <path d="M0 146 C30 132, 58 134, 92 144 C124 154, 156 150, 186 142 C214 134, 246 136, 278 146 C298 152, 310 151, 320 147 L320 180 L0 180 Z" fill="url(#pvHillFront)"/>

    <g fill="#5a934f" opacity=".52"><rect x="0" y="150" width="320" height="1"/><rect x="0" y="156" width="320" height="1"/><rect x="0" y="162" width="320" height="1"/><rect x="0" y="168" width="320" height="1"/></g>

    <g>
      <polygon points="20,142 28,126 36,142" fill="#2b5d3a"/><rect x="27" y="142" width="2" height="6" fill="#3e2c20"/>
      <polygon points="48,140 58,118 68,140" fill="#2f6a40"/><rect x="57" y="140" width="2" height="6" fill="#3e2c20"/>
      <polygon points="84,142 92,128 100,142" fill="#2a5f3a"/><rect x="91" y="142" width="2" height="6" fill="#3e2c20"/>
      <polygon points="118,145 129,121 140,145" fill="#2f6b40"/><rect x="128" y="145" width="2" height="6" fill="#3e2c20"/>
      <polygon points="154,144 164,123 174,144" fill="#2c643d"/><rect x="163" y="144" width="2" height="6" fill="#3e2c20"/>
      <polygon points="194,142 203,125 212,142" fill="#2a603a"/><rect x="202" y="142" width="2" height="6" fill="#3e2c20"/>
      <polygon points="226,145 237,122 248,145" fill="#2e6a40"/><rect x="236" y="145" width="2" height="6" fill="#3e2c20"/>
      <polygon points="266,143 275,126 284,143" fill="#295d38"/><rect x="274" y="143" width="2" height="6" fill="#3e2c20"/>
      <polygon points="292,144 302,124 312,144" fill="#2d653d"/><rect x="301" y="144" width="2" height="6" fill="#3e2c20"/>
    </g>

    <g>
      <rect x="12" y="154" width="2" height="2" fill="#ffb5cf"/><rect x="36" y="160" width="2" height="2" fill="#ffe58a"/><rect x="68" y="152" width="2" height="2" fill="#9df2ff"/>
      <rect x="96" y="166" width="2" height="2" fill="#ffc4de"/><rect x="124" y="156" width="2" height="2" fill="#ffe58a"/><rect x="162" y="162" width="2" height="2" fill="#9df2ff"/>
      <rect x="194" y="154" width="2" height="2" fill="#ffb5cf"/><rect x="228" y="166" width="2" height="2" fill="#ffe58a"/><rect x="256" y="156" width="2" height="2" fill="#9df2ff"/><rect x="292" y="160" width="2" height="2" fill="#ffc4de"/>
    </g>

    <g class="pv-animal pv-rabbit" fill="#f7efe4"><rect x="90" y="158" width="4" height="3"/><rect x="91" y="156" width="1" height="2"/><rect x="93" y="156" width="1" height="2"/><rect x="94" y="159" width="1" height="1" fill="#d9c9b9"/></g>
    <g class="pv-animal pv-rabbit" fill="#efe8de"><rect x="206" y="164" width="4" height="3"/><rect x="207" y="162" width="1" height="2"/><rect x="209" y="162" width="1" height="2"/></g>
    <g class="pv-animal pv-deer" fill="#9e6b45"><rect x="144" y="158" width="7" height="3"/><rect x="151" y="157" width="2" height="2"/><rect x="145" y="161" width="1" height="3"/><rect x="149" y="161" width="1" height="3"/><rect x="152" y="158" width="1" height="1" fill="#e6d8c6"/></g>

    <g class="pv-bird" fill="#1c2d3e" opacity=".8"><rect x="120" y="56" width="2" height="1"/><rect x="123" y="56" width="2" height="1"/><rect x="124" y="55" width="1" height="1"/></g>
    <g class="pv-bird" fill="#203245" opacity=".8"><rect x="212" y="52" width="2" height="1"/><rect x="215" y="52" width="2" height="1"/><rect x="216" y="51" width="1" height="1"/></g>

    <g class="pv-stars" fill="#f3f7ff" opacity="0">
      <rect x="24" y="18" width="1" height="1"/><rect x="48" y="12" width="1" height="1"/><rect x="92" y="20" width="1" height="1"/>
      <rect x="134" y="14" width="1" height="1"/><rect x="176" y="22" width="1" height="1"/><rect x="218" y="10" width="1" height="1"/>
      <rect x="246" y="18" width="1" height="1"/><rect x="278" y="12" width="1" height="1"/><rect x="306" y="20" width="1" height="1"/>
    </g>

    <g class="pv-rain" stroke="#cfe6ff" stroke-width="1" opacity="0">
      <line x1="24" y1="80" x2="22" y2="86"/><line x1="52" y1="84" x2="50" y2="90"/><line x1="80" y1="76" x2="78" y2="82"/>
      <line x1="108" y1="82" x2="106" y2="88"/><line x1="136" y1="78" x2="134" y2="84"/><line x1="164" y1="86" x2="162" y2="92"/>
      <line x1="192" y1="80" x2="190" y2="86"/><line x1="220" y1="84" x2="218" y2="90"/><line x1="248" y1="78" x2="246" y2="84"/>
      <line x1="276" y1="86" x2="274" y2="92"/><line x1="304" y1="80" x2="302" y2="86"/>
    </g>

    <g fill="#fff4b4"><rect class="pv-firefly f1" x="56" y="138" width="2" height="2"/><rect class="pv-firefly f2" x="104" y="134" width="2" height="2"/><rect class="pv-firefly f3" x="170" y="140" width="2" height="2"/><rect class="pv-firefly f4" x="236" y="136" width="2" height="2"/><rect class="pv-firefly f5" x="282" y="132" width="2" height="2"/></g>
  </svg>
</div>
HTML;
}

add_action('wp_body_open', 'prismtek_pixel_scene_markup_once', 1);
add_action('wp_footer', 'prismtek_pixel_scene_markup_once', 1);


add_filter('show_admin_bar', '__return_false');


function prismtek_pixel_find_latest_iso_url() {
    $repos = ['codysumpter-cloud/PrismBot-Public', 'codysumpter-cloud/omni-bmo'];
    foreach ($repos as $repo) {
        $api = 'https://api.github.com/repos/' . $repo . '/releases';
        $res = wp_remote_get($api, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'prismtek-dev-site',
                'Accept' => 'application/vnd.github+json',
            ],
        ]);

    


        if (is_wp_error($res)) continue;
        $body = wp_remote_retrieve_body($res);
        $rows = json_decode($body, true);
        if (!is_array($rows)) continue;
        foreach ($rows as $rel) {
            $assets = is_array($rel['assets'] ?? null) ? $rel['assets'] : [];
            foreach ($assets as $a) {
                $name = strtolower((string) ($a['name'] ?? ''));
                if (str_ends_with($name, '.iso')) {
                    $u = (string) ($a['browser_download_url'] ?? '');
                    if ($u) return $u;
                }
            }
        }
    }
    return 'https://github.com/codysumpter-cloud/PrismBot-Public/releases';
}

add_action('template_redirect', function () {
    if (!isset($_GET['prismtek_iso_dl'])) return;
    $url = prismtek_pixel_find_latest_iso_url();
    wp_redirect($url, 302);
    exit;
});

add_action('init', function () {
    if (get_option('users_can_register') != '1') update_option('users_can_register', '1');
    if (!get_option('default_role')) update_option('default_role', 'subscriber');
});

add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if (!is_wp_error($user) && $user) return home_url('/pixel-arcade/');
    return $redirect_to;
}, 10, 3);


add_filter('auth_cookie_expiration', function ($length, $user_id, $remember) {
    // Keep users signed in by default until they explicitly log out.
    return 60 * DAY_IN_SECONDS;
}, 99, 3);


function prismtek_pixel_nocache() {
    if (is_admin()) return;
    if (function_exists('is_page') && is_page('pixel-arcade')) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
}
add_action('template_redirect', 'prismtek_pixel_nocache', 0);

// Restored portal shortcodes used by split pages.
if (!function_exists('prismtek_render_portal_view')) {
    function prismtek_render_portal_view($mode = 'hub') {
        $mode = sanitize_key((string)$mode);
        $css = '';

        if ($mode === 'games') {
            $css = '
            .pph-wrap > h2, .pph-wrap > p { display:none !important; }
            .pph-toggle[data-toggle-key="account"],
            .pph-toggle[data-toggle-key="chat"],
            .pph-toggle[data-toggle-key="studio"],
            .pph-toggle[data-toggle-key="wall"],
            #pph-pet-panel,
            #pph-clear-chat,
            #pph-clear-wall,
            #pph-reset-scores { display:none !important; }
            .pph-toggle[data-toggle-key="games"] { display:block !important; }
            .pph-toggle[data-toggle-key="games"] > summary { display:none !important; }
            ';
        } elseif ($mode === 'studio') {
            $css = '
            .pph-wrap > h2, .pph-wrap > p { display:none !important; }
            .pph-toggle[data-toggle-key="account"],
            .pph-toggle[data-toggle-key="games"],
            .pph-toggle[data-toggle-key="chat"],
            .pph-toggle[data-toggle-key="wall"],
            #pph-pet-panel,
            #pph-clear-chat,
            #pph-clear-wall,
            #pph-reset-scores { display:none !important; }
            .pph-toggle[data-toggle-key="studio"] { display:block !important; }
            .pph-toggle[data-toggle-key="studio"] > summary { display:none !important; }
            ';
        } elseif ($mode === 'creatures') {
            $css = '
            .pph-wrap > h2, .pph-wrap > p { display:none !important; }
            .pph-toggle[data-toggle-key="games"],
            .pph-toggle[data-toggle-key="chat"],
            .pph-toggle[data-toggle-key="studio"],
            .pph-toggle[data-toggle-key="wall"],
            .pph-toggle[data-toggle-key="account"] > p,
            .pph-toggle[data-toggle-key="account"] > form,
            .pph-toggle[data-toggle-key="account"] .pph-tool-row,
            #pph-clear-chat,
            #pph-clear-wall,
            #pph-reset-scores { display:none !important; }
            #pph-pet-panel { display:block !important; }
            #pph-pet-panel > details > summary { display:none !important; }
            ';
        }

        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]');
    }
}

if (!shortcode_exists('prism_games_portal')) {
    add_shortcode('prism_games_portal', function () {
        return prismtek_render_portal_view('games');
    });
}

if (!shortcode_exists('prism_studio_pro_page')) {
    add_shortcode('prism_studio_pro_page', function () {
        return prismtek_render_portal_view('studio');
    });
}

if (!shortcode_exists('prism_creatures_portal')) {
    add_shortcode('prism_creatures_portal', function () {
        return prismtek_render_portal_view('creatures');
    });
}

// ===== Portal shortcode hotfix (2026-03-09) =====
add_action('init', function () {
    // Override prior portal shortcodes with cleaner implementations.
    remove_shortcode('prism_games_portal');
    remove_shortcode('prism_studio_pro_page');
    remove_shortcode('prism_creatures_portal');

    add_shortcode('prism_games_portal', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; }
        .pph-toggle[data-toggle-key="account"],
        .pph-toggle[data-toggle-key="chat"],
        .pph-toggle[data-toggle-key="studio"],
        .pph-toggle[data-toggle-key="wall"],
        #pph-pet-panel,
        #pph-clear-chat,
        #pph-clear-wall,
        #pph-reset-scores { display:none !important; }
        .pph-toggle[data-toggle-key="games"] { display:block !important; }
        .pph-toggle[data-toggle-key="games"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        ';
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]');
    });

    add_shortcode('prism_studio_pro_page', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; }
        .pph-toggle[data-toggle-key="account"],
        .pph-toggle[data-toggle-key="games"],
        .pph-toggle[data-toggle-key="chat"],
        .pph-toggle[data-toggle-key="wall"],
        #pph-pet-panel,
        #pph-clear-chat,
        #pph-clear-wall,
        #pph-reset-scores { display:none !important; }
        .pph-toggle[data-toggle-key="studio"] { display:block !important; }
        .pph-toggle[data-toggle-key="studio"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        ';
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]');
    });

    add_shortcode('prism_creatures_portal', function () {
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));

        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap">
          <h2>Prism Creatures</h2>
          <p>Creature-only tab: growth, customization, social visits, and battle prototype.</p>

          <?php if (!$logged): ?>
            <article class="pph-card">
              <p>Log in to care for your creature.</p>
              <p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p>
            </article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel">
              <h3>My Pixel Creature</h3>
              <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
              <div class="pph-tool-row">
                <button type="button" id="pph-pet-feed">Feed</button>
                <button type="button" id="pph-pet-play">Play</button>
                <button type="button" id="pph-pet-rest">Rest</button>
              </div>
              <div class="pph-tool-row">
                <input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" />
                <button type="button" id="pph-pet-rename">Save Name</button>
              </div>
              <div class="pph-tool-row">
                <select id="pph-pet-skin"><option value="default">default</option></select>
                <button type="button" id="pph-pet-skin-save">Apply Skin</button>
              </div>
              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>

          <article class="pph-card">
            <h3>Creature Showcase</h3>
            <?php echo do_shortcode('[prism_pet_showcase]'); ?>
          </article>
        </section>
        <script>
        (()=>{
          const API = '<?php echo esc_js($api); ?>';
          const nonce = '<?php echo esc_js($nonce); ?>';
          const petView = document.getElementById('pph-pet-view');
          if(!petView) return;
          const petStatus = document.getElementById('pph-pet-status');
          const petNameInput = document.getElementById('pph-pet-name');
          const petSkinSelect = document.getElementById('pph-pet-skin');

          function renderPet(pet){
            if(!pet) return;
            const mood = (pet.happiness>70 && pet.energy>50) ? 'Happy' : (pet.health<35 ? 'Sickly' : 'Okay');
            petView.innerHTML = `<strong>${pet.name||'Prismo'}</strong> (${pet.species||'blob'}) · ${mood}<br>Stage ${pet.stage||'baby'} · Skin ${pet.skin||'default'}<br>Hunger ${pet.hunger}% · Happiness ${pet.happiness}% · Energy ${pet.energy}% · Health ${pet.health}%`;
            if(petNameInput && !petNameInput.value) petNameInput.value = pet.name || '';
            if(petSkinSelect){
              const skins = (pet.unlocks && Array.isArray(pet.unlocks.skins)) ? pet.unlocks.skins : ['default'];
              petSkinSelect.innerHTML = skins.map(sk=>`<option value="${sk}" ${sk===(pet.skin||'default')?'selected':''}>${sk}</option>`).join('');
            }
          }

          async function loadPet(){
            try{
              const r = await fetch(API+'pet?ts='+Date.now(), { credentials:'include', cache:'no-store', headers: nonce ? {'X-WP-Nonce':nonce} : {} });
              if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; }
              const j = await r.json(); renderPet(j.pet||null);
            }catch{ petView.textContent='Creature unavailable right now.'; }
          }

          async function petAction(action, extra={}){
            if(petStatus) petStatus.textContent = 'Working...';
            const r = await fetch(API+'pet/action', { method:'POST', credentials:'include', headers:{'content-type':'application/json','X-WP-Nonce':nonce}, body:JSON.stringify(Object.assign({action}, extra||{})) });
            if(!r.ok){ if(petStatus) petStatus.textContent='Action failed.'; return; }
            const j = await r.json(); renderPet(j.pet||null); if(petStatus) petStatus.textContent='Done.';
          }

          document.getElementById('pph-pet-feed')?.addEventListener('click', ()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click', ()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click', ()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click', ()=>petAction('rename', {name:(petNameInput?.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click', ()=>petAction('setskin', {skin: petSkinSelect?.value||'default'}));
          loadPet();
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Portal force-open hotfix (2026-03-09b) =====
add_action('init', function () {
    remove_shortcode('prism_games_portal');
    remove_shortcode('prism_studio_pro_page');

    add_shortcode('prism_games_portal', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; margin:0 !important; }
        .pph-toggle[data-toggle-key="account"],
        .pph-toggle[data-toggle-key="chat"],
        .pph-toggle[data-toggle-key="studio"],
        .pph-toggle[data-toggle-key="wall"],
        #pph-pet-panel,
        #pph-clear-chat,
        #pph-clear-wall,
        #pph-reset-scores,
        .pph-status:empty { display:none !important; }
        .pph-toggle[data-toggle-key="games"] { display:block !important; }
        .pph-toggle[data-toggle-key="games"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        ';
        $js = "<script>(function(){document.addEventListener('DOMContentLoaded',function(){var g=document.querySelector('.pph-toggle[data-toggle-key=\"games\"]');if(g){g.open=true;}['account','chat','studio','wall'].forEach(function(k){var el=document.querySelector('.pph-toggle[data-toggle-key=\"'+k+'\"]');if(el){el.open=false;}});try{localStorage.setItem('pph_toggle_games','1');localStorage.setItem('pph_toggle_account','0');localStorage.setItem('pph_toggle_chat','0');localStorage.setItem('pph_toggle_studio','0');localStorage.setItem('pph_toggle_wall','0');}catch(e){}});})();</script>";
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]') . $js;
    });

    add_shortcode('prism_studio_pro_page', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; margin:0 !important; }
        .pph-toggle[data-toggle-key="account"],
        .pph-toggle[data-toggle-key="games"],
        .pph-toggle[data-toggle-key="chat"],
        .pph-toggle[data-toggle-key="wall"],
        #pph-pet-panel,
        #pph-clear-chat,
        #pph-clear-wall,
        #pph-reset-scores,
        .pph-status:empty { display:none !important; }
        .pph-toggle[data-toggle-key="studio"] { display:block !important; }
        .pph-toggle[data-toggle-key="studio"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        ';
        $js = "<script>(function(){document.addEventListener('DOMContentLoaded',function(){var s=document.querySelector('.pph-toggle[data-toggle-key=\"studio\"]');if(s){s.open=true;}['account','chat','games','wall'].forEach(function(k){var el=document.querySelector('.pph-toggle[data-toggle-key=\"'+k+'\"]');if(el){el.open=false;}});try{localStorage.setItem('pph_toggle_studio','1');localStorage.setItem('pph_toggle_account','0');localStorage.setItem('pph_toggle_chat','0');localStorage.setItem('pph_toggle_games','0');localStorage.setItem('pph_toggle_wall','0');}catch(e){}});})();</script>";
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]') . $js;
    });
});

// ===== Empty boxes cleanup hotfix (2026-03-09c) =====
add_action('init', function () {
    // Re-override with hard cleanup of hidden cards.
    remove_shortcode('prism_games_portal');
    remove_shortcode('prism_studio_pro_page');

    add_shortcode('prism_games_portal', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; margin:0 !important; }
        .pph-toggle[data-toggle-key="games"] { display:block !important; }
        .pph-toggle[data-toggle-key="games"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        .pph-card{margin-bottom:12px !important}
        ';
        $js = "<script>(function(){document.addEventListener('DOMContentLoaded',function(){var keep=document.querySelector('.pph-toggle[data-toggle-key=\"games\"]')?.closest('article');document.querySelectorAll('.pph-wrap article').forEach(function(a){if(keep&&a!==keep)a.remove();});if(keep){var d=keep.querySelector('.pph-toggle[data-toggle-key=\"games\"]');if(d)d.open=true;}try{localStorage.setItem('pph_toggle_games','1');}catch(e){}});})();</script>";
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]') . $js;
    });

    add_shortcode('prism_studio_pro_page', function () {
        $css = '
        .pph-wrap > h2, .pph-wrap > p { display:none !important; margin:0 !important; }
        .pph-toggle[data-toggle-key="studio"] { display:block !important; }
        .pph-toggle[data-toggle-key="studio"] > summary { display:none !important; }
        .pph-wrap{margin-top:0 !important}
        .pph-card{margin-bottom:12px !important}
        ';
        $js = "<script>(function(){document.addEventListener('DOMContentLoaded',function(){var keep=document.querySelector('.pph-toggle[data-toggle-key=\"studio\"]')?.closest('article');document.querySelectorAll('.pph-wrap article').forEach(function(a){if(keep&&a!==keep)a.remove();});if(keep){var d=keep.querySelector('.pph-toggle[data-toggle-key=\"studio\"]');if(d)d.open=true;}try{localStorage.setItem('pph_toggle_studio','1');}catch(e){}});})();</script>";
        return '<style>' . $css . '</style>' . do_shortcode('[prism_pixel_hub]') . $js;
    });

    // tighten creatures spacing
    remove_shortcode('prism_creatures_portal');
    add_shortcode('prism_creatures_portal', function () {
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));
        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap" style="margin-top:0;gap:12px;">
          <?php if (!$logged): ?>
            <article class="pph-card"><p>Log in to care for your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel"><h3>My Pixel Creature</h3><div id="pph-pet-view" class="pph-pet-view">Loading creature...</div><div class="pph-tool-row"><button type="button" id="pph-pet-feed">Feed</button><button type="button" id="pph-pet-play">Play</button><button type="button" id="pph-pet-rest">Rest</button></div><div class="pph-tool-row"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button type="button" id="pph-pet-rename">Save Name</button></div><div class="pph-tool-row"><select id="pph-pet-skin"><option value="default">default</option></select><button type="button" id="pph-pet-skin-save">Apply Skin</button></div><p id="pph-pet-status" class="pph-status"></p></article>
          <?php endif; ?>
          <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
        </section>
        <script>
        (()=>{const API='<?php echo esc_js($api); ?>';const nonce='<?php echo esc_js($nonce); ?>';const petView=document.getElementById('pph-pet-view');if(!petView)return;const petStatus=document.getElementById('pph-pet-status');const petNameInput=document.getElementById('pph-pet-name');const petSkinSelect=document.getElementById('pph-pet-skin');function renderPet(p){if(!p)return;petView.innerHTML=`<strong>${p.name||'Prismo'}</strong> (${p.species||'blob'})<br>Stage ${p.stage||'baby'} · Skin ${p.skin||'default'}<br>Hunger ${p.hunger}% · Happiness ${p.happiness}% · Energy ${p.energy}% · Health ${p.health}%`;if(petNameInput&&!petNameInput.value)petNameInput.value=p.name||'';if(petSkinSelect){const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];petSkinSelect.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===(p.skin||'default')?'selected':''}>${sk}</option>`).join('');}}async function loadPet(){try{const r=await fetch(API+'pet?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});if(!r.ok){petView.textContent='Log in to care for your creature.';return;}const j=await r.json();renderPet(j.pet||null);}catch{petView.textContent='Creature unavailable right now.';}}async function petAction(action,extra={}){if(petStatus)petStatus.textContent='Working...';const r=await fetch(API+'pet/action',{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(Object.assign({action},extra||{}))});if(!r.ok){if(petStatus)petStatus.textContent='Action failed.';return;}const j=await r.json();renderPet(j.pet||null);if(petStatus)petStatus.textContent='Done.';}document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(petNameInput?.value||'').trim()}));document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:petSkinSelect?.value||'default'}));loadPet();})();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Prism Creatures sprite restore hotfix (2026-03-09d) =====
add_action('init', function () {
    remove_shortcode('prism_creatures_portal');

    add_shortcode('prism_creatures_portal', function () {
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));

        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap" style="margin-top:0;gap:12px;">
          <?php if (!$logged): ?>
            <article class="pph-card">
              <h3>Prism Creature</h3>
              <div class="pph-pet-sprite" aria-hidden="true"><div class="pph-sprite-fallback">:)</div></div>
              <p>Log in to care for your creature.</p>
              <p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p>
            </article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel">
              <h3>My Pixel Creature</h3>
              <div id="pph-pet-sprite" class="pph-pet-sprite" aria-label="Creature sprite"><div class="pph-sprite-fallback">:)</div></div>
              <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
              <div class="pph-tool-row">
                <button type="button" id="pph-pet-feed">Feed</button>
                <button type="button" id="pph-pet-play">Play</button>
                <button type="button" id="pph-pet-rest">Rest</button>
              </div>
              <div class="pph-tool-row">
                <input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" />
                <button type="button" id="pph-pet-rename">Save Name</button>
              </div>
              <div class="pph-tool-row">
                <select id="pph-pet-skin"><option value="default">default</option></select>
                <button type="button" id="pph-pet-skin-save">Apply Skin</button>
              </div>
              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>

          <article class="pph-card">
            <h3>Creature Showcase</h3>
            <?php echo do_shortcode('[prism_pet_showcase]'); ?>
          </article>
        </section>
        <style>
          .pph-pet-sprite{width:96px;height:96px;border:2px solid #6b74c7;background:#0e1026;image-rendering:pixelated;display:grid;place-items:center;margin:0 0 10px}
          .pph-sprite-fallback{color:#fff;font:700 20px/1 monospace}
          .pph-sprite-wrap{display:grid;grid-template-columns:repeat(8,1fr);grid-template-rows:repeat(8,1fr);width:80px;height:80px;gap:0}
          .pph-px{width:100%;height:100%}
        </style>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>';
          const nonce='<?php echo esc_js($nonce); ?>';
          const petView=document.getElementById('pph-pet-view');
          const petSprite=document.getElementById('pph-pet-sprite');
          if(!petView||!petSprite) return;
          const petStatus=document.getElementById('pph-pet-status');
          const petNameInput=document.getElementById('pph-pet-name');
          const petSkinSelect=document.getElementById('pph-pet-skin');

          function paletteForSkin(skin){
            const map={
              default:['#00000000','#59d9ff','#b8f2ff','#0e1026'],
              mint:['#00000000','#77ffc4','#c8ffe8','#0e1026'],
              sunset:['#00000000','#ff8f66','#ffd18a','#0e1026'],
              galaxy:['#00000000','#8f7bff','#ff7bf2','#0e1026'],
              neon:['#00000000','#39ff14','#00e5ff','#0e1026']
            };
            return map[skin]||map.default;
          }

          function spritePattern(stage){
            if(stage==='adult') return [
              '00111100','01122110','11222211','12233221','12222221','11222211','01111110','00100100'
            ];
            if(stage==='teen') return [
              '00011000','00122100','01222210','12233221','12222221','01222210','00111100','00011000'
            ];
            return [
              '00000000','00111100','01222210','12233221','12222221','01222210','00111100','00000000'
            ];
          }

          function renderSprite(pet){
            const pal=paletteForSkin(pet.skin||'default');
            const patt=spritePattern(pet.stage||'baby');
            const wrap=document.createElement('div');
            wrap.className='pph-sprite-wrap';
            patt.join('').split('').forEach(ch=>{
              const px=document.createElement('div');
              px.className='pph-px';
              const idx=Number(ch)||0;
              px.style.background=pal[idx]||'transparent';
              wrap.appendChild(px);
            });
            petSprite.innerHTML='';
            petSprite.appendChild(wrap);
          }

          function renderPet(p){
            if(!p) return;
            renderSprite(p);
            petView.innerHTML=`<strong>${p.name||'Prismo'}</strong> (${p.species||'blob'})<br>Stage ${p.stage||'baby'} · Skin ${p.skin||'default'}<br>Hunger ${p.hunger}% · Happiness ${p.happiness}% · Energy ${p.energy}% · Health ${p.health}%`;
            if(petNameInput&&!petNameInput.value) petNameInput.value=p.name||'';
            if(petSkinSelect){
              const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
              petSkinSelect.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===(p.skin||'default')?'selected':''}>${sk}</option>`).join('');
            }
          }

          async function loadPet(){
            try{
              const r=await fetch(API+'pet?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});
              if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; }
              const j=await r.json();
              renderPet(j.pet||null);
            }catch{ petView.textContent='Creature unavailable right now.'; }
          }

          async function petAction(action,extra={}){
            if(petStatus) petStatus.textContent='Working...';
            const r=await fetch(API+'pet/action',{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(Object.assign({action},extra||{}))});
            if(!r.ok){ if(petStatus) petStatus.textContent='Action failed.'; return; }
            const j=await r.json(); renderPet(j.pet||null); if(petStatus) petStatus.textContent='Done.';
          }

          document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(petNameInput?.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:petSkinSelect?.value||'default'}));
          loadPet();
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Build Log feature completion pack (2026-03-09e) =====

if (!function_exists('prismtek_bl_get_flags')) {
    function prismtek_bl_get_flags() {
        $rows = get_option('prismtek_chat_flags', []);
        return is_array($rows) ? $rows : [];
    }
    function prismtek_bl_set_flags($rows) {
        if (!is_array($rows)) $rows = [];
        update_option('prismtek_chat_flags', array_slice($rows, -300), false);
    }
    function prismtek_bl_get_spotlight_history() {
        $rows = get_option('prismtek_spotlight_history', []);
        return is_array($rows) ? $rows : [];
    }
    function prismtek_bl_set_spotlight_history($rows) {
        if (!is_array($rows)) $rows = [];
        update_option('prismtek_spotlight_history', array_slice($rows, -104), false);
    }
    function prismtek_bl_pick_weekly_spotlight() {
        $wall = prismtek_pixel_get_wall_items();
        if (!is_array($wall) || empty($wall)) return null;

        $weekAgo = time() - 7 * DAY_IN_SECONDS;
        $recent = array_values(array_filter($wall, function($it) use ($weekAgo){
            return (int)($it['ts'] ?? 0) >= $weekAgo;
        }));
        $pool = !empty($recent) ? $recent : $wall;
        $chosen = $pool[array_rand($pool)];

        foreach ($wall as &$it) {
            $it['featured'] = (($it['id'] ?? '') === ($chosen['id'] ?? ''));
        }
        unset($it);
        prismtek_pixel_set_wall_items($wall);

        $hist = prismtek_bl_get_spotlight_history();
        $hist[] = [
            'id' => wp_generate_uuid4(),
            'itemId' => (string)($chosen['id'] ?? ''),
            'name' => (string)($chosen['name'] ?? ''),
            'caption' => (string)($chosen['caption'] ?? ''),
            'url' => (string)($chosen['url'] ?? ''),
            'ts' => time(),
        ];
        prismtek_bl_set_spotlight_history($hist);
        return $chosen;
    }

    function prismtek_bl_get_game_settings() {
        $rows = get_option('prismtek_game_settings', []);
        return is_array($rows) ? $rows : [];
    }
    function prismtek_bl_set_game_settings($rows) {
        if (!is_array($rows)) $rows = [];
        update_option('prismtek_game_settings', $rows, false);
    }

    function prismtek_bl_profile_badges($uid) {
        $uid = (int)$uid;
        if ($uid <= 0) return [];

        $scores = prismtek_pixel_get_scores();
        $total = 0;
        $games = 0;
        foreach ($scores as $slug => $rows) {
            $seen = false;
            if (!is_array($rows)) continue;
            foreach ($rows as $r) {
                if ((int)($r['userId'] ?? 0) !== $uid) continue;
                $total += (int)($r['score'] ?? 0);
                $seen = true;
            }
            if ($seen) $games++;
        }

        $chatCount = 0;
        foreach (prismtek_pixel_get_chat_messages() as $m) {
            if ((int)($m['userId'] ?? 0) === $uid) $chatCount++;
        }
        $wallCount = 0;
        foreach (prismtek_pixel_get_wall_items() as $w) {
            if ((int)($w['userId'] ?? 0) === $uid) $wallCount++;
        }
        $pet = get_user_meta($uid, 'prismtek_pet_state', true);

        $badgeDefs = [
            'first-score' => ['name'=>'First Score','icon'=>'🎮'],
            'score-100' => ['name'=>'Century','icon'=>'💯'],
            'score-500' => ['name'=>'High Scorer','icon'=>'🏆'],
            'score-1000' => ['name'=>'Arcade Master','icon'=>'👑'],
            'chatter' => ['name'=>'Chatter','icon'=>'💬'],
            'artist' => ['name'=>'Wall Artist','icon'=>'🎨'],
            'creature-keeper' => ['name'=>'Creature Keeper','icon'=>'🥚'],
            'multigame' => ['name'=>'Multi-Game','icon'=>'🕹️'],
        ];

        $earned = [];
        if ($total > 0) $earned[] = 'first-score';
        if ($total >= 100) $earned[] = 'score-100';
        if ($total >= 500) $earned[] = 'score-500';
        if ($total >= 1000) $earned[] = 'score-1000';
        if ($chatCount >= 10) $earned[] = 'chatter';
        if ($wallCount >= 1) $earned[] = 'artist';
        if (is_array($pet) && !empty($pet)) $earned[] = 'creature-keeper';
        if ($games >= 3) $earned[] = 'multigame';

        $out = [];
        foreach ($earned as $id) {
            $out[] = ['id'=>$id,'name'=>$badgeDefs[$id]['name'],'icon'=>$badgeDefs[$id]['icon']];
        }
        return $out;
    }
}

add_action('rest_api_init', function () {
    // 1) Chat moderation queue
    register_rest_route('prismtek/v1', '/chat/flag', [
        'methods' => 'POST',
        'permission_callback' => function(){ return (bool)get_current_user_id(); },
        'callback' => function (WP_REST_Request $req) {
            $msgId = sanitize_text_field((string)$req->get_param('id'));
            $reason = sanitize_text_field((string)$req->get_param('reason'));
            if ($msgId === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_id'],400);
            $uid = get_current_user_id();
            $rows = prismtek_bl_get_flags();
            $rows[] = ['id'=>wp_generate_uuid4(),'msgId'=>$msgId,'reason'=>mb_substr($reason ?: 'flagged',0,120),'userId'=>(int)$uid,'ts'=>time(),'status'=>'pending'];
            prismtek_bl_set_flags($rows);
            return rest_ensure_response(['ok'=>true]);
        }
    ]);

    register_rest_route('prismtek/v1', '/moderation/queue', [
        'methods' => 'GET',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function () {
            $flags = array_values(array_filter(prismtek_bl_get_flags(), fn($f)=>($f['status'] ?? 'pending') === 'pending'));
            $messages = prismtek_pixel_get_chat_messages();
            $byId = [];
            foreach ($messages as $m) $byId[(string)($m['id'] ?? '')] = $m;
            foreach ($flags as &$f) {
                $mid = (string)($f['msgId'] ?? '');
                $f['message'] = $byId[$mid]['message'] ?? '';
                $f['name'] = $byId[$mid]['name'] ?? '';
            }
            unset($f);
            return rest_ensure_response(['ok'=>true,'queue'=>$flags]);
        }
    ]);

    register_rest_route('prismtek/v1', '/moderation/resolve', [
        'methods' => 'POST',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $req) {
            $flagId = sanitize_text_field((string)$req->get_param('flagId'));
            $action = sanitize_key((string)$req->get_param('action')); // approve|reject
            if ($flagId === '' || !in_array($action, ['approve','reject'], true)) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_payload'],400);

            $flags = prismtek_bl_get_flags();
            $target = null;
            foreach ($flags as &$f) {
                if (($f['id'] ?? '') !== $flagId) continue;
                $f['status'] = ($action === 'approve') ? 'approved' : 'rejected';
                $target = $f;
                break;
            }
            unset($f);
            if (!$target) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
            prismtek_bl_set_flags($flags);

            if ($action === 'approve') {
                $mid = (string)($target['msgId'] ?? '');
                if ($mid !== '') {
                    $next = [];
                    foreach (prismtek_pixel_get_chat_messages() as $m) {
                        if (($m['id'] ?? '') === $mid) continue;
                        $next[] = $m;
                    }
                    prismtek_pixel_set_chat_messages($next);
                }
            }
            return rest_ensure_response(['ok'=>true]);
        }
    ]);

    // 2) Weekly spotlight automation + archive
    register_rest_route('prismtek/v1', '/spotlight', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $wall = prismtek_pixel_get_wall_items();
            $current = null;
            foreach ($wall as $it) {
                if (!empty($it['featured'])) { $current = $it; }
            }
            return rest_ensure_response([
                'ok'=>true,
                'current'=>$current,
                'history'=>array_reverse(array_slice(prismtek_bl_get_spotlight_history(), -24)),
            ]);
        }
    ]);

    register_rest_route('prismtek/v1', '/spotlight/pick', [
        'methods' => 'POST',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function () {
            $pick = prismtek_bl_pick_weekly_spotlight();
            return rest_ensure_response(['ok'=>true,'spotlight'=>$pick]);
        }
    ]);

    // 3) Per-game settings panel endpoints
    register_rest_route('prismtek/v1', '/games/settings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $slug = sanitize_title((string)$req->get_param('slug'));
            $all = prismtek_bl_get_game_settings();
            $default = ['spawnRate'=>'normal','difficultyPreset'=>'normal','enabled'=>true];
            return rest_ensure_response(['ok'=>true,'settings'=>($slug && isset($all[$slug]) ? $all[$slug] : $default)]);
        }
    ]);

    register_rest_route('prismtek/v1', '/games/settings', [
        'methods' => 'POST',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $req) {
            $slug = sanitize_title((string)$req->get_param('slug'));
            if ($slug === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_slug'],400);
            $all = prismtek_bl_get_game_settings();
            $all[$slug] = [
                'spawnRate' => sanitize_text_field((string)$req->get_param('spawnRate') ?: 'normal'),
                'difficultyPreset' => sanitize_text_field((string)$req->get_param('difficultyPreset') ?: 'normal'),
                'enabled' => (bool)$req->get_param('enabled'),
            ];
            prismtek_bl_set_game_settings($all);
            return rest_ensure_response(['ok'=>true]);
        }
    ]);

    // 4) Public player profile + badges
    register_rest_route('prismtek/v1', '/profile/public', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $userParam = sanitize_text_field((string)$req->get_param('user'));
            if ($userParam === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_user'],400);

            $u = get_user_by('login', $userParam);
            if (!$u) $u = get_user_by('slug', $userParam);
            if (!$u) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);

            $uid = (int)$u->ID;
            $scores = prismtek_pixel_get_scores();
            $total = 0;
            $games = 0;
            foreach ($scores as $slug => $rows) {
                $has = false;
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    if ((int)($r['userId'] ?? 0) !== $uid) continue;
                    $total += (int)($r['score'] ?? 0);
                    $has = true;
                }
                if ($has) $games++;
            }

            $pet = get_user_meta($uid, 'prismtek_pet_state', true);
            return rest_ensure_response([
                'ok'=>true,
                'profile'=>[
                    'user'=>(string)$u->user_login,
                    'displayName'=>(string)$u->display_name,
                    'bio'=>(string)get_user_meta($uid, 'prismtek_bio', true),
                    'favoriteGame'=>(string)get_user_meta($uid, 'prismtek_favorite_game', true),
                    'themeColor'=>(string)get_user_meta($uid, 'prismtek_theme_color', true),
                    'totalScore'=>$total,
                    'gamesPlayed'=>$games,
                    'badges'=>prismtek_bl_profile_badges($uid),
                    'creature'=>is_array($pet) ? [
                        'name'=>(string)($pet['name'] ?? 'Prismo'),
                        'stage'=>prismtek_pet_compute_stage($pet),
                        'skin'=>(string)($pet['skin'] ?? 'default'),
                    ] : null,
                ]
            ]);
        }
    ]);

    register_rest_route('prismtek/v1', '/profiles/leaderboard', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $scores = prismtek_pixel_get_scores();
            $totals = [];
            foreach ($scores as $slug => $rows) {
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    $uid = (int)($r['userId'] ?? 0);
                    if ($uid <= 0) continue;
                    if (!isset($totals[$uid])) $totals[$uid] = 0;
                    $totals[$uid] += (int)($r['score'] ?? 0);
                }
            }
            arsort($totals);
            $out = [];
            $rank = 1;
            foreach ($totals as $uid => $total) {
                $u = get_userdata((int)$uid);
                if (!$u) continue;
                $out[] = [
                    'rank' => $rank++,
                    'user' => (string)$u->user_login,
                    'displayName' => (string)$u->display_name,
                    'totalScore' => (int)$total,
                    'badges' => array_slice(prismtek_bl_profile_badges((int)$uid), 0, 4),
                ];
                if (count($out) >= 25) break;
            }
            return rest_ensure_response(['ok'=>true,'rows'=>$out]);
        }
    ]);
});

add_action('init', function(){
    if (!wp_next_scheduled('prismtek_weekly_spotlight_event')) {
        // next Sunday 00:00 UTC
        $ts = strtotime('next sunday 00:00 UTC');
        if (!$ts) $ts = time() + WEEK_IN_SECONDS;
        wp_schedule_event($ts, 'weekly', 'prismtek_weekly_spotlight_event');
    }
});

add_action('prismtek_weekly_spotlight_event', function(){
    prismtek_bl_pick_weekly_spotlight();
});

// ===== Prism Creatures Phase Next (2026-03-09f) =====
if (!function_exists('prismtek_pet_level_from_xp')) {
    function prismtek_pet_level_from_xp($xp) {
        $xp = max(0, (int)$xp);
        // simple nonlinear level curve
        return max(1, (int)floor(sqrt($xp / 30.0)) + 1);
    }

    function prismtek_pet_next_level_xp($level) {
        $level = max(1, (int)$level);
        return (int)(pow(max(1, $level), 2) * 30);
    }

    function prismtek_pet_enrich_state($state) {
        if (!is_array($state)) $state = prismtek_pet_default_state();
        if (empty($state['species'])) $state['species'] = 'sprout';
        if (empty($state['personality'])) $state['personality'] = 'brave';
        if (!isset($state['xp'])) $state['xp'] = 0;
        if (!isset($state['wins'])) $state['wins'] = 0;
        if (!isset($state['losses'])) $state['losses'] = 0;
        $state['xp'] = max(0, (int)$state['xp']);
        $state['wins'] = max(0, (int)$state['wins']);
        $state['losses'] = max(0, (int)$state['losses']);
        $state['level'] = prismtek_pet_level_from_xp((int)$state['xp']);
        $state['nextLevelXp'] = prismtek_pet_next_level_xp((int)$state['level']);
        $state['form'] = prismtek_pet_compute_form($state);
        return $state;
    }

    function prismtek_pet_compute_form($state) {
        $species = sanitize_key((string)($state['species'] ?? 'sprout'));
        $personality = sanitize_key((string)($state['personality'] ?? 'brave'));
        $lvl = prismtek_pet_level_from_xp((int)($state['xp'] ?? 0));

        $tier = 'cub';
        if ($lvl >= 18) $tier = 'mythic';
        elseif ($lvl >= 10) $tier = 'champion';
        elseif ($lvl >= 4) $tier = 'rookie';

        return $species . '-' . $personality . '-' . $tier;
    }

    function prismtek_pet_battle_power($state) {
        $state = prismtek_pet_enrich_state($state);
        $base = ((int)$state['health'] + (int)$state['energy'] + (int)$state['happiness']) / 3;
        $lvl = (int)$state['level'];
        $mod = 0;
        $p = (string)($state['personality'] ?? 'brave');
        if ($p === 'brave') $mod += 8;
        if ($p === 'curious') $mod += 4;
        if ($p === 'calm') $mod += 3;
        if ($p === 'chaotic') $mod += 2;
        return (int)round($base + $lvl * 3 + $mod);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/pet/rpg', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            $state = prismtek_pet_get_state($uid);
            $state = prismtek_pet_enrich_state($state);
            prismtek_pet_set_state($uid, $state);
            $state['stage'] = prismtek_pet_compute_stage($state);
            $state['unlocks'] = prismtek_pet_get_unlocks($uid);
            return rest_ensure_response(['ok'=>true, 'pet'=>$state]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/adopt', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);

            $species = sanitize_key((string)$request->get_param('species'));
            $personality = sanitize_key((string)$request->get_param('personality'));
            $allowedSpecies = ['sprout','ember','tidal','volt','shade'];
            $allowedP = ['brave','curious','calm','chaotic'];
            if (!in_array($species, $allowedSpecies, true)) $species = 'sprout';
            if (!in_array($personality, $allowedP, true)) $personality = 'brave';

            $state = prismtek_pet_get_state($uid);
            $state['species'] = $species;
            $state['personality'] = $personality;
            $state['xp'] = (int)($state['xp'] ?? 0);
            $state = prismtek_pet_enrich_state($state);
            prismtek_pet_set_state($uid, $state);

            return rest_ensure_response(['ok'=>true, 'pet'=>$state]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/train', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);

            $state = prismtek_pet_get_state($uid);
            $state = prismtek_pet_enrich_state($state);

            if ((int)$state['energy'] < 12) {
                return new WP_REST_Response(['ok'=>false,'error'=>'too_tired'], 400);
            }

            $gain = rand(10, 18);
            $state['xp'] = (int)$state['xp'] + $gain;
            $state['energy'] = max(0, (int)$state['energy'] - rand(10,16));
            $state['hunger'] = max(0, (int)$state['hunger'] - rand(5,10));
            $state['happiness'] = min(100, (int)$state['happiness'] + rand(1,4));
            $state['health'] = min(100, (int)$state['health'] + 1);
            $state = prismtek_pet_enrich_state($state);
            prismtek_pet_set_state($uid, $state);

            return rest_ensure_response(['ok'=>true, 'xpGained'=>$gain, 'pet'=>$state]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/battle/spar', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);

            $state = prismtek_pet_get_state($uid);
            $state = prismtek_pet_enrich_state($state);

            if ((int)$state['energy'] < 18) {
                return new WP_REST_Response(['ok'=>false,'error'=>'too_tired'], 400);
            }

            $yourPower = prismtek_pet_battle_power($state) + rand(-8, 10);
            $rival = max(20, (int)$state['level'] * 9 + rand(12, 55));
            $won = $yourPower >= $rival;

            $xpGain = $won ? rand(18, 34) : rand(7, 15);
            $state['xp'] = (int)$state['xp'] + $xpGain;
            $state['energy'] = max(0, (int)$state['energy'] - rand(14,22));
            $state['hunger'] = max(0, (int)$state['hunger'] - rand(8,14));
            $state['happiness'] = max(0, min(100, (int)$state['happiness'] + ($won ? 8 : -4)));
            if ($won) $state['wins'] = (int)$state['wins'] + 1;
            else $state['losses'] = (int)$state['losses'] + 1;

            $state = prismtek_pet_enrich_state($state);
            prismtek_pet_set_state($uid, $state);

            return rest_ensure_response([
                'ok'=>true,
                'result'=>$won ? 'win' : 'loss',
                'xpGained'=>$xpGain,
                'yourPower'=>$yourPower,
                'rivalPower'=>$rival,
                'pet'=>$state,
            ]);
        }
    ]);
});

add_action('init', function () {
    remove_shortcode('prism_creatures_portal');

    add_shortcode('prism_creatures_portal', function () {
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));

        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap" style="margin-top:0;gap:14px;">
          <article class="pph-card">
            <h3>Prism Creatures</h3>
            <p style="margin:.3rem 0 0;color:#dbe4ff">Creature care + evolution + battle training.</p>
          </article>

          <?php if (!$logged): ?>
            <article class="pph-card">
              <div class="pph-pet-sprite"><div class="pph-sprite-fallback">:)</div></div>
              <p>Log in to adopt and train your creature.</p>
              <p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p>
            </article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel">
              <div class="pph-pet-head">
                <div id="pph-pet-sprite" class="pph-pet-sprite" aria-label="Creature sprite"><div class="pph-sprite-fallback">:)</div></div>
                <div>
                  <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
                  <div id="pph-pet-bars"></div>
                </div>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <label>Species
                  <select id="pph-pet-species">
                    <option value="sprout">Sprout</option>
                    <option value="ember">Ember</option>
                    <option value="tidal">Tidal</option>
                    <option value="volt">Volt</option>
                    <option value="shade">Shade</option>
                  </select>
                </label>
                <label>Personality
                  <select id="pph-pet-personality">
                    <option value="brave">Brave</option>
                    <option value="curious">Curious</option>
                    <option value="calm">Calm</option>
                    <option value="chaotic">Chaotic</option>
                  </select>
                </label>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <button type="button" id="pph-pet-adopt">Save Creature Type</button>
                <button type="button" id="pph-pet-train">Train (+XP)</button>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
                <button type="button" id="pph-pet-feed">Feed</button>
                <button type="button" id="pph-pet-play">Play</button>
                <button type="button" id="pph-pet-rest">Rest</button>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" />
                <button type="button" id="pph-pet-rename">Save Name</button>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <select id="pph-pet-skin"><option value="default">default</option></select>
                <button type="button" id="pph-pet-skin-save">Apply Skin</button>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr;">
                <button type="button" id="pph-pet-spar">Spar Battle</button>
              </div>

              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>

          <article class="pph-card">
            <h3>Creature Showcase</h3>
            <?php echo do_shortcode('[prism_pet_showcase]'); ?>
          </article>
        </section>
        <style>
          .pph-pet-head{display:grid;grid-template-columns:110px 1fr;gap:12px;align-items:start}
          .pph-pet-sprite{width:96px;height:96px;border:2px solid #6b74c7;background:#0e1026;image-rendering:pixelated;display:grid;place-items:center;margin:0}
          .pph-sprite-fallback{color:#fff;font:700 20px/1 monospace}
          .pph-sprite-wrap{display:grid;grid-template-columns:repeat(8,1fr);grid-template-rows:repeat(8,1fr);width:80px;height:80px;gap:0}
          .pph-px{width:100%;height:100%}
          .pph-bar{height:8px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:6px 0}
          .pph-bar > span{display:block;height:100%}
          .pph-pet-view strong{font-size:14px}
          @media (max-width:700px){.pph-pet-head{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>';
          const nonce='<?php echo esc_js($nonce); ?>';
          const petView=document.getElementById('pph-pet-view');
          const petSprite=document.getElementById('pph-pet-sprite');
          const bars=document.getElementById('pph-pet-bars');
          if(!petView || !petSprite) return;

          const petStatus=document.getElementById('pph-pet-status');
          const petNameInput=document.getElementById('pph-pet-name');
          const petSkinSelect=document.getElementById('pph-pet-skin');
          const speciesSelect=document.getElementById('pph-pet-species');
          const personalitySelect=document.getElementById('pph-pet-personality');

          function paletteFor(skin){
            const map={
              default:['#00000000','#59d9ff','#b8f2ff','#0e1026'],
              mint:['#00000000','#77ffc4','#c8ffe8','#0e1026'],
              sunset:['#00000000','#ff8f66','#ffd18a','#0e1026'],
              galaxy:['#00000000','#8f7bff','#ff7bf2','#0e1026'],
              neon:['#00000000','#39ff14','#00e5ff','#0e1026']
            }; return map[skin] || map.default;
          }

          function basePattern(stage){
            if(stage==='adult') return ['00111100','01122110','11222211','12233221','12222221','11222211','01111110','00100100'];
            if(stage==='teen') return ['00011000','00122100','01222210','12233221','12222221','01222210','00111100','00011000'];
            return ['00000000','00111100','01222210','12233221','12222221','01222210','00111100','00000000'];
          }

          function renderSprite(p){
            const pal=paletteFor(p.skin||'default');
            const patt=basePattern(p.stage||'baby');
            const wrap=document.createElement('div'); wrap.className='pph-sprite-wrap';
            patt.join('').split('').forEach(ch=>{ const px=document.createElement('div'); px.className='pph-px'; px.style.background=pal[Number(ch)||0]||'transparent'; wrap.appendChild(px);});
            petSprite.innerHTML=''; petSprite.appendChild(wrap);
          }

          function renderBars(p){
            const row=(label,val,color)=>`<div style="font-size:10px;margin-top:4px">${label} ${val}%</div><div class="pph-bar"><span style="width:${Math.max(0,Math.min(100,val||0))}%;background:${color}"></span></div>`;
            bars.innerHTML = row('Health',p.health,'#5de28f') + row('Energy',p.energy,'#59d9ff') + row('Happiness',p.happiness,'#f8c062') + row('Hunger',p.hunger,'#d98fff');
          }

          function renderPet(p){
            if(!p) return;
            renderSprite(p); renderBars(p);
            petView.innerHTML = `<strong>${p.name||'Prismo'}</strong><br>Species ${p.species||'sprout'} · Personality ${p.personality||'brave'}<br>Form ${p.form||'core'} · Stage ${p.stage||'baby'}<br>Lvl ${p.level||1} · XP ${(p.xp||0)}/${p.nextLevelXp||30} · W/L ${(p.wins||0)}/${(p.losses||0)}`;
            if(petNameInput && !petNameInput.value) petNameInput.value = p.name || '';
            if(speciesSelect) speciesSelect.value = p.species || 'sprout';
            if(personalitySelect) personalitySelect.value = p.personality || 'brave';
            if(petSkinSelect){
              const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
              petSkinSelect.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===(p.skin||'default')?'selected':''}>${sk}</option>`).join('');
            }
          }

          async function loadPet(){
            try{
              const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});
              if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; }
              const j=await r.json(); renderPet(j.pet||null);
            }catch{ petView.textContent='Creature unavailable right now.'; }
          }

          async function post(path,payload){
            const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload||{})});
            const j=await r.json().catch(()=>({}));
            return {ok:r.ok, data:j};
          }

          async function petAction(action, extra={}){
            if(petStatus) petStatus.textContent='Working...';
            const out=await post('pet/action',Object.assign({action},extra||{}));
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Action failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent='Done.';
          }

          document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(petNameInput?.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:petSkinSelect?.value||'default'}));

          document.getElementById('pph-pet-adopt')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Saving creature type...';
            const out=await post('pet/adopt',{species:speciesSelect?.value||'sprout', personality:personalitySelect?.value||'brave'});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Adopt failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent='Creature type updated.';
          });

          document.getElementById('pph-pet-train')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Training...';
            const out=await post('pet/train',{});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Training failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent=`Training complete (+${out.data.xpGained||0} XP)`;
          });

          document.getElementById('pph-pet-spar')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Sparring...';
            const out=await post('pet/battle/spar',{});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Battle failed.'; return; }
            renderPet(out.data.pet||null);
            const r=out.data.result==='win'?'WIN':'LOSS';
            if(petStatus) petStatus.textContent=`${r} · +${out.data.xpGained||0} XP (Power ${out.data.yourPower||0} vs ${out.data.rivalPower||0})`;
          });

          loadPet();
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Prism Creatures Sprite Sheet System (2026-03-09g) =====
if (!function_exists('prismtek_pet_default_sprite_pack')) {
    function prismtek_pet_default_sprite_pack() {
        $url = content_url('uploads/prismtek-creatures/trex-sheet.png');
        $pack = [
            'source' => 'default',
            'imageUrl' => esc_url_raw($url),
            'sheetW' => 384,
            'sheetH' => 320,
            'frameW' => 32,
            'frameH' => 32,
            'columns' => 12,
            'rows' => 10,
            'fps' => 10,
            'frames' => [],
            'animations' => [
                'idle' => [0,1,2,3],
                'walk' => [4,5,6,7,8,9],
                'run' => [10,11,12,13,14,15],
            ],
            'metaFormat' => 'grid',
        ];
        $i = 0;
        for ($r=0;$r<10;$r++) {
            for ($c=0;$c<12;$c++) {
                $pack['frames'][] = ['i'=>$i++, 'x'=>$c*32, 'y'=>$r*32, 'w'=>32, 'h'=>32];
            }
        }
        return $pack;
    }

    function prismtek_pet_get_sprite_pack($uid) {
        $uid = (int)$uid;
        $pack = $uid > 0 ? get_user_meta($uid, 'prismtek_pet_sprite_pack', true) : [];
        if (!is_array($pack) || empty($pack)) {
            $pack = get_option('prismtek_pet_default_sprite_pack', []);
        }
        if (!is_array($pack) || empty($pack)) {
            $pack = prismtek_pet_default_sprite_pack();
            update_option('prismtek_pet_default_sprite_pack', $pack, false);
        }
        return $pack;
    }

    function prismtek_pet_set_sprite_pack($uid, $pack) {
        $uid = (int)$uid;
        if ($uid <= 0 || !is_array($pack)) return;
        update_user_meta($uid, 'prismtek_pet_sprite_pack', $pack);
    }

    function prismtek_pet_normalize_sprite_pack($imageUrl, $sheetW, $sheetH, $meta = []) {
        $sheetW = max(1, (int)$sheetW);
        $sheetH = max(1, (int)$sheetH);

        $frameW = max(8, min($sheetW, (int)($meta['frameW'] ?? 32)));
        $frameH = max(8, min($sheetH, (int)($meta['frameH'] ?? 32)));
        $fps = max(1, min(30, (int)($meta['fps'] ?? 10)));
        $format = sanitize_key((string)($meta['format'] ?? 'grid'));

        $frames = [];
        $animations = [];

        // Aseprite/TexturePacker-like JSON support
        if (!empty($meta['frames']) && is_array($meta['frames'])) {
            $rawFrames = $meta['frames'];
            $isAssoc = array_keys($rawFrames) !== range(0, count($rawFrames)-1);

            if ($isAssoc) {
                $idx = 0;
                $nameToIndex = [];
                foreach ($rawFrames as $name => $f) {
                    $fr = is_array($f['frame'] ?? null) ? $f['frame'] : (is_array($f) ? $f : []);
                    $x = (int)($fr['x'] ?? 0);
                    $y = (int)($fr['y'] ?? 0);
                    $w = max(1, (int)($fr['w'] ?? $frameW));
                    $h = max(1, (int)($fr['h'] ?? $frameH));
                    $frames[] = ['i'=>$idx,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'name'=>(string)$name];
                    $nameToIndex[(string)$name] = $idx;
                    $idx++;
                }

                // frameTags (Aseprite)
                if (!empty($meta['meta']['frameTags']) && is_array($meta['meta']['frameTags'])) {
                    foreach ($meta['meta']['frameTags'] as $tag) {
                        $nm = sanitize_key((string)($tag['name'] ?? 'anim'));
                        $from = max(0, (int)($tag['from'] ?? 0));
                        $to = max($from, (int)($tag['to'] ?? $from));
                        $seq = [];
                        for ($i=$from;$i<=$to;$i++) $seq[] = $i;
                        if (!empty($seq)) $animations[$nm] = $seq;
                    }
                }
            } else {
                $idx = 0;
                foreach ($rawFrames as $fr) {
                    $f = is_array($fr['frame'] ?? null) ? $fr['frame'] : (is_array($fr) ? $fr : []);
                    $x = (int)($f['x'] ?? 0);
                    $y = (int)($f['y'] ?? 0);
                    $w = max(1, (int)($f['w'] ?? $frameW));
                    $h = max(1, (int)($f['h'] ?? $frameH));
                    $frames[] = ['i'=>$idx++,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h];
                }
            }
        }

        // Custom animations object support
        if (!empty($meta['animations']) && is_array($meta['animations'])) {
            foreach ($meta['animations'] as $k => $seq) {
                $name = sanitize_key((string)$k);
                if (!is_array($seq)) continue;
                $clean = [];
                foreach ($seq as $v) {
                    $iv = (int)$v;
                    if ($iv >= 0) $clean[] = $iv;
                }
                if (!empty($clean)) $animations[$name] = array_values(array_unique($clean));
            }
        }

        // Grid fallback or explicit grid mode
        if (empty($frames) || $format === 'grid') {
            $cols = max(1, (int)floor($sheetW / $frameW));
            $rows = max(1, (int)floor($sheetH / $frameH));
            $frames = [];
            $idx = 0;
            for ($r=0;$r<$rows;$r++) {
                for ($c=0;$c<$cols;$c++) {
                    $frames[] = ['i'=>$idx++,'x'=>$c*$frameW,'y'=>$r*$frameH,'w'=>$frameW,'h'=>$frameH];
                }
            }
            if (empty($animations)) {
                $count = count($frames);
                $idle = [];
                for ($i=0;$i<min(4,$count);$i++) $idle[] = $i;
                $walk = [];
                for ($i=4;$i<min(10,$count);$i++) $walk[] = $i;
                $run = [];
                for ($i=10;$i<min(18,$count);$i++) $run[] = $i;
                $animations = [
                    'idle' => !empty($idle) ? $idle : [0],
                    'walk' => !empty($walk) ? $walk : (!empty($idle) ? $idle : [0]),
                    'run' => !empty($run) ? $run : (!empty($walk) ? $walk : (!empty($idle) ? $idle : [0])),
                ];
            }
        }

        if (empty($animations)) $animations = ['idle' => [0]];

        return [
            'source' => 'user',
            'imageUrl' => esc_url_raw((string)$imageUrl),
            'sheetW' => $sheetW,
            'sheetH' => $sheetH,
            'frameW' => $frameW,
            'frameH' => $frameH,
            'columns' => max(1, (int)floor($sheetW / $frameW)),
            'rows' => max(1, (int)floor($sheetH / $frameH)),
            'fps' => $fps,
            'frames' => $frames,
            'animations' => $animations,
            'metaFormat' => $format ?: 'grid',
        ];
    }
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/pet/sprite-pack', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            return rest_ensure_response(['ok'=>true, 'pack'=>prismtek_pet_get_sprite_pack($uid)]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/sprite-upload', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            if (empty($_FILES['sheet'])) return new WP_REST_Response(['ok'=>false,'error'=>'missing_sheet'], 400);

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $overrides = [
                'test_form' => false,
                'mimes' => [
                    'png' => 'image/png',
                    'jpg|jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                ],
            ];
            $up = wp_handle_upload($_FILES['sheet'], $overrides);
            if (!empty($up['error'])) return new WP_REST_Response(['ok'=>false,'error'=>'upload_failed','detail'=>$up['error']], 400);

            $path = (string)($up['file'] ?? '');
            $url = esc_url_raw((string)($up['url'] ?? ''));
            $dim = @getimagesize($path);
            if (!$dim) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_image'], 400);

            $metaRaw = (string)$request->get_param('metaJson');
            $meta = [];
            if ($metaRaw !== '') {
                $j = json_decode($metaRaw, true);
                if (is_array($j)) $meta = $j;
            }
            if (!empty($_FILES['meta']) && is_uploaded_file((string)$_FILES['meta']['tmp_name'])) {
                $raw = @file_get_contents((string)$_FILES['meta']['tmp_name']);
                $j = json_decode((string)$raw, true);
                if (is_array($j)) $meta = $j;
            }

            // direct field overrides
            $meta['frameW'] = (int)($request->get_param('frameW') ?: ($meta['frameW'] ?? 32));
            $meta['frameH'] = (int)($request->get_param('frameH') ?: ($meta['frameH'] ?? 32));
            $meta['fps'] = (int)($request->get_param('fps') ?: ($meta['fps'] ?? 10));
            $meta['format'] = sanitize_key((string)($request->get_param('format') ?: ($meta['format'] ?? 'grid')));

            $pack = prismtek_pet_normalize_sprite_pack($url, (int)$dim[0], (int)$dim[1], $meta);
            prismtek_pet_set_sprite_pack($uid, $pack);

            return rest_ensure_response(['ok'=>true, 'pack'=>$pack]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/sprite-use-default', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            delete_user_meta($uid, 'prismtek_pet_sprite_pack');
            return rest_ensure_response(['ok'=>true, 'pack'=>prismtek_pet_get_sprite_pack($uid)]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pet/sprite-config', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            $pack = prismtek_pet_get_sprite_pack($uid);
            $fps = (int)$request->get_param('fps');
            if ($fps > 0) $pack['fps'] = max(1, min(30, $fps));

            $animJson = (string)$request->get_param('animationsJson');
            if ($animJson !== '') {
                $j = json_decode($animJson, true);
                if (is_array($j)) {
                    $anims = [];
                    foreach ($j as $k=>$seq) {
                        $name = sanitize_key((string)$k);
                        if (!is_array($seq)) continue;
                        $clean = [];
                        foreach ($seq as $v) { $iv = (int)$v; if ($iv>=0) $clean[] = $iv; }
                        if (!empty($clean)) $anims[$name] = array_values(array_unique($clean));
                    }
                    if (!empty($anims)) $pack['animations'] = $anims;
                }
            }

            prismtek_pet_set_sprite_pack($uid, $pack);
            return rest_ensure_response(['ok'=>true,'pack'=>$pack]);
        }
    ]);
});

add_action('init', function () {
    remove_shortcode('prism_creatures_portal');

    add_shortcode('prism_creatures_portal', function () {
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));

        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap" style="margin-top:0;gap:14px;">
          <article class="pph-card">
            <h3>Prism Creatures</h3>
            <p style="margin:.3rem 0 0;color:#dbe4ff">Custom sprite sheets + care + evolution + battle training.</p>
          </article>

          <?php if (!$logged): ?>
            <article class="pph-card">
              <div class="pph-pet-sprite"><div class="pph-sprite-fallback">:)</div></div>
              <p>Log in to adopt, train, and upload custom sprite sheets.</p>
              <p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p>
            </article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel">
              <div class="pph-pet-head">
                <div class="pph-pet-sprite-wrap">
                  <canvas id="pph-pet-canvas" class="pph-pet-canvas" width="96" height="96"></canvas>
                </div>
                <div>
                  <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
                  <div id="pph-pet-bars"></div>
                </div>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <label>Species
                  <select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select>
                </label>
                <label>Personality
                  <select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select>
                </label>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button type="button" id="pph-pet-adopt">Save Creature Type</button><button type="button" id="pph-pet-train">Train (+XP)</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button type="button" id="pph-pet-feed">Feed</button><button type="button" id="pph-pet-play">Play</button><button type="button" id="pph-pet-rest">Rest</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button type="button" id="pph-pet-rename">Save Name</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"><option value="default">default</option></select><button type="button" id="pph-pet-skin-save">Apply Skin</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr;"><button type="button" id="pph-pet-spar">Spar Battle</button></div>

              <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
              <h4 style="margin:4px 0">Sprite Sheet Studio</h4>
              <p style="font-size:11px;margin:0 0 8px;color:#dbe4ff">Supports grid sheets + JSON atlases (Aseprite/TexturePacker-style) + custom animation maps.</p>
              <form id="pph-sprite-upload-form" class="pph-form" enctype="multipart/form-data">
                <input type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required />
                <input type="file" name="meta" accept="application/json,.json" />
                <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
                  <input type="number" name="frameW" min="8" max="512" placeholder="Frame W (e.g. 32)" />
                  <input type="number" name="frameH" min="8" max="512" placeholder="Frame H (e.g. 32)" />
                  <input type="number" name="fps" min="1" max="30" placeholder="FPS (e.g. 10)" />
                </div>
                <select name="format"><option value="grid">Grid</option><option value="aseprite">Aseprite JSON</option><option value="texturepacker">TexturePacker JSON</option><option value="custom">Custom JSON</option></select>
                <button type="submit">Upload Sprite Sheet</button>
              </form>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <button type="button" id="pph-sprite-default">Use Default T-Rex Sheet</button>
                <select id="pph-sprite-animation"></select>
              </div>

              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>

          <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
        </section>
        <style>
          .pph-pet-head{display:grid;grid-template-columns:110px 1fr;gap:12px;align-items:start}
          .pph-pet-canvas{width:96px;height:96px;border:2px solid #6b74c7;background:#0e1026;image-rendering:pixelated;display:block}
          .pph-sprite-fallback{color:#fff;font:700 20px/1 monospace}
          .pph-bar{height:8px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:6px 0}
          .pph-bar > span{display:block;height:100%}
          .pph-pet-view strong{font-size:14px}
          @media (max-width:700px){.pph-pet-head{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>';
          const nonce='<?php echo esc_js($nonce); ?>';
          const petView=document.getElementById('pph-pet-view');
          const canvas=document.getElementById('pph-pet-canvas');
          if(!petView || !canvas) return;
          const ctx=canvas.getContext('2d');
          ctx.imageSmoothingEnabled=false;

          const bars=document.getElementById('pph-pet-bars');
          const petStatus=document.getElementById('pph-pet-status');
          const petNameInput=document.getElementById('pph-pet-name');
          const petSkinSelect=document.getElementById('pph-pet-skin');
          const speciesSelect=document.getElementById('pph-pet-species');
          const personalitySelect=document.getElementById('pph-pet-personality');
          const animSelect=document.getElementById('pph-sprite-animation');
          const uploadForm=document.getElementById('pph-sprite-upload-form');
          let spritePack=null, petState=null, spriteImg=null, frameTimer=null, activeAnim='idle', frameCursor=0;

          function paletteFor(skin){
            const map={default:['#00000000','#59d9ff','#b8f2ff','#0e1026'],mint:['#00000000','#77ffc4','#c8ffe8','#0e1026'],sunset:['#00000000','#ff8f66','#ffd18a','#0e1026'],galaxy:['#00000000','#8f7bff','#ff7bf2','#0e1026'],neon:['#00000000','#39ff14','#00e5ff','#0e1026']};
            return map[skin] || map.default;
          }
          function basePattern(stage){
            if(stage==='adult') return ['00111100','01122110','11222211','12233221','12222221','11222211','01111110','00100100'];
            if(stage==='teen') return ['00011000','00122100','01222210','12233221','12222221','01222210','00111100','00011000'];
            return ['00000000','00111100','01222210','12233221','12222221','01222210','00111100','00000000'];
          }
          function drawFallbackSprite(p){
            const pal=paletteFor((p&&p.skin)||'default');
            const patt=basePattern((p&&p.stage)||'baby');
            ctx.clearRect(0,0,96,96);
            const scale=12; // 8*12=96
            patt.forEach((row,y)=>row.split('').forEach((ch,x)=>{const c=pal[Number(ch)||0]; if(c&&c!=='#00000000'){ctx.fillStyle=c;ctx.fillRect(x*scale,y*scale,scale,scale);}}));
          }

          function renderBars(p){
            const row=(label,val,color)=>`<div style="font-size:10px;margin-top:4px">${label} ${val}%</div><div class="pph-bar"><span style="width:${Math.max(0,Math.min(100,val||0))}%;background:${color}"></span></div>`;
            bars.innerHTML = row('Health',p.health,'#5de28f') + row('Energy',p.energy,'#59d9ff') + row('Happiness',p.happiness,'#f8c062') + row('Hunger',p.hunger,'#d98fff');
          }

          function renderPet(p){
            if(!p) return;
            petState=p;
            renderBars(p);
            petView.innerHTML = `<strong>${p.name||'Prismo'}</strong><br>Species ${p.species||'sprout'} · Personality ${p.personality||'brave'}<br>Form ${p.form||'core'} · Stage ${p.stage||'baby'}<br>Lvl ${p.level||1} · XP ${(p.xp||0)}/${p.nextLevelXp||30} · W/L ${(p.wins||0)}/${(p.losses||0)}`;
            if(petNameInput && !petNameInput.value) petNameInput.value = p.name || '';
            if(speciesSelect) speciesSelect.value = p.species || 'sprout';
            if(personalitySelect) personalitySelect.value = p.personality || 'brave';
            if(petSkinSelect){
              const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
              petSkinSelect.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===(p.skin||'default')?'selected':''}>${sk}</option>`).join('');
            }
            if(!spritePack || !spritePack.imageUrl) drawFallbackSprite(p);
          }

          function loadSpriteImage(url){
            return new Promise((resolve,reject)=>{
              const im=new Image(); im.crossOrigin='anonymous';
              im.onload=()=>resolve(im); im.onerror=reject; im.src=url + (url.includes('?')?'&':'?') + 'v=' + Date.now();
            });
          }

          function frameByIndex(i){
            if(!spritePack || !Array.isArray(spritePack.frames)) return null;
            return spritePack.frames.find(f=>Number(f.i)===Number(i)) || null;
          }

          function startAnimation(){
            if(frameTimer) { clearInterval(frameTimer); frameTimer=null; }
            if(!spritePack || !spriteImg) { drawFallbackSprite(petState); return; }
            const fps = Math.max(1, Math.min(30, Number(spritePack.fps||10)));
            const seq = (spritePack.animations && spritePack.animations[activeAnim]) ? spritePack.animations[activeAnim] : (spritePack.animations?.idle || [0]);
            if(!Array.isArray(seq) || !seq.length){ drawFallbackSprite(petState); return; }
            frameCursor=0;
            const tick=()=>{
              const idx = Number(seq[frameCursor % seq.length] || 0);
              frameCursor++;
              const fr = frameByIndex(idx);
              if(!fr){ drawFallbackSprite(petState); return; }
              ctx.clearRect(0,0,96,96);
              const dw = 96, dh = 96;
              try { ctx.drawImage(spriteImg, Number(fr.x||0), Number(fr.y||0), Number(fr.w||32), Number(fr.h||32), 0,0,dw,dh); }
              catch(e){ drawFallbackSprite(petState); }
            };
            tick();
            frameTimer=setInterval(tick, Math.round(1000/fps));
          }

          function refreshAnimSelect(){
            if(!animSelect) return;
            const names = spritePack?.animations ? Object.keys(spritePack.animations) : [];
            animSelect.innerHTML = names.length ? names.map(n=>`<option value="${n}">${n}</option>`).join('') : '<option value="idle">idle</option>';
            if(names.includes(activeAnim)) animSelect.value = activeAnim;
            else activeAnim = (names[0] || 'idle');
          }

          async function loadSpritePack(){
            try{
              const r=await fetch(API+'pet/sprite-pack?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});
              if(!r.ok) return;
              const j=await r.json();
              spritePack=j.pack||null;
              refreshAnimSelect();
              if(spritePack && spritePack.imageUrl){
                try{ spriteImg = await loadSpriteImage(spritePack.imageUrl); } catch(e){ spriteImg=null; }
              } else spriteImg=null;
              startAnimation();
            }catch{}
          }

          async function loadPet(){
            try{
              const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});
              if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; }
              const j=await r.json(); renderPet(j.pet||null);
            }catch{ petView.textContent='Creature unavailable right now.'; }
          }

          async function post(path,payload,form){
            const opts={method:'POST',credentials:'include',headers:{'X-WP-Nonce':nonce}};
            if(form){ opts.body=form; }
            else { opts.headers['content-type']='application/json'; opts.body=JSON.stringify(payload||{}); }
            const r=await fetch(API+path,opts);
            const j=await r.json().catch(()=>({}));
            return {ok:r.ok,data:j};
          }

          async function petAction(action, extra={}){
            if(petStatus) petStatus.textContent='Working...';
            const out=await post('pet/action',Object.assign({action},extra||{}));
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Action failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent='Done.';
          }

          document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(petNameInput?.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:petSkinSelect?.value||'default'}));

          document.getElementById('pph-pet-adopt')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Saving creature type...';
            const out=await post('pet/adopt',{species:speciesSelect?.value||'sprout', personality:personalitySelect?.value||'brave'});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Adopt failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent='Creature type updated.';
          });

          document.getElementById('pph-pet-train')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Training...';
            const out=await post('pet/train',{});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Training failed.'; return; }
            renderPet(out.data.pet||null); if(petStatus) petStatus.textContent=`Training complete (+${out.data.xpGained||0} XP)`;
          });

          document.getElementById('pph-pet-spar')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Sparring...';
            const out=await post('pet/battle/spar',{});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Battle failed.'; return; }
            renderPet(out.data.pet||null);
            const r=out.data.result==='win'?'WIN':'LOSS';
            if(petStatus) petStatus.textContent=`${r} · +${out.data.xpGained||0} XP`;
          });

          uploadForm?.addEventListener('submit', async (e)=>{
            e.preventDefault();
            if(petStatus) petStatus.textContent='Uploading sprite sheet...';
            const fd = new FormData(uploadForm);
            const out = await post('pet/sprite-upload', null, fd);
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Upload failed.'; return; }
            spritePack = out.data.pack || null;
            refreshAnimSelect();
            if(spritePack?.imageUrl){ try{ spriteImg = await loadSpriteImage(spritePack.imageUrl); }catch(e){ spriteImg=null; } }
            startAnimation();
            if(petStatus) petStatus.textContent='Sprite sheet uploaded.';
          });

          document.getElementById('pph-sprite-default')?.addEventListener('click', async ()=>{
            if(petStatus) petStatus.textContent='Switching to default sheet...';
            const out = await post('pet/sprite-use-default', {});
            if(!out.ok){ if(petStatus) petStatus.textContent=out.data?.error || 'Failed.'; return; }
            spritePack = out.data.pack || null;
            refreshAnimSelect();
            if(spritePack?.imageUrl){ try{ spriteImg = await loadSpriteImage(spritePack.imageUrl); }catch(e){ spriteImg=null; } }
            startAnimation();
            if(petStatus) petStatus.textContent='Default T-Rex sheet applied.';
          });

          animSelect?.addEventListener('change', ()=>{ activeAnim = animSelect.value || 'idle'; startAnimation(); });

          loadPet();
          loadSpritePack();
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Prism Creatures QA hotfix (centering + state sanitize + trex defaults) =====
if (!function_exists('prismtek_pet_state_sanitize_identity')) {
    function prismtek_pet_state_sanitize_identity($state) {
        if (!is_array($state)) $state = prismtek_pet_default_state();
        $allowedSpecies = ['sprout','ember','tidal','volt','shade','blob'];
        $allowedPersonality = ['brave','curious','calm','chaotic'];

        $species = $state['species'] ?? 'sprout';
        if (!is_string($species)) $species = is_array($species) ? reset($species) : 'sprout';
        $species = sanitize_key((string)$species);
        if (!in_array($species, $allowedSpecies, true)) $species = 'sprout';
        if ($species === 'blob') $species = 'sprout'; // migrate legacy naming

        $personality = $state['personality'] ?? 'brave';
        if (!is_string($personality)) $personality = is_array($personality) ? reset($personality) : 'brave';
        $personality = sanitize_key((string)$personality);
        if (!in_array($personality, $allowedPersonality, true)) $personality = 'brave';

        $state['species'] = $species;
        $state['personality'] = $personality;
        return $state;
    }
}

add_action('init', function(){
    // sanitize current user's legacy/corrupted pet identity fields
    $uid = get_current_user_id();
    if ($uid) {
        $state = get_user_meta($uid, 'prismtek_pet_state', true);
        if (is_array($state)) {
            $clean = prismtek_pet_state_sanitize_identity($state);
            if ($clean !== $state) update_user_meta($uid, 'prismtek_pet_state', $clean);
        }
    }

    // upgrade default trex sheet pack dimensions if still old grid defaults
    $def = get_option('prismtek_pet_default_sprite_pack', []);
    $url = content_url('uploads/prismtek-creatures/trex-sheet.png');
    $needs = !is_array($def) || (($def['imageUrl'] ?? '') === $url && (int)($def['frameW'] ?? 0) <= 40);
    if ($needs) {
        $frames = [];
        $i = 0;
        for ($r=0;$r<4;$r++) {
            for ($c=0;$c<4;$c++) {
                $frames[] = ['i'=>$i++, 'x'=>$c*96, 'y'=>$r*80, 'w'=>96, 'h'=>80];
            }
        }
        $pack = [
            'source' => 'default',
            'imageUrl' => esc_url_raw($url),
            'sheetW' => 384,
            'sheetH' => 320,
            'frameW' => 96,
            'frameH' => 80,
            'columns' => 4,
            'rows' => 4,
            'fps' => 8,
            'frames' => $frames,
            'animations' => [
                'idle' => [0,1,2,3],
                'walk' => [4,5,6,7],
                'run'  => [8,9,10,11],
                'attack' => [12,13,14,15],
            ],
            'metaFormat' => 'grid',
        ];
        update_option('prismtek_pet_default_sprite_pack', $pack, false);
    }
});

add_action('init', function(){
    remove_shortcode('prism_creatures_portal');
    add_shortcode('prism_creatures_portal', function(){
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));
        ob_start(); ?>
        <section class="pph-wrap pph-creatures-wrap" style="margin-top:0;gap:14px;">
          <?php if(!$logged): ?>
            <article class="pph-card"><p>Log in to adopt, train, and upload sprite sheets.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
          <?php else: ?>
            <article class="pph-card" id="pph-pet-panel">
              <div class="pph-pet-head">
                <canvas id="pph-pet-canvas" class="pph-pet-canvas" width="128" height="128"></canvas>
                <div>
                  <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
                  <div id="pph-pet-bars"></div>
                </div>
              </div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
                <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
              </div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button type="button" id="pph-pet-adopt">Save Creature Type</button><button type="button" id="pph-pet-train">Train (+XP)</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button type="button" id="pph-pet-feed">Feed</button><button type="button" id="pph-pet-play">Play</button><button type="button" id="pph-pet-rest">Rest</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button type="button" id="pph-pet-rename">Save Name</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"><option value="default">default</option></select><button type="button" id="pph-pet-skin-save">Apply Skin</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr;"><button type="button" id="pph-pet-spar">Spar Battle</button></div>

              <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
              <h4 style="margin:4px 0">Sprite Sheet Studio</h4>
              <form id="pph-sprite-upload-form" class="pph-form" enctype="multipart/form-data">
                <input type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required />
                <input type="file" name="meta" accept="application/json,.json" />
                <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><input type="number" name="frameW" min="8" max="1024" placeholder="Frame W" /><input type="number" name="frameH" min="8" max="1024" placeholder="Frame H" /><input type="number" name="fps" min="1" max="30" placeholder="FPS" /></div>
                <select name="format"><option value="grid">Grid</option><option value="aseprite">Aseprite JSON</option><option value="texturepacker">TexturePacker JSON</option><option value="custom">Custom JSON</option></select>
                <button type="submit">Upload Sprite Sheet</button>
              </form>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button type="button" id="pph-sprite-default">Use Default T-Rex Sheet</button><select id="pph-sprite-animation"></select></div>
              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>
          <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
        </section>
        <style>
          .pph-pet-head{display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:start}
          .pph-pet-canvas{width:128px;height:128px;border:2px solid #6b74c7;background:#0e1026;image-rendering:pixelated;display:block}
          .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:72px}
          .pph-bar{height:8px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}
          .pph-bar > span{display:block;height:100%}
          @media (max-width:700px){.pph-pet-head{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
          const petView=document.getElementById('pph-pet-view'); const canvas=document.getElementById('pph-pet-canvas'); if(!petView||!canvas) return;
          const ctx=canvas.getContext('2d'); ctx.imageSmoothingEnabled=false;
          const bars=document.getElementById('pph-pet-bars'); const petStatus=document.getElementById('pph-pet-status');
          const petNameInput=document.getElementById('pph-pet-name'); const petSkinSelect=document.getElementById('pph-pet-skin');
          const speciesSelect=document.getElementById('pph-pet-species'); const personalitySelect=document.getElementById('pph-pet-personality');
          const animSelect=document.getElementById('pph-sprite-animation'); const uploadForm=document.getElementById('pph-sprite-upload-form');
          let spritePack=null, petState=null, spriteImg=null, frameTimer=null, activeAnim='idle', frameCursor=0;

          const allowedSpecies=['sprout','ember','tidal','volt','shade'];
          const allowedPersonality=['brave','curious','calm','chaotic'];
          const s=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?(String(v[0]??d)):d);

          function normalizePet(p){
            if(!p||typeof p!=='object') return null;
            const out={...p};
            out.species=s(out.species,'sprout'); if(!allowedSpecies.includes(out.species)) out.species='sprout';
            out.personality=s(out.personality,'brave'); if(!allowedPersonality.includes(out.personality)) out.personality='brave';
            out.form=s(out.form,`${out.species}-${out.personality}-cub`);
            out.name=s(out.name,'Prismo'); out.skin=s(out.skin,'default'); out.stage=s(out.stage,'baby');
            ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>out[k]=Number(out[k]||0));
            return out;
          }

          function drawFallback(){ ctx.clearRect(0,0,128,128); ctx.fillStyle='#59d9ff'; ctx.fillRect(36,36,56,56); ctx.fillStyle='#0e1026'; ctx.fillRect(52,52,8,8); ctx.fillRect(68,52,8,8); }

          function fitDraw(img,sx,sy,sw,sh){
            ctx.clearRect(0,0,128,128);
            const scale=Math.min(128/sw,128/sh); const dw=Math.max(1,Math.floor(sw*scale)); const dh=Math.max(1,Math.floor(sh*scale));
            const dx=Math.floor((128-dw)/2); const dy=Math.floor((128-dh)/2);
            try{ ctx.drawImage(img,sx,sy,sw,sh,dx,dy,dw,dh);}catch(e){ drawFallback(); }
          }

          function frameByIndex(i){ if(!spritePack||!Array.isArray(spritePack.frames)) return null; return spritePack.frames.find(f=>Number(f.i)===Number(i))||null; }
          function refreshAnimSelect(){
            const names=spritePack?.animations?Object.keys(spritePack.animations):[];
            animSelect.innerHTML=names.length?names.map(n=>`<option value="${n}">${n}</option>`).join(''):'<option value="idle">idle</option>';
            if(names.includes(activeAnim)) animSelect.value=activeAnim; else activeAnim=(names[0]||'idle');
          }

          function coerceTrex(pack){
            if(!pack||!pack.imageUrl) return pack;
            if(!String(pack.imageUrl).includes('trex-sheet.png')) return pack;
            if(Number(pack.frameW||0) > 40) return pack; // already corrected
            const frames=[]; let i=0; for(let r=0;r<4;r++)for(let c=0;c<4;c++)frames.push({i:i++,x:c*96,y:r*80,w:96,h:80});
            return {...pack, frameW:96, frameH:80, columns:4, rows:4, fps:8, frames, animations:{idle:[0,1,2,3],walk:[4,5,6,7],run:[8,9,10,11],attack:[12,13,14,15]}};
          }

          function startAnimation(){
            if(frameTimer){clearInterval(frameTimer);frameTimer=null;}
            if(!spritePack||!spriteImg){drawFallback();return;}
            const fps=Math.max(1,Math.min(30,Number(spritePack.fps||10)));
            const seq=(spritePack.animations&&spritePack.animations[activeAnim])?spritePack.animations[activeAnim]:(spritePack.animations?.idle||[0]);
            if(!Array.isArray(seq)||!seq.length){drawFallback();return;}
            frameCursor=0;
            const tick=()=>{ const idx=Number(seq[frameCursor%seq.length]||0); frameCursor++; const fr=frameByIndex(idx); if(!fr){drawFallback();return;} fitDraw(spriteImg,Number(fr.x||0),Number(fr.y||0),Math.max(1,Number(fr.w||32)),Math.max(1,Number(fr.h||32)));};
            tick(); frameTimer=setInterval(tick,Math.round(1000/fps));
          }

          function renderBars(p){
            const row=(label,val,color)=>`<div style="font-size:10px;margin-top:2px">${label} ${Math.round(val)}%</div><div class="pph-bar"><span style="width:${Math.max(0,Math.min(100,val||0))}%;background:${color}"></span></div>`;
            bars.innerHTML=row('Health',p.health,'#5de28f')+row('Energy',p.energy,'#59d9ff')+row('Happiness',p.happiness,'#f8c062')+row('Hunger',p.hunger,'#d98fff');
          }

          function renderPet(raw){
            const p=normalizePet(raw); if(!p) return; petState=p;
            renderBars(p);
            petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`;
            if(!petNameInput.value) petNameInput.value=p.name; speciesSelect.value=p.species; personalitySelect.value=p.personality;
            const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default']; petSkinSelect.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join('');
          }

          function loadImg(url){ return new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=url+(url.includes('?')?'&':'?')+'v='+Date.now(); }); }
          async function post(path,payload,form){ const opts={method:'POST',credentials:'include',headers:{'X-WP-Nonce':nonce}}; if(form){opts.body=form;} else {opts.headers['content-type']='application/json'; opts.body=JSON.stringify(payload||{});} const r=await fetch(API+path,opts); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }

          async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
          async function loadPack(){ const r=await fetch(API+'pet/sprite-pack?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ drawFallback(); return; } const j=await r.json(); spritePack=coerceTrex(j.pack||null); refreshAnimSelect(); if(spritePack?.imageUrl){ try{spriteImg=await loadImg(spritePack.imageUrl);}catch(e){spriteImg=null;} } startAnimation(); }

          async function petAction(action,extra={}){ petStatus.textContent='Working...'; const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){petStatus.textContent=out.data?.error||'Action failed.';return;} renderPet(out.data.pet||null); petStatus.textContent='Done.'; }
          document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(petNameInput?.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:petSkinSelect?.value||'default'}));
          document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{petStatus.textContent='Saving creature type...';const out=await post('pet/adopt',{species:speciesSelect.value||'sprout',personality:personalitySelect.value||'brave'});if(!out.ok){petStatus.textContent=out.data?.error||'Adopt failed.';return;}renderPet(out.data.pet||null);petStatus.textContent='Creature type updated.';});
          document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{petStatus.textContent='Training...';const out=await post('pet/train',{});if(!out.ok){petStatus.textContent=out.data?.error||'Training failed.';return;}renderPet(out.data.pet||null);petStatus.textContent=`Training complete (+${out.data.xpGained||0} XP)`;});
          document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{petStatus.textContent='Sparring...';const out=await post('pet/battle/spar',{});if(!out.ok){petStatus.textContent=out.data?.error||'Battle failed.';return;}renderPet(out.data.pet||null);petStatus.textContent=`${out.data.result==='win'?'WIN':'LOSS'} · +${out.data.xpGained||0} XP`;});

          uploadForm?.addEventListener('submit', async (e)=>{e.preventDefault(); petStatus.textContent='Uploading sprite sheet...'; const fd=new FormData(uploadForm); const out=await post('pet/sprite-upload',null,fd); if(!out.ok){petStatus.textContent=out.data?.error||'Upload failed.';return;} spritePack=out.data.pack||null; refreshAnimSelect(); if(spritePack?.imageUrl){ try{spriteImg=await loadImg(spritePack.imageUrl);}catch(e){spriteImg=null;} } startAnimation(); petStatus.textContent='Sprite sheet uploaded.'; });
          document.getElementById('pph-sprite-default')?.addEventListener('click', async ()=>{ petStatus.textContent='Switching to default sheet...'; const out=await post('pet/sprite-use-default',{}); if(!out.ok){petStatus.textContent=out.data?.error||'Failed.';return;} spritePack=coerceTrex(out.data.pack||null); refreshAnimSelect(); if(spritePack?.imageUrl){ try{spriteImg=await loadImg(spritePack.imageUrl);}catch(e){spriteImg=null;} } startAnimation(); petStatus.textContent='Default T-Rex sheet applied.'; });
          animSelect?.addEventListener('change',()=>{activeAnim=animSelect.value||'idle'; startAnimation();});

          loadPet().catch(()=>{}); loadPack().catch(()=>{});
        })();
        </script>
        <?php return ob_get_clean();
    });
});

// ===== My Account portal + password-at-signup flow (2026-03-09h) =====
add_filter('register_url', function($url){
    return home_url('/my-account/');
}, 99, 1);

add_action('init', function(){
    remove_shortcode('prism_account_portal');
    add_shortcode('prism_account_portal', function(){
        $logged = is_user_logged_in();
        $uid = get_current_user_id();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));
        $user = $logged ? wp_get_current_user() : null;

        ob_start(); ?>
        <section class="pph-wrap" style="margin-top:0;gap:14px;">
          <article class="pph-card">
            <h3>My Account</h3>
            <p style="margin:.3rem 0 0;color:#dbe4ff">Create account with your own password, then manage profile settings.</p>
          </article>

          <?php if(!$logged): ?>
            <article class="pph-card">
              <h4>Create Account</h4>
              <form id="pph-register-form" class="pph-form">
                <input name="username" type="text" minlength="3" maxlength="24" placeholder="Username" required />
                <input name="email" type="email" placeholder="Email" required />
                <input name="password" type="password" minlength="8" placeholder="Create password (min 8 chars)" required />
                <label><input type="checkbox" name="age13" required /> I am 13+ or have parent/guardian permission</label>
                <button type="submit">Create Account</button>
              </form>
              <p id="pph-register-status" class="pph-status"></p>
              <p style="font-size:11px">Already have an account? <a href="<?php echo esc_url(wp_login_url(home_url('/my-account/'))); ?>">Login</a></p>
            </article>
          <?php else: ?>
            <article class="pph-card">
              <p>Logged in as <strong><?php echo esc_html((string)($user->display_name ?? $user->user_login ?? 'user')); ?></strong></p>
              <p><a href="<?php echo esc_url(wp_logout_url(home_url('/my-account/'))); ?>">Logout</a></p>
            </article>

            <article class="pph-card">
              <h4>Profile Settings</h4>
              <form id="pph-profile-form" class="pph-form">
                <input name="displayName" type="text" maxlength="24" placeholder="Display name" />
                <input name="bio" type="text" maxlength="120" placeholder="Short bio" />
                <input name="favoriteGame" type="text" maxlength="60" placeholder="Favorite game slug" />
                <label>Theme Color <input name="themeColor" type="color" value="#59d9ff" /></label>
                <button type="submit">Save Profile</button>
              </form>
              <p id="pph-profile-status" class="pph-status"></p>
            </article>
          <?php endif; ?>
        </section>

        <script>
        (()=>{
          const API = '<?php echo esc_js($api); ?>';
          const nonce = '<?php echo esc_js($nonce); ?>';
          const registerForm = document.getElementById('pph-register-form');
          const registerStatus = document.getElementById('pph-register-status');
          const profileForm = document.getElementById('pph-profile-form');
          const profileStatus = document.getElementById('pph-profile-status');

          registerForm?.addEventListener('submit', async (e)=>{
            e.preventDefault();
            if(registerStatus) registerStatus.textContent='Creating account...';
            const fd = new FormData(registerForm);
            const payload = {
              username: String(fd.get('username')||'').trim(),
              email: String(fd.get('email')||'').trim(),
              password: String(fd.get('password')||''),
              age13: !!fd.get('age13')
            };
            const r = await fetch(API+'register', {method:'POST', headers:{'content-type':'application/json'}, body:JSON.stringify(payload)});
            const j = await r.json().catch(()=>({}));
            if(!r.ok){
              const m = j.error || 'registration_failed';
              const pretty = {
                age_consent_required:'Please confirm 13+ or guardian permission.',
                invalid_username:'Username must be at least 3 characters.',
                invalid_email:'Please enter a valid email.',
                weak_password:'Password must be at least 8 characters.',
                username_taken:'Username is already taken.',
                email_taken:'Email is already in use.',
                rate_limited:'Too many attempts. Please wait a moment.'
              };
              if(registerStatus) registerStatus.textContent = pretty[m] || m;
              return;
            }
            if(registerStatus) registerStatus.textContent='Account created! Reloading...';
            setTimeout(()=>location.reload(), 700);
          });

          async function loadProfile(){
            if(!profileForm) return;
            const r = await fetch(API+'profile?ts='+Date.now(), {credentials:'include', cache:'no-store', headers: nonce ? {'X-WP-Nonce':nonce} : {}});
            if(!r.ok) return;
            const j = await r.json();
            profileForm.displayName.value = j.displayName || '';
            profileForm.bio.value = j.bio || '';
            profileForm.favoriteGame.value = j.favoriteGame || '';
            profileForm.themeColor.value = j.themeColor || '#59d9ff';
          }

          profileForm?.addEventListener('submit', async (e)=>{
            e.preventDefault();
            if(profileStatus) profileStatus.textContent='Saving...';
            const fd = new FormData(profileForm);
            const payload = {
              displayName: String(fd.get('displayName')||'').trim(),
              bio: String(fd.get('bio')||'').trim(),
              favoriteGame: String(fd.get('favoriteGame')||'').trim(),
              themeColor: String(fd.get('themeColor')||'#59d9ff')
            };
            const r = await fetch(API+'profile', {method:'POST', credentials:'include', headers:{'content-type':'application/json','X-WP-Nonce':nonce}, body:JSON.stringify(payload)});
            if(!r.ok){ if(profileStatus) profileStatus.textContent='Save failed.'; return; }
            if(profileStatus) profileStatus.textContent='Profile saved.';
          });

          loadProfile().catch(()=>{});
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});

// ===== Account UX upgrades + Sprite animation stabilization (2026-03-09i) =====

add_action('init', function(){
    remove_shortcode('prism_account_portal');
    add_shortcode('prism_account_portal', function(){
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));
        $user = $logged ? wp_get_current_user() : null;
        ob_start(); ?>
        <section class="pph-wrap" style="margin-top:0;gap:14px;">
          <article class="pph-card"><h3>My Account</h3><p style="margin:.3rem 0 0;color:#dbe4ff">Create your account with your own password and manage your profile.</p></article>

          <?php if(!$logged): ?>
          <article class="pph-card">
            <h4>Create Account</h4>
            <form id="pph-register-form" class="pph-form">
              <input name="username" type="text" minlength="3" maxlength="24" placeholder="Username" required />
              <input name="email" type="email" placeholder="Email" required />
              <div class="pph-tool-row" style="grid-template-columns:1fr auto;align-items:center;gap:8px;">
                <input id="pph-password" name="password" type="password" minlength="8" placeholder="Create password (min 8 chars)" required />
                <button type="button" id="pph-pass-toggle" style="padding:8px 10px;white-space:nowrap;">Show</button>
              </div>
              <input id="pph-password-confirm" name="passwordConfirm" type="password" minlength="8" placeholder="Confirm password" required />
              <div id="pph-pass-meter-wrap" style="margin-top:2px;">
                <div style="font-size:11px;margin-bottom:4px;">Password strength: <span id="pph-pass-label">—</span></div>
                <div style="height:8px;background:#1b1f45;border:1px solid #4f59a6;"><span id="pph-pass-meter" style="display:block;height:100%;width:0;background:#888;"></span></div>
              </div>
              <label><input type="checkbox" name="age13" required /> I am 13+ or have parent/guardian permission</label>
              <button type="submit" id="pph-register-submit">Create Account</button>
            </form>
            <p id="pph-register-status" class="pph-status"></p>
            <p style="font-size:11px">Already have an account? <a href="<?php echo esc_url(wp_login_url(home_url('/my-account/'))); ?>">Login</a></p>
          </article>
          <?php else: ?>
          <article class="pph-card">
            <p>Logged in as <strong><?php echo esc_html((string)($user->display_name ?? $user->user_login ?? 'user')); ?></strong></p>
            <p><a href="<?php echo esc_url(wp_logout_url(home_url('/my-account/'))); ?>">Logout</a></p>
          </article>
          <?php endif; ?>
        </section>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
          const registerForm=document.getElementById('pph-register-form'); const registerStatus=document.getElementById('pph-register-status');
          const pass=document.getElementById('pph-password'); const pass2=document.getElementById('pph-password-confirm');
          const passToggle=document.getElementById('pph-pass-toggle'); const passMeter=document.getElementById('pph-pass-meter'); const passLabel=document.getElementById('pph-pass-label');
          const submitBtn=document.getElementById('pph-register-submit');

          function scorePassword(p){
            if(!p) return 0;
            let s=0;
            if(p.length>=8) s+=25; if(p.length>=12) s+=15;
            if(/[a-z]/.test(p)) s+=10; if(/[A-Z]/.test(p)) s+=15;
            if(/[0-9]/.test(p)) s+=15; if(/[^A-Za-z0-9]/.test(p)) s+=20;
            return Math.min(100,s);
          }
          function meterColor(v){ if(v<35) return '#d85a5a'; if(v<70) return '#e5b34f'; return '#5de28f'; }
          function meterLabel(v){ if(v<35) return 'Weak'; if(v<70) return 'Okay'; return 'Strong'; }

          function refreshPassUI(){
            if(!pass) return;
            const v=scorePassword(pass.value||'');
            if(passMeter){ passMeter.style.width=v+'%'; passMeter.style.background=meterColor(v); }
            if(passLabel) passLabel.textContent=meterLabel(v);
            const mismatch = pass2 && pass.value !== pass2.value;
            if(pass2){ pass2.style.borderColor = mismatch ? '#d85a5a' : ''; }
            if(submitBtn) submitBtn.disabled = mismatch || v < 35;
          }

          pass?.addEventListener('input', refreshPassUI);
          pass2?.addEventListener('input', refreshPassUI);
          passToggle?.addEventListener('click', ()=>{
            if(!pass || !pass2) return;
            const next = pass.type === 'password' ? 'text' : 'password';
            pass.type = next; pass2.type = next;
            passToggle.textContent = next === 'password' ? 'Show' : 'Hide';
          });

          registerForm?.addEventListener('submit', async (e)=>{
            e.preventDefault();
            const fd=new FormData(registerForm);
            const p1=String(fd.get('password')||'');
            const p2=String(fd.get('passwordConfirm')||'');
            if(p1!==p2){ if(registerStatus) registerStatus.textContent='Passwords do not match.'; return; }
            if(scorePassword(p1)<35){ if(registerStatus) registerStatus.textContent='Please use a stronger password.'; return; }

            if(registerStatus) registerStatus.textContent='Creating account...';
            const payload={username:String(fd.get('username')||'').trim(),email:String(fd.get('email')||'').trim(),password:p1,age13:!!fd.get('age13')};
            const r=await fetch(API+'register',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(payload)});
            const j=await r.json().catch(()=>({}));
            if(!r.ok){
              const map={age_consent_required:'Please confirm 13+ or guardian permission.',invalid_username:'Username must be at least 3 characters.',invalid_email:'Please enter a valid email.',weak_password:'Password must be at least 8 characters.',username_taken:'Username is already taken.',email_taken:'Email is already in use.',rate_limited:'Too many attempts. Please wait a moment.'};
              if(registerStatus) registerStatus.textContent=map[j.error]||j.error||'Registration failed.'; return;
            }
            if(registerStatus) registerStatus.textContent='Account created! Reloading...';
            setTimeout(()=>location.reload(),700);
          });

          refreshPassUI();
        })();
        </script>
        <?php return ob_get_clean();
    });
});

if (!function_exists('prismtek_sprite_pack_sanitize_server')) {
    function prismtek_sprite_pack_sanitize_server($pack) {
        if (!is_array($pack)) return prismtek_pet_default_sprite_pack();
        $sheetW = max(1, (int)($pack['sheetW'] ?? 0));
        $sheetH = max(1, (int)($pack['sheetH'] ?? 0));
        $frameW = max(1, (int)($pack['frameW'] ?? 32));
        $frameH = max(1, (int)($pack['frameH'] ?? 32));
        $fps = max(1, min(30, (int)($pack['fps'] ?? 10)));
        $img = esc_url_raw((string)($pack['imageUrl'] ?? ''));

        // Special fix for known trex sheet default dimensions
        if (str_contains($img, 'trex-sheet.png') && $frameW <= 40) {
            $frameW = 96; $frameH = 80; $sheetW = 384; $sheetH = 320; $fps = 8;
            $frames = []; $i=0;
            for($r=0;$r<4;$r++) for($c=0;$c<4;$c++) $frames[]=['i'=>$i++,'x'=>$c*96,'y'=>$r*80,'w'=>96,'h'=>80];
            $animations = ['idle'=>[0,1,2,3],'walk'=>[4,5,6,7],'run'=>[8,9,10,11],'attack'=>[12,13,14,15]];
            return [
                'source'=>(string)($pack['source'] ?? 'default'),'imageUrl'=>$img,'sheetW'=>$sheetW,'sheetH'=>$sheetH,
                'frameW'=>$frameW,'frameH'=>$frameH,'columns'=>4,'rows'=>4,'fps'=>$fps,'frames'=>$frames,'animations'=>$animations,
                'metaFormat'=>(string)($pack['metaFormat'] ?? 'grid')
            ];
        }

        $rawFrames = is_array($pack['frames'] ?? null) ? $pack['frames'] : [];
        $frames = [];
        $i = 0;
        foreach ($rawFrames as $fr) {
            if (!is_array($fr)) continue;
            $x = max(0, (int)($fr['x'] ?? 0));
            $y = max(0, (int)($fr['y'] ?? 0));
            $w = max(1, (int)($fr['w'] ?? $frameW));
            $h = max(1, (int)($fr['h'] ?? $frameH));
            if ($x + $w > $sheetW) $w = max(1, $sheetW - $x);
            if ($y + $h > $sheetH) $h = max(1, $sheetH - $y);
            if ($w < 1 || $h < 1) continue;
            $frames[] = ['i'=>$i++, 'x'=>$x, 'y'=>$y, 'w'=>$w, 'h'=>$h];
        }

        if (empty($frames)) {
            $cols = max(1, (int)floor($sheetW / $frameW));
            $rows = max(1, (int)floor($sheetH / $frameH));
            $i=0;
            for($r=0;$r<$rows;$r++) for($c=0;$c<$cols;$c++) $frames[]=['i'=>$i++,'x'=>$c*$frameW,'y'=>$r*$frameH,'w'=>$frameW,'h'=>$frameH];
        }

        $maxIdx = max(0, count($frames)-1);
        $rawAnims = is_array($pack['animations'] ?? null) ? $pack['animations'] : [];
        $anims = [];
        foreach ($rawAnims as $name=>$seq) {
            $k = sanitize_key((string)$name);
            if (!is_array($seq)) continue;
            $clean = [];
            foreach ($seq as $v) {
                $iv = (int)$v;
                if ($iv >= 0 && $iv <= $maxIdx) $clean[] = $iv;
            }
            $clean = array_values(array_unique($clean));
            if (!empty($clean)) $anims[$k] = $clean;
        }
        if (empty($anims)) {
            $idle = [];
            for($x=0;$x<min(4,count($frames));$x++) $idle[] = $x;
            $anims = ['idle' => !empty($idle) ? $idle : [0]];
        }

        return [
            'source' => (string)($pack['source'] ?? 'user'),
            'imageUrl' => $img,
            'sheetW' => $sheetW,
            'sheetH' => $sheetH,
            'frameW' => $frameW,
            'frameH' => $frameH,
            'columns' => max(1, (int)floor($sheetW / $frameW)),
            'rows' => max(1, (int)floor($sheetH / $frameH)),
            'fps' => $fps,
            'frames' => $frames,
            'animations' => $anims,
            'metaFormat' => (string)($pack['metaFormat'] ?? 'grid'),
        ];
    }
}

add_action('rest_api_init', function(){
    // override getter to always sanitize/repair pack before returning
    register_rest_route('prismtek/v1', '/pet/sprite-pack', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            $pack = prismtek_pet_get_sprite_pack($uid);
            $clean = prismtek_sprite_pack_sanitize_server($pack);
            if ($clean !== $pack) prismtek_pet_set_sprite_pack($uid, $clean);
            return rest_ensure_response(['ok'=>true, 'pack'=>$clean]);
        }
    ]);

    // override uploader to sanitize immediately after normalize
    register_rest_route('prismtek/v1', '/pet/sprite-upload', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            if (empty($_FILES['sheet'])) return new WP_REST_Response(['ok'=>false,'error'=>'missing_sheet'], 400);

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $overrides = ['test_form'=>false,'mimes'=>['png'=>'image/png','jpg|jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif']];
            $up = wp_handle_upload($_FILES['sheet'], $overrides);
            if (!empty($up['error'])) return new WP_REST_Response(['ok'=>false,'error'=>'upload_failed','detail'=>$up['error']], 400);

            $path = (string)($up['file'] ?? '');
            $url = esc_url_raw((string)($up['url'] ?? ''));
            $dim = @getimagesize($path);
            if (!$dim) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_image'], 400);

            $metaRaw = (string)$request->get_param('metaJson');
            $meta = [];
            if ($metaRaw !== '') { $j = json_decode($metaRaw, true); if (is_array($j)) $meta = $j; }
            if (!empty($_FILES['meta']) && is_uploaded_file((string)$_FILES['meta']['tmp_name'])) {
                $raw = @file_get_contents((string)$_FILES['meta']['tmp_name']);
                $j = json_decode((string)$raw, true);
                if (is_array($j)) $meta = $j;
            }
            $meta['frameW'] = (int)($request->get_param('frameW') ?: ($meta['frameW'] ?? 32));
            $meta['frameH'] = (int)($request->get_param('frameH') ?: ($meta['frameH'] ?? 32));
            $meta['fps'] = (int)($request->get_param('fps') ?: ($meta['fps'] ?? 10));
            $meta['format'] = sanitize_key((string)($request->get_param('format') ?: ($meta['format'] ?? 'grid')));

            $pack = prismtek_pet_normalize_sprite_pack($url, (int)$dim[0], (int)$dim[1], $meta);
            $pack = prismtek_sprite_pack_sanitize_server($pack);
            prismtek_pet_set_sprite_pack($uid, $pack);
            return rest_ensure_response(['ok'=>true,'pack'=>$pack]);
        }
    ]);
});

// ===== Prism Creatures final animation + UI polish (2026-03-09j) =====
if (!function_exists('prismtek_sprite_pack_force_clean')) {
    function prismtek_sprite_pack_force_clean($pack) {
        if (!is_array($pack)) $pack = prismtek_pet_default_sprite_pack();
        $img = esc_url_raw((string)($pack['imageUrl'] ?? ''));
        $sheetW = max(1, (int)($pack['sheetW'] ?? 0));
        $sheetH = max(1, (int)($pack['sheetH'] ?? 0));
        $frameW = max(1, (int)($pack['frameW'] ?? 32));
        $frameH = max(1, (int)($pack['frameH'] ?? 32));
        $fps = max(1, min(24, (int)($pack['fps'] ?? 10)));

        // Strong defaults for known T-Rex sheet
        if (str_contains($img, 'trex-sheet.png')) {
            $sheetW = 384; $sheetH = 320; $frameW = 96; $frameH = 80; $fps = 8;
            $frames = [];
            $idx = 0;
            for ($r=0;$r<4;$r++) {
                for ($c=0;$c<4;$c++) {
                    $frames[] = ['i'=>$idx++, 'x'=>$c*96, 'y'=>$r*80, 'w'=>96, 'h'=>80];
                }
            }
            return [
                'source' => (string)($pack['source'] ?? 'default'),
                'imageUrl' => $img,
                'sheetW' => $sheetW,
                'sheetH' => $sheetH,
                'frameW' => $frameW,
                'frameH' => $frameH,
                'columns' => 4,
                'rows' => 4,
                'fps' => $fps,
                'frames' => $frames,
                'animations' => [
                    'idle' => [0,1,2,3],
                    'walk' => [4,5,6,7],
                    'run' => [8,9,10,11],
                    'attack' => [12,13,14,15],
                ],
                'metaFormat' => 'grid',
            ];
        }

        $frames = [];
        if (!empty($pack['frames']) && is_array($pack['frames'])) {
            foreach ($pack['frames'] as $i => $fr) {
                if (!is_array($fr)) continue;
                $x = max(0, (int)($fr['x'] ?? 0));
                $y = max(0, (int)($fr['y'] ?? 0));
                $w = max(1, (int)($fr['w'] ?? $frameW));
                $h = max(1, (int)($fr['h'] ?? $frameH));
                if ($sheetW > 0 && $x + $w > $sheetW) $w = max(1, $sheetW - $x);
                if ($sheetH > 0 && $y + $h > $sheetH) $h = max(1, $sheetH - $y);
                if ($w < 1 || $h < 1) continue;
                $frames[] = ['i'=>count($frames), 'x'=>$x, 'y'=>$y, 'w'=>$w, 'h'=>$h];
            }
        }

        if (empty($frames)) {
            $cols = max(1, (int)floor($sheetW / $frameW));
            $rows = max(1, (int)floor($sheetH / $frameH));
            for ($r=0;$r<$rows;$r++) {
                for ($c=0;$c<$cols;$c++) {
                    $frames[] = [
                        'i'=>count($frames),
                        'x'=>$c*$frameW,
                        'y'=>$r*$frameH,
                        'w'=>$frameW,
                        'h'=>$frameH,
                    ];
                }
            }
        }

        $max = max(0, count($frames)-1);
        $animations = [];
        $rawAnims = is_array($pack['animations'] ?? null) ? $pack['animations'] : [];
        foreach ($rawAnims as $name => $seq) {
            $k = sanitize_key((string)$name);
            if ($k === '' || !is_array($seq)) continue;
            $clean = [];
            foreach ($seq as $v) {
                $iv = (int)$v;
                if ($iv >= 0 && $iv <= $max) $clean[] = $iv;
            }
            $clean = array_values(array_unique($clean));
            if (!empty($clean)) $animations[$k] = $clean;
        }
        if (empty($animations)) {
            $idle=[]; for($i=0;$i<min(4,count($frames));$i++) $idle[]=$i;
            $animations=['idle'=>!empty($idle)?$idle:[0]];
        }

        return [
            'source' => (string)($pack['source'] ?? 'user'),
            'imageUrl' => $img,
            'sheetW' => $sheetW,
            'sheetH' => $sheetH,
            'frameW' => $frameW,
            'frameH' => $frameH,
            'columns' => max(1, (int)floor($sheetW / $frameW)),
            'rows' => max(1, (int)floor($sheetH / $frameH)),
            'fps' => $fps,
            'frames' => $frames,
            'animations' => $animations,
            'metaFormat' => (string)($pack['metaFormat'] ?? 'grid'),
        ];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1', '/pet/sprite-reset', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(){
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            delete_user_meta($uid, 'prismtek_pet_sprite_pack');
            $pack = prismtek_sprite_pack_force_clean(prismtek_pet_get_sprite_pack($uid));
            prismtek_pet_set_sprite_pack($uid, $pack);
            return rest_ensure_response(['ok'=>true,'pack'=>$pack]);
        }
    ]);

    // Override sprite-pack endpoint with force-clean output
    register_rest_route('prismtek/v1', '/pet/sprite-pack', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(){
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $pack = prismtek_sprite_pack_force_clean(prismtek_pet_get_sprite_pack($uid));
            prismtek_pet_set_sprite_pack($uid, $pack);
            return rest_ensure_response(['ok'=>true,'pack'=>$pack]);
        }
    ]);
});

add_action('init', function(){
    remove_shortcode('prism_creatures_portal');
    add_shortcode('prism_creatures_portal', function(){
        $logged = is_user_logged_in();
        $nonce = $logged ? wp_create_nonce('wp_rest') : '';
        $api = esc_url_raw(rest_url('prismtek/v1/'));
        ob_start(); ?>
        <section class="pph-wrap" style="margin-top:0;gap:14px;">
          <article class="pph-card creature-hero">
            <h3>Prism Creatures</h3>
            <p>Raise, train, and evolve your partner creature.</p>
          </article>

          <?php if(!$logged): ?>
            <article class="pph-card"><p>Log in to unlock creature care, training, and custom sprite animations.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
          <?php else: ?>
            <article class="pph-card creature-main" id="pph-pet-panel">
              <div class="creature-layout">
                <div class="creature-canvas-wrap">
                  <canvas id="pph-pet-canvas" width="160" height="160"></canvas>
                  <div class="creature-canvas-label">LIVE ANIMATION</div>
                </div>
                <div class="creature-info">
                  <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
                  <div id="pph-pet-bars"></div>
                </div>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
                <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
                <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
              </div>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button type="button" id="pph-pet-adopt">Save Creature Type</button><button type="button" id="pph-pet-train">Train (+XP)</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button type="button" id="pph-pet-feed">Feed</button><button type="button" id="pph-pet-play">Play</button><button type="button" id="pph-pet-rest">Rest</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button type="button" id="pph-pet-rename">Save Name</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"><option value="default">default</option></select><button type="button" id="pph-pet-skin-save">Apply Skin</button></div>
              <div class="pph-tool-row" style="grid-template-columns:1fr;"><button type="button" id="pph-pet-spar">Spar Battle</button></div>

              <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
              <h4 style="margin:4px 0">Sprite Studio</h4>
              <form id="pph-sprite-upload-form" class="pph-form" enctype="multipart/form-data">
                <input type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required />
                <input type="file" name="meta" accept="application/json,.json" />
                <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><input type="number" name="frameW" min="8" max="1024" placeholder="Frame W" /><input type="number" name="frameH" min="8" max="1024" placeholder="Frame H" /><input type="number" name="fps" min="1" max="24" placeholder="FPS" /></div>
                <select name="format"><option value="grid">Grid</option><option value="aseprite">Aseprite JSON</option><option value="texturepacker">TexturePacker JSON</option><option value="custom">Custom JSON</option></select>
                <button type="submit">Upload Sprite Sheet</button>
              </form>

              <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
                <button type="button" id="pph-sprite-default">Use Default T-Rex</button>
                <button type="button" id="pph-sprite-reset">Reset My Visuals</button>
                <select id="pph-sprite-animation"></select>
              </div>

              <p id="pph-pet-status" class="pph-status"></p>
            </article>
          <?php endif; ?>

          <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
        </section>
        <style>
          .creature-hero h3{margin:0 0 6px}
          .creature-layout{display:grid;grid-template-columns:176px 1fr;gap:14px;align-items:start}
          .creature-canvas-wrap{display:grid;gap:8px;justify-items:center}
          #pph-pet-canvas{width:160px;height:160px;border:2px solid #6b74c7;background:radial-gradient(circle at 50% 30%,#1d2b57,#0e1026 70%);image-rendering:pixelated;display:block}
          .creature-canvas-label{font-size:10px;opacity:.9;color:#d6dcff}
          .creature-info{display:grid;gap:8px}
          .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px}
          .pph-bar{height:8px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}
          .pph-bar > span{display:block;height:100%}
          @media (max-width:760px){.creature-layout{grid-template-columns:1fr}}
        </style>
        <script>
        (()=>{
          const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
          const petView=document.getElementById('pph-pet-view'); const canvas=document.getElementById('pph-pet-canvas');
          if(!petView || !canvas) return;
          const ctx=canvas.getContext('2d'); ctx.imageSmoothingEnabled=false;
          const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
          const nameEl=document.getElementById('pph-pet-name'); const skinEl=document.getElementById('pph-pet-skin');
          const speciesEl=document.getElementById('pph-pet-species'); const persEl=document.getElementById('pph-pet-personality');
          const animEl=document.getElementById('pph-sprite-animation'); const uploadForm=document.getElementById('pph-sprite-upload-form');

          let pack=null,img=null,pet=null,timer=null,anim='idle',cursor=0;
          const allowedSpecies=['sprout','ember','tidal','volt','shade'];
          const allowedPers=['brave','curious','calm','chaotic'];

          const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
          const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
          function setStatus(t){ if(statusEl) statusEl.textContent=t||''; }

          function normalizePet(raw){
            if(!raw||typeof raw!=='object') return null;
            const p={...raw};
            p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default');
            p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout';
            p.personality=toStr(p.personality,'brave'); if(!allowedPers.includes(p.personality)) p.personality='brave';
            p.form=toStr(p.form,`${p.species}-${p.personality}-cub`);
            ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0));
            return p;
          }

          function safePack(inPack){
            if(!inPack||typeof inPack!=='object') return null;
            const p={...inPack};
            p.imageUrl=toStr(p.imageUrl,''); p.sheetW=Math.max(1,Number(p.sheetW||0)); p.sheetH=Math.max(1,Number(p.sheetH||0));
            p.frameW=Math.max(1,Number(p.frameW||32)); p.frameH=Math.max(1,Number(p.frameH||32)); p.fps=clamp(Number(p.fps||10),1,24);

            if(p.imageUrl.includes('trex-sheet.png')){
              p.sheetW=384; p.sheetH=320; p.frameW=96; p.frameH=80; p.fps=8;
              p.frames=[]; let i=0; for(let r=0;r<4;r++)for(let c=0;c<4;c++)p.frames.push({i:i++,x:c*96,y:r*80,w:96,h:80});
              p.animations={idle:[0,1,2,3],walk:[4,5,6,7],run:[8,9,10,11],attack:[12,13,14,15]};
              return p;
            }

            const frames=Array.isArray(p.frames)?p.frames:[];
            const cleanFrames=[];
            for(const fr of frames){
              if(!fr||typeof fr!=='object') continue;
              let x=Math.max(0,Number(fr.x||0)), y=Math.max(0,Number(fr.y||0)), w=Math.max(1,Number(fr.w||p.frameW)), h=Math.max(1,Number(fr.h||p.frameH));
              if(x+w>p.sheetW) w=Math.max(1,p.sheetW-x); if(y+h>p.sheetH) h=Math.max(1,p.sheetH-y);
              cleanFrames.push({i:cleanFrames.length,x,y,w,h});
            }
            if(!cleanFrames.length){
              const cols=Math.max(1,Math.floor(p.sheetW/p.frameW)), rows=Math.max(1,Math.floor(p.sheetH/p.frameH));
              for(let r=0;r<rows;r++)for(let c=0;c<cols;c++)cleanFrames.push({i:cleanFrames.length,x:c*p.frameW,y:r*p.frameH,w:p.frameW,h:p.frameH});
            }
            p.frames=cleanFrames;

            const max=cleanFrames.length-1;
            const rawAnims=(p.animations&&typeof p.animations==='object')?p.animations:{};
            const cleanAnims={};
            for(const [k,v] of Object.entries(rawAnims)){
              if(!Array.isArray(v)) continue;
              const seq=[...new Set(v.map(n=>Number(n)).filter(n=>Number.isFinite(n)&&n>=0&&n<=max))];
              if(seq.length) cleanAnims[String(k)] = seq;
            }
            if(!Object.keys(cleanAnims).length){ cleanAnims.idle = [0,1,2,3].filter(i=>i<=max); if(!cleanAnims.idle.length) cleanAnims.idle=[0]; }
            p.animations=cleanAnims;
            return p;
          }

          function drawFallback(){
            ctx.clearRect(0,0,160,160);
            ctx.fillStyle='#1a2750'; ctx.fillRect(0,0,160,160);
            ctx.fillStyle='#59d9ff'; ctx.fillRect(50,50,60,60);
            ctx.fillStyle='#0e1026'; ctx.fillRect(66,68,8,8); ctx.fillRect(86,68,8,8);
          }

          function fitDraw(im,sx,sy,sw,sh){
            ctx.clearRect(0,0,160,160);
            const scale=Math.min(140/sw,140/sh);
            const dw=Math.max(1,Math.floor(sw*scale)), dh=Math.max(1,Math.floor(sh*scale));
            const dx=Math.floor((160-dw)/2), dy=Math.floor((160-dh)/2);
            try{ ctx.drawImage(im,sx,sy,sw,sh,dx,dy,dw,dh);}catch{drawFallback();}
          }

          function frameBy(i){ return pack?.frames?.find(f=>Number(f.i)===Number(i)) || null; }
          function refreshAnimSelect(){
            const names=pack&&pack.animations?Object.keys(pack.animations):['idle'];
            animEl.innerHTML=names.map(n=>`<option value="${n}">${n}</option>`).join('');
            if(!names.includes(anim)) anim=names[0]||'idle'; animEl.value=anim;
          }

          function startAnim(){
            if(timer){clearInterval(timer); timer=null;}
            if(!pack||!img){drawFallback(); return;}
            const seq=(pack.animations&&pack.animations[anim])?pack.animations[anim]:(pack.animations?.idle||[0]);
            if(!Array.isArray(seq)||!seq.length){drawFallback(); return;}
            cursor=0;
            const tick=()=>{ const idx=Number(seq[cursor%seq.length]||0); cursor++; const fr=frameBy(idx); if(!fr){drawFallback(); return;} fitDraw(img,Number(fr.x),Number(fr.y),Math.max(1,Number(fr.w)),Math.max(1,Number(fr.h))); };
            tick();
            timer=setInterval(tick, Math.round(1000/clamp(Number(pack.fps||8),1,24)));
          }

          function renderBars(p){
            const bar=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div class="pph-bar"><span style="width:${clamp(v,0,100)}%;background:${c}"></span></div>`;
            bars.innerHTML=bar('Health',p.health,'#5de28f')+bar('Energy',p.energy,'#59d9ff')+bar('Happiness',p.happiness,'#f8c062')+bar('Hunger',p.hunger,'#d98fff');
          }

          function renderPet(raw){
            const p=normalizePet(raw); if(!p) return; pet=p;
            renderBars(p);
            petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`;
            if(!nameEl.value) nameEl.value=p.name;
            speciesEl.value=p.species; persEl.value=p.personality;
            const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
            skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join('');
          }

          async function loadImg(url){ return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=url+(url.includes('?')?'&':'?')+'v='+Date.now(); }); }
          async function post(path,payload,form){ const o={method:'POST',credentials:'include',headers:{'X-WP-Nonce':nonce}}; if(form){o.body=form;} else {o.headers['content-type']='application/json'; o.body=JSON.stringify(payload||{});} const r=await fetch(API+path,o); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }

          async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){petView.textContent='Log in to care for your creature.'; return;} const j=await r.json(); renderPet(j.pet||null); }
          async function loadPack(){
            const r=await fetch(API+'pet/sprite-pack?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}});
            if(!r.ok){ drawFallback(); return; }
            const j=await r.json(); pack=safePack(j.pack||null); refreshAnimSelect();
            if(pack?.imageUrl){ try{ img=await loadImg(pack.imageUrl);}catch{ img=null; } } else img=null;
            startAnim();
          }

          async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.'); return;} renderPet(out.data.pet||null); setStatus('Done.'); }
          document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
          document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
          document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
          document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
          document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
          document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Saving creature type...'); const out=await post('pet/adopt',{species:speciesEl.value||'sprout',personality:persEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.'); return;} renderPet(out.data.pet||null); setStatus('Creature type updated.'); });
          document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.'); return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
          document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.'); return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });

          uploadForm?.addEventListener('submit', async (e)=>{ e.preventDefault(); setStatus('Uploading sprite sheet...'); const fd=new FormData(uploadForm); const out=await post('pet/sprite-upload',null,fd); if(!out.ok){setStatus(out.data?.error||'Upload failed.'); return;} pack=safePack(out.data.pack||null); refreshAnimSelect(); if(pack?.imageUrl){ try{img=await loadImg(pack.imageUrl);}catch{img=null;} } startAnim(); setStatus('Sprite sheet uploaded.'); });
          document.getElementById('pph-sprite-default')?.addEventListener('click', async ()=>{ setStatus('Applying default T-Rex...'); const out=await post('pet/sprite-use-default',{}); if(!out.ok){setStatus(out.data?.error||'Failed.'); return;} pack=safePack(out.data.pack||null); refreshAnimSelect(); if(pack?.imageUrl){ try{img=await loadImg(pack.imageUrl);}catch{img=null;} } startAnim(); setStatus('Default T-Rex applied.'); });
          document.getElementById('pph-sprite-reset')?.addEventListener('click', async ()=>{ setStatus('Resetting visuals...'); const out=await post('pet/sprite-reset',{}); if(!out.ok){setStatus(out.data?.error||'Failed.'); return;} pack=safePack(out.data.pack||null); refreshAnimSelect(); if(pack?.imageUrl){ try{img=await loadImg(pack.imageUrl);}catch{img=null;} } startAnim(); setStatus('Visuals reset to stable defaults.'); });
          animEl?.addEventListener('change',()=>{anim=animEl.value||'idle'; startAnim();});

          loadPet().catch(()=>{}); loadPack().catch(()=>{});
        })();
        </script>
        <?php return ob_get_clean();
    });
}, 9999);

// ===== Prism Creatures isolated V2 pipeline (avoids legacy route conflicts) =====
if (!function_exists('prismtek_creature_pack_default_v2')) {
  function prismtek_creature_pack_default_v2() {
    $url = content_url('uploads/prismtek-creatures/trex-sheet.png');
    $frames=[]; $i=0;
    for($r=0;$r<4;$r++) for($c=0;$c<4;$c++) $frames[]=['i'=>$i++,'x'=>$c*96,'y'=>$r*80,'w'=>96,'h'=>80];
    return [
      'version'=>2,
      'imageUrl'=>esc_url_raw($url),
      'sheetW'=>384,'sheetH'=>320,
      'frameW'=>96,'frameH'=>80,
      'fps'=>8,
      'frames'=>$frames,
      'animations'=>[
        'idle'=>[0,1,2,3],
        'walk'=>[4,5,6,7],
        'run'=>[8,9,10,11],
        'attack'=>[12,13,14,15],
      ],
    ];
  }

  function prismtek_creature_pack_sanitize_v2($pack) {
    if (!is_array($pack)) $pack = [];
    $img = esc_url_raw((string)($pack['imageUrl'] ?? ''));
    if ($img === '') return prismtek_creature_pack_default_v2();

    // hard map known trex sheet
    if (str_contains($img, 'trex-sheet.png')) {
      $out = prismtek_creature_pack_default_v2();
      $out['imageUrl'] = $img;
      return $out;
    }

    $sheetW = max(1, (int)($pack['sheetW'] ?? 0));
    $sheetH = max(1, (int)($pack['sheetH'] ?? 0));
    $frameW = max(1, (int)($pack['frameW'] ?? 32));
    $frameH = max(1, (int)($pack['frameH'] ?? 32));
    $fps = max(1, min(20, (int)($pack['fps'] ?? 10)));

    $frames=[];
    $rawFrames = is_array($pack['frames'] ?? null) ? $pack['frames'] : [];
    foreach($rawFrames as $fr){
      if(!is_array($fr)) continue;
      $x=max(0,(int)($fr['x']??0)); $y=max(0,(int)($fr['y']??0));
      $w=max(1,(int)($fr['w']??$frameW)); $h=max(1,(int)($fr['h']??$frameH));
      if($x+$w>$sheetW) $w=max(1,$sheetW-$x);
      if($y+$h>$sheetH) $h=max(1,$sheetH-$y);
      if($w<1||$h<1) continue;
      $frames[]=['i'=>count($frames),'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h];
    }
    if(empty($frames)){
      $cols=max(1,(int)floor($sheetW/$frameW)); $rows=max(1,(int)floor($sheetH/$frameH));
      for($r=0;$r<$rows;$r++) for($c=0;$c<$cols;$c++) $frames[]=['i'=>count($frames),'x'=>$c*$frameW,'y'=>$r*$frameH,'w'=>$frameW,'h'=>$frameH];
    }

    $max=max(0,count($frames)-1);
    $anims=[];
    $rawAnims=is_array($pack['animations']??null)?$pack['animations']:[];
    foreach($rawAnims as $name=>$seq){
      $k=sanitize_key((string)$name); if($k===''||!is_array($seq)) continue;
      $clean=[];
      foreach($seq as $v){ $iv=(int)$v; if($iv>=0 && $iv<=$max) $clean[]=$iv; }
      $clean=array_values(array_unique($clean));
      if(!empty($clean)) $anims[$k]=$clean;
    }
    if(empty($anims)){
      $idle=[]; for($i=0;$i<min(4,count($frames));$i++) $idle[]=$i;
      $anims=['idle'=>!empty($idle)?$idle:[0]];
    }

    return [
      'version'=>2,
      'imageUrl'=>$img,
      'sheetW'=>$sheetW,'sheetH'=>$sheetH,
      'frameW'=>$frameW,'frameH'=>$frameH,
      'fps'=>$fps,
      'frames'=>$frames,
      'animations'=>$anims,
    ];
  }

  function prismtek_creature_pack_get_v2($uid){
    $uid=(int)$uid;
    $raw=$uid?get_user_meta($uid,'prismtek_pet_sprite_pack_v2',true):[];
    if(!is_array($raw)||empty($raw)) $raw=prismtek_creature_pack_default_v2();
    $clean=prismtek_creature_pack_sanitize_v2($raw);
    if($uid) update_user_meta($uid,'prismtek_pet_sprite_pack_v2',$clean);
    return $clean;
  }
  function prismtek_creature_pack_set_v2($uid,$pack){
    $uid=(int)$uid; if(!$uid) return;
    update_user_meta($uid,'prismtek_pet_sprite_pack_v2',prismtek_creature_pack_sanitize_v2($pack));
  }
}

add_action('rest_api_init', function(){
  register_rest_route('prismtek/v1','/pet/sprite-pack-v2',[
    'methods'=>'GET','permission_callback'=>'__return_true',
    'callback'=>function(){ $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401); return rest_ensure_response(['ok'=>true,'pack'=>prismtek_creature_pack_get_v2($uid)]);}]);

  register_rest_route('prismtek/v1','/pet/sprite-default-v2',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(){ $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401); $p=prismtek_creature_pack_default_v2(); prismtek_creature_pack_set_v2($uid,$p); return rest_ensure_response(['ok'=>true,'pack'=>prismtek_creature_pack_get_v2($uid)]);}]);

  register_rest_route('prismtek/v1','/pet/sprite-reset-v2',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(){ $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401); delete_user_meta($uid,'prismtek_pet_sprite_pack_v2'); return rest_ensure_response(['ok'=>true,'pack'=>prismtek_creature_pack_get_v2($uid)]);}]);

  register_rest_route('prismtek/v1','/pet/sprite-upload-v2',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(WP_REST_Request $request){
      $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
      if(empty($_FILES['sheet'])) return new WP_REST_Response(['ok'=>false,'error'=>'missing_sheet'],400);

      require_once ABSPATH.'wp-admin/includes/file.php';
      $over=['test_form'=>false,'mimes'=>['png'=>'image/png','jpg|jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif']];
      $up=wp_handle_upload($_FILES['sheet'],$over);
      if(!empty($up['error'])) return new WP_REST_Response(['ok'=>false,'error'=>'upload_failed','detail'=>$up['error']],400);

      $path=(string)($up['file']??''); $url=esc_url_raw((string)($up['url']??''));
      $dim=@getimagesize($path); if(!$dim) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_image'],400);

      $meta=[];
      if(!empty($_FILES['meta']) && is_uploaded_file((string)$_FILES['meta']['tmp_name'])){
        $raw=@file_get_contents((string)$_FILES['meta']['tmp_name']);
        $j=json_decode((string)$raw,true); if(is_array($j)) $meta=$j;
      }
      $rawMeta=(string)$request->get_param('metaJson');
      if($rawMeta!==''){ $j=json_decode($rawMeta,true); if(is_array($j)) $meta=$j; }

      $frameW=(int)($request->get_param('frameW') ?: ($meta['frameW'] ?? 32));
      $frameH=(int)($request->get_param('frameH') ?: ($meta['frameH'] ?? 32));
      $fps=(int)($request->get_param('fps') ?: ($meta['fps'] ?? 10));

      $pack=['imageUrl'=>$url,'sheetW'=>(int)$dim[0],'sheetH'=>(int)$dim[1],'frameW'=>$frameW,'frameH'=>$frameH,'fps'=>$fps,'frames'=>$meta['frames'] ?? [],'animations'=>$meta['animations'] ?? []];
      prismtek_creature_pack_set_v2($uid,$pack);
      return rest_ensure_response(['ok'=>true,'pack'=>prismtek_creature_pack_get_v2($uid)]);
    }
  ]);
});

add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in(); $nonce=$logged?wp_create_nonce('wp_rest'):''; $api=esc_url_raw(rest_url('prismtek/v1/'));
    ob_start(); ?>
    <section class="pph-wrap creature-v2" style="margin-top:0;gap:14px;">
      <?php if(!$logged): ?>
      <article class="pph-card"><h3>Prism Creatures</h3><p>Log in to raise, train, and animate your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
      <?php else: ?>
      <article class="pph-card creature-shell">
        <div class="creature-top">
          <div class="creature-stage">
            <canvas id="pph-pet-canvas" width="192" height="192"></canvas>
            <div class="creature-stage-label">PARTNER LINK</div>
          </div>
          <div class="creature-panel">
            <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <h4 style="margin:0 0 8px">Sprite Studio (Stable v2)</h4>
        <form id="pph-sprite-upload-form" class="pph-form" enctype="multipart/form-data">
          <input type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required />
          <input type="file" name="meta" accept="application/json,.json" />
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><input type="number" name="frameW" min="8" max="1024" placeholder="Frame W" /><input type="number" name="frameH" min="8" max="1024" placeholder="Frame H" /><input type="number" name="fps" min="1" max="20" placeholder="FPS" /></div>
          <button type="submit">Upload Sheet</button>
        </form>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-sprite-default" type="button">Use Default T-Rex</button><button id="pph-sprite-reset" type="button">Reset My Visuals</button><select id="pph-sprite-animation"></select></div>
        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>
      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <style>
      .creature-shell{background:linear-gradient(180deg,rgba(24,29,70,.95),rgba(15,18,46,.95));}
      .creature-top{display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start;margin-bottom:8px}
      .creature-stage{display:grid;gap:8px;justify-items:center}
      #pph-pet-canvas{width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block}
      .creature-stage-label{font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9}
      .creature-panel{display:grid;gap:8px}
      .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px}
      .pph-bar{height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}
      .pph-bar>span{display:block;height:100%}
      @media (max-width:840px){.creature-top{grid-template-columns:1fr}}
    </style>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
      const petView=document.getElementById('pph-pet-view'); const c=document.getElementById('pph-pet-canvas'); if(!petView||!c) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality'), animEl=document.getElementById('pph-sprite-animation');
      const uploadForm=document.getElementById('pph-sprite-upload-form');

      let pet=null, pack=null, img=null, raf=0, last=0, cursor=0, anim='idle';
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];
      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      function setStatus(t){ if(statusEl) statusEl.textContent=t||''; }

      function normalizePet(raw){
        if(!raw||typeof raw!=='object') return null;
        const p={...raw}; p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default');
        p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout';
        p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave';
        p.form=toStr(p.form,`${p.species}-${p.personality}-cub`);
        ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0));
        return p;
      }

      function drawFallback(){
        x.clearRect(0,0,192,192);
        x.fillStyle='#1a2750'; x.fillRect(0,0,192,192);
        x.fillStyle='#59d9ff'; x.fillRect(64,64,64,64);
        x.fillStyle='#0e1026'; x.fillRect(82,86,8,8); x.fillRect(102,86,8,8);
      }

      function frame(i){ return pack?.frames?.find(f=>Number(f.i)===Number(i)) || null; }
      function fitDraw(im,fr){
        const sw=Math.max(1,Number(fr.w||1)), sh=Math.max(1,Number(fr.h||1));
        const scale=Math.min(168/sw,168/sh); const dw=Math.max(1,Math.floor(sw*scale)), dh=Math.max(1,Math.floor(sh*scale));
        const dx=Math.floor((192-dw)/2), dy=Math.floor((192-dh)/2);
        x.clearRect(0,0,192,192);
        try{x.drawImage(im,Number(fr.x||0),Number(fr.y||0),sw,sh,dx,dy,dw,dh);}catch{drawFallback();}
      }

      function renderBars(p){
        const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div class="pph-bar"><span style="width:${clamp(v,0,100)}%;background:${c}"></span></div>`;
        bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff');
      }

      function renderPet(raw){
        const p=normalizePet(raw); if(!p) return; pet=p;
        petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`;
        renderBars(p);
        if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality;
        const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
        skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join('');
      }

      function refreshAnimOptions(){
        const names=pack&&pack.animations?Object.keys(pack.animations):['idle'];
        animEl.innerHTML=names.map(n=>`<option value="${n}">${n}</option>`).join('');
        if(!names.includes(anim)) anim=names[0]||'idle'; animEl.value=anim;
      }

      function stopLoop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){
        if(!img||!pack){ drawFallback(); return; }
        const fps=clamp(Number(pack.fps||8),1,20), frameMs=1000/fps;
        if(!last || ts-last>=frameMs){
          last=ts;
          const seq=(pack.animations&&pack.animations[anim])?pack.animations[anim]:(pack.animations?.idle||[0]);
          const idx=Number(seq[cursor%seq.length]||0); cursor++;
          const fr=frame(idx); if(fr) fitDraw(img,fr); else drawFallback();
        }
        raf=requestAnimationFrame(loop);
      }
      function startLoop(){ stopLoop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }

      async function loadImage(url){ return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=url+(url.includes('?')?'&':'?')+'v='+Date.now(); }); }
      async function post(path,payload,form){ const o={method:'POST',credentials:'include',headers:{'X-WP-Nonce':nonce}}; if(form){o.body=form;} else {o.headers['content-type']='application/json'; o.body=JSON.stringify(payload||{});} const r=await fetch(API+path,o); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }

      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){petView.textContent='Log in to care for your creature.';return;} const j=await r.json(); renderPet(j.pet||null); }
      async function loadPack(){ const r=await fetch(API+'pet/sprite-pack-v2?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){drawFallback();return;} const j=await r.json(); pack=j.pack||null; refreshAnimOptions(); if(pack?.imageUrl){ try{img=await loadImage(pack.imageUrl);}catch{img=null;} } if(!img){drawFallback(); return;} startLoop(); }

      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }
      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });

      uploadForm?.addEventListener('submit', async (e)=>{ e.preventDefault(); setStatus('Uploading...'); const fd=new FormData(uploadForm); const out=await post('pet/sprite-upload-v2',null,fd); if(!out.ok){setStatus(out.data?.error||'Upload failed.');return;} pack=out.data.pack||null; refreshAnimOptions(); if(pack?.imageUrl){ try{img=await loadImage(pack.imageUrl);}catch{img=null;} } if(!img){drawFallback();} else startLoop(); setStatus('Sprite uploaded.'); });
      document.getElementById('pph-sprite-default')?.addEventListener('click', async ()=>{ setStatus('Applying default...'); const out=await post('pet/sprite-default-v2',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} pack=out.data.pack||null; refreshAnimOptions(); if(pack?.imageUrl){ try{img=await loadImage(pack.imageUrl);}catch{img=null;} } if(!img){drawFallback();} else startLoop(); setStatus('Default T-Rex applied.'); });
      document.getElementById('pph-sprite-reset')?.addEventListener('click', async ()=>{ setStatus('Resetting visuals...'); const out=await post('pet/sprite-reset-v2',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} pack=out.data.pack||null; refreshAnimOptions(); if(pack?.imageUrl){ try{img=await loadImage(pack.imageUrl);}catch{img=null;} } if(!img){drawFallback();} else startLoop(); setStatus('Visuals reset to stable defaults.'); });
      animEl?.addEventListener('change',()=>{anim=animEl.value||'idle'; cursor=0;});

      loadPet().catch(()=>{}); loadPack().catch(()=>{});
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 10000);

// ===== Prism Creatures STABLE MODE (force-known-good animation) =====
add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in();
    $nonce=$logged?wp_create_nonce('wp_rest'):'';
    $api=esc_url_raw(rest_url('prismtek/v1/'));
    $sheet=esc_url_raw(content_url('uploads/prismtek-creatures/trex-sheet.png'));
    ob_start(); ?>
    <section class="pph-wrap creature-stable" style="margin-top:0;gap:14px;">
      <article class="pph-card creature-hero"><h3>Prism Creatures</h3><p>Stable partner mode enabled — animation reliability first.</p></article>

      <?php if(!$logged): ?>
        <article class="pph-card"><p>Log in to raise, train, and battle your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
      <?php else: ?>
      <article class="pph-card creature-shell">
        <div class="creature-top">
          <div class="creature-stage">
            <canvas id="pph-pet-canvas" width="192" height="192"></canvas>
            <div class="creature-stage-label">PARTNER LINK · STABLE</div>
          </div>
          <div class="creature-panel">
            <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Animation<select id="pph-sprite-animation"><option value="idle">idle</option><option value="walk">walk</option><option value="run">run</option><option value="attack">attack</option></select></label>
          <button id="pph-sprite-reload" type="button">Reload Stable Sprite</button>
        </div>

        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>

      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <style>
      .creature-shell{background:linear-gradient(180deg,rgba(24,29,70,.95),rgba(15,18,46,.95));}
      .creature-top{display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start}
      .creature-stage{display:grid;gap:8px;justify-items:center}
      #pph-pet-canvas{width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block}
      .creature-stage-label{font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9}
      .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px}
      .pph-bar{height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}.pph-bar>span{display:block;height:100%}
      @media (max-width:840px){.creature-top{grid-template-columns:1fr}}
    </style>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
      const SHEET='<?php echo esc_js($sheet); ?>';
      const c=document.getElementById('pph-pet-canvas'); const petView=document.getElementById('pph-pet-view'); if(!c||!petView) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality');
      const animEl=document.getElementById('pph-sprite-animation');
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];

      let pet=null, img=null, raf=0, last=0, cursor=0, anim='idle';
      const frames=[]; let i=0; for(let r=0;r<4;r++)for(let c0=0;c0<4;c0++)frames.push({i:i++,x:c0*96,y:r*80,w:96,h:80});
      const anims={idle:[0,1,2,3],walk:[4,5,6,7],run:[8,9,10,11],attack:[12,13,14,15]};

      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      function setStatus(t){ if(statusEl) statusEl.textContent=t||''; }

      function drawFallback(){ x.clearRect(0,0,192,192); x.fillStyle='#1a2750'; x.fillRect(0,0,192,192); x.fillStyle='#59d9ff'; x.fillRect(64,64,64,64); x.fillStyle='#0e1026'; x.fillRect(82,86,8,8); x.fillRect(102,86,8,8); }
      function fitDraw(fr){
        const sw=fr.w, sh=fr.h; const scale=Math.min(168/sw,168/sh); const dw=Math.floor(sw*scale), dh=Math.floor(sh*scale);
        const dx=Math.floor((192-dw)/2), dy=Math.floor((192-dh)/2);
        x.clearRect(0,0,192,192); try{x.drawImage(img,fr.x,fr.y,fr.w,fr.h,dx,dy,dw,dh);}catch{drawFallback();}
      }

      function normalizePet(raw){
        if(!raw||typeof raw!=='object') return null; const p={...raw};
        p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default');
        p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout';
        p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave';
        p.form=toStr(p.form,`${p.species}-${p.personality}-cub`);
        ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0));
        return p;
      }

      function renderBars(p){
        const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div class="pph-bar"><span style="width:${clamp(v,0,100)}%;background:${c}"></span></div>`;
        bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff');
      }

      function renderPet(raw){
        const p=normalizePet(raw); if(!p) return; pet=p;
        petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`;
        renderBars(p);
        if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality;
        const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default'];
        skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join('');
      }

      function stop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){
        if(!img){ drawFallback(); return; }
        if(!last || ts-last>=125){ // ~8fps stable
          last=ts;
          const seq=anims[anim]||anims.idle; const idx=seq[cursor%seq.length]; cursor++;
          const fr=frames[idx]||frames[0]; fitDraw(fr);
        }
        raf=requestAnimationFrame(loop);
      }
      function start(){ stop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }

      async function loadImage(){
        return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=SHEET+'?v='+Date.now(); });
      }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }
      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }

      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });

      animEl?.addEventListener('change',()=>{ anim=animEl.value||'idle'; cursor=0; });
      document.getElementById('pph-sprite-reload')?.addEventListener('click', async ()=>{ setStatus('Reloading stable sprite...'); try{img=await loadImage(); start(); setStatus('Stable sprite loaded.');}catch{drawFallback(); setStatus('Sprite load failed.');} });

      loadPet().catch(()=>{});
      loadImage().then(im=>{img=im; start(); setStatus('Stable animation loaded.');}).catch(()=>{drawFallback(); setStatus('Sprite load failed.');});
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 20000);

// ===== Prism Creatures STABLE MODE v2 (new user sheet mapping) =====
add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in();
    $nonce=$logged?wp_create_nonce('wp_rest'):'';
    $api=esc_url_raw(rest_url('prismtek/v1/'));
    $sheet=esc_url_raw(content_url('uploads/prismtek-creatures/stable-sheet.jpg'));
    ob_start(); ?>
    <section class="pph-wrap creature-stable" style="margin-top:0;gap:14px;">
      <article class="pph-card creature-hero"><h3>Prism Creatures</h3><p>Stable partner mode · new sheet profile loaded.</p></article>

      <?php if(!$logged): ?>
        <article class="pph-card"><p>Log in to raise, train, and battle your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a> · <a href="<?php echo esc_url(wp_registration_url()); ?>">Create Account</a></p></article>
      <?php else: ?>
      <article class="pph-card creature-shell">
        <div class="creature-top">
          <div class="creature-stage">
            <canvas id="pph-pet-canvas" width="192" height="192"></canvas>
            <div class="creature-stage-label">PARTNER LINK · STABLE v2</div>
          </div>
          <div class="creature-panel">
            <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Animation<select id="pph-sprite-animation"><option value="idle">idle</option><option value="walk">walk</option><option value="run">run</option><option value="attack">attack</option></select></label>
          <button id="pph-sprite-reload" type="button">Reload Stable Sprite</button>
        </div>

        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>

      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <style>
      .creature-shell{background:linear-gradient(180deg,rgba(24,29,70,.95),rgba(15,18,46,.95));}
      .creature-top{display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start}
      .creature-stage{display:grid;gap:8px;justify-items:center}
      #pph-pet-canvas{width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block}
      .creature-stage-label{font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9}
      .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px}
      .pph-bar{height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}.pph-bar>span{display:block;height:100%}
      @media (max-width:840px){.creature-top{grid-template-columns:1fr}}
    </style>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
      const SHEET='<?php echo esc_js($sheet); ?>';
      const c=document.getElementById('pph-pet-canvas'); const petView=document.getElementById('pph-pet-view'); if(!c||!petView) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality');
      const animEl=document.getElementById('pph-sprite-animation');
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];

      let pet=null, img=null, raf=0, last=0, cursor=0, anim='idle';
      const frameW=128, frameH=128, cols=12, rows=8;
      const frames=[]; let i=0; for(let r=0;r<rows;r++) for(let c0=0;c0<cols;c0++) frames.push({i:i++,x:c0*frameW,y:r*frameH,w:frameW,h:frameH});
      const anims={
        idle:[0,1,2,3,4,5],
        walk:[12,13,14,15,16,17],
        run:[24,25,26,27,28,29],
        attack:[36,37,38,39,40,41]
      };

      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      function setStatus(t){ if(statusEl) statusEl.textContent=t||''; }

      function drawFallback(){ x.clearRect(0,0,192,192); x.fillStyle='#1a2750'; x.fillRect(0,0,192,192); x.fillStyle='#59d9ff'; x.fillRect(64,64,64,64); x.fillStyle='#0e1026'; x.fillRect(82,86,8,8); x.fillRect(102,86,8,8); }
      function fitDraw(fr){ const sw=fr.w, sh=fr.h; const scale=Math.min(168/sw,168/sh); const dw=Math.floor(sw*scale), dh=Math.floor(sh*scale); const dx=Math.floor((192-dw)/2), dy=Math.floor((192-dh)/2); x.clearRect(0,0,192,192); try{x.drawImage(img,fr.x,fr.y,fr.w,fr.h,dx,dy,dw,dh);}catch{drawFallback();} }

      function normalizePet(raw){ if(!raw||typeof raw!=='object') return null; const p={...raw}; p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default'); p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout'; p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave'; p.form=toStr(p.form,`${p.species}-${p.personality}-cub`); ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0)); return p; }
      function renderBars(p){ const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div class="pph-bar"><span style="width:${clamp(v,0,100)}%;background:${c}"></span></div>`; bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff'); }
      function renderPet(raw){ const p=normalizePet(raw); if(!p) return; pet=p; petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`; renderBars(p); if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality; const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default']; skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join(''); }

      function stop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){ if(!img){drawFallback();return;} if(!last||ts-last>=120){ last=ts; const seq=anims[anim]||anims.idle; const idx=seq[cursor%seq.length]; cursor++; const fr=frames[idx]||frames[0]; fitDraw(fr);} raf=requestAnimationFrame(loop); }
      function start(){ stop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }

      async function loadImage(){ return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=SHEET+'?v='+Date.now(); }); }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }
      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }

      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });
      animEl?.addEventListener('change',()=>{ anim=animEl.value||'idle'; cursor=0; });
      document.getElementById('pph-sprite-reload')?.addEventListener('click', async ()=>{ setStatus('Reloading stable sprite...'); try{img=await loadImage(); start(); setStatus('Stable sprite loaded.');}catch{drawFallback(); setStatus('Sprite load failed.');} });

      loadPet().catch(()=>{});
      loadImage().then(im=>{ img=im; start(); setStatus('Stable animation loaded.'); }).catch(()=>{ drawFallback(); setStatus('Sprite load failed.'); });
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 30000);

// ===== Emergency rollback to transparent stable sheet =====
add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in();
    $nonce=$logged?wp_create_nonce('wp_rest'):'';
    $api=esc_url_raw(rest_url('prismtek/v1/'));
    $sheet=esc_url_raw(content_url('uploads/prismtek-creatures/trex-sheet.png'));
    ob_start(); ?>
    <section class="pph-wrap" style="margin-top:0;gap:14px;">
      <article class="pph-card"><h3>Prism Creatures</h3><p>Stable transparent mode restored.</p></article>
      <?php if(!$logged): ?>
        <article class="pph-card"><p>Log in to raise and train your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a></p></article>
      <?php else: ?>
      <article class="pph-card">
        <div style="display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start" class="creature-top">
          <div style="display:grid;gap:8px;justify-items:center">
            <canvas id="pph-pet-canvas" width="192" height="192" style="width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block"></canvas>
            <div style="font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9">PARTNER LINK · RESTORED</div>
          </div>
          <div>
            <div id="pph-pet-view" class="pph-pet-view" style="padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Animation<select id="pph-sprite-animation"><option value="idle">idle</option><option value="walk">walk</option><option value="run">run</option><option value="attack">attack</option></select></label>
          <button id="pph-sprite-reload" type="button">Reload Stable Sprite</button>
        </div>
        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>
      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>'; const SHEET='<?php echo esc_js($sheet); ?>';
      const c=document.getElementById('pph-pet-canvas'); const petView=document.getElementById('pph-pet-view'); if(!c||!petView) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality');
      const animEl=document.getElementById('pph-sprite-animation');
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];

      let img=null, raf=0, last=0, cursor=0, anim='idle';
      const frames=[]; let i=0; for(let r=0;r<4;r++) for(let c0=0;c0<4;c0++) frames.push({i:i++,x:c0*96,y:r*80,w:96,h:80});
      const anims={idle:[0,1,2,3],walk:[4,5,6,7],run:[8,9,10,11],attack:[12,13,14,15]};

      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const setStatus=(t)=>{ if(statusEl) statusEl.textContent=t||''; };

      function drawFallback(){x.clearRect(0,0,192,192);x.fillStyle='#1a2750';x.fillRect(0,0,192,192);x.fillStyle='#59d9ff';x.fillRect(64,64,64,64);}
      function fitDraw(fr){ const sw=fr.w, sh=fr.h; const scale=Math.min(168/sw,168/sh); const dw=Math.floor(sw*scale), dh=Math.floor(sh*scale); const dx=Math.floor((192-dw)/2), dy=Math.floor((192-dh)/2); x.clearRect(0,0,192,192); try{x.drawImage(img,fr.x,fr.y,fr.w,fr.h,dx,dy,dw,dh);}catch{drawFallback();} }

      function normalizePet(raw){ if(!raw||typeof raw!=='object') return null; const p={...raw}; p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default'); p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout'; p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave'; p.form=toStr(p.form,`${p.species}-${p.personality}-cub`); ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0)); return p; }
      function renderBars(p){ const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div style="height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px"><span style="display:block;height:100%;width:${clamp(v,0,100)}%;background:${c}"></span></div>`; bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff'); }
      function renderPet(raw){ const p=normalizePet(raw); if(!p) return; petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`; renderBars(p); if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality; const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default']; skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join(''); }

      function stop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){ if(!img){drawFallback();return;} if(!last||ts-last>=125){ last=ts; const seq=anims[anim]||anims.idle; const idx=seq[cursor%seq.length]; cursor++; const fr=frames[idx]||frames[0]; fitDraw(fr);} raf=requestAnimationFrame(loop); }
      function start(){ stop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }
      async function loadImage(){ return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=SHEET+'?v='+Date.now(); }); }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }
      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }

      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });
      animEl?.addEventListener('change',()=>{ anim=animEl.value||'idle'; cursor=0; });
      document.getElementById('pph-sprite-reload')?.addEventListener('click',async()=>{ setStatus('Reloading stable sprite...'); try{img=await loadImage(); start(); setStatus('Stable sprite loaded.');}catch{drawFallback(); setStatus('Sprite load failed.');} });

      loadPet().catch(()=>{});
      loadImage().then(im=>{img=im; start(); setStatus('Stable transparent animation loaded.');}).catch(()=>{drawFallback(); setStatus('Sprite load failed.');});
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 50000);

// ===== Prism Creatures self-drawn animation hotfix (sheetless, deterministic) =====
add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in();
    $nonce=$logged?wp_create_nonce('wp_rest'):'';
    $api=esc_url_raw(rest_url('prismtek/v1/'));
    ob_start(); ?>
    <section class="pph-wrap" style="margin-top:0;gap:14px;">
      <article class="pph-card"><h3>Prism Creatures</h3><p>Engine: native pixel animator (no sprite-sheet dependency).</p></article>
      <?php if(!$logged): ?>
        <article class="pph-card"><p>Log in to raise and battle your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a></p></article>
      <?php else: ?>
      <article class="pph-card">
        <div style="display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start" class="creature-top">
          <div style="display:grid;gap:8px;justify-items:center">
            <canvas id="pph-pet-canvas" width="192" height="192" style="width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block"></canvas>
            <div style="font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9">PARTNER LINK · NATIVE PIXEL</div>
          </div>
          <div>
            <div id="pph-pet-view" class="pph-pet-view" style="padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Animation<select id="pph-sprite-animation"><option value="idle">idle</option><option value="walk">walk</option><option value="run">run</option><option value="attack">attack</option></select></label>
          <button id="pph-sprite-reload" type="button">Reload Animator</button>
        </div>
        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>
      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
      const c=document.getElementById('pph-pet-canvas'); const petView=document.getElementById('pph-pet-view'); if(!c||!petView) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality');
      const animEl=document.getElementById('pph-sprite-animation');
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];

      let raf=0,last=0,cursor=0,anim='idle',pet=null;
      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const setStatus=(t)=>{ if(statusEl) statusEl.textContent=t||''; };

      function palette(skin){
        const map={default:['#00000000','#59d9ff','#b8f2ff','#0e1026'],mint:['#00000000','#77ffc4','#c8ffe8','#0e1026'],sunset:['#00000000','#ff8f66','#ffd18a','#0e1026'],galaxy:['#00000000','#8f7bff','#ff7bf2','#0e1026'],neon:['#00000000','#39ff14','#00e5ff','#0e1026']};
        return map[skin]||map.default;
      }

      // 16x16 base + motion variants
      const base=[
        '0000000000000000','0000011111100000','0001122222211000','0012223333222100',
        '0122233333332210','0122233333332210','0012223333222100','0001122222211000',
        '0000011221110000','0000111000110000','0001100000011000','0011000000001100',
        '0011000000001100','0001000000001000','0000100000010000','0000011111100000'
      ];
      const walk1=[...base]; walk1[11]='0011000000000110'; walk1[12]='0001100000011000';
      const walk2=[...base]; walk2[11]='0110000000001100'; walk2[12]='0011000000000110';
      const run1 =[...base]; run1[10]='0011000000000110'; run1[11]='0110000000001100';
      const run2 =[...base]; run2[10]='0110000000001100'; run2[11]='0011000000000110';
      const atk1 =[...base]; atk1[4]='0122233333332222'; atk1[5]='0122233333332222';
      const atk2 =[...base]; atk2[4]='2222233333332210'; atk2[5]='2222233333332210';

      const animFrames={idle:[base,walk1],walk:[walk1,base,walk2,base],run:[run1,walk1,run2,walk2],attack:[atk1,atk2,atk1,base]};

      function drawSprite(frame, skin='default'){
        const pal=palette(skin);
        x.clearRect(0,0,192,192);
        const s=10; // 16*10 = 160
        const ox=16, oy=16;
        for(let yy=0; yy<16; yy++){
          const row=(frame[yy]||'').padEnd(16,'0');
          for(let xx=0; xx<16; xx++){
            const idx=Number(row[xx])||0; const col=pal[idx]||'transparent';
            if(col!=='transparent' && col!=='#00000000'){ x.fillStyle=col; x.fillRect(ox+xx*s, oy+yy*s, s, s); }
          }
        }
      }

      function normalizePet(raw){ if(!raw||typeof raw!=='object') return null; const p={...raw}; p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default'); p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout'; p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave'; p.form=toStr(p.form,`${p.species}-${p.personality}-cub`); ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0)); return p; }
      function renderBars(p){ const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div style="height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px"><span style="display:block;height:100%;width:${clamp(v,0,100)}%;background:${c}"></span></div>`; bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff'); }
      function renderPet(raw){ const p=normalizePet(raw); if(!p) return; pet=p; petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`; renderBars(p); if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality; const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default']; skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join(''); }

      function stop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){
        const fps = anim==='run' ? 12 : (anim==='walk'?8:6);
        if(!last||ts-last>=1000/fps){
          last=ts;
          const seq=animFrames[anim]||animFrames.idle;
          const frame=seq[cursor%seq.length]; cursor++;
          drawSprite(frame, pet?.skin || 'default');
        }
        raf=requestAnimationFrame(loop);
      }
      function start(){ stop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }

      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }
      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }

      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });
      animEl?.addEventListener('change',()=>{anim=animEl.value||'idle'; cursor=0;});
      document.getElementById('pph-sprite-reload')?.addEventListener('click',()=>{start(); setStatus('Native pixel animator reloaded.');});

      loadPet().catch(()=>{});
      start();
      setStatus('Native pixel animation active.');
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 999999);

// ===== Prism Creatures UX + Uploader V3 (mobile + desktop) =====
if (!function_exists('prismtek_pet_sprite_v3_default')) {
  function prismtek_pet_sprite_v3_default(){
    return [
      'enabled'=>false,
      'imageUrl'=>'',
      'sheetW'=>0,
      'sheetH'=>0,
      'frameW'=>0,
      'frameH'=>0,
      'fps'=>10,
      'animations'=>[
        'idle'=>[0,1,2,3],
        'walk'=>[4,5,6,7],
        'run'=>[8,9,10,11],
        'attack'=>[12,13,14,15],
      ],
    ];
  }

  function prismtek_pet_sprite_v3_get($uid){
    $uid=(int)$uid;
    $raw = $uid ? get_user_meta($uid,'prismtek_pet_sprite_v3',true) : [];
    if(!is_array($raw)) $raw=[];
    return array_merge(prismtek_pet_sprite_v3_default(), $raw);
  }

  function prismtek_pet_sprite_v3_sanitize($cfg){
    $d = prismtek_pet_sprite_v3_default();
    if(!is_array($cfg)) return $d;
    $out = array_merge($d, $cfg);
    $out['enabled'] = !empty($out['enabled']);
    $out['imageUrl'] = esc_url_raw((string)($out['imageUrl'] ?? ''));
    $out['sheetW'] = max(0,(int)($out['sheetW'] ?? 0));
    $out['sheetH'] = max(0,(int)($out['sheetH'] ?? 0));
    $out['frameW'] = max(0,(int)($out['frameW'] ?? 0));
    $out['frameH'] = max(0,(int)($out['frameH'] ?? 0));
    $out['fps'] = max(1,min(24,(int)($out['fps'] ?? 10)));

    $cleanAnims=[];
    $rawAnims = is_array($out['animations'] ?? null) ? $out['animations'] : [];
    foreach($rawAnims as $k=>$seq){
      $name=sanitize_key((string)$k);
      if($name==='' || !is_array($seq)) continue;
      $vals=[];
      foreach($seq as $v){ $iv=(int)$v; if($iv>=0) $vals[]=$iv; }
      $vals=array_values(array_unique($vals));
      if(!empty($vals)) $cleanAnims[$name]=$vals;
    }
    if(empty($cleanAnims)) $cleanAnims=$d['animations'];
    $out['animations']=$cleanAnims;
    return $out;
  }

  function prismtek_pet_sprite_v3_set($uid,$cfg){
    $uid=(int)$uid; if(!$uid) return;
    update_user_meta($uid,'prismtek_pet_sprite_v3',prismtek_pet_sprite_v3_sanitize($cfg));
  }
}

add_action('rest_api_init', function(){
  register_rest_route('prismtek/v1','/pet/sprite-v3',[
    'methods'=>'GET','permission_callback'=>'__return_true',
    'callback'=>function(){
      $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
      return rest_ensure_response(['ok'=>true,'config'=>prismtek_pet_sprite_v3_get($uid)]);
    }
  ]);

  register_rest_route('prismtek/v1','/pet/sprite-v3/reset',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(){
      $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
      delete_user_meta($uid,'prismtek_pet_sprite_v3');
      return rest_ensure_response(['ok'=>true,'config'=>prismtek_pet_sprite_v3_get($uid)]);
    }
  ]);

  register_rest_route('prismtek/v1','/pet/sprite-v3/config',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(WP_REST_Request $r){
      $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
      $cfg = prismtek_pet_sprite_v3_get($uid);
      $cfg['enabled'] = (bool)$r->get_param('enabled');
      $cfg['frameW'] = max(8,(int)($r->get_param('frameW') ?: $cfg['frameW']));
      $cfg['frameH'] = max(8,(int)($r->get_param('frameH') ?: $cfg['frameH']));
      $cfg['fps'] = max(1,min(24,(int)($r->get_param('fps') ?: $cfg['fps'])));
      $animJson = (string)$r->get_param('animationsJson');
      if($animJson!==''){
        $j=json_decode($animJson,true);
        if(is_array($j)) $cfg['animations']=$j;
      }
      $cfg = prismtek_pet_sprite_v3_sanitize($cfg);
      prismtek_pet_sprite_v3_set($uid,$cfg);
      return rest_ensure_response(['ok'=>true,'config'=>$cfg]);
    }
  ]);

  register_rest_route('prismtek/v1','/pet/sprite-v3/upload',[
    'methods'=>'POST','permission_callback'=>'__return_true',
    'callback'=>function(WP_REST_Request $r){
      $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
      if(empty($_FILES['sheet'])) return new WP_REST_Response(['ok'=>false,'error'=>'missing_sheet'],400);
      require_once ABSPATH.'wp-admin/includes/file.php';
      $over=['test_form'=>false,'mimes'=>['png'=>'image/png','jpg|jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif']];
      $up=wp_handle_upload($_FILES['sheet'],$over);
      if(!empty($up['error'])) return new WP_REST_Response(['ok'=>false,'error'=>'upload_failed','detail'=>$up['error']],400);

      $path=(string)($up['file']??''); $url=esc_url_raw((string)($up['url']??''));
      $dim=@getimagesize($path);
      if(!$dim) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_image'],400);
      $sheetW=(int)$dim[0]; $sheetH=(int)$dim[1];

      $cfg=prismtek_pet_sprite_v3_get($uid);
      $cfg['imageUrl']=$url;
      $cfg['sheetW']=$sheetW;
      $cfg['sheetH']=$sheetH;
      $cfg['frameW']=max(8,(int)($r->get_param('frameW') ?: ($cfg['frameW']?:32)));
      $cfg['frameH']=max(8,(int)($r->get_param('frameH') ?: ($cfg['frameH']?:32)));
      $cfg['fps']=max(1,min(24,(int)($r->get_param('fps') ?: ($cfg['fps']?:10))));
      $cfg['enabled']=true;

      // strict validity checks for predictable animation
      if($cfg['frameW']>$sheetW || $cfg['frameH']>$sheetH){
        $cfg['enabled']=false;
        prismtek_pet_sprite_v3_set($uid,$cfg);
        return new WP_REST_Response(['ok'=>false,'error'=>'frame_too_large','config'=>$cfg],400);
      }
      if($sheetW % $cfg['frameW'] !== 0 || $sheetH % $cfg['frameH'] !== 0){
        $cfg['enabled']=false;
        prismtek_pet_sprite_v3_set($uid,$cfg);
        return new WP_REST_Response(['ok'=>false,'error'=>'sheet_not_divisible','config'=>$cfg],400);
      }
      $cfg = prismtek_pet_sprite_v3_sanitize($cfg);
      prismtek_pet_sprite_v3_set($uid,$cfg);
      return rest_ensure_response(['ok'=>true,'config'=>$cfg]);
    }
  ]);
});

add_action('init', function(){
  remove_shortcode('prism_creatures_portal');
  add_shortcode('prism_creatures_portal', function(){
    $logged=is_user_logged_in();
    $nonce=$logged?wp_create_nonce('wp_rest'):'';
    $api=esc_url_raw(rest_url('prismtek/v1/'));
    ob_start(); ?>
    <section class="pph-wrap creature-v3" style="margin-top:0;gap:14px;">
      <article class="pph-card"><h3>Prism Creatures</h3><p>Companion life-sim + battle trainer. Stable core + advanced sprite uploader.</p></article>
      <?php if(!$logged): ?>
        <article class="pph-card"><p>Log in to raise and customize your creature.</p><p><a href="<?php echo esc_url(wp_login_url(home_url('/prism-creatures/'))); ?>">Login</a></p></article>
      <?php else: ?>
      <article class="pph-card creature-shell">
        <div class="creature-top">
          <div class="creature-stage">
            <canvas id="pph-pet-canvas" width="192" height="192"></canvas>
            <div class="creature-stage-label">PARTNER LINK</div>
          </div>
          <div class="creature-panel">
            <div id="pph-pet-view" class="pph-pet-view">Loading creature...</div>
            <div id="pph-pet-bars"></div>
          </div>
        </div>

        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="pph-pet-species"><option value="sprout">Sprout</option><option value="ember">Ember</option><option value="tidal">Tidal</option><option value="volt">Volt</option><option value="shade">Shade</option></select></label>
          <label>Personality<select id="pph-pet-personality"><option value="brave">Brave</option><option value="curious">Curious</option><option value="calm">Calm</option><option value="chaotic">Chaotic</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pph-pet-adopt" type="button">Sync Creature Type</button><button id="pph-pet-train" type="button">Train (+XP)</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pph-pet-feed" type="button">Feed</button><button id="pph-pet-play" type="button">Play</button><button id="pph-pet-rest" type="button">Rest</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pph-pet-name" type="text" maxlength="20" placeholder="Rename creature" /><button id="pph-pet-rename" type="button">Save Name</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><select id="pph-pet-skin"></select><button id="pph-pet-skin-save" type="button">Apply Skin</button></div>
        <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pph-pet-spar" type="button">Spar Battle</button></div>

        <hr style="border-color:#3d4688;opacity:.6;margin:12px 0">
        <h4 style="margin:0 0 8px">Sprite Uploader (Desktop + Mobile)</h4>
        <form id="pph-sprite-upload-form" class="pph-form" enctype="multipart/form-data">
          <input type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required />
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><input type="number" name="frameW" min="8" max="1024" placeholder="Frame W" /><input type="number" name="frameH" min="8" max="1024" placeholder="Frame H" /><input type="number" name="fps" min="1" max="24" placeholder="FPS" /></div>
          <button type="submit">Upload & Validate</button>
        </form>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
          <button id="pph-sprite-native" type="button">Use Native Animator</button>
          <button id="pph-sprite-reset" type="button">Reset Uploader Config</button>
          <select id="pph-sprite-animation"><option value="idle">idle</option><option value="walk">walk</option><option value="run">run</option><option value="attack">attack</option></select>
        </div>
        <p id="pph-pet-status" class="pph-status"></p>
      </article>
      <?php endif; ?>
      <article class="pph-card"><h3>Creature Showcase</h3><?php echo do_shortcode('[prism_pet_showcase]'); ?></article>
    </section>

    <style>
      .creature-shell{background:linear-gradient(180deg,rgba(24,29,70,.95),rgba(15,18,46,.95));}
      .creature-top{display:grid;grid-template-columns:208px 1fr;gap:14px;align-items:start}
      .creature-stage{display:grid;gap:8px;justify-items:center}
      #pph-pet-canvas{width:192px;height:192px;border:2px solid #7f8cff;background:radial-gradient(circle at 50% 35%,#23356d,#0c1028 72%);image-rendering:pixelated;display:block}
      .creature-stage-label{font-size:10px;letter-spacing:.12em;color:#d6dcff;opacity:.9}
      .pph-pet-view{padding:10px;border:1px solid #4c5498;background:#0f1130;font-size:12px;line-height:1.5;min-height:78px}
      .pph-bar{height:9px;background:#1b1f45;border:1px solid #4f59a6;position:relative;margin:4px 0 7px}.pph-bar>span{display:block;height:100%}
      @media (max-width:840px){.creature-top{grid-template-columns:1fr} #pph-pet-canvas{margin:0 auto}}
    </style>

    <script>
    (()=>{
      const API='<?php echo esc_js($api); ?>'; const nonce='<?php echo esc_js($nonce); ?>';
      const c=document.getElementById('pph-pet-canvas'); const petView=document.getElementById('pph-pet-view'); if(!c||!petView) return;
      const x=c.getContext('2d'); x.imageSmoothingEnabled=false;
      const bars=document.getElementById('pph-pet-bars'); const statusEl=document.getElementById('pph-pet-status');
      const nameEl=document.getElementById('pph-pet-name'), skinEl=document.getElementById('pph-pet-skin'), spEl=document.getElementById('pph-pet-species'), perEl=document.getElementById('pph-pet-personality');
      const animEl=document.getElementById('pph-sprite-animation'), uploadForm=document.getElementById('pph-sprite-upload-form');
      const allowedSpecies=['sprout','ember','tidal','volt','shade']; const allowedPer=['brave','curious','calm','chaotic'];

      let pet=null, cfg=null, img=null, raf=0, last=0, cursor=0, anim='idle';
      const toStr=(v,d='')=>typeof v==='string'?v:(Array.isArray(v)?String(v[0]??d):d);
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const setStatus=(t)=>{ if(statusEl) statusEl.textContent=t||''; };

      // native fallback animator
      const palMap={default:['#00000000','#59d9ff','#b8f2ff','#0e1026'],mint:['#00000000','#77ffc4','#c8ffe8','#0e1026'],sunset:['#00000000','#ff8f66','#ffd18a','#0e1026'],galaxy:['#00000000','#8f7bff','#ff7bf2','#0e1026'],neon:['#00000000','#39ff14','#00e5ff','#0e1026']};
      const base=['0000000000000000','0000011111100000','0001122222211000','0012223333222100','0122233333332210','0122233333332210','0012223333222100','0001122222211000','0000011221110000','0000111000110000','0001100000011000','0011000000001100','0011000000001100','0001000000001000','0000100000010000','0000011111100000'];
      const walk1=[...base]; walk1[11]='0011000000000110'; walk1[12]='0001100000011000';
      const walk2=[...base]; walk2[11]='0110000000001100'; walk2[12]='0011000000000110';
      const run1=[...base]; run1[10]='0011000000000110'; run1[11]='0110000000001100';
      const run2=[...base]; run2[10]='0110000000001100'; run2[11]='0011000000000110';
      const atk1=[...base]; atk1[4]='0122233333332222'; atk1[5]='0122233333332222';
      const atk2=[...base]; atk2[4]='2222233333332210'; atk2[5]='2222233333332210';
      const nativeAnims={idle:[base,walk1],walk:[walk1,base,walk2,base],run:[run1,walk1,run2,walk2],attack:[atk1,atk2,atk1,base]};

      function drawNative(frame,skin='default'){
        const pal=palMap[skin]||palMap.default;
        x.clearRect(0,0,192,192);
        const s=10, ox=16, oy=16;
        for(let yy=0; yy<16; yy++){
          const row=(frame[yy]||'').padEnd(16,'0');
          for(let xx=0; xx<16; xx++){
            const idx=Number(row[xx])||0; const col=pal[idx]||'transparent';
            if(col!=='transparent'&&col!=='#00000000'){ x.fillStyle=col; x.fillRect(ox+xx*s, oy+yy*s, s, s); }
          }
        }
      }

      function drawSheetFrame(){
        if(!cfg||!img) return false;
        const cols=Math.floor(cfg.sheetW/cfg.frameW); if(cols<1) return false;
        const idxSeq=(cfg.animations&&cfg.animations[anim])?cfg.animations[anim]:(cfg.animations?.idle||[0]);
        if(!Array.isArray(idxSeq)||!idxSeq.length) return false;
        const idx=Number(idxSeq[cursor%idxSeq.length]||0); cursor++;
        const sx=(idx%cols)*cfg.frameW, sy=Math.floor(idx/cols)*cfg.frameH;
        if(sx+cfg.frameW>cfg.sheetW || sy+cfg.frameH>cfg.sheetH) return false;
        const scale=Math.min(168/cfg.frameW,168/cfg.frameH); const dw=Math.floor(cfg.frameW*scale), dh=Math.floor(cfg.frameH*scale);
        const dx=Math.floor((192-dw)/2), dy=Math.floor((192-dh)/2);
        x.clearRect(0,0,192,192);
        try{x.drawImage(img,sx,sy,cfg.frameW,cfg.frameH,dx,dy,dw,dh);}catch{return false;}
        return true;
      }

      function normalizePet(raw){ if(!raw||typeof raw!=='object') return null; const p={...raw}; p.name=toStr(p.name,'Prismo'); p.stage=toStr(p.stage,'baby'); p.skin=toStr(p.skin,'default'); p.species=toStr(p.species,'sprout'); if(!allowedSpecies.includes(p.species)) p.species='sprout'; p.personality=toStr(p.personality,'brave'); if(!allowedPer.includes(p.personality)) p.personality='brave'; p.form=toStr(p.form,`${p.species}-${p.personality}-cub`); ['health','energy','happiness','hunger','level','xp','nextLevelXp','wins','losses'].forEach(k=>p[k]=Number(p[k]||0)); return p; }
      function renderBars(p){ const b=(n,v,c)=>`<div style="font-size:10px">${n} ${Math.round(v)}%</div><div class="pph-bar"><span style="width:${clamp(v,0,100)}%;background:${c}"></span></div>`; bars.innerHTML=b('Health',p.health,'#5de28f')+b('Energy',p.energy,'#59d9ff')+b('Happiness',p.happiness,'#f8c062')+b('Hunger',p.hunger,'#d98fff'); }
      function renderPet(raw){ const p=normalizePet(raw); if(!p) return; pet=p; petView.innerHTML=`<strong>${p.name}</strong><br>Species ${p.species} · Personality ${p.personality}<br>Form ${p.form} · Stage ${p.stage}<br>Lvl ${p.level||1} · XP ${p.xp||0}/${p.nextLevelXp||30} · W/L ${p.wins||0}/${p.losses||0}`; renderBars(p); if(!nameEl.value) nameEl.value=p.name; spEl.value=p.species; perEl.value=p.personality; const skins=(p.unlocks&&Array.isArray(p.unlocks.skins))?p.unlocks.skins:['default']; skinEl.innerHTML=skins.map(sk=>`<option value="${sk}" ${sk===p.skin?'selected':''}>${sk}</option>`).join(''); }

      function stop(){ if(raf) cancelAnimationFrame(raf); raf=0; }
      function loop(ts){
        const fps = cfg?.enabled ? clamp(Number(cfg.fps||10),1,24) : (anim==='run'?12:(anim==='walk'?8:6));
        if(!last || ts-last>=1000/fps){
          last=ts;
          let ok=false;
          if(cfg?.enabled && img) ok=drawSheetFrame();
          if(!ok){ const seq=nativeAnims[anim]||nativeAnims.idle; const fr=seq[cursor%seq.length]; cursor++; drawNative(fr, pet?.skin||'default'); }
        }
        raf=requestAnimationFrame(loop);
      }
      function start(){ stop(); cursor=0; last=0; raf=requestAnimationFrame(loop); }

      async function loadImage(url){ return await new Promise((res,rej)=>{ const im=new Image(); im.crossOrigin='anonymous'; im.onload=()=>res(im); im.onerror=rej; im.src=url+(url.includes('?')?'&':'?')+'v='+Date.now(); }); }
      async function post(path,payload,form){ const o={method:'POST',credentials:'include',headers:{'X-WP-Nonce':nonce}}; if(form){o.body=form;} else {o.headers['content-type']='application/json'; o.body=JSON.stringify(payload||{});} const r=await fetch(API+path,o); const j=await r.json().catch(()=>({})); return {ok:r.ok,data:j}; }
      async function loadCfg(){ const r=await fetch(API+'pet/sprite-v3?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){cfg={enabled:false};return;} const j=await r.json(); cfg=j.config||{enabled:false}; if(cfg.enabled && cfg.imageUrl){ try{img=await loadImage(cfg.imageUrl);}catch{img=null; cfg.enabled=false;} } }
      async function loadPet(){ const r=await fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include',cache:'no-store',headers:nonce?{'X-WP-Nonce':nonce}:{}}); if(!r.ok){ petView.textContent='Log in to care for your creature.'; return; } const j=await r.json(); renderPet(j.pet||null); }
      async function petAction(action,extra={}){ setStatus('Working...'); const out=await post('pet/action',Object.assign({action},extra||{})); if(!out.ok){setStatus(out.data?.error||'Action failed.');return;} renderPet(out.data.pet||null); setStatus('Done.'); }

      document.getElementById('pph-pet-feed')?.addEventListener('click',()=>petAction('feed'));
      document.getElementById('pph-pet-play')?.addEventListener('click',()=>petAction('play'));
      document.getElementById('pph-pet-rest')?.addEventListener('click',()=>petAction('rest'));
      document.getElementById('pph-pet-rename')?.addEventListener('click',()=>petAction('rename',{name:(nameEl.value||'').trim()}));
      document.getElementById('pph-pet-skin-save')?.addEventListener('click',()=>petAction('setskin',{skin:skinEl.value||'default'}));
      document.getElementById('pph-pet-adopt')?.addEventListener('click',async()=>{ setStatus('Syncing type...'); const out=await post('pet/adopt',{species:spEl.value||'sprout',personality:perEl.value||'brave'}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus('Creature type synced.'); });
      document.getElementById('pph-pet-train')?.addEventListener('click',async()=>{ setStatus('Training...'); const out=await post('pet/train',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`Training +${out.data.xpGained||0} XP`); });
      document.getElementById('pph-pet-spar')?.addEventListener('click',async()=>{ setStatus('Sparring...'); const out=await post('pet/battle/spar',{}); if(!out.ok){setStatus(out.data?.error||'Failed.');return;} renderPet(out.data.pet||null); setStatus(`${out.data.result==='win'?'WIN':'LOSS'} +${out.data.xpGained||0} XP`); });
      animEl?.addEventListener('change',()=>{ anim=animEl.value||'idle'; cursor=0; });

      uploadForm?.addEventListener('submit', async (e)=>{
        e.preventDefault(); setStatus('Uploading & validating...');
        const fd=new FormData(uploadForm);
        const out=await post('pet/sprite-v3/upload',null,fd);
        if(!out.ok){
          const msg={sheet_not_divisible:'Frame W/H must divide image dimensions exactly.',frame_too_large:'Frame size is larger than image.',upload_failed:'Upload failed.'};
          setStatus(msg[out.data?.error]||out.data?.error||'Upload failed.'); return;
        }
        cfg=out.data.config||{enabled:false};
        img=null; if(cfg.enabled && cfg.imageUrl){ try{img=await loadImage(cfg.imageUrl);}catch{img=null; cfg.enabled=false;} }
        cursor=0; setStatus(cfg.enabled?'Custom sprite enabled.':'Using native animator.');
      });

      document.getElementById('pph-sprite-native')?.addEventListener('click', async ()=>{
        setStatus('Switching to native animator...');
        const out=await post('pet/sprite-v3/config',{enabled:false});
        if(!out.ok){setStatus('Failed.');return;}
        cfg=out.data.config||{enabled:false}; img=null; cursor=0; setStatus('Native animator active.');
      });

      document.getElementById('pph-sprite-reset')?.addEventListener('click', async ()=>{
        setStatus('Resetting uploader config...');
        const out=await post('pet/sprite-v3/reset',{});
        if(!out.ok){setStatus('Failed.');return;}
        cfg=out.data.config||{enabled:false}; img=null; cursor=0; setStatus('Uploader config reset.');
      });

      Promise.all([loadPet(), loadCfg()]).then(()=>{start(); setStatus(cfg?.enabled?'Custom sprite active.':'Native animator active.');}).catch(()=>{start();});
    })();
    </script>
    <?php return ob_get_clean();
  });
}, 2000000);

// ===== Prism Creatures UX polish: readability + expandable uploader =====
add_action('wp_head', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    echo '<style id="prism-creatures-ux-polish">\n'
      . '.creature-v3, .creature-v3 * { color:#eef2ff; }\n'
      . '.creature-v3 p, .creature-v3 label, .creature-v3 input, .creature-v3 select, .creature-v3 button { font-size:13px !important; line-height:1.45 !important; }\n'
      . '.creature-v3 .pph-card { border-color:#8b95ff !important; box-shadow:6px 6px 0 rgba(40,48,110,.65) !important; }\n'
      . '.creature-v3 .pph-pet-view { color:#f6f8ff !important; background:#0d1334 !important; border-color:#5f6ad1 !important; }\n'
      . '.creature-v3 .pph-status { color:#ffe08a !important; text-shadow:1px 1px 0 #000; font-size:12px !important; }\n'
      . '.creature-v3 input, .creature-v3 select { background:#0f163c !important; color:#f3f6ff !important; border:1px solid #5c67cc !important; }\n'
      . '.creature-v3 input::placeholder { color:#c8d0ff !important; opacity:.95; }\n'
      . '.creature-v3 button { background:#1d2a66 !important; color:#fff !important; border:1px solid #7381f0 !important; font-weight:600; }\n'
      . '.creature-v3 button:hover { filter:brightness(1.08); }\n'
      . '.creature-v3 h3, .creature-v3 h4 { color:#ffffff !important; text-shadow:1px 1px 0 #000; }\n'
      . '.creature-v3 details.prism-uploader { border:1px solid #5f6ad1; background:#0d1334; padding:8px; margin-top:6px; }\n'
      . '.creature-v3 details.prism-uploader > summary { cursor:pointer; font-weight:700; color:#dfe5ff; list-style:none; }\n'
      . '.creature-v3 details.prism-uploader > summary::-webkit-details-marker { display:none; }\n'
      . '.creature-v3 details.prism-uploader[open] > summary { margin-bottom:8px; }\n'
      . '@media (max-width:760px){\n'
      . '  .creature-v3 .pph-tool-row{ grid-template-columns:1fr !important; }\n'
      . '  .creature-v3 #pph-pet-canvas{ width:min(72vw,192px) !important; height:min(72vw,192px) !important; }\n'
      . '}\n'
      . '</style>';
}, 99999);

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script>
    (function(){
      function wrapUploader(){
        const form = document.getElementById('pph-sprite-upload-form');
        if(!form || form.dataset.wrappedUploader==='1') return;
        const details=document.createElement('details');
        details.className='prism-uploader';
        details.open=false;
        const sum=document.createElement('summary');
        sum.textContent='Sprite Uploader (tap to expand)';
        details.appendChild(sum);

        const row = form.parentElement;
        // Move heading + form + action row into details if present
        const heading = row.querySelector('h4');
        if(heading) details.appendChild(heading);
        details.appendChild(form);

        // include immediate tool row after form when present
        const siblings=[...row.children];
        const idx=siblings.indexOf(form);
        if(idx>=0 && siblings[idx+1] && siblings[idx+1].classList.contains('pph-tool-row')){
          details.appendChild(siblings[idx+1]);
        }

        row.insertBefore(details, row.querySelector('#pph-pet-status') || null);
        form.dataset.wrappedUploader='1';
      }

      function improveStatus(){
        const status=document.getElementById('pph-pet-status');
        if(status && !status.textContent.trim()) status.textContent='Ready.';
      }

      function run(){ wrapUploader(); improveStatus(); }
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run);
      else run();
      // one retry for async-render edge cases
      setTimeout(run, 300);
    })();
    </script>
    <?php
}, 99999);

// ===== Site fixes: restore Memory Wall + header/logo/mobile + home centering =====
add_action('init', function(){
  if (!shortcode_exists('prism_memory_wall_portal')) {
    add_shortcode('prism_memory_wall_portal', function(){
      $css = '\n'
        . '.pph-wrap > h2, .pph-wrap > p { display:none !important; }\n'
        . '.pph-toggle[data-toggle-key="account"],\n'
        . '.pph-toggle[data-toggle-key="games"],\n'
        . '.pph-toggle[data-toggle-key="chat"],\n'
        . '.pph-toggle[data-toggle-key="studio"],\n'
        . '#pph-pet-panel,\n'
        . '#pph-clear-chat,\n'
        . '#pph-reset-scores { display:none !important; }\n'
        . '.pph-toggle[data-toggle-key="wall"] { display:block !important; }\n'
        . '.pph-toggle[data-toggle-key="wall"] > summary { display:none !important; }\n'
        . '.pph-wrap{margin-top:0 !important;}\n';
      $js = "<script>(function(){document.addEventListener('DOMContentLoaded',function(){var w=document.querySelector('.pph-toggle[data-toggle-key=\"wall\"]');if(w){w.open=true;}['account','games','chat','studio'].forEach(function(k){var el=document.querySelector('.pph-toggle[data-toggle-key=\"'+k+'\"]');if(el){el.open=false;}});});})();</script>";
      return '<style>'.$css.'</style>'.do_shortcode('[prism_pixel_hub]').$js;
    });
  }
});

add_action('wp_head', function(){
  echo '<style id="prism-global-ui-fixes">\n'
    // Header logo/title fixes
    . '.site-logo-img img.custom-logo{height:auto !important; width:auto !important; max-height:52px !important; max-width:52px !important; object-fit:contain !important;}\n'
    . '.ast-site-identity{display:flex !important; align-items:center !important; gap:10px !important;}\n'
    . '.ast-site-title-wrap .site-title a{white-space:nowrap !important; line-height:1 !important;}\n'
    . '.ast-site-title-wrap .site-title{margin:0 !important;}\n'
    . '@media (max-width:921px){\n'
    . '  .site-logo-img img.custom-logo{max-height:34px !important; max-width:34px !important;}\n'
    . '  .ast-site-title-wrap .site-title{font-size:18px !important;}\n'
    . '  .ast-site-title-wrap .site-title a{white-space:nowrap !important;}\n'
    . '}\n'
    // Home page centering (desktop emphasis)
    . 'body.home .entry-content{max-width:980px !important; margin:0 auto !important; text-align:center !important;}\n'
    . 'body.home .entry-content .page-grid, body.home .entry-content .page-card{margin-left:auto !important; margin-right:auto !important;}\n'
    . 'body.home .entry-content .page-links{justify-content:center !important;}\n'
    . '@media (max-width:921px){ body.home .entry-content{text-align:left !important;} }\n'
    . '</style>';
}, 999999);

// ===== Logo blend + extra home centering tune =====
add_action('wp_head', function(){
  echo '<style id="prism-logo-home-tune2">\n'
    . '.site-logo-img img.custom-logo{mix-blend-mode:multiply !important;}\n'
    . '@media (max-width:921px){ .site-header-primary-section-right .site-title, .site-header-primary-section-left .site-title{font-size:16px !important;} }\n'
    . '@media (min-width:922px){ body.home .elementor, body.home .elementor-widget-wrap, body.home .elementor-widget-container{ text-align:center !important; } }\n'
    . '</style>';
}, 1000000);

// ===== Hard nav/header fix for mobile + desktop branding =====
add_action('wp_head', function(){
  echo '<style id="prism-nav-hardfix3">\n'
    . '.site-branding{display:flex !important;align-items:center !important;gap:8px !important;min-width:0 !important;}\n'
    . '.site-branding .site-logo-img{flex:0 0 auto !important;line-height:0 !important;}\n'
    . '.site-branding .site-logo-img img.custom-logo{max-width:42px !important;max-height:42px !important;width:auto !important;height:auto !important;object-fit:contain !important;border-radius:4px;mix-blend-mode:multiply !important;}\n'
    . '.site-branding .ast-site-title-wrap{min-width:0 !important;max-width:100% !important;}\n'
    . '.site-branding .site-title,.site-branding .site-title a{white-space:nowrap !important;line-height:1 !important;overflow:hidden !important;text-overflow:ellipsis !important;display:block !important;}\n'
    . '.site-branding .site-title{font-size:20px !important;margin:0 !important;}\n'

    . '@media (max-width:921px){\n'
    . '  .ast-mobile-header-wrap .site-branding{max-width:calc(100vw - 84px) !important;}\n'
    . '  .ast-mobile-header-wrap .site-branding .site-logo-img img.custom-logo{max-width:28px !important;max-height:28px !important;}\n'
    . '  .ast-mobile-header-wrap .site-branding .site-title{font-size:15px !important;}\n'
    . '  .ast-mobile-header-wrap .site-branding .site-title a{white-space:nowrap !important;}\n'
    . '}\n'

    // keep nav links on one line where possible
    . '.main-header-menu .menu-link{white-space:nowrap !important;}\n'
    . '</style>';
}, 2000000);

// ===== Hard center home content desktop =====
add_action('wp_head', function(){
  if (!function_exists('is_front_page') || !is_front_page()) return;
  echo '<style id="prism-home-center-hardfix">\n'
    . '@media (min-width:922px){\n'
    . '  body.home .site-content > .ast-container{display:block !important;max-width:1240px !important;margin:0 auto !important;}\n'
    . '  body.home #primary{float:none !important;max-width:100% !important;width:100% !important;margin:0 auto !important;}\n'
    . '  body.home article.page{max-width:980px !important;margin:0 auto !important;}\n'
    . '  body.home .entry-content{max-width:980px !important;margin:0 auto !important;text-align:center !important;}\n'
    . '  body.home .elementor, body.home .elementor-section, body.home .elementor-container{max-width:980px !important;margin-left:auto !important;margin-right:auto !important;}\n'
    . '  body.home .entry-content .page-grid, body.home .entry-content .page-card{margin-left:auto !important;margin-right:auto !important;}\n'
    . '}\n'
    . '</style>';
}, 3000000);

// ===== Home page hard DOM centering (desktop) =====
add_action('wp_footer', function(){
  if (!function_exists('is_front_page') || !is_front_page()) return;
  ?>
  <script id="prism-home-dom-hardfix">
  (function(){
    function setImp(el, prop, val){ if(!el) return; el.style.setProperty(prop, val, 'important'); }
    function apply(){
      if(window.innerWidth < 922) return;
      const root = document.querySelector('body.home');
      if(!root) return;

      const astContainer = document.querySelector('.site-content > .ast-container');
      setImp(astContainer,'display','block');
      setImp(astContainer,'max-width','1240px');
      setImp(astContainer,'margin-left','auto');
      setImp(astContainer,'margin-right','auto');

      const primary = document.querySelector('#primary');
      setImp(primary,'float','none');
      setImp(primary,'width','100%');
      setImp(primary,'max-width','100%');
      setImp(primary,'margin-left','auto');
      setImp(primary,'margin-right','auto');

      const article = document.querySelector('article.page');
      setImp(article,'max-width','980px');
      setImp(article,'margin-left','auto');
      setImp(article,'margin-right','auto');

      const entry = document.querySelector('.entry-content');
      setImp(entry,'max-width','980px');
      setImp(entry,'margin-left','auto');
      setImp(entry,'margin-right','auto');
      setImp(entry,'text-align','center');

      document.querySelectorAll('.entry-content .page-grid, .entry-content .page-card, .entry-content .page-links').forEach(el=>{
        setImp(el,'margin-left','auto');
        setImp(el,'margin-right','auto');
      });

      document.querySelectorAll('.entry-content .elementor, .entry-content .elementor-section, .entry-content .elementor-container').forEach(el=>{
        setImp(el,'max-width','980px');
        setImp(el,'margin-left','auto');
        setImp(el,'margin-right','auto');
      });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', apply);
    else apply();
    window.addEventListener('resize', apply);
    setTimeout(apply, 300);
    setTimeout(apply, 1000);
  })();
  </script>
  <?php
}, 4000000);

// ===== Next Targets Pack: layout/logo/creatures directory + battle v2 =====

// 1) Home structural wrapper centering (content-level)
add_filter('the_content', function($content){
    if (!is_admin() && function_exists('is_front_page') && is_front_page() && is_main_query() && in_the_loop()) {
        if (strpos($content, 'prism-home-structure-wrap') === false) {
            $content = '<div class="prism-home-structure-wrap">' . $content . '</div>';
        }
    }
    return $content;
}, 999999);

add_action('wp_head', function(){
    echo '<style id="prism-next-targets-layout">'
      . '@media (min-width:922px){'
      . 'body.home .prism-home-structure-wrap{max-width:980px;margin:0 auto;text-align:center;}'
      . 'body.home .prism-home-structure-wrap .page-grid, body.home .prism-home-structure-wrap .page-card{margin-left:auto;margin-right:auto;}'
      . '}'
      . '.site-branding .site-logo-img img.custom-logo{max-width:34px!important;max-height:34px!important;width:auto!important;height:auto!important;object-fit:contain!important;}'
      . '.site-branding .site-title,.site-branding .site-title a{white-space:nowrap!important;line-height:1!important;overflow:hidden!important;text-overflow:ellipsis!important;}'
      . '@media (min-width:922px){.site-branding .site-logo-img img.custom-logo{max-width:44px!important;max-height:44px!important;}}'
      . '</style>';
}, 5000000);

// 2) Creatures directory + profile data
if (!function_exists('prismtek_creature_public_data')) {
    function prismtek_creature_public_data($uid) {
        $uid = (int)$uid;
        $u = get_userdata($uid);
        if (!$u) return null;

        $pet = get_user_meta($uid, 'prismtek_pet_state', true);
        if (!is_array($pet) || empty($pet)) return null;

        $scores = function_exists('prismtek_pixel_get_scores') ? prismtek_pixel_get_scores() : [];
        $total = 0;
        foreach ($scores as $g => $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $r) {
                if ((int)($r['userId'] ?? 0) === $uid) $total += (int)($r['score'] ?? 0);
            }
        }

        $stage = function_exists('prismtek_pet_compute_stage') ? prismtek_pet_compute_stage($pet) : 'baby';
        return [
            'user' => (string)$u->user_login,
            'displayName' => (string)$u->display_name,
            'bio' => (string)get_user_meta($uid, 'prismtek_bio', true),
            'favoriteGame' => (string)get_user_meta($uid, 'prismtek_favorite_game', true),
            'themeColor' => (string)get_user_meta($uid, 'prismtek_theme_color', true),
            'totalScore' => (int)$total,
            'creature' => [
                'name' => (string)($pet['name'] ?? 'Prismo'),
                'species' => (string)($pet['species'] ?? 'sprout'),
                'personality' => (string)($pet['personality'] ?? 'brave'),
                'skin' => (string)($pet['skin'] ?? 'default'),
                'stage' => (string)$stage,
                'health' => (int)($pet['health'] ?? 100),
                'happiness' => (int)($pet['happiness'] ?? 100),
                'energy' => (int)($pet['energy'] ?? 100),
                'hunger' => (int)($pet['hunger'] ?? 100),
            ],
        ];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/creatures/directory',[
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function(){
            $users = get_users(['number'=>150,'fields'=>['ID']]);
            $rows = [];
            foreach ($users as $u) {
                $d = prismtek_creature_public_data((int)$u->ID);
                if ($d) $rows[] = $d;
            }
            usort($rows, fn($a,$b)=>($b['totalScore']<=>$a['totalScore']));
            return rest_ensure_response(['ok'=>true,'rows'=>$rows]);
        }
    ]);

    register_rest_route('prismtek/v1','/creatures/profile',[
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $user = sanitize_text_field((string)$r->get_param('user'));
            if ($user==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_user'],400);
            $u = get_user_by('login',$user);
            if (!$u) $u = get_user_by('slug',$user);
            if (!$u) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
            $d = prismtek_creature_public_data((int)$u->ID);
            if (!$d) return new WP_REST_Response(['ok'=>false,'error'=>'no_creature'],404);
            return rest_ensure_response(['ok'=>true,'profile'=>$d]);
        }
    ]);
});

// 3) Battle v2 engine
if (!function_exists('prismtek_battle_v2_rating')) {
    function prismtek_battle_v2_rating($uid){
        $r = (int)get_user_meta((int)$uid,'prismtek_battle_v2_rating',true);
        if ($r <= 0) $r = 1000;
        return $r;
    }
    function prismtek_battle_v2_set_rating($uid,$rating){
        update_user_meta((int)$uid,'prismtek_battle_v2_rating',max(100,(int)$rating));
    }

    function prismtek_battle_v2_new($uid){
        $pet = function_exists('prismtek_pet_get_state') ? prismtek_pet_get_state((int)$uid) : [];
        $lvl = max(1,(int)($pet['level'] ?? 1));

        $state = [
            'startedAt' => time(),
            'round' => 1,
            'player' => [
                'hp' => 80 + $lvl * 5,
                'maxHp' => 80 + $lvl * 5,
                'guard' => 0,
                'charge' => 0,
                'cooldowns' => ['heal'=>0,'charge'=>0],
            ],
            'enemy' => [
                'name' => 'Wild Glitchmon',
                'hp' => 70 + $lvl * 5 + rand(0,20),
                'maxHp' => 70 + $lvl * 5 + rand(0,20),
                'guard' => 0,
                'charge' => 0,
            ],
            'log' => ['Battle started.'],
            'done' => false,
        ];
        update_user_meta((int)$uid,'prismtek_battle_v2_state',$state);
        return $state;
    }

    function prismtek_battle_v2_get($uid){
        $s = get_user_meta((int)$uid,'prismtek_battle_v2_state',true);
        return is_array($s) ? $s : [];
    }

    function prismtek_battle_v2_set($uid,$state){
        if (!is_array($state)) $state=[];
        update_user_meta((int)$uid,'prismtek_battle_v2_state',$state);
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/battle-v2/start',[
        'methods'=>'POST',
        'permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid = get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $s = prismtek_battle_v2_new($uid);
            return rest_ensure_response(['ok'=>true,'state'=>$s]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/battle-v2/move',[
        'methods'=>'POST',
        'permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid = get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $move = sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $s = prismtek_battle_v2_get($uid);
            if (empty($s) || !empty($s['done'])) $s = prismtek_battle_v2_new($uid);

            $p =& $s['player']; $e =& $s['enemy'];
            foreach (($p['cooldowns'] ?? []) as $k=>$v) $p['cooldowns'][$k] = max(0,(int)$v-1);

            $log = [];
            // Player turn
            if ($move === 'strike') {
                $dmg = rand(12,22) + (int)$p['charge'];
                if (!empty($e['guard'])) $dmg = (int)floor($dmg * 0.55);
                $e['hp'] = max(0,(int)$e['hp'] - $dmg);
                $log[] = "You used STRIKE for {$dmg}.";
                $p['charge'] = 0;
            } elseif ($move === 'guard') {
                $p['guard'] = 1;
                $log[] = 'You used GUARD (reduced next damage).';
            } elseif ($move === 'charge') {
                if ((int)($p['cooldowns']['charge'] ?? 0) > 0) {
                    $log[] = 'CHARGE is on cooldown.';
                } else {
                    $p['charge'] = min(14,(int)$p['charge'] + 8);
                    $p['cooldowns']['charge'] = 2;
                    $log[] = 'You used CHARGE (+power next strike).';
                }
            } elseif ($move === 'heal') {
                if ((int)($p['cooldowns']['heal'] ?? 0) > 0) {
                    $log[] = 'HEAL is on cooldown.';
                } else {
                    $heal = rand(14,24);
                    $p['hp'] = min((int)$p['maxHp'], (int)$p['hp'] + $heal);
                    $p['cooldowns']['heal'] = 3;
                    $log[] = "You used HEAL (+{$heal} HP).";
                }
            }

            if ((int)$e['hp'] > 0) {
                // Enemy turn
                $enemyMove = ['strike','strike','guard','heal'][rand(0,3)];
                if ($enemyMove === 'guard') {
                    $e['guard'] = 1;
                    $log[] = $e['name'].' used GUARD.';
                } elseif ($enemyMove === 'heal' && (int)$e['hp'] < (int)($e['maxHp']*0.6)) {
                    $eh = rand(10,18);
                    $e['hp'] = min((int)$e['maxHp'], (int)$e['hp'] + $eh);
                    $log[] = $e['name']." healed {$eh}.";
                } else {
                    $ed = rand(10,20) + (int)$e['charge'];
                    if (!empty($p['guard'])) $ed = (int)floor($ed * 0.55);
                    $p['hp'] = max(0,(int)$p['hp'] - $ed);
                    $log[] = $e['name']." hit for {$ed}.";
                    $e['charge'] = 0;
                }
            }

            // guards consume after turn
            $p['guard'] = 0; $e['guard'] = 0;
            $s['round'] = (int)$s['round'] + 1;
            $s['log'] = array_slice(array_merge((array)$s['log'],$log), -20);

            if ((int)$p['hp'] <= 0 || (int)$e['hp'] <= 0) {
                $win = (int)$e['hp'] <= 0;
                $s['done'] = true;
                $s['result'] = $win ? 'win' : 'loss';
                $rating = prismtek_battle_v2_rating($uid);
                $rating += $win ? 18 : -12;
                prismtek_battle_v2_set_rating($uid,$rating);

                $pet = function_exists('prismtek_pet_get_state') ? prismtek_pet_get_state($uid) : [];
                if (is_array($pet)) {
                    $pet['wins'] = (int)($pet['wins'] ?? 0) + ($win ? 1 : 0);
                    $pet['losses'] = (int)($pet['losses'] ?? 0) + ($win ? 0 : 1);
                    $pet['xp'] = (int)($pet['xp'] ?? 0) + ($win ? rand(22,36) : rand(8,16));
                    if (function_exists('prismtek_pet_set_state')) prismtek_pet_set_state($uid,$pet);
                }
                $s['log'][] = $win ? 'Victory! Rating up.' : 'Defeat. Keep training.';
            }

            prismtek_battle_v2_set($uid,$s);
            return rest_ensure_response(['ok'=>true,'state'=>$s,'rating'=>prismtek_battle_v2_rating($uid)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/battle-v2/rankings',[
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function(){
            $users = get_users(['number'=>150,'fields'=>['ID','display_name','user_login']]);
            $rows = [];
            foreach($users as $u){
                $uid=(int)$u->ID;
                $r=(int)get_user_meta($uid,'prismtek_battle_v2_rating',true);
                if($r<=0) continue;
                $pet=get_user_meta($uid,'prismtek_pet_state',true);
                $rows[]=[
                    'user'=>(string)$u->user_login,
                    'displayName'=>(string)$u->display_name,
                    'rating'=>$r,
                    'wins'=>(int)($pet['wins'] ?? 0),
                    'losses'=>(int)($pet['losses'] ?? 0),
                ];
            }
            usort($rows,fn($a,$b)=>($b['rating']<=>$a['rating']));
            return rest_ensure_response(['ok'=>true,'rows'=>array_slice($rows,0,30)]);
        }
    ]);
});

// 4) Shortcodes: directory + profile
add_action('init', function(){
    if (!shortcode_exists('prism_creature_directory')) {
        add_shortcode('prism_creature_directory', function(){
            return '<section class="prism-creature-directory"><h2>Creature Directory</h2><div id="prism-creature-dir-grid">Loading creatures...</div></section>'
                . '<style>.prism-creature-directory #prism-creature-dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.prism-c-card{border:1px solid #5f6ad1;background:#0d1334;color:#eef2ff;padding:10px}.prism-c-card a{color:#9fd1ff;text-decoration:none}</style>'
                . '<script>(function(){const root=document.getElementById("prism-creature-dir-grid");if(!root)return;fetch("/wp-json/prismtek/v1/creatures/directory").then(r=>r.json()).then(j=>{if(!j.ok){root.textContent="Directory unavailable.";return;}root.innerHTML=(j.rows||[]).map(x=>`<article class="prism-c-card"><strong>${x.displayName||x.user}</strong><div>${x.creature?.name||"Prismo"} · ${x.creature?.stage||"baby"}</div><div>Score ${x.totalScore||0}</div><a href="/creature-profile/?user=${encodeURIComponent(x.user)}">View Profile</a></article>`).join("");}).catch(()=>root.textContent="Directory unavailable.");})();</script>';
        });
    }

    if (!shortcode_exists('prism_creature_profile')) {
        add_shortcode('prism_creature_profile', function(){
            $user = isset($_GET['user']) ? sanitize_text_field((string)$_GET['user']) : '';
            $safe = esc_attr($user);
            return '<section class="prism-creature-profile"><h2>Creature Profile</h2><div id="prism-creature-profile-box">Loading profile...</div></section>'
                . '<style>.prism-creature-profile #prism-creature-profile-box{border:1px solid #5f6ad1;background:#0d1334;color:#eef2ff;padding:12px}.prism-creature-profile a{color:#9fd1ff}</style>'
                . '<script>(function(){const box=document.getElementById("prism-creature-profile-box");if(!box)return;const u="'.$safe.'"||new URLSearchParams(location.search).get("user")||"";if(!u){box.textContent="No user selected.";return;}fetch("/wp-json/prismtek/v1/creatures/profile?user="+encodeURIComponent(u)).then(r=>r.json()).then(j=>{if(!j.ok){box.textContent="Profile unavailable.";return;}const p=j.profile;box.innerHTML=`<strong>${p.displayName||p.user}</strong><div>${p.creature?.name||"Prismo"} · ${p.creature?.stage||"baby"}</div><div>Species ${p.creature?.species||"sprout"} · Personality ${p.creature?.personality||"brave"}</div><div>HP ${p.creature?.health||0} · Happy ${p.creature?.happiness||0}</div><div>Total Score ${p.totalScore||0}</div><div style=\"margin-top:8px\"><a href=\"/creature-directory/\">Back to Directory</a></div>`;}).catch(()=>box.textContent="Profile unavailable.");})();</script>';
        });
    }
});

// 5) Prism Creatures premium UI + battle v2 panel enhancements
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-creatures-premium-pass">
    (function(){
      const root=document.querySelector('.creature-v3, .pph-wrap');
      if(!root) return;

      // Add action icons/polish labels once
      const map=[['pph-pet-feed','🍖 Feed'],['pph-pet-play','🎾 Play'],['pph-pet-rest','🛌 Rest'],['pph-pet-train','🧠 Train (+XP)'],['pph-pet-spar','⚔️ Spar Battle']];
      map.forEach(([id,label])=>{const b=document.getElementById(id); if(b && !b.dataset.iconized){b.textContent=label;b.dataset.iconized='1';}});

      // Inject battle v2 panel
      if(!document.getElementById('prism-battle-v2-panel')){
        const status=document.getElementById('pph-pet-status');
        const host=status ? status.parentElement : root;
        const panel=document.createElement('details');
        panel.id='prism-battle-v2-panel'; panel.open=true;
        panel.innerHTML=`<summary><strong>Battle Arena v2</strong></summary>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
            <button type="button" id="prism-bv2-start">Start Battle</button>
            <button type="button" id="prism-bv2-rank">Load Rankings</button>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:repeat(4,1fr); margin-top:8px;">
            <button type="button" data-m="strike" class="prism-bv2-m">Strike</button>
            <button type="button" data-m="guard" class="prism-bv2-m">Guard</button>
            <button type="button" data-m="charge" class="prism-bv2-m">Charge</button>
            <button type="button" data-m="heal" class="prism-bv2-m">Heal</button>
          </div>
          <div id="prism-bv2-state" style="margin-top:8px;font-size:12px;color:#e6ecff">No active battle.</div>
          <pre id="prism-bv2-log" style="white-space:pre-wrap;background:#0d1334;border:1px solid #5f6ad1;padding:8px;max-height:180px;overflow:auto;margin-top:8px;">Battle log will appear here.</pre>
          <div id="prism-bv2-ranks" style="margin-top:8px;font-size:12px"></div>`;
        host.insertBefore(panel,status);
      }

      function hdr(){
        const n=document.querySelector('meta[name="rest-nonce"]')?.content || '';
        return n ? {'content-type':'application/json','X-WP-Nonce':n} : {'content-type':'application/json'};
      }
      async function jpost(url,payload){ const r=await fetch(url,{method:'POST',credentials:'include',headers:hdr(),body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      function renderState(s, rating){
        const box=document.getElementById('prism-bv2-state'); const log=document.getElementById('prism-bv2-log');
        if(!box||!log||!s){ return; }
        box.textContent=`Round ${s.round||1} · You ${s.player?.hp||0}/${s.player?.maxHp||0} HP · Enemy ${s.enemy?.hp||0}/${s.enemy?.maxHp||0} HP · Rating ${rating||'-'} ${s.done?('· '+(s.result||'done').toUpperCase()):''}`;
        log.textContent=(s.log||[]).join('\n');
      }

      document.getElementById('prism-bv2-start')?.addEventListener('click', async ()=>{
        const out=await jpost('/wp-json/prismtek/v1/pet/battle-v2/start',{});
        if(!out.ok){alert('Battle start failed');return;}
        renderState(out.j.state, out.j.rating);
      });

      document.querySelectorAll('.prism-bv2-m').forEach(btn=>btn.addEventListener('click', async ()=>{
        const m=btn.getAttribute('data-m');
        const out=await jpost('/wp-json/prismtek/v1/pet/battle-v2/move',{move:m});
        if(!out.ok){alert(out.j?.error || 'Move failed');return;}
        renderState(out.j.state, out.j.rating);
      }));

      document.getElementById('prism-bv2-rank')?.addEventListener('click', async ()=>{
        const box=document.getElementById('prism-bv2-ranks');
        const r=await fetch('/wp-json/prismtek/v1/pet/battle-v2/rankings',{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        if(!r.ok||!j.ok){ box.textContent='Rankings unavailable.'; return; }
        box.innerHTML=(j.rows||[]).slice(0,10).map((x,i)=>`${i+1}. ${x.displayName||x.user} — ${x.rating} (${x.wins||0}-${x.losses||0})`).join('<br>');
      });

      // quick links to new pages
      if(!document.getElementById('prism-creature-links')){
        const container=document.createElement('div');
        container.id='prism-creature-links';
        container.style.marginTop='10px';
        container.innerHTML='<a href="/creature-directory/">Creature Directory</a> · <a href="/build-log/">Build Log</a>';
        const status=document.getElementById('pph-pet-status');
        if(status) status.parentElement.appendChild(container);
      }
    })();
    </script>
    <style id="prism-creatures-premium-css">
      #prism-battle-v2-panel{border:1px solid #5f6ad1;background:#0d1334;padding:8px;margin-top:10px}
      #prism-battle-v2-panel summary{cursor:pointer;color:#dfe5ff}
      #prism-creature-links a{color:#9fd1ff;text-decoration:none}
      #prism-creature-links a:hover{text-decoration:underline}
    </style>
    <?php
}, 5000000);

// ===== Battle v2 visual polish (cooldown chips + turn indicator + mobile compact) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-battlev2-visual-polish">
    (function(){
      function ensureUI(){
        const panel=document.getElementById('prism-battle-v2-panel');
        if(!panel) return null;
        if(!document.getElementById('prism-bv2-turn')){
          const turn=document.createElement('div');
          turn.id='prism-bv2-turn';
          turn.textContent='TURN: READY';
          turn.style.cssText='margin-top:8px;padding:6px 8px;border:1px solid #5f6ad1;background:#101a46;color:#dff3ff;font-size:12px;font-weight:700;letter-spacing:.03em';
          panel.appendChild(turn);
        }
        if(!document.getElementById('prism-bv2-cd')){
          const cd=document.createElement('div');
          cd.id='prism-bv2-cd';
          cd.style.cssText='margin-top:8px;display:flex;gap:6px;flex-wrap:wrap';
          cd.innerHTML='<span class="prism-chip" data-k="heal">HEAL CD: 0</span><span class="prism-chip" data-k="charge">CHARGE CD: 0</span>';
          panel.appendChild(cd);
        }
        return panel;
      }

      function setTurn(text, busy){
        const el=document.getElementById('prism-bv2-turn');
        if(!el) return;
        el.textContent='TURN: '+text;
        el.style.background=busy ? '#3b1f2a' : '#101a46';
        el.style.borderColor=busy ? '#b35b7a' : '#5f6ad1';
      }

      function setCooldowns(state){
        const row=document.getElementById('prism-bv2-cd');
        if(!row || !state) return;
        const cds=(state.player && state.player.cooldowns) ? state.player.cooldowns : {};
        row.querySelectorAll('.prism-chip').forEach(ch=>{
          const k=ch.getAttribute('data-k');
          const v=Math.max(0,Number(cds[k]||0));
          ch.textContent=(k||'').toUpperCase()+' CD: '+v;
          ch.style.opacity=v>0?'1':'0.85';
          ch.style.borderColor=v>0?'#ffb36b':'#5f6ad1';
          ch.style.color=v>0?'#ffe6c2':'#dbe8ff';
          ch.style.background=v>0?'#3a2513':'#101a46';
        });
      }

      function patchFetch(){
        if(window.__prismBv2FetchPatched) return;
        window.__prismBv2FetchPatched=true;
        const orig=window.fetch;
        window.fetch=async function(input, init){
          const url=typeof input==='string'?input:(input&&input.url)||'';
          const isMove=url.includes('/pet/battle-v2/move');
          const isStart=url.includes('/pet/battle-v2/start');
          if(isMove) setTurn('RESOLVING...', true);
          const res=await orig.apply(this, arguments);
          try{
            if(isMove || isStart){
              const clone=res.clone();
              const j=await clone.json();
              if(j && j.state){
                setCooldowns(j.state);
                if(j.state.done){
                  setTurn((j.state.result||'DONE').toUpperCase(), false);
                } else {
                  setTurn('READY', false);
                }
              }
            }
          }catch{}
          return res;
        };
      }

      function run(){
        const p=ensureUI();
        if(!p) return;
        patchFetch();
        setTurn('READY', false);
      }

      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run);
      else run();
      setTimeout(run, 500);
    })();
    </script>
    <style id="prism-battlev2-visual-polish-css">
      #prism-bv2-panel, #prism-battle-v2-panel{box-shadow:inset 0 0 0 1px rgba(168,184,255,.15)}
      #prism-bv2-cd .prism-chip{border:1px solid #5f6ad1;padding:4px 8px;font-size:11px;border-radius:999px;background:#101a46;color:#dbe8ff}
      #prism-bv2-log{border-radius:8px}
      @media (max-width:720px){
        #prism-battle-v2-panel .pph-tool-row{grid-template-columns:1fr 1fr !important}
        #prism-battle-v2-panel .pph-tool-row .prism-bv2-m:nth-child(odd){margin-right:2px}
        #prism-battle-v2-panel .pph-tool-row .prism-bv2-m:nth-child(even){margin-left:2px}
        #prism-bv2-log{max-height:140px !important}
      }
    </style>
    <?php
}, 6000000);

// ===== PixelLab BYOK integration (per-user key, usage-rules aware) =====
if (!function_exists('prismtek_pixellab_encrypt')) {
    function prismtek_pixellab_encrypt($plain) {
        $plain = (string)$plain;
        if ($plain === '') return '';
        $method = 'AES-256-CBC';
        $key = hash('sha256', (string)AUTH_KEY, true);
        $iv = substr(hash('sha256', (string)SECURE_AUTH_KEY, true), 0, 16);
        $enc = openssl_encrypt($plain, $method, $key, 0, $iv);
        return is_string($enc) ? $enc : '';
    }
    function prismtek_pixellab_decrypt($enc) {
        $enc = (string)$enc;
        if ($enc === '') return '';
        $method = 'AES-256-CBC';
        $key = hash('sha256', (string)AUTH_KEY, true);
        $iv = substr(hash('sha256', (string)SECURE_AUTH_KEY, true), 0, 16);
        $plain = openssl_decrypt($enc, $method, $key, 0, $iv);
        return is_string($plain) ? $plain : '';
    }
    function prismtek_pixellab_mask($token) {
        $t = trim((string)$token);
        if ($t === '') return '';
        if (stripos($t, 'Bearer ') === 0) $t = trim(substr($t, 7));
        if (strlen($t) <= 8) return str_repeat('*', strlen($t));
        return substr($t, 0, 4) . str_repeat('*', max(4, strlen($t)-8)) . substr($t, -4);
    }
    function prismtek_pixellab_normalize_token($token) {
        $t = trim((string)$token);
        if ($t === '') return '';
        if (stripos($t, 'Bearer ') === 0) return 'Bearer ' . trim(substr($t, 7));
        return 'Bearer ' . $t;
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1', '/pixellab/status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            $enc = (string)get_user_meta($uid, 'prismtek_pixellab_key_enc', true);
            $token = $enc ? prismtek_pixellab_decrypt($enc) : '';
            $email = (string)get_user_meta($uid, 'prismtek_pixellab_email', true);
            $acceptedAt = (int)get_user_meta($uid, 'prismtek_pixellab_rules_ts', true);
            return rest_ensure_response([
                'ok'=>true,
                'connected' => $token !== '',
                'maskedKey' => prismtek_pixellab_mask($token),
                'email' => $email,
                'rulesAcceptedAt' => $acceptedAt,
                'links' => [
                    'mcp' => 'https://www.pixellab.ai/mcp',
                    'api' => 'https://www.pixellab.ai/pixellab-api',
                    'site' => 'https://www.pixellab.ai/',
                ],
            ]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pixellab/connect', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $r) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);

            $token = prismtek_pixellab_normalize_token((string)$r->get_param('apiKey'));
            $email = sanitize_email((string)$r->get_param('email'));
            $accepted = (bool)$r->get_param('acceptedUsageRules');

            if (!$accepted) return new WP_REST_Response(['ok'=>false,'error'=>'must_accept_usage_rules'], 400);
            if ($token === 'Bearer ' || strlen($token) < 20) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_api_key'], 400);

            update_user_meta($uid, 'prismtek_pixellab_key_enc', prismtek_pixellab_encrypt($token));
            update_user_meta($uid, 'prismtek_pixellab_email', $email);
            update_user_meta($uid, 'prismtek_pixellab_rules_ts', time());

            return rest_ensure_response(['ok'=>true, 'connected'=>true, 'maskedKey'=>prismtek_pixellab_mask($token)]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pixellab/disconnect', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            delete_user_meta($uid, 'prismtek_pixellab_key_enc');
            delete_user_meta($uid, 'prismtek_pixellab_email');
            delete_user_meta($uid, 'prismtek_pixellab_rules_ts');
            return rest_ensure_response(['ok'=>true, 'connected'=>false]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pixellab/prompt-template', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'], 401);
            $pet = function_exists('prismtek_pet_get_state') ? prismtek_pet_get_state($uid) : [];
            if (!is_array($pet)) $pet = [];
            $name = sanitize_text_field((string)($pet['name'] ?? 'Prismo'));
            $species = sanitize_text_field((string)($pet['species'] ?? 'sprout'));
            $personality = sanitize_text_field((string)($pet['personality'] ?? 'brave'));
            $skin = sanitize_text_field((string)($pet['skin'] ?? 'default'));
            $stage = function_exists('prismtek_pet_compute_stage') ? prismtek_pet_compute_stage($pet) : 'baby';

            $prompt = "Create a pixel-art creature sprite sheet for Prism Creatures.\\n"
                . "Character: {$name}\\n"
                . "Species: {$species}\\n"
                . "Personality: {$personality}\\n"
                . "Stage: {$stage}\\n"
                . "Palette mood: {$skin}\\n"
                . "Requirements: transparent background, crisp pixel edges, 4x4 sheet, 96x80 frames.\\n"
                . "Rows: idle / walk / run / attack.\\n"
                . "No copyrighted characters, no explicit/adult content, no hateful/violent extremism.";

            return rest_ensure_response(['ok'=>true,'prompt'=>$prompt]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page')) return;
    $onAccount = is_page('my-account');
    $onCreatures = is_page('prism-creatures');
    if (!$onAccount && !$onCreatures) return;
    ?>
    <script id="prism-pixellab-byok-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content || '';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const onAccount=location.pathname.includes('/my-account');
      const onCreatures=location.pathname.includes('/prism-creatures');

      async function status(){
        const r=await fetch(API+'pixellab/status',{credentials:'include'});return await r.json();
      }
      async function getPrompt(){
        const r=await fetch(API+'pixellab/prompt-template',{credentials:'include'});return await r.json();
      }

      function accountCard(){
        const host=document.querySelector('.pph-wrap'); if(!host) return;
        const card=document.createElement('article'); card.className='pph-card';
        card.innerHTML=`<h4>PixelLab AI Connect (BYOK)</h4>
          <p style="font-size:12px">Use your own PixelLab account/API key. We do not use one shared key for all users.</p>
          <p><a href="https://www.pixellab.ai/" target="_blank" rel="noopener">Create PixelLab account</a> · <a href="https://www.pixellab.ai/pixellab-api" target="_blank" rel="noopener">API docs</a> · <a href="https://www.pixellab.ai/mcp" target="_blank" rel="noopener">MCP docs</a></p>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pl-email" type="email" placeholder="PixelLab account email (optional)" /><input id="pl-key" type="password" placeholder="PixelLab API key / Bearer token" /></div>
          <label style="display:block;margin-top:6px"><input id="pl-accept" type="checkbox" /> I agree to follow PixelLab usage rules and policy.</label>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pl-connect" type="button">Connect PixelLab</button><button id="pl-disconnect" type="button">Disconnect</button></div>
          <p id="pl-status" class="pph-status">Checking status...</p>`;
        host.appendChild(card);

        const st=card.querySelector('#pl-status');
        function set(t){st.textContent=t;}

        status().then(s=>{
          if(!s.ok){set('Unable to load PixelLab status.');return;}
          set(s.connected?`Connected: ${s.maskedKey||'yes'}`:'Not connected yet.');
          card.querySelector('#pl-email').value=s.email||'';
        }).catch(()=>set('Unable to load PixelLab status.'));

        card.querySelector('#pl-connect').addEventListener('click', async ()=>{
          set('Connecting...');
          const payload={
            email: card.querySelector('#pl-email').value.trim(),
            apiKey: card.querySelector('#pl-key').value.trim(),
            acceptedUsageRules: !!card.querySelector('#pl-accept').checked,
          };
          const r=await fetch(API+'pixellab/connect',{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload)});
          const j=await r.json().catch(()=>({}));
          if(!r.ok){set('Connect failed: '+(j.error||'unknown'));return;}
          set('Connected: '+(j.maskedKey||'ok'));
          card.querySelector('#pl-key').value='';
        });

        card.querySelector('#pl-disconnect').addEventListener('click', async ()=>{
          set('Disconnecting...');
          const r=await fetch(API+'pixellab/disconnect',{method:'POST',credentials:'include',headers:H,body:'{}'});
          const j=await r.json().catch(()=>({}));
          if(!r.ok){set('Disconnect failed.');return;}
          set('Disconnected.');
        });
      }

      function creaturesCard(){
        const host=document.querySelector('.pph-wrap'); if(!host) return;
        const card=document.createElement('article'); card.className='pph-card';
        card.innerHTML=`<h4>Create Creature with PixelLab</h4>
          <p style="font-size:12px">Generate your own creature art in PixelLab with your own account/API key.</p>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pl-open" type="button">Open PixelLab</button><button id="pl-copy" type="button">Copy Creature Prompt</button><button id="pl-status-btn" type="button">Check Connection</button></div>
          <p id="pl-creature-status" class="pph-status">Ready.</p>`;
        host.appendChild(card);
        const st=card.querySelector('#pl-creature-status');
        const set=t=>st.textContent=t;

        card.querySelector('#pl-open').addEventListener('click',()=>window.open('https://www.pixellab.ai/','_blank','noopener'));
        card.querySelector('#pl-status-btn').addEventListener('click', async ()=>{
          set('Checking...');
          const s=await status().catch(()=>({ok:false}));
          if(!s.ok){set('Unable to check status.');return;}
          set(s.connected?`Connected (${s.maskedKey||'key'})`:'Not connected. Go to My Account to connect your own key.');
        });
        card.querySelector('#pl-copy').addEventListener('click', async ()=>{
          set('Preparing prompt...');
          const p=await getPrompt().catch(()=>({ok:false}));
          if(!p.ok||!p.prompt){set('Could not generate prompt template.');return;}
          try{await navigator.clipboard.writeText(p.prompt); set('Prompt copied. Paste into PixelLab.');}
          catch{set('Copy failed.');}
        });
      }

      if(onAccount) accountCard();
      if(onCreatures) creaturesCard();
    })();
    </script>
    <style id="prism-pixellab-byok-css">
      .pph-card a{color:#9fd1ff}
    </style>
    <?php
}, 7000000);

// ===== PixelLab direct generation on Prism Creatures (BYOK, server-proxy) =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1', '/pixellab/generate', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $r){
            $uid = get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

            $enc = (string)get_user_meta($uid, 'prismtek_pixellab_key_enc', true);
            $token = $enc ? prismtek_pixellab_decrypt($enc) : '';
            if ($token === '') return new WP_REST_Response(['ok'=>false,'error'=>'pixellab_not_connected'],400);

            $accepted = (int)get_user_meta($uid, 'prismtek_pixellab_rules_ts', true);
            if ($accepted <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'must_accept_usage_rules'],400);

            $model = sanitize_key((string)$r->get_param('model'));
            if(!in_array($model,['pixflux','bitforge'],true)) $model='pixflux';
            $prompt = trim((string)$r->get_param('prompt'));
            if($prompt==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_prompt'],400);
            $w = max(16,min($model==='pixflux'?400:200,(int)$r->get_param('width')));
            $h = max(16,min($model==='pixflux'?400:200,(int)$r->get_param('height')));
            $noBg = (bool)$r->get_param('noBackground');

            $endpoint = $model==='pixflux' ? 'https://api.pixellab.ai/v1/generate-image-pixflux' : 'https://api.pixellab.ai/v1/generate-image-bitforge';
            $payload = [
                'description' => $prompt,
                'image_size' => ['width'=>$w,'height'=>$h],
                'no_background' => $noBg,
            ];

            $resp = wp_remote_post($endpoint, [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]);
            if (is_wp_error($resp)) return new WP_REST_Response(['ok'=>false,'error'=>'pixellab_request_failed'],502);

            $code = (int)wp_remote_retrieve_response_code($resp);
            $body = (string)wp_remote_retrieve_body($resp);
            $j = json_decode($body,true);
            if($code<200 || $code>=300 || !is_array($j)) {
                return new WP_REST_Response(['ok'=>false,'error'=>'pixellab_error','status'=>$code,'detail'=>$body],502);
            }

            $b64 = (string)($j['image']['base64'] ?? '');
            if($b64==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_image_payload'],502);
            $bin = base64_decode($b64, true);
            if($bin===false || strlen($bin)<100) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_image_payload'],502);

            $up = wp_upload_dir();
            $dir = trailingslashit($up['basedir']).'prismtek-creatures/generated';
            if(!wp_mkdir_p($dir)) return new WP_REST_Response(['ok'=>false,'error'=>'storage_unavailable'],500);
            $name = 'pixellab-'.$uid.'-'.time().'-'.wp_generate_password(4,false,false).'.png';
            $path = trailingslashit($dir).$name;
            file_put_contents($path,$bin);
            @chmod($path,0644);
            $url = trailingslashit($up['baseurl']).'prismtek-creatures/generated/'.$name;

            // keep last generation pointer
            update_user_meta($uid,'prismtek_pixellab_last_image',$url);

            return rest_ensure_response([
                'ok'=>true,
                'imageUrl'=>$url,
                'usageUsd'=>(float)($j['usage']['usd'] ?? 0),
                'model'=>$model,
                'width'=>$w,
                'height'=>$h,
            ]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pixellab/use-image', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $r){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $url = esc_url_raw((string)$r->get_param('imageUrl'));
            if($url==='') $url = (string)get_user_meta($uid,'prismtek_pixellab_last_image',true);
            if($url==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_image'],400);
            update_user_meta($uid,'prismtek_pet_generated_image',$url);
            return rest_ensure_response(['ok'=>true,'imageUrl'=>$url]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page')) return;
    if (!is_page('my-account') && !is_page('prism-creatures')) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <script id="prism-pixellab-direct-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':nonce};
      const onAccount=location.pathname.includes('/my-account');
      const onCreatures=location.pathname.includes('/prism-creatures');

      function removeLegacy(){
        document.querySelectorAll('.pph-card h4').forEach(h=>{
          const t=(h.textContent||'').toLowerCase();
          if(t.includes('pixellab ai connect')||t.includes('create creature with pixellab')){
            const card=h.closest('.pph-card'); if(card) card.remove();
          }
        });
      }
      async function jget(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
      async function jpost(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      function mountAccount(){
        const host=document.querySelector('.pph-wrap'); if(!host) return;
        const card=document.createElement('article'); card.className='pph-card';
        card.innerHTML=`<h4>PixelLab Account Link (BYOK)</h4>
          <p style="font-size:12px">Create your PixelLab account, then connect your own API key here. Image generation runs on prismtek.dev using your key.</p>
          <p><a href="https://www.pixellab.ai/" target="_blank" rel="noopener">Create PixelLab account</a> · <a href="https://www.pixellab.ai/pixellab-api" target="_blank" rel="noopener">Get API key</a> · <a href="https://www.pixellab.ai/mcp" target="_blank" rel="noopener">Usage rules/docs</a></p>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pl-email2" type="email" placeholder="PixelLab email (optional)" /><input id="pl-key2" type="password" placeholder="PixelLab API key or Bearer token" /></div>
          <label style="display:block;margin-top:6px"><input id="pl-accept2" type="checkbox" /> I accept PixelLab usage rules/policy.</label>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="pl-connect2" type="button">Connect</button><button id="pl-disconnect2" type="button">Disconnect</button></div>
          <p id="pl-status2" class="pph-status">Checking...</p>`;
        host.appendChild(card);
        const st=card.querySelector('#pl-status2');
        const set=t=>st.textContent=t;
        jget('pixellab/status').then(({ok,j})=>{ if(!ok||!j.ok){set('Status unavailable.');return;} card.querySelector('#pl-email2').value=j.email||''; set(j.connected?`Connected (${j.maskedKey||'key'})`:'Not connected.'); }).catch(()=>set('Status unavailable.'));
        card.querySelector('#pl-connect2').addEventListener('click', async ()=>{
          set('Connecting...');
          const out=await jpost('pixellab/connect',{email:card.querySelector('#pl-email2').value.trim(),apiKey:card.querySelector('#pl-key2').value.trim(),acceptedUsageRules:!!card.querySelector('#pl-accept2').checked});
          if(!out.ok){set('Connect failed: '+(out.j.error||'unknown'));return;}
          card.querySelector('#pl-key2').value='';
          set('Connected: '+(out.j.maskedKey||'ok'));
        });
        card.querySelector('#pl-disconnect2').addEventListener('click', async ()=>{ set('Disconnecting...'); const out=await jpost('pixellab/disconnect',{}); set(out.ok?'Disconnected.':'Disconnect failed.'); });
      }

      function mountCreatures(){
        const host=document.querySelector('.pph-wrap'); if(!host) return;
        const card=document.createElement('article'); card.className='pph-card';
        card.innerHTML=`<h4>PixelLab Creature Generator (on-site)</h4>
          <p style="font-size:12px">Generate creature art directly on prismtek.dev using your connected PixelLab key.</p>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><select id="pl-model"><option value="pixflux">Pixflux</option><option value="bitforge">Bitforge</option></select><input id="pl-w" type="number" min="32" max="400" value="128" placeholder="Width" /><input id="pl-h" type="number" min="32" max="400" value="128" placeholder="Height" /></div>
          <textarea id="pl-prompt" rows="5" style="width:100%;margin-top:6px" placeholder="Describe your Prism Creature..."></textarea>
          <label style="display:block;margin-top:6px"><input id="pl-nobg" type="checkbox" checked /> Transparent background</label>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><button id="pl-prompt-fill" type="button">Use Creature Prompt</button><button id="pl-generate" type="button">Generate</button><button id="pl-use" type="button">Use as Creature Artwork</button></div>
          <p id="pl-gen-status" class="pph-status">Ready.</p>
          <div id="pl-preview-wrap" style="display:none;margin-top:8px"><img id="pl-preview" alt="Generated creature" style="max-width:220px;image-rendering:pixelated;border:1px solid #5f6ad1;background:#0d1334"/></div>`;
        host.appendChild(card);
        const st=card.querySelector('#pl-gen-status'),img=card.querySelector('#pl-preview');
        let lastUrl='';
        const set=t=>st.textContent=t;

        card.querySelector('#pl-prompt-fill').addEventListener('click', async ()=>{
          set('Loading prompt template...');
          const out=await jget('pixellab/prompt-template');
          if(!out.ok||!out.j.ok){set('Could not load prompt template.');return;}
          card.querySelector('#pl-prompt').value=out.j.prompt||'';
          set('Prompt loaded.');
        });

        card.querySelector('#pl-generate').addEventListener('click', async ()=>{
          set('Generating (PixelLab)...');
          const payload={
            model: card.querySelector('#pl-model').value,
            prompt: card.querySelector('#pl-prompt').value.trim(),
            width: Number(card.querySelector('#pl-w').value||128),
            height: Number(card.querySelector('#pl-h').value||128),
            noBackground: !!card.querySelector('#pl-nobg').checked,
          };
          const out=await jpost('pixellab/generate',payload);
          if(!out.ok){ set('Generate failed: '+(out.j.error||'unknown')); return; }
          lastUrl=out.j.imageUrl||'';
          if(lastUrl){ img.src=lastUrl+'?v='+Date.now(); card.querySelector('#pl-preview-wrap').style.display='block'; }
          set(`Generated (${out.j.model||''}) · $${(out.j.usageUsd||0).toFixed(4)}`);
        });

        card.querySelector('#pl-use').addEventListener('click', async ()=>{
          if(!lastUrl){ set('Generate an image first.'); return; }
          set('Applying artwork...');
          const out=await jpost('pixellab/use-image',{imageUrl:lastUrl});
          if(!out.ok){ set('Apply failed.'); return; }
          set('Creature artwork applied.');
        });

        jget('pixellab/status').then(({ok,j})=>{
          if(!ok||!j.ok){ set('Connect PixelLab in My Account first.'); return; }
          if(!j.connected) set('Not connected. Go to My Account → PixelLab Link.');
          else set('Connected. Ready to generate.');
        }).catch(()=>set('Status check failed.'));
      }

      removeLegacy();
      if(onAccount) mountAccount();
      if(onCreatures) mountCreatures();
    })();
    </script>
    <?php
}, 8000000);

// ===== PixelLab token-efficient starter pack generation (consistent style) =====
if (!function_exists('prismtek_pixellab_request_raw')) {
    function prismtek_pixellab_request_raw($token, $endpoint, $payload) {
        $resp = wp_remote_post($endpoint, [
            'timeout' => 150,
            'headers' => [
                'Authorization' => (string)$token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($resp)) {
            return ['ok'=>false, 'error'=>'network_error'];
        }
        $code = (int)wp_remote_retrieve_response_code($resp);
        $body = (string)wp_remote_retrieve_body($resp);
        $j = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($j)) {
            return ['ok'=>false, 'error'=>'api_error', 'status'=>$code, 'detail'=>$body];
        }
        return ['ok'=>true, 'json'=>$j];
    }

    function prismtek_pixellab_save_base64($uid, $slug, $b64) {
        $bin = base64_decode((string)$b64, true);
        if ($bin === false || strlen($bin) < 100) return ['ok'=>false, 'error'=>'invalid_image'];

        $up = wp_upload_dir();
        $baseDir = trailingslashit($up['basedir']).'prismtek-creatures/pixellab/'.(int)$uid;
        $baseUrl = trailingslashit($up['baseurl']).'prismtek-creatures/pixellab/'.(int)$uid;
        if (!wp_mkdir_p($baseDir)) return ['ok'=>false, 'error'=>'storage_unavailable'];

        $name = sanitize_file_name($slug).'-'.time().'.png';
        $path = trailingslashit($baseDir).$name;
        file_put_contents($path, $bin);
        @chmod($path, 0644);
        return ['ok'=>true, 'path'=>$path, 'url'=>trailingslashit($baseUrl).$name];
    }

    function prismtek_pixellab_make_prompt($kind, $species='', $stage='') {
        $base = 'pixel-art, clean silhouette, high readability, game-ready, centered subject, transparent background, no text, no watermark';
        if ($kind === 'style-anchor') {
            return 'Create a style anchor sprite for Prism Creatures: vibrant retro-futurist pixel creature mascot, chibi proportions, smooth shading bands, '.$base;
        }
        if ($kind === 'battle-scene') {
            return 'Create a side-view battle arena scene for a tamagotchi/pokemon/digimon crossover vibe: platform foreground, atmospheric midground, dramatic sky, readable combat space, no UI text, 16-bit pixel style.';
        }
        return "Create a {$stage} stage {$species} creature for Prism Creatures, with expressive face and iconic shape. {$base}";
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1', '/pixellab/starter-pack', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $r){
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

            $enc = (string)get_user_meta($uid, 'prismtek_pixellab_key_enc', true);
            $token = $enc ? prismtek_pixellab_decrypt($enc) : '';
            if ($token === '') return new WP_REST_Response(['ok'=>false,'error'=>'pixellab_not_connected'],400);
            if ((int)get_user_meta($uid,'prismtek_pixellab_rules_ts',true) <= 0) {
                return new WP_REST_Response(['ok'=>false,'error'=>'must_accept_usage_rules'],400);
            }

            $lite = (bool)$r->get_param('liteMode');
            $species = $lite ? ['sprout','ember'] : ['sprout','ember','tidal'];
            $stages = ['baby','teen','adult'];

            $manifest = [
                'version' => 1,
                'generatedAt' => time(),
                'liteMode' => $lite,
                'styleAnchor' => null,
                'battleScene' => null,
                'creatures' => [],
                'usageUsd' => 0,
            ];

            // 1) style anchor (bitforge, tiny)
            $anchorReq = [
                'description' => prismtek_pixellab_make_prompt('style-anchor'),
                'image_size' => ['width'=>96,'height'=>96],
                'no_background' => true,
            ];
            $anchorCall = prismtek_pixellab_request_raw($token, 'https://api.pixellab.ai/v1/generate-image-bitforge', $anchorReq);
            if (!$anchorCall['ok']) return new WP_REST_Response(['ok'=>false,'error'=>'style_anchor_failed','detail'=>$anchorCall],502);
            $anchorB64 = (string)($anchorCall['json']['image']['base64'] ?? '');
            $anchorSave = prismtek_pixellab_save_base64($uid, 'style-anchor', $anchorB64);
            if (!$anchorSave['ok']) return new WP_REST_Response(['ok'=>false,'error'=>'style_anchor_save_failed'],500);
            $manifest['styleAnchor'] = $anchorSave['url'];
            $manifest['usageUsd'] += (float)($anchorCall['json']['usage']['usd'] ?? 0);

            $styleImage = ['type'=>'base64', 'base64'=>$anchorB64];

            // 2) battle scene (pixflux)
            $battleReq = [
                'description' => prismtek_pixellab_make_prompt('battle-scene'),
                'image_size' => ['width'=>320,'height'=>180],
                'no_background' => false,
            ];
            $battleCall = prismtek_pixellab_request_raw($token, 'https://api.pixellab.ai/v1/generate-image-pixflux', $battleReq);
            if ($battleCall['ok']) {
                $battleB64 = (string)($battleCall['json']['image']['base64'] ?? '');
                $battleSave = prismtek_pixellab_save_base64($uid, 'battle-scene', $battleB64);
                if ($battleSave['ok']) $manifest['battleScene'] = $battleSave['url'];
                $manifest['usageUsd'] += (float)($battleCall['json']['usage']['usd'] ?? 0);
            }

            // 3) creatures assortment with consistent style via style_image
            foreach ($species as $sp) {
                foreach ($stages as $st) {
                    $req = [
                        'description' => prismtek_pixellab_make_prompt('creature', $sp, $st),
                        'image_size' => ['width'=>128,'height'=>128],
                        'no_background' => true,
                        'style_image' => $styleImage,
                        'style_strength' => 0.78,
                    ];
                    $call = prismtek_pixellab_request_raw($token, 'https://api.pixellab.ai/v1/generate-image-bitforge', $req);
                    if (!$call['ok']) {
                        $manifest['creatures'][] = ['species'=>$sp,'stage'=>$st,'ok'=>false];
                        continue;
                    }
                    $b64 = (string)($call['json']['image']['base64'] ?? '');
                    $save = prismtek_pixellab_save_base64($uid, 'creature-'.$sp.'-'.$st, $b64);
                    $manifest['usageUsd'] += (float)($call['json']['usage']['usd'] ?? 0);
                    if ($save['ok']) {
                        $manifest['creatures'][] = ['species'=>$sp,'stage'=>$st,'ok'=>true,'url'=>$save['url']];
                    } else {
                        $manifest['creatures'][] = ['species'=>$sp,'stage'=>$st,'ok'=>false];
                    }
                }
            }

            update_user_meta($uid, 'prismtek_pixellab_asset_manifest', $manifest);
            return rest_ensure_response(['ok'=>true,'manifest'=>$manifest]);
        }
    ]);

    register_rest_route('prismtek/v1', '/pixellab/assets', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(){
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $m = get_user_meta($uid, 'prismtek_pixellab_asset_manifest', true);
            if (!is_array($m)) $m = [];
            return rest_ensure_response(['ok'=>true,'manifest'=>$m]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <script id="prism-pixellab-starterpack-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const H={'content-type':'application/json','X-WP-Nonce':<?php echo wp_json_encode($nonce); ?>};
      const host=document.querySelector('.pph-wrap'); if(!host) return;

      const card=document.createElement('article'); card.className='pph-card';
      card.innerHTML=`<h4>PixelLab Starter Asset Pack</h4>
        <p style="font-size:12px">Generate a token-efficient consistent-style creature set (battle scene + growth stages) directly on prismtek.dev.</p>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
          <button id="pl-pack-lite" type="button">Generate Lite Pack</button>
          <button id="pl-pack-full" type="button">Generate Full Pack</button>
          <button id="pl-pack-refresh" type="button">Refresh Gallery</button>
        </div>
        <p id="pl-pack-status" class="pph-status">Ready.</p>
        <div id="pl-pack-gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px"></div>`;
      host.appendChild(card);

      const st=card.querySelector('#pl-pack-status'), gal=card.querySelector('#pl-pack-gallery');
      const set=t=>st.textContent=t;
      async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
      async function get(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      function render(m){
        gal.innerHTML='';
        if(!m || !m.creatures){ gal.textContent='No generated assets yet.'; return; }
        if(m.battleScene){
          const a=document.createElement('a');a.href=m.battleScene;a.target='_blank';a.rel='noopener';a.innerHTML=`<img src="${m.battleScene}" style="width:100%;image-rendering:pixelated;border:1px solid #5f6ad1"><div style="font-size:11px">Battle Scene</div>`;gal.appendChild(a);
        }
        (m.creatures||[]).forEach(c=>{
          if(!c.url) return;
          const a=document.createElement('a');a.href=c.url;a.target='_blank';a.rel='noopener';a.innerHTML=`<img src="${c.url}" style="width:100%;image-rendering:pixelated;border:1px solid #5f6ad1"><div style="font-size:11px">${c.species} · ${c.stage}</div>`;gal.appendChild(a);
        });
      }

      async function refresh(){ set('Loading assets...'); const out=await get('pixellab/assets'); if(!out.ok||!out.j.ok){set('Could not load asset gallery.');return;} render(out.j.manifest||{}); const usd=Number(out.j.manifest?.usageUsd||0); set(out.j.manifest?.generatedAt?`Asset pack loaded · est $${usd.toFixed(4)}`:'No asset pack yet.'); }

      card.querySelector('#pl-pack-lite').addEventListener('click', async ()=>{
        set('Generating lite pack (token-efficient)...');
        const out=await post('pixellab/starter-pack',{liteMode:true});
        if(!out.ok){ set('Generate failed: '+(out.j.error||'unknown')); return; }
        render(out.j.manifest||{});
        set(`Lite pack done · est $${Number(out.j.manifest?.usageUsd||0).toFixed(4)}`);
      });

      card.querySelector('#pl-pack-full').addEventListener('click', async ()=>{
        set('Generating full pack (this can take a bit)...');
        const out=await post('pixellab/starter-pack',{liteMode:false});
        if(!out.ok){ set('Generate failed: '+(out.j.error||'unknown')); return; }
        render(out.j.manifest||{});
        set(`Full pack done · est $${Number(out.j.manifest?.usageUsd||0).toFixed(4)}`);
      });

      card.querySelector('#pl-pack-refresh').addEventListener('click', refresh);
      refresh();
    })();
    </script>
    <?php
}, 8100000);

// ===== Lock down starter-pack to admin + expose curated pack to all =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1', '/pixellab/starter-pack', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $r){
            if (!current_user_can('manage_options')) {
                return new WP_REST_Response(['ok'=>false,'error'=>'admin_only'],403);
            }
            // fallback to already-registered implementation by directly invoking generation endpoint if needed is not possible here.
            // This override simply protects against public use if route precedence hits this callback.
            return new WP_REST_Response(['ok'=>false,'error'=>'admin_only'],403);
        }
    ]);

    register_rest_route('prismtek/v1', '/creatures/curated-pack', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(){
            $m = get_option('prismtek_curated_assets', []);
            if (!is_array($m)) $m = [];
            return rest_ensure_response(['ok'=>true,'manifest'=>$m]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-curated-pack-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      function removeStarterPackCard(){
        document.querySelectorAll('.pph-card h4').forEach(h=>{
          const t=(h.textContent||'').toLowerCase();
          if(t.includes('starter asset pack')){
            const card=h.closest('.pph-card'); if(card) card.remove();
          }
        });
      }
      function mountCurated(manifest){
        const host=document.querySelector('.pph-wrap'); if(!host||!manifest) return;

        // battle scene application
        const battle=document.getElementById('prism-battle-v2-panel');
        const bUrl=manifest.battleSceneUrl || '';
        if(battle && bUrl){
          battle.style.backgroundImage=`linear-gradient(rgba(8,10,26,.75),rgba(8,10,26,.75)),url(${bUrl})`;
          battle.style.backgroundSize='cover';
          battle.style.backgroundPosition='center';
        }

        if(document.getElementById('prism-curated-dex')) return;
        const card=document.createElement('article'); card.className='pph-card'; card.id='prism-curated-dex';
        card.innerHTML=`<details open><summary><strong>Official Prism Creature Dex</strong></summary>
          <p style="font-size:12px">Admin-curated species and growth stages generated via PixelLab.</p>
          <div id="prism-curated-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px"></div>
        </details>`;
        host.appendChild(card);
        const grid=card.querySelector('#prism-curated-grid');
        const rows=manifest.creatures||[];
        grid.innerHTML=rows.map(c=>`<div style="border:1px solid #5f6ad1;background:#0d1334;padding:6px"><img src="${c.url}" style="width:100%;image-rendering:pixelated;border:1px solid #4a57b8"/><div style="font-size:11px;margin-top:4px">${c.species} · ${c.stage}</div></div>`).join('');
      }

      async function load(){
        removeStarterPackCard();
        const r=await fetch(API+'creatures/curated-pack',{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        if(!r.ok||!j.ok) return;
        mountCurated(j.manifest||{});
      }
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', load);
      else load();
      setTimeout(load,600);
    })();
    </script>
    <?php
}, 8200000);

// ===== Curated sprite wiring + tiered battle scenes + PvP showdown-lite =====
if (!function_exists('prismtek_curated_v2')) {
    function prismtek_curated_v2(){
        $m = get_option('prismtek_curated_assets_v2', []);
        return is_array($m) ? $m : [];
    }
    function prismtek_battle_tier($rating){
        $r=(int)$rating;
        if($r>=1250) return 'mythic';
        if($r>=1080) return 'champion';
        return 'rookie';
    }

    function prismtek_pvp_get_matches(){
        $m=get_option('prismtek_pvp_matches',[]);
        return is_array($m)?$m:[];
    }
    function prismtek_pvp_set_matches($m){
        if(!is_array($m)) $m=[];
        update_option('prismtek_pvp_matches',$m,false);
    }
    function prismtek_pvp_hp_from_uid($uid){
        $pet=function_exists('prismtek_pet_get_state')?prismtek_pet_get_state((int)$uid):[];
        $lvl=max(1,(int)($pet['level'] ?? 1));
        return 90 + $lvl*5;
    }
    function prismtek_pvp_user_tag($uid){
        $u=get_userdata((int)$uid);
        return $u ? (string)$u->user_login : ('u'.(int)$uid);
    }

    function prismtek_pvp_resolve_round(&$match){
        $a=$match['a']; $b=$match['b'];
        $ma=$match['moves'][$a] ?? null;
        $mb=$match['moves'][$b] ?? null;
        if(!$ma||!$mb) return;

        $log=[];
        $order=[[$a,$b,$ma],[$b,$a,$mb]];
        foreach($order as $turn){
            [$uid,$opp,$move]=$turn;
            if(($match['hp'][$uid] ?? 0)<=0 || ($match['hp'][$opp] ?? 0)<=0) continue;
            $cd=&$match['cd'][$uid];
            foreach($cd as $k=>$v) $cd[$k]=max(0,(int)$v-1);

            if($move==='strike'){
                $d=rand(12,22)+(int)($match['charge'][$uid] ?? 0);
                if(!empty($match['guard'][$opp])) $d=(int)floor($d*0.55);
                $match['hp'][$opp]=max(0,(int)$match['hp'][$opp]-$d);
                $match['charge'][$uid]=0;
                $log[] = prismtek_pvp_user_tag($uid)." used STRIKE for {$d}.";
            }elseif($move==='guard'){
                $match['guard'][$uid]=1;
                $log[] = prismtek_pvp_user_tag($uid)." used GUARD.";
            }elseif($move==='charge'){
                if((int)($cd['charge'] ?? 0)>0){
                    $log[] = prismtek_pvp_user_tag($uid)." tried CHARGE (cooldown).";
                }else{
                    $match['charge'][$uid]=min(14,(int)($match['charge'][$uid] ?? 0)+8);
                    $cd['charge']=2;
                    $log[] = prismtek_pvp_user_tag($uid)." used CHARGE.";
                }
            }elseif($move==='heal'){
                if((int)($cd['heal'] ?? 0)>0){
                    $log[] = prismtek_pvp_user_tag($uid)." tried HEAL (cooldown).";
                }else{
                    $h=rand(14,24);
                    $match['hp'][$uid]=min((int)$match['maxHp'][$uid], (int)$match['hp'][$uid]+$h);
                    $cd['heal']=3;
                    $log[] = prismtek_pvp_user_tag($uid)." healed {$h}.";
                }
            }
        }

        $match['guard'][$a]=0; $match['guard'][$b]=0;
        $match['round']=(int)$match['round']+1;
        $match['log']=array_slice(array_merge((array)$match['log'],$log),-30);
        $match['moves']=[];

        $ha=(int)$match['hp'][$a]; $hb=(int)$match['hp'][$b];
        if($ha<=0 || $hb<=0){
            $match['done']=true;
            if($ha==$hb) $winner=0;
            else $winner=$ha>$hb?$a:$b;
            $match['winner']=$winner;
            if($winner){
                $loser=$winner===$a?$b:$a;
                $rw=prismtek_battle_v2_rating($winner)+20;
                $rl=prismtek_battle_v2_rating($loser)-14;
                prismtek_battle_v2_set_rating($winner,$rw);
                prismtek_battle_v2_set_rating($loser,$rl);
                $match['log'][]='Winner: '.prismtek_pvp_user_tag($winner);
            }else{
                $match['log'][]='Draw.';
            }
        }
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/creatures/curated-pack-v2',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){ return rest_ensure_response(['ok'=>true,'manifest'=>prismtek_curated_v2()]); }
    ]);

    register_rest_route('prismtek/v1','/pet/battle-v2/me-tier',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $rating=prismtek_battle_v2_rating($uid);
            return rest_ensure_response(['ok'=>true,'rating'=>$rating,'tier'=>prismtek_battle_tier($rating)]);
        }
    ]);

    register_rest_route('prismtek/v1','/creatures/gallery-v2',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id();
            $m=prismtek_curated_v2();
            $official=[];
            foreach(($m['species'] ?? []) as $sp=>$stages){
                foreach(($stages ?: []) as $st=>$url){ $official[]=['species'=>$sp,'stage'=>$st,'url'=>$url]; }
            }
            $user=[];
            if($uid){
                $um=get_user_meta($uid,'prismtek_pixellab_asset_manifest',true);
                if(is_array($um) && !empty($um['creatures']) && is_array($um['creatures'])){
                    foreach($um['creatures'] as $c){ if(!empty($c['url'])) $user[]=['species'=>(string)($c['species']??'custom'),'stage'=>(string)($c['stage']??'custom'),'url'=>(string)$c['url']]; }
                }
                $last=(string)get_user_meta($uid,'prismtek_pet_generated_image',true);
                if($last!=='') $user[]=['species'=>'custom','stage'=>'latest','url'=>$last];
            }
            return rest_ensure_response(['ok'=>true,'official'=>$official,'user'=>$user]);
        }
    ]);

    // PvP showdown-lite
    register_rest_route('prismtek/v1','/pet/pvp/challenge',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $oppName=sanitize_text_field((string)$r->get_param('opponent'));
            if($oppName==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_opponent'],400);
            $opp=get_user_by('login',$oppName); if(!$opp) $opp=get_user_by('slug',$oppName);
            if(!$opp) return new WP_REST_Response(['ok'=>false,'error'=>'opponent_not_found'],404);
            $oid=(int)$opp->ID; if($oid===$uid) return new WP_REST_Response(['ok'=>false,'error'=>'cannot_challenge_self'],400);

            $matches=prismtek_pvp_get_matches();
            $id=wp_generate_uuid4();
            $maxA=prismtek_pvp_hp_from_uid($uid); $maxB=prismtek_pvp_hp_from_uid($oid);
            $matches[$id]=[
                'id'=>$id,'a'=>$uid,'b'=>$oid,'status'=>'pending','round'=>1,'done'=>false,'winner'=>0,
                'hp'=>[$uid=>$maxA,$oid=>$maxB],'maxHp'=>[$uid=>$maxA,$oid=>$maxB],
                'guard'=>[$uid=>0,$oid=>0],'charge'=>[$uid=>0,$oid=>0],
                'cd'=>[$uid=>['heal'=>0,'charge'=>0],$oid=>['heal'=>0,'charge'=>0]],
                'moves'=>[],'log'=>['Challenge created by '.prismtek_pvp_user_tag($uid)],'updatedAt'=>time(),
            ];
            prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'matchId'=>$id]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/accept',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['b']!==$uid && (int)$m['a']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $m['status']='active';
            $m['log'][]=prismtek_pvp_user_tag($uid).' accepted challenge.';
            $m['updatedAt']=time();
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>$m]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/state',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            return rest_ensure_response(['ok'=>true,'state'=>$m]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/move',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>$m]);
            if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);

            $m['moves'][$uid]=$move;
            $m['updatedAt']=time();
            prismtek_pvp_resolve_round($m);
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>$m,'rating'=>prismtek_battle_v2_rating($uid)]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-curated-pvp-ui-v2">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const host=document.querySelector('.pph-wrap'); if(!host) return;
      const card=document.createElement('article'); card.className='pph-card';
      card.innerHTML=`<h4>PvP Arena (Showdown-style Beta)</h4>
      <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;"><input id="pvp-user" placeholder="Opponent username"/><button id="pvp-challenge">Challenge</button><button id="pvp-load">Load Match</button></div>
      <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><input id="pvp-id" placeholder="Match ID"/><button id="pvp-accept">Accept</button></div>
      <div id="pvp-screen" style="margin-top:8px;border:1px solid #5f6ad1;background:#0d1334;padding:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;align-items:end">
        <div style="text-align:left"><img id="pvp-you" style="width:96px;image-rendering:pixelated"/><div id="pvp-you-hp">You HP</div></div>
        <div style="text-align:right"><img id="pvp-opp" style="width:96px;image-rendering:pixelated;transform:scaleX(-1)"/><div id="pvp-opp-hp">Opp HP</div></div>
      </div>
      <div class="pph-tool-row" style="grid-template-columns:repeat(4,1fr);margin-top:8px"><button class="pvp-m" data-m="strike">Strike</button><button class="pvp-m" data-m="guard">Guard</button><button class="pvp-m" data-m="charge">Charge</button><button class="pvp-m" data-m="heal">Heal</button></div>
      <pre id="pvp-log" style="margin-top:8px;max-height:160px;overflow:auto;background:#0a0f2b;border:1px solid #4f5aba;padding:8px;white-space:pre-wrap">No match loaded.</pre>
      <h4 style="margin-top:12px">Creature Gallery</h4>
      <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="gal-official">Official</button><button id="gal-user">User-generated</button></div>
      <div id="gal-grid" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px"></div>
      <p id="pvp-status" class="pph-status">Ready.</p>`;
      host.appendChild(card);
      const st=card.querySelector('#pvp-status');
      const set=t=>st.textContent=t;
      let matchId=localStorage.getItem('prism_pvp_match_id')||'';
      let curated={};

      async function g(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
      async function p(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      function tierFromRating(r){r=Number(r||1000); if(r>=1250) return 'mythic'; if(r>=1080) return 'champion'; return 'rookie';}
      function spriteFor(user, stage='baby'){
        const sp=(user?.creature?.species||'sprout');
        return (curated.species?.[sp]?.[stage]) || '';
      }

      async function loadCurated(){
        const out=await g('creatures/curated-pack-v2');
        if(out.ok&&out.j.ok) curated=out.j.manifest||{};
        const tierOut=await g('pet/battle-v2/me-tier');
        const tier=tierOut.ok&&tierOut.j.ok?tierOut.j.tier:'rookie';
        const bg=curated.battleScenes?.[tier];
        if(bg){
          const screen=card.querySelector('#pvp-screen');
          screen.style.backgroundImage=`linear-gradient(rgba(8,10,26,.4),rgba(8,10,26,.4)),url(${bg})`;
          screen.style.backgroundSize='cover';
          screen.style.backgroundPosition='center';
        }
      }

      function renderState(s){
        if(!s) return;
        const meId=s.a; // will be corrected below
        const uid = Number(window.__prism_uid||0);
        const you = uid===Number(s.a) ? s.a : s.b;
        const opp = you===s.a ? s.b : s.a;
        card.querySelector('#pvp-you-hp').textContent=`You HP ${s.hp?.[you]||0}/${s.maxHp?.[you]||0}`;
        card.querySelector('#pvp-opp-hp').textContent=`Opp HP ${s.hp?.[opp]||0}/${s.maxHp?.[opp]||0}`;
        // stage proxy from HP for dynamic look
        const youStage=(s.hp?.[you]||0)>(s.maxHp?.[you]||1)*0.66?'adult':((s.hp?.[you]||0)>(s.maxHp?.[you]||1)*0.33?'teen':'baby');
        const oppStage=(s.hp?.[opp]||0)>(s.maxHp?.[opp]||1)*0.66?'adult':((s.hp?.[opp]||0)>(s.maxHp?.[opp]||1)*0.33?'teen':'baby');
        const youSp = (window.__prism_self_species || 'sprout');
        const oppSp = (window.__prism_opp_species || 'ember');
        card.querySelector('#pvp-you').src=curated.species?.[youSp]?.[youStage]||'';
        card.querySelector('#pvp-opp').src=curated.species?.[oppSp]?.[oppStage]||'';
        card.querySelector('#pvp-log').textContent=(s.log||[]).join('\n');
      }

      async function loadMatch(){
        if(!matchId){set('Enter or create a match first.');return;}
        const out=await g('pet/pvp/state?matchId='+encodeURIComponent(matchId));
        if(!out.ok||!out.j.ok){set('Load failed: '+(out.j.error||'unknown'));return;}
        renderState(out.j.state);
        set('Match loaded.');
      }

      card.querySelector('#pvp-challenge').addEventListener('click', async ()=>{
        const opp=card.querySelector('#pvp-user').value.trim();
        if(!opp){set('Enter opponent username.');return;}
        set('Creating challenge...');
        const out=await p('pet/pvp/challenge',{opponent:opp});
        if(!out.ok){set('Challenge failed: '+(out.j.error||'unknown'));return;}
        matchId=out.j.matchId||''; card.querySelector('#pvp-id').value=matchId; localStorage.setItem('prism_pvp_match_id',matchId); set('Challenge created. Share Match ID.');
      });

      card.querySelector('#pvp-accept').addEventListener('click', async ()=>{
        matchId=card.querySelector('#pvp-id').value.trim()||matchId;
        if(!matchId){set('No match id.');return;}
        set('Accepting...');
        const out=await p('pet/pvp/accept',{matchId});
        if(!out.ok){set('Accept failed: '+(out.j.error||'unknown'));return;}
        localStorage.setItem('prism_pvp_match_id',matchId); renderState(out.j.state); set('Accepted.');
      });

      card.querySelector('#pvp-load').addEventListener('click', async ()=>{
        const typed=card.querySelector('#pvp-id').value.trim(); if(typed) matchId=typed;
        await loadMatch();
      });

      card.querySelectorAll('.pvp-m').forEach(btn=>btn.addEventListener('click', async ()=>{
        if(!matchId){set('No active match.');return;}
        const m=btn.getAttribute('data-m');
        set('Submitting move...');
        const out=await p('pet/pvp/move',{matchId,move:m});
        if(!out.ok){set('Move failed: '+(out.j.error||'unknown'));return;}
        renderState(out.j.state); set('Move submitted.');
      }));

      async function loadGallery(kind='official'){
        const out=await g('creatures/gallery-v2');
        if(!out.ok||!out.j.ok){set('Gallery unavailable.');return;}
        const rows=kind==='official'?(out.j.official||[]):(out.j.user||[]);
        const grid=card.querySelector('#gal-grid');
        grid.innerHTML = rows.length ? rows.map(c=>`<div style="border:1px solid #5f6ad1;background:#0d1334;padding:6px"><img src="${c.url}" style="width:100%;image-rendering:pixelated;border:1px solid #4a57b8"/><div style="font-size:11px;margin-top:4px">${c.species} · ${c.stage}</div></div>`).join('') : '<div style="font-size:12px;color:#d7ddff">No entries yet.</div>';
      }
      card.querySelector('#gal-official').addEventListener('click',()=>loadGallery('official'));
      card.querySelector('#gal-user').addEventListener('click',()=>loadGallery('user'));

      loadCurated().then(()=>{loadGallery('official'); if(matchId) card.querySelector('#pvp-id').value=matchId;});
    })();
    </script>
    <style id="prism-curated-pvp-css">
      #pvp-screen img{filter:drop-shadow(0 2px 0 rgba(0,0,0,.45))}
      @media (max-width:760px){#pvp-screen{grid-template-columns:1fr !important;gap:4px !important;text-align:center}}
    </style>
    <?php
}, 8300000);

// ===== Curated evolution sprite overlay on creature panel =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $uid = get_current_user_id();
    ?>
    <script id="prism-curated-evolution-overlay">
    window.__prism_uid = <?php echo (int)$uid; ?>;
    (function(){
      const API='/wp-json/prismtek/v1/';
      async function j(path){const r=await fetch(API+path,{credentials:'include'});const d=await r.json().catch(()=>({}));return {ok:r.ok,d};}
      async function run(){
        const canvas=document.getElementById('pph-pet-canvas');
        if(!canvas) return;
        let box=canvas.parentElement;
        if(!box) return;

        let img=document.getElementById('prism-official-evo');
        if(!img){
          img=document.createElement('img');
          img.id='prism-official-evo';
          img.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:contain;image-rendering:pixelated;pointer-events:none;z-index:2';
          if(getComputedStyle(box).position==='static') box.style.position='relative';
          box.appendChild(img);
        }

        const [petOut,curOut] = await Promise.all([j('pet/rpg?ts='+Date.now()), j('creatures/curated-pack-v2?ts='+Date.now())]);
        if(!petOut.ok||!petOut.d.ok||!curOut.ok||!curOut.d.ok) return;
        const p=petOut.d.pet||{};
        const sp=(typeof p.species==='string' && p.species)?p.species:'sprout';
        const st=(typeof p.stage==='string' && p.stage)?p.stage:'baby';
        const url = (((curOut.d.manifest||{}).species||{})[sp]||{})[st] || '';
        if(url){ img.src = url + (url.includes('?')?'&':'?') + 'v=' + Date.now(); img.style.display='block'; }
      }
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run);
      else run();
      setTimeout(run,600);
    })();
    </script>
    <?php
}, 8350000);

// ===== Enriched PvP state endpoints for showdown UI =====
if (!function_exists('prismtek_pvp_enrich_state')) {
    function prismtek_pvp_enrich_state($m){
        if(!is_array($m)) return $m;
        $a=(int)($m['a'] ?? 0); $b=(int)($m['b'] ?? 0);
        $ua=get_userdata($a); $ub=get_userdata($b);
        $pa=get_user_meta($a,'prismtek_pet_state',true); if(!is_array($pa)) $pa=[];
        $pb=get_user_meta($b,'prismtek_pet_state',true); if(!is_array($pb)) $pb=[];
        $m['participants']=[
            'a'=>['id'=>$a,'user'=>$ua?(string)$ua->user_login:'a','displayName'=>$ua?(string)$ua->display_name:'A','species'=>(string)($pa['species']??'sprout')],
            'b'=>['id'=>$b,'user'=>$ub?(string)$ub->user_login:'b','displayName'=>$ub?(string)$ub->display_name:'B','species'=>(string)($pb['species']??'ember')],
        ];
        return $m;
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/state-full',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/move-full',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
            if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);
            $m['moves'][$uid]=$move;
            $m['updatedAt']=time();
            prismtek_pvp_resolve_round($m);
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m),'rating'=>prismtek_battle_v2_rating($uid)]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-pvp-fullstate-client-patch">
    (function(){
      // patch existing pvp controls to use enriched endpoints
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const card=document.querySelector('#pvp-screen')?.closest('.pph-card');
      if(!card) return;
      const log=card.querySelector('#pvp-log');
      const youImg=card.querySelector('#pvp-you'); const oppImg=card.querySelector('#pvp-opp');
      const yHp=card.querySelector('#pvp-you-hp'); const oHp=card.querySelector('#pvp-opp-hp');
      const idInput=card.querySelector('#pvp-id');
      let matchId=localStorage.getItem('prism_pvp_match_id')||'';
      function tierStage(h,max){ if(max<=0) return 'baby'; const r=h/max; if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby'; }

      async function getCurated(){
        const r=await fetch(API+'creatures/curated-pack-v2',{credentials:'include'}); const j=await r.json().catch(()=>({}));
        return (r.ok&&j.ok)?(j.manifest||{}):{};
      }
      async function g(path){ const r=await fetch(API+path,{credentials:'include'}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function p(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

      let curated={}; getCurated().then(m=>curated=m);

      function render(s){
        if(!s) return;
        const uid=Number(window.__prism_uid||0);
        const a=s.participants?.a||{}; const b=s.participants?.b||{};
        const me = (Number(a.id)===uid)?a:b;
        const opp = (Number(a.id)===uid)?b:a;
        const meId=Number(me.id||0), oppId=Number(opp.id||0);
        const mh=Number((s.hp||{})[meId]||0), mmax=Number((s.maxHp||{})[meId]||1);
        const oh=Number((s.hp||{})[oppId]||0), omax=Number((s.maxHp||{})[oppId]||1);
        yHp.textContent=`${me.displayName||'You'} HP ${mh}/${mmax}`;
        oHp.textContent=`${opp.displayName||'Opp'} HP ${oh}/${omax}`;
        const ms=tierStage(mh,mmax), os=tierStage(oh,omax);
        youImg.src = (((curated.species||{})[(me.species||'sprout')]||{})[ms]) || '';
        oppImg.src = (((curated.species||{})[(opp.species||'ember')]||{})[os]) || '';
        if(log) log.textContent=(s.log||[]).join('\n');
      }

      // override move buttons
      card.querySelectorAll('.pvp-m').forEach(btn=>{
        btn.addEventListener('click', async (e)=>{
          e.stopImmediatePropagation();
          matchId=(idInput?.value||'').trim()||matchId;
          if(!matchId) return;
          const m=btn.getAttribute('data-m');
          const out=await p('pet/pvp/move-full',{matchId,move:m});
          if(out.ok&&out.j.ok){ render(out.j.state); }
        }, true);
      });

      const loadBtn=card.querySelector('#pvp-load');
      loadBtn?.addEventListener('click', async (e)=>{
        e.stopImmediatePropagation();
        matchId=(idInput?.value||'').trim()||matchId;
        if(!matchId) return;
        const out=await g('pet/pvp/state-full?matchId='+encodeURIComponent(matchId));
        if(out.ok&&out.j.ok){ render(out.j.state); localStorage.setItem('prism_pvp_match_id',matchId); }
      }, true);
    })();
    </script>
    <?php
}, 8400000);

// ===== Unify battles to showdown format; retire legacy Battle Arena v2 =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/forfeit',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
            $winner = ((int)$m['a']===$uid) ? (int)$m['b'] : (int)$m['a'];
            $m['done']=true; $m['result']='forfeit'; $m['winner']=$winner; $m['status']='done';
            $m['log'][]=prismtek_pvp_user_tag($uid).' forfeited.';
            if($winner){
                prismtek_battle_v2_set_rating($winner, prismtek_battle_v2_rating($winner)+16);
                prismtek_battle_v2_set_rating($uid, prismtek_battle_v2_rating($uid)-12);
            }
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/rematch',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $old=$matches[$id];
            if((int)$old['a']!==$uid && (int)$old['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $a=(int)$old['a']; $b=(int)$old['b'];
            $newId=wp_generate_uuid4();
            $maxA=prismtek_pvp_hp_from_uid($a); $maxB=prismtek_pvp_hp_from_uid($b);
            $matches[$newId]=[
                'id'=>$newId,'a'=>$a,'b'=>$b,'status'=>'active','round'=>1,'done'=>false,'winner'=>0,
                'hp'=>[$a=>$maxA,$b=>$maxB],'maxHp'=>[$a=>$maxA,$b=>$maxB],
                'guard'=>[$a=>0,$b=>0],'charge'=>[$a=>0,$b=>0],
                'cd'=>[$a=>['heal'=>0,'charge'=>0],$b=>['heal'=>0,'charge'=>0]],
                'moves'=>[],'log'=>['Rematch started.'],'updatedAt'=>time(),
            ];
            prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'matchId'=>$newId,'state'=>prismtek_pvp_enrich_state($matches[$newId])]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-showdown-unify-ui">
    (function(){
      // Remove old Battle Arena v2 block(s)
      const old=document.getElementById('prism-battle-v2-panel');
      if(old) old.remove();

      const card=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(!card) return;

      // Upgrade existing PvP card to showdown-style controls
      if(!document.getElementById('pvp-timer')){
        const status=document.getElementById('pvp-status');
        const timer=document.createElement('div');
        timer.id='pvp-timer';
        timer.style.cssText='margin-top:8px;padding:6px 8px;border:1px solid #5f6ad1;background:#101a46;color:#e9efff;font-size:12px;font-weight:700';
        timer.textContent='TURN TIMER: --';
        status?.parentElement?.insertBefore(timer,status);
      }

      if(!document.getElementById('pvp-extra-row')){
        const status=document.getElementById('pvp-status');
        const row=document.createElement('div');
        row.id='pvp-extra-row';
        row.className='pph-tool-row';
        row.style.gridTemplateColumns='1fr 1fr';
        row.innerHTML='<button id="pvp-forfeit" type="button">Forfeit</button><button id="pvp-rematch" type="button">Rematch</button>';
        status?.parentElement?.insertBefore(row,status);
      }

      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const idInput=document.getElementById('pvp-id');
      const log=document.getElementById('pvp-log');
      const timer=document.getElementById('pvp-timer');
      let matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
      let tick=20, iv=null;

      function setTimer(v){ if(timer) timer.textContent='TURN TIMER: '+String(v).padStart(2,'0')+'s'; }
      function restartTimer(){ tick=20; setTimer(tick); if(iv) clearInterval(iv); iv=setInterval(()=>{ tick=Math.max(0,tick-1); setTimer(tick); if(tick===0){ clearInterval(iv); } },1000); }

      async function getState(){
        matchId=(idInput?.value||matchId||'').trim();
        if(!matchId) return;
        const r=await fetch(API+'pet/pvp/state-full?matchId='+encodeURIComponent(matchId),{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        if(r.ok&&j.ok){
          if(log) log.textContent=(j.state?.log||[]).join('\n');
          if(!j.state?.done) restartTimer();
        }
      }

      document.getElementById('pvp-forfeit')?.addEventListener('click', async ()=>{
        matchId=(idInput?.value||matchId||'').trim(); if(!matchId) return;
        const r=await fetch(API+'pet/pvp/forfeit',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({matchId})});
        const j=await r.json().catch(()=>({}));
        if(r.ok&&j.ok){ if(log) log.textContent=(j.state?.log||[]).join('\n'); if(iv) clearInterval(iv); setTimer(0); }
      });

      document.getElementById('pvp-rematch')?.addEventListener('click', async ()=>{
        matchId=(idInput?.value||matchId||'').trim(); if(!matchId) return;
        const r=await fetch(API+'pet/pvp/rematch',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({matchId})});
        const j=await r.json().catch(()=>({}));
        if(r.ok&&j.ok){
          matchId=j.matchId||matchId;
          if(idInput) idInput.value=matchId;
          localStorage.setItem('prism_pvp_match_id',matchId);
          if(log) log.textContent=(j.state?.log||[]).join('\n');
          restartTimer();
        }
      });

      // live polling every 2s for showdown feel
      setInterval(getState, 2000);
      getState();
    })();
    </script>
    <style id="prism-showdown-unify-css">
      #pvp-screen{border:2px solid #6f7cff !important;box-shadow:inset 0 0 0 1px rgba(180,194,255,.2)}
      #pvp-log{font-family:ui-monospace,monospace}
      @media (max-width:760px){
        #pvp-extra-row{grid-template-columns:1fr 1fr !important}
      }
    </style>
    <?php
}, 8500000);

// ===== Showdown Plus: nameplates/status chips/PP queue/history + curated-for-all =====
if (!function_exists('prismtek_pvp_history_get')) {
    function prismtek_pvp_history_get(){ $h=get_option('prismtek_pvp_history',[]); return is_array($h)?$h:[]; }
    function prismtek_pvp_history_set($h){ if(!is_array($h)) $h=[]; update_option('prismtek_pvp_history', array_slice($h,-400), false); }
    function prismtek_pvp_history_add($m){
        if(!is_array($m) || empty($m['id'])) return;
        $h=prismtek_pvp_history_get();
        $id=(string)$m['id'];
        foreach($h as $row){ if((string)($row['id']??'')===$id) return; }
        $h[]=$m; prismtek_pvp_history_set($h);
    }

    function prismtek_pvp_default_pp(){ return ['strike'=>99,'guard'=>99,'charge'=>8,'heal'=>5]; }

    function prismtek_pvp_resolve_round_plus(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        if(empty($m['pp'][$a])) $m['pp'][$a]=prismtek_pvp_default_pp();
        if(empty($m['pp'][$b])) $m['pp'][$b]=prismtek_pvp_default_pp();
        if(empty($m['cd'][$a])) $m['cd'][$a]=['heal'=>0,'charge'=>0];
        if(empty($m['cd'][$b])) $m['cd'][$b]=['heal'=>0,'charge'=>0];

        $ma=$m['moves'][$a]??null; $mb=$m['moves'][$b]??null;
        if(!$ma || !$mb) return;

        $order=[[$a,$b,$ma],[$b,$a,$mb]];
        $log=[];

        foreach($order as $turn){
            [$uid,$opp,$move]=$turn;
            if(($m['hp'][$uid]??0)<=0 || ($m['hp'][$opp]??0)<=0) continue;

            $cd=&$m['cd'][$uid];
            foreach($cd as $k=>$v) $cd[$k]=max(0,(int)$v-1);

            if(($m['pp'][$uid][$move]??0)<=0){
                $log[] = prismtek_pvp_user_tag($uid)." tried ".strtoupper($move)." (no PP).";
                continue;
            }
            if($move!=='strike' && $move!=='guard') $m['pp'][$uid][$move]=max(0,(int)$m['pp'][$uid][$move]-1);

            if($move==='strike'){
                $d=rand(12,22)+(int)($m['charge'][$uid]??0);
                if(!empty($m['guard'][$opp])) $d=(int)floor($d*0.55);
                $m['hp'][$opp]=max(0,(int)$m['hp'][$opp]-$d);
                $m['charge'][$uid]=0;
                $log[] = prismtek_pvp_user_tag($uid)." used STRIKE for {$d}.";
            } elseif($move==='guard'){
                $m['guard'][$uid]=1;
                $log[] = prismtek_pvp_user_tag($uid)." used GUARD.";
            } elseif($move==='charge'){
                if((int)($cd['charge']??0)>0){
                    $log[] = prismtek_pvp_user_tag($uid)." tried CHARGE (cooldown).";
                } else {
                    $m['charge'][$uid]=min(14,(int)($m['charge'][$uid]??0)+8);
                    $cd['charge']=2;
                    $log[] = prismtek_pvp_user_tag($uid)." used CHARGE.";
                }
            } elseif($move==='heal'){
                if((int)($cd['heal']??0)>0){
                    $log[] = prismtek_pvp_user_tag($uid)." tried HEAL (cooldown).";
                } else {
                    $h=rand(14,24);
                    $m['hp'][$uid]=min((int)$m['maxHp'][$uid], (int)$m['hp'][$uid]+$h);
                    $cd['heal']=3;
                    $log[] = prismtek_pvp_user_tag($uid)." healed {$h}.";
                }
            }
        }

        $m['guard'][$a]=0; $m['guard'][$b]=0;
        $m['round']=(int)$m['round']+1;
        $m['log']=array_slice(array_merge((array)$m['log'],$log),-40);
        $m['moves']=[];

        $ha=(int)$m['hp'][$a]; $hb=(int)$m['hp'][$b];
        if($ha<=0 || $hb<=0){
            $m['done']=true; $m['status']='done';
            if($ha===$hb){ $winner=0; $m['result']='draw'; }
            else { $winner=$ha>$hb?$a:$b; $m['result']='win'; }
            $m['winner']=$winner;
            if($winner){
                $loser=$winner===$a?$b:$a;
                prismtek_battle_v2_set_rating($winner, prismtek_battle_v2_rating($winner)+20);
                prismtek_battle_v2_set_rating($loser, prismtek_battle_v2_rating($loser)-14);
                $m['log'][]='Winner: '.prismtek_pvp_user_tag($winner);
            } else {
                $m['log'][]='Draw.';
            }
            prismtek_pvp_history_add(prismtek_pvp_enrich_state($m));
        }
    }
}

add_action('init', function(){
    // one-time migration: force all unknown species to curated set
    if(get_option('prismtek_curated_species_migrated')==='1') return;
    $users=get_users(['number'=>500,'fields'=>['ID']]);
    foreach($users as $u){
        $uid=(int)$u->ID;
        $pet=get_user_meta($uid,'prismtek_pet_state',true);
        if(!is_array($pet)) continue;
        $sp=sanitize_key((string)($pet['species']??'sprout'));
        if(!in_array($sp,['sprout','ember','tidal','volt'],true)){
            $pet['species']='sprout';
            update_user_meta($uid,'prismtek_pet_state',$pet);
        }
    }
    update_option('prismtek_curated_species_migrated','1',false);
});

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/challenge',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $oppName=sanitize_text_field((string)$r->get_param('opponent'));
            if($oppName==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_opponent'],400);
            $opp=get_user_by('login',$oppName); if(!$opp) $opp=get_user_by('slug',$oppName);
            if(!$opp) return new WP_REST_Response(['ok'=>false,'error'=>'opponent_not_found'],404);
            $oid=(int)$opp->ID; if($oid===$uid) return new WP_REST_Response(['ok'=>false,'error'=>'cannot_challenge_self'],400);
            $matches=prismtek_pvp_get_matches();
            $id=wp_generate_uuid4();
            $maxA=prismtek_pvp_hp_from_uid($uid); $maxB=prismtek_pvp_hp_from_uid($oid);
            $matches[$id]=[
                'id'=>$id,'a'=>$uid,'b'=>$oid,'status'=>'pending','round'=>1,'done'=>false,'winner'=>0,
                'hp'=>[$uid=>$maxA,$oid=>$maxB],'maxHp'=>[$uid=>$maxA,$oid=>$maxB],
                'guard'=>[$uid=>0,$oid=>0],'charge'=>[$uid=>0,$oid=>0],
                'cd'=>[$uid=>['heal'=>0,'charge'=>0],$oid=>['heal'=>0,'charge'=>0]],
                'pp'=>[$uid=>prismtek_pvp_default_pp(),$oid=>prismtek_pvp_default_pp()],
                'moves'=>[],'queue'=>[],'log'=>['Challenge created by '.prismtek_pvp_user_tag($uid)],'updatedAt'=>time(),
            ];
            prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'matchId'=>$id]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/move-full',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $matches=prismtek_pvp_get_matches();
            if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
            if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);

            $m['moves'][$uid]=$move;
            $m['queue'][]=['uid'=>$uid,'move'=>$move,'at'=>time()];
            $m['queue']=array_slice((array)$m['queue'],-6);
            $m['updatedAt']=time();
            prismtek_pvp_resolve_round_plus($m);
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m),'rating'=>prismtek_battle_v2_rating($uid)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/history',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $h=prismtek_pvp_history_get();
            $rows=[];
            foreach(array_reverse($h) as $m){
                $a=(int)($m['a']??0); $b=(int)($m['b']??0);
                if($a!==$uid && $b!==$uid) continue;
                $rows[]=[
                    'id'=>(string)($m['id']??''),
                    'result'=>(string)($m['result']??'done'),
                    'winner'=>(int)($m['winner']??0),
                    'updatedAt'=>(int)($m['updatedAt']??0),
                    'youVs'=>prismtek_pvp_user_tag($a).' vs '.prismtek_pvp_user_tag($b),
                ];
                if(count($rows)>=30) break;
            }
            return rest_ensure_response(['ok'=>true,'rows'=>$rows]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/replay',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $h=prismtek_pvp_history_get();
            foreach($h as $m){
                if((string)($m['id']??'')!==$id) continue;
                $a=(int)($m['a']??0); $b=(int)($m['b']??0);
                if($a!==$uid && $b!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
                return rest_ensure_response(['ok'=>true,'state'=>$m]);
            }
            return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-showdown-plus-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const card=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(!card) return;

      const status=card.querySelector('#pvp-status');
      const idInput=card.querySelector('#pvp-id');
      const log=card.querySelector('#pvp-log');
      const youHp=card.querySelector('#pvp-you-hp');
      const oppHp=card.querySelector('#pvp-opp-hp');
      const timer=document.getElementById('pvp-timer');
      const moveBtns=[...card.querySelectorAll('.pvp-m')];
      const queueBox=document.createElement('div'); queueBox.id='pvp-queue'; queueBox.style.cssText='margin-top:8px;font-size:12px;color:#dbe8ff';
      const histBox=document.createElement('div'); histBox.id='pvp-history'; histBox.style.cssText='margin-top:8px;font-size:12px;color:#dbe8ff';
      log?.parentElement?.insertBefore(queueBox, log);
      log?.parentElement?.insertBefore(histBox, log.nextSibling);

      let matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
      let currentState=null;
      let tick=20, iv=null;
      const set=t=>{ if(status) status.textContent=t; };

      function setTimer(v){ if(timer) timer.textContent='TURN TIMER: '+String(v).padStart(2,'0')+'s'; }
      function restartTimer(){ tick=20; setTimer(tick); if(iv) clearInterval(iv); iv=setInterval(()=>{tick=Math.max(0,tick-1);setTimer(tick);if(tick===0)clearInterval(iv);},1000); }

      async function g(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
      async function p(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      function ppFor(state, uid, move){ return Number(((state?.pp||{})[uid]||{})[move] ?? (move==='heal'?5:(move==='charge'?8:99))); }
      function cdFor(state, uid, k){ return Number(((state?.cd||{})[uid]||{})[k]||0); }
      function uidMe(state){ const me=Number(window.__prism_uid||0); return me===Number(state?.a)?Number(state?.a):Number(state?.b); }
      function uidOpp(state){ const me=uidMe(state); return me===Number(state?.a)?Number(state?.b):Number(state?.a); }

      function renderEnh(state){
        if(!state) return;
        currentState=state;
        const me=uidMe(state), opp=uidOpp(state);

        // nameplates + chips
        const meName=(state.participants?.a?.id===me?state.participants?.a?.displayName:state.participants?.b?.displayName)||'You';
        const opName=(state.participants?.a?.id===opp?state.participants?.a?.displayName:state.participants?.b?.displayName)||'Opponent';
        youHp.textContent=`${meName} HP ${(state.hp||{})[me]||0}/${(state.maxHp||{})[me]||0}`;
        oppHp.textContent=`${opName} HP ${(state.hp||{})[opp]||0}/${(state.maxHp||{})[opp]||0}`;

        const meGuard=((state.guard||{})[me]||0)>0, meCharge=((state.charge||{})[me]||0)>0;
        const chips=[]; if(meGuard) chips.push('🛡 Guard'); if(meCharge) chips.push('⚡ Charge');
        queueBox.innerHTML = `<strong>Action Queue:</strong> ${(state.queue||[]).slice(-4).map(q=>`${q.uid===me?'You':'Opp'}:${q.move}`).join(' · ') || 'empty'}<br><strong>Status:</strong> ${chips.join(' · ') || 'normal'}`;

        // move button badges
        moveBtns.forEach(btn=>{
          const m=btn.getAttribute('data-m');
          if(!m) return;
          const pp=ppFor(state, me, m);
          const cd=(m==='heal'||m==='charge')?cdFor(state, me, m):0;
          btn.innerHTML = `${m.charAt(0).toUpperCase()+m.slice(1)} <span style="font-size:10px;opacity:.9">PP:${pp}${cd>0?` · CD:${cd}`:''}</span>`;
          btn.disabled = !!state.done || pp<=0 || cd>0;
        });

        if(state.done){ if(iv) clearInterval(iv); setTimer(0); }
      }

      async function loadHistory(){
        const out=await g('pet/pvp/history');
        if(!out.ok||!out.j.ok){ histBox.textContent='History unavailable.'; return; }
        const rows=out.j.rows||[];
        if(!rows.length){ histBox.textContent='No match history yet.'; return; }
        histBox.innerHTML='<strong>History:</strong><br>'+rows.slice(0,6).map(r=>`<a href="#" data-replay="${r.id}">${r.youVs}</a> · ${r.result}`).join('<br>');
        histBox.querySelectorAll('[data-replay]').forEach(a=>a.addEventListener('click', async (e)=>{
          e.preventDefault();
          const id=a.getAttribute('data-replay');
          const rep=await g('pet/pvp/replay?matchId='+encodeURIComponent(id));
          if(rep.ok&&rep.j.ok){ if(log) log.textContent=(rep.j.state?.log||[]).join('\n'); renderEnh(rep.j.state); set('Replay loaded.'); }
        }));
      }

      async function refresh(){
        matchId=(idInput?.value||matchId||'').trim();
        if(!matchId) return;
        const out=await g('pet/pvp/state-full?matchId='+encodeURIComponent(matchId));
        if(out.ok&&out.j.ok){
          if(log) log.textContent=(out.j.state?.log||[]).join('\n');
          renderEnh(out.j.state);
          if(!out.j.state?.done) restartTimer();
        }
      }

      // intercept existing move buttons for enriched endpoint
      moveBtns.forEach(btn=>btn.addEventListener('click', async (e)=>{
        e.preventDefault(); e.stopImmediatePropagation();
        matchId=(idInput?.value||matchId||'').trim(); if(!matchId){ set('No active match.'); return; }
        const m=btn.getAttribute('data-m');
        const out=await p('pet/pvp/move-full',{matchId,move:m});
        if(!out.ok||!out.j.ok){ set('Move failed: '+(out.j?.error||'unknown')); return; }
        if(log) log.textContent=(out.j.state?.log||[]).join('\n');
        renderEnh(out.j.state);
        set('Move submitted.');
      }, true));

      // refresh history + polling
      setInterval(refresh, 2200);
      setInterval(loadHistory, 12000);
      loadHistory();
      refresh();
    })();
    </script>
    <style id="prism-showdown-plus-css">
      #pvp-queue{border:1px solid #4f5aba;background:#0f163a;padding:6px}
      #pvp-history a{color:#9fd1ff;text-decoration:none}
      #pvp-history a:hover{text-decoration:underline}
      .pvp-m[disabled]{opacity:.5}
    </style>
    <?php
}, 8600000);

// ===== Showdown Pro: priority/speed, spectator links, battle FX, guided prompt, dex sync =====
if (!function_exists('prismtek_pvp_speed_stat')) {
    function prismtek_pvp_speed_stat($uid){
        $pet = function_exists('prismtek_pet_get_state') ? prismtek_pet_get_state((int)$uid) : [];
        if(!is_array($pet)) $pet=[];
        $lvl = max(1,(int)($pet['level'] ?? 1));
        $species = sanitize_key((string)($pet['species'] ?? 'sprout'));
        $person = sanitize_key((string)($pet['personality'] ?? 'brave'));
        $sp = 10 + $lvl*2;
        $speciesBonus = ['volt'=>6,'ember'=>3,'tidal'=>2,'sprout'=>1];
        $personBonus = ['chaotic'=>4,'curious'=>3,'brave'=>2,'calm'=>1];
        $sp += (int)($speciesBonus[$species] ?? 1);
        $sp += (int)($personBonus[$person] ?? 1);
        return $sp;
    }

    function prismtek_pvp_priority($move){
        $m=sanitize_key((string)$move);
        $prio=['guard'=>2,'heal'=>1,'charge'=>0,'strike'=>0];
        return (int)($prio[$m] ?? 0);
    }

    function prismtek_pvp_resolve_round_speed(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        if(empty($m['pp'][$a])) $m['pp'][$a]=prismtek_pvp_default_pp();
        if(empty($m['pp'][$b])) $m['pp'][$b]=prismtek_pvp_default_pp();
        if(empty($m['cd'][$a])) $m['cd'][$a]=['heal'=>0,'charge'=>0];
        if(empty($m['cd'][$b])) $m['cd'][$b]=['heal'=>0,'charge'=>0];

        $ma=$m['moves'][$a]??null; $mb=$m['moves'][$b]??null;
        if(!$ma || !$mb) return;

        $ord=[
          ['uid'=>$a,'opp'=>$b,'move'=>$ma,'prio'=>prismtek_pvp_priority($ma),'spd'=>prismtek_pvp_speed_stat($a)],
          ['uid'=>$b,'opp'=>$a,'move'=>$mb,'prio'=>prismtek_pvp_priority($mb),'spd'=>prismtek_pvp_speed_stat($b)],
        ];
        usort($ord,function($x,$y){
            if($x['prio']!==$y['prio']) return $y['prio']<=>$x['prio'];
            if($x['spd']!==$y['spd']) return $y['spd']<=>$x['spd'];
            return rand(0,1)?1:-1;
        });

        $log=[];
        foreach($ord as $turn){
            $uid=(int)$turn['uid']; $opp=(int)$turn['opp']; $move=(string)$turn['move'];
            if(($m['hp'][$uid]??0)<=0 || ($m['hp'][$opp]??0)<=0) continue;

            $cd=&$m['cd'][$uid]; foreach($cd as $k=>$v) $cd[$k]=max(0,(int)$v-1);
            if(($m['pp'][$uid][$move]??0)<=0){ $log[]=prismtek_pvp_user_tag($uid)." tried ".strtoupper($move)." (no PP)."; continue; }
            if($move!=='strike' && $move!=='guard') $m['pp'][$uid][$move]=max(0,(int)$m['pp'][$uid][$move]-1);

            if($move==='strike'){
                $d=rand(12,22)+(int)($m['charge'][$uid]??0);
                if(!empty($m['guard'][$opp])) $d=(int)floor($d*0.55);
                $m['hp'][$opp]=max(0,(int)$m['hp'][$opp]-$d);
                $m['charge'][$uid]=0;
                $log[] = prismtek_pvp_user_tag($uid)." used STRIKE for {$d}.";
            }elseif($move==='guard'){
                $m['guard'][$uid]=1; $log[] = prismtek_pvp_user_tag($uid)." used GUARD.";
            }elseif($move==='charge'){
                if((int)($cd['charge']??0)>0){ $log[] = prismtek_pvp_user_tag($uid)." tried CHARGE (cooldown)."; }
                else { $m['charge'][$uid]=min(14,(int)($m['charge'][$uid]??0)+8); $cd['charge']=2; $log[] = prismtek_pvp_user_tag($uid)." used CHARGE."; }
            }elseif($move==='heal'){
                if((int)($cd['heal']??0)>0){ $log[] = prismtek_pvp_user_tag($uid)." tried HEAL (cooldown)."; }
                else { $h=rand(14,24); $m['hp'][$uid]=min((int)$m['maxHp'][$uid],(int)$m['hp'][$uid]+$h); $cd['heal']=3; $log[] = prismtek_pvp_user_tag($uid)." healed {$h}."; }
            }
        }

        $m['guard'][$a]=0; $m['guard'][$b]=0;
        $m['round']=(int)$m['round']+1;
        $m['log']=array_slice(array_merge((array)$m['log'],$log),-40);
        $m['moves']=[];

        $ha=(int)$m['hp'][$a]; $hb=(int)$m['hp'][$b];
        if($ha<=0||$hb<=0){
            $m['done']=true; $m['status']='done';
            if($ha===$hb){ $winner=0; $m['result']='draw'; }
            else { $winner=$ha>$hb?$a:$b; $m['result']='win'; }
            $m['winner']=$winner;
            if($winner){ $loser=$winner===$a?$b:$a; prismtek_battle_v2_set_rating($winner,prismtek_battle_v2_rating($winner)+20); prismtek_battle_v2_set_rating($loser,prismtek_battle_v2_rating($loser)-14); $m['log'][]='Winner: '.prismtek_pvp_user_tag($winner); }
            else $m['log'][]='Draw.';
            prismtek_pvp_history_add(prismtek_pvp_enrich_state($m));
        }
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/move-pro',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $matches=prismtek_pvp_get_matches(); if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id]; if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
            if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);
            $m['moves'][$uid]=$move; $m['queue'][]=['uid'=>$uid,'move'=>$move,'at'=>time()]; $m['queue']=array_slice((array)$m['queue'],-8); $m['updatedAt']=time();
            prismtek_pvp_resolve_round_speed($m);
            $matches[$id]=$m; prismtek_pvp_set_matches($matches);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m),'rating'=>prismtek_battle_v2_rating($uid)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/spectate-link',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $matches=prismtek_pvp_get_matches(); if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id]; if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $tok=(string)($m['spectateToken'] ?? ''); if($tok===''){ $tok=wp_generate_password(24,false,false); $m['spectateToken']=$tok; $matches[$id]=$m; prismtek_pvp_set_matches($matches); }
            $url=home_url('/prism-creatures/?spectate='.$tok);
            return rest_ensure_response(['ok'=>true,'url'=>$url,'token'=>$tok]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/pvp/spectate-state',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $tok=sanitize_text_field((string)$r->get_param('token')); if($tok==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_token'],400);
            $matches=prismtek_pvp_get_matches();
            foreach($matches as $m){
                if((string)($m['spectateToken'] ?? '')!==$tok) continue;
                return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
            }
            return new WP_REST_Response(['ok'=>false,'error'=>'not_found'],404);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-showdown-pro-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const params=new URLSearchParams(location.search);
      const spectateToken=params.get('spectate')||'';
      const card=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(!card) return;
      const idInput=card.querySelector('#pvp-id');
      const status=card.querySelector('#pvp-status');
      const log=card.querySelector('#pvp-log');
      const set=t=>{ if(status) status.textContent=t; };

      // spectator mode
      if(spectateToken){
        card.querySelectorAll('button,input,select,textarea').forEach(el=>el.disabled=true);
        set('Spectating match...');
        const poll=async()=>{
          const r=await fetch(API+'pet/pvp/spectate-state?token='+encodeURIComponent(spectateToken),{credentials:'omit'});
          const j=await r.json().catch(()=>({}));
          if(r.ok&&j.ok){ if(log) log.textContent=(j.state?.log||[]).join('\n'); }
        };
        poll(); setInterval(poll,2000);
        return;
      }

      // replace move endpoint usage to move-pro
      card.querySelectorAll('.pvp-m').forEach(btn=>btn.addEventListener('click', async (e)=>{
        e.preventDefault(); e.stopImmediatePropagation();
        const matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
        if(!matchId){ set('No active match.'); return; }
        const move=btn.getAttribute('data-m');
        const r=await fetch(API+'pet/pvp/move-pro',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({matchId,move})});
        const j=await r.json().catch(()=>({}));
        if(!r.ok||!j.ok){ set('Move failed: '+(j.error||'unknown')); return; }
        if(log) log.textContent=(j.state?.log||[]).join('\n');
        set('Move submitted.');
      }, true));

      // add spectator link button
      if(!document.getElementById('pvp-spectate-link')){
        const row=document.createElement('div'); row.className='pph-tool-row'; row.style.gridTemplateColumns='1fr';
        row.innerHTML='<button id="pvp-spectate-link" type="button">Copy Spectator Link</button>';
        status?.parentElement?.insertBefore(row,status);
        row.querySelector('#pvp-spectate-link').addEventListener('click', async ()=>{
          const matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
          if(!matchId){ set('No match loaded.'); return; }
          const r=await fetch(API+'pet/pvp/spectate-link',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({matchId})});
          const j=await r.json().catch(()=>({}));
          if(!r.ok||!j.ok){ set('Could not create spectator link.'); return; }
          try{ await navigator.clipboard.writeText(j.url||''); set('Spectator link copied.'); }
          catch{ set(j.url||'Spectator link ready.'); }
        });
      }

      // guided prompt builder for pixellab
      const genCard=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pixellab creature generator'));
      if(genCard && !document.getElementById('pl-guided-builder')){
        const prompt=genCard.querySelector('#pl-prompt');
        const builder=document.createElement('details'); builder.id='pl-guided-builder';
        builder.innerHTML=`<summary><strong>Guided Prompt Builder</strong></summary>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:6px;">
            <select id="gp-species"><option>sprout</option><option>ember</option><option>tidal</option><option>volt</option></select>
            <select id="gp-stage"><option>baby</option><option>teen</option><option>adult</option></select>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:6px;">
            <select id="gp-mood"><option>brave</option><option>playful</option><option>mysterious</option><option>calm</option></select>
            <select id="gp-bg"><option>transparent</option><option>battle-ready transparent</option><option>minimal aura transparent</option></select>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr; margin-top:6px;"><button id="gp-apply" type="button">Build Prompt</button></div>`;
        prompt?.parentElement?.insertBefore(builder,prompt);
        builder.querySelector('#gp-apply').addEventListener('click', ()=>{
          const sp=builder.querySelector('#gp-species').value;
          const st=builder.querySelector('#gp-stage').value;
          const mood=builder.querySelector('#gp-mood').value;
          const bg=builder.querySelector('#gp-bg').value;
          const txt=`Create a ${st} stage ${sp} creature for Prism Creatures. Personality vibe: ${mood}. Pixel art, centered, readable silhouette, no text/watermark, ${bg}.`;
          if(prompt) prompt.value=txt;
        });
      }

      // dex sync: official + user sections explicitly in prism dex card
      const dex=document.getElementById('prism-curated-dex');
      if(dex && !document.getElementById('prism-dex-sections')){
        const box=document.createElement('div'); box.id='prism-dex-sections'; box.style.marginTop='8px';
        box.innerHTML='<div class="pph-tool-row" style="grid-template-columns:1fr 1fr;"><button id="dex-official">Official</button><button id="dex-user">User-generated</button></div><div id="dex-grid-sync" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px"></div>';
        dex.appendChild(box);
        const grid=box.querySelector('#dex-grid-sync');
        async function load(kind){
          const r=await fetch(API+'creatures/gallery-v2',{credentials:'include'}); const j=await r.json().catch(()=>({}));
          if(!r.ok||!j.ok){ grid.textContent='Unavailable'; return; }
          const rows=kind==='official'?(j.official||[]):(j.user||[]);
          grid.innerHTML=rows.length?rows.map(c=>`<div style="border:1px solid #5f6ad1;background:#0d1334;padding:6px"><img src="${c.url}" style="width:100%;image-rendering:pixelated;border:1px solid #4a57b8"/><div style="font-size:11px">${c.species} · ${c.stage}</div></div>`).join(''):'<div style="font-size:12px">No entries.</div>';
        }
        box.querySelector('#dex-official').addEventListener('click',()=>load('official'));
        box.querySelector('#dex-user').addEventListener('click',()=>load('user'));
        load('official');
      }
    })();
    </script>
    <?php
}, 8700000);

// ===== Hardcore PvP pass + category-only prompt builder =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/preview-order',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            if(!in_array($move,['strike','guard','charge','heal'],true)) return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
            $matches=prismtek_pvp_get_matches(); if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$matches[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $opp=((int)$m['a']===$uid)?(int)$m['b']:(int)$m['a'];
            $oppMove=sanitize_key((string)($m['moves'][$opp] ?? ''));
            $prio=['guard'=>2,'heal'=>1,'charge'=>0,'strike'=>0];
            $pYou=(int)($prio[$move] ?? 0);
            $pOpp=(int)($prio[$oppMove] ?? 0);
            $sYou=prismtek_pvp_speed_stat($uid);
            $sOpp=prismtek_pvp_speed_stat($opp);
            $order='unknown';
            if($oppMove!==''){
                if($pYou!==$pOpp) $order = $pYou>$pOpp ? 'you-first' : 'opp-first';
                elseif($sYou!==$sOpp) $order = $sYou>$sOpp ? 'you-first' : 'opp-first';
                else $order='coinflip';
            }
            return rest_ensure_response(['ok'=>true,'order'=>$order,'yourMove'=>$move,'oppMove'=>$oppMove?:null,'yourSpeed'=>$sYou,'oppSpeed'=>$sOpp]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-hardcore-pass-ui">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};

      // ---- PvP visual/FX pass ----
      const pvpCard=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(pvpCard){
        const screen=pvpCard.querySelector('#pvp-screen');
        const youImg=pvpCard.querySelector('#pvp-you');
        const oppImg=pvpCard.querySelector('#pvp-opp');
        const idInput=pvpCard.querySelector('#pvp-id');
        let lastHP={you:null,opp:null};

        if(screen && !document.getElementById('pvp-flash-overlay')){
          const ov=document.createElement('div'); ov.id='pvp-flash-overlay';
          ov.style.cssText='position:absolute;inset:0;pointer-events:none;opacity:0;background:#fff;mix-blend-mode:screen;z-index:4';
          if(getComputedStyle(screen).position==='static') screen.style.position='relative';
          screen.appendChild(ov);
        }

        if(!document.getElementById('pvp-order-chip')){
          const chip=document.createElement('div'); chip.id='pvp-order-chip';
          chip.style.cssText='margin-top:8px;padding:6px 8px;border:1px solid #5f6ad1;background:#101a46;color:#dff3ff;font-size:12px;font-weight:700';
          chip.textContent='TURN ORDER PREVIEW: select a move';
          const log=pvpCard.querySelector('#pvp-log');
          log?.parentElement?.insertBefore(chip,log);
        }

        function fxFlash(){
          const ov=document.getElementById('pvp-flash-overlay'); if(!ov) return;
          ov.animate([{opacity:0},{opacity:.45},{opacity:0}],{duration:220,easing:'ease-out'});
        }
        function fxShake(el){ if(!el) return; el.animate([{transform:el===oppImg?'scaleX(-1) translateX(0)':'translateX(0)'},{transform:el===oppImg?'scaleX(-1) translateX(-6px)':'translateX(6px)'},{transform:el===oppImg?'scaleX(-1) translateX(4px)':'translateX(-4px)'},{transform:el===oppImg?'scaleX(-1) translateX(0)':'translateX(0)'}],{duration:260,easing:'ease-out'}); }
        function fxFaint(el,isOpp){ if(!el) return; const base=isOpp?'scaleX(-1) ':' '; el.animate([{transform:base+'translateY(0)',opacity:1},{transform:base+'translateY(12px)',opacity:.35},{transform:base+'translateY(20px)',opacity:0}],{duration:600,fill:'forwards',easing:'ease-in'}); }

        function setSidePoses(){
          if(youImg){ youImg.style.transform='scale(1)'; youImg.style.filter='drop-shadow(0 2px 0 rgba(0,0,0,.45))'; }
          if(oppImg){ oppImg.style.transform='scaleX(-1)'; oppImg.style.filter='drop-shadow(0 2px 0 rgba(0,0,0,.45))'; }
        }
        setSidePoses();

        async function preview(move){
          const chip=document.getElementById('pvp-order-chip');
          const matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
          if(!matchId){ if(chip) chip.textContent='TURN ORDER PREVIEW: no match loaded'; return; }
          const r=await fetch(API+'pet/pvp/preview-order',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({matchId,move})});
          const j=await r.json().catch(()=>({}));
          if(!r.ok||!j.ok){ if(chip) chip.textContent='TURN ORDER PREVIEW: unavailable'; return; }
          const txt = j.order==='you-first'?'You likely act first':(j.order==='opp-first'?'Opponent likely acts first':(j.order==='coinflip'?'Speed tie (coinflip)':'Waiting for opponent move'));
          if(chip) chip.textContent='TURN ORDER PREVIEW: '+txt;
        }

        pvpCard.querySelectorAll('.pvp-m').forEach(btn=>{
          const mv=btn.getAttribute('data-m')||'strike';
          btn.addEventListener('mouseenter',()=>preview(mv));
          btn.addEventListener('focus',()=>preview(mv));
          btn.addEventListener('click',()=>{ const chip=document.getElementById('pvp-order-chip'); if(chip) chip.textContent='TURN ORDER PREVIEW: move locked'; },true);
        });

        // poll state to trigger FX by HP deltas
        async function pollFx(){
          const matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
          if(!matchId) return;
          const r=await fetch(API+'pet/pvp/state-full?matchId='+encodeURIComponent(matchId),{credentials:'include'});
          const j=await r.json().catch(()=>({}));
          if(!r.ok||!j.ok) return;
          const s=j.state||{};
          const uid=Number(window.__prism_uid||0);
          const me=(Number(s.a)===uid)?Number(s.a):Number(s.b);
          const opp=(me===Number(s.a))?Number(s.b):Number(s.a);
          const hYou=Number((s.hp||{})[me]||0), hOpp=Number((s.hp||{})[opp]||0);

          if(lastHP.you!==null && hYou<lastHP.you){ fxFlash(); fxShake(youImg); }
          if(lastHP.opp!==null && hOpp<lastHP.opp){ fxFlash(); fxShake(oppImg); }
          if(lastHP.you!==null && lastHP.you>0 && hYou===0){ fxFaint(youImg,false); }
          if(lastHP.opp!==null && lastHP.opp>0 && hOpp===0){ fxFaint(oppImg,true); }

          lastHP={you:hYou,opp:hOpp};
        }
        setInterval(pollFx, 1500);
      }

      // ---- Guided prompt category-only UX ----
      const genCard=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pixellab creature generator'));
      if(genCard){
        const prompt=genCard.querySelector('#pl-prompt');
        if(prompt){
          prompt.readOnly=true;
          prompt.placeholder='Use categories below. Prompt will be auto-built.';
        }

        // remove old loose builder if present
        const old=document.getElementById('pl-guided-builder'); if(old) old.remove();

        if(!document.getElementById('pl-category-builder')){
          const block=document.createElement('details');
          block.id='pl-category-builder'; block.open=true;
          block.innerHTML=`<summary><strong>Creature Prompt Categories (Required)</strong></summary>
            <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
              <label>Species<select id="cb-species"><option>sprout</option><option>ember</option><option>tidal</option><option>volt</option></select></label>
              <label>Growth Stage<select id="cb-stage"><option>baby</option><option>teen</option><option>adult</option></select></label>
            </div>
            <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
              <label>Element Type<select id="cb-type"><option>nature</option><option>fire</option><option>water</option><option>electric</option></select></label>
              <label>Personality<select id="cb-personality"><option>brave</option><option>curious</option><option>calm</option><option>chaotic</option></select></label>
            </div>
            <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
              <label>Body Shape<select id="cb-shape"><option>chibi</option><option>agile</option><option>tanky</option><option>serpentine</option></select></label>
              <label>Color Mood<select id="cb-color"><option>vibrant</option><option>pastel</option><option>neon</option><option>muted</option></select></label>
            </div>
            <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
              <label>Pose<select id="cb-pose"><option>battle ready</option><option>idle</option><option>charging attack</option><option>victory stance</option></select></label>
              <label>Background<select id="cb-bg"><option>transparent</option><option>transparent with subtle aura</option></select></label>
            </div>
            <div class="pph-tool-row" style="grid-template-columns:1fr; margin-top:8px;"><button type="button" id="cb-build">Build Prompt From Categories</button></div>`;
          const modelRow=genCard.querySelector('#pl-model')?.closest('.pph-tool-row') || genCard.firstElementChild;
          if(modelRow) modelRow.parentElement.insertBefore(block, modelRow.nextSibling);

          const build=()=>{
            const v=id=>block.querySelector('#'+id)?.value||'';
            const txt=`Create a ${v('cb-stage')} stage ${v('cb-species')} creature for Prism Creatures. Element type: ${v('cb-type')}. Personality: ${v('cb-personality')}. Body shape: ${v('cb-shape')}. Color mood: ${v('cb-color')}. Pose: ${v('cb-pose')}. Background: ${v('cb-bg')}. Pixel art, centered subject, readable silhouette, no text, no watermark.`;
            if(prompt) prompt.value=txt;
          };
          block.querySelectorAll('select').forEach(s=>s.addEventListener('change',build));
          block.querySelector('#cb-build').addEventListener('click',build);
          build();
        }
      }
    })();
    </script>
    <style id="prism-hardcore-final-css">
      #pvp-screen{overflow:hidden}
      #pvp-order-chip{border-radius:6px}
      #pl-category-builder{border:1px solid #5f6ad1;background:#0d1334;padding:8px;margin-top:8px}
      #pl-category-builder > summary{cursor:pointer;color:#dfe5ff}
      #pl-category-builder > summary::-webkit-details-marker{display:none}
    </style>
    <?php
}, 8800000);

// ===== Local Ollama Cloud Agent (owner-only, on-server) =====
if (!function_exists('prismtek_agent_is_owner')) {
    function prismtek_agent_is_owner(){
        if (!is_user_logged_in()) return false;
        $u = wp_get_current_user();
        if (!$u || !$u->exists()) return false;
        return current_user_can('manage_options') && strtolower((string)$u->user_login) === 'prismtek';
    }

    function prismtek_agent_exec_actions($actions){
        $results=[];
        if(!is_array($actions)) return $results;
        foreach($actions as $a){
            if(!is_array($a)) continue;
            $type=sanitize_key((string)($a['type'] ?? ''));
            try{
                if($type==='update_page'){
                    $slug=sanitize_title((string)($a['slug'] ?? ''));
                    $content=(string)($a['content'] ?? '');
                    if($slug==='') throw new Exception('missing_slug');
                    $post=get_page_by_path($slug);
                    if(!$post) throw new Exception('page_not_found');
                    wp_update_post(['ID'=>$post->ID,'post_content'=>$content]);
                    $results[]=['type'=>$type,'ok'=>true,'slug'=>$slug,'id'=>$post->ID];
                } elseif($type==='append_build_log'){
                    $post=get_page_by_path('build-log');
                    if(!$post) throw new Exception('build_log_not_found');
                    $old=(string)$post->post_content;
                    $html=(string)($a['html'] ?? '');
                    wp_update_post(['ID'=>$post->ID,'post_content'=>$html.$old]);
                    $results[]=['type'=>$type,'ok'=>true,'id'=>$post->ID];
                } elseif($type==='set_option'){
                    $k=sanitize_key((string)($a['key'] ?? ''));
                    $v=$a['value'] ?? null;
                    if($k==='') throw new Exception('missing_key');
                    update_option($k,$v,false);
                    $results[]=['type'=>$type,'ok'=>true,'key'=>$k];
                } elseif($type==='write_upload_text'){
                    $rel=(string)($a['path'] ?? '');
                    $content=(string)($a['content'] ?? '');
                    $rel=ltrim(str_replace('..','',$rel),'/');
                    if($rel==='') throw new Exception('missing_path');
                    $up=wp_upload_dir();
                    $base=trailingslashit($up['basedir']).'prism-agent-files/';
                    wp_mkdir_p(dirname($base.$rel));
                    file_put_contents($base.$rel,$content);
                    $results[]=['type'=>$type,'ok'=>true,'path'=>$rel];
                } elseif($type==='wp_cache_flush'){
                    if(function_exists('wp_cache_flush')) wp_cache_flush();
                    $results[]=['type'=>$type,'ok'=>true];
                } else {
                    $results[]=['type'=>$type ?: 'unknown','ok'=>false,'error'=>'unsupported_action'];
                }
            } catch(Throwable $e){
                $results[]=['type'=>$type ?: 'unknown','ok'=>false,'error'=>$e->getMessage()];
            }
        }
        return $results;
    }

    function prismtek_agent_ollama_chat($message, $model='qwen2.5:3b'){
        $system = "You are Prismtek's local website agent running on prismtek.dev. Return STRICT JSON only with keys: reply (string), actions (array). "
            ."Allowed action types: update_page{slug,content}, append_build_log{html}, set_option{key,value}, write_upload_text{path,content}, wp_cache_flush{}. "
            ."Only include actions when explicitly asked. Keep actions minimal and safe.";

        $payload=[
            'model'=>$model,
            'stream'=>false,
            'messages'=>[
                ['role'=>'system','content'=>$system],
                ['role'=>'user','content'=>(string)$message],
            ],
            'options'=>['temperature'=>0.2],
        ];

        $resp=wp_remote_post('http://127.0.0.1:11434/api/chat',[
            'timeout'=>120,
            'headers'=>['Content-Type'=>'application/json'],
            'body'=>wp_json_encode($payload),
        ]);
        if(is_wp_error($resp)) return ['ok'=>false,'error'=>'ollama_unreachable'];
        $code=(int)wp_remote_retrieve_response_code($resp);
        $body=(string)wp_remote_retrieve_body($resp);
        if($code<200||$code>=300) return ['ok'=>false,'error'=>'ollama_http_'.$code,'detail'=>$body];
        $j=json_decode($body,true);
        $txt=(string)($j['message']['content'] ?? '');

        // best-effort JSON parse
        $data=json_decode($txt,true);
        if(!is_array($data)){
            // fallback wrap plain reply
            $data=['reply'=>$txt ?: 'Done.','actions'=>[]];
        }
        if(!isset($data['reply'])) $data['reply']='Done.';
        if(!isset($data['actions']) || !is_array($data['actions'])) $data['actions']=[];
        return ['ok'=>true,'data'=>$data,'raw'=>$txt];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/agent/status',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            if(!prismtek_agent_is_owner()) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $probe=wp_remote_get('http://127.0.0.1:11434/api/tags',['timeout'=>10]);
            $up=!is_wp_error($probe) && (int)wp_remote_retrieve_response_code($probe)===200;
            return rest_ensure_response(['ok'=>true,'owner'=>true,'ollamaUp'=>$up]);
        }
    ]);

    register_rest_route('prismtek/v1','/agent/chat',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            if(!prismtek_agent_is_owner()) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $message=(string)$r->get_param('message');
            $model=sanitize_text_field((string)$r->get_param('model')) ?: 'qwen2.5:3b';
            $autoApply=(bool)$r->get_param('autoApply');
            if(trim($message)==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_message'],400);

            $out=prismtek_agent_ollama_chat($message,$model);
            if(!$out['ok']) return new WP_REST_Response($out,502);

            $data=$out['data'];
            $applied=[];
            if($autoApply && !empty($data['actions'])){
                $applied=prismtek_agent_exec_actions($data['actions']);
            }
            return rest_ensure_response(['ok'=>true,'reply'=>$data['reply'],'actions'=>$data['actions'],'applied'=>$applied]);
        }
    ]);
});

add_action('init', function(){
    if(!shortcode_exists('prism_local_agent')){
        add_shortcode('prism_local_agent', function(){
            if(!prismtek_agent_is_owner()) return '<div class="pph-card"><p>Agent access denied.</p></div>';
            ob_start(); ?>
            <section class="pph-wrap">
              <article class="pph-card">
                <h3>Prism Local Agent (Ollama)</h3>
                <p>Owner-only. Runs on this website server. Can plan + (optional) apply safe website actions.</p>
                <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
                  <select id="pla-model"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select>
                  <label><input id="pla-auto" type="checkbox" /> Auto-apply actions</label>
                  <button id="pla-status" type="button">Check Status</button>
                </div>
                <textarea id="pla-msg" rows="6" style="width:100%;margin-top:8px" placeholder="Ask me to update pages, build content, or fix website parts..."></textarea>
                <div class="pph-tool-row" style="grid-template-columns:1fr;"><button id="pla-send" type="button">Run Agent</button></div>
                <p id="pla-out" class="pph-status">Ready.</p>
                <pre id="pla-json" style="white-space:pre-wrap;max-height:260px;overflow:auto;background:#0d1334;border:1px solid #5f6ad1;padding:8px"></pre>
              </article>
            </section>
            <script>
            (function(){
              const API='/wp-json/prismtek/v1/';
              const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
              const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
              const out=document.getElementById('pla-out'), js=document.getElementById('pla-json');
              const set=t=>out.textContent=t;
              async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
              async function get(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

              document.getElementById('pla-status').addEventListener('click', async ()=>{
                set('Checking...'); const s=await get('agent/status'); if(!s.ok||!s.j.ok){set('Status unavailable'); return;} set('Ollama '+(s.j.ollamaUp?'online':'offline')); js.textContent=JSON.stringify(s.j,null,2);
              });

              document.getElementById('pla-send').addEventListener('click', async ()=>{
                const msg=document.getElementById('pla-msg').value.trim(); if(!msg){set('Enter a request.');return;}
                set('Running agent...');
                const payload={message:msg,model:document.getElementById('pla-model').value,autoApply:!!document.getElementById('pla-auto').checked};
                const r=await post('agent/chat',payload);
                if(!r.ok||!r.j.ok){ set('Agent failed'); js.textContent=JSON.stringify(r.j||{},null,2); return; }
                set(r.j.reply||'Done.'); js.textContent=JSON.stringify(r.j,null,2);
              });
            })();
            </script>
            <?php
            return ob_get_clean();
        });
    }
});

// ensure page exists
add_action('init', function(){
    $p=get_page_by_path('prism-agent');
    if(!$p){
        wp_insert_post([
            'post_type'=>'page',
            'post_status'=>'publish',
            'post_title'=>'Prism Agent',
            'post_name'=>'prism-agent',
            'post_content'=>'[prism_local_agent]',
        ]);
    }
});

// ===== Enforce 3-stage uniform generation + style lock =====
if (!function_exists('prismtek_stagepack_allowed')) {
    function prismtek_stagepack_allowed(){
        return [
            'species'=>['sprout','ember','tidal','volt'],
            'element'=>['nature','fire','water','electric'],
            'personality'=>['brave','curious','calm','chaotic','playful','mysterious'],
            'shape'=>['chibi','agile','tanky','serpentine'],
            'color'=>['vibrant','pastel','neon','muted'],
            'pose'=>['battle ready','idle','charging attack','victory stance'],
            'bg'=>['transparent','transparent with subtle aura'],
        ];
    }
    function prismtek_stagepack_pick($v, $set, $fallback){
        $v = sanitize_text_field((string)$v);
        return in_array($v, $set, true) ? $v : $fallback;
    }
    function prismtek_stagepack_style_lock(){
        return 'Prism Creatures official style lock: retro-futurist pixel art, crisp edges, readable silhouette, consistent anatomy across evolutions, no drastic design drift, no text, no watermark, family-consistent color logic.';
    }
    function prismtek_stagepack_build_prompt($cats, $stage){
        $desc = [
            'baby'=>'small first-stage creature',
            'teen'=>'mid evolution creature with same core silhouette',
            'adult'=>'final evolution creature with same identity and recognizable features',
        ];
        $stageDesc = $desc[$stage] ?? $desc['baby'];
        return "Create a {$stageDesc} for Prism Creatures. "
            ."Species: {$cats['species']}. Element: {$cats['element']}. Personality: {$cats['personality']}. "
            ."Body shape: {$cats['shape']}. Color mood: {$cats['color']}. Pose: {$cats['pose']}. Background: {$cats['bg']}. "
            .prismtek_stagepack_style_lock();
    }
    function prismtek_stagepack_save($uid, $slug, $b64){
        $bin = base64_decode((string)$b64, true);
        if($bin===false || strlen($bin)<100) return ['ok'=>false,'error'=>'invalid_image'];
        $up = wp_upload_dir();
        $baseDir = trailingslashit($up['basedir']).'prismtek-creatures/user-stages/'.(int)$uid;
        $baseUrl = trailingslashit($up['baseurl']).'prismtek-creatures/user-stages/'.(int)$uid;
        if(!wp_mkdir_p($baseDir)) return ['ok'=>false,'error'=>'storage_unavailable'];
        $name = sanitize_file_name($slug).'-'.time().'.png';
        $path = trailingslashit($baseDir).$name;
        file_put_contents($path, $bin);
        @chmod($path,0644);
        return ['ok'=>true,'url'=>trailingslashit($baseUrl).$name];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pixellab/generate-stages',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $enc=(string)get_user_meta($uid,'prismtek_pixellab_key_enc',true);
            $token=$enc?prismtek_pixellab_decrypt($enc):'';
            if($token==='') return new WP_REST_Response(['ok'=>false,'error'=>'pixellab_not_connected'],400);
            if((int)get_user_meta($uid,'prismtek_pixellab_rules_ts',true)<=0) return new WP_REST_Response(['ok'=>false,'error'=>'must_accept_usage_rules'],400);

            $allow=prismtek_stagepack_allowed();
            $cats=[
                'species'=>prismtek_stagepack_pick($r->get_param('species'),$allow['species'],'sprout'),
                'element'=>prismtek_stagepack_pick($r->get_param('element'),$allow['element'],'nature'),
                'personality'=>prismtek_stagepack_pick($r->get_param('personality'),$allow['personality'],'brave'),
                'shape'=>prismtek_stagepack_pick($r->get_param('shape'),$allow['shape'],'chibi'),
                'color'=>prismtek_stagepack_pick($r->get_param('colorMood'),$allow['color'],'vibrant'),
                'pose'=>prismtek_stagepack_pick($r->get_param('pose'),$allow['pose'],'battle ready'),
                'bg'=>prismtek_stagepack_pick($r->get_param('background'),$allow['bg'],'transparent'),
            ];
            $model=sanitize_key((string)$r->get_param('model')); if(!in_array($model,['pixflux','bitforge'],true)) $model='bitforge';
            $size=128;

            // style anchor for uniformity
            $anchorPrompt = "Create a style anchor creature sprite for Prism Creatures. Species {$cats['species']}, element {$cats['element']}, personality {$cats['personality']}. ".prismtek_stagepack_style_lock();
            $anchorReq=['description'=>$anchorPrompt,'image_size'=>['width'=>$size,'height'=>$size],'no_background'=>true];
            $anchorEndpoint='https://api.pixellab.ai/v1/generate-image-bitforge';
            $aResp=wp_remote_post($anchorEndpoint,['timeout'=>120,'headers'=>['Authorization'=>$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($anchorReq)]);
            if(is_wp_error($aResp)) return new WP_REST_Response(['ok'=>false,'error'=>'anchor_network_error'],502);
            $aCode=(int)wp_remote_retrieve_response_code($aResp); $aBody=(string)wp_remote_retrieve_body($aResp); $aJ=json_decode($aBody,true);
            if($aCode<200||$aCode>=300||!is_array($aJ)||empty($aJ['image']['base64'])) return new WP_REST_Response(['ok'=>false,'error'=>'anchor_failed','status'=>$aCode],502);
            $styleImage=['type'=>'base64','base64'=>(string)$aJ['image']['base64']];

            $endpoint = $model==='pixflux' ? 'https://api.pixellab.ai/v1/generate-image-pixflux' : 'https://api.pixellab.ai/v1/generate-image-bitforge';
            $stages=['baby','teen','adult'];
            $urls=[]; $usage=(float)($aJ['usage']['usd'] ?? 0);
            foreach($stages as $st){
                $prompt=prismtek_stagepack_build_prompt($cats,$st);
                $payload=['description'=>$prompt,'image_size'=>['width'=>$size,'height'=>$size],'no_background'=>true,'style_image'=>$styleImage,'style_strength'=>0.82];
                $resp=wp_remote_post($endpoint,['timeout'=>150,'headers'=>['Authorization'=>$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
                if(is_wp_error($resp)) return new WP_REST_Response(['ok'=>false,'error'=>'stage_network_error','stage'=>$st],502);
                $code=(int)wp_remote_retrieve_response_code($resp); $body=(string)wp_remote_retrieve_body($resp); $j=json_decode($body,true);
                if($code<200||$code>=300||!is_array($j)||empty($j['image']['base64'])) return new WP_REST_Response(['ok'=>false,'error'=>'stage_failed','stage'=>$st,'status'=>$code],502);
                $sv=prismtek_stagepack_save($uid,'stage-'.$cats['species'].'-'.$st,(string)$j['image']['base64']);
                if(!$sv['ok']) return new WP_REST_Response(['ok'=>false,'error'=>'stage_save_failed','stage'=>$st],500);
                $urls[$st]=$sv['url'];
                $usage += (float)($j['usage']['usd'] ?? 0);
            }

            $pack=['version'=>1,'generatedAt'=>time(),'categories'=>$cats,'model'=>$model,'usageUsd'=>round($usage,6),'stages'=>$urls];
            update_user_meta($uid,'prismtek_user_stage_pack',$pack);
            update_user_meta($uid,'prismtek_stage_sprite_mode','user');
            return rest_ensure_response(['ok'=>true,'pack'=>$pack]);
        }
    ]);

    register_rest_route('prismtek/v1','/pixellab/stages-pack',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $p=get_user_meta($uid,'prismtek_user_stage_pack',true);
            if(!is_array($p)) $p=[];
            return rest_ensure_response(['ok'=>true,'pack'=>$p,'mode'=>(string)get_user_meta($uid,'prismtek_stage_sprite_mode',true)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pixellab/stages-mode',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $mode=sanitize_key((string)$r->get_param('mode'));
            if(!in_array($mode,['official','user'],true)) $mode='official';
            update_user_meta($uid,'prismtek_stage_sprite_mode',$mode);
            return rest_ensure_response(['ok'=>true,'mode'=>$mode]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $nonce=wp_create_nonce('wp_rest');
    ?>
    <script id="prism-stagepack-ui-lock">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const H={'content-type':'application/json','X-WP-Nonce':<?php echo wp_json_encode($nonce); ?>};
      const genCard=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pixellab creature generator'));
      if(!genCard) return;

      const oldPrompt=genCard.querySelector('#pl-prompt');
      const oldGenerate=genCard.querySelector('#pl-generate');
      const oldUse=genCard.querySelector('#pl-use');
      if(oldGenerate) oldGenerate.style.display='none';
      if(oldUse) oldUse.style.display='none';
      if(oldPrompt){ oldPrompt.readOnly=true; oldPrompt.placeholder='Prompt is auto-built from categories below.'; }

      // enforce category-only fields
      const oldBuilder=document.getElementById('pl-category-builder');
      if(!oldBuilder){
        // safety: if missing, create strict category builder
        const d=document.createElement('details'); d.id='pl-category-builder'; d.open=true;
        d.innerHTML=`<summary><strong>Creature Prompt Categories (Required)</strong></summary>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
            <label>Species<select id="cb-species"><option>sprout</option><option>ember</option><option>tidal</option><option>volt</option></select></label>
            <label>Growth Stage Preview<select id="cb-stage"><option>baby</option><option>teen</option><option>adult</option></select></label>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
            <label>Element<select id="cb-element"><option>nature</option><option>fire</option><option>water</option><option>electric</option></select></label>
            <label>Personality<select id="cb-personality"><option>brave</option><option>curious</option><option>calm</option><option>chaotic</option><option>playful</option><option>mysterious</option></select></label>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
            <label>Body Shape<select id="cb-shape"><option>chibi</option><option>agile</option><option>tanky</option><option>serpentine</option></select></label>
            <label>Color Mood<select id="cb-color"><option>vibrant</option><option>pastel</option><option>neon</option><option>muted</option></select></label>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr; margin-top:8px;">
            <label>Pose<select id="cb-pose"><option>battle ready</option><option>idle</option><option>charging attack</option><option>victory stance</option></select></label>
            <label>Background<select id="cb-bg"><option>transparent</option><option>transparent with subtle aura</option></select></label>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr; margin-top:8px;">
            <button type="button" id="cb-build">Build Prompt</button>
            <button type="button" id="cb-gen3">Generate 3 Stages</button>
            <button type="button" id="cb-official">Use Official Stages</button>
          </div>
          <div class="pph-tool-row" style="grid-template-columns:1fr; margin-top:8px;"><button type="button" id="cb-user">Use My Generated Stages</button></div>
          <p id="cb-status" class="pph-status">Category mode active.</p>
          <div id="cb-gallery" style="display:grid;grid-template-columns:repeat(3,minmax(90px,1fr));gap:8px"></div>`;
        const modelRow=genCard.querySelector('#pl-model')?.closest('.pph-tool-row') || genCard.firstElementChild;
        modelRow?.parentElement?.insertBefore(d, modelRow.nextSibling);
      }

      const b=document.getElementById('pl-category-builder');
      const status=b?.querySelector('#cb-status');
      const gallery=b?.querySelector('#cb-gallery');
      const set=t=>{ if(status) status.textContent=t; };
      const val=id=>b?.querySelector('#'+id)?.value||'';

      function buildPrompt(){
        const txt=`Create a ${val('cb-stage')} stage ${val('cb-species')} creature for Prism Creatures. Element: ${val('cb-element')}. Personality: ${val('cb-personality')}. Body shape: ${val('cb-shape')}. Color mood: ${val('cb-color')}. Pose: ${val('cb-pose')}. Background: ${val('cb-bg')}. Pixel art, centered, readable silhouette, no text, no watermark.`;
        const p=genCard.querySelector('#pl-prompt'); if(p) p.value=txt;
      }

      async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}
      async function get(path){const r=await fetch(API+path,{credentials:'include'});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      function renderPack(pack){
        if(!gallery) return;
        const s=pack?.stages||{};
        gallery.innerHTML=['baby','teen','adult'].map(k=>s[k]?`<div style="border:1px solid #5f6ad1;background:#0d1334;padding:6px"><img src="${s[k]}" style="width:100%;image-rendering:pixelated;border:1px solid #4a57b8"/><div style="font-size:11px">${k}</div></div>`:'').join('');
      }

      b?.querySelector('#cb-build')?.addEventListener('click', ()=>{ buildPrompt(); set('Prompt rebuilt from categories.'); });
      b?.querySelector('#cb-gen3')?.addEventListener('click', async ()=>{
        buildPrompt();
        set('Generating baby/teen/adult set...');
        const out=await post('pixellab/generate-stages',{
          model: genCard.querySelector('#pl-model')?.value || 'bitforge',
          species: val('cb-species'),
          element: val('cb-element'),
          personality: val('cb-personality'),
          shape: val('cb-shape'),
          colorMood: val('cb-color'),
          pose: val('cb-pose'),
          background: val('cb-bg'),
        });
        if(!out.ok||!out.j.ok){ set('Generate failed: '+(out.j.error||'unknown')); return; }
        renderPack(out.j.pack||{});
        set(`3-stage set generated · est $${Number(out.j.pack?.usageUsd||0).toFixed(4)}`);
      });

      b?.querySelector('#cb-official')?.addEventListener('click', async ()=>{
        const out=await post('pixellab/stages-mode',{mode:'official'});
        set(out.ok&&out.j.ok?'Using official stage sprites.':'Failed to switch mode.');
      });
      b?.querySelector('#cb-user')?.addEventListener('click', async ()=>{
        const out=await post('pixellab/stages-mode',{mode:'user'});
        set(out.ok&&out.j.ok?'Using your generated stage sprites.':'Failed to switch mode.');
      });

      // load existing pack
      get('pixellab/stages-pack').then(out=>{ if(out.ok&&out.j.ok){ renderPack(out.j.pack||{}); } });
      buildPrompt();
    })();
    </script>
    <?php
}, 8900000);

// overlay selector update: user mode vs official
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-stage-mode-overlay">
    (function(){
      const API='/wp-json/prismtek/v1/';
      async function run(){
        const img=document.getElementById('prism-official-evo'); if(!img) return;
        const [petRes, modeRes, curRes] = await Promise.all([
          fetch(API+'pet/rpg?ts='+Date.now(),{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
          fetch(API+'pixellab/stages-pack?ts='+Date.now(),{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
          fetch(API+'creatures/curated-pack-v2?ts='+Date.now(),{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
        ]);
        if(!petRes.ok||!curRes.ok) return;
        const p=petRes.pet||{};
        const sp=(typeof p.species==='string'&&p.species)?p.species:'sprout';
        const st=(typeof p.stage==='string'&&p.stage)?p.stage:'baby';
        const mode=(modeRes.mode||'official');
        let url='';
        if(mode==='user'){ url=(modeRes.pack?.stages||{})[st]||''; }
        if(!url){ url=(((curRes.manifest||{}).species||{})[sp]||{})[st]||''; }
        if(url){ img.src=url+(url.includes('?')?'&':'?')+'v='+Date.now(); }
      }
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run);
      else run();
      setTimeout(run, 800);
    })();
    </script>
    <?php
}, 9000000);

// ===== Visible proof patch: always-render Stage-Lock banner + controls on Prism Creatures =====
add_filter('the_content', function($content){
    if (is_admin() || !function_exists('is_page') || !is_page('prism-creatures')) return $content;
    if (strpos($content, 'prism-stage-lock-banner') !== false) return $content;

    $banner = '<section class="prism-stage-lock-banner" style="margin:0 0 12px 0;padding:10px;border:2px solid #7d89ff;background:#0d1334;color:#eef2ff">'
        . '<strong>Stage-Lock Generator Mode Active</strong>'
        . '<div style="font-size:12px;margin-top:6px">Creatures now generate as a locked 3-stage set (baby + teen + adult) using category-only inputs to enforce style consistency.</div>'
        . '<div style="font-size:12px;margin-top:4px">If you do not see new controls, run a hard refresh: <code>Ctrl+Shift+R</code>.</div>'
        . '</section>';

    return $banner . $content;
}, 99999999);

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-stage-lock-visible-controls">
    (function(){
      const root=document.querySelector('.pph-wrap, .entry-content');
      if(!root) return;
      if(document.getElementById('prism-stage-lock-controls')) return;

      const box=document.createElement('article');
      box.className='pph-card';
      box.id='prism-stage-lock-controls';
      box.innerHTML=`<h4>3-Stage Creature Generator (Required)</h4>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Species<select id="s-species"><option>sprout</option><option>ember</option><option>tidal</option><option>volt</option></select></label>
          <label>Element<select id="s-element"><option>nature</option><option>fire</option><option>water</option><option>electric</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Personality<select id="s-personality"><option>brave</option><option>curious</option><option>calm</option><option>chaotic</option><option>playful</option><option>mysterious</option></select></label>
          <label>Body Shape<select id="s-shape"><option>chibi</option><option>agile</option><option>tanky</option><option>serpentine</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr;">
          <label>Color Mood<select id="s-color"><option>vibrant</option><option>pastel</option><option>neon</option><option>muted</option></select></label>
          <label>Pose<select id="s-pose"><option>battle ready</option><option>idle</option><option>charging attack</option><option>victory stance</option></select></label>
        </div>
        <div class="pph-tool-row" style="grid-template-columns:1fr 1fr 1fr;">
          <button id="s-generate3" type="button">Generate 3 Stages</button>
          <button id="s-official" type="button">Use Official Stages</button>
          <button id="s-user" type="button">Use My Stages</button>
        </div>
        <p id="s-status" class="pph-status">Ready.</p>`;
      root.appendChild(box);

      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const set=t=>{const el=document.getElementById('s-status'); if(el) el.textContent=t;};
      async function post(path,payload){const r=await fetch('/wp-json/prismtek/v1/'+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j};}

      document.getElementById('s-generate3').addEventListener('click', async ()=>{
        set('Generating all 3 stages...');
        const payload={
          model:'bitforge',
          species:document.getElementById('s-species').value,
          element:document.getElementById('s-element').value,
          personality:document.getElementById('s-personality').value,
          shape:document.getElementById('s-shape').value,
          colorMood:document.getElementById('s-color').value,
          pose:document.getElementById('s-pose').value,
          background:'transparent'
        };
        const out=await post('pixellab/generate-stages',payload);
        if(!out.ok||!out.j.ok){ set('Generate failed: '+(out.j.error||'unknown')); return; }
        set('3-stage pack generated successfully.');
      });
      document.getElementById('s-official').addEventListener('click', async ()=>{
        const out=await post('pixellab/stages-mode',{mode:'official'});
        set(out.ok&&out.j.ok?'Official stages enabled.':'Failed to switch.');
      });
      document.getElementById('s-user').addEventListener('click', async ()=>{
        const out=await post('pixellab/stages-mode',{mode:'user'});
        set(out.ok&&out.j.ok?'User stages enabled.':'Failed to switch.');
      });
    })();
    </script>
    <?php
}, 99999999);

// ===== Prism Creatures UI Unifier (2026-03-09e): dedupe controls, gallery-locked battle sprites, dex/gallery 1:1, showdown layout =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-creatures-ui-unifier-css">
      .prism-showdown-clean #pvp-screen{display:grid !important;grid-template-columns:1fr 64px 1fr !important;gap:8px;align-items:end;padding:10px !important}
      .prism-showdown-clean .pvp-side{display:grid;gap:6px}
      .prism-showdown-clean .pvp-side.opp{text-align:right}
      .prism-showdown-clean .pvp-vs-chip{display:grid;place-items:center;font-weight:900;letter-spacing:.08em;color:#f5f8ff;background:#131a44;border:1px solid #5f6ad1;height:42px;margin-bottom:24px}
      .prism-showdown-clean #pvp-you,
      .prism-showdown-clean #pvp-opp{width:112px !important;height:112px !important;object-fit:contain;image-rendering:pixelated}
      .prism-showdown-clean #pvp-log{max-height:190px !important}
      #prism-dex-grid-11{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-top:8px}
      #prism-dex-grid-11 .dex-item{border:1px solid #5f6ad1;background:#0d1334;padding:6px}
      #prism-dex-grid-11 .dex-item img{width:100%;height:auto;image-rendering:pixelated;border:1px solid #4a57b8;background:#0a0f2b}
      #prism-dex-grid-11 .dex-meta{font-size:11px;line-height:1.25;margin-top:4px}
    </style>
    <script id="prism-creatures-ui-unifier-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // 1) remove duplicate/legacy controls (leave the newest/main surfaces)
      ['#prism-stage-lock-controls','#pl-guided-builder','#pl-category-builder','#prism-dex-sections','#dex-grid-sync','#pvp-queue','#pvp-history']
        .forEach(sel=>qa(sel).forEach(el=>el.remove()));

      const dedupeCardsByTitle=(needle)=>{
        const cards=qa('.pph-card').filter(c=>((q('h4,h3',c)?.textContent||'').toLowerCase().includes(needle)));
        cards.slice(1).forEach(c=>c.remove());
      };
      dedupeCardsByTitle('pvp arena');
      dedupeCardsByTitle('pixellab creature generator');

      // 4) enforce clean showdown-like battle composition
      const pvpCard = qa('.pph-card').find(c=>((q('h4,h3',c)?.textContent||'').toLowerCase().includes('pvp arena')));
      if (pvpCard) {
        pvpCard.classList.add('prism-showdown-clean');
        const screen=q('#pvp-screen',pvpCard), you=q('#pvp-you',pvpCard), opp=q('#pvp-opp',pvpCard);
        if(screen && you && opp && !q('.pvp-vs-chip',screen)){
          const left=document.createElement('div'); left.className='pvp-side you';
          const right=document.createElement('div'); right.className='pvp-side opp';
          const youHp=q('#pvp-you-hp',pvpCard), oppHp=q('#pvp-opp-hp',pvpCard);
          const vs=document.createElement('div'); vs.className='pvp-vs-chip'; vs.textContent='VS';
          left.appendChild(you); if(youHp) left.appendChild(youHp);
          right.appendChild(opp); if(oppHp) right.appendChild(oppHp);
          screen.innerHTML=''; screen.appendChild(left); screen.appendChild(vs); screen.appendChild(right);
        }
      }

      // 2) force battle sprites to Prism Gallery system (user-first, official fallback)
      let galleryCache=null;
      const mapRows=(arr)=>{
        const m={};
        (arr||[]).forEach(r=>{
          const key=`${String(r.species||'').toLowerCase()}::${String(r.stage||'').toLowerCase()}`;
          if(key!=="::" && r.url) m[key]=r.url;
        });
        return m;
      };

      async function loadGallery(){
        const r=await fetch(API+'creatures/gallery-v2?ts='+Date.now(),{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        if(!r.ok||!j.ok) return null;
        galleryCache={official:j.official||[], user:j.user||[], officialMap:mapRows(j.official||[]), userMap:mapRows(j.user||[])};
        return galleryCache;
      }
      function pickSprite(species,stage){
        if(!galleryCache) return '';
        const k=`${String(species||'sprout').toLowerCase()}::${String(stage||'baby').toLowerCase()}`;
        return galleryCache.userMap[k] || galleryCache.officialMap[k] || '';
      }
      function hpStage(hp,maxHp){
        const h=Number(hp||0), m=Math.max(1,Number(maxHp||1));
        const r=h/m; if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby';
      }

      async function enforceBattleSprites(){
        const card = qa('.pph-card').find(c=>((q('h4,h3',c)?.textContent||'').toLowerCase().includes('pvp arena')));
        if(!card) return;
        const idInput=q('#pvp-id',card);
        const matchId=(idInput?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
        if(!matchId) return;

        const stateRes = await fetch(API+'pet/pvp/state-full?matchId='+encodeURIComponent(matchId)+'&ts='+Date.now(),{credentials:'include'})
          .then(async r=>({ok:r.ok,j:await r.json().catch(()=>({}))}))
          .catch(()=>({ok:false,j:{}}));
        if(!stateRes.ok || !stateRes.j.ok) return;
        const s=stateRes.j.state||{};
        const uid=Number(window.__prism_uid||0);
        const me=(Number(s?.participants?.a?.id||s.a)===uid)?(s.participants?.a||{}):(s.participants?.b||{});
        const op=(Number(s?.participants?.a?.id||s.a)===uid)?(s.participants?.b||{}):(s.participants?.a||{});
        const meId=Number(me.id||s.a||0), opId=Number(op.id||s.b||0);

        const meUrl=pickSprite(String(me.species||'sprout'), hpStage((s.hp||{})[meId],(s.maxHp||{})[meId]));
        const opUrl=pickSprite(String(op.species||'ember'), hpStage((s.hp||{})[opId],(s.maxHp||{})[opId]));

        const you=q('#pvp-you',card), opp=q('#pvp-opp',card);
        if(you && meUrl) you.src=meUrl+(meUrl.includes('?')?'&':'?')+'v='+Date.now();
        if(opp && opUrl) opp.src=opUrl+(opUrl.includes('?')?'&':'?')+'v='+Date.now();
      }

      // 3) Prism Dex = Prism Gallery 1:1
      async function syncDexToGallery(){
        const dex=q('#prism-curated-dex');
        if(!dex) return;
        if(!galleryCache) await loadGallery();
        if(!galleryCache) return;

        qa('#prism-dex-sections,#dex-grid-sync,.prism-dex-old',dex).forEach(el=>el.remove());

        let grid=q('#prism-dex-grid-11',dex);
        if(!grid){
          grid=document.createElement('div');
          grid.id='prism-dex-grid-11';
          dex.appendChild(grid);
        }

        const rows=[...(galleryCache.official||[]), ...(galleryCache.user||[])];
        grid.innerHTML = rows.length
          ? rows.map(r=>`<div class="dex-item"><img src="${r.url}" alt="${r.species||'creature'} ${r.stage||''}"><div class="dex-meta"><strong>${r.species||'unknown'}</strong> · ${r.stage||'baby'}<br>${r.source||'gallery'}</div></div>`).join('')
          : '<div style="font-size:12px;color:#d6dcff">No creatures in Prism Gallery yet.</div>';
      }

      // 5) clean broken/legacy UI paths and stale links/buttons
      function scrubLegacyUI(){
        qa('[data-legacy="true"], .legacy-ui, .broken-path').forEach(el=>el.remove());
        qa('a[href*="move-full"], a[href*="sprite-pack-v2"], a[href*="stage-lock"]').forEach(a=>a.remove());
      }

      (async ()=>{
        await loadGallery();
        scrubLegacyUI();
        await syncDexToGallery();
        await enforceBattleSprites();
        setInterval(async ()=>{ await loadGallery(); await syncDexToGallery(); await enforceBattleSprites(); scrubLegacyUI(); }, 3500);
      })();
    })();
    </script>
    <?php
}, 999999991);

// ===== Prism Creatures hotfix (2026-03-09f): top hero uses gallery sprite; Train triggers AI battle XP =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-creatures-topsprite-train-hotfix">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      const normSpecies=(s)=>{
        const v=String(s||'').toLowerCase();
        if(!v || v==='blob' || v==='default') return 'sprout';
        return v;
      };
      const stageByHp=(hp,max)=>{
        const h=Number(hp||0), m=Math.max(1,Number(max||1));
        const r=h/m; if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby';
      };
      const mapRows=(arr)=>{
        const m={};
        (arr||[]).forEach(r=>{ const k=`${String(r.species||'').toLowerCase()}::${String(r.stage||'').toLowerCase()}`; if(k!=="::" && r.url) m[k]=r.url; });
        return m;
      };

      async function getJson(path){
        const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        return {ok:r.ok,j};
      }

      async function syncTopCreatureSprite(){
        const [petOut, galOut] = await Promise.all([
          getJson('pet/rpg'),
          getJson('creatures/gallery-v2'),
        ]);
        if(!petOut.ok || !petOut.j?.ok || !galOut.ok || !galOut.j?.ok) return;

        const p=petOut.j.pet||{};
        const species=normSpecies(p.species);
        const stage=String(p.stage||'').toLowerCase() || stageByHp(p.health||100,100);

        const userMap=mapRows(galOut.j.user||[]);
        const offMap=mapRows(galOut.j.official||[]);
        const key=`${species}::${stage}`;
        const fallbackKey=`${species}::baby`;
        const url=userMap[key] || offMap[key] || userMap[fallbackKey] || offMap[fallbackKey] || '';
        if(!url) return;

        // top evolution/hero image slots
        qa('#prism-official-evo, #prism-user-stage-preview, .prism-creature-hero img, .creature-hero img').forEach(img=>{
          try {
            img.src=url+(url.includes('?')?'&':'?')+'v='+Date.now();
            img.style.imageRendering='pixelated';
            img.style.objectFit='contain';
          } catch(e) {}
        });
      }

      async function wireTrainToAIBattle(){
        const trainBtn=q('#pph-pet-train');
        if(!trainBtn || trainBtn.dataset.aiBattleBound==='1') return;
        trainBtn.dataset.aiBattleBound='1';

        const status=q('#pph-pet-status');
        const setStatus=(t)=>{ if(status) status.textContent=t; };

        trainBtn.addEventListener('click', async (e)=>{
          e.preventDefault();
          e.stopImmediatePropagation();
          setStatus('Starting AI training battle...');

          const nonce=q('meta[name="rest-nonce"]')?.content || '';
          const headers=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
          const r=await fetch(API+'pet/battle/spar',{
            method:'POST',
            credentials:'include',
            headers,
            body:JSON.stringify({mode:'ai'})
          });
          const j=await r.json().catch(()=>({}));
          if(!r.ok || !j.ok){ setStatus('AI battle failed.'); return; }

          const xp=Number(j.xpGained||0);
          const res=(j.result==='win')?'WIN':'LOSS';
          setStatus(`AI Battle ${res} · +${xp} XP`);

          // refresh pet panel + top sprite after battle
          try{
            const petOut=await getJson('pet/rpg');
            const pv=q('#pph-pet-view');
            if(petOut.ok && petOut.j?.ok && pv){
              const p=petOut.j.pet||{};
              const nm=String(p.name||'Prismo');
              const sp=normSpecies(p.species||'sprout');
              const st=String(p.stage||'baby');
              const lvl=Number(p.level||1), cur=Number(p.xp||0), nxt=Number(p.nextLevelXp||30);
              pv.innerHTML=`<strong>${nm}</strong><br>Species ${sp} · Stage ${st}<br>Lvl ${lvl} · XP ${cur}/${nxt}`;
            }
          }catch(_e){}
          syncTopCreatureSprite().catch(()=>{});
        }, true);
      }

      const run=()=>{ syncTopCreatureSprite().catch(()=>{}); wireTrainToAIBattle().catch(()=>{}); };
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', run);
      else run();
      setTimeout(run, 600);
      setInterval(syncTopCreatureSprite, 4000);
    })();
    </script>
    <?php
}, 999999995);

// ===== Prism Creatures premium restructure (2026-03-09g) =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/gallery-choice',[
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $choice=get_user_meta($uid,'prismtek_pet_gallery_choice_v1',true);
            if(!is_array($choice)) $choice=[];
            return rest_ensure_response(['ok'=>true,'choice'=>$choice]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/gallery-choice',[
        'methods'=>'POST',
        'permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $species=sanitize_key((string)$r->get_param('species'));
            $stage=sanitize_key((string)$r->get_param('stage'));
            $source=sanitize_key((string)$r->get_param('source'));
            $url=esc_url_raw((string)$r->get_param('url'));
            if($species==='') $species='sprout';
            if(!in_array($stage,['baby','teen','adult'],true)) $stage='baby';
            if(!in_array($source,['official','user','custom'],true)) $source='official';
            $choice=['species'=>$species,'stage'=>$stage,'source'=>$source,'url'=>$url,'updatedAt'=>time()];
            update_user_meta($uid,'prismtek_pet_gallery_choice_v1',$choice);
            return rest_ensure_response(['ok'=>true,'choice'=>$choice]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style id="prism-premium-creatures-css">
      .prism-premium-shell{display:grid;gap:12px}
      .prism-premium-card{border:1px solid #5d69d1;background:linear-gradient(180deg,#101741,#0b1030);padding:12px;color:#eef3ff}
      .prism-premium-title{margin:0 0 8px;font-size:15px;letter-spacing:.04em}
      .prism-hero{display:grid;grid-template-columns:180px 1fr;gap:12px;align-items:start}
      .prism-hero-stage{border:2px solid #6e7cff;background:#0b112f;display:grid;place-items:center;height:180px}
      .prism-hero-stage img{max-width:160px;max-height:160px;image-rendering:pixelated;object-fit:contain}
      .prism-stats{display:grid;gap:6px}
      .prism-bar{height:9px;border:1px solid #4f59a6;background:#1b1f45}
      .prism-bar>span{display:block;height:100%}
      .prism-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      .prism-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
      .prism-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
      .prism-btn{border:1px solid #6b76d8;background:#19215a;color:#eef3ff;padding:8px;cursor:pointer;font-weight:700}
      .prism-btn:hover{background:#24307b}
      .prism-input,.prism-select{width:100%;background:#0d143a;border:1px solid #5662c4;color:#eef3ff;padding:8px}
      .prism-status{margin-top:8px;font-size:12px;color:#c9d6ff;min-height:16px}
      #prism-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(108px,1fr));gap:8px;max-height:260px;overflow:auto}
      #prism-gallery-grid .item{border:1px solid #5260c7;background:#0c1338;padding:6px;cursor:pointer}
      #prism-gallery-grid .item.active{outline:2px solid #8aa0ff}
      #prism-gallery-grid img{width:100%;height:auto;image-rendering:pixelated;border:1px solid #4653b2;background:#070c24}
      .prism-battle{display:grid;grid-template-columns:1fr 60px 1fr;gap:8px;align-items:end;border:1px solid #5f6ad1;background:#0d1334;padding:8px}
      .prism-vs{display:grid;place-items:center;height:42px;border:1px solid #5f6ad1;background:#131a44;font-weight:900;letter-spacing:.1em}
      .prism-side img{width:112px;height:112px;object-fit:contain;image-rendering:pixelated}
      .prism-side.opp{text-align:right}
      @media (max-width:860px){.prism-hero{grid-template-columns:1fr}.prism-grid-4{grid-template-columns:1fr 1fr}.prism-battle{grid-template-columns:1fr}}
    </style>

    <script id="prism-premium-creatures-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      const host=q('.pph-wrap');
      if(!host) return;

      // remove duplicate/legacy cards from old stacks
      const keepWords=['creature showcase'];
      qa('.pph-card').forEach((c)=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        if(!t) return;
        if(keepWords.some(k=>t.includes(k))) return;
        c.style.display='none';
      });

      let shell=q('#prism-premium-shell',host);
      if(!shell){
        shell=document.createElement('section');
        shell.id='prism-premium-shell';
        shell.className='prism-premium-shell';
        shell.innerHTML=''
          +'<article class="prism-premium-card">'
          +'<h3 class="prism-premium-title">Prism Creature Partner</h3>'
          +'<div class="prism-hero">'
          +'<div class="prism-hero-stage"><img id="prism-hero-img" alt="Creature"></div>'
          +'<div class="prism-stats">'
          +'<div id="prism-hero-text">Loading creature...</div>'
          +'<div><small>Health</small><div class="prism-bar"><span id="bar-health" style="background:#5de28f;width:0%"></span></div></div>'
          +'<div><small>Energy</small><div class="prism-bar"><span id="bar-energy" style="background:#59d9ff;width:0%"></span></div></div>'
          +'<div><small>Happiness</small><div class="prism-bar"><span id="bar-happy" style="background:#f8c062;width:0%"></span></div></div>'
          +'<div><small>Hunger</small><div class="prism-bar"><span id="bar-hunger" style="background:#d98fff;width:0%"></span></div></div>'
          +'</div></div>'
          +'<div class="prism-grid-4" style="margin-top:8px">'
          +'<button class="prism-btn" id="btn-feed">Feed</button>'
          +'<button class="prism-btn" id="btn-play">Play</button>'
          +'<button class="prism-btn" id="btn-rest">Rest</button>'
          +'<button class="prism-btn" id="btn-train-ai">AI Battle Train (+XP)</button>'
          +'</div>'
          +'<div class="prism-grid-2" style="margin-top:8px">'
          +'<input class="prism-input" id="input-name" maxlength="20" placeholder="Creature name">'
          +'<button class="prism-btn" id="btn-name">Save Name</button>'
          +'</div>'
          +'<div id="prism-status-main" class="prism-status"></div>'
          +'</article>'

          +'<article class="prism-premium-card">'
          +'<h3 class="prism-premium-title">Sprite Studio</h3>'
          +'<div class="prism-grid-2">'
          +'<button class="prism-btn" id="btn-load-official">Browse Official Gallery</button>'
          +'<button class="prism-btn" id="btn-load-user">Browse User Gallery</button>'
          +'</div>'
          +'<div id="prism-gallery-grid" style="margin-top:8px"></div>'
          +'<div class="prism-grid-2" style="margin-top:8px">'
          +'<button class="prism-btn" id="btn-apply-selected">Use Selected Sprite</button>'
          +'<button class="prism-btn" id="btn-default-sprite">Use Default Sprite</button>'
          +'</div>'
          +'<form id="prism-upload-form" class="prism-grid-2" enctype="multipart/form-data" style="margin-top:8px">'
          +'<input class="prism-input" type="file" name="sheet" accept="image/png,image/webp,image/gif,image/jpeg" required>'
          +'<button class="prism-btn" type="submit">Upload My Custom Sprite</button>'
          +'</form>'
          +'<div id="prism-status-sprite" class="prism-status"></div>'
          +'</article>'

          +'<article class="prism-premium-card">'
          +'<h3 class="prism-premium-title">Battle Arena</h3>'
          +'<div class="prism-battle">'
          +'<div class="prism-side you"><img id="battle-you" alt="You"><div id="battle-you-hp">You</div></div>'
          +'<div class="prism-vs">VS</div>'
          +'<div class="prism-side opp"><img id="battle-opp" alt="AI"><div id="battle-opp-hp">AI Trainer</div></div>'
          +'</div>'
          +'<pre id="battle-log" style="margin-top:8px;max-height:180px;overflow:auto;background:#0a0f2b;border:1px solid #4f5aba;padding:8px;white-space:pre-wrap">Use AI Battle Train to fight and level up.</pre>'
          +'<div id="prism-status-battle" class="prism-status"></div>'
          +'</article>';
        host.prepend(shell);
      }

      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const normSpecies=(s)=>{ const v=String(s||'').toLowerCase(); return (!v||v==='blob'||v==='default')?'sprout':v; };
      const normStage=(s)=>{ const v=String(s||'').toLowerCase(); return (v==='teen'||v==='adult')?v:'baby'; };
      const hpStage=(hp,max)=>{ const r=Number(hp||0)/Math.max(1,Number(max||1)); if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby'; };

      let pet=null, gallery={official:[],user:[]}, selected=null, galleryMode='official', choice=null;

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function post(path,payload,form){
        const o={method:'POST',credentials:'include',headers:{'X-WP-Nonce':NONCE}};
        if(form){ o.body=form; } else { o.headers['content-type']='application/json'; o.body=JSON.stringify(payload||{}); }
        const r=await fetch(API+path,o); const j=await r.json().catch(()=>({})); return {ok:r.ok,j};
      }

      function stageUrl(species,stage){
        const all=[...(gallery.user||[]),...(gallery.official||[])];
        const s=normSpecies(species), st=normStage(stage);
        const hit=all.find(x=>String(x.species||'').toLowerCase()===s && String(x.stage||'').toLowerCase()===st);
        if(hit&&hit.url) return hit.url;
        const baby=all.find(x=>String(x.species||'').toLowerCase()===s && String(x.stage||'').toLowerCase()==='baby');
        return baby?.url || '';
      }

      function resolveHeroSprite(){
        const img=q('#prism-hero-img'); if(!img) return;
        let url='';
        if(choice?.url) url=choice.url;
        if(!url && pet){
          const st=choice?.stage || normStage(pet.stage||hpStage(pet.health||100,100));
          url=stageUrl(choice?.species||pet.species||'sprout', st);
        }
        if(url) img.src=url+(url.includes('?')?'&':'?')+'v='+Date.now();
      }

      function renderPet(){
        if(!pet) return;
        q('#prism-hero-text').innerHTML='<strong>'+(pet.name||'Prismo')+'</strong><br>Species '+normSpecies(pet.species)+' · Stage '+normStage(pet.stage)+'<br>Lvl '+Number(pet.level||1)+' · XP '+Number(pet.xp||0)+'/'+Number(pet.nextLevelXp||30)+' · W/L '+Number(pet.wins||0)+'/'+Number(pet.losses||0);
        q('#input-name').value=pet.name||'';
        q('#bar-health').style.width=clamp(Number(pet.health||0),0,100)+'%';
        q('#bar-energy').style.width=clamp(Number(pet.energy||0),0,100)+'%';
        q('#bar-happy').style.width=clamp(Number(pet.happiness||0),0,100)+'%';
        q('#bar-hunger').style.width=clamp(Number(pet.hunger||0),0,100)+'%';
        resolveHeroSprite();
        q('#battle-you').src=(q('#prism-hero-img').src||'');
        q('#battle-you-hp').textContent='You HP '+Number(pet.health||0)+'/100';
      }

      function renderGallery(){
        const grid=q('#prism-gallery-grid');
        const rows=galleryMode==='user'?(gallery.user||[]):(gallery.official||[]);
        grid.innerHTML = rows.length ? rows.map((r,idx)=>{
          const key=[r.species,r.stage,r.source,idx].join('|');
          const active = selected && selected.key===key ? ' active' : '';
          return '<div class="item'+active+'" data-key="'+key+'" data-url="'+(r.url||'')+'" data-species="'+(r.species||'')+'" data-stage="'+(r.stage||'')+'" data-source="'+(r.source||galleryMode)+'"><img src="'+(r.url||'')+'"><div style="font-size:11px;margin-top:4px"><strong>'+(r.species||'')+'</strong> · '+(r.stage||'')+'</div></div>';
        }).join('') : '<div style="font-size:12px">No sprites in this gallery.</div>';

        qa('.item',grid).forEach(el=>el.addEventListener('click',()=>{
          selected={key:el.dataset.key,url:el.dataset.url,species:el.dataset.species,stage:el.dataset.stage,source:el.dataset.source};
          qa('.item',grid).forEach(i=>i.classList.remove('active')); el.classList.add('active');
        }));
      }

      async function refreshAll(){
        const [petOut, galOut, choiceOut] = await Promise.all([get('pet/rpg'),get('creatures/gallery-v2'),get('pet/gallery-choice')]);
        if(petOut.ok && petOut.j?.ok) pet=petOut.j.pet||{};
        if(galOut.ok && galOut.j?.ok) gallery={official:galOut.j.official||[],user:galOut.j.user||[]};
        if(choiceOut.ok && choiceOut.j?.ok) choice=choiceOut.j.choice||null;
        renderPet(); renderGallery();
        // AI sprite mirror = use same as player for now but flipped visual in css transform if desired
        q('#battle-opp').src = stageUrl('ember','adult') || q('#battle-you').src;
      }

      const setMain=(t)=>{ const el=q('#prism-status-main'); if(el) el.textContent=t||''; };
      const setSprite=(t)=>{ const el=q('#prism-status-sprite'); if(el) el.textContent=t||''; };
      const setBattle=(t)=>{ const el=q('#prism-status-battle'); if(el) el.textContent=t||''; };

      async function care(action,extra={}){
        setMain('Working...');
        const out=await post('pet/action',Object.assign({action},extra||{}));
        if(!out.ok||!out.j?.ok){ setMain('Action failed.'); return; }
        pet=out.j.pet||pet; renderPet(); setMain('Done.');
      }

      async function trainAIBattle(){
        setBattle('Starting AI battle...');
        // Primary: AI spar endpoint
        let out=await post('pet/battle/spar',{});
        // Fallback: train endpoint if spar unavailable
        if(!out.ok || !out.j?.ok) out=await post('pet/train',{});
        if(!out.ok || !out.j?.ok){ setBattle('AI battle failed.'); return; }
        const xp=Number(out.j.xpGained||0);
        const result=(out.j.result==='win')?'WIN':'TRAIN';
        setBattle(result+' · +'+xp+' XP');
        q('#battle-log').textContent = (q('#battle-log').textContent + '\n' + (new Date().toLocaleTimeString()) + ' '+result+' +'+xp+' XP').split('\n').slice(-18).join('\n');
        await refreshAll();
      }

      q('#btn-feed')?.addEventListener('click',()=>care('feed'));
      q('#btn-play')?.addEventListener('click',()=>care('play'));
      q('#btn-rest')?.addEventListener('click',()=>care('rest'));
      q('#btn-name')?.addEventListener('click',()=>care('rename',{name:(q('#input-name')?.value||'').trim()}));
      q('#btn-train-ai')?.addEventListener('click',(e)=>{ e.preventDefault(); e.stopImmediatePropagation(); trainAIBattle(); },true);

      q('#btn-load-official')?.addEventListener('click',()=>{ galleryMode='official'; renderGallery(); setSprite('Showing official gallery.'); });
      q('#btn-load-user')?.addEventListener('click',()=>{ galleryMode='user'; renderGallery(); setSprite('Showing user gallery.'); });

      q('#btn-apply-selected')?.addEventListener('click', async ()=>{
        if(!selected){ setSprite('Pick a sprite first.'); return; }
        const out=await post('pet/gallery-choice',{species:selected.species,stage:selected.stage,source:selected.source,url:selected.url});
        if(!out.ok||!out.j?.ok){ setSprite('Could not save selection.'); return; }
        choice=out.j.choice||null;
        resolveHeroSprite(); q('#battle-you').src=(q('#prism-hero-img').src||'');
        setSprite('Selected sprite applied.');
      });

      q('#btn-default-sprite')?.addEventListener('click', async ()=>{
        const out=await post('pet/sprite-use-default',{});
        if(!out.ok||!out.j?.ok){ setSprite('Failed to reset sprite.'); return; }
        choice=null; await post('pet/gallery-choice',{species:normSpecies(pet?.species||'sprout'),stage:'baby',source:'official',url:''});
        await refreshAll(); setSprite('Default sprite restored.');
      });

      q('#prism-upload-form')?.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const form=e.currentTarget;
        const fd=new FormData(form);
        setSprite('Uploading custom sprite...');
        let out=await post('pet/sprite-upload-v2',null,fd);
        if((!out.ok||!out.j?.ok)) out=await post('pet/sprite-upload',null,fd);
        if(!out.ok||!out.j?.ok){ setSprite('Upload failed.'); return; }
        // use uploaded pack image as custom choice preview
        const p=out.j.pack||{};
        if(p.imageUrl){
          await post('pet/gallery-choice',{species:normSpecies(pet?.species||'sprout'),stage:normStage(pet?.stage||'baby'),source:'custom',url:p.imageUrl});
        }
        await refreshAll();
        setSprite('Custom sprite uploaded and applied.');
        form.reset();
      });

      refreshAll().catch(()=>{});
      setInterval(refreshAll, 8000);
    })();
    </script>
    <?php
}, 999999999);

// ===== Prism Creatures polish pass (2026-03-09h): showdown-like AI battle, single Dex, restore Pixellab tools =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <script id="prism-creatures-polish-h">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};

      // --- 1) keep exactly one Prism Creature Dex card ---
      const dexCards = qa('.pph-card').filter(c=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        return t.includes('prism creature dex') || c.id==='prism-curated-dex' || !!q('#prism-curated-dex',c);
      });
      dexCards.slice(1).forEach(c=>c.remove());

      // --- 2) restore Pixellab generator tools into visible premium flow ---
      const shell = q('#prism-premium-shell');
      const pixellabCard = qa('.pph-card').find(c=>((q('h3,h4',c)?.textContent||'').toLowerCase().includes('pixellab creature generator')));
      if (shell && pixellabCard) {
        pixellabCard.style.display='block';
        if (!pixellabCard.classList.contains('prism-premium-card')) pixellabCard.classList.add('prism-premium-card');
        if (!q('#prism-pixellab-mounted')) {
          const marker=document.createElement('div'); marker.id='prism-pixellab-mounted';
          shell.appendChild(marker);
          marker.replaceWith(pixellabCard);
        }
      }

      // --- 3) Upgrade AI battler to showdown-like turn system ---
      const battleCard = shell ? qa('article',shell).find(a=>((q('h3,h4',a)?.textContent||'').toLowerCase().includes('battle arena'))) : null;
      if (!battleCard) return;

      // replace simplistic battle body with showdown panel once
      if (!q('#showdown-ai-panel', battleCard)) {
        const panel=document.createElement('div');
        panel.id='showdown-ai-panel';
        panel.innerHTML=''
          +'<div class="prism-battle" id="showdown-field">'
          +'<div class="prism-side you"><img id="sd-you-img" alt="You"><div id="sd-you-name">You</div><div id="sd-you-hp">HP 100/100</div></div>'
          +'<div class="prism-vs">VS</div>'
          +'<div class="prism-side opp"><img id="sd-ai-img" alt="AI"><div id="sd-ai-name">AI Trainer</div><div id="sd-ai-hp">HP 100/100</div></div>'
          +'</div>'
          +'<div class="prism-grid-4" style="margin-top:8px">'
          +'<button class="prism-btn" id="sd-move-strike">Strike</button>'
          +'<button class="prism-btn" id="sd-move-guard">Guard</button>'
          +'<button class="prism-btn" id="sd-move-charge">Charge</button>'
          +'<button class="prism-btn" id="sd-move-heal">Heal</button>'
          +'</div>'
          +'<div class="prism-grid-2" style="margin-top:8px">'
          +'<button class="prism-btn" id="sd-start">Start AI Match</button>'
          +'<button class="prism-btn" id="sd-reset">Reset Match</button>'
          +'</div>'
          +'<pre id="sd-log" style="margin-top:8px;max-height:190px;overflow:auto;background:#0a0f2b;border:1px solid #4f5aba;padding:8px;white-space:pre-wrap">Showdown AI ready.</pre>'
          +'<div id="sd-status" class="prism-status"></div>';

        const oldLog=q('#battle-log', battleCard); if(oldLog) oldLog.remove();
        const oldS=q('#prism-status-battle', battleCard); if(oldS) oldS.remove();
        battleCard.appendChild(panel);
      }

      const normSpecies=s=>{const v=String(s||'').toLowerCase(); return (!v||v==='blob'||v==='default')?'sprout':v;};
      const getImg=()=>q('#prism-hero-img')?.src || '';
      const setStatus=t=>{ const el=q('#sd-status'); if(el) el.textContent=t||''; };
      const log=(t)=>{ const el=q('#sd-log'); if(!el) return; const rows=(el.textContent||'').split('\n').filter(Boolean); rows.push(t); el.textContent=rows.slice(-22).join('\n'); el.scrollTop=el.scrollHeight; };

      let petStats={name:'Prismo',species:'sprout',level:1,xp:0,next:30};
      let state={active:false,you:{hp:100,max:100,charge:0,guard:false},ai:{hp:100,max:100,charge:0,guard:false}};

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

      async function loadPet(){
        const out=await get('pet/rpg');
        if(out.ok&&out.j?.ok){
          const p=out.j.pet||{};
          petStats={name:String(p.name||'Prismo'),species:normSpecies(p.species),level:Number(p.level||1),xp:Number(p.xp||0),next:Number(p.nextLevelXp||30)};
        }
      }

      function render(){
        q('#sd-you-name').textContent = petStats.name+' (Lv '+petStats.level+')';
        q('#sd-you-hp').textContent = 'HP '+Math.max(0,state.you.hp)+'/'+state.you.max;
        q('#sd-ai-hp').textContent = 'HP '+Math.max(0,state.ai.hp)+'/'+state.ai.max;
        const hero=getImg(); if(hero) q('#sd-you-img').src=hero;
        if(!q('#sd-ai-img').src){ q('#sd-ai-img').src=hero || q('#prism-hero-img')?.src || ''; q('#sd-ai-img').style.transform='scaleX(-1)'; }
      }

      function aiChoose(){
        const roll=Math.random();
        if(state.ai.hp < 38 && roll < 0.28) return 'heal';
        if(state.ai.charge===0 && roll < 0.18) return 'charge';
        if(roll < 0.22) return 'guard';
        return 'strike';
      }

      function movePriority(m){ return m==='guard'?2:(m==='heal'?1:0); }
      function speed(){ return 10 + petStats.level*2; }

      function applyMove(side,move,target){
        const actor=state[side], enemy=state[target];
        if(actor.hp<=0||enemy.hp<=0) return;
        if(move==='guard'){ actor.guard=true; log((side==='you'?'You':'AI')+' used GUARD.'); return; }
        if(move==='charge'){ actor.charge=Math.min(14,actor.charge+8); log((side==='you'?'You':'AI')+' used CHARGE.'); return; }
        if(move==='heal'){
          const h=14+Math.floor(Math.random()*11);
          actor.hp=Math.min(actor.max,actor.hp+h);
          log((side==='you'?'You':'AI')+' healed '+h+'.');
          return;
        }
        if(move==='strike'){
          let d=12+Math.floor(Math.random()*11)+actor.charge;
          if(enemy.guard) d=Math.floor(d*0.55);
          enemy.hp=Math.max(0,enemy.hp-d);
          actor.charge=0;
          log((side==='you'?'You':'AI')+' used STRIKE for '+d+'.');
          return;
        }
      }

      async function rewardWin(){
        // grant XP via backend endpoint
        let out=await post('pet/battle/spar',{});
        if(!out.ok||!out.j?.ok) out=await post('pet/train',{});
        if(out.ok&&out.j?.ok){
          log('Reward: +'+Number(out.j.xpGained||0)+' XP');
          setStatus('Victory reward applied.');
        } else {
          setStatus('Win recorded, reward endpoint unavailable.');
        }
      }

      function endCheck(){
        if(state.you.hp<=0 && state.ai.hp<=0){ state.active=false; log('Draw.'); setStatus('Draw.'); return true; }
        if(state.ai.hp<=0){ state.active=false; log('You win!'); setStatus('You win! Granting XP...'); rewardWin(); return true; }
        if(state.you.hp<=0){ state.active=false; log('You lost.'); setStatus('AI wins. Try again.'); return true; }
        return false;
      }

      async function doTurn(playerMove){
        if(!state.active){ setStatus('Start a match first.'); return; }
        state.you.guard=false; state.ai.guard=false;
        const aiMove=aiChoose();

        const order=[
          {s:'you',m:playerMove,t:'ai',p:movePriority(playerMove),sp:speed()+2},
          {s:'ai',m:aiMove,t:'you',p:movePriority(aiMove),sp:speed()},
        ].sort((a,b)=> (b.p-a.p) || (b.sp-a.sp));

        for(const t of order){ applyMove(t.s,t.m,t.t); if(endCheck()) break; }
        render();
      }

      q('#sd-start')?.addEventListener('click', async ()=>{
        await loadPet();
        const base=100 + Math.max(0,(petStats.level-1))*6;
        state={active:true,you:{hp:base,max:base,charge:0,guard:false},ai:{hp:base,max:base,charge:0,guard:false}};
        q('#sd-log').textContent='Match started. Choose your move.';
        setStatus('AI match live.');
        render();
      });
      q('#sd-reset')?.addEventListener('click',()=>{ state.active=false; q('#sd-log').textContent='Match reset.'; setStatus('Reset.'); render(); });
      q('#sd-move-strike')?.addEventListener('click',()=>doTurn('strike'));
      q('#sd-move-guard')?.addEventListener('click',()=>doTurn('guard'));
      q('#sd-move-charge')?.addEventListener('click',()=>doTurn('charge'));
      q('#sd-move-heal')?.addEventListener('click',()=>doTurn('heal'));

      loadPet().then(render).catch(()=>{});
    })();
    </script>
    <?php
}, 1000000000);

// ===== Prism Creatures emergency PvP restore (2026-03-09i) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style id="prism-pvp-restore-css">
      #prism-pvp-online{border:1px solid #5f6ad1;background:linear-gradient(180deg,#101741,#0b1030);padding:12px;color:#eef3ff}
      #prism-pvp-online .row{display:grid;gap:8px;margin-top:8px}
      #prism-pvp-online .r3{grid-template-columns:1fr 1fr 1fr}
      #prism-pvp-online .r2{grid-template-columns:1fr 1fr}
      #prism-pvp-online .r4{grid-template-columns:repeat(4,1fr)}
      #prism-pvp-online input,#prism-pvp-online button{background:#0d143a;border:1px solid #5662c4;color:#eef3ff;padding:8px}
      #prism-pvp-screen{margin-top:8px;border:1px solid #5f6ad1;background:#0d1334;padding:8px;display:grid;grid-template-columns:1fr 64px 1fr;gap:8px;align-items:end}
      #prism-pvp-screen img{width:112px;height:112px;object-fit:contain;image-rendering:pixelated}
      #prism-pvp-screen .opp{text-align:right}
      #prism-pvp-screen .vs{display:grid;place-items:center;height:42px;border:1px solid #5f6ad1;background:#131a44;font-weight:900;letter-spacing:.08em}
      #prism-pvp-log{margin-top:8px;max-height:190px;overflow:auto;background:#0a0f2b;border:1px solid #4f5aba;padding:8px;white-space:pre-wrap}
      #prism-pvp-status{margin-top:8px;font-size:12px;color:#c9d6ff}
      @media (max-width:860px){#prism-pvp-online .r3,#prism-pvp-online .r4,#prism-pvp-online .r2,#prism-pvp-screen{grid-template-columns:1fr}}
    </style>
    <script id="prism-pvp-restore-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // Bring legacy PvP cards back if hidden (in case user wants both)
      qa('.pph-card').forEach(c=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        if(t.includes('pvp arena')) c.style.display='block';
      });

      const shell=q('#prism-premium-shell') || q('.pph-wrap');
      if(!shell) return;

      // remove previous failed AI-only panel from premium battle card
      qa('#showdown-ai-panel').forEach(el=>el.remove());

      let panel=q('#prism-pvp-online');
      if(!panel){
        panel=document.createElement('article');
        panel.id='prism-pvp-online';
        panel.className='prism-premium-card';
        panel.innerHTML=''
          +'<h3 style="margin:0">PvP Online (Showdown-Style)</h3>'
          +'<div class="row r3"><input id="pvp-user" placeholder="Opponent username"><button id="pvp-challenge">Challenge</button><button id="pvp-load">Load Match</button></div>'
          +'<div class="row r2"><input id="pvp-id" placeholder="Match ID"><button id="pvp-accept">Accept Match</button></div>'
          +'<div id="prism-pvp-screen">'
          +'  <div class="you"><img id="pvp-you" alt="You"><div id="pvp-you-hp">You HP</div></div>'
          +'  <div class="vs">VS</div>'
          +'  <div class="opp"><img id="pvp-opp" alt="Opponent"><div id="pvp-opp-hp">Opp HP</div></div>'
          +'</div>'
          +'<div class="row r4"><button class="pvp-m" data-m="strike">Strike</button><button class="pvp-m" data-m="guard">Guard</button><button class="pvp-m" data-m="charge">Charge</button><button class="pvp-m" data-m="heal">Heal</button></div>'
          +'<div class="row r2"><button id="pvp-spectate">Copy Spectator Link</button><button id="pvp-refresh">Refresh State</button></div>'
          +'<pre id="prism-pvp-log">No match loaded.</pre>'
          +'<div id="prism-pvp-status">Ready.</div>';
        shell.appendChild(panel);
      }

      const set=(t)=>{ const el=q('#prism-pvp-status'); if(el) el.textContent=t||''; };
      const logEl=q('#prism-pvp-log');
      let matchId=localStorage.getItem('prism_pvp_match_id')||'';

      const hpStage=(hp,max)=>{ const r=Number(hp||0)/Math.max(1,Number(max||1)); if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby'; };

      async function g(path){ const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function p(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

      let gallery={official:[],user:[]};
      function sprite(species,stage){
        const all=[...(gallery.user||[]),...(gallery.official||[])];
        const s=String(species||'sprout').toLowerCase(); const st=String(stage||'baby').toLowerCase();
        const hit=all.find(r=>String(r.species||'').toLowerCase()===s && String(r.stage||'').toLowerCase()===st);
        return hit?.url || all.find(r=>String(r.species||'').toLowerCase()===s && String(r.stage||'').toLowerCase()==='baby')?.url || '';
      }

      async function ensureGallery(){
        const out=await g('creatures/gallery-v2?ts='+Date.now());
        if(out.ok&&out.j?.ok) gallery={official:out.j.official||[],user:out.j.user||[]};
      }

      function renderState(s){
        if(!s) return;
        const uid=Number(window.__prism_uid||0);
        const a=s.participants?.a||{id:s.a,species:'sprout',displayName:'A'};
        const b=s.participants?.b||{id:s.b,species:'ember',displayName:'B'};
        const me=(Number(a.id)===uid)?a:b;
        const opp=(Number(a.id)===uid)?b:a;
        const meId=Number(me.id||s.a), oppId=Number(opp.id||s.b);
        const mh=Number((s.hp||{})[meId]||0), mm=Number((s.maxHp||{})[meId]||1);
        const oh=Number((s.hp||{})[oppId]||0), om=Number((s.maxHp||{})[oppId]||1);

        q('#pvp-you-hp').textContent=(me.displayName||'You')+' HP '+mh+'/'+mm;
        q('#pvp-opp-hp').textContent=(opp.displayName||'Opp')+' HP '+oh+'/'+om;

        const yi=sprite(me.species,hpStage(mh,mm));
        const oi=sprite(opp.species,hpStage(oh,om));
        if(yi) q('#pvp-you').src=yi+(yi.includes('?')?'&':'?')+'v='+Date.now();
        if(oi) q('#pvp-opp').src=oi+(oi.includes('?')?'&':'?')+'v='+Date.now();

        if(logEl) logEl.textContent=(s.log||[]).join('\n') || 'No logs yet.';
      }

      async function refresh(){
        const id=(q('#pvp-id')?.value||matchId||'').trim();
        if(!id){ set('Enter or create a match first.'); return; }
        matchId=id; localStorage.setItem('prism_pvp_match_id',matchId);
        const out=await g('pet/pvp/state-full?matchId='+encodeURIComponent(matchId));
        if(!out.ok||!out.j?.ok){ set('Load failed: '+(out.j?.error||'unknown')); return; }
        renderState(out.j.state||null);
        set((out.j.state?.done)?'Match finished.':'Match live.');
      }

      q('#pvp-challenge')?.addEventListener('click', async ()=>{
        const opp=(q('#pvp-user')?.value||'').trim();
        if(!opp){ set('Enter opponent username.'); return; }
        set('Creating challenge...');
        const out=await p('pet/pvp/challenge',{opponent:opp});
        if(!out.ok||!out.j?.ok){ set('Challenge failed: '+(out.j?.error||'unknown')); return; }
        matchId=out.j.matchId||''; if(q('#pvp-id')) q('#pvp-id').value=matchId;
        localStorage.setItem('prism_pvp_match_id',matchId);
        set('Challenge created. Share Match ID.');
      });

      q('#pvp-accept')?.addEventListener('click', async ()=>{
        const id=(q('#pvp-id')?.value||matchId||'').trim();
        if(!id){ set('No match id.'); return; }
        set('Accepting...');
        const out=await p('pet/pvp/accept',{matchId:id});
        if(!out.ok||!out.j?.ok){ set('Accept failed: '+(out.j?.error||'unknown')); return; }
        matchId=id; localStorage.setItem('prism_pvp_match_id',matchId);
        renderState(out.j.state||null);
        set('Accepted.');
      });

      q('#pvp-load')?.addEventListener('click', refresh);
      q('#pvp-refresh')?.addEventListener('click', refresh);

      qa('.pvp-m',panel).forEach(btn=>btn.addEventListener('click', async ()=>{
        const id=(q('#pvp-id')?.value||matchId||'').trim();
        if(!id){ set('No active match.'); return; }
        const move=btn.getAttribute('data-m');
        const out=await p('pet/pvp/move-pro',{matchId:id,move});
        if(!out.ok||!out.j?.ok){ set('Move failed: '+(out.j?.error||'unknown')); return; }
        renderState(out.j.state||null);
        set('Move submitted.');
      }));

      q('#pvp-spectate')?.addEventListener('click', async ()=>{
        const id=(q('#pvp-id')?.value||matchId||'').trim();
        if(!id){ set('No match loaded.'); return; }
        const out=await p('pet/pvp/spectate-link',{matchId:id});
        if(!out.ok||!out.j?.ok){ set('Could not create spectator link.'); return; }
        const url=out.j.url||'';
        try{ await navigator.clipboard.writeText(url); set('Spectator link copied.'); }
        catch{ set(url||'Spectator link ready.'); }
      });

      (async ()=>{
        await ensureGallery();
        if(matchId && q('#pvp-id')) q('#pvp-id').value=matchId;
        if(matchId) refresh();
      })();
    })();
    </script>
    <?php
}, 1000000001);

// ===== Prism Battle Engine V2 (2026-03-09j): modular showdown-style turn system =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-battle-v2-css">
      #prism-battle-v2-card{border:1px solid #5f6ad1;background:linear-gradient(180deg,#101741,#0b1030);padding:12px;color:#eef3ff}
      #prism-battle-v2-card .title{margin:0 0 8px}
      #prism-battle-v2-card .row{display:grid;gap:8px;margin-top:8px}
      #prism-battle-v2-card .r2{grid-template-columns:1fr 1fr}
      #prism-battle-v2-card .r4{grid-template-columns:repeat(4,1fr)}
      #prism-battle-v2-card select,#prism-battle-v2-card button{background:#0d143a;border:1px solid #5662c4;color:#eef3ff;padding:8px}
      #pb2-field{display:grid;grid-template-columns:1fr 70px 1fr;gap:10px;align-items:end;border:1px solid #5f6ad1;background:#0d1334;padding:10px}
      #pb2-field .side{display:grid;gap:6px}
      #pb2-field .side.opp{text-align:right}
      #pb2-field .vs{display:grid;place-items:center;height:44px;border:1px solid #5f6ad1;background:#131a44;font-weight:900;letter-spacing:.1em}
      #pb2-field img{width:116px;height:116px;object-fit:contain;image-rendering:pixelated}
      .pb2-name{font-weight:700}
      .pb2-desc{font-size:11px;color:#c9d6ff}
      .pb2-hp-wrap{display:grid;gap:4px}
      .pb2-hp-label{font-size:11px;color:#d7e2ff}
      .pb2-hp{height:10px;border:1px solid #4f59a6;background:#1b1f45;overflow:hidden}
      .pb2-hp > span{display:block;height:100%;width:100%;background:linear-gradient(90deg,#47d87f,#8cff7e);transition:width .35s ease, background-color .2s ease}
      #pb2-moves button{font-weight:700}
      #pb2-moves button[disabled]{opacity:.45;cursor:not-allowed}
      #pb2-log{margin-top:8px;max-height:210px;overflow:auto;background:#0a0f2b;border:1px solid #4f5aba;padding:8px;white-space:pre-wrap;font-size:12px;line-height:1.4}
      #pb2-status{margin-top:8px;font-size:12px;color:#c9d6ff;min-height:16px}
      @media (max-width:860px){#prism-battle-v2-card .r2,#prism-battle-v2-card .r4,#pb2-field{grid-template-columns:1fr}}
    </style>

    <script id="prism-battle-v2-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const host=q('#prism-premium-shell') || q('.pph-wrap');
      if(!host) return;

      // Hide older battle widgets so only one clear system remains.
      qa('.pph-card').forEach(c=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        if(t.includes('pvp arena') || t.includes('battle arena')) c.style.display='none';
      });
      qa('#prism-pvp-online,#showdown-ai-panel').forEach(el=>el.remove());

      let card=q('#prism-battle-v2-card');
      if(!card){
        card=document.createElement('article');
        card.id='prism-battle-v2-card';
        card.className='prism-premium-card';
        card.innerHTML=''
          +'<h3 class="title">Prism Battle Engine (Showdown Style)</h3>'
          +'<div class="row r2">'
          +'<label>My Creature <select id="pb2-player-select"></select></label>'
          +'<label>Opponent <select id="pb2-opp-select"></select></label>'
          +'</div>'
          +'<div class="row r2">'
          +'<button id="pb2-start">Start Battle</button>'
          +'<button id="pb2-restart" disabled>Restart</button>'
          +'</div>'
          +'<div id="pb2-field" class="row">'
          +'<div class="side you">'
          +'<img id="pb2-you-img" alt="Player creature">'
          +'<div class="pb2-name" id="pb2-you-name">-</div>'
          +'<div class="pb2-desc" id="pb2-you-type">-</div>'
          +'<div class="pb2-hp-wrap"><div class="pb2-hp-label" id="pb2-you-hp-label">HP 0/0</div><div class="pb2-hp"><span id="pb2-you-hp-bar"></span></div></div>'
          +'</div>'
          +'<div class="vs">VS</div>'
          +'<div class="side opp">'
          +'<img id="pb2-opp-img" alt="Opponent creature">'
          +'<div class="pb2-name" id="pb2-opp-name">-</div>'
          +'<div class="pb2-desc" id="pb2-opp-type">-</div>'
          +'<div class="pb2-hp-wrap"><div class="pb2-hp-label" id="pb2-opp-hp-label">HP 0/0</div><div class="pb2-hp"><span id="pb2-opp-hp-bar"></span></div></div>'
          +'</div>'
          +'</div>'
          +'<div id="pb2-moves" class="row r4"></div>'
          +'<pre id="pb2-log">Ready. Pick creatures and start battle.</pre>'
          +'<div id="pb2-status"></div>';
        host.appendChild(card);
      }

      // -------- Data modules (expandable) --------
      const TYPE_CHART = {
        fire:{nature:2,water:0.5,fire:0.5,steel:2,dragon:0.5},
        water:{fire:2,nature:0.5,electric:0.5,dragon:0.5},
        electric:{water:2,nature:0.5,electric:0.5,steel:0.5},
        nature:{water:2,fire:0.5,nature:0.5,dragon:0.5,steel:0.5},
        psychic:{poison:2,steel:0.5,psychic:0.5},
        steel:{nature:1,fire:0.5,water:0.5,electric:0.5,dragon:1},
        dragon:{dragon:2,steel:0.5},
        fairy:{dragon:2,fire:0.5,steel:0.5}
      };

      const MOVES = {
        solar_claw:{name:'Solar Claw',type:'nature',category:'physical',power:75,accuracy:95,effect:{targetStat:'def',stage:-1,chance:20}},
        pollen_burst:{name:'Pollen Burst',type:'nature',category:'special',power:80,accuracy:90,effect:{status:'poison',chance:20}},
        fairy_guard:{name:'Fairy Guard',type:'fairy',category:'status',power:0,accuracy:100,effect:{selfStat:'def',stage:1,chance:100}},
        sap_surge:{name:'Sap Surge',type:'nature',category:'status',power:0,accuracy:100,effect:{healPercent:28,chance:100}},

        plasma_bolt:{name:'Plasma Bolt',type:'electric',category:'special',power:85,accuracy:95,effect:{status:'paralyze',chance:20}},
        iron_ram:{name:'Iron Ram',type:'steel',category:'physical',power:90,accuracy:90,effect:{selfStat:'atk',stage:1,chance:20}},
        overclock:{name:'Overclock',type:'electric',category:'status',power:0,accuracy:100,effect:{selfStat:'spe',stage:2,chance:100}},
        static_field:{name:'Static Field',type:'electric',category:'status',power:0,accuracy:100,effect:{targetStat:'spe',stage:-1,chance:100}},

        blaze_fang:{name:'Blaze Fang',type:'fire',category:'physical',power:80,accuracy:95,effect:{status:'burn',chance:20}},
        comet_flare:{name:'Comet Flare',type:'fire',category:'special',power:95,accuracy:85,effect:{targetStat:'spd',stage:-1,chance:30}},
        dragon_focus:{name:'Dragon Focus',type:'dragon',category:'status',power:0,accuracy:100,effect:{selfStat:'spa',stage:1,chance:100}},
        ember_wall:{name:'Ember Wall',type:'fire',category:'status',power:0,accuracy:100,effect:{selfStat:'def',stage:1,chance:100}},

        tidal_lance:{name:'Tidal Lance',type:'water',category:'special',power:90,accuracy:95,effect:{targetStat:'atk',stage:-1,chance:20}},
        mind_ripple:{name:'Mind Ripple',type:'psychic',category:'special',power:75,accuracy:100,effect:{targetStat:'spd',stage:-1,chance:30}},
        aqua_barrier:{name:'Aqua Barrier',type:'water',category:'status',power:0,accuracy:100,effect:{selfStat:'spd',stage:1,chance:100}},
        lucid_pulse:{name:'Lucid Pulse',type:'psychic',category:'status',power:0,accuracy:100,effect:{healPercent:20,selfStat:'spa',stage:1,chance:100}},
      };

      const CREATURES = {
        spriglit:{
          id:'spriglit',name:'Spriglit',types:['nature','fairy'],
          description:'A bright forest familiar that heals allies with living sap.',
          stats:{hp:92,atk:80,def:88,spa:94,spd:96,spe:72},
          moves:['solar_claw','pollen_burst','fairy_guard','sap_surge']
        },
        voltigon:{
          id:'voltigon',name:'Voltigon',types:['electric','steel'],
          description:'A high-voltage striker that snowballs speed and pressure.',
          stats:{hp:84,atk:95,def:82,spa:86,spd:80,spe:104},
          moves:['plasma_bolt','iron_ram','overclock','static_field']
        },
        pyronyx:{
          id:'pyronyx',name:'Pyronyx',types:['fire','dragon'],
          description:'A fierce glass-cannon drake with explosive burst turns.',
          stats:{hp:88,atk:98,def:78,spa:108,spd:85,spe:92},
          moves:['blaze_fang','comet_flare','dragon_focus','ember_wall']
        },
        aqualume:{
          id:'aqualume',name:'Aqualume',types:['water','psychic'],
          description:'A tactical caster that controls tempo with debuffs and sustain.',
          stats:{hp:96,atk:70,def:86,spa:102,spd:101,spe:84},
          moves:['tidal_lance','mind_ripple','aqua_barrier','lucid_pulse']
        }
      };

      // -------- Battle engine --------
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const stageMultiplier=(s)=>{
        const st=clamp(Number(s||0),-6,6);
        if(st>=0) return (2+st)/2;
        return 2/(2+Math.abs(st));
      };

      function buildFighter(creatureId, level=50){
        const c=CREATURES[creatureId];
        const st={atk:0,def:0,spa:0,spd:0,spe:0,acc:0,eva:0};
        return {
          id:c.id,name:c.name,types:[...c.types],description:c.description,base:c.stats,moves:[...c.moves],
          level,status:null,statStages:st,
          hp:Math.floor(c.stats.hp + level),
          maxHp:Math.floor(c.stats.hp + level),
          fainted:false
        };
      }

      function typeEffectiveness(moveType, targetTypes){
        let mod=1;
        targetTypes.forEach(t=>{ const m=(TYPE_CHART[moveType]&&TYPE_CHART[moveType][t]) ? TYPE_CHART[moveType][t] : 1; mod*=m; });
        return mod;
      }

      function computeStat(f, statName){
        const base=Number(f.base[statName]||1);
        return base * stageMultiplier(f.statStages[statName]||0);
      }

      function accuracyCheck(move, attacker, defender){
        const accStage=stageMultiplier(attacker.statStages.acc||0);
        const evaStage=stageMultiplier(defender.statStages.eva||0);
        const chance=clamp((move.accuracy||100) * (accStage/evaStage), 1, 100);
        return Math.random()*100 <= chance;
      }

      function simplifiedDamage(attacker, defender, move){
        if(move.power<=0) return 0;
        const atkStat = move.category==='physical' ? computeStat(attacker,'atk') : computeStat(attacker,'spa');
        const defStat = move.category==='physical' ? computeStat(defender,'def') : computeStat(defender,'spd');
        const base = (((2*attacker.level/5 + 2) * move.power * (atkStat/Math.max(1,defStat))) / 50) + 2;
        const stab = attacker.types.includes(move.type) ? 1.5 : 1;
        const typeMod = typeEffectiveness(move.type, defender.types);
        const random = 0.85 + Math.random()*0.15;
        const burn = (attacker.status==='burn' && move.category==='physical') ? 0.5 : 1;
        const dmg = Math.floor(Math.max(1, base * stab * typeMod * random * burn));
        return {dmg, stab, typeMod};
      }

      function applyEndTurnStatus(f){
        if(f.fainted) return {text:''};
        let text='';
        if(f.status==='poison'){
          const d=Math.max(1,Math.floor(f.maxHp*0.08));
          f.hp=Math.max(0,f.hp-d);
          text=`${f.name} is hurt by poison (${d}).`;
        }
        if(f.status==='burn'){
          const d=Math.max(1,Math.floor(f.maxHp*0.06));
          f.hp=Math.max(0,f.hp-d);
          text=(text?text+' ':'')+`${f.name} is hurt by burn (${d}).`;
        }
        if(f.hp<=0){ f.hp=0; f.fainted=true; }
        return {text};
      }

      function maybeApplyEffect(move, attacker, defender, battleLog){
        const ef=move.effect; if(!ef) return;
        const roll=()=>Math.random()*100 <= (ef.chance||0);

        if(ef.selfStat && roll()){
          attacker.statStages[ef.selfStat]=clamp((attacker.statStages[ef.selfStat]||0)+(ef.stage||0),-6,6);
          battleLog.push(`${attacker.name}'s ${ef.selfStat.toUpperCase()} changed by ${ef.stage>0?'+':''}${ef.stage}.`);
        }
        if(ef.targetStat && roll()){
          defender.statStages[ef.targetStat]=clamp((defender.statStages[ef.targetStat]||0)+(ef.stage||0),-6,6);
          battleLog.push(`${defender.name}'s ${ef.targetStat.toUpperCase()} changed by ${ef.stage>0?'+':''}${ef.stage}.`);
        }
        if(ef.status && !defender.status && roll()){
          defender.status=ef.status;
          battleLog.push(`${defender.name} is now ${ef.status.toUpperCase()}!`);
        }
        if(ef.healPercent && roll()){
          const h=Math.max(1,Math.floor(attacker.maxHp*(ef.healPercent/100)));
          attacker.hp=Math.min(attacker.maxHp,attacker.hp+h);
          battleLog.push(`${attacker.name} restored ${h} HP.`);
        }
      }

      function pickAIMove(ai, player){
        // weighted: prefer KO, then super-effective, then buffs/debuffs when behind.
        const scored=ai.moves.map(mid=>{
          const m=MOVES[mid];
          let score=10 + Math.random()*4;
          if(m.power>0){
            const sim=simplifiedDamage(ai,player,m);
            score += sim.dmg/8;
            if(sim.typeMod>1) score += 6;
            if(player.hp-sim.dmg<=0) score += 20;
          } else {
            if(ai.hp/ai.maxHp < 0.45 && m.effect?.healPercent) score += 14;
            if(player.hp/player.maxHp > 0.6 && m.effect?.targetStat) score += 7;
          }
          return {mid,score};
        }).sort((a,b)=>b.score-a.score);

        const top=scored.slice(0,2);
        return top[Math.floor(Math.random()*top.length)].mid;
      }

      function speedStat(f){
        let sp=computeStat(f,'spe');
        if(f.status==='paralyze') sp*=0.5;
        return sp;
      }

      function doMove(attacker, defender, moveId, battleLog){
        if(attacker.fainted) return;
        const move=MOVES[moveId];
        battleLog.push(`${attacker.name} used ${move.name}!`);

        if(!accuracyCheck(move, attacker, defender)){
          battleLog.push('It missed!');
          return;
        }

        if(move.power>0){
          const out=simplifiedDamage(attacker, defender, move);
          defender.hp=Math.max(0, defender.hp-out.dmg);
          if(out.typeMod>=2) battleLog.push('It is super effective!');
          else if(out.typeMod<1) battleLog.push('It is not very effective...');
          battleLog.push(`${defender.name} took ${out.dmg} damage.`);
          if(defender.hp<=0){ defender.hp=0; defender.fainted=true; }
        }

        maybeApplyEffect(move, attacker, defender, battleLog);
      }

      function battleRound(state, playerMoveId){
        const log=[];
        if(state.over) return log;

        const aiMoveId=pickAIMove(state.ai, state.player);
        const pSp=speedStat(state.player);
        const aSp=speedStat(state.ai);

        const first = (pSp===aSp) ? (Math.random()<0.5?'player':'ai') : (pSp>aSp?'player':'ai');
        const second = first==='player'?'ai':'player';
        log.push(`Turn ${state.turn}: ${first==='player'?state.player.name:state.ai.name} moves first.`);

        const actor1=state[first], target1=state[second], m1=(first==='player')?playerMoveId:aiMoveId;
        doMove(actor1,target1,m1,log);
        if(target1.fainted){
          log.push(`${target1.name} fainted!`);
          state.over=true;
          state.winner=(first==='player')?'player':'ai';
        }

        if(!state.over){
          const actor2=state[second], target2=state[first], m2=(second==='player')?playerMoveId:aiMoveId;
          doMove(actor2,target2,m2,log);
          if(target2.fainted){
            log.push(`${target2.name} fainted!`);
            state.over=true;
            state.winner=(second==='player')?'player':'ai';
          }
        }

        const e1=applyEndTurnStatus(state.player); if(e1.text) log.push(e1.text);
        const e2=applyEndTurnStatus(state.ai); if(e2.text) log.push(e2.text);
        if(!state.over && state.player.fainted){ state.over=true; state.winner='ai'; log.push(`${state.player.name} fainted!`); }
        if(!state.over && state.ai.fainted){ state.over=true; state.winner='player'; log.push(`${state.ai.name} fainted!`); }

        if(state.over){
          log.push(state.winner==='player' ? 'You win the battle!' : 'You were defeated.');
        }

        state.turn += 1;
        return log;
      }

      // -------- UI controller --------
      const playerSel=q('#pb2-player-select',card);
      const oppSel=q('#pb2-opp-select',card);
      const movesWrap=q('#pb2-moves',card);
      const logEl=q('#pb2-log',card);
      const statusEl=q('#pb2-status',card);
      const startBtn=q('#pb2-start',card);
      const restartBtn=q('#pb2-restart',card);

      let gameState=null;
      let gallery={official:[],user:[]};

      function setStatus(t){ if(statusEl) statusEl.textContent=t||''; }
      function appendLog(lines){
        if(!logEl) return;
        const existing=(logEl.textContent||'').split('\n').filter(Boolean);
        logEl.textContent=[...existing,...lines].slice(-60).join('\n');
        logEl.scrollTop=logEl.scrollHeight;
      }

      function hpBarColor(r){ if(r>0.5) return 'linear-gradient(90deg,#47d87f,#8cff7e)'; if(r>0.2) return 'linear-gradient(90deg,#d9d247,#ffd770)'; return 'linear-gradient(90deg,#e15a5a,#ff8585)'; }

      function spriteFor(creatureId){
        const c=CREATURES[creatureId]; if(!c) return '';
        const all=[...(gallery.user||[]),...(gallery.official||[])];
        const tryStages=['adult','teen','baby'];
        for(const st of tryStages){
          const hit=all.find(r=>String(r.species||'').toLowerCase()===c.id.replace('spriglit','sprout').replace('voltigon','volt').replace('pyronyx','ember').replace('aqualume','tidal') && String(r.stage||'').toLowerCase()===st);
          if(hit?.url) return hit.url;
        }
        return q('#prism-hero-img')?.src || '';
      }

      function render(){
        if(!gameState) return;
        const p=gameState.player, a=gameState.ai;

        q('#pb2-you-name',card).textContent=`${p.name} Lv${p.level}`;
        q('#pb2-you-type',card).textContent=`${p.types.join(' / ')} · ${p.description}`;
        q('#pb2-opp-name',card).textContent=`${a.name} Lv${a.level}`;
        q('#pb2-opp-type',card).textContent=`${a.types.join(' / ')} · ${a.description}`;

        const pRatio=clamp(p.hp/p.maxHp,0,1), aRatio=clamp(a.hp/a.maxHp,0,1);
        q('#pb2-you-hp-label',card).textContent=`HP ${p.hp}/${p.maxHp}`;
        q('#pb2-opp-hp-label',card).textContent=`HP ${a.hp}/${a.maxHp}`;
        const pBar=q('#pb2-you-hp-bar',card), aBar=q('#pb2-opp-hp-bar',card);
        pBar.style.width=(pRatio*100).toFixed(1)+'%'; pBar.style.background=hpBarColor(pRatio);
        aBar.style.width=(aRatio*100).toFixed(1)+'%'; aBar.style.background=hpBarColor(aRatio);

        const yi=q('#pb2-you-img',card), oi=q('#pb2-opp-img',card);
        const ys=spriteFor(p.id), os=spriteFor(a.id);
        if(ys) yi.src=ys + (ys.includes('?')?'&':'?') + 'v=' + Date.now();
        if(os) oi.src=os + (os.includes('?')?'&':'?') + 'v=' + Date.now();
        oi.style.transform='scaleX(-1)';

        movesWrap.innerHTML='';
        p.moves.forEach(mid=>{
          const m=MOVES[mid];
          const b=document.createElement('button');
          b.textContent=`${m.name} (${m.type})`;
          b.title=`${m.category.toUpperCase()} · Power ${m.power||'-'} · Acc ${m.accuracy}%`;
          b.disabled = gameState.over;
          b.addEventListener('click', ()=>onPlayerMove(mid));
          movesWrap.appendChild(b);
        });

        restartBtn.disabled = !gameState.over;
      }

      async function onPlayerMove(mid){
        if(!gameState || gameState.over) return;
        const lines=battleRound(gameState, mid);
        appendLog(lines);
        render();
        if(gameState.over){
          setStatus(gameState.winner==='player'?'Victory!':'Defeat.');
          // Optional XP reward integration
          if(gameState.winner==='player'){
            try{
              let r=await fetch('/wp-json/prismtek/v1/pet/battle/spar',{method:'POST',credentials:'include',headers:{'content-type':'application/json'}});
              let j=await r.json().catch(()=>({}));
              if(!r.ok||!j.ok){
                r=await fetch('/wp-json/prismtek/v1/pet/train',{method:'POST',credentials:'include',headers:{'content-type':'application/json'}});
                j=await r.json().catch(()=>({}));
              }
              if(r.ok&&j.ok) appendLog([`Reward: +${Number(j.xpGained||0)} XP`]);
            }catch(_e){}
          }
        } else {
          setStatus('Choose your next move.');
        }
      }

      function buildSelects(){
        const opts=Object.values(CREATURES).map(c=>`<option value="${c.id}">${c.name}</option>`).join('');
        playerSel.innerHTML=opts;
        oppSel.innerHTML=opts;
        playerSel.value='spriglit';
        oppSel.value='voltigon';
      }

      async function loadGallery(){
        try{
          const r=await fetch('/wp-json/prismtek/v1/creatures/gallery-v2?ts='+Date.now(),{credentials:'include'});
          const j=await r.json().catch(()=>({}));
          if(r.ok&&j.ok) gallery={official:j.official||[],user:j.user||[]};
        }catch(_e){}
      }

      function startBattle(){
        const pId=playerSel.value;
        const oId=oppSel.value;
        if(!CREATURES[pId]||!CREATURES[oId]) return;
        if(pId===oId){ setStatus('Choose different creatures for player and opponent.'); return; }
        gameState={
          turn:1,
          over:false,
          winner:null,
          player:buildFighter(pId,50),
          ai:buildFighter(oId,50)
        };
        logEl.textContent='Battle started!';
        setStatus('Choose your move.');
        render();
      }

      startBtn.addEventListener('click', startBattle);
      restartBtn.addEventListener('click', startBattle);

      loadGallery().then(()=>{ buildSelects(); setStatus('Ready.'); });
    })();
    </script>
    <?php
}, 1000000100);

// ===== Prism Battle Unifier (2026-03-09k): rename existing species + single Pokemon-style window =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-battle-v3-css">
      #prism-battle-v3{border:2px solid #6d79de;background:linear-gradient(180deg,#0f1540,#090e2a);padding:12px;color:#eef3ff}
      #pb3-scene{position:relative;border:2px solid #5f6ad1;background:linear-gradient(180deg,#79b8ff 0%,#7fd4ff 42%,#6dbf6d 43%,#6da96d 100%);min-height:290px;overflow:hidden}
      #pb3-scene .plat{position:absolute;width:120px;height:28px;background:rgba(30,40,28,.35);border:2px solid rgba(20,30,18,.45);border-radius:50%}
      #pb3-opp-plat{top:48px;right:36px}
      #pb3-you-plat{bottom:38px;left:46px}
      #pb3-opp-sprite,#pb3-you-sprite{position:absolute;image-rendering:pixelated;object-fit:contain;filter:drop-shadow(0 2px 0 rgba(0,0,0,.35))}
      #pb3-opp-sprite{top:8px;right:52px;width:128px;height:128px;transform:scaleX(-1)}
      #pb3-you-sprite{bottom:14px;left:56px;width:148px;height:148px}
      .pb3-box{position:absolute;background:#f9f9ff;color:#101225;border:2px solid #1e2149;padding:8px 10px;min-width:230px;max-width:45%}
      .pb3-box .name{font-weight:900}
      .pb3-box .meta{font-size:11px;opacity:.8}
      .pb3-box .hp{margin-top:6px;height:8px;border:1px solid #384286;background:#dbe1ff}
      .pb3-box .hp>span{display:block;height:100%;width:100%;background:#4bd67e;transition:width .28s ease}
      #pb3-opp-box{top:14px;left:14px}
      #pb3-you-box{right:14px;bottom:14px}

      #pb3-panel{margin-top:10px;border:2px solid #5f6ad1;background:#0b1031;padding:10px}
      #pb3-log{height:116px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px;white-space:pre-wrap;font-size:12px}
      #pb3-controls{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
      #pb3-controls .left,#pb3-controls .right{display:grid;gap:8px}
      #pb3-controls select,#pb3-controls button{width:100%;background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px}
      #pb3-moves{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #pb3-moves button{font-weight:800}
      #pb3-status{margin-top:8px;font-size:12px;color:#c8d5ff;min-height:16px}
      @media (max-width:860px){#pb3-controls{grid-template-columns:1fr}.pb3-box{max-width:68%}}
    </style>

    <script id="prism-battle-v3-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const host=q('#prism-premium-shell')||q('.pph-wrap'); if(!host) return;

      // 1) Unify: hide previous battle surfaces and keep one battle window.
      qa('#prism-battle-v2-card,#prism-pvp-online,#showdown-ai-panel').forEach(el=>el.remove());
      qa('.pph-card').forEach(c=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        if(t.includes('pvp arena')||t.includes('battle arena')) c.style.display='none';
      });

      // 2) Rename existing species keys to premium names (display layer only, no data break).
      const SPECIES_MAP={
        sprout:{name:'Spriglit',types:['Nature','Fairy'],desc:'Forest tactician with sustain and utility.'},
        volt:{name:'Voltigon',types:['Electric','Steel'],desc:'High-speed striker with momentum pressure.'},
        ember:{name:'Pyronyx',types:['Fire','Dragon'],desc:'Burst attacker with volatile offense.'},
        tidal:{name:'Aqualume',types:['Water','Psychic'],desc:'Control caster with tempo tools.'},
        shade:{name:'Noctivyre',types:['Dark','Ghost'],desc:'Elusive disruptor from the shadow lane.'}
      };

      // 3) Data modules (using existing species ids).
      const TYPE={
        fire:{nature:2,water:.5,fire:.5,dragon:.5,steel:2},
        water:{fire:2,nature:.5,electric:.5,dragon:.5},
        electric:{water:2,nature:.5,electric:.5,steel:.5},
        nature:{water:2,fire:.5,nature:.5,dragon:.5,steel:.5},
        psychic:{ghost:1,dark:.5,steel:.5},
        steel:{fairy:2,rock:2,ice:2,fire:.5,water:.5,electric:.5,steel:.5},
        dragon:{dragon:2,steel:.5,fairy:0},
        fairy:{dragon:2,fire:.5,steel:.5,dark:2},
        dark:{psychic:2,fairy:.5},
        ghost:{psychic:2,dark:.5}
      };

      const MOVES={
        solar_claw:{name:'Solar Claw',type:'nature',cat:'physical',power:75,acc:95,effect:{target:'def',stage:-1,chance:20}},
        pollen_burst:{name:'Pollen Burst',type:'nature',cat:'special',power:80,acc:90,effect:{status:'poison',chance:20}},
        fairy_guard:{name:'Fairy Guard',type:'fairy',cat:'status',power:0,acc:100,effect:{self:'def',stage:1,chance:100}},
        sap_surge:{name:'Sap Surge',type:'nature',cat:'status',power:0,acc:100,effect:{heal:28,chance:100}},

        plasma_bolt:{name:'Plasma Bolt',type:'electric',cat:'special',power:85,acc:95,effect:{status:'paralyze',chance:20}},
        iron_ram:{name:'Iron Ram',type:'steel',cat:'physical',power:90,acc:90,effect:{self:'atk',stage:1,chance:20}},
        overclock:{name:'Overclock',type:'electric',cat:'status',power:0,acc:100,effect:{self:'spe',stage:2,chance:100}},
        static_field:{name:'Static Field',type:'electric',cat:'status',power:0,acc:100,effect:{target:'spe',stage:-1,chance:100}},

        blaze_fang:{name:'Blaze Fang',type:'fire',cat:'physical',power:80,acc:95,effect:{status:'burn',chance:20}},
        comet_flare:{name:'Comet Flare',type:'fire',cat:'special',power:95,acc:85,effect:{target:'spd',stage:-1,chance:30}},
        dragon_focus:{name:'Dragon Focus',type:'dragon',cat:'status',power:0,acc:100,effect:{self:'spa',stage:1,chance:100}},
        ember_wall:{name:'Ember Wall',type:'fire',cat:'status',power:0,acc:100,effect:{self:'def',stage:1,chance:100}},

        tidal_lance:{name:'Tidal Lance',type:'water',cat:'special',power:90,acc:95,effect:{target:'atk',stage:-1,chance:20}},
        mind_ripple:{name:'Mind Ripple',type:'psychic',cat:'special',power:75,acc:100,effect:{target:'spd',stage:-1,chance:30}},
        aqua_barrier:{name:'Aqua Barrier',type:'water',cat:'status',power:0,acc:100,effect:{self:'spd',stage:1,chance:100}},
        lucid_pulse:{name:'Lucid Pulse',type:'psychic',cat:'status',power:0,acc:100,effect:{heal:20,self:'spa',stage:1,chance:100}},

        shade_slash:{name:'Shade Slash',type:'dark',cat:'physical',power:82,acc:95,effect:{target:'def',stage:-1,chance:15}},
        phantom_echo:{name:'Phantom Echo',type:'ghost',cat:'special',power:88,acc:92,effect:{target:'spa',stage:-1,chance:25}},
        voidstep:{name:'Voidstep',type:'ghost',cat:'status',power:0,acc:100,effect:{self:'spe',stage:2,chance:100}},
        dusk_veil:{name:'Dusk Veil',type:'dark',cat:'status',power:0,acc:100,effect:{self:'spd',stage:1,target:'acc',stage2:-1,chance:100}}
      };

      const CREATURES={
        sprout:{id:'sprout',stats:{hp:92,atk:80,def:88,spa:94,spd:96,spe:72},moves:['solar_claw','pollen_burst','fairy_guard','sap_surge']},
        volt:{id:'volt',stats:{hp:84,atk:95,def:82,spa:86,spd:80,spe:104},moves:['plasma_bolt','iron_ram','overclock','static_field']},
        ember:{id:'ember',stats:{hp:88,atk:98,def:78,spa:108,spd:85,spe:92},moves:['blaze_fang','comet_flare','dragon_focus','ember_wall']},
        tidal:{id:'tidal',stats:{hp:96,atk:70,def:86,spa:102,spd:101,spe:84},moves:['tidal_lance','mind_ripple','aqua_barrier','lucid_pulse']},
        shade:{id:'shade',stats:{hp:86,atk:92,def:76,spa:96,spd:88,spe:108},moves:['shade_slash','phantom_echo','voidstep','dusk_veil']}
      };

      // build UI once
      let card=q('#prism-battle-v3');
      if(!card){
        card=document.createElement('article'); card.id='prism-battle-v3'; card.className='prism-premium-card';
        card.innerHTML=''
          +'<h3 style="margin:0 0 8px">Prism Battle Window</h3>'
          +'<div id="pb3-scene">'
          +'<div class="plat" id="pb3-opp-plat"></div><div class="plat" id="pb3-you-plat"></div>'
          +'<img id="pb3-opp-sprite" alt="Opponent"><img id="pb3-you-sprite" alt="Player">'
          +'<div class="pb3-box" id="pb3-opp-box"><div class="name" id="pb3-opp-name">-</div><div class="meta" id="pb3-opp-meta">-</div><div class="hp"><span id="pb3-opp-hp"></span></div></div>'
          +'<div class="pb3-box" id="pb3-you-box"><div class="name" id="pb3-you-name">-</div><div class="meta" id="pb3-you-meta">-</div><div class="hp"><span id="pb3-you-hp"></span></div></div>'
          +'</div>'
          +'<div id="pb3-panel">'
          +'<div id="pb3-log">Choose creatures and start battle.</div>'
          +'<div id="pb3-controls">'
          +'<div class="left"><select id="pb3-player"></select><button id="pb3-start">Start Battle</button><button id="pb3-restart" disabled>Restart</button></div>'
          +'<div class="right"><select id="pb3-opp"></select><div id="pb3-moves"></div></div>'
          +'</div>'
          +'<div id="pb3-status"></div>'
          +'</div>';
        host.appendChild(card);
      }

      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const stageMult=s=>{const v=clamp(Number(s||0),-6,6); return v>=0?(2+v)/2:2/(2+Math.abs(v));};
      const status=q('#pb3-status',card), logEl=q('#pb3-log',card);
      const setStatus=t=>{ if(status) status.textContent=t||''; };
      const log=(line)=>{ const rows=(logEl.textContent||'').split('\n').filter(Boolean); rows.push(line); logEl.textContent=rows.slice(-26).join('\n'); logEl.scrollTop=logEl.scrollHeight; };

      let gallery={official:[],user:[]};
      const spr=(species,stage='adult')=>{
        const all=[...(gallery.user||[]),...(gallery.official||[])];
        const s=String(species||'sprout').toLowerCase();
        const tries=[stage,'teen','baby'];
        for(const st of tries){ const hit=all.find(r=>String(r.species||'').toLowerCase()===s && String(r.stage||'').toLowerCase()===st); if(hit?.url) return hit.url; }
        return q('#prism-hero-img')?.src||'';
      };

      function info(id){ return SPECIES_MAP[id]||{name:id,types:['Unknown'],desc:''}; }

      function makeFighter(id,lvl=50){
        const c=CREATURES[id], meta=info(id);
        return {id,name:meta.name,types:meta.types.map(t=>t.toLowerCase()),desc:meta.desc,base:c.stats,moves:[...c.moves],
          level:lvl,hp:c.stats.hp+lvl,maxHp:c.stats.hp+lvl,status:null,fainted:false,stages:{atk:0,def:0,spa:0,spd:0,spe:0,acc:0,eva:0}};
      }
      const stat=(f,k)=>Number(f.base[k]||1)*stageMult(f.stages[k]||0);
      const tmod=(mt,types)=>types.reduce((m,t)=>m*(((TYPE[mt]||{})[t]||1),m*=((TYPE[mt]||{})[t]||1),1),1); // override below
      function typeMod(mt,types){ let m=1; (types||[]).forEach(t=>{ m*=(((TYPE[mt]||{})[t])??1); }); return m; }
      function hit(move,a,d){ const c=clamp((move.acc||100)*(stageMult(a.stages.acc)/stageMult(d.stages.eva)),1,100); return Math.random()*100<=c; }
      function dmg(a,d,m){ if(m.power<=0) return {n:0,tm:1}; const A=m.cat==='physical'?stat(a,'atk'):stat(a,'spa'); const D=m.cat==='physical'?stat(d,'def'):stat(d,'spd'); const base=(((2*a.level/5+2)*m.power*(A/Math.max(1,D)))/50)+2; const stab=a.types.includes(m.type)?1.5:1; const tm=typeMod(m.type,d.types); const rand=0.85+Math.random()*0.15; const burn=(a.status==='burn'&&m.cat==='physical')?0.5:1; return {n:Math.floor(Math.max(1,base*stab*tm*rand*burn)),tm}; }
      const aiPick=(ai,pl)=>{
        const ranked=ai.moves.map(mid=>{ const m=MOVES[mid]; let s=10+Math.random()*4; if(m.power>0){ const o=dmg(ai,pl,m); s+=o.n/8+(o.tm>1?6:0)+((pl.hp-o.n<=0)?22:0);} else { if(ai.hp/ai.maxHp<0.45&&m.effect?.heal) s+=14; if(pl.hp/pl.maxHp>0.6&&m.effect?.target) s+=7; } return {mid,s}; }).sort((a,b)=>b.s-a.s);
        return ranked[Math.floor(Math.random()*Math.min(2,ranked.length))].mid;
      };

      function applyEff(m,a,d){
        const e=m.effect; if(!e) return;
        const ok=()=>Math.random()*100<=(e.chance||0);
        if(e.self && ok()){ a.stages[e.self]=clamp((a.stages[e.self]||0)+(e.stage||0),-6,6); log(`${a.name}'s ${String(e.self).toUpperCase()} changed.`); }
        if(e.target && ok()){ const st=(e.stage2!==undefined?e.stage2:e.stage)||0; d.stages[e.target]=clamp((d.stages[e.target]||0)+st,-6,6); log(`${d.name}'s ${String(e.target).toUpperCase()} changed.`); }
        if(e.status && !d.status && ok()){ d.status=e.status; log(`${d.name} is ${String(e.status).toUpperCase()}!`); }
        if(e.heal && ok()){ const h=Math.max(1,Math.floor(a.maxHp*(Number(e.heal)/100))); a.hp=Math.min(a.maxHp,a.hp+h); log(`${a.name} restored ${h} HP.`); }
      }

      function afterStatus(f){ if(f.fainted) return; if(f.status==='poison'){ const d=Math.max(1,Math.floor(f.maxHp*0.08)); f.hp=Math.max(0,f.hp-d); log(`${f.name} is hurt by poison (${d}).`);} if(f.status==='burn'){ const d=Math.max(1,Math.floor(f.maxHp*0.06)); f.hp=Math.max(0,f.hp-d); log(`${f.name} is hurt by burn (${d}).`);} if(f.hp<=0){f.hp=0;f.fainted=true;} }

      let state=null;
      function redraw(){
        if(!state) return;
        const p=state.p,o=state.o;
        q('#pb3-you-name').textContent=`${p.name} Lv${p.level}`;
        q('#pb3-you-meta').textContent=`${info(p.id).types.join('/')} · HP ${p.hp}/${p.maxHp}`;
        q('#pb3-opp-name').textContent=`${o.name} Lv${o.level}`;
        q('#pb3-opp-meta').textContent=`${info(o.id).types.join('/')} · HP ${o.hp}/${o.maxHp}`;
        const pr=clamp(p.hp/p.maxHp,0,1), or=clamp(o.hp/o.maxHp,0,1);
        q('#pb3-you-hp').style.width=(pr*100).toFixed(1)+'%';
        q('#pb3-opp-hp').style.width=(or*100).toFixed(1)+'%';
        q('#pb3-you-hp').style.background=pr>0.5?'#4bd67e':(pr>0.2?'#e4c64e':'#e06a6a');
        q('#pb3-opp-hp').style.background=or>0.5?'#4bd67e':(or>0.2?'#e4c64e':'#e06a6a');
        const yi=spr(p.id,'adult'), oi=spr(o.id,'adult'); if(yi) q('#pb3-you-sprite').src=yi+(yi.includes('?')?'&':'?')+'v='+Date.now(); if(oi) q('#pb3-opp-sprite').src=oi+(oi.includes('?')?'&':'?')+'v='+Date.now();

        const mv=q('#pb3-moves'); mv.innerHTML='';
        p.moves.forEach(mid=>{ const m=MOVES[mid]; const b=document.createElement('button'); b.textContent=m.name; b.title=`${m.type.toUpperCase()} · ${m.cat.toUpperCase()} · Pow ${m.power||'-'} · Acc ${m.acc}%`; b.disabled=state.over; b.addEventListener('click',()=>turn(mid)); mv.appendChild(b); });
        q('#pb3-restart').disabled=!state.over;
      }

      function act(a,d,mid){ if(a.fainted) return; const m=MOVES[mid]; log(`${a.name} used ${m.name}!`); if(!hit(m,a,d)){ log('It missed!'); return; } if(m.power>0){ const out=dmg(a,d,m); d.hp=Math.max(0,d.hp-out.n); if(out.tm>=2) log('Super effective!'); else if(out.tm<1) log('Not very effective...'); log(`${d.name} took ${out.n} damage.`); if(d.hp<=0){d.hp=0;d.fainted=true;} } applyEff(m,a,d); }

      async function rewardWin(){ try{ let r=await fetch('/wp-json/prismtek/v1/pet/battle/spar',{method:'POST',credentials:'include',headers:{'content-type':'application/json'}}); let j=await r.json().catch(()=>({})); if(!r.ok||!j.ok){ r=await fetch('/wp-json/prismtek/v1/pet/train',{method:'POST',credentials:'include',headers:{'content-type':'application/json'}}); j=await r.json().catch(()=>({})); } if(r.ok&&j.ok) log(`Reward: +${Number(j.xpGained||0)} XP`);}catch(e){} }

      async function turn(playerMove){
        if(!state||state.over) return;
        const aiMove=aiPick(state.o,state.p);
        const pSp=stat(state.p,'spe')*(state.p.status==='paralyze'?0.5:1);
        const oSp=stat(state.o,'spe')*(state.o.status==='paralyze'?0.5:1);
        const first=(pSp===oSp)?(Math.random()<0.5?'p':'o'):(pSp>oSp?'p':'o');
        log(`Turn ${state.turn}: ${(first==='p'?state.p.name:state.o.name)} moves first.`);

        if(first==='p'){ act(state.p,state.o,playerMove); if(state.o.fainted){ state.over=true; state.winner='p'; } else { act(state.o,state.p,aiMove); if(state.p.fainted){ state.over=true; state.winner='o'; } } }
        else { act(state.o,state.p,aiMove); if(state.p.fainted){ state.over=true; state.winner='o'; } else { act(state.p,state.o,playerMove); if(state.o.fainted){ state.over=true; state.winner='p'; } } }

        afterStatus(state.p); afterStatus(state.o);
        if(!state.over && state.p.fainted){ state.over=true; state.winner='o'; }
        if(!state.over && state.o.fainted){ state.over=true; state.winner='p'; }

        if(state.over){
          log(state.winner==='p' ? `${state.o.name} fainted! You win!` : `${state.p.name} fainted! You lost.`);
          setStatus(state.winner==='p' ? 'Victory!' : 'Defeat.');
          if(state.winner==='p') await rewardWin();
        } else setStatus('Choose your next move.');

        state.turn+=1;
        redraw();
      }

      async function loadGallery(){ try{ const r=await fetch('/wp-json/prismtek/v1/creatures/gallery-v2?ts='+Date.now(),{credentials:'include'}); const j=await r.json().catch(()=>({})); if(r.ok&&j.ok) gallery={official:j.official||[],user:j.user||[]}; }catch(e){} }
      function fill(){ const options=Object.keys(CREATURES).map(id=>`<option value="${id}">${SPECIES_MAP[id]?.name||id}</option>`).join(''); q('#pb3-player').innerHTML=options; q('#pb3-opp').innerHTML=options; q('#pb3-player').value='sprout'; q('#pb3-opp').value='volt'; }
      function start(){ const p=q('#pb3-player').value, o=q('#pb3-opp').value; if(p===o){ setStatus('Choose different creatures.'); return; } state={turn:1,over:false,winner:null,p:makeFighter(p,50),o:makeFighter(o,50)}; q('#pb3-log').textContent='Battle started!'; setStatus('Choose your move.'); redraw(); }

      q('#pb3-start')?.addEventListener('click',start);
      q('#pb3-restart')?.addEventListener('click',start);

      // Rewrite visible name labels in other panels to unified names.
      function relabelExistingNames(){
        const textNodes=qa('*').filter(el=>el.children.length===0 && el.textContent && /\b(sprout|ember|tidal|volt|shade)\b/i.test(el.textContent));
        textNodes.forEach(el=>{
          let t=el.textContent;
          t=t.replace(/\bsprout\b/ig,SPECIES_MAP.sprout.name).replace(/\bember\b/ig,SPECIES_MAP.ember.name).replace(/\btidal\b/ig,SPECIES_MAP.tidal.name).replace(/\bvolt\b/ig,SPECIES_MAP.volt.name).replace(/\bshade\b/ig,SPECIES_MAP.shade.name);
          el.textContent=t;
        });
      }

      loadGallery().then(()=>{ fill(); relabelExistingNames(); setStatus('Unified battle window ready.'); });
    })();
    </script>
    <?php
}, 1000000200);

// ===== Prism Integrations panel (2026-03-09l): Base44 key section + restore in-page Ollama agent =====
if (!function_exists('prismtek_base44_encrypt')) {
    function prismtek_base44_encrypt($plain){
        $key = wp_salt('auth');
        $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
        return base64_encode(openssl_encrypt((string)$plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    }
    function prismtek_base44_decrypt($enc){
        $raw = base64_decode((string)$enc, true);
        if ($raw===false) return '';
        $key = wp_salt('auth');
        $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
        $out = openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($out) ? $out : '';
    }
    function prismtek_base44_mask($token){
        $t = trim((string)$token);
        $n = strlen($t);
        if($n<=8) return str_repeat('*', max(0,$n));
        return substr($t,0,4).str_repeat('*', max(0,$n-8)).substr($t,-4);
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/base44/status',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $enc=(string)get_user_meta($uid,'prismtek_base44_key_enc',true);
            $token=$enc?prismtek_base44_decrypt($enc):'';
            return rest_ensure_response(['ok'=>true,'connected'=>$token!=='','maskedKey'=>prismtek_base44_mask($token)]);
        }
    ]);

    register_rest_route('prismtek/v1','/base44/connect',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $token=trim((string)$r->get_param('apiKey'));
            if(strlen($token)<20) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_api_key'],400);
            update_user_meta($uid,'prismtek_base44_key_enc',prismtek_base44_encrypt($token));
            update_user_meta($uid,'prismtek_base44_key_ts',time());
            return rest_ensure_response(['ok'=>true,'connected'=>true,'maskedKey'=>prismtek_base44_mask($token)]);
        }
    ]);

    register_rest_route('prismtek/v1','/base44/disconnect',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            delete_user_meta($uid,'prismtek_base44_key_enc');
            delete_user_meta($uid,'prismtek_base44_key_ts');
            return rest_ensure_response(['ok'=>true,'connected'=>false]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <script id="prism-integrations-panel-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s);

      const host=q('#prism-premium-shell')||q('.pph-wrap');
      if(!host||q('#prism-integrations-card')) return;

      const card=document.createElement('article');
      card.id='prism-integrations-card';
      card.className='prism-premium-card';
      card.innerHTML=''
        +'<h3 style="margin:0 0 8px">Integrations</h3>'
        +'<div style="display:grid;gap:12px">'
        +'<section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px">'
        +'  <h4 style="margin:0 0 6px">Base44 API Key</h4>'
        +'  <p style="margin:0 0 8px;font-size:12px;color:#c9d6ff">Stored per-user (encrypted). Used for future Base44-powered creature tooling.</p>'
        +'  <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px">'
        +'    <input id="b44-key" type="password" placeholder="Paste Base44 API key" style="background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px" />'
        +'    <button id="b44-save" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Save Key</button>'
        +'    <button id="b44-remove" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Remove</button>'
        +'  </div>'
        +'  <p id="b44-status" style="margin:8px 0 0;font-size:12px;color:#c9d6ff">Checking...</p>'
        +'</section>'
        +'<section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px">'
        +'  <h4 style="margin:0 0 6px">Website Ollama Agent (Restored)</h4>'
        +'  <p style="margin:0 0 8px;font-size:12px;color:#c9d6ff">Owner-only local agent controls for in-website tasks.</p>'
        +'  <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px">'
        +'    <select id="pla2-model" style="background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select>'
        +'    <label style="display:flex;align-items:center;gap:6px;font-size:12px"><input id="pla2-auto" type="checkbox"/> Auto-apply actions</label>'
        +'    <button id="pla2-check" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Check</button>'
        +'  </div>'
        +'  <textarea id="pla2-msg" rows="4" placeholder="Ask your local Ollama agent..." style="width:100%;margin-top:8px;background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px"></textarea>'
        +'  <div style="display:grid;grid-template-columns:auto auto;gap:8px;margin-top:8px">'
        +'    <button id="pla2-run" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Run Agent</button>'
        +'    <a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px;text-decoration:none">Open Full Agent Page</a>'
        +'  </div>'
        +'  <p id="pla2-status" style="margin:8px 0 0;font-size:12px;color:#c9d6ff">Ready.</p>'
        +'  <pre id="pla2-json" style="margin-top:8px;white-space:pre-wrap;max-height:220px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px"></pre>'
        +'</section>'
        +'</div>';

      host.appendChild(card);

      const setB=t=>{const e=q('#b44-status',card); if(e) e.textContent=t;};
      const setA=t=>{const e=q('#pla2-status',card); if(e) e.textContent=t;};
      const json=q('#pla2-json',card);
      async function get(path){ const r=await fetch(API+path,{credentials:'include'}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

      async function refreshB44(){
        const out=await get('base44/status');
        if(!out.ok||!out.j?.ok){ setB('Status unavailable.'); return; }
        setB(out.j.connected?`Connected (${out.j.maskedKey||'key'})`:'Not connected.');
      }

      q('#b44-save',card)?.addEventListener('click', async ()=>{
        const key=(q('#b44-key',card)?.value||'').trim();
        if(!key){ setB('Paste a key first.'); return; }
        setB('Saving key...');
        const out=await post('base44/connect',{apiKey:key});
        if(!out.ok||!out.j?.ok){ setB('Save failed.'); return; }
        q('#b44-key',card).value='';
        setB(`Connected (${out.j.maskedKey||'key'})`);
      });

      q('#b44-remove',card)?.addEventListener('click', async ()=>{
        setB('Removing...');
        const out=await post('base44/disconnect',{});
        setB(out.ok&&out.j?.ok?'Disconnected.':'Remove failed.');
      });

      q('#pla2-check',card)?.addEventListener('click', async ()=>{
        setA('Checking...');
        const out=await get('agent/status');
        if(!out.ok||!out.j?.ok){ setA('Agent status unavailable or access denied.'); json.textContent=JSON.stringify(out.j||{},null,2); return; }
        setA('Ollama '+(out.j.ollamaUp?'online':'offline'));
        json.textContent=JSON.stringify(out.j,null,2);
      });

      q('#pla2-run',card)?.addEventListener('click', async ()=>{
        const msg=(q('#pla2-msg',card)?.value||'').trim();
        if(!msg){ setA('Enter a request first.'); return; }
        setA('Running agent...');
        const payload={message:msg,model:(q('#pla2-model',card)?.value||'qwen2.5:3b'),autoApply:!!q('#pla2-auto',card)?.checked};
        const out=await post('agent/chat',payload);
        if(!out.ok||!out.j?.ok){ setA('Agent failed or access denied.'); json.textContent=JSON.stringify(out.j||{},null,2); return; }
        setA(out.j.reply||'Done.');
        json.textContent=JSON.stringify(out.j,null,2);
      });

      refreshB44();
    })();
    </script>
    <?php
}, 1000000300);

// ===== Integrations hotfix (2026-03-09m): reliable Base44 save + agent visibility with relaxed owner gate =====
add_action('rest_api_init', function(){
    // Agent routes that rely on capability (admin) instead of strict login-name match.
    register_rest_route('prismtek/v1','/agent2/status',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            if(!is_user_logged_in() || !current_user_can('manage_options')) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $up = wp_remote_get('http://127.0.0.1:11434/api/tags',['timeout'=>3]);
            $ollamaUp = !is_wp_error($up) && ((int)wp_remote_retrieve_response_code($up) >= 200) && ((int)wp_remote_retrieve_response_code($up) < 500);
            $u = wp_get_current_user();
            return rest_ensure_response(['ok'=>true,'owner'=>true,'user'=>$u?($u->user_login):'','ollamaUp'=>$ollamaUp]);
        }
    ]);

    register_rest_route('prismtek/v1','/agent2/chat',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            if(!is_user_logged_in() || !current_user_can('manage_options')) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(!function_exists('prismtek_agent_ollama_chat')) return new WP_REST_Response(['ok'=>false,'error'=>'agent_unavailable'],500);
            $message = sanitize_textarea_field((string)$r->get_param('message'));
            $model = sanitize_text_field((string)$r->get_param('model'));
            $autoApply = (bool)$r->get_param('autoApply');
            if($message==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_message'],400);
            $out = prismtek_agent_ollama_chat($message,$model?:'qwen2.5:3b');
            if(empty($out['ok'])) return new WP_REST_Response(['ok'=>false,'error'=>$out['error'] ?? 'agent_failed','detail'=>$out['detail'] ?? null],502);
            $data=$out['data'];
            $applied=[];
            if($autoApply && !empty($data['actions']) && function_exists('prismtek_agent_exec_actions')) $applied = prismtek_agent_exec_actions($data['actions']);
            return rest_ensure_response(['ok'=>true,'reply'=>$data['reply'],'actions'=>$data['actions'],'applied'=>$applied]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <script id="prism-integrations-hotfix-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s);

      const old=document.getElementById('prism-integrations-card');
      if(old) old.style.display='none';

      const host=q('#prism-premium-shell')||q('.pph-wrap');
      if(!host || q('#prism-integrations-card-v2')) return;

      const card=document.createElement('article');
      card.id='prism-integrations-card-v2';
      card.className='prism-premium-card';
      card.innerHTML=''
        +'<h3 style="margin:0 0 8px">Integrations (Fixed)</h3>'
        +'<section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px;margin-bottom:10px">'
        +'<h4 style="margin:0 0 6px">Base44 API Key</h4>'
        +'<div style="display:grid;grid-template-columns:1fr auto auto;gap:8px">'
        +'<input id="b44f-key" type="password" placeholder="Paste Base44 key" style="background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px" />'
        +'<button id="b44f-save" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Save</button>'
        +'<button id="b44f-del" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Remove</button>'
        +'</div>'
        +'<p id="b44f-status" style="margin:8px 0 0;font-size:12px;color:#c9d6ff">Checking...</p>'
        +'</section>'
        +'<section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px">'
        +'<h4 style="margin:0 0 6px">In-Browser Ollama Agent</h4>'
        +'<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px">'
        +'<select id="ag2-model" style="background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select>'
        +'<label style="display:flex;align-items:center;gap:6px;font-size:12px"><input id="ag2-auto" type="checkbox"/> Auto-apply</label>'
        +'<button id="ag2-check" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Check</button>'
        +'</div>'
        +'<textarea id="ag2-msg" rows="4" placeholder="Ask local agent..." style="width:100%;margin-top:8px;background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px"></textarea>'
        +'<div style="display:grid;grid-template-columns:auto auto;gap:8px;margin-top:8px"><button id="ag2-run" style="background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px">Run</button><a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:8px;text-decoration:none">Open Full Agent</a></div>'
        +'<p id="ag2-status" style="margin:8px 0 0;font-size:12px;color:#c9d6ff">Ready.</p>'
        +'<pre id="ag2-json" style="margin-top:8px;white-space:pre-wrap;max-height:220px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px"></pre>'
        +'</section>';
      host.appendChild(card);

      const setB=t=>{const e=q('#b44f-status',card); if(e) e.textContent=t;};
      const setA=t=>{const e=q('#ag2-status',card); if(e) e.textContent=t;};
      const out=q('#ag2-json',card);

      async function get(path){ const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      async function refreshB(){
        const o=await get('base44/status');
        if(!o.ok||!o.j?.ok){ setB('Status failed: '+(o.j?.error||o.status)); return; }
        setB(o.j.connected?('Connected ('+(o.j.maskedKey||'key')+')'):'Not connected.');
      }

      q('#b44f-save',card).addEventListener('click', async ()=>{
        const key=(q('#b44f-key',card).value||'').trim();
        if(!key){ setB('Paste key first.'); return; }
        setB('Saving...');
        const o=await post('base44/connect',{apiKey:key});
        if(!o.ok||!o.j?.ok){ setB('Save failed: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; }
        q('#b44f-key',card).value='';
        setB('Connected ('+(o.j.maskedKey||'key')+')');
      });

      q('#b44f-del',card).addEventListener('click', async ()=>{
        setB('Removing...');
        const o=await post('base44/disconnect',{});
        setB(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status));
      });

      q('#ag2-check',card).addEventListener('click', async ()=>{
        setA('Checking...');
        const o=await get('agent2/status');
        if(!o.ok||!o.j?.ok){ setA('Unavailable: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; }
        setA('Ollama '+(o.j.ollamaUp?'online':'offline')+' as '+(o.j.user||'user'));
        out.textContent=JSON.stringify(o.j,null,2);
      });

      q('#ag2-run',card).addEventListener('click', async ()=>{
        const msg=(q('#ag2-msg',card).value||'').trim();
        if(!msg){ setA('Enter a request first.'); return; }
        setA('Running...');
        const o=await post('agent2/chat',{message:msg,model:q('#ag2-model',card).value,autoApply:!!q('#ag2-auto',card).checked});
        if(!o.ok||!o.j?.ok){ setA('Run failed: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; }
        setA(o.j.reply||'Done.');
        out.textContent=JSON.stringify(o.j,null,2);
      });

      refreshB();
    })();
    </script>
    <?php
}, 1000000400);

// ===== Account-surface integration placement (2026-03-09n): move integrations to My Account + popout Ollama launcher =====
add_action('wp_footer', function(){
    if (!function_exists('is_page')) return;
    ?>
    <script id="prism-integrations-relocate-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // 1) Remove/hide integrations from Prism Creatures page.
      if(document.body.classList.contains('page-template') || true){
        const isCreatures = location.pathname.replace(/\/+$/,'').endsWith('/prism-creatures');
        if(isCreatures){
          qa('#prism-integrations-card,#prism-integrations-card-v2').forEach(el=>el.remove());
        }
      }

      const isAccount = location.pathname.replace(/\/+$/,'').endsWith('/my-account');
      if(!isAccount) return;

      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};

      if(q('#prism-account-integrations')) return;
      const host=q('.entry-content')||q('.pph-wrap')||document.body;

      const card=document.createElement('section');
      card.id='prism-account-integrations';
      card.className='pph-wrap';
      card.innerHTML=''
        +'<article class="pph-card">'
        +'  <h3>Account Integrations</h3>'
        +'  <p style="font-size:12px;color:#dbe4ff">Manage API keys and local assistant access from your account page.</p>'
        +'  <div style="display:grid;gap:12px">'
        +'    <section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px">'
        +'      <h4 style="margin:0 0 6px">Base44 API Key</h4>'
        +'      <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px">'
        +'        <input id="acct-b44-key" type="password" placeholder="Paste Base44 key" style="background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px" />'
        +'        <button id="acct-b44-save">Save</button>'
        +'        <button id="acct-b44-del">Remove</button>'
        +'      </div>'
        +'      <p id="acct-b44-status" class="pph-status" style="margin-top:8px">Checking...</p>'
        +'    </section>'
        +'    <section style="border:1px solid #5f6ad1;background:#0d1334;padding:10px">'
        +'      <h4 style="margin:0 0 6px">Local Ollama Agent</h4>'
        +'      <p style="font-size:12px;color:#dbe4ff;margin:0 0 8px">Open as a popout chat or full page tab.</p>'
        +'      <div style="display:grid;grid-template-columns:auto auto 1fr;gap:8px">'
        +'        <button id="acct-agent-popout">Open Popout Chat</button>'
        +'        <a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;padding:8px;border:1px solid #5d68cf;background:#111843;color:#eef3ff;text-decoration:none">Open Agent Tab</a>'
        +'        <button id="acct-agent-check">Check Agent Status</button>'
        +'      </div>'
        +'      <p id="acct-agent-status" class="pph-status" style="margin-top:8px">Ready.</p>'
        +'    </section>'
        +'  </div>'
        +'</article>';
      host.prepend(card);

      const setB=t=>{const e=q('#acct-b44-status'); if(e) e.textContent=t;};
      const setA=t=>{const e=q('#acct-agent-status'); if(e) e.textContent=t;};

      async function get(path){ const r=await fetch(API+path,{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      async function refreshB(){
        const o=await get('base44/status');
        if(!o.ok||!o.j?.ok){ setB('Status failed: '+(o.j?.error||o.status)); return; }
        setB(o.j.connected?('Connected ('+(o.j.maskedKey||'key')+')'):'Not connected.');
      }

      q('#acct-b44-save')?.addEventListener('click', async ()=>{
        const key=(q('#acct-b44-key')?.value||'').trim();
        if(!key){ setB('Paste key first.'); return; }
        setB('Saving...');
        const o=await post('base44/connect',{apiKey:key});
        if(!o.ok||!o.j?.ok){ setB('Save failed: '+(o.j?.error||o.status)); return; }
        q('#acct-b44-key').value='';
        setB('Connected ('+(o.j.maskedKey||'key')+')');
      });

      q('#acct-b44-del')?.addEventListener('click', async ()=>{
        setB('Removing...');
        const o=await post('base44/disconnect',{});
        setB(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status));
      });

      q('#acct-agent-popout')?.addEventListener('click', ()=>{
        const w=window.open('/prism-agent/','prismAgentPopout','width=980,height=760,menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=yes');
        if(!w){ setA('Popout blocked by browser. Use Open Agent Tab.'); return; }
        setA('Popout opened.');
      });

      q('#acct-agent-check')?.addEventListener('click', async ()=>{
        setA('Checking...');
        const o=await get('agent2/status');
        if(!o.ok||!o.j?.ok){ setA('Agent unavailable: '+(o.j?.error||o.status)); return; }
        setA('Ollama '+(o.j.ollamaUp?'online':'offline')+' as '+(o.j.user||'user'));
      });

      refreshB();
    })();
    </script>
    <?php
}, 1000000500);

// ===== Prism Creatures final application shell (2026-03-09o): polished UX + Pixellab/Base44 + functional showdown PvP =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    $uid = (int)get_current_user_id();
    ?>
    <style id="prism-creatures-final-css">
      #prism-final-shell{display:grid;gap:12px}
      #prism-final-shell .card{border:1px solid #5f6ad1;background:linear-gradient(180deg,#111843,#0b1030);padding:12px;color:#eef3ff}
      #prism-final-shell h3{margin:0 0 8px}
      .pf-grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      .pf-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
      .pf-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
      .pf-input,.pf-select,.pf-btn{background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px}
      .pf-btn{font-weight:700;cursor:pointer}
      .pf-btn:hover{background:#16205a}
      .pf-status{margin-top:8px;font-size:12px;color:#c9d6ff;min-height:16px}
      .pf-bars{display:grid;gap:6px}
      .pf-bar{height:9px;border:1px solid #4f59a6;background:#1b1f45}
      .pf-bar>span{display:block;height:100%;width:0;transition:width .3s ease}
      .pf-hero{display:grid;grid-template-columns:180px 1fr;gap:12px}
      .pf-stage{display:grid;place-items:center;border:2px solid #6f7bdd;background:#0a112f;height:180px}
      .pf-stage img{max-width:160px;max-height:160px;image-rendering:pixelated;object-fit:contain}

      #pf-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px;max-height:220px;overflow:auto}
      #pf-gallery .item{border:1px solid #5160c6;background:#0d1338;padding:6px;cursor:pointer}
      #pf-gallery .item.active{outline:2px solid #91a3ff}
      #pf-gallery img{width:100%;image-rendering:pixelated;border:1px solid #4553b2;background:#070c24}

      #pf-pvp-scene{border:2px solid #5f6ad1;background:linear-gradient(180deg,#75b9ff 0%,#82d7ff 42%,#6eb86f 43%,#669c67 100%);min-height:250px;position:relative;overflow:hidden}
      #pf-pvp-scene .plat{position:absolute;width:120px;height:28px;border-radius:50%;background:rgba(28,38,25,.35);border:2px solid rgba(20,30,18,.45)}
      #pf-pvp-scene .opp-plat{top:42px;right:34px}
      #pf-pvp-scene .you-plat{bottom:34px;left:40px}
      #pf-pvp-you,#pf-pvp-opp{position:absolute;image-rendering:pixelated;object-fit:contain;filter:drop-shadow(0 2px 0 rgba(0,0,0,.4))}
      #pf-pvp-opp{top:6px;right:48px;width:124px;height:124px;transform:scaleX(-1)}
      #pf-pvp-you{bottom:12px;left:48px;width:144px;height:144px}
      .pf-hpbox{position:absolute;background:#f8f9ff;color:#12142e;border:2px solid #212654;padding:7px 10px;min-width:220px;max-width:46%}
      .pf-hpbox .name{font-weight:900}
      .pf-hpbox .meta{font-size:11px;opacity:.85}
      .pf-hp{margin-top:6px;height:8px;border:1px solid #3b468e;background:#dbe1ff}
      .pf-hp>span{display:block;height:100%;width:100%;background:#4ad67f;transition:width .35s ease}
      #pf-you-box{right:14px;bottom:14px}
      #pf-opp-box{left:14px;top:12px}
      #pf-pvp-log{margin-top:8px;max-height:200px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px;white-space:pre-wrap;font-size:12px}

      @media (max-width:900px){.pf-grid2,.pf-grid3,.pf-grid4,.pf-hero{grid-template-columns:1fr}.pf-hpbox{max-width:70%}}
    </style>

    <script id="prism-creatures-final-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const UID=<?php echo (int)$uid; ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const host=q('.pph-wrap')||q('.entry-content')||document.body;
      if(!host) return;

      // keep existing data/features but hide cluttered duplicate cards
      qa('.pph-card').forEach(c=>{
        const t=(q('h3,h4',c)?.textContent||'').toLowerCase();
        const keep=t.includes('creature showcase');
        if(!keep) c.style.display='none';
      });
      qa('#prism-battle-v2-card,#prism-battle-v3,#prism-pvp-online,#showdown-ai-panel,#prism-integrations-card,#prism-integrations-card-v2').forEach(el=>el.remove());

      let shell=q('#prism-final-shell');
      if(!shell){
        shell=document.createElement('section');
        shell.id='prism-final-shell';
        shell.innerHTML=''
          +'<article class="card" id="pf-partner">'
          +'<h3>Prism Partner Hub</h3>'
          +'<div class="pf-hero"><div class="pf-stage"><img id="pf-hero-img" alt="Partner"></div><div>'
          +'<div id="pf-hero-text">Loading creature...</div>'
          +'<div class="pf-bars">'
          +'<div><small>Health</small><div class="pf-bar"><span id="pf-h-health" style="background:#52db84"></span></div></div>'
          +'<div><small>Energy</small><div class="pf-bar"><span id="pf-h-energy" style="background:#57d7ff"></span></div></div>'
          +'<div><small>Happiness</small><div class="pf-bar"><span id="pf-h-happy" style="background:#f2c462"></span></div></div>'
          +'<div><small>Hunger</small><div class="pf-bar"><span id="pf-h-hunger" style="background:#d695ff"></span></div></div>'
          +'</div></div></div>'
          +'<div class="pf-grid4" style="margin-top:8px"><button class="pf-btn" id="pf-feed">Feed</button><button class="pf-btn" id="pf-play">Play</button><button class="pf-btn" id="pf-rest">Rest</button><button class="pf-btn" id="pf-train">Train (+XP)</button></div>'
          +'<div class="pf-grid3" style="margin-top:8px"><input class="pf-input" id="pf-name" maxlength="20" placeholder="Creature name"><button class="pf-btn" id="pf-name-save">Save Name</button><button class="pf-btn" id="pf-refresh">Refresh</button></div>'
          +'<p class="pf-status" id="pf-partner-status">Ready.</p>'
          +'</article>'

          +'<article class="card" id="pf-sprites">'
          +'<h3>Sprite Forge (PixelLab + Base44)</h3>'
          +'<div class="pf-grid3"><button class="pf-btn" id="pf-gal-off">Official Gallery</button><button class="pf-btn" id="pf-gal-user">User Gallery</button><button class="pf-btn" id="pf-apply-selected">Use Selected Sprite</button></div>'
          +'<div id="pf-gallery" style="margin-top:8px"></div>'
          +'<div class="pf-grid2" style="margin-top:8px"><button class="pf-btn" id="pf-mode-off">Battle Uses Official Set</button><button class="pf-btn" id="pf-mode-user">Battle Uses My Generated Set</button></div>'
          +'<details style="margin-top:8px"><summary><strong>Generate Stages with PixelLab</strong></summary>'
          +'<div class="pf-grid3" style="margin-top:8px"><select class="pf-select" id="pf-pl-model"><option value="bitforge">bitforge</option><option value="pixflux">pixflux</option></select><input class="pf-input" id="pf-pl-species" placeholder="species (sprout/ember/tidal/volt)" value="sprout"><input class="pf-input" id="pf-pl-element" placeholder="element" value="nature"></div>'
          +'<div class="pf-grid3" style="margin-top:8px"><input class="pf-input" id="pf-pl-personality" placeholder="personality" value="brave"><input class="pf-input" id="pf-pl-shape" placeholder="shape" value="chibi"><input class="pf-input" id="pf-pl-color" placeholder="colorMood" value="vibrant"></div>'
          +'<div class="pf-grid2" style="margin-top:8px"><button class="pf-btn" id="pf-pl-gen">Generate baby/teen/adult</button><button class="pf-btn" id="pf-pl-status">Check PixelLab Connection</button></div>'
          +'</details>'
          +'<details style="margin-top:8px"><summary><strong>Integration Keys</strong></summary>'
          +'<div class="pf-grid2" style="margin-top:8px"><input class="pf-input" id="pf-key-pl" type="password" placeholder="PixelLab API Key"><button class="pf-btn" id="pf-key-pl-save">Save PixelLab Key</button></div>'
          +'<div class="pf-grid2" style="margin-top:8px"><input class="pf-input" id="pf-key-b44" type="password" placeholder="Base44 API Key"><button class="pf-btn" id="pf-key-b44-save">Save Base44 Key</button></div>'
          +'</details>'
          +'<p class="pf-status" id="pf-sprite-status">Ready.</p>'
          +'</article>'

          +'<article class="card" id="pf-pvp">'
          +'<h3>Prism Showdown PvP</h3>'
          +'<div class="pf-grid3"><input class="pf-input" id="pf-pvp-user" placeholder="Opponent username"><button class="pf-btn" id="pf-pvp-challenge">Challenge</button><button class="pf-btn" id="pf-pvp-load">Load Match</button></div>'
          +'<div class="pf-grid3" style="margin-top:8px"><input class="pf-input" id="pf-pvp-id" placeholder="Match ID"><button class="pf-btn" id="pf-pvp-accept">Accept Match</button><button class="pf-btn" id="pf-pvp-spectate">Copy Spectate Link</button></div>'
          +'<div id="pf-pvp-scene" style="margin-top:8px">'
          +'<div class="plat opp-plat"></div><div class="plat you-plat"></div><img id="pf-pvp-opp" alt="Opp"><img id="pf-pvp-you" alt="You">'
          +'<div class="pf-hpbox" id="pf-opp-box"><div class="name" id="pf-opp-name">Opponent</div><div class="meta" id="pf-opp-meta">HP 0/0</div><div class="pf-hp"><span id="pf-opp-hp"></span></div></div>'
          +'<div class="pf-hpbox" id="pf-you-box"><div class="name" id="pf-you-name">You</div><div class="meta" id="pf-you-meta">HP 0/0</div><div class="pf-hp"><span id="pf-you-hp"></span></div></div>'
          +'</div>'
          +'<div class="pf-grid4" style="margin-top:8px"><button class="pf-btn pf-m" data-m="strike">Strike</button><button class="pf-btn pf-m" data-m="guard">Guard</button><button class="pf-btn pf-m" data-m="charge">Charge</button><button class="pf-btn pf-m" data-m="heal">Heal</button></div>'
          +'<pre id="pf-pvp-log">No match loaded.</pre>'
          +'<p class="pf-status" id="pf-pvp-status">Ready.</p>'
          +'</article>';
        host.prepend(shell);
      }

      const SPECIES_NAME={sprout:'Spriglit',ember:'Pyronyx',tidal:'Aqualume',volt:'Voltigon',shade:'Noctivyre'};
      const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
      const normSpecies=s=>{const v=String(s||'').toLowerCase(); return (['sprout','ember','tidal','volt','shade'].includes(v)?v:'sprout');};
      const normStage=s=>{const v=String(s||'').toLowerCase(); return (v==='adult'||v==='teen')?v:'baby';};
      const hpStage=(hp,max)=>{const r=Number(hp||0)/Math.max(1,Number(max||1)); if(r>0.66)return 'adult'; if(r>0.33)return 'teen'; return 'baby';};

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      let pet=null, gallery={official:[],user:[]}, galMode='official', selected=null, matchId=localStorage.getItem('prism_pvp_match_id')||'';
      const setP=t=>q('#pf-partner-status').textContent=t;
      const setS=t=>q('#pf-sprite-status').textContent=t;
      const setV=t=>q('#pf-pvp-status').textContent=t;

      function stageUrl(species,stage){
        const all=[...(gallery.user||[]),...(gallery.official||[])];
        const s=normSpecies(species), st=normStage(stage);
        const hit=all.find(r=>String(r.species||'').toLowerCase()===s && String(r.stage||'').toLowerCase()===st);
        if(hit?.url) return hit.url;
        const baby=all.find(r=>String(r.species||'').toLowerCase()===s && String(r.stage||'').toLowerCase()==='baby');
        return baby?.url||'';
      }

      function renderPartner(){
        if(!pet) return;
        const sp=normSpecies(pet.species);
        q('#pf-hero-text').innerHTML='<strong>'+(pet.name||'Prismo')+'</strong><br>'+(SPECIES_NAME[sp]||sp)+' · '+normStage(pet.stage)+'<br>Lvl '+Number(pet.level||1)+' · XP '+Number(pet.xp||0)+'/'+Number(pet.nextLevelXp||30)+' · W/L '+Number(pet.wins||0)+'/'+Number(pet.losses||0);
        q('#pf-name').value=pet.name||'';
        q('#pf-h-health').style.width=clamp(Number(pet.health||0),0,100)+'%';
        q('#pf-h-energy').style.width=clamp(Number(pet.energy||0),0,100)+'%';
        q('#pf-h-happy').style.width=clamp(Number(pet.happiness||0),0,100)+'%';
        q('#pf-h-hunger').style.width=clamp(Number(pet.hunger||0),0,100)+'%';
        const hero=stageUrl(sp, normStage(pet.stage||hpStage(pet.health||100,100)));
        if(hero) q('#pf-hero-img').src=hero+(hero.includes('?')?'&':'?')+'v='+Date.now();
      }

      function renderGallery(){
        const rows=(galMode==='user')?(gallery.user||[]):(gallery.official||[]);
        const grid=q('#pf-gallery');
        grid.innerHTML = rows.length ? rows.map((r,i)=>{
          const key=[r.species,r.stage,r.source||galMode,i].join('|');
          const active = selected?.key===key?' active':'';
          return '<div class="item'+active+'" data-k="'+key+'" data-url="'+(r.url||'')+'" data-species="'+(r.species||'')+'" data-stage="'+(r.stage||'')+'" data-source="'+(r.source||galMode)+'"><img src="'+(r.url||'')+'"><div style="font-size:11px;margin-top:4px"><strong>'+(SPECIES_NAME[normSpecies(r.species)]||r.species)+'</strong> · '+(r.stage||'')+'</div></div>';
        }).join('') : '<div style="font-size:12px">No sprites in this gallery yet.</div>';

        qa('.item',grid).forEach(el=>el.addEventListener('click',()=>{
          selected={key:el.dataset.k,url:el.dataset.url,species:el.dataset.species,stage:el.dataset.stage,source:el.dataset.source};
          qa('.item',grid).forEach(i=>i.classList.remove('active')); el.classList.add('active');
        }));
      }

      async function refreshCore(){
        const [p,g]=await Promise.all([get('pet/rpg'),get('creatures/gallery-v2')]);
        if(p.ok&&p.j?.ok) pet=p.j.pet||{};
        if(g.ok&&g.j?.ok) gallery={official:g.j.official||[],user:g.j.user||[]};
        renderPartner(); renderGallery();
      }

      async function care(action,extra={}){
        setP('Working...');
        const out=await post('pet/action',Object.assign({action},extra||{}));
        if(!out.ok||!out.j?.ok){ setP('Action failed: '+(out.j?.error||out.status)); return; }
        pet=out.j.pet||pet; renderPartner(); setP('Done.');
      }
      q('#pf-feed').addEventListener('click',()=>care('feed'));
      q('#pf-play').addEventListener('click',()=>care('play'));
      q('#pf-rest').addEventListener('click',()=>care('rest'));
      q('#pf-name-save').addEventListener('click',()=>care('rename',{name:(q('#pf-name').value||'').trim()}));
      q('#pf-refresh').addEventListener('click',()=>refreshCore());
      q('#pf-train').addEventListener('click', async ()=>{
        setP('Training battle...');
        let out=await post('pet/battle/spar',{});
        if(!out.ok||!out.j?.ok) out=await post('pet/train',{});
        if(!out.ok||!out.j?.ok){ setP('Train failed: '+(out.j?.error||out.status)); return; }
        await refreshCore();
        setP((out.j.result==='win'?'WIN':'TRAIN')+' · +'+Number(out.j.xpGained||0)+' XP');
      });

      q('#pf-gal-off').addEventListener('click',()=>{galMode='official'; renderGallery(); setS('Official gallery loaded.');});
      q('#pf-gal-user').addEventListener('click',()=>{galMode='user'; renderGallery(); setS('User gallery loaded.');});
      q('#pf-apply-selected').addEventListener('click', async ()=>{
        if(!selected){ setS('Choose a sprite first.'); return; }
        const out=await post('pet/gallery-choice',{species:normSpecies(selected.species),stage:normStage(selected.stage),source:selected.source||galMode,url:selected.url||''});
        if(!out.ok||!out.j?.ok){ setS('Apply failed: '+(out.j?.error||out.status)); return; }
        setS('Selected sprite applied.');
        await refreshCore();
      });
      q('#pf-mode-off').addEventListener('click', async ()=>{const o=await post('pixellab/stages-mode',{mode:'official'}); setS(o.ok&&o.j?.ok?'Battle set: official stages.':'Mode switch failed.');});
      q('#pf-mode-user').addEventListener('click', async ()=>{const o=await post('pixellab/stages-mode',{mode:'user'}); setS(o.ok&&o.j?.ok?'Battle set: user-generated stages.':'Mode switch failed.');});

      q('#pf-pl-status').addEventListener('click', async ()=>{
        const o=await get('pixellab/status');
        if(!o.ok||!o.j?.ok){ setS('PixelLab status failed: '+(o.j?.error||o.status)); return; }
        setS(o.j.connected?('PixelLab connected ('+(o.j.maskedKey||'key')+')'):'PixelLab not connected.');
      });
      q('#pf-pl-gen').addEventListener('click', async ()=>{
        setS('Generating 3-stage pack...');
        const payload={
          model:q('#pf-pl-model').value,
          species:q('#pf-pl-species').value.trim()||'sprout',
          element:q('#pf-pl-element').value.trim()||'nature',
          personality:q('#pf-pl-personality').value.trim()||'brave',
          shape:q('#pf-pl-shape').value.trim()||'chibi',
          colorMood:q('#pf-pl-color').value.trim()||'vibrant',
          pose:'battle ready',
          background:'transparent'
        };
        const o=await post('pixellab/generate-stages',payload);
        if(!o.ok||!o.j?.ok){ setS('Generate failed: '+(o.j?.error||o.status)); return; }
        setS('Generated stages saved. Est $'+Number(o.j.pack?.usageUsd||0).toFixed(4));
        await refreshCore();
      });

      q('#pf-key-pl-save').addEventListener('click', async ()=>{
        const key=(q('#pf-key-pl').value||'').trim(); if(!key){ setS('Paste PixelLab key first.'); return; }
        const o=await post('pixellab/connect',{apiKey:key,acceptedUsageRules:true});
        if(!o.ok||!o.j?.ok){ setS('PixelLab key save failed: '+(o.j?.error||o.status)); return; }
        q('#pf-key-pl').value=''; setS('PixelLab key saved ('+(o.j.maskedKey||'key')+').');
      });
      q('#pf-key-b44-save').addEventListener('click', async ()=>{
        const key=(q('#pf-key-b44').value||'').trim(); if(!key){ setS('Paste Base44 key first.'); return; }
        const o=await post('base44/connect',{apiKey:key});
        if(!o.ok||!o.j?.ok){ setS('Base44 key save failed: '+(o.j?.error||o.status)); return; }
        q('#pf-key-b44').value=''; setS('Base44 key saved ('+(o.j.maskedKey||'key')+').');
      });

      // functional Pokemon-showdown-style PvP window using existing endpoints
      function applyPvpState(s){
        if(!s) return;
        const a=s.participants?.a||{id:s.a,species:'sprout',displayName:'A'};
        const b=s.participants?.b||{id:s.b,species:'ember',displayName:'B'};
        const me=(Number(a.id)===UID)?a:b;
        const op=(Number(a.id)===UID)?b:a;
        const meId=Number(me.id||s.a), opId=Number(op.id||s.b);
        const mh=Number((s.hp||{})[meId]||0), mm=Number((s.maxHp||{})[meId]||1);
        const oh=Number((s.hp||{})[opId]||0), om=Number((s.maxHp||{})[opId]||1);

        q('#pf-you-name').textContent=(me.displayName||'You');
        q('#pf-opp-name').textContent=(op.displayName||'Opponent');
        q('#pf-you-meta').textContent='HP '+mh+'/'+mm+' · '+(SPECIES_NAME[normSpecies(me.species)]||me.species||'Creature');
        q('#pf-opp-meta').textContent='HP '+oh+'/'+om+' · '+(SPECIES_NAME[normSpecies(op.species)]||op.species||'Creature');
        q('#pf-you-hp').style.width=clamp((mh/Math.max(1,mm))*100,0,100)+'%';
        q('#pf-opp-hp').style.width=clamp((oh/Math.max(1,om))*100,0,100)+'%';

        const yi=stageUrl(normSpecies(me.species),hpStage(mh,mm));
        const oi=stageUrl(normSpecies(op.species),hpStage(oh,om));
        if(yi) q('#pf-pvp-you').src=yi+(yi.includes('?')?'&':'?')+'v='+Date.now();
        if(oi) q('#pf-pvp-opp').src=oi+(oi.includes('?')?'&':'?')+'v='+Date.now();

        q('#pf-pvp-log').textContent=(s.log||[]).join('\n') || 'No logs yet.';
        if(s.done) setV('Battle complete.');
      }

      async function loadMatch(){
        const id=(q('#pf-pvp-id').value||matchId||'').trim();
        if(!id){ setV('No match id.'); return; }
        matchId=id; localStorage.setItem('prism_pvp_match_id',matchId);
        const o=await get('pet/pvp/state-full?matchId='+encodeURIComponent(matchId));
        if(!o.ok||!o.j?.ok){ setV('Load failed: '+(o.j?.error||o.status)); return; }
        applyPvpState(o.j.state||null);
        setV('Match loaded.');
      }

      q('#pf-pvp-challenge').addEventListener('click', async ()=>{
        const opp=(q('#pf-pvp-user').value||'').trim();
        if(!opp){ setV('Enter opponent username.'); return; }
        const o=await post('pet/pvp/challenge',{opponent:opp});
        if(!o.ok||!o.j?.ok){ setV('Challenge failed: '+(o.j?.error||o.status)); return; }
        matchId=o.j.matchId||''; q('#pf-pvp-id').value=matchId; localStorage.setItem('prism_pvp_match_id',matchId);
        setV('Challenge created. Share Match ID.');
      });

      q('#pf-pvp-accept').addEventListener('click', async ()=>{
        const id=(q('#pf-pvp-id').value||matchId||'').trim();
        if(!id){ setV('No match id.'); return; }
        const o=await post('pet/pvp/accept',{matchId:id});
        if(!o.ok||!o.j?.ok){ setV('Accept failed: '+(o.j?.error||o.status)); return; }
        matchId=id; localStorage.setItem('prism_pvp_match_id',matchId); applyPvpState(o.j.state||null); setV('Accepted.');
      });

      q('#pf-pvp-load').addEventListener('click', loadMatch);
      q('#pf-pvp-spectate').addEventListener('click', async ()=>{
        const id=(q('#pf-pvp-id').value||matchId||'').trim();
        if(!id){ setV('No match id.'); return; }
        const o=await post('pet/pvp/spectate-link',{matchId:id});
        if(!o.ok||!o.j?.ok){ setV('Could not create spectator link.'); return; }
        const url=o.j.url||'';
        try{ await navigator.clipboard.writeText(url); setV('Spectator link copied.'); }
        catch{ setV(url||'Spectator link ready.'); }
      });

      qa('.pf-m').forEach(btn=>btn.addEventListener('click', async ()=>{
        const id=(q('#pf-pvp-id').value||matchId||'').trim();
        if(!id){ setV('No active match.'); return; }
        const move=btn.dataset.m;
        const o=await post('pet/pvp/move-pro',{matchId:id,move});
        if(!o.ok||!o.j?.ok){ setV('Move failed: '+(o.j?.error||o.status)); return; }
        applyPvpState(o.j.state||null);
        setV('Move submitted.');
      }));

      refreshCore().then(()=>{
        if(matchId) q('#pf-pvp-id').value=matchId;
        setP('Loaded.'); setS('Loaded.'); setV('Ready.');
      });
    })();
    </script>
    <?php
}, 1000000600);

// ===== Showdown V3 core (2026-03-09p): generated creature kits + true turn-based AI/PvP =====
if (!function_exists('prismtek_showdown_v3_types_for_species')) {
    function prismtek_showdown_v3_types_for_species($species){
        $s = sanitize_key((string)$species);
        $map = [
            'sprout' => ['nature','fairy'],
            'ember'  => ['fire','dragon'],
            'tidal'  => ['water','psychic'],
            'volt'   => ['electric','steel'],
            'shade'  => ['dark','ghost'],
        ];
        return $map[$s] ?? ['nature','fairy'];
    }

    function prismtek_showdown_v3_personality_mods($personality){
        $p = sanitize_key((string)$personality);
        $mods = [
            'brave'   => ['atk'=>8,'def'=>2,'spe'=>-2],
            'curious' => ['spa'=>6,'spe'=>4,'def'=>-2],
            'calm'    => ['spd'=>8,'def'=>4,'atk'=>-2],
            'chaotic' => ['spe'=>8,'atk'=>4,'spd'=>-3],
        ];
        return $mods[$p] ?? ['atk'=>2,'def'=>2,'spa'=>2,'spd'=>2,'spe'=>2];
    }

    function prismtek_showdown_v3_move_library(){
        return [
            'vine_arc'      => ['name'=>'Vine Arc','type'=>'nature','category'=>'physical','power'=>78,'accuracy'=>95,'effect'=>['targetStat'=>'def','stage'=>-1,'chance'=>20]],
            'bloom_pulse'   => ['name'=>'Bloom Pulse','type'=>'nature','category'=>'special','power'=>82,'accuracy'=>92,'effect'=>['status'=>'poison','chance'=>20]],
            'pixie_shell'   => ['name'=>'Pixie Shell','type'=>'fairy','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'def','stage'=>1,'chance'=>100]],
            'sap_recover'   => ['name'=>'Sap Recover','type'=>'nature','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['healPercent'=>26,'chance'=>100]],

            'flare_lunge'   => ['name'=>'Flare Lunge','type'=>'fire','category'=>'physical','power'=>84,'accuracy'=>93,'effect'=>['status'=>'burn','chance'=>20]],
            'nova_spark'    => ['name'=>'Nova Spark','type'=>'fire','category'=>'special','power'=>94,'accuracy'=>88,'effect'=>['targetStat'=>'spd','stage'=>-1,'chance'=>25]],
            'drake_focus'   => ['name'=>'Drake Focus','type'=>'dragon','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'spa','stage'=>1,'chance'=>100]],
            'scale_guard'   => ['name'=>'Scale Guard','type'=>'dragon','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'def','stage'=>1,'chance'=>100]],

            'tidal_shard'   => ['name'=>'Tidal Shard','type'=>'water','category'=>'special','power'=>88,'accuracy'=>94,'effect'=>['targetStat'=>'atk','stage'=>-1,'chance'=>20]],
            'mind_tide'     => ['name'=>'Mind Tide','type'=>'psychic','category'=>'special','power'=>76,'accuracy'=>100,'effect'=>['targetStat'=>'spd','stage'=>-1,'chance'=>30]],
            'aqua_screen'   => ['name'=>'Aqua Screen','type'=>'water','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'spd','stage'=>1,'chance'=>100]],
            'lucid_wave'    => ['name'=>'Lucid Wave','type'=>'psychic','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['healPercent'=>20,'selfStat'=>'spa','stage'=>1,'chance'=>100]],

            'ion_slash'     => ['name'=>'Ion Slash','type'=>'electric','category'=>'physical','power'=>86,'accuracy'=>92,'effect'=>['targetStat'=>'spe','stage'=>-1,'chance'=>20]],
            'rail_burst'    => ['name'=>'Rail Burst','type'=>'steel','category'=>'special','power'=>90,'accuracy'=>90,'effect'=>['selfStat'=>'spa','stage'=>1,'chance'=>20]],
            'overdrive'     => ['name'=>'Overdrive','type'=>'electric','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'spe','stage'=>2,'chance'=>100]],
            'magnet_shield' => ['name'=>'Magnet Shield','type'=>'steel','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'def','stage'=>1,'chance'=>100]],

            'night_rend'    => ['name'=>'Night Rend','type'=>'dark','category'=>'physical','power'=>84,'accuracy'=>95,'effect'=>['targetStat'=>'def','stage'=>-1,'chance'=>15]],
            'phantom_ray'   => ['name'=>'Phantom Ray','type'=>'ghost','category'=>'special','power'=>90,'accuracy'=>92,'effect'=>['targetStat'=>'spa','stage'=>-1,'chance'=>25]],
            'voidstep'      => ['name'=>'Voidstep','type'=>'ghost','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['selfStat'=>'spe','stage'=>2,'chance'=>100]],
            'eclipse_veil'  => ['name'=>'Eclipse Veil','type'=>'dark','category'=>'status','power'=>0,'accuracy'=>100,'effect'=>['targetStat'=>'acc','stage'=>-1,'chance'=>100]],
        ];
    }

    function prismtek_showdown_v3_type_chart(){
        return [
            'fire'=>['nature'=>2,'water'=>0.5,'fire'=>0.5,'dragon'=>0.5,'steel'=>2],
            'water'=>['fire'=>2,'nature'=>0.5,'electric'=>0.5,'dragon'=>0.5],
            'electric'=>['water'=>2,'nature'=>0.5,'electric'=>0.5,'steel'=>0.5],
            'nature'=>['water'=>2,'fire'=>0.5,'nature'=>0.5,'dragon'=>0.5,'steel'=>0.5],
            'psychic'=>['dark'=>0.5,'steel'=>0.5,'ghost'=>1],
            'steel'=>['fairy'=>2,'dragon'=>1,'fire'=>0.5,'water'=>0.5,'electric'=>0.5,'steel'=>0.5],
            'dragon'=>['dragon'=>2,'steel'=>0.5,'fairy'=>0],
            'fairy'=>['dragon'=>2,'fire'=>0.5,'steel'=>0.5,'dark'=>2],
            'dark'=>['psychic'=>2,'fairy'=>0.5],
            'ghost'=>['psychic'=>2,'dark'=>0.5],
        ];
    }

    function prismtek_showdown_v3_pet_state($uid){
        if(function_exists('prismtek_pet_get_state')){
            $p=prismtek_pet_get_state((int)$uid);
            if(is_array($p)) return $p;
        }
        $p=get_user_meta((int)$uid,'prismtek_pet_state',true);
        return is_array($p)?$p:[];
    }

    function prismtek_showdown_v3_default_species_stats($species){
        $s=sanitize_key((string)$species);
        $base=[
            'sprout'=>['hp'=>92,'atk'=>80,'def'=>88,'spa'=>94,'spd'=>96,'spe'=>72],
            'ember' =>['hp'=>88,'atk'=>98,'def'=>78,'spa'=>108,'spd'=>85,'spe'=>92],
            'tidal' =>['hp'=>96,'atk'=>70,'def'=>86,'spa'=>102,'spd'=>101,'spe'=>84],
            'volt'  =>['hp'=>84,'atk'=>95,'def'=>82,'spa'=>86,'spd'=>80,'spe'=>104],
            'shade' =>['hp'=>86,'atk'=>92,'def'=>76,'spa'=>96,'spd'=>88,'spe'=>108],
        ];
        return $base[$s] ?? $base['sprout'];
    }

    function prismtek_showdown_v3_seeded_pick($arr, $seed, $count=4){
        $items=array_values($arr);
        if(empty($items)) return [];
        $out=[]; $used=[];
        for($i=0;$i<$count;$i++){
            $idx = abs(crc32($seed.'|'.$i)) % count($items);
            $tries=0;
            while(isset($used[$idx]) && $tries < count($items)){
                $idx = ($idx+1)%count($items); $tries++;
            }
            $used[$idx]=1; $out[]=$items[$idx];
            if(count($used)>=count($items)) break;
        }
        return $out;
    }

    function prismtek_showdown_v3_generate_kit($uid){
        $uid=(int)$uid;
        $pet=prismtek_showdown_v3_pet_state($uid);
        $species=sanitize_key((string)($pet['species'] ?? 'sprout'));
        $personality=sanitize_key((string)($pet['personality'] ?? 'brave'));
        $types=prismtek_showdown_v3_types_for_species($species);
        $base=prismtek_showdown_v3_default_species_stats($species);
        $mods=prismtek_showdown_v3_personality_mods($personality);
        foreach($mods as $k=>$v){ if(isset($base[$k])) $base[$k]=max(35,(int)$base[$k]+(int)$v); }

        $lib=prismtek_showdown_v3_move_library();
        $byType=[];
        foreach($lib as $mid=>$m){ $byType[sanitize_key((string)$m['type'])][]=$mid; }

        $seed = $uid.'|'.$species.'|'.$personality.'|'.((string)($pet['name'] ?? 'prismo'));
        $pool=[];
        foreach($types as $t){ if(!empty($byType[$t])) $pool=array_merge($pool,$byType[$t]); }
        if(empty($pool)) $pool=array_keys($lib);
        $moves=prismtek_showdown_v3_seeded_pick(array_values(array_unique($pool)),$seed,4);
        if(count($moves)<4){
            $fallback=prismtek_showdown_v3_seeded_pick(array_keys($lib),$seed.'|fallback',4-count($moves));
            $moves=array_values(array_unique(array_merge($moves,$fallback)));
        }
        $moves=array_slice($moves,0,4);

        $prompt = "Create Prism Creature sprite sheet for {$species} ({$personality}). 3 stages: baby, teen, adult. Keep silhouette readable and consistent. Pixel art only. No text/watermarks.";
        $format = [
            'canvas' => 'PNG sprite sheet (recommended 384x320 for 4x4, frame 96x80) or valid JSON-mapped atlas',
            'stages' => 'Provide baby/teen/adult variants with same identity and progression',
            'readability' => 'High contrast, clear silhouette, no tiny noisy details',
            'constraints' => 'No copyrighted characters, no text, no watermark',
        ];

        $kit=[
            'version'=>1,
            'species'=>$species,
            'personality'=>$personality,
            'types'=>$types,
            'stats'=>$base,
            'moves'=>$moves,
            'generatedAt'=>time(),
            'artPrompt'=>$prompt,
            'artFormat'=>$format,
        ];
        update_user_meta($uid,'prismtek_pet_battlekit_v3',$kit);
        return $kit;
    }

    function prismtek_showdown_v3_get_kit($uid){
        $uid=(int)$uid;
        $kit=get_user_meta($uid,'prismtek_pet_battlekit_v3',true);
        if(!is_array($kit) || empty($kit['moves']) || empty($kit['stats'])) $kit=prismtek_showdown_v3_generate_kit($uid);
        return $kit;
    }

    function prismtek_showdown_v3_build_fighter_from_uid($uid, $level=50){
        $uid=(int)$uid;
        $kit=prismtek_showdown_v3_get_kit($uid);
        $pet=prismtek_showdown_v3_pet_state($uid);
        $name=(string)($pet['name'] ?? 'Prismo');
        $hp=(int)($kit['stats']['hp'] ?? 80) + (int)$level;
        return [
            'uid'=>$uid,
            'name'=>$name,
            'species'=>(string)$kit['species'],
            'types'=>array_values((array)($kit['types'] ?? ['nature'])),
            'stats'=>(array)$kit['stats'],
            'moves'=>array_values((array)$kit['moves']),
            'level'=>(int)$level,
            'hp'=>$hp,
            'maxHp'=>$hp,
            'status'=>null,
            'fainted'=>false,
            'stages'=>['atk'=>0,'def'=>0,'spa'=>0,'spd'=>0,'spe'=>0,'acc'=>0,'eva'=>0],
        ];
    }

    function prismtek_showdown_v3_stage_mult($stage){
        $s=max(-6,min(6,(int)$stage));
        if($s>=0) return (2+$s)/2;
        return 2/(2+abs($s));
    }

    function prismtek_showdown_v3_stat($f, $key){
        $base=(float)($f['stats'][$key] ?? 1);
        $stage=(int)($f['stages'][$key] ?? 0);
        return max(1, $base * prismtek_showdown_v3_stage_mult($stage));
    }

    function prismtek_showdown_v3_type_mod($moveType, $defTypes){
        $chart=prismtek_showdown_v3_type_chart();
        $mt=sanitize_key((string)$moveType);
        $m=1.0;
        foreach((array)$defTypes as $t){
            $tk=sanitize_key((string)$t);
            $m*= (float)($chart[$mt][$tk] ?? 1.0);
        }
        return $m;
    }

    function prismtek_showdown_v3_accuracy_hit($move, $att, $def){
        $acc=(float)($move['accuracy'] ?? 100);
        $acc*=prismtek_showdown_v3_stage_mult((int)($att['stages']['acc'] ?? 0));
        $acc/=max(0.01,prismtek_showdown_v3_stage_mult((int)($def['stages']['eva'] ?? 0)));
        $acc=max(1,min(100,$acc));
        return (mt_rand(1,10000)/100.0) <= $acc;
    }

    function prismtek_showdown_v3_damage($att, $def, $move){
        $power=(int)($move['power'] ?? 0);
        if($power<=0) return ['damage'=>0,'typeMod'=>1.0];
        $cat=sanitize_key((string)($move['category'] ?? 'physical'));
        $atk = $cat==='physical' ? prismtek_showdown_v3_stat($att,'atk') : prismtek_showdown_v3_stat($att,'spa');
        $dfn = $cat==='physical' ? prismtek_showdown_v3_stat($def,'def') : prismtek_showdown_v3_stat($def,'spd');
        $lvl=(int)($att['level'] ?? 50);
        $base = (((2*$lvl/5 + 2) * $power * ($atk/max(1,$dfn))) / 50) + 2;
        $stab = in_array(sanitize_key((string)$move['type']), array_map('sanitize_key',(array)$att['types']), true) ? 1.5 : 1.0;
        $typeMod = prismtek_showdown_v3_type_mod((string)$move['type'], (array)$def['types']);
        $rand = 0.85 + (mt_rand(0,1500)/10000.0);
        $burn = ((string)($att['status'] ?? '')==='burn' && $cat==='physical') ? 0.5 : 1.0;
        $d = (int)floor(max(1, $base * $stab * $typeMod * $rand * $burn));
        return ['damage'=>$d,'typeMod'=>$typeMod];
    }

    function prismtek_showdown_v3_apply_effect($move, &$att, &$def, &$log){
        $ef = is_array($move['effect'] ?? null) ? $move['effect'] : [];
        if(empty($ef)) return;
        $chance=(int)($ef['chance'] ?? 0);
        $ok = (mt_rand(1,100) <= max(0,min(100,$chance)));
        if(!$ok) return;

        if(!empty($ef['selfStat'])){
            $k=sanitize_key((string)$ef['selfStat']);
            $att['stages'][$k]=max(-6,min(6,(int)($att['stages'][$k] ?? 0)+(int)($ef['stage'] ?? 0)));
            $log[]=$att['name']."'s ".strtoupper($k)." changed.";
        }
        if(!empty($ef['targetStat'])){
            $k=sanitize_key((string)$ef['targetStat']);
            $def['stages'][$k]=max(-6,min(6,(int)($def['stages'][$k] ?? 0)+(int)($ef['stage'] ?? 0)));
            $log[]=$def['name']."'s ".strtoupper($k)." changed.";
        }
        if(!empty($ef['status']) && empty($def['status'])){
            $def['status']=sanitize_key((string)$ef['status']);
            $log[]=$def['name'].' is now '.strtoupper((string)$def['status']).'!';
        }
        if(!empty($ef['healPercent'])){
            $heal=max(1,(int)floor((float)$att['maxHp']*((float)$ef['healPercent']/100.0)));
            $att['hp']=min((int)$att['maxHp'], (int)$att['hp']+$heal);
            $log[]=$att['name'].' restored '.$heal.' HP.';
        }
    }

    function prismtek_showdown_v3_end_status(&$f, &$log){
        if(!empty($f['fainted'])) return;
        $st=sanitize_key((string)($f['status'] ?? ''));
        if($st==='poison'){
            $d=max(1,(int)floor((float)$f['maxHp']*0.08));
            $f['hp']=max(0,(int)$f['hp']-$d);
            $log[]=$f['name'].' is hurt by poison ('.$d.').';
        }
        if($st==='burn'){
            $d=max(1,(int)floor((float)$f['maxHp']*0.06));
            $f['hp']=max(0,(int)$f['hp']-$d);
            $log[]=$f['name'].' is hurt by burn ('.$d.').';
        }
        if((int)$f['hp']<=0){ $f['hp']=0; $f['fainted']=true; }
    }

    function prismtek_showdown_v3_apply_move(&$att,&$def,$moveId,&$log){
        $lib=prismtek_showdown_v3_move_library();
        if(empty($lib[$moveId])){ $log[]='Move failed (unknown move).'; return; }
        $m=$lib[$moveId];
        $log[]=$att['name'].' used '.$m['name'].'!';
        if(!prismtek_showdown_v3_accuracy_hit($m,$att,$def)){ $log[]='It missed!'; return; }
        $out=prismtek_showdown_v3_damage($att,$def,$m);
        if((int)$out['damage']>0){
            $def['hp']=max(0,(int)$def['hp']-(int)$out['damage']);
            if((float)$out['typeMod']>=2) $log[]='It is super effective!';
            elseif((float)$out['typeMod']<1) $log[]='It is not very effective...';
            $log[]=$def['name'].' took '.(int)$out['damage'].' damage.';
            if((int)$def['hp']<=0){ $def['hp']=0; $def['fainted']=true; }
        }
        prismtek_showdown_v3_apply_effect($m,$att,$def,$log);
    }

    function prismtek_showdown_v3_ai_pick($ai,$pl){
        $lib=prismtek_showdown_v3_move_library();
        $best=[];
        foreach((array)$ai['moves'] as $mid){
            if(empty($lib[$mid])) continue;
            $m=$lib[$mid];
            $score=10+mt_rand(0,4);
            if((int)($m['power']??0)>0){
                $d=prismtek_showdown_v3_damage($ai,$pl,$m);
                $score += ((int)$d['damage'])/8;
                if((float)$d['typeMod']>1) $score += 6;
                if(((int)$pl['hp']-(int)$d['damage'])<=0) $score += 20;
            } else {
                if(((int)$ai['hp']) < ((int)$ai['maxHp']*0.45) && !empty($m['effect']['healPercent'])) $score += 12;
            }
            $best[]=['mid'=>$mid,'score'=>$score];
        }
        usort($best,function($a,$b){ return $b['score'] <=> $a['score']; });
        if(empty($best)) return (string)($ai['moves'][0] ?? '');
        $pick=array_slice($best,0,min(2,count($best)));
        $idx=mt_rand(0,max(0,count($pick)-1));
        return (string)$pick[$idx]['mid'];
    }

    function prismtek_showdown_v3_resolve_turn(&$state, $playerChoice){
        $log=[];
        if(!empty($state['over'])) return $log;
        $p=&$state['player']; $o=&$state['opponent'];
        $aiChoice=prismtek_showdown_v3_ai_pick($o,$p);
        $pSpe=prismtek_showdown_v3_stat($p,'spe') * (((string)($p['status']??'')==='paralyze')?0.5:1.0);
        $oSpe=prismtek_showdown_v3_stat($o,'spe') * (((string)($o['status']??'')==='paralyze')?0.5:1.0);
        $first = ($pSpe===$oSpe) ? (mt_rand(0,1)?'player':'opponent') : (($pSpe>$oSpe)?'player':'opponent');
        $second = $first==='player'?'opponent':'player';
        $log[]='Turn '.(int)$state['turn'].': '.(($first==='player')?$p['name']:$o['name']).' moves first.';

        if($first==='player') prismtek_showdown_v3_apply_move($p,$o,$playerChoice,$log);
        else prismtek_showdown_v3_apply_move($o,$p,$aiChoice,$log);

        if(empty($state[$second]['fainted'])){
            if($second==='player') prismtek_showdown_v3_apply_move($p,$o,$playerChoice,$log);
            else prismtek_showdown_v3_apply_move($o,$p,$aiChoice,$log);
        }

        prismtek_showdown_v3_end_status($p,$log);
        prismtek_showdown_v3_end_status($o,$log);

        if(!empty($p['fainted']) || !empty($o['fainted'])){
            $state['over']=true;
            if(!empty($p['fainted']) && !empty($o['fainted'])) $state['winner']='draw';
            elseif(!empty($o['fainted'])) $state['winner']='player';
            else $state['winner']='opponent';
            if($state['winner']==='player') $log[]='You win the battle!';
            elseif($state['winner']==='opponent') $log[]='You were defeated.';
            else $log[]='Draw.';
        }

        $state['turn']=(int)$state['turn']+1;
        $state['log']=array_slice(array_merge((array)$state['log'],$log),-80);
        return $log;
    }

    function prismtek_showdown_v3_matches_get(){
        $m=get_option('prismtek_showdown_v3_matches',[]);
        return is_array($m)?$m:[];
    }
    function prismtek_showdown_v3_matches_set($m){ update_option('prismtek_showdown_v3_matches',$m,false); }

    function prismtek_showdown_v3_state_for_user($m, $uid){
        $uid=(int)$uid;
        $a=(int)($m['a'] ?? 0); $b=(int)($m['b'] ?? 0);
        $you = ($a===$uid)?'a':'b';
        $opp = ($you==='a')?'b':'a';
        $fy = (array)($m['fighters'][$you] ?? []);
        $fo = (array)($m['fighters'][$opp] ?? []);
        $state=[
            'id'=>(string)$m['id'],
            'status'=>(string)($m['status'] ?? 'pending'),
            'done'=>!empty($m['over']),
            'turn'=>(int)($m['turn'] ?? 1),
            'log'=>(array)($m['log'] ?? []),
            'participants'=>[
                'you'=>['id'=>$uid,'name'=>(string)($fy['name'] ?? 'You'),'species'=>(string)($fy['species'] ?? 'sprout')],
                'opp'=>['id'=>($you==='a'?$b:$a),'name'=>(string)($fo['name'] ?? 'Opponent'),'species'=>(string)($fo['species'] ?? 'ember')],
            ],
            'hp'=>['you'=>(int)($fy['hp'] ?? 0),'opp'=>(int)($fo['hp'] ?? 0)],
            'maxHp'=>['you'=>(int)($fy['maxHp'] ?? 1),'opp'=>(int)($fo['maxHp'] ?? 1)],
            'moves'=>(array)($fy['moves'] ?? []),
            'winner'=>(string)($m['winner'] ?? ''),
        ];
        return $state;
    }
}

add_action('added_user_meta', function($mid,$uid,$key,$val){
    if($key==='prismtek_pet_state') prismtek_showdown_v3_generate_kit((int)$uid);
},10,4);
add_action('updated_user_meta', function($mid,$uid,$key,$val){
    if($key==='prismtek_pet_state') prismtek_showdown_v3_generate_kit((int)$uid);
},10,4);

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/showdown/spec',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $kit=prismtek_showdown_v3_get_kit($uid);
            return rest_ensure_response(['ok'=>true,'kit'=>$kit,'moveLibrary'=>prismtek_showdown_v3_move_library()]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/ensure-kit',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $kit=prismtek_showdown_v3_generate_kit($uid);
            return rest_ensure_response(['ok'=>true,'kit'=>$kit]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/ai/start',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $oppSpecies=sanitize_key((string)$r->get_param('oppSpecies')); if($oppSpecies==='') $oppSpecies='volt';
            $tmpUid=0-$uid; // synthetic AI id bucket
            $aiKit=prismtek_showdown_v3_get_kit($uid);
            $aiKit['species']=$oppSpecies;
            $aiKit['types']=prismtek_showdown_v3_types_for_species($oppSpecies);
            $aiKit['stats']=prismtek_showdown_v3_default_species_stats($oppSpecies);
            $aiKit['moves']=array_slice(prismtek_showdown_v3_seeded_pick(array_keys(prismtek_showdown_v3_move_library()), 'ai|'.$uid.'|'.$oppSpecies,4),0,4);
            $level=50;
            $player=prismtek_showdown_v3_build_fighter_from_uid($uid,$level);
            $opp=[
                'uid'=>$tmpUid,'name'=>'AI '.$oppSpecies,'species'=>$oppSpecies,'types'=>$aiKit['types'],'stats'=>$aiKit['stats'],'moves'=>$aiKit['moves'],'level'=>$level,
                'hp'=>$aiKit['stats']['hp']+$level,'maxHp'=>$aiKit['stats']['hp']+$level,'status'=>null,'fainted'=>false,
                'stages'=>['atk'=>0,'def'=>0,'spa'=>0,'spd'=>0,'spe'=>0,'acc'=>0,'eva'=>0],
            ];
            $id='ai_'.wp_generate_password(10,false,false);
            $state=['id'=>$id,'turn'=>1,'over'=>false,'winner'=>'','player'=>$player,'opponent'=>$opp,'log'=>['AI battle started.']];
            update_user_meta($uid,'prismtek_showdown_ai_state_v3',$state);
            return rest_ensure_response(['ok'=>true,'state'=>$state]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/ai/move',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $move=sanitize_key((string)$r->get_param('move'));
            $state=get_user_meta($uid,'prismtek_showdown_ai_state_v3',true);
            if(!is_array($state)||empty($state['id'])) return new WP_REST_Response(['ok'=>false,'error'=>'no_active_ai_battle'],400);
            if(!in_array($move,(array)($state['player']['moves'] ?? []),true)) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_move'],400);
            prismtek_showdown_v3_resolve_turn($state,$move);
            update_user_meta($uid,'prismtek_showdown_ai_state_v3',$state);
            return rest_ensure_response(['ok'=>true,'state'=>$state]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/pvp/challenge',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $oppUser=sanitize_user((string)$r->get_param('opponent'));
            if($oppUser==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_opponent'],400);
            $opp=get_user_by('login',$oppUser);
            if(!$opp || !(int)$opp->ID) return new WP_REST_Response(['ok'=>false,'error'=>'opponent_not_found'],404);
            $oid=(int)$opp->ID; if($oid===$uid) return new WP_REST_Response(['ok'=>false,'error'=>'cannot_self_challenge'],400);

            $id='pvp3_'.wp_generate_password(12,false,false);
            $m=[
                'id'=>$id,'a'=>$uid,'b'=>$oid,'status'=>'pending','turn'=>1,'over'=>false,'winner'=>'',
                'fighters'=>[
                    'a'=>prismtek_showdown_v3_build_fighter_from_uid($uid,50),
                    'b'=>prismtek_showdown_v3_build_fighter_from_uid($oid,50),
                ],
                'choices'=>[],'log'=>['Challenge created. Waiting for acceptance.'],'createdAt'=>time(),'updatedAt'=>time(),
            ];
            $all=prismtek_showdown_v3_matches_get();
            $all[$id]=$m;
            prismtek_showdown_v3_matches_set($all);
            return rest_ensure_response(['ok'=>true,'matchId'=>$id]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/pvp/accept',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $all=prismtek_showdown_v3_matches_get();
            if(empty($all[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$all[$id];
            if((int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            $m['status']='active';
            $m['log'][]='Battle accepted. Fight!';
            $m['updatedAt']=time();
            $all[$id]=$m; prismtek_showdown_v3_matches_set($all);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_showdown_v3_state_for_user($m,$uid)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/pvp/state',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $all=prismtek_showdown_v3_matches_get();
            if(empty($all[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$all[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_showdown_v3_state_for_user($m,$uid)]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/pvp/move',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id(); if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $id=sanitize_text_field((string)$r->get_param('matchId'));
            $move=sanitize_key((string)$r->get_param('move'));
            $all=prismtek_showdown_v3_matches_get();
            if(empty($all[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
            $m=$all[$id];
            if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
            if(($m['status'] ?? '')!=='active') return new WP_REST_Response(['ok'=>false,'error'=>'match_not_active'],400);
            if(!empty($m['over'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_showdown_v3_state_for_user($m,$uid)]);

            $slot=((int)$m['a']===$uid)?'a':'b';
            $opp = ($slot==='a')?'b':'a';
            $fighter=(array)($m['fighters'][$slot] ?? []);
            if(!in_array($move,(array)($fighter['moves'] ?? []),true)) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_move'],400);
            $m['choices'][$slot]=$move;

            if(!empty($m['choices']['a']) && !empty($m['choices']['b'])){
                // Resolve both choices in showdown style.
                $fa=&$m['fighters']['a']; $fb=&$m['fighters']['b'];
                $ma=(string)$m['choices']['a']; $mb=(string)$m['choices']['b'];
                $aSpe=prismtek_showdown_v3_stat($fa,'spe') * (((string)($fa['status']??'')==='paralyze')?0.5:1.0);
                $bSpe=prismtek_showdown_v3_stat($fb,'spe') * (((string)($fb['status']??'')==='paralyze')?0.5:1.0);
                $first=($aSpe===$bSpe)?(mt_rand(0,1)?'a':'b'):(($aSpe>$bSpe)?'a':'b');
                $second=($first==='a')?'b':'a';
                $turnLog=['Turn '.(int)$m['turn'].': '.($first==='a'?$fa['name']:$fb['name']).' moves first.'];

                if($first==='a') prismtek_showdown_v3_apply_move($fa,$fb,$ma,$turnLog); else prismtek_showdown_v3_apply_move($fb,$fa,$mb,$turnLog);
                if(empty($m['fighters'][$second]['fainted'])){
                    if($second==='a') prismtek_showdown_v3_apply_move($fa,$fb,$ma,$turnLog); else prismtek_showdown_v3_apply_move($fb,$fa,$mb,$turnLog);
                }

                prismtek_showdown_v3_end_status($fa,$turnLog);
                prismtek_showdown_v3_end_status($fb,$turnLog);

                if(!empty($fa['fainted']) || !empty($fb['fainted'])){
                    $m['over']=true;
                    if(!empty($fa['fainted']) && !empty($fb['fainted'])) $m['winner']='draw';
                    elseif(!empty($fb['fainted'])) $m['winner']='a';
                    else $m['winner']='b';
                    if($m['winner']==='draw') $turnLog[]='Draw.';
                    elseif($m['winner']==='a') $turnLog[]='Winner: '.$fa['name'];
                    else $turnLog[]='Winner: '.$fb['name'];
                }
                $m['turn']=(int)$m['turn']+1;
                $m['choices']=[];
                $m['log']=array_slice(array_merge((array)$m['log'],$turnLog),-100);
            } else {
                $m['log']=array_slice(array_merge((array)$m['log'], [$fighter['name'].' locked in '.$move.'.']),-100);
            }

            $m['updatedAt']=time();
            $all[$id]=$m; prismtek_showdown_v3_matches_set($all);
            return rest_ensure_response(['ok'=>true,'state'=>prismtek_showdown_v3_state_for_user($m,$uid)]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <script id="prism-showdown-v3-ui-bridge">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const setS=(t)=>{ const e=q('#pf-sprite-status'); if(e) e.textContent=t; };
      const setV=(t)=>{ const e=q('#pf-pvp-status'); if(e) e.textContent=t; };

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      // Ensure generated kit exists whenever creature page loads.
      post('pet/showdown/ensure-kit',{}).then(()=>{});

      // Publish prompt/art format guidance directly in UI.
      const spriteCard=q('#pf-sprites');
      if(spriteCard && !q('#pf-format-guide')){
        const box=document.createElement('details');
        box.id='pf-format-guide';
        box.style.marginTop='8px';
        box.innerHTML='<summary><strong>Required Prompt + Art Format Guide</strong></summary><div id="pf-format-body" style="font-size:12px;line-height:1.45;margin-top:8px;color:#dbe4ff">Loading guide...</div>';
        spriteCard.appendChild(box);
        get('pet/showdown/spec').then(o=>{
          if(!o.ok||!o.j?.ok){ q('#pf-format-body').textContent='Guide unavailable.'; return; }
          const k=o.j.kit||{};
          const fmt=k.artFormat||{};
          q('#pf-format-body').innerHTML=
            '<p><strong>Prompt template:</strong> '+(k.artPrompt||'')+'</p>'+
            '<ul>'+
            '<li><strong>Canvas:</strong> '+(fmt.canvas||'')+'</li>'+
            '<li><strong>Stages:</strong> '+(fmt.stages||'')+'</li>'+
            '<li><strong>Readability:</strong> '+(fmt.readability||'')+'</li>'+
            '<li><strong>Constraints:</strong> '+(fmt.constraints||'')+'</li>'+
            '</ul>';
        });
      }

      // Replace generic pvp moves with generated creature move labels + use new showdown endpoints.
      function renderShowdownState(s){
        if(!s) return;
        const mwrap=q('#pf-pvp .pf-grid4'); if(!mwrap) return;
        const moves=Array.isArray(s.moves)?s.moves:[];
        if(moves.length){
          mwrap.innerHTML='';
          moves.forEach(mid=>{
            const b=document.createElement('button');
            b.className='pf-btn pf-m3'; b.dataset.mid=mid;
            b.textContent=mid.replace(/_/g,' ');
            b.addEventListener('click',()=>submitMove(mid));
            mwrap.appendChild(b);
          });
        }

        const log=q('#pf-pvp-log'); if(log) log.textContent=(s.log||[]).join('\n') || 'No logs yet.';
        const youName=q('#pf-you-name'); const oppName=q('#pf-opp-name');
        if(youName) youName.textContent=s.participants?.you?.name||'You';
        if(oppName) oppName.textContent=s.participants?.opp?.name||'Opponent';
        const ym=q('#pf-you-meta'), om=q('#pf-opp-meta');
        if(ym) ym.textContent='HP '+(s.hp?.you??0)+'/'+(s.maxHp?.you??1)+' · '+(s.participants?.you?.species||'');
        if(om) om.textContent='HP '+(s.hp?.opp??0)+'/'+(s.maxHp?.opp??1)+' · '+(s.participants?.opp?.species||'');
        const yb=q('#pf-you-hp'), ob=q('#pf-opp-hp');
        if(yb) yb.style.width=((Math.max(0,(s.hp?.you??0))/Math.max(1,(s.maxHp?.you??1)))*100).toFixed(1)+'%';
        if(ob) ob.style.width=((Math.max(0,(s.hp?.opp??0))/Math.max(1,(s.maxHp?.opp??1)))*100).toFixed(1)+'%';
        if(s.done) setV('Battle complete.');
      }

      let matchId=localStorage.getItem('prism_showdown_match_v3') || '';
      const idInput=q('#pf-pvp-id'); if(idInput && matchId) idInput.value=matchId;

      async function loadState(){
        const id=(idInput?.value||matchId||'').trim();
        if(!id){ setV('No match id.'); return; }
        matchId=id; localStorage.setItem('prism_showdown_match_v3',matchId);
        const o=await get('pet/showdown/pvp/state?matchId='+encodeURIComponent(matchId));
        if(!o.ok||!o.j?.ok){ setV('Load failed: '+(o.j?.error||o.status)); return; }
        renderShowdownState(o.j.state||null);
        setV('Showdown match loaded.');
      }

      async function submitMove(mid){
        if(!matchId){ setV('No active match.'); return; }
        const o=await post('pet/showdown/pvp/move',{matchId,move:mid});
        if(!o.ok||!o.j?.ok){ setV('Move failed: '+(o.j?.error||o.status)); return; }
        renderShowdownState(o.j.state||null);
      }

      // Wire over existing pvp buttons to new endpoints.
      const ch=q('#pf-pvp-challenge'), ac=q('#pf-pvp-accept'), ld=q('#pf-pvp-load');
      if(ch && !ch.dataset.v3){ ch.dataset.v3='1'; ch.addEventListener('click', async (e)=>{e.preventDefault(); const opp=(q('#pf-pvp-user')?.value||'').trim(); if(!opp){setV('Enter opponent username.');return;} const o=await post('pet/showdown/pvp/challenge',{opponent:opp}); if(!o.ok||!o.j?.ok){setV('Challenge failed: '+(o.j?.error||o.status));return;} matchId=o.j.matchId||''; if(idInput) idInput.value=matchId; localStorage.setItem('prism_showdown_match_v3',matchId); setV('Challenge created.'); }, true); }
      if(ac && !ac.dataset.v3){ ac.dataset.v3='1'; ac.addEventListener('click', async (e)=>{e.preventDefault(); const id=(idInput?.value||matchId||'').trim(); if(!id){setV('No match id.');return;} const o=await post('pet/showdown/pvp/accept',{matchId:id}); if(!o.ok||!o.j?.ok){setV('Accept failed: '+(o.j?.error||o.status));return;} matchId=id; localStorage.setItem('prism_showdown_match_v3',matchId); renderShowdownState(o.j.state||null); setV('Accepted.'); }, true); }
      if(ld && !ld.dataset.v3){ ld.dataset.v3='1'; ld.addEventListener('click', (e)=>{ e.preventDefault(); loadState(); }, true); }

      // Disable generic move buttons if present.
      qa('.pf-m').forEach(b=>{ b.disabled=true; b.style.opacity='.45'; b.title='Replaced by generated creature moves'; });

      // AI battle quick launcher using generated moves.
      const trainBtn=q('#pf-train');
      if(trainBtn && !trainBtn.dataset.v3){
        trainBtn.dataset.v3='1';
        trainBtn.addEventListener('click', async (e)=>{
          e.preventDefault();
          setV('Starting AI showdown battle...');
          const st=await post('pet/showdown/ai/start',{oppSpecies:'volt'});
          if(!st.ok||!st.j?.ok){ setV('AI start failed: '+(st.j?.error||st.status)); return; }
          const state=st.j.state||{};
          const mv=((state.player||{}).moves||[])[0]||'';
          if(!mv){ setV('No generated moves found.'); return; }
          const turn=await post('pet/showdown/ai/move',{move:mv});
          if(!turn.ok||!turn.j?.ok){ setV('AI move failed: '+(turn.j?.error||turn.status)); return; }
          const log=q('#pf-pvp-log'); if(log) log.textContent=(turn.j.state?.log||[]).join('\n');
          setV('AI showdown battle active. Use PvP panel for full turn flow.');
        }, true);
      }

      setS('Generated moves + format guide + showdown v3 routes ready.');
    })();
    </script>
    <?php
}, 1000000700);

// ===== Prism animation + move UX pass (2026-03-09q): partner/battle/dex animations + non-generic moves in PvP tab =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-anim-pass-v1-css">
      /* Partner hub idle animation */
      #pf-hero-img{animation:pfIdleFloat 2.8s ease-in-out infinite;transform-origin:center bottom}

      /* Battle idle loops */
      #pf-pvp-you{animation:pfIdleFloat 2.4s ease-in-out infinite;transform-origin:center bottom}
      #pf-pvp-opp{animation:pfIdleFloatOpp 2.2s ease-in-out infinite;transform-origin:center bottom}

      /* Dex/gallery animation polish */
      #pf-gallery .item img,
      #prism-dex-grid-11 .dex-item img,
      #gal-grid img{animation:pfDexPulse 2.9s ease-in-out infinite;transform-origin:center bottom}
      #pf-gallery .item:hover img,
      #prism-dex-grid-11 .dex-item:hover img,
      #gal-grid div:hover img{animation:pfDexHover .45s ease-out forwards}

      /* Showdown impact/faint helpers */
      .pf-hit{animation:pfHitShake .24s ease-out}
      .pf-faint{animation:pfFaintDrop .65s ease-in forwards}
      .pf-attack-you{animation:pfAttackLunge .26s ease-out}
      .pf-attack-opp{animation:pfAttackLungeOpp .26s ease-out}

      @keyframes pfIdleFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
      @keyframes pfIdleFloatOpp{0%,100%{transform:scaleX(-1) translateY(0)}50%{transform:scaleX(-1) translateY(-3px)}}
      @keyframes pfDexPulse{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-2px) scale(1.015)}}
      @keyframes pfDexHover{0%{transform:translateY(0) scale(1)}100%{transform:translateY(-3px) scale(1.03)}}
      @keyframes pfHitShake{0%{transform:translateX(0)}25%{transform:translateX(-4px)}50%{transform:translateX(3px)}75%{transform:translateX(-2px)}100%{transform:translateX(0)}}
      @keyframes pfFaintDrop{0%{opacity:1;transform:translateY(0)}100%{opacity:.2;transform:translateY(14px)}}
      @keyframes pfAttackLunge{0%{transform:translateX(0)}55%{transform:translateX(10px)}100%{transform:translateX(0)}}
      @keyframes pfAttackLungeOpp{0%{transform:scaleX(-1) translateX(0)}55%{transform:scaleX(-1) translateX(-10px)}100%{transform:scaleX(-1) translateX(0)}}
    </style>

    <script id="prism-anim-pass-v1-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // Ensure PvP tab uses generated moves immediately (no generic placeholders).
      const pvpWrap=q('#pf-pvp .pf-grid4');
      let currentMoveIds=[];

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

      function setPvpStatus(t){ const el=q('#pf-pvp-status'); if(el) el.textContent=t||''; }
      function applyImpactAnimation(actor){
        const you=q('#pf-pvp-you'), opp=q('#pf-pvp-opp');
        if(actor==='you' && you){ you.classList.remove('pf-attack-you'); void you.offsetWidth; you.classList.add('pf-attack-you'); }
        if(actor==='opp' && opp){ opp.classList.remove('pf-attack-opp'); void opp.offsetWidth; opp.classList.add('pf-attack-opp'); }
      }
      function applyHitAnimation(target){
        const you=q('#pf-pvp-you'), opp=q('#pf-pvp-opp');
        if(target==='you' && you){ you.classList.remove('pf-hit'); void you.offsetWidth; you.classList.add('pf-hit'); }
        if(target==='opp' && opp){ opp.classList.remove('pf-hit'); void opp.offsetWidth; opp.classList.add('pf-hit'); }
      }

      function renderGeneratedMoves(lib,moves){
        if(!pvpWrap) return;
        pvpWrap.innerHTML='';
        currentMoveIds = Array.isArray(moves)?moves.slice(0,4):[];
        currentMoveIds.forEach((mid,idx)=>{
          const m=lib?.[mid]||{};
          const b=document.createElement('button');
          b.className='pf-btn pf-mv3';
          b.dataset.mid=mid;
          const nm = m.name || mid.replace(/_/g,' ');
          const type = String(m.type||'').toUpperCase();
          b.textContent = nm;
          b.title = `${type} · ${(m.category||'').toUpperCase()} · Pow ${m.power||0} · Acc ${m.accuracy||100}%`;
          b.addEventListener('click', ()=>submitV3Move(mid, idx));
          pvpWrap.appendChild(b);
        });
      }

      async function loadSpecAndMoves(){
        const out=await get('pet/showdown/spec');
        if(!out.ok||!out.j?.ok) return;
        const kit=out.j.kit||{};
        renderGeneratedMoves(out.j.moveLibrary||{}, kit.moves||[]);
      }

      async function submitV3Move(mid, idx){
        const id=(q('#pf-pvp-id')?.value||localStorage.getItem('prism_showdown_match_v3')||'').trim();
        if(!id){ setPvpStatus('No active showdown match. Challenge/accept first.'); return; }
        applyImpactAnimation('you');
        const out=await post('pet/showdown/pvp/move',{matchId:id,move:mid});
        if(!out.ok||!out.j?.ok){ setPvpStatus('Move failed: '+(out.j?.error||'unknown')); return; }
        const s=out.j.state||{};
        const log=q('#pf-pvp-log'); if(log) log.textContent=(s.log||[]).join('\n')||'No logs yet.';

        // simple impact inference: on any move, shake both slightly for modern battle feel
        applyHitAnimation('opp');
        setTimeout(()=>applyHitAnimation('you'),120);

        // HP/faint animation pass
        const youHp=Number(s.hp?.you||0), youMax=Math.max(1,Number(s.maxHp?.you||1));
        const oppHp=Number(s.hp?.opp||0), oppMax=Math.max(1,Number(s.maxHp?.opp||1));
        const yb=q('#pf-you-hp'), ob=q('#pf-opp-hp');
        if(yb) yb.style.width=((youHp/youMax)*100).toFixed(1)+'%';
        if(ob) ob.style.width=((oppHp/oppMax)*100).toFixed(1)+'%';
        if(youHp<=0){ const y=q('#pf-pvp-you'); if(y) y.classList.add('pf-faint'); }
        if(oppHp<=0){ const o=q('#pf-pvp-opp'); if(o) o.classList.add('pf-faint'); }

        setPvpStatus(s.done?'Battle complete.':'Move submitted.');
      }

      // Tamagotchi-leaning but detailed visual polish note in UI.
      const spriteStatus=q('#pf-sprite-status');
      if(spriteStatus && !q('#pf-style-note')){
        const note=document.createElement('div');
        note.id='pf-style-note';
        note.style.cssText='margin-top:6px;font-size:12px;color:#dbe4ff';
        note.textContent='Style target: Tamagotchi-lean silhouette + modern detail. Keep readable faces, chunky outlines, and stage-consistent forms.';
        spriteStatus.parentElement?.appendChild(note);
      }

      // Refresh moves after challenge/accept/load so generic never appears.
      ['#pf-pvp-challenge','#pf-pvp-accept','#pf-pvp-load'].forEach(sel=>{
        const el=q(sel); if(!el) return;
        if(el.dataset.mv3Bound==='1') return;
        el.dataset.mv3Bound='1';
        el.addEventListener('click', ()=>setTimeout(loadSpecAndMoves, 400), true);
      });

      loadSpecAndMoves();
      setTimeout(loadSpecAndMoves, 900);
    })();
    </script>
    <?php
}, 1000000800);

// ===== Guide + idle animation reliability patch (2026-03-09r) =====
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/showdown/guide',[
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(){
            $uid=get_current_user_id();
            if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
            $kit = function_exists('prismtek_showdown_v3_get_kit') ? prismtek_showdown_v3_get_kit($uid) : [];
            $prompt = (string)($kit['artPrompt'] ?? 'Create Prism Creature sprite sheet with baby/teen/adult stages. Keep silhouette readable, cute tamagotchi-like face proportions, crisp pixel edges, no text or watermark.');
            $format = (array)($kit['artFormat'] ?? [
                'canvas'=>'PNG sprite sheet 384x320 (4x4 frames, frame 96x80) or valid JSON atlas',
                'stages'=>'Provide baby, teen, adult variants with consistent identity',
                'readability'=>'Chunky readable silhouette, clean outlines, expressive face',
                'constraints'=>'No copyrighted characters, no text, no watermark',
            ]);
            return rest_ensure_response(['ok'=>true,'prompt'=>$prompt,'format'=>$format]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-guide-anim-reliability-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // Fix "Guide unavailable" by fallback route + static fallback text.
      async function fixGuide(){
        const body=q('#pf-format-body');
        if(!body) return;
        const txt=(body.textContent||'').toLowerCase().trim();
        if(txt && !txt.includes('guide unavailable')) return;

        try{
          const r=await fetch(API+'pet/showdown/guide?ts='+Date.now(),{credentials:'include'});
          const j=await r.json().catch(()=>({}));
          if(r.ok && j.ok){
            const f=j.format||{};
            body.innerHTML='<p><strong>Prompt template:</strong> '+(j.prompt||'')+'</p>'+
              '<ul>'+
              '<li><strong>Canvas:</strong> '+(f.canvas||'')+'</li>'+
              '<li><strong>Stages:</strong> '+(f.stages||'')+'</li>'+
              '<li><strong>Readability:</strong> '+(f.readability||'')+'</li>'+
              '<li><strong>Constraints:</strong> '+(f.constraints||'')+'</li>'+
              '</ul>';
            return;
          }
        } catch(e){}

        body.innerHTML='<p><strong>Prompt template:</strong> Create Prism Creature sprite sheet with baby/teen/adult stages. Tamagotchi-leaning proportions, modern detail, crisp pixel edges, no text/watermark.</p><ul><li><strong>Canvas:</strong> PNG 384x320 sheet (4x4, frame 96x80) or JSON atlas.</li><li><strong>Stages:</strong> Baby, Teen, Adult with consistent identity.</li><li><strong>Readability:</strong> Chunky silhouette, expressive face, clear outline.</li><li><strong>Constraints:</strong> No copyrighted characters, no text, no watermark.</li></ul>';
      }

      // Ensure idle animations are visible in Dex hub + Sprite Forge image tiles.
      function enforceIdleAnimations(){
        const selectors=[
          '#pf-gallery img',
          '#prism-dex-grid-11 img',
          '#prism-curated-dex img',
          '#gal-grid img',
          '.pph-card img[data-dex], .pph-card .dex-item img'
        ];
        selectors.forEach(sel=>{
          qa(sel).forEach(img=>{
            img.style.imageRendering='pixelated';
            img.style.transformOrigin='center bottom';
            if(!img.dataset.idleAnim){
              img.style.animation='pfDexPulse 2.9s ease-in-out infinite';
              img.dataset.idleAnim='1';
            }
          });
        });
      }

      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', ()=>{fixGuide(); enforceIdleAnimations();});
      else { fixGuide(); enforceIdleAnimations(); }

      setTimeout(fixGuide, 900);
      setInterval(enforceIdleAnimations, 2500);
    })();
    </script>
    <?php
}, 1000000900);

// ===== Real Showdown UI mode (2026-03-09s): explicit turn phases for AI + PvP =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-real-showdown-css">
      #pf-pvp-real-wrap{margin-top:8px;border:2px solid #5f6ad1;background:#0b1031;padding:8px}
      #pf-pvp-real-wrap .row{display:grid;gap:8px;margin-top:8px}
      #pf-pvp-real-wrap .r2{grid-template-columns:1fr 1fr}
      #pf-pvp-real-wrap .r3{grid-template-columns:1fr 1fr 1fr}
      #pf-cmd-box{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #pf-cmd-box button{background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:10px;font-weight:800}
      #pf-move-menu{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #pf-move-menu button{background:#10173f;border:1px solid #5d68cf;color:#eef3ff;padding:10px;text-align:left}
      #pf-turn-text{margin-top:8px;min-height:52px;background:#f8f9ff;color:#151933;border:2px solid #1f2552;padding:8px;font-weight:700}
      .pf-hidden{display:none !important}
      @media (max-width:860px){#pf-pvp-real-wrap .r2,#pf-pvp-real-wrap .r3,#pf-cmd-box,#pf-move-menu{grid-template-columns:1fr}}
    </style>

    <script id="prism-real-showdown-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const card=q('#pf-pvp'); if(!card) return;

      // Hide older direct controls to enforce clear turn-based phases.
      qa('.pf-grid3, .pf-grid4', card).forEach((el,idx)=>{ if(idx<3) el.classList.add('pf-hidden'); });

      let wrap=q('#pf-pvp-real-wrap',card);
      if(!wrap){
        wrap=document.createElement('div');
        wrap.id='pf-pvp-real-wrap';
        wrap.innerHTML=''
          +'<div class="row r3">'
          +' <button id="pf-mode-ai" class="pf-btn">AI Battle Mode</button>'
          +' <button id="pf-mode-pvp" class="pf-btn">PvP Battle Mode</button>'
          +' <button id="pf-refresh-state" class="pf-btn">Refresh State</button>'
          +'</div>'
          +'<div id="pf-mode-ai-controls" class="row r2">'
          +' <select id="pf-ai-species" class="pf-select"><option value="volt">Voltigon</option><option value="ember">Pyronyx</option><option value="tidal">Aqualume</option><option value="shade">Noctivyre</option><option value="sprout">Spriglit</option></select>'
          +' <button id="pf-ai-start" class="pf-btn">Start AI Match</button>'
          +'</div>'
          +'<div id="pf-mode-pvp-controls" class="row r3 pf-hidden">'
          +' <input id="pf-r-opp" class="pf-input" placeholder="Opponent username">'
          +' <button id="pf-r-challenge" class="pf-btn">Challenge</button>'
          +' <button id="pf-r-accept" class="pf-btn">Accept</button>'
          +'</div>'
          +'<div id="pf-mode-pvp-controls2" class="row r2 pf-hidden">'
          +' <input id="pf-r-id" class="pf-input" placeholder="Match ID">'
          +' <button id="pf-r-load" class="pf-btn">Load Match</button>'
          +'</div>'
          +'<div id="pf-turn-text">Choose a mode and start battle.</div>'
          +'<div id="pf-cmd-box" class="row">'
          +' <button id="pf-cmd-fight">FIGHT</button>'
          +' <button id="pf-cmd-info">INFO</button>'
          +' <button id="pf-cmd-reset">RESET</button>'
          +' <button id="pf-cmd-run" disabled>RUN (disabled)</button>'
          +'</div>'
          +'<div id="pf-move-menu" class="row pf-hidden"></div>';
        card.appendChild(wrap);
      }

      let mode='ai';
      let moveLib={};
      let myMoves=[];
      let pvpMatchId=localStorage.getItem('prism_showdown_match_v3')||'';
      let aiActive=false;

      const turnText=q('#pf-turn-text');
      const setTurn=t=>{ if(turnText) turnText.textContent=t||''; };
      const setStatus=t=>{ const el=q('#pf-pvp-status'); if(el) el.textContent=t||''; };
      const logEl=q('#pf-pvp-log');

      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      function normalizeAIState(s){
        const p=s.player||{}, o=s.opponent||{};
        return {
          done: !!s.over,
          log: s.log||[],
          participants:{you:{name:p.name||'You',species:p.species||'sprout'},opp:{name:o.name||'AI',species:o.species||'volt'}},
          hp:{you:Number(p.hp||0),opp:Number(o.hp||0)},
          maxHp:{you:Number(p.maxHp||1),opp:Number(o.maxHp||1)},
          moves:Array.isArray(p.moves)?p.moves:[],
          winner:s.winner||''
        };
      }

      function speciesPretty(s){
        const m={sprout:'Spriglit',ember:'Pyronyx',tidal:'Aqualume',volt:'Voltigon',shade:'Noctivyre'};
        return m[String(s||'').toLowerCase()]||s||'Creature';
      }

      function renderState(st){
        if(!st) return;
        const you=st.participants?.you||{}, opp=st.participants?.opp||{};
        const yh=Number(st.hp?.you||0), ym=Math.max(1,Number(st.maxHp?.you||1));
        const oh=Number(st.hp?.opp||0), om=Math.max(1,Number(st.maxHp?.opp||1));

        const yn=q('#pf-you-name'), on=q('#pf-opp-name'), ymEl=q('#pf-you-meta'), omEl=q('#pf-opp-meta');
        if(yn) yn.textContent=you.name||'You';
        if(on) on.textContent=opp.name||'Opponent';
        if(ymEl) ymEl.textContent=`HP ${yh}/${ym} · ${speciesPretty(you.species)}`;
        if(omEl) omEl.textContent=`HP ${oh}/${om} · ${speciesPretty(opp.species)}`;

        const yb=q('#pf-you-hp'), ob=q('#pf-opp-hp');
        if(yb) yb.style.width=((yh/ym)*100).toFixed(1)+'%';
        if(ob) ob.style.width=((oh/om)*100).toFixed(1)+'%';

        if(logEl) logEl.textContent=(st.log||[]).join('\n')||'No logs yet.';
        myMoves = Array.isArray(st.moves)?st.moves:myMoves;

        if(st.done){
          if(st.winner==='player' || st.winner==='a' || st.winner==='you') setTurn('Battle ended: You win!');
          else if(st.winner==='draw') setTurn('Battle ended: Draw.');
          else setTurn('Battle ended: You lost.');
        } else {
          const last=(st.log||[]).slice(-1)[0]||'Choose your action.';
          setTurn(last);
        }
      }

      function renderMoveMenu(){
        const box=q('#pf-move-menu'); if(!box) return;
        box.innerHTML='';
        myMoves.slice(0,4).forEach(mid=>{
          const m=moveLib[mid]||{};
          const b=document.createElement('button');
          b.dataset.mid=mid;
          b.innerHTML=`<strong>${m.name||mid.replace(/_/g,' ')}</strong><br><small>${String(m.type||'').toUpperCase()} · ${String(m.category||'').toUpperCase()} · Pow ${m.power||0} · Acc ${m.accuracy||100}%</small>`;
          b.addEventListener('click',()=>lockMove(mid));
          box.appendChild(b);
        });
      }

      async function lockMove(mid){
        setTurn('Move selected: '+(moveLib[mid]?.name||mid)+'. Resolving turn...');
        qa('#pf-move-menu button').forEach(b=>b.disabled=true);
        if(mode==='ai'){
          const out=await post('pet/showdown/ai/move',{move:mid});
          qa('#pf-move-menu button').forEach(b=>b.disabled=false);
          if(!out.ok||!out.j?.ok){ setStatus('AI move failed: '+(out.j?.error||out.status)); return; }
          const st=normalizeAIState(out.j.state||{});
          renderState(st);
        } else {
          if(!pvpMatchId){ setStatus('No PvP match loaded.'); qa('#pf-move-menu button').forEach(b=>b.disabled=false); return; }
          const out=await post('pet/showdown/pvp/move',{matchId:pvpMatchId,move:mid});
          qa('#pf-move-menu button').forEach(b=>b.disabled=false);
          if(!out.ok||!out.j?.ok){ setStatus('PvP move failed: '+(out.j?.error||out.status)); return; }
          renderState(out.j.state||{});
          const last=(out.j.state?.log||[]).slice(-1)[0]||'';
          if(last.toLowerCase().includes('locked in')) setTurn('Move locked. Waiting for opponent...');
        }
      }

      async function loadSpec(){
        const o=await get('pet/showdown/spec');
        if(o.ok&&o.j?.ok){
          moveLib=o.j.moveLibrary||{};
          const km=o.j.kit?.moves||[];
          if(Array.isArray(km)&&km.length) myMoves=km.slice(0,4);
          renderMoveMenu();
        }
      }

      async function refreshState(){
        if(mode==='pvp' && pvpMatchId){
          const o=await get('pet/showdown/pvp/state?matchId='+encodeURIComponent(pvpMatchId));
          if(o.ok&&o.j?.ok) renderState(o.j.state||{});
        }
      }

      function showMode(next){
        mode=next;
        q('#pf-mode-ai-controls')?.classList.toggle('pf-hidden', mode!=='ai');
        q('#pf-mode-pvp-controls')?.classList.toggle('pf-hidden', mode!=='pvp');
        q('#pf-mode-pvp-controls2')?.classList.toggle('pf-hidden', mode!=='pvp');
        setTurn(mode==='ai'?'AI mode ready. Start a match.':'PvP mode ready. Challenge/accept/load match.');
      }

      q('#pf-mode-ai')?.addEventListener('click',()=>showMode('ai'));
      q('#pf-mode-pvp')?.addEventListener('click',()=>showMode('pvp'));
      q('#pf-refresh-state')?.addEventListener('click',refreshState);

      q('#pf-ai-start')?.addEventListener('click', async ()=>{
        const oppSpecies=(q('#pf-ai-species')?.value||'volt').trim();
        setStatus('Starting AI match...');
        const out=await post('pet/showdown/ai/start',{oppSpecies});
        if(!out.ok||!out.j?.ok){ setStatus('AI start failed: '+(out.j?.error||out.status)); return; }
        aiActive=true;
        const st=normalizeAIState(out.j.state||{});
        myMoves=st.moves||myMoves;
        renderMoveMenu();
        renderState(st);
        setStatus('AI battle started. Choose FIGHT then a move.');
      });

      q('#pf-r-challenge')?.addEventListener('click', async ()=>{
        const opp=(q('#pf-r-opp')?.value||'').trim(); if(!opp){ setStatus('Enter opponent username.'); return; }
        const out=await post('pet/showdown/pvp/challenge',{opponent:opp});
        if(!out.ok||!out.j?.ok){ setStatus('Challenge failed: '+(out.j?.error||out.status)); return; }
        pvpMatchId=out.j.matchId||''; localStorage.setItem('prism_showdown_match_v3',pvpMatchId);
        if(q('#pf-r-id')) q('#pf-r-id').value=pvpMatchId;
        if(q('#pf-pvp-id')) q('#pf-pvp-id').value=pvpMatchId;
        setTurn('Challenge created. Ask opponent to accept with Match ID.');
      });

      q('#pf-r-accept')?.addEventListener('click', async ()=>{
        const id=(q('#pf-r-id')?.value||pvpMatchId||'').trim(); if(!id){ setStatus('No match id.'); return; }
        const out=await post('pet/showdown/pvp/accept',{matchId:id});
        if(!out.ok||!out.j?.ok){ setStatus('Accept failed: '+(out.j?.error||out.status)); return; }
        pvpMatchId=id; localStorage.setItem('prism_showdown_match_v3',pvpMatchId);
        renderState(out.j.state||{});
        myMoves=(out.j.state?.moves||myMoves);
        renderMoveMenu();
      });

      q('#pf-r-load')?.addEventListener('click', async ()=>{
        const id=(q('#pf-r-id')?.value||pvpMatchId||'').trim(); if(!id){ setStatus('No match id.'); return; }
        const out=await get('pet/showdown/pvp/state?matchId='+encodeURIComponent(id));
        if(!out.ok||!out.j?.ok){ setStatus('Load failed: '+(out.j?.error||out.status)); return; }
        pvpMatchId=id; localStorage.setItem('prism_showdown_match_v3',pvpMatchId);
        renderState(out.j.state||{});
        myMoves=(out.j.state?.moves||myMoves);
        renderMoveMenu();
      });

      q('#pf-cmd-fight')?.addEventListener('click',()=>{ q('#pf-move-menu')?.classList.remove('pf-hidden'); setTurn('Choose your move.'); });
      q('#pf-cmd-info')?.addEventListener('click',()=>{ setTurn('Turn-based flow: choose move -> lock in -> resolve by speed/accuracy/damage/effects.'); });
      q('#pf-cmd-reset')?.addEventListener('click',()=>{ if(mode==='ai'){ q('#pf-ai-start')?.click(); } else { refreshState(); } });

      loadSpec();
      showMode('ai');
      if(pvpMatchId){ if(q('#pf-r-id')) q('#pf-r-id').value=pvpMatchId; }
    })();
    </script>
    <?php
}, 1000001000);

// ===== Prism hard cleanup lock (2026-03-09t): remove stacked legacy UI + enforce final surfaces =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-hard-cleanup-css">
      /* stronger visible idle in forge + dex */
      #pf-gallery img,
      #prism-dex-grid-11 img,
      #prism-curated-dex img,
      #gal-grid img,
      .pph-card .dex-item img{animation:pfHardIdle 1.9s steps(2,end) infinite !important; image-rendering:pixelated}
      @keyframes pfHardIdle{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
    </style>
    <script id="prism-hard-cleanup-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // remove duplicate legacy cards by title; keep only final shell sections
      const killNeedles=[
        'battle arena',
        'pvp arena',
        'prism creature partner',
        'sprite studio',
        'sprite forge (pixellab + base44)',
        'prism showdown pvp',
        'integrations',
        'showdown-style beta'
      ];

      // keep whitelist by id
      const keepIds=new Set(['pf-partner','pf-sprites','pf-pvp','pf-pvp-real-wrap','prism-curated-dex']);

      qa('.pph-card, article, section').forEach(el=>{
        if(el.id && keepIds.has(el.id)) return;
        const t=(q('h2,h3,h4',el)?.textContent||'').toLowerCase().trim();
        if(!t) return;
        if(killNeedles.some(n=>t.includes(n))){
          // do not remove final wrappers
          if(el.closest('#prism-final-shell')){
            const parentId = el.id || '';
            if(!keepIds.has(parentId)) el.remove();
          } else {
            el.remove();
          }
        }
      });

      // hard-remove generic move buttons anywhere (strike/guard/charge/heal)
      qa('button').forEach(b=>{
        const t=(b.textContent||'').trim().toLowerCase();
        if(['strike','guard','charge','heal'].includes(t) || ['strike','guard','charge','heal'].includes((b.getAttribute('data-m')||'').toLowerCase())){
          b.remove();
        }
      });

      // keep exactly one showdown command box
      const realWraps=qa('#pf-pvp-real-wrap');
      realWraps.forEach((el,i)=>{ if(i>0) el.remove(); });

      // ensure move menu is visible after FIGHT press and not hidden forever
      const fightBtn=q('#pf-cmd-fight');
      if(fightBtn && !fightBtn.dataset.cleanBound){
        fightBtn.dataset.cleanBound='1';
        fightBtn.addEventListener('click',()=>{ q('#pf-move-menu')?.classList.remove('pf-hidden'); });
      }

      // ensure one dex surface
      const dexes=qa('#prism-curated-dex');
      dexes.forEach((d,i)=>{ if(i>0) d.remove(); });
    })();
    </script>
    <?php
}, 1000001100);

// ===== AI auth fallback patch (2026-03-09u): session battle when auth cookie/nonce is flaky =====
if (!function_exists('prismtek_showdown_v3_session_key')) {
    function prismtek_showdown_v3_session_key(){
        $uid=get_current_user_id();
        if($uid) return 'u'.$uid;
        $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ua=(string)($_SERVER['HTTP_USER_AGENT'] ?? 'ua');
        return 'anon_'.substr(sha1($ip.'|'.$ua.'|'.wp_salt('nonce')),0,16);
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/showdown/ai/start-open',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $uid=get_current_user_id();
            $oppSpecies=sanitize_key((string)$r->get_param('oppSpecies')); if($oppSpecies==='') $oppSpecies='volt';

            if(function_exists('prismtek_showdown_v3_build_fighter_from_uid') && $uid){
                $player=prismtek_showdown_v3_build_fighter_from_uid($uid,50);
            } else {
                // anonymous fallback fighter (keeps UX alive)
                $species='sprout';
                $types=function_exists('prismtek_showdown_v3_types_for_species')?prismtek_showdown_v3_types_for_species($species):['nature','fairy'];
                $stats=function_exists('prismtek_showdown_v3_default_species_stats')?prismtek_showdown_v3_default_species_stats($species):['hp'=>90,'atk'=>80,'def'=>80,'spa'=>85,'spd'=>85,'spe'=>80];
                $moves=['vine_arc','bloom_pulse','pixie_shell','sap_recover'];
                $hp=(int)$stats['hp']+50;
                $player=['uid'=>0,'name'=>'You','species'=>$species,'types'=>$types,'stats'=>$stats,'moves'=>$moves,'level'=>50,'hp'=>$hp,'maxHp'=>$hp,'status'=>null,'fainted'=>false,'stages'=>['atk'=>0,'def'=>0,'spa'=>0,'spd'=>0,'spe'=>0,'acc'=>0,'eva'=>0]];
            }

            $aiStats=function_exists('prismtek_showdown_v3_default_species_stats')?prismtek_showdown_v3_default_species_stats($oppSpecies):['hp'=>90,'atk'=>85,'def'=>80,'spa'=>85,'spd'=>80,'spe'=>85];
            $aiTypes=function_exists('prismtek_showdown_v3_types_for_species')?prismtek_showdown_v3_types_for_species($oppSpecies):['electric','steel'];
            $lib=function_exists('prismtek_showdown_v3_move_library')?prismtek_showdown_v3_move_library():[];
            $moveKeys=!empty($lib)?array_keys($lib):['vine_arc','bloom_pulse','pixie_shell','sap_recover'];
            if(function_exists('prismtek_showdown_v3_seeded_pick')) $aiMoves=array_slice(prismtek_showdown_v3_seeded_pick($moveKeys,'open_ai|'.$oppSpecies,4),0,4);
            else $aiMoves=array_slice($moveKeys,0,4);
            $aiHp=(int)$aiStats['hp']+50;
            $opp=['uid'=>-1,'name'=>'AI '.$oppSpecies,'species'=>$oppSpecies,'types'=>$aiTypes,'stats'=>$aiStats,'moves'=>$aiMoves,'level'=>50,'hp'=>$aiHp,'maxHp'=>$aiHp,'status'=>null,'fainted'=>false,'stages'=>['atk'=>0,'def'=>0,'spa'=>0,'spd'=>0,'spe'=>0,'acc'=>0,'eva'=>0]];

            $id='aiopen_'.wp_generate_password(10,false,false);
            $state=['id'=>$id,'turn'=>1,'over'=>false,'winner'=>'','player'=>$player,'opponent'=>$opp,'log'=>['AI battle started.']];
            update_option('prismtek_showdown_ai_open_'.prismtek_showdown_v3_session_key(),$state,false);
            return rest_ensure_response(['ok'=>true,'state'=>$state,'fallback'=>!$uid]);
        }
    ]);

    register_rest_route('prismtek/v1','/pet/showdown/ai/move-open',[
        'methods'=>'POST','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $r){
            $move=sanitize_key((string)$r->get_param('move'));
            $k='prismtek_showdown_ai_open_'.prismtek_showdown_v3_session_key();
            $state=get_option($k,[]);
            if(!is_array($state)||empty($state['id'])) return new WP_REST_Response(['ok'=>false,'error'=>'no_active_ai_battle'],400);
            if(!in_array($move,(array)($state['player']['moves'] ?? []),true)) return new WP_REST_Response(['ok'=>false,'error'=>'invalid_move'],400);
            if(function_exists('prismtek_showdown_v3_resolve_turn')){
                prismtek_showdown_v3_resolve_turn($state,$move);
            }
            update_option($k,$state,false);
            return rest_ensure_response(['ok'=>true,'state'=>$state]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-ai-auth-fallback-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s);
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      const start=q('#pf-ai-start');
      if(start && !start.dataset.authFallbackBound){
        start.dataset.authFallbackBound='1';
        start.addEventListener('click', async (e)=>{
          // let existing handler run first; fallback only if auth_required detected
          setTimeout(async ()=>{
            const status=(q('#pf-pvp-status')?.textContent||'').toLowerCase();
            if(!status.includes('auth_required') && !status.includes('ai start failed')) return;
            const opp=(q('#pf-ai-species')?.value||'volt').trim();
            const out=await post('pet/showdown/ai/start-open',{oppSpecies:opp});
            if(!out.ok||!out.j?.ok) return;
            const st=out.j.state||{};
            const log=q('#pf-pvp-log'); if(log) log.textContent=(st.log||[]).join('\n');
            const moves=st.player?.moves||[];
            const menu=q('#pf-move-menu');
            if(menu && moves.length){
              menu.innerHTML='';
              moves.slice(0,4).forEach(mid=>{
                const b=document.createElement('button');
                b.dataset.mid=mid;
                b.textContent=mid.replace(/_/g,' ');
                b.addEventListener('click', async ()=>{
                  const mo=await post('pet/showdown/ai/move-open',{move:mid});
                  if(!mo.ok||!mo.j?.ok) return;
                  const s2=mo.j.state||{};
                  if(log) log.textContent=(s2.log||[]).join('\n');
                });
                menu.appendChild(b);
              });
              menu.classList.remove('pf-hidden');
            }
            const stEl=q('#pf-pvp-status'); if(stEl) stEl.textContent='AI battle started (session fallback mode).';
          }, 450);
        }, true);
      }
    })();
    </script>
    <?php
}, 1000001200);

// ===== Battle Window XL rescue (2026-03-09v): large functional showdown panel =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-battle-xl-css">
      #pf-pvp-xl{border:2px solid #6f7ce0;background:linear-gradient(180deg,#101742,#090f2b);padding:12px;margin-top:10px}
      #pf-pvp-xl .top{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
      #pf-pvp-xl input,#pf-pvp-xl select,#pf-pvp-xl button{background:#0e1439;border:1px solid #5b67ce;color:#eef3ff;padding:9px}
      #pf-pvp-xl button{font-weight:800}
      #pf-xl-scene{margin-top:10px;position:relative;min-height:420px;border:2px solid #5f6ad1;background:linear-gradient(180deg,#7dbdff 0%,#87d7ff 44%,#73bf72 45%,#699f68 100%);overflow:hidden}
      #pf-xl-scene .plat{position:absolute;width:170px;height:38px;border-radius:50%;background:rgba(30,42,27,.36);border:2px solid rgba(18,28,16,.42)}
      #pf-xl-scene .opp-plat{top:76px;right:72px}
      #pf-xl-scene .you-plat{bottom:66px;left:88px}
      #pf-xl-you,#pf-xl-opp{position:absolute;image-rendering:pixelated;object-fit:contain;filter:drop-shadow(0 3px 0 rgba(0,0,0,.35))}
      #pf-xl-opp{top:20px;right:90px;width:190px;height:190px;transform:scaleX(-1)}
      #pf-xl-you{bottom:18px;left:96px;width:220px;height:220px}
      .pf-xl-hp{position:absolute;min-width:290px;max-width:42%;background:#f9faff;color:#141735;border:2px solid #222854;padding:10px}
      .pf-xl-hp .nm{font-weight:900;font-size:15px}
      .pf-xl-hp .meta{font-size:12px;opacity:.85}
      .pf-xl-hp .bar{margin-top:7px;height:10px;border:1px solid #3a458d;background:#dbe1ff}
      .pf-xl-hp .bar>span{display:block;height:100%;width:100%;background:#4ad67f;transition:width .35s ease}
      #pf-xl-youbox{right:18px;bottom:20px}
      #pf-xl-oppbox{left:18px;top:20px}
      #pf-xl-turn{margin-top:10px;background:#f8f9ff;color:#141933;border:2px solid #1f2552;padding:10px;font-weight:800;min-height:42px}
      #pf-xl-menu{margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #pf-xl-menu button{text-align:left}
      #pf-xl-log{margin-top:8px;max-height:220px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px;white-space:pre-wrap;font-size:12px}
      @media (max-width:980px){#pf-pvp-xl .top{grid-template-columns:1fr 1fr}#pf-xl-scene{min-height:320px}.pf-xl-hp{max-width:68%;min-width:200px}#pf-xl-opp{width:132px;height:132px;right:34px}#pf-xl-you{width:160px;height:160px;left:34px}}
      @media (max-width:760px){#pf-pvp-xl .top,#pf-xl-menu{grid-template-columns:1fr}}
    </style>
    <script id="prism-battle-xl-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const root=q('#pf-pvp'); if(!root) return;

      // hide smaller legacy wrappers inside pf-pvp
      qa('#pf-pvp-real-wrap,#pf-pvp-scene,#pf-pvp-log,.pf-grid3,.pf-grid4',root).forEach(el=>el.classList.add('pf-hidden'));

      let xl=q('#pf-pvp-xl',root);
      if(!xl){
        xl=document.createElement('div');
        xl.id='pf-pvp-xl';
        xl.innerHTML=''
          +'<div class="top">'
          +' <button id="pf-xl-mode-ai">AI Mode</button>'
          +' <button id="pf-xl-mode-pvp">PvP Mode</button>'
          +' <button id="pf-xl-refresh">Refresh</button>'
          +' <input id="pf-xl-opp-user" placeholder="Opponent username">'
          +' <input id="pf-xl-match" placeholder="Match ID">'
          +' <button id="pf-xl-chal">Challenge</button>'
          +' <button id="pf-xl-accept">Accept</button>'
          +' <select id="pf-xl-ai-species"><option value="volt">Voltigon</option><option value="ember">Pyronyx</option><option value="tidal">Aqualume</option><option value="shade">Noctivyre</option><option value="sprout">Spriglit</option></select>'
          +' <button id="pf-xl-start-ai">Start AI Match</button>'
          +'</div>'
          +'<div id="pf-xl-scene">'
          +' <div class="plat opp-plat"></div><div class="plat you-plat"></div>'
          +' <img id="pf-xl-opp" alt="Opponent"><img id="pf-xl-you" alt="You">'
          +' <div class="pf-xl-hp" id="pf-xl-oppbox"><div class="nm" id="pf-xl-opp-nm">Opponent</div><div class="meta" id="pf-xl-opp-meta">HP 0/0</div><div class="bar"><span id="pf-xl-opp-hp"></span></div></div>'
          +' <div class="pf-xl-hp" id="pf-xl-youbox"><div class="nm" id="pf-xl-you-nm">You</div><div class="meta" id="pf-xl-you-meta">HP 0/0</div><div class="bar"><span id="pf-xl-you-hp"></span></div></div>'
          +'</div>'
          +'<div id="pf-xl-turn">Choose mode and start a match.</div>'
          +'<div id="pf-xl-menu"></div>'
          +'<pre id="pf-xl-log">No match loaded.</pre>';
        root.appendChild(xl);
      }

      let mode='ai';
      let moveLib={};
      let myMoves=[];
      let pvpId=localStorage.getItem('prism_showdown_match_v3')||'';
      let aiFallback=false;
      if(pvpId) q('#pf-xl-match').value=pvpId;

      const pretty={sprout:'Spriglit',ember:'Pyronyx',tidal:'Aqualume',volt:'Voltigon',shade:'Noctivyre'};
      const setTurn=t=>{ q('#pf-xl-turn').textContent=t||''; const s=q('#pf-pvp-status'); if(s) s.textContent=t||''; };
      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      function stageUrl(species){
        const imgs=qa('#pf-gallery .item');
        const s=String(species||'sprout').toLowerCase();
        let hit='';
        imgs.forEach(it=>{
          if(hit) return;
          const sp=(it.dataset.species||'').toLowerCase(); const st=(it.dataset.stage||'').toLowerCase();
          if(sp===s && st==='adult') hit=it.dataset.url||'';
        });
        if(!hit){
          imgs.forEach(it=>{ if(hit) return; const sp=(it.dataset.species||'').toLowerCase(); const st=(it.dataset.stage||'').toLowerCase(); if(sp===s && st==='baby') hit=it.dataset.url||''; });
        }
        return hit||q('#pf-hero-img')?.src||'';
      }

      function renderMoveMenu(){
        const menu=q('#pf-xl-menu');
        menu.innerHTML='';
        menu.style.display='grid';
        menu.style.gridTemplateColumns='1fr 1fr';
        menu.style.gap='8px';
        myMoves.slice(0,4).forEach(mid=>{
          const m=moveLib[mid]||{};
          const b=document.createElement('button');
          b.className='pf-btn';
          b.innerHTML='<strong>'+(m.name||mid.replace(/_/g,' '))+'</strong><br><small>'+String(m.type||'').toUpperCase()+' · '+String(m.category||'').toUpperCase()+' · Pow '+(m.power||0)+' · Acc '+(m.accuracy||100)+'%</small>';
          b.addEventListener('click',()=>doMove(mid));
          menu.appendChild(b);
        });
      }

      function renderState(st){
        if(!st) return;
        const you=st.participants?.you||{}, opp=st.participants?.opp||{};
        const yh=Number(st.hp?.you||0), ym=Math.max(1,Number(st.maxHp?.you||1));
        const oh=Number(st.hp?.opp||0), om=Math.max(1,Number(st.maxHp?.opp||1));
        q('#pf-xl-you-nm').textContent=you.name||'You';
        q('#pf-xl-opp-nm').textContent=opp.name||'Opponent';
        q('#pf-xl-you-meta').textContent='HP '+yh+'/'+ym+' · '+(pretty[String(you.species||'').toLowerCase()]||you.species||'Creature');
        q('#pf-xl-opp-meta').textContent='HP '+oh+'/'+om+' · '+(pretty[String(opp.species||'').toLowerCase()]||opp.species||'Creature');
        q('#pf-xl-you-hp').style.width=((yh/ym)*100).toFixed(1)+'%';
        q('#pf-xl-opp-hp').style.width=((oh/om)*100).toFixed(1)+'%';
        const yu=stageUrl(you.species), op=stageUrl(opp.species);
        if(yu) q('#pf-xl-you').src=yu+(yu.includes('?')?'&':'?')+'v='+Date.now();
        if(op) q('#pf-xl-opp').src=op+(op.includes('?')?'&':'?')+'v='+Date.now();
        q('#pf-xl-log').textContent=(st.log||[]).join('\n')||'No logs yet.';
        myMoves=Array.isArray(st.moves)?st.moves:myMoves;
        renderMoveMenu();
        if(st.done) setTurn(st.winner==='draw'?'Battle ended: Draw.':'Battle ended.');
        else setTurn((st.log||[]).slice(-1)[0]||'Choose your move.');
      }

      function normAI(st){
        const p=st.player||{}, o=st.opponent||{};
        return {done:!!st.over,winner:st.winner||'',log:st.log||[],participants:{you:{name:p.name||'You',species:p.species||'sprout'},opp:{name:o.name||'AI',species:o.species||'volt'}},hp:{you:Number(p.hp||0),opp:Number(o.hp||0)},maxHp:{you:Number(p.maxHp||1),opp:Number(o.maxHp||1)},moves:Array.isArray(p.moves)?p.moves:[]};
      }

      async function loadSpec(){
        const o=await get('pet/showdown/spec');
        if(o.ok&&o.j?.ok){ moveLib=o.j.moveLibrary||{}; myMoves=o.j.kit?.moves||myMoves; renderMoveMenu(); }
      }

      async function startAI(){
        const sp=(q('#pf-xl-ai-species').value||'volt').trim();
        setTurn('Starting AI match...');
        let out=await post('pet/showdown/ai/start',{oppSpecies:sp});
        aiFallback=false;
        if(!out.ok||!out.j?.ok){
          out=await post('pet/showdown/ai/start-open',{oppSpecies:sp});
          aiFallback=true;
        }
        if(!out.ok||!out.j?.ok){ setTurn('AI start failed: '+(out.j?.error||out.status)); return; }
        mode='ai';
        renderState(normAI(out.j.state||{}));
        setTurn(aiFallback?'AI match started (fallback mode). Choose move.':'AI match started. Choose move.');
      }

      async function doMove(mid){
        if(mode==='ai'){
          const out=aiFallback ? await post('pet/showdown/ai/move-open',{move:mid}) : await post('pet/showdown/ai/move',{move:mid});
          if(!out.ok||!out.j?.ok){ setTurn('AI move failed: '+(out.j?.error||out.status)); return; }
          renderState(normAI(out.j.state||{}));
        } else {
          if(!pvpId){ setTurn('No PvP match loaded.'); return; }
          const out=await post('pet/showdown/pvp/move',{matchId:pvpId,move:mid});
          if(!out.ok||!out.j?.ok){ setTurn('PvP move failed: '+(out.j?.error||out.status)); return; }
          renderState(out.j.state||{});
        }
      }

      async function challenge(){
        const opp=(q('#pf-xl-opp-user').value||'').trim(); if(!opp){ setTurn('Enter opponent username.'); return; }
        const out=await post('pet/showdown/pvp/challenge',{opponent:opp});
        if(!out.ok||!out.j?.ok){ setTurn('Challenge failed: '+(out.j?.error||out.status)); return; }
        pvpId=out.j.matchId||''; localStorage.setItem('prism_showdown_match_v3',pvpId); q('#pf-xl-match').value=pvpId;
        mode='pvp';
        setTurn('Challenge created. Share Match ID and wait for accept.');
      }

      async function accept(){
        const id=(q('#pf-xl-match').value||pvpId||'').trim(); if(!id){ setTurn('No match id.'); return; }
        const out=await post('pet/showdown/pvp/accept',{matchId:id});
        if(!out.ok||!out.j?.ok){ setTurn('Accept failed: '+(out.j?.error||out.status)); return; }
        pvpId=id; localStorage.setItem('prism_showdown_match_v3',pvpId); mode='pvp';
        renderState(out.j.state||{});
      }

      async function loadPvp(){
        const id=(q('#pf-xl-match').value||pvpId||'').trim(); if(!id){ setTurn('No match id.'); return; }
        const out=await get('pet/showdown/pvp/state?matchId='+encodeURIComponent(id));
        if(!out.ok||!out.j?.ok){ setTurn('Load failed: '+(out.j?.error||out.status)); return; }
        pvpId=id; localStorage.setItem('prism_showdown_match_v3',pvpId); mode='pvp';
        renderState(out.j.state||{});
      }

      q('#pf-xl-mode-ai').addEventListener('click',()=>{mode='ai';setTurn('AI mode selected. Start AI match.');});
      q('#pf-xl-mode-pvp').addEventListener('click',()=>{mode='pvp';setTurn('PvP mode selected. Challenge/accept/load.');});
      q('#pf-xl-start-ai').addEventListener('click',startAI);
      q('#pf-xl-chal').addEventListener('click',challenge);
      q('#pf-xl-accept').addEventListener('click',accept);
      q('#pf-xl-refresh').addEventListener('click',()=>{ if(mode==='pvp') loadPvp(); else setTurn('AI mode: choose a move or restart AI match.'); });

      loadSpec();
      if(pvpId) q('#pf-xl-match').value=pvpId;
    })();
    </script>
    <?php
}, 1000001300);

// ===== Arena XL isolated mount (2026-03-09w): single-source functional battle UI =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    ?>
    <style id="prism-arena-xl-isolated-css">
      #prism-arena-xl{border:2px solid #7684e8;background:linear-gradient(180deg,#101742,#090f2b);padding:12px;margin:12px 0;color:#eef3ff}
      #prism-arena-xl .row{display:grid;gap:8px;margin-top:8px}
      #prism-arena-xl .r3{grid-template-columns:1fr 1fr 1fr}
      #prism-arena-xl .r2{grid-template-columns:1fr 1fr}
      #prism-arena-xl input,#prism-arena-xl select,#prism-arena-xl button{background:#0f153a;border:1px solid #5d69cf;color:#eef3ff;padding:10px}
      #prism-arena-xl button{font-weight:800}
      #pxl-scene{position:relative;min-height:460px;border:2px solid #5f6ad1;background:linear-gradient(180deg,#81c1ff 0%,#8edcff 45%,#79c776 46%,#6ca46a 100%);overflow:hidden;margin-top:8px}
      #pxl-scene .plat{position:absolute;width:180px;height:40px;border-radius:50%;background:rgba(28,42,24,.35);border:2px solid rgba(18,28,16,.45)}
      #pxl-opp-plat{top:76px;right:76px} #pxl-you-plat{bottom:74px;left:84px}
      #pxl-you,#pxl-opp{position:absolute;image-rendering:pixelated;object-fit:contain;filter:drop-shadow(0 3px 0 rgba(0,0,0,.35))}
      #pxl-opp{top:24px;right:96px;width:200px;height:200px;transform:scaleX(-1)}
      #pxl-you{bottom:16px;left:92px;width:230px;height:230px}
      .pxl-hp{position:absolute;min-width:300px;max-width:42%;background:#f8f9ff;color:#131733;border:2px solid #222857;padding:10px}
      .pxl-hp .n{font-weight:900;font-size:16px}.pxl-hp .m{font-size:12px;opacity:.85}
      .pxl-hp .b{margin-top:7px;height:10px;border:1px solid #3a458d;background:#dbe1ff}.pxl-hp .b>span{display:block;height:100%;width:100%;background:#4ad67f;transition:width .32s ease}
      #pxl-opp-box{left:20px;top:20px} #pxl-you-box{right:20px;bottom:22px}
      #pxl-turn{margin-top:8px;background:#f8f9ff;color:#161a37;border:2px solid #1f2552;padding:10px;font-weight:800;min-height:44px}
      #pxl-menu{margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #pxl-menu button{text-align:left}
      #pxl-log{margin-top:8px;max-height:220px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px;white-space:pre-wrap;font-size:12px}
      @media (max-width:980px){#prism-arena-xl .r3,#prism-arena-xl .r2{grid-template-columns:1fr 1fr}#pxl-scene{min-height:340px}.pxl-hp{max-width:70%;min-width:200px}#pxl-opp{width:140px;height:140px;right:34px}#pxl-you{width:170px;height:170px;left:34px}}
      @media (max-width:760px){#prism-arena-xl .r3,#prism-arena-xl .r2,#pxl-menu{grid-template-columns:1fr}}
    </style>
    <script id="prism-arena-xl-isolated-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // hard-hide previous battle UIs to avoid script collision
      ['#pf-pvp','#prism-battle-v3','#prism-battle-v2-card','#prism-pvp-online','#pf-pvp-real-wrap','#pf-pvp-xl'].forEach(sel=>qa(sel).forEach(el=>el.style.display='none'));

      const host=q('#prism-final-shell')||q('.pph-wrap')||q('.entry-content')||document.body;
      if(!host) return;
      let arena=q('#prism-arena-xl');
      if(!arena){
        arena=document.createElement('article');
        arena.id='prism-arena-xl';
        arena.className='card';
        arena.innerHTML=''
          +'<h3 style="margin:0 0 6px">Prism Showdown Arena XL</h3>'
          +'<div class="row r3">'
          +' <button id="pxl-mode-ai">AI Mode</button><button id="pxl-mode-pvp">PvP Mode</button><button id="pxl-refresh">Refresh State</button>'
          +' <input id="pxl-opp-user" placeholder="Opponent username"><input id="pxl-match-id" placeholder="Match ID"><button id="pxl-challenge">Challenge</button>'
          +' <button id="pxl-accept">Accept</button><select id="pxl-ai-species"><option value="volt">Voltigon</option><option value="ember">Pyronyx</option><option value="tidal">Aqualume</option><option value="shade">Noctivyre</option><option value="sprout">Spriglit</option></select><button id="pxl-start-ai">Start AI Match</button>'
          +'</div>'
          +'<div id="pxl-scene"><div class="plat" id="pxl-opp-plat"></div><div class="plat" id="pxl-you-plat"></div><img id="pxl-opp" alt="Opponent"><img id="pxl-you" alt="You"><div class="pxl-hp" id="pxl-opp-box"><div class="n" id="pxl-opp-name">Opponent</div><div class="m" id="pxl-opp-meta">HP 0/0</div><div class="b"><span id="pxl-opp-hp"></span></div></div><div class="pxl-hp" id="pxl-you-box"><div class="n" id="pxl-you-name">You</div><div class="m" id="pxl-you-meta">HP 0/0</div><div class="b"><span id="pxl-you-hp"></span></div></div></div>'
          +'<div id="pxl-turn">Choose mode and start a match.</div>'
          +'<div id="pxl-menu"></div>'
          +'<pre id="pxl-log">No match loaded.</pre>';
        host.appendChild(arena);
      }

      let mode='ai';
      let moveLib={};
      let myMoves=[];
      let pvpId=localStorage.getItem('prism_showdown_match_v3')||'';
      let aiFallback=false;
      let galleryMap={};
      if(pvpId) q('#pxl-match-id').value=pvpId;

      const pretty={sprout:'Spriglit',ember:'Pyronyx',tidal:'Aqualume',volt:'Voltigon',shade:'Noctivyre'};
      const setTurn=t=>{ q('#pxl-turn').textContent=t||''; const s=q('#pf-pvp-status'); if(s) s.textContent=t||''; };
      async function get(path){ const r=await fetch(API+path+(path.includes('?')?'&':'?')+'ts='+Date.now(),{credentials:'include',headers:nonce?{'X-WP-Nonce':nonce}:{}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      async function loadGallery(){
        const o=await get('creatures/gallery-v2');
        if(!o.ok||!o.j?.ok) return;
        galleryMap={};
        [...(o.j.user||[]),...(o.j.official||[])].forEach(r=>{
          const k=`${String(r.species||'').toLowerCase()}::${String(r.stage||'').toLowerCase()}`;
          if(!galleryMap[k] && r.url) galleryMap[k]=r.url;
        });
      }
      function stageByHp(h,m){ const r=Number(h||0)/Math.max(1,Number(m||1)); if(r>0.66) return 'adult'; if(r>0.33) return 'teen'; return 'baby'; }
      function sprite(species,h,m){
        const s=String(species||'sprout').toLowerCase();
        const st=stageByHp(h,m);
        return galleryMap[`${s}::${st}`]||galleryMap[`${s}::baby`]||q('#pf-hero-img')?.src||'';
      }

      function renderMoveMenu(){
        const menu=q('#pxl-menu'); menu.innerHTML=''; menu.style.display='grid'; menu.style.gridTemplateColumns='1fr 1fr'; menu.style.gap='8px';
        myMoves.slice(0,4).forEach(mid=>{
          const m=moveLib[mid]||{};
          const b=document.createElement('button');
          b.innerHTML='<strong>'+(m.name||mid.replace(/_/g,' '))+'</strong><br><small>'+String(m.type||'').toUpperCase()+' · '+String(m.category||'').toUpperCase()+' · Pow '+(m.power||0)+' · Acc '+(m.accuracy||100)+'%</small>';
          b.addEventListener('click',()=>doMove(mid));
          menu.appendChild(b);
        });
      }

      function normAI(st){
        const p=st.player||{}, o=st.opponent||{};
        return {done:!!st.over,winner:st.winner||'',log:st.log||[],participants:{you:{name:p.name||'You',species:p.species||'sprout'},opp:{name:o.name||'AI',species:o.species||'volt'}},hp:{you:Number(p.hp||0),opp:Number(o.hp||0)},maxHp:{you:Number(p.maxHp||1),opp:Number(o.maxHp||1)},moves:Array.isArray(p.moves)?p.moves:[]};
      }

      function renderState(st){
        if(!st) return;
        const y=st.participants?.you||{}, o=st.participants?.opp||{};
        const yh=Number(st.hp?.you||0), ym=Math.max(1,Number(st.maxHp?.you||1));
        const oh=Number(st.hp?.opp||0), om=Math.max(1,Number(st.maxHp?.opp||1));
        q('#pxl-you-name').textContent=y.name||'You';
        q('#pxl-opp-name').textContent=o.name||'Opponent';
        q('#pxl-you-meta').textContent=`HP ${yh}/${ym} · ${pretty[String(y.species||'').toLowerCase()]||y.species||'Creature'}`;
        q('#pxl-opp-meta').textContent=`HP ${oh}/${om} · ${pretty[String(o.species||'').toLowerCase()]||o.species||'Creature'}`;
        q('#pxl-you-hp').style.width=((yh/ym)*100).toFixed(1)+'%';
        q('#pxl-opp-hp').style.width=((oh/om)*100).toFixed(1)+'%';
        const ys=sprite(y.species,yh,ym), os=sprite(o.species,oh,om);
        if(ys) q('#pxl-you').src=ys+(ys.includes('?')?'&':'?')+'v='+Date.now();
        if(os) q('#pxl-opp').src=os+(os.includes('?')?'&':'?')+'v='+Date.now();
        q('#pxl-log').textContent=(st.log||[]).join('\n')||'No logs yet.';
        myMoves=Array.isArray(st.moves)?st.moves:myMoves;
        renderMoveMenu();
        if(st.done){
          if(st.winner==='draw') setTurn('Battle ended: Draw.');
          else setTurn('Battle ended.');
        } else setTurn((st.log||[]).slice(-1)[0]||'Choose your move.');
      }

      async function loadSpec(){
        const o=await get('pet/showdown/spec');
        if(o.ok&&o.j?.ok){ moveLib=o.j.moveLibrary||{}; if(Array.isArray(o.j.kit?.moves)) myMoves=o.j.kit.moves.slice(0,4); renderMoveMenu(); }
      }

      async function startAI(){
        const sp=(q('#pxl-ai-species').value||'volt').trim();
        setTurn('Starting AI match...');
        let o=await post('pet/showdown/ai/start',{oppSpecies:sp});
        aiFallback=false;
        if(!o.ok||!o.j?.ok){ o=await post('pet/showdown/ai/start-open',{oppSpecies:sp}); aiFallback=true; }
        if(!o.ok||!o.j?.ok){ setTurn('AI start failed: '+(o.j?.error||o.status)); return; }
        mode='ai';
        renderState(normAI(o.j.state||{}));
        setTurn(aiFallback?'AI match started (fallback). Choose move.':'AI match started. Choose move.');
      }

      async function doMove(mid){
        if(mode==='ai'){
          const o=aiFallback?await post('pet/showdown/ai/move-open',{move:mid}):await post('pet/showdown/ai/move',{move:mid});
          if(!o.ok||!o.j?.ok){ setTurn('AI move failed: '+(o.j?.error||o.status)); return; }
          renderState(normAI(o.j.state||{}));
        } else {
          if(!pvpId){ setTurn('No PvP match loaded.'); return; }
          const o=await post('pet/showdown/pvp/move',{matchId:pvpId,move:mid});
          if(!o.ok||!o.j?.ok){ setTurn('PvP move failed: '+(o.j?.error||o.status)); return; }
          renderState(o.j.state||{});
        }
      }

      async function challenge(){
        const opp=(q('#pxl-opp-user').value||'').trim(); if(!opp){ setTurn('Enter opponent username.'); return; }
        const o=await post('pet/showdown/pvp/challenge',{opponent:opp});
        if(!o.ok||!o.j?.ok){ setTurn('Challenge failed: '+(o.j?.error||o.status)); return; }
        pvpId=o.j.matchId||''; localStorage.setItem('prism_showdown_match_v3',pvpId); q('#pxl-match-id').value=pvpId; mode='pvp';
        setTurn('Challenge created. Share Match ID.');
      }
      async function accept(){
        const id=(q('#pxl-match-id').value||pvpId||'').trim(); if(!id){ setTurn('No match id.'); return; }
        const o=await post('pet/showdown/pvp/accept',{matchId:id});
        if(!o.ok||!o.j?.ok){ setTurn('Accept failed: '+(o.j?.error||o.status)); return; }
        pvpId=id; localStorage.setItem('prism_showdown_match_v3',pvpId); mode='pvp';
        renderState(o.j.state||{});
      }
      async function loadPvp(){
        const id=(q('#pxl-match-id').value||pvpId||'').trim(); if(!id){ setTurn('No match id.'); return; }
        const o=await get('pet/showdown/pvp/state?matchId='+encodeURIComponent(id));
        if(!o.ok||!o.j?.ok){ setTurn('Load failed: '+(o.j?.error||o.status)); return; }
        pvpId=id; localStorage.setItem('prism_showdown_match_v3',pvpId); mode='pvp';
        renderState(o.j.state||{});
      }

      q('#pxl-mode-ai').addEventListener('click',()=>{mode='ai';setTurn('AI mode selected.');});
      q('#pxl-mode-pvp').addEventListener('click',()=>{mode='pvp';setTurn('PvP mode selected.');});
      q('#pxl-refresh').addEventListener('click',()=>{ if(mode==='pvp') loadPvp(); else setTurn('AI mode active.'); });
      q('#pxl-start-ai').addEventListener('click',startAI);
      q('#pxl-challenge').addEventListener('click',challenge);
      q('#pxl-accept').addEventListener('click',accept);

      Promise.all([loadGallery(),loadSpec()]).then(()=>{ if(pvpId) q('#pxl-match-id').value=pvpId; setTurn('Arena XL ready.'); });
    })();
    </script>
    <?php
}, 1000001400);

// ===== My Account integrations tab fix (2026-03-09x): proper tab layout + non-forbidden agent access =====
if (!function_exists('prismtek_agent2_allowed')) {
    function prismtek_agent2_allowed(){
        if(!is_user_logged_in()) return false;
        $u = wp_get_current_user();
        if(!$u || !$u->exists()) return false;
        $login = strtolower((string)$u->user_login);
        if(current_user_can('manage_options')) return true;
        if($login === 'prismtek') return true;
        return false;
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/agent3/status',[
        'methods'=>'GET',
        'permission_callback'=>function(){ return prismtek_agent2_allowed(); },
        'callback'=>function(){
            $up = wp_remote_get('http://127.0.0.1:11434/api/tags',['timeout'=>3]);
            $ollamaUp = !is_wp_error($up) && ((int)wp_remote_retrieve_response_code($up) >= 200) && ((int)wp_remote_retrieve_response_code($up) < 500);
            $u = wp_get_current_user();
            return rest_ensure_response(['ok'=>true,'user'=>$u?(string)$u->user_login:'','ollamaUp'=>$ollamaUp]);
        }
    ]);

    register_rest_route('prismtek/v1','/agent3/chat',[
        'methods'=>'POST',
        'permission_callback'=>function(){ return prismtek_agent2_allowed(); },
        'callback'=>function(WP_REST_Request $r){
            if(!function_exists('prismtek_agent_ollama_chat')) return new WP_REST_Response(['ok'=>false,'error'=>'agent_unavailable'],500);
            $msg=sanitize_textarea_field((string)$r->get_param('message'));
            $model=sanitize_text_field((string)$r->get_param('model'));
            $auto=(bool)$r->get_param('autoApply');
            if($msg==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_message'],400);
            $out=prismtek_agent_ollama_chat($msg,$model?:'qwen2.5:3b');
            if(empty($out['ok'])) return new WP_REST_Response(['ok'=>false,'error'=>$out['error']??'agent_failed','detail'=>$out['detail']??null],502);
            $data=$out['data'];
            $applied=[];
            if($auto && !empty($data['actions']) && function_exists('prismtek_agent_exec_actions')) $applied=prismtek_agent_exec_actions($data['actions']);
            return rest_ensure_response(['ok'=>true,'reply'=>$data['reply'],'actions'=>$data['actions'],'applied'=>$applied]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    if (!is_user_logged_in()) return;
    $nonce=wp_create_nonce('wp_rest');
    ?>
    <style id="prism-account-tabs-fix-css">
      #prism-account-tools{border:1px solid #5f6ad1;background:linear-gradient(180deg,#101741,#0b1030);padding:12px;color:#eef3ff;margin-top:12px}
      #prism-account-tools .tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      #prism-account-tools .tabs button{background:#111843;border:1px solid #5d68cf;color:#eef3ff;padding:10px;font-weight:800}
      #prism-account-tools .tabs button.active{background:#23317d}
      #prism-account-tools .panel{display:none;margin-top:10px;border:1px solid #5f6ad1;background:#0d1334;padding:10px}
      #prism-account-tools .panel.active{display:block}
      #prism-account-tools input,#prism-account-tools select,#prism-account-tools textarea,#prism-account-tools button.action{background:#0c1236;border:1px solid #5c67cc;color:#eef3ff;padding:8px}
      #prism-account-tools .row{display:grid;gap:8px;margin-top:8px}
      #prism-account-tools .r2{grid-template-columns:1fr 1fr}
      #prism-account-tools .r3{grid-template-columns:1fr 1fr auto}
      #prism-account-tools pre{white-space:pre-wrap;max-height:220px;overflow:auto;background:#090d26;border:1px solid #4855b8;padding:8px}
      @media (max-width:780px){#prism-account-tools .tabs,#prism-account-tools .r2,#prism-account-tools .r3{grid-template-columns:1fr}}
    </style>
    <script id="prism-account-tabs-fix-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // Hide previous misplaced integration cards.
      qa('#prism-account-integrations,#prism-integrations-card,#prism-integrations-card-v2').forEach(el=>el.remove());

      const mount=q('.woocommerce-MyAccount-content')||q('.entry-content')||document.body;
      if(!mount || q('#prism-account-tools')) return;

      const box=document.createElement('section');
      box.id='prism-account-tools';
      box.innerHTML=''
        +'<h3 style="margin:0 0 8px">Account Tools</h3>'
        +'<div class="tabs"><button id="tab-int" class="active">Base44 Integration</button><button id="tab-agent">Local Ollama Agent</button></div>'
        +'<div id="panel-int" class="panel active">'
        +'  <p style="font-size:12px;color:#dbe4ff;margin:0 0 8px">Save/remove your Base44 key to your account.</p>'
        +'  <div class="row r3"><input id="acc-b44-key" type="password" placeholder="Paste Base44 key"><button id="acc-b44-save" class="action">Save Key</button><button id="acc-b44-del" class="action">Remove</button></div>'
        +'  <p id="acc-b44-status" style="font-size:12px;color:#dbe4ff">Checking...</p>'
        +'</div>'
        +'<div id="panel-agent" class="panel">'
        +'  <p style="font-size:12px;color:#dbe4ff;margin:0 0 8px">Chat with your website-local Ollama agent.</p>'
        +'  <div class="row r3"><select id="acc-agent-model"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select><label style="display:flex;align-items:center;gap:6px"><input id="acc-agent-auto" type="checkbox"> Auto-apply</label><button id="acc-agent-check" class="action">Check</button></div>'
        +'  <textarea id="acc-agent-msg" rows="4" placeholder="Ask your local agent..."></textarea>'
        +'  <div class="row r2"><button id="acc-agent-run" class="action">Run Agent</button><a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;padding:8px;border:1px solid #5d68cf;background:#111843;color:#eef3ff;text-decoration:none">Open Full Agent Page</a></div>'
        +'  <p id="acc-agent-status" style="font-size:12px;color:#dbe4ff">Ready.</p>'
        +'  <pre id="acc-agent-json"></pre>'
        +'</div>';
      mount.prepend(box);

      function show(tab){
        q('#tab-int',box).classList.toggle('active',tab==='int');
        q('#tab-agent',box).classList.toggle('active',tab==='agent');
        q('#panel-int',box).classList.toggle('active',tab==='int');
        q('#panel-agent',box).classList.toggle('active',tab==='agent');
      }
      q('#tab-int',box).addEventListener('click',()=>show('int'));
      q('#tab-agent',box).addEventListener('click',()=>show('agent'));

      const setB=t=>{q('#acc-b44-status',box).textContent=t||''};
      const setA=t=>{q('#acc-agent-status',box).textContent=t||''};
      const out=q('#acc-agent-json',box);

      async function get(path){const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}
      async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}

      async function refreshBase44(){
        const o=await get('base44/status');
        if(!o.ok||!o.j?.ok){ setB('Status failed: '+(o.j?.error||o.status)); return; }
        setB(o.j.connected?('Connected ('+(o.j.maskedKey||'key')+')'):'Not connected.');
      }

      q('#acc-b44-save',box).addEventListener('click', async ()=>{
        const key=(q('#acc-b44-key',box).value||'').trim();
        if(!key){ setB('Paste key first.'); return; }
        setB('Saving...');
        const o=await post('base44/connect',{apiKey:key});
        if(!o.ok||!o.j?.ok){ setB('Save failed: '+(o.j?.error||o.status)); return; }
        q('#acc-b44-key',box).value='';
        setB('Connected ('+(o.j.maskedKey||'key')+')');
      });

      q('#acc-b44-del',box).addEventListener('click', async ()=>{
        setB('Removing...');
        const o=await post('base44/disconnect',{});
        setB(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status));
      });

      q('#acc-agent-check',box).addEventListener('click', async ()=>{
        setA('Checking...');
        const o=await get('agent3/status');
        if(!o.ok||!o.j?.ok){ setA('Agent unavailable: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; }
        setA('Ollama '+(o.j.ollamaUp?'online':'offline')+' as '+(o.j.user||'user'));
        out.textContent=JSON.stringify(o.j,null,2);
      });

      q('#acc-agent-run',box).addEventListener('click', async ()=>{
        const msg=(q('#acc-agent-msg',box).value||'').trim();
        if(!msg){ setA('Enter a request first.'); return; }
        setA('Running...');
        const o=await post('agent3/chat',{message:msg,model:q('#acc-agent-model',box).value,autoApply:!!q('#acc-agent-auto',box).checked});
        if(!o.ok||!o.j?.ok){ setA('Run failed: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; }
        setA(o.j.reply||'Done.');
        out.textContent=JSON.stringify(o.j,null,2);
      });

      refreshBase44();
    })();
    </script>
    <?php
}, 1000001500);

// ===== Final battle DOM replacement lock (2026-03-09y): remove old Prism Showdown PvP card and mount Arena XL in-place =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-final-battle-lock-css">
      #pf-pvp, #pf-pvp-real-wrap, #pf-pvp-xl, #prism-battle-v2-card, #prism-battle-v3, #prism-pvp-online, [id*="pvp-screen"], .pvp-m { display:none !important; }
    </style>
    <script id="prism-final-battle-lock-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      function removeLegacyBattleCards(){
        qa('.pph-card, article, section').forEach(el=>{
          const t=(q('h2,h3,h4',el)?.textContent||'').toLowerCase();
          if(!t) return;
          if(t.includes('prism showdown pvp') || t.includes('pvp arena') || t.includes('battle arena') || t.includes('showdown-style beta')){
            if(el.id==='prism-arena-xl') return;
            el.remove();
          }
        });
        qa('#pf-pvp,#pf-pvp-real-wrap,#pf-pvp-xl,#prism-battle-v2-card,#prism-battle-v3,#prism-pvp-online').forEach(el=>el.remove());
      }

      function placeArena(){
        const arena=q('#prism-arena-xl');
        if(!arena) return;
        const shell=q('#prism-final-shell')||q('.pph-wrap')||q('.entry-content');
        if(!shell) return;
        const sprite=q('#pf-sprites',shell);
        if(sprite && arena.previousElementSibling!==sprite){
          sprite.insertAdjacentElement('afterend', arena);
        } else if(!sprite && arena.parentElement!==shell){
          shell.appendChild(arena);
        }
      }

      removeLegacyBattleCards();
      placeArena();
      setTimeout(()=>{ removeLegacyBattleCards(); placeArena(); }, 500);
      setTimeout(()=>{ removeLegacyBattleCards(); placeArena(); }, 1400);
    })();
    </script>
    <?php
}, 1000001600);

// ===== Arena visual integration patch (2026-03-09z): match existing PixelLab/Prism formatting =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-arena-theme-blend-css">
      /* remove separate dark module feel; blend into existing section flow */
      #prism-arena-xl{
        border:0 !important;
        background:transparent !important;
        padding:0 !important;
        margin:8px 0 0 0 !important;
        box-shadow:none !important;
      }
      #prism-arena-xl > h3{
        margin:0 0 6px 0 !important;
        font-size:34px;
        line-height:1;
        letter-spacing:.02em;
      }

      /* match existing button/input skin from page */
      #prism-arena-xl input,
      #prism-arena-xl select,
      #prism-arena-xl button{
        background:linear-gradient(90deg,#7f6cff,#5fd3ff) !important;
        border:1px solid #4a57b8 !important;
        color:#eef3ff !important;
      }

      /* scene should feel embedded, not boxed-off */
      #pxl-scene{
        border:1px solid #5f6ad1 !important;
        border-radius:0 !important;
        box-shadow:none !important;
      }

      /* turn+log boxes align with existing info bars */
      #pxl-turn,
      #pxl-log{
        border:1px solid #4f5aba !important;
      }
    </style>
    <script id="prism-arena-theme-blend-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const arena=q('#prism-arena-xl');
      if(!arena) return;

      // position directly after Sprite Forge/Sprite Studio block for native flow
      const sprite=q('#pf-sprites') || qa('.pph-card, article, section').find(el=>((q('h2,h3,h4',el)?.textContent||'').toLowerCase().includes('sprite forge') || (q('h2,h3,h4',el)?.textContent||'').toLowerCase().includes('sprite studio')));
      if(sprite && arena.previousElementSibling!==sprite){
        sprite.insertAdjacentElement('afterend', arena);
      }

      // ensure no duplicate heading styles from legacy wrappers leak in
      arena.classList.remove('card','pph-card');
    })();
    </script>
    <?php
}, 1000001700);

// ===== UX speed + layout corrective patch (2026-03-09aa) =====
if (!function_exists('prismtek_agent_ollama_chat_fast')) {
    function prismtek_agent_ollama_chat_fast($message, $model='qwen2.5:3b'){
        $system = "You are Prismtek's fast local assistant. Return STRICT JSON: {reply:string, actions:array}. Keep reply concise. Prefer no actions unless explicitly requested.";
        $payload=[
            'model'=>$model ?: 'qwen2.5:3b',
            'stream'=>false,
            'messages'=>[
                ['role'=>'system','content'=>$system],
                ['role'=>'user','content'=>(string)$message],
            ],
            'options'=>['temperature'=>0.15,'num_predict'=>220],
        ];
        $resp=wp_remote_post('http://127.0.0.1:11434/api/chat',[
            'timeout'=>35,
            'headers'=>['Content-Type'=>'application/json'],
            'body'=>wp_json_encode($payload),
        ]);
        if(is_wp_error($resp)) return ['ok'=>false,'error'=>'ollama_unreachable'];
        $code=(int)wp_remote_retrieve_response_code($resp);
        $body=(string)wp_remote_retrieve_body($resp);
        if($code<200||$code>=300) return ['ok'=>false,'error'=>'ollama_http_'.$code,'detail'=>$body];
        $j=json_decode($body,true);
        $txt=(string)($j['message']['content'] ?? '');
        $data=json_decode($txt,true);
        if(!is_array($data)) $data=['reply'=>$txt ?: 'Done.','actions'=>[]];
        if(!isset($data['reply'])) $data['reply']='Done.';
        if(!isset($data['actions'])||!is_array($data['actions'])) $data['actions']=[];
        return ['ok'=>true,'data'=>$data,'raw'=>$txt];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/agent3/chat-fast',[
        'methods'=>'POST',
        'permission_callback'=>function(){ return function_exists('prismtek_agent2_allowed') ? prismtek_agent2_allowed() : current_user_can('manage_options'); },
        'callback'=>function(WP_REST_Request $r){
            $msg=sanitize_textarea_field((string)$r->get_param('message'));
            $model=sanitize_text_field((string)$r->get_param('model')) ?: 'qwen2.5:3b';
            if($msg==='') return new WP_REST_Response(['ok'=>false,'error'=>'missing_message'],400);
            $out=prismtek_agent_ollama_chat_fast($msg,$model);
            if(empty($out['ok'])) return new WP_REST_Response(['ok'=>false,'error'=>$out['error'] ?? 'agent_failed','detail'=>$out['detail'] ?? null],502);
            return rest_ensure_response(['ok'=>true,'reply'=>$out['data']['reply'],'actions'=>$out['data']['actions']]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-arena-debox-final-css">
      /* absolute de-boxing in case any prior card skin remains */
      #prism-arena-xl,
      #prism-arena-xl.card,
      #prism-arena-xl.pph-card,
      #prism-arena-xl.prism-premium-card{
        background:transparent !important;
        border:none !important;
        box-shadow:none !important;
        padding:0 !important;
        margin:8px 0 0 !important;
      }
      #prism-arena-xl h3{margin:0 0 6px !important}
    </style>
    <script id="prism-arena-reposition-final-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const arena=q('#prism-arena-xl');
      if(!arena) return;
      // ensure exact position after sprite section
      const sprite=q('#pf-sprites');
      if(sprite && arena.previousElementSibling!==sprite){ sprite.insertAdjacentElement('afterend', arena); }
      // remove any lingering duplicate showdown title cards
      qa('.pph-card,article,section').forEach(el=>{
        if(el===arena) return;
        const t=(q('h2,h3,h4',el)?.textContent||'').toLowerCase();
        if(t.includes('prism showdown pvp') || t.includes('prism showdown arena xl')) el.remove();
      });
    })();
    </script>
    <?php
}, 1000001800);

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    ?>
    <script id="prism-agent-fast-route-ui-hook">
    (function(){
      const q=(s,r=document)=>r.querySelector(s);
      const run=q('#acc-agent-run');
      if(!run || run.dataset.fastHook==='1') return;
      run.dataset.fastHook='1';
      const API='/wp-json/prismtek/v1/';
      const nonce=document.querySelector('meta[name="rest-nonce"]')?.content||'';
      const H=nonce?{'content-type':'application/json','X-WP-Nonce':nonce}:{'content-type':'application/json'};
      const setA=(t)=>{ const e=q('#acc-agent-status'); if(e) e.textContent=t||''; };
      const out=q('#acc-agent-json');

      run.addEventListener('click', async (e)=>{
        e.preventDefault();
        const msg=(q('#acc-agent-msg')?.value||'').trim();
        if(!msg){ setA('Enter a request first.'); return; }
        setA('Running fast mode...');
        const model=(q('#acc-agent-model')?.value||'qwen2.5:3b');
        const r=await fetch(API+'agent3/chat-fast',{method:'POST',credentials:'include',headers:H,body:JSON.stringify({message:msg,model})});
        const j=await r.json().catch(()=>({}));
        if(!r.ok||!j.ok){ setA('Fast mode failed: '+(j.error||r.status)); if(out) out.textContent=JSON.stringify(j||{},null,2); return; }
        setA(j.reply||'Done.'); if(out) out.textContent=JSON.stringify(j,null,2);
      }, true);
    })();
    </script>
    <?php
}, 1000001801);

// ===== My Account layout polish (2026-03-09ab): remove dark box + native account styling =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    ?>
    <style id="prism-account-native-style">
      #prism-account-tools{
        background:transparent !important;
        border:none !important;
        box-shadow:none !important;
        padding:0 !important;
        margin:10px 0 0 !important;
        color:inherit !important;
      }
      #prism-account-tools > h3{margin:0 0 10px !important}
      #prism-account-tools .tabs{display:flex !important;gap:8px;flex-wrap:wrap}
      #prism-account-tools .tabs button{
        background:#f4f6ff !important;
        color:#1a1f3d !important;
        border:1px solid #d3daf7 !important;
        border-radius:8px !important;
        padding:8px 12px !important;
      }
      #prism-account-tools .tabs button.active{
        background:#5f6ad1 !important;
        color:#fff !important;
        border-color:#5f6ad1 !important;
      }
      #prism-account-tools .panel{
        background:#fff !important;
        color:#1b2242 !important;
        border:1px solid #e2e7ff !important;
        border-radius:10px !important;
        padding:12px !important;
        margin-top:10px !important;
      }
      #prism-account-tools input,
      #prism-account-tools select,
      #prism-account-tools textarea{
        background:#fff !important;
        color:#1b2242 !important;
        border:1px solid #cfd8ff !important;
        border-radius:8px !important;
      }
      #prism-account-tools button.action{
        background:#5f6ad1 !important;
        color:#fff !important;
        border:1px solid #4f5fc2 !important;
        border-radius:8px !important;
      }
      #prism-account-tools pre{
        background:#f8faff !important;
        color:#1b2242 !important;
        border:1px solid #dbe3ff !important;
        border-radius:8px !important;
      }
    </style>
    <script id="prism-account-native-layout-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s);
      const box=q('#prism-account-tools');
      if(!box) return;

      // place in native My Account content flow after first heading/intro block if present
      const content=q('.woocommerce-MyAccount-content')||q('.entry-content');
      if(content && box.parentElement!==content) content.appendChild(box);
    })();
    </script>
    <?php
}, 1000001900);

// ===== My Account key grouping fix (2026-03-09ac): PixelLab + Base44 together =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    if (!is_user_logged_in()) return;
    $nonce=wp_create_nonce('wp_rest');
    ?>
    <script id="prism-account-key-grouping-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s);

      // remove previous account tool variants
      document.querySelectorAll('#prism-account-tools,#prism-account-integrations,#prism-integrations-card,#prism-integrations-card-v2').forEach(el=>el.remove());

      const mount=q('.woocommerce-MyAccount-content')||q('.entry-content')||document.body;
      if(!mount || q('#prism-account-keys-tools')) return;

      const box=document.createElement('section');
      box.id='prism-account-keys-tools';
      box.style.cssText='margin-top:10px';
      box.innerHTML=''
        +'<h3 style="margin:0 0 10px">Account Tools</h3>'
        +'<div style="display:flex;gap:8px;flex-wrap:wrap">'
        +'  <button id="kat-tab-keys" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px 12px">API Keys (PixelLab + Base44)</button>'
        +'  <button id="kat-tab-agent" style="background:#f4f6ff;color:#1a1f3d;border:1px solid #d3daf7;border-radius:8px;padding:8px 12px">Local Ollama Agent</button>'
        +'</div>'
        +'<div id="kat-panel-keys" style="margin-top:10px;background:#fff;color:#1b2242;border:1px solid #e2e7ff;border-radius:10px;padding:12px">'
        +'  <h4 style="margin:0 0 6px">PixelLab Key</h4>'
        +'  <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px"><input id="kat-pl-key" type="password" placeholder="Paste PixelLab API key" style="background:#fff;color:#1b2242;border:1px solid #cfd8ff;border-radius:8px;padding:8px"><button id="kat-pl-save" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Save</button><button id="kat-pl-del" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Remove</button></div>'
        +'  <p id="kat-pl-status" style="font-size:12px;color:#3a4266">Checking...</p>'
        +'  <h4 style="margin:10px 0 6px">Base44 Key</h4>'
        +'  <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px"><input id="kat-b44-key" type="password" placeholder="Paste Base44 API key" style="background:#fff;color:#1b2242;border:1px solid #cfd8ff;border-radius:8px;padding:8px"><button id="kat-b44-save" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Save</button><button id="kat-b44-del" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Remove</button></div>'
        +'  <p id="kat-b44-status" style="font-size:12px;color:#3a4266">Checking...</p>'
        +'</div>'
        +'<div id="kat-panel-agent" style="display:none;margin-top:10px;background:#fff;color:#1b2242;border:1px solid #e2e7ff;border-radius:10px;padding:12px">'
        +'  <h4 style="margin:0 0 6px">Local Ollama Agent</h4>'
        +'  <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px"><select id="kat-agent-model" style="background:#fff;color:#1b2242;border:1px solid #cfd8ff;border-radius:8px;padding:8px"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select><label style="display:flex;align-items:center;gap:6px"><input id="kat-agent-auto" type="checkbox"> Auto-apply</label><button id="kat-agent-check" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Check</button></div>'
        +'  <textarea id="kat-agent-msg" rows="4" placeholder="Ask your local agent..." style="width:100%;margin-top:8px;background:#fff;color:#1b2242;border:1px solid #cfd8ff;border-radius:8px;padding:8px"></textarea>'
        +'  <div style="display:grid;grid-template-columns:auto auto;gap:8px;margin-top:8px"><button id="kat-agent-run" style="background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px">Run Agent</button><a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px;text-decoration:none">Open Full Agent Page</a></div>'
        +'  <p id="kat-agent-status" style="font-size:12px;color:#3a4266">Ready.</p>'
        +'  <pre id="kat-agent-json" style="white-space:pre-wrap;max-height:220px;overflow:auto;background:#f8faff;color:#1b2242;border:1px solid #dbe3ff;border-radius:8px;padding:8px"></pre>'
        +'</div>';
      mount.prepend(box);

      const tabKeys=q('#kat-tab-keys',box), tabAgent=q('#kat-tab-agent',box), pKeys=q('#kat-panel-keys',box), pAgent=q('#kat-panel-agent',box);
      const setTab=(k)=>{
        const keys=k==='keys';
        pKeys.style.display=keys?'block':'none'; pAgent.style.display=keys?'none':'block';
        tabKeys.style.background=keys?'#5f6ad1':'#f4f6ff'; tabKeys.style.color=keys?'#fff':'#1a1f3d';
        tabAgent.style.background=keys?'#f4f6ff':'#5f6ad1'; tabAgent.style.color=keys?'#1a1f3d':'#fff';
      };
      tabKeys.addEventListener('click',()=>setTab('keys'));
      tabAgent.addEventListener('click',()=>setTab('agent'));

      const setPL=t=>q('#kat-pl-status',box).textContent=t||'';
      const setB=t=>q('#kat-b44-status',box).textContent=t||'';
      const setA=t=>q('#kat-agent-status',box).textContent=t||'';
      const out=q('#kat-agent-json',box);

      async function get(path){const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}
      async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}

      async function refreshStatuses(){
        const pl=await get('pixellab/status');
        setPL(pl.ok&&pl.j?.ok ? (pl.j.connected?('Connected ('+(pl.j.maskedKey||'key')+')'):'Not connected.') : ('Status failed: '+(pl.j?.error||pl.status)));
        const b=await get('base44/status');
        setB(b.ok&&b.j?.ok ? (b.j.connected?('Connected ('+(b.j.maskedKey||'key')+')'):'Not connected.') : ('Status failed: '+(b.j?.error||b.status)));
      }

      q('#kat-pl-save',box).addEventListener('click', async ()=>{ const key=(q('#kat-pl-key',box).value||'').trim(); if(!key){setPL('Paste key first.'); return;} setPL('Saving...'); const o=await post('pixellab/connect',{apiKey:key,acceptedUsageRules:true}); if(!o.ok||!o.j?.ok){setPL('Save failed: '+(o.j?.error||o.status)); return;} q('#kat-pl-key',box).value=''; setPL('Connected ('+(o.j.maskedKey||'key')+')'); });
      q('#kat-pl-del',box).addEventListener('click', async ()=>{ setPL('Removing...'); const o=await post('pixellab/disconnect',{}); setPL(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status)); });

      q('#kat-b44-save',box).addEventListener('click', async ()=>{ const key=(q('#kat-b44-key',box).value||'').trim(); if(!key){setB('Paste key first.'); return;} setB('Saving...'); const o=await post('base44/connect',{apiKey:key}); if(!o.ok||!o.j?.ok){setB('Save failed: '+(o.j?.error||o.status)); return;} q('#kat-b44-key',box).value=''; setB('Connected ('+(o.j.maskedKey||'key')+')'); });
      q('#kat-b44-del',box).addEventListener('click', async ()=>{ setB('Removing...'); const o=await post('base44/disconnect',{}); setB(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status)); });

      q('#kat-agent-check',box).addEventListener('click', async ()=>{ setA('Checking...'); const o=await get('agent3/status'); if(!o.ok||!o.j?.ok){ setA('Agent unavailable: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return;} setA('Ollama '+(o.j.ollamaUp?'online':'offline')+' as '+(o.j.user||'user')); out.textContent=JSON.stringify(o.j,null,2); });
      q('#kat-agent-run',box).addEventListener('click', async ()=>{ const msg=(q('#kat-agent-msg',box).value||'').trim(); if(!msg){ setA('Enter a request first.'); return; } setA('Running fast mode...'); const o=await post('agent3/chat-fast',{message:msg,model:q('#kat-agent-model',box).value,autoApply:!!q('#kat-agent-auto',box).checked}); if(!o.ok||!o.j?.ok){ setA('Run failed: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return; } setA(o.j.reply||'Done.'); out.textContent=JSON.stringify(o.j,null,2); });

      refreshStatuses();
      setTab('keys');
    })();
    </script>
    <?php
}, 1000002000);

// ===== My Account readability/order pass (2026-03-09ad): simplify layout + restore legible links block =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    ?>
    <style id="prism-account-readability-pass-css">
      #prism-account-keys-tools{max-width:980px;margin:12px 0 0 !important}
      #prism-account-keys-tools h3{font-size:22px;line-height:1.2}
      #prism-account-keys-tools h4{font-size:16px;line-height:1.25}
      #prism-account-keys-tools p{line-height:1.45}
      #prism-account-keys-tools .compact-note{font-size:12px;color:#4b557d;margin:0 0 8px}
      #prism-account-keys-tools .grid-3{display:grid;grid-template-columns:1fr auto auto;gap:8px}
      #prism-account-keys-tools .grid-3 input{min-width:0}
      #prism-account-links{
        margin-top:10px;
        max-width:980px;
        background:#f8faff;
        border:1px solid #dbe3ff;
        border-radius:10px;
        padding:10px;
        color:#1b2242;
        font-size:13px;
      }
      #prism-account-links a{color:#3048b7;text-decoration:none}
      #prism-account-links a:hover{text-decoration:underline}
      @media (max-width:780px){#prism-account-keys-tools .grid-3{grid-template-columns:1fr}}
    </style>
    <script id="prism-account-readability-pass-js">
    (function(){
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const mount=q('.woocommerce-MyAccount-content')||q('.entry-content')||document.body;
      const box=q('#prism-account-keys-tools');
      if(!mount || !box) return;

      // remove older leftover account integration blocks to reduce clutter/order issues
      qa('#prism-account-tools,#prism-account-integrations,#prism-integrations-card,#prism-integrations-card-v2').forEach(el=>el.remove());

      // ensure tabs block is near top of account content
      if(box.parentElement!==mount || mount.firstElementChild!==box){
        mount.prepend(box);
      }

      // tighten copy in keys panel for readability
      const pKeys=q('#kat-panel-keys',box);
      if(pKeys && !q('.compact-note',pKeys)){
        const n=document.createElement('p');
        n.className='compact-note';
        n.textContent='Save your PixelLab and Base44 keys here. Agent controls are in the separate Local Ollama Agent tab.';
        pKeys.prepend(n);
      }

      // normalize row classes for responsive readability
      const plRow=q('#kat-pl-key')?.parentElement;
      const bRow=q('#kat-b44-key')?.parentElement;
      if(plRow) plRow.classList.add('grid-3');
      if(bRow) bRow.classList.add('grid-3');

      // restore old-style legible resource links block at bottom (requested)
      let links=q('#prism-account-links');
      if(!links){
        links=document.createElement('div');
        links.id='prism-account-links';
        links.innerHTML=''
          +'<strong>Integration Links</strong><br>'
          +'<a href="https://www.pixellab.ai/" target="_blank" rel="noopener">PixelLab account</a> · '
          +'<a href="https://www.pixellab.ai/pixellab-api" target="_blank" rel="noopener">PixelLab API docs</a> · '
          +'<a href="https://www.pixellab.ai/mcp" target="_blank" rel="noopener">PixelLab MCP docs</a> · '
          +'<a href="https://base44.com" target="_blank" rel="noopener">Base44</a>';
        mount.appendChild(links);
      }
    })();
    </script>
    <?php
}, 1000002100);

// ===== My Account Control Center reset (2026-03-09ae): one clean block only =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style id="prism-account-control-center-css">
      #prism-account-control-center{max-width:980px;margin:14px 0 0}
      #prism-account-control-center .cc-card{background:#fff;color:#1b2242;border:1px solid #e2e7ff;border-radius:12px;padding:12px}
      #prism-account-control-center .cc-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
      #prism-account-control-center .cc-tabs button{background:#f4f6ff;color:#1a1f3d;border:1px solid #d3daf7;border-radius:8px;padding:8px 12px;font-weight:700}
      #prism-account-control-center .cc-tabs button.active{background:#5f6ad1;color:#fff;border-color:#5f6ad1}
      #prism-account-control-center .cc-panel{display:none}
      #prism-account-control-center .cc-panel.active{display:block}
      #prism-account-control-center .row{display:grid;gap:8px;margin-top:8px}
      #prism-account-control-center .r3{grid-template-columns:1fr auto auto}
      #prism-account-control-center .r2{grid-template-columns:1fr 1fr}
      #prism-account-control-center input,#prism-account-control-center select,#prism-account-control-center textarea{background:#fff;color:#1b2242;border:1px solid #cfd8ff;border-radius:8px;padding:8px}
      #prism-account-control-center button.action,#prism-account-control-center a.action{background:#5f6ad1;color:#fff;border:1px solid #4f5fc2;border-radius:8px;padding:8px;text-decoration:none;display:inline-grid;place-items:center}
      #prism-account-control-center pre{white-space:pre-wrap;max-height:210px;overflow:auto;background:#f8faff;color:#1b2242;border:1px solid #dbe3ff;border-radius:8px;padding:8px}
      #prism-account-control-center .links{margin-top:10px;font-size:13px;color:#4b557d}
      #prism-account-control-center .links a{color:#3048b7;text-decoration:none}
      #prism-account-control-center .links a:hover{text-decoration:underline}
      @media (max-width:780px){#prism-account-control-center .r3,#prism-account-control-center .r2{grid-template-columns:1fr}}
    </style>
    <script id="prism-account-control-center-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

      // remove all prior injected variants for a true reset
      ['#prism-account-tools','#prism-account-keys-tools','#prism-account-integrations','#prism-integrations-card','#prism-integrations-card-v2','#prism-account-links'].forEach(sel=>qa(sel).forEach(el=>el.remove()));

      const mount=q('.woocommerce-MyAccount-content')||q('.entry-content')||document.body;
      if(!mount) return;
      let root=q('#prism-account-control-center');
      if(!root){
        root=document.createElement('section');
        root.id='prism-account-control-center';
        root.innerHTML=''
          +'<div class="cc-card">'
          +'<h3 style="margin:0 0 10px">Account Control Center</h3>'
          +'<div class="cc-tabs"><button id="cc-tab-keys" class="active">API Keys</button><button id="cc-tab-agent">Local Ollama Agent</button></div>'

          +'<div id="cc-panel-keys" class="cc-panel active">'
          +'<p style="margin:0 0 8px;font-size:12px;color:#4b557d">PixelLab and Base44 are grouped here.</p>'
          +'<h4 style="margin:0 0 6px">PixelLab Key</h4>'
          +'<div class="row r3"><input id="cc-pl-key" type="password" placeholder="Paste PixelLab key"><button id="cc-pl-save" class="action">Save</button><button id="cc-pl-del" class="action">Remove</button></div>'
          +'<p id="cc-pl-status" style="font-size:12px;color:#4b557d">Checking...</p>'

          +'<h4 style="margin:10px 0 6px">Base44 Key</h4>'
          +'<div class="row r3"><input id="cc-b44-key" type="password" placeholder="Paste Base44 key"><button id="cc-b44-save" class="action">Save</button><button id="cc-b44-del" class="action">Remove</button></div>'
          +'<p id="cc-b44-status" style="font-size:12px;color:#4b557d">Checking...</p>'

          +'<div class="links"><strong>Integration Links</strong>: <a href="https://www.pixellab.ai/" target="_blank" rel="noopener">PixelLab account</a> · <a href="https://www.pixellab.ai/pixellab-api" target="_blank" rel="noopener">PixelLab API docs</a> · <a href="https://www.pixellab.ai/mcp" target="_blank" rel="noopener">PixelLab MCP docs</a> · <a href="https://base44.com" target="_blank" rel="noopener">Base44</a></div>'
          +'</div>'

          +'<div id="cc-panel-agent" class="cc-panel">'
          +'<p style="margin:0 0 8px;font-size:12px;color:#4b557d">Fast local mode enabled by default.</p>'
          +'<div class="row r3"><select id="cc-agent-model"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select><label style="display:flex;align-items:center;gap:6px"><input id="cc-agent-auto" type="checkbox"> Auto-apply</label><button id="cc-agent-check" class="action">Check</button></div>'
          +'<textarea id="cc-agent-msg" rows="4" placeholder="Ask your local agent..."></textarea>'
          +'<div class="row r2"><button id="cc-agent-run" class="action">Run Agent</button><a class="action" href="/prism-agent/" target="_blank" rel="noopener">Open Full Agent Page</a></div>'
          +'<p id="cc-agent-status" style="font-size:12px;color:#4b557d">Ready.</p>'
          +'<pre id="cc-agent-json"></pre>'
          +'</div>'
          +'</div>';
        mount.prepend(root);
      }

      const tabKeys=q('#cc-tab-keys',root), tabAgent=q('#cc-tab-agent',root), pKeys=q('#cc-panel-keys',root), pAgent=q('#cc-panel-agent',root);
      const setTab=(k)=>{
        const keys=k==='keys';
        pKeys.classList.toggle('active',keys); pAgent.classList.toggle('active',!keys);
        tabKeys.classList.toggle('active',keys); tabAgent.classList.toggle('active',!keys);
      };
      tabKeys.addEventListener('click',()=>setTab('keys'));
      tabAgent.addEventListener('click',()=>setTab('agent'));

      const setPL=t=>q('#cc-pl-status',root).textContent=t||'';
      const setB=t=>q('#cc-b44-status',root).textContent=t||'';
      const setA=t=>q('#cc-agent-status',root).textContent=t||'';
      const out=q('#cc-agent-json',root);

      async function get(path){const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}
      async function post(path,payload){const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})});const j=await r.json().catch(()=>({}));return {ok:r.ok,j,status:r.status};}

      async function refresh(){
        const pl=await get('pixellab/status');
        setPL(pl.ok&&pl.j?.ok ? (pl.j.connected?('Connected ('+(pl.j.maskedKey||'key')+')'):'Not connected.') : ('Status failed: '+(pl.j?.error||pl.status)));
        const b=await get('base44/status');
        setB(b.ok&&b.j?.ok ? (b.j.connected?('Connected ('+(b.j.maskedKey||'key')+')'):'Not connected.') : ('Status failed: '+(b.j?.error||b.status)));
      }

      q('#cc-pl-save',root).addEventListener('click', async ()=>{const key=(q('#cc-pl-key',root).value||'').trim(); if(!key){setPL('Paste key first.');return;} setPL('Saving...'); const o=await post('pixellab/connect',{apiKey:key,acceptedUsageRules:true}); if(!o.ok||!o.j?.ok){setPL('Save failed: '+(o.j?.error||o.status));return;} q('#cc-pl-key',root).value=''; setPL('Connected ('+(o.j.maskedKey||'key')+')');});
      q('#cc-pl-del',root).addEventListener('click', async ()=>{setPL('Removing...'); const o=await post('pixellab/disconnect',{}); setPL(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status));});

      q('#cc-b44-save',root).addEventListener('click', async ()=>{const key=(q('#cc-b44-key',root).value||'').trim(); if(!key){setB('Paste key first.');return;} setB('Saving...'); const o=await post('base44/connect',{apiKey:key}); if(!o.ok||!o.j?.ok){setB('Save failed: '+(o.j?.error||o.status));return;} q('#cc-b44-key',root).value=''; setB('Connected ('+(o.j.maskedKey||'key')+')');});
      q('#cc-b44-del',root).addEventListener('click', async ()=>{setB('Removing...'); const o=await post('base44/disconnect',{}); setB(o.ok&&o.j?.ok?'Disconnected.':'Remove failed: '+(o.j?.error||o.status));});

      q('#cc-agent-check',root).addEventListener('click', async ()=>{setA('Checking...'); const o=await get('agent3/status'); if(!o.ok||!o.j?.ok){setA('Agent unavailable: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return;} setA('Ollama '+(o.j.ollamaUp?'online':'offline')+' as '+(o.j.user||'user')); out.textContent=JSON.stringify(o.j,null,2);});
      q('#cc-agent-run',root).addEventListener('click', async ()=>{const msg=(q('#cc-agent-msg',root).value||'').trim(); if(!msg){setA('Enter a request first.');return;} setA('Running fast mode...'); const o=await post('agent3/chat-fast',{message:msg,model:q('#cc-agent-model',root).value,autoApply:!!q('#cc-agent-auto',root).checked}); if(!o.ok||!o.j?.ok){setA('Run failed: '+(o.j?.error||o.status)); out.textContent=JSON.stringify(o.j||{},null,2); return;} setA(o.j.reply||'Done.'); out.textContent=JSON.stringify(o.j,null,2);});

      refresh();
      setTab('keys');
    })();
    </script>
    <?php
}, 1000002200);

// ===== My Account rollback-to-native readability (2026-03-09af) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    ?>
    <style id="prism-account-rollback-native-css">
      #prism-account-control-center,
      #prism-account-keys-tools,
      #prism-account-tools,
      #prism-account-integrations,
      #prism-integrations-card,
      #prism-integrations-card-v2,
      #prism-account-links { display:none !important; }
    </style>
    <script id="prism-account-rollback-native-js">
    (function(){
      const ids=['prism-account-control-center','prism-account-keys-tools','prism-account-tools','prism-account-integrations','prism-integrations-card','prism-integrations-card-v2','prism-account-links'];
      ids.forEach(id=>{ const el=document.getElementById(id); if(el) el.remove(); });
    })();
    </script>
    <?php
}, 1000002300);

// ===== My Account native quick tools re-add (2026-03-09ag) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('my-account')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style id="prism-native-quicktools-css">
      #prism-native-quicktools{max-width:980px;margin:10px 0 0;font-size:14px}
      #prism-native-quicktools details{border:1px solid #dbe3ff;border-radius:8px;padding:8px 10px;background:#fff;margin-top:8px}
      #prism-native-quicktools summary{cursor:pointer;font-weight:700}
      #prism-native-quicktools .row{display:grid;grid-template-columns:1fr auto auto;gap:8px;margin-top:8px}
      #prism-native-quicktools input,#prism-native-quicktools select,#prism-native-quicktools textarea{border:1px solid #cfd8ff;border-radius:8px;padding:8px;background:#fff;color:#1b2242}
      #prism-native-quicktools button{border:1px solid #4f5fc2;border-radius:8px;padding:8px;background:#5f6ad1;color:#fff}
      #prism-native-quicktools .muted{font-size:12px;color:#4b557d;margin-top:6px}
      #prism-native-quicktools pre{white-space:pre-wrap;max-height:180px;overflow:auto;background:#f8faff;border:1px solid #dbe3ff;border-radius:8px;padding:8px}
      @media (max-width:780px){#prism-native-quicktools .row{grid-template-columns:1fr}}
    </style>
    <script id="prism-native-quicktools-js">
    (function(){
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s);

      const mount=q('.woocommerce-MyAccount-content')||q('.entry-content')||document.body;
      if(!mount || q('#prism-native-quicktools')) return;

      const box=document.createElement('section');
      box.id='prism-native-quicktools';
      box.innerHTML=''
        +'<h4 style="margin:0 0 6px">Quick Tools</h4>'
        +'<details open><summary>API Keys (PixelLab + Base44)</summary>'
        +'<div class="row"><input id="nqt-pl-key" type="password" placeholder="PixelLab API key"><button id="nqt-pl-save">Save PixelLab</button><button id="nqt-pl-del">Remove</button></div>'
        +'<div class="muted" id="nqt-pl-status">PixelLab: checking...</div>'
        +'<div class="row"><input id="nqt-b44-key" type="password" placeholder="Base44 API key"><button id="nqt-b44-save">Save Base44</button><button id="nqt-b44-del">Remove</button></div>'
        +'<div class="muted" id="nqt-b44-status">Base44: checking...</div>'
        +'<div class="muted">Links: <a href="https://www.pixellab.ai/" target="_blank" rel="noopener">PixelLab</a> · <a href="https://www.pixellab.ai/pixellab-api" target="_blank" rel="noopener">API docs</a> · <a href="https://base44.com" target="_blank" rel="noopener">Base44</a></div>'
        +'</details>'
        +'<details><summary>Local Ollama Agent</summary>'
        +'<div class="row" style="grid-template-columns:1fr 1fr auto"><select id="nqt-agent-model"><option>qwen2.5:3b</option><option>omni-core:phase3</option><option>llama3.2:3b</option></select><input id="nqt-agent-msg" placeholder="Ask local agent..."><button id="nqt-agent-run">Run</button></div>'
        +'<div class="row" style="grid-template-columns:auto auto"><button id="nqt-agent-check">Check Status</button><a href="/prism-agent/" target="_blank" rel="noopener" style="display:inline-grid;place-items:center;border:1px solid #4f5fc2;border-radius:8px;padding:8px;background:#5f6ad1;color:#fff;text-decoration:none">Open Full Agent</a></div>'
        +'<div class="muted" id="nqt-agent-status">Agent: ready.</div>'
        +'<pre id="nqt-agent-json"></pre>'
        +'</details>';
      mount.prepend(box);

      const setPL=t=>q('#nqt-pl-status',box).textContent=t||'';
      const setB=t=>q('#nqt-b44-status',box).textContent=t||'';
      const setA=t=>q('#nqt-agent-status',box).textContent=t||'';
      const out=q('#nqt-agent-json',box);

      async function get(path){ const r=await fetch(API+path,{credentials:'include',headers:{'X-WP-Nonce':NONCE}}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }
      async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:H,body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j,status:r.status}; }

      async function refresh(){
        const pl=await get('pixellab/status');
        setPL(pl.ok&&pl.j?.ok?(pl.j.connected?('PixelLab connected ('+(pl.j.maskedKey||'key')+')'):'PixelLab not connected.'):'PixelLab status failed');
        const b=await get('base44/status');
        setB(b.ok&&b.j?.ok?(b.j.connected?('Base44 connected ('+(b.j.maskedKey||'key')+')'):'Base44 not connected.'):'Base44 status failed');
      }

      q('#nqt-pl-save',box).addEventListener('click', async ()=>{ const key=(q('#nqt-pl-key',box).value||'').trim(); if(!key){setPL('Paste key first.'); return;} const o=await post('pixellab/connect',{apiKey:key,acceptedUsageRules:true}); if(!o.ok||!o.j?.ok){setPL('Save failed'); return;} q('#nqt-pl-key',box).value=''; setPL('PixelLab key saved.'); });
      q('#nqt-pl-del',box).addEventListener('click', async ()=>{ const o=await post('pixellab/disconnect',{}); setPL(o.ok&&o.j?.ok?'PixelLab disconnected.':'Remove failed'); });
      q('#nqt-b44-save',box).addEventListener('click', async ()=>{ const key=(q('#nqt-b44-key',box).value||'').trim(); if(!key){setB('Paste key first.'); return;} const o=await post('base44/connect',{apiKey:key}); if(!o.ok||!o.j?.ok){setB('Save failed'); return;} q('#nqt-b44-key',box).value=''; setB('Base44 key saved.'); });
      q('#nqt-b44-del',box).addEventListener('click', async ()=>{ const o=await post('base44/disconnect',{}); setB(o.ok&&o.j?.ok?'Base44 disconnected.':'Remove failed'); });

      q('#nqt-agent-check',box).addEventListener('click', async ()=>{ setA('Checking...'); const o=await get('agent3/status'); if(!o.ok||!o.j?.ok){ setA('Agent unavailable'); out.textContent=JSON.stringify(o.j||{},null,2); return; } setA('Ollama '+(o.j.ollamaUp?'online':'offline')); out.textContent=JSON.stringify(o.j,null,2); });
      q('#nqt-agent-run',box).addEventListener('click', async ()=>{ const msg=(q('#nqt-agent-msg',box).value||'').trim(); if(!msg){setA('Type a message first.'); return;} setA('Running...'); const o=await post('agent3/chat-fast',{message:msg,model:q('#nqt-agent-model',box).value}); if(!o.ok||!o.j?.ok){ setA('Run failed'); out.textContent=JSON.stringify(o.j||{},null,2); return; } setA(o.j.reply||'Done.'); out.textContent=JSON.stringify(o.j,null,2); });

      refresh();
    })();
    </script>
    <?php
}, 1000002400);
// ===== Prism Creatures v2 modular systems (non-destructive additive upgrade, 2026-03-10) =====
if (!function_exists('prismtek_prism_v2_default_state')) {
    function prismtek_prism_v2_default_state() {
        return [
            'energy' => 72,
            'mood' => 68,
            'stability' => 64,
            'bond' => 22,
            'actions' => [
                'training' => 0,
                'battles' => 0,
                'stabilizing' => 0,
                'exploration' => 0,
                'neglect' => 0,
            ],
            'rank' => 'Bronze',
            'forms' => [
                'blade' => ['unlocked' => true],
                'shield' => ['unlocked' => true],
                'pulse' => ['unlocked' => true],
                'flux' => ['unlocked' => false],
            ],
            'resonance' => [
                'combat' => 0,
                'stability' => 0,
                'exploration' => 0,
            ],
            'updatedAt' => time(),
        ];
    }

    function prismtek_prism_v2_clamp($v, $min = 0, $max = 100) {
        $n = (int)round((float)$v);
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }

    function prismtek_prism_v2_rank_from_bond($bond) {
        $bond = (int)$bond;
        if ($bond >= 90) return 'Radiant';
        if ($bond >= 72) return 'Prism';
        if ($bond >= 54) return 'Gold';
        if ($bond >= 34) return 'Silver';
        return 'Bronze';
    }

    function prismtek_prism_v2_traits($actions) {
        $a = is_array($actions) ? $actions : [];
        $training = (int)($a['training'] ?? 0);
        $battles = (int)($a['battles'] ?? 0);
        $stabilizing = (int)($a['stabilizing'] ?? 0);
        $exploration = (int)($a['exploration'] ?? 0);
        $neglect = (int)($a['neglect'] ?? 0);
        $total = max(1, $training + $battles + $stabilizing + $exploration + $neglect);
        return [
            'disciplined' => (int)round(($training / $total) * 100),
            'aggressive' => (int)round(($battles / $total) * 100),
            'calm' => (int)round(($stabilizing / $total) * 100),
            'curious' => (int)round(($exploration / $total) * 100),
            'unstable' => (int)round(($neglect / $total) * 100),
        ];
    }

    function prismtek_prism_v2_merge($raw) {
        $d = prismtek_prism_v2_default_state();
        $r = is_array($raw) ? $raw : [];
        $out = $d;
        $out['energy'] = prismtek_prism_v2_clamp($r['energy'] ?? $d['energy']);
        $out['mood'] = prismtek_prism_v2_clamp($r['mood'] ?? $d['mood']);
        $out['stability'] = prismtek_prism_v2_clamp($r['stability'] ?? $d['stability']);
        $out['bond'] = prismtek_prism_v2_clamp($r['bond'] ?? $d['bond']);
        $out['actions'] = array_merge($d['actions'], is_array($r['actions'] ?? null) ? $r['actions'] : []);
        foreach ($out['actions'] as $k => $v) $out['actions'][$k] = max(0, (int)$v);
        $out['resonance'] = array_merge($d['resonance'], is_array($r['resonance'] ?? null) ? $r['resonance'] : []);
        foreach ($out['resonance'] as $k => $v) $out['resonance'][$k] = max(0, (int)$v);
        $out['forms'] = array_merge($d['forms'], is_array($r['forms'] ?? null) ? $r['forms'] : []);
        $out['rank'] = prismtek_prism_v2_rank_from_bond($out['bond']);
        $out['traits'] = prismtek_prism_v2_traits($out['actions']);
        $out['updatedAt'] = time();
        return $out;
    }
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/prism/v2/profile', [
        'methods' => 'GET',
        'permission_callback' => function(){ return (bool)get_current_user_id(); },
        'callback' => function () {
            $uid = get_current_user_id();
            $raw = get_user_meta($uid, 'prismtek_prism_v2_profile', true);
            $state = prismtek_prism_v2_merge(is_array($raw) ? $raw : []);
            return rest_ensure_response(['ok' => true, 'state' => $state]);
        }
    ]);

    register_rest_route('prismtek/v1', '/prism/v2/profile', [
        'methods' => 'POST',
        'permission_callback' => function(){ return (bool)get_current_user_id(); },
        'callback' => function (WP_REST_Request $req) {
            $uid = get_current_user_id();
            $payload = $req->get_json_params();
            if (!is_array($payload)) $payload = [];
            $raw = get_user_meta($uid, 'prismtek_prism_v2_profile', true);
            $base = prismtek_prism_v2_merge(is_array($raw) ? $raw : []);
            $next = array_replace_recursive($base, $payload);
            $state = prismtek_prism_v2_merge($next);
            update_user_meta($uid, 'prismtek_prism_v2_profile', $state);
            return rest_ensure_response(['ok' => true, 'state' => $state]);
        }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    if (!is_user_logged_in()) return;
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style id="prism-v2-modular-css">
      .pcv2-card{margin-top:12px;border:2px solid #6b74c7;background:#111531;color:#eef2ff;padding:12px;box-shadow:4px 4px 0 rgba(38,48,106,.8)}
      .pcv2-card h3,.pcv2-card h4{margin:0 0 8px}
      .pcv2-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
      .pcv2-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
      .pcv2-card button,.pcv2-card select{background:#1a2354;color:#fff;border:1px solid #7a86e2;padding:8px}
      .pcv2-pill{display:inline-block;padding:3px 7px;margin:0 6px 6px 0;border:1px solid #7380df;background:#141c45;font-size:11px}
      .pcv2-bar{height:9px;background:#1a1f47;border:1px solid #5562bb;margin:4px 0 8px}
      .pcv2-bar>span{display:block;height:100%;background:linear-gradient(90deg,#62dcff,#8a7dff)}
      .pcv2-note{font-size:11px;opacity:.9}
      @media (max-width:760px){.pcv2-grid,.pcv2-actions{grid-template-columns:1fr}}
    </style>
    <script id="prism-v2-modular-js">
    (()=>{
      const API='/wp-json/prismtek/v1/';
      const NONCE=<?php echo wp_json_encode($nonce); ?>;
      const H={'content-type':'application/json','X-WP-Nonce':NONCE};
      const q=(s,r=document)=>r.querySelector(s);
      const pet=q('#pph-pet-panel') || q('.pph-creatures-wrap .pph-card');
      if(!pet || q('#pcv2-root')) return;

      const box=document.createElement('article');
      box.className='pph-card pcv2-card';
      box.id='pcv2-root';
      box.innerHTML=''
        +'<h3>Prism Creatures v2 Modules (Beta)</h3>'
        +'<div class="pcv2-note">Non-destructive layer: your current creature + battle systems remain active.</div>'
        +'<div id="pcv2-rank" class="pcv2-pill">Rank: Bronze</div>'
        +'<div class="pcv2-grid">'
          +'<div><h4>Core Resources</h4>'
            +'<div>Energy</div><div class="pcv2-bar"><span id="pcv2-energy"></span></div>'
            +'<div>Stability</div><div class="pcv2-bar"><span id="pcv2-stability"></span></div>'
            +'<div>Bond</div><div class="pcv2-bar"><span id="pcv2-bond"></span></div>'
            +'<div>Mood</div><div class="pcv2-bar"><span id="pcv2-mood"></span></div>'
          +'</div>'
          +'<div><h4>Personality Drift</h4>'
            +'<div id="pcv2-traits"></div>'
            +'<div class="pcv2-note">Training→Disciplined · Battles→Aggressive · Stabilize→Calm · Explore→Curious · Neglect→Unstable</div>'
          +'</div>'
        +'</div>'
        +'<h4>Daily Loop</h4>'
        +'<div class="pcv2-actions">'
          +'<button data-act="feed">Feed Energy</button>'
          +'<button data-act="train">Train Abilities</button>'
          +'<button data-act="stabilize">Stabilize Core</button>'
          +'<button data-act="explore">Explore Mutation</button>'
          +'<button data-act="battle">Run PvP Drill</button>'
          +'<button data-act="neglect">Skip Care</button>'
        +'</div>'
        +'<h4 style="margin-top:10px">Transformation Combat</h4>'
        +'<div class="pcv2-actions">'
          +'<button data-form="blade">Blade (Atk)</button>'
          +'<button data-form="shield">Shield (Def)</button>'
          +'<button data-form="pulse">Pulse (Range)</button>'
          +'<button data-form="flux">Flux (Burst)</button>'
        +'</div>'
        +'<div class="pcv2-note" id="pcv2-status">Ready.</div>';

      pet.parentNode.insertBefore(box, pet.nextSibling);

      const state={energy:72,mood:68,stability:64,bond:22,actions:{training:0,battles:0,stabilizing:0,exploration:0,neglect:0},traits:{disciplined:0,aggressive:0,calm:0,curious:0,unstable:0},rank:'Bronze'};
      const clamp=(v,min=0,max=100)=>Math.max(min,Math.min(max,Math.round(v)));
      const stat=q('#pcv2-status',box);

      function traitRow(t){
        return '<span class="pcv2-pill">Disciplined '+(t.disciplined||0)+'%</span>'
          +'<span class="pcv2-pill">Aggressive '+(t.aggressive||0)+'%</span>'
          +'<span class="pcv2-pill">Calm '+(t.calm||0)+'%</span>'
          +'<span class="pcv2-pill">Curious '+(t.curious||0)+'%</span>'
          +'<span class="pcv2-pill">Unstable '+(t.unstable||0)+'%</span>';
      }

      function paint(){
        q('#pcv2-energy',box).style.width=clamp(state.energy)+'%';
        q('#pcv2-stability',box).style.width=clamp(state.stability)+'%';
        q('#pcv2-bond',box).style.width=clamp(state.bond)+'%';
        q('#pcv2-mood',box).style.width=clamp(state.mood)+'%';
        q('#pcv2-rank',box).textContent='Rank: '+(state.rank||'Bronze');
        q('#pcv2-traits',box).innerHTML=traitRow(state.traits||{});
      }

      async function load(){
        try{
          const r=await fetch(API+'prism/v2/profile?ts='+Date.now(),{credentials:'include',headers:{'X-WP-Nonce':NONCE}});
          const j=await r.json();
          if(r.ok&&j.ok&&j.state) Object.assign(state,j.state);
          paint();
        }catch{ stat.textContent='Module load failed.'; }
      }

      async function save(){
        try{
          const r=await fetch(API+'prism/v2/profile',{method:'POST',credentials:'include',headers:H,body:JSON.stringify(state)});
          const j=await r.json();
          if(r.ok&&j.ok&&j.state) Object.assign(state,j.state);
          paint();
        }catch{}
      }

      function applyAction(act){
        if(act==='feed'){ state.energy=clamp(state.energy+16); state.mood=clamp(state.mood+6); state.bond=clamp(state.bond+2); }
        if(act==='train'){ state.energy=clamp(state.energy-9); state.mood=clamp(state.mood+2); state.bond=clamp(state.bond+3); state.actions.training=(state.actions.training||0)+1; }
        if(act==='stabilize'){ state.energy=clamp(state.energy-5); state.stability=clamp(state.stability+12); state.actions.stabilizing=(state.actions.stabilizing||0)+1; }
        if(act==='explore'){ state.energy=clamp(state.energy-8); state.stability=clamp(state.stability-2); state.bond=clamp(state.bond+2); state.actions.exploration=(state.actions.exploration||0)+1; }
        if(act==='battle'){ state.energy=clamp(state.energy-12); state.stability=clamp(state.stability-6); state.bond=clamp(state.bond+5); state.actions.battles=(state.actions.battles||0)+1; }
        if(act==='neglect'){ state.mood=clamp(state.mood-12); state.stability=clamp(state.stability-10); state.actions.neglect=(state.actions.neglect||0)+1; }
      }

      function applyForm(form){
        const costs={blade:9,shield:7,pulse:8,flux:14};
        const c=costs[form]||8;
        if(state.energy<c){ stat.textContent='Not enough energy for '+form+'.'; return; }
        state.energy=clamp(state.energy-c);
        if(form==='blade'){ state.stability=clamp(state.stability-3); }
        if(form==='shield'){ state.stability=clamp(state.stability+4); }
        if(form==='pulse'){ state.stability=clamp(state.stability-1); }
        if(form==='flux'){ state.stability=clamp(state.stability-9); state.bond=clamp(state.bond+3); }
        stat.textContent='Transformed: '+form.toUpperCase()+'.';
      }

      box.addEventListener('click', (e)=>{
        const b=e.target.closest('button');
        if(!b) return;
        const act=b.getAttribute('data-act');
        const form=b.getAttribute('data-form');
        if(act){ applyAction(act); stat.textContent='Action: '+act+'.'; }
        if(form){ applyForm(form); }
        paint();
        save();
      });

      load();
    })();
    </script>
    <?php
}, 1000002500);

// ===== Prism v2 -> live PvP resolution bridge (non-destructive, 2026-03-10) =====
if (!function_exists('prismtek_pvp_v2_profile')) {
    function prismtek_pvp_v2_profile($uid){
        $uid=(int)$uid;
        $raw = get_user_meta($uid, 'prismtek_prism_v2_profile', true);
        if (function_exists('prismtek_prism_v2_merge')) return prismtek_prism_v2_merge(is_array($raw)?$raw:[]);
        $traits=['disciplined'=>0,'aggressive'=>0,'calm'=>0,'curious'=>0,'unstable'=>0];
        return ['energy'=>70,'mood'=>65,'stability'=>60,'bond'=>20,'traits'=>$traits,'actions'=>[],'rank'=>'Bronze'];
    }

    function prismtek_pvp_v2_clamp($v,$min=0,$max=100){ $v=(int)round((float)$v); return max($min,min($max,$v)); }

    function prismtek_pvp_v2_boot_state(&$m,$uid){
        $uid=(int)$uid;
        if(!isset($m['v2']) || !is_array($m['v2'])) $m['v2']=[];
        if(!isset($m['v2profiles']) || !is_array($m['v2profiles'])) $m['v2profiles']=[];
        if(isset($m['v2'][$uid])) return;

        $p = prismtek_pvp_v2_profile($uid);
        $t = is_array($p['traits'] ?? null) ? $p['traits'] : [];
        $disc=(int)($t['disciplined'] ?? 0);
        $aggr=(int)($t['aggressive'] ?? 0);
        $unst=(int)($t['unstable'] ?? 0);
        $stability=(int)($p['stability'] ?? 60);

        $inst = prismtek_pvp_v2_clamp((100-$stability) + (int)round($unst*0.30) - (int)round($disc*0.15) + (int)round($aggr*0.10));
        $m['v2'][$uid] = [
            'instability' => $inst,
            'disrupted' => 0,
            'exposed' => 0,
            'clutchUsed' => false,
            'lastForm' => 'blade',
        ];
        $m['v2profiles'][$uid] = [
            'energy' => prismtek_pvp_v2_clamp((int)($p['energy'] ?? 70)),
            'mood' => prismtek_pvp_v2_clamp((int)($p['mood'] ?? 65)),
            'stability' => prismtek_pvp_v2_clamp((int)($p['stability'] ?? 60)),
            'bond' => prismtek_pvp_v2_clamp((int)($p['bond'] ?? 20)),
            'traits' => [
                'disciplined' => prismtek_pvp_v2_clamp((int)($t['disciplined'] ?? 0)),
                'aggressive' => prismtek_pvp_v2_clamp((int)($t['aggressive'] ?? 0)),
                'calm' => prismtek_pvp_v2_clamp((int)($t['calm'] ?? 0)),
                'curious' => prismtek_pvp_v2_clamp((int)($t['curious'] ?? 0)),
                'unstable' => prismtek_pvp_v2_clamp((int)($t['unstable'] ?? 0)),
            ],
            'rank' => (string)($p['rank'] ?? 'Bronze'),
        ];

        // Bond/energy/mood setup bonus (applies once at battle entry)
        $bond = (int)$m['v2profiles'][$uid]['bond'];
        $energy = (int)$m['v2profiles'][$uid]['energy'];
        $mood = (int)$m['v2profiles'][$uid]['mood'];

        $hpBoost = (int)floor(max(0,$bond-10)/12); // up to ~7
        $m['maxHp'][$uid] = (int)($m['maxHp'][$uid] ?? 100) + $hpBoost;
        $m['hp'][$uid] = min((int)$m['maxHp'][$uid], (int)($m['hp'][$uid] ?? (int)$m['maxHp'][$uid]) + $hpBoost);

        $m['charge'][$uid] = min(16, (int)($m['charge'][$uid] ?? 0) + (int)floor(max(0,$energy-60)/18));
        if($mood >= 75){
            $m['v2'][$uid]['instability'] = max(0, (int)$m['v2'][$uid]['instability'] - 6);
        } elseif($mood <= 35){
            $m['v2'][$uid]['instability'] = min(100, (int)$m['v2'][$uid]['instability'] + 6);
        }
    }

    function prismtek_pvp_v2_move_to_form($move){
        $m=sanitize_key((string)$move);
        $map=['strike'=>'blade','guard'=>'shield','charge'=>'pulse','heal'=>'flux','blade'=>'blade','shield'=>'shield','pulse'=>'pulse','flux'=>'flux'];
        return (string)($map[$m] ?? 'blade');
    }

    function prismtek_pvp_v2_priority($form){
        $prio=['shield'=>2,'pulse'=>1,'blade'=>0,'flux'=>0];
        return (int)($prio[$form] ?? 0);
    }

    function prismtek_pvp_v2_rng($min,$max){ return mt_rand((int)$min,(int)$max); }

    function prismtek_pvp_v2_apply_clutch(&$m,$uid,$log){
        $uid=(int)$uid;
        if(empty($m['v2'][$uid]) || empty($m['v2profiles'][$uid])) return;
        $max=(int)($m['maxHp'][$uid] ?? 100);
        $hp=(int)($m['hp'][$uid] ?? 0);
        $bond=(int)($m['v2profiles'][$uid]['bond'] ?? 0);
        if($bond < 68) return;
        if(!empty($m['v2'][$uid]['clutchUsed'])) return;
        if($hp > (int)floor($max*0.25)) return;
        $heal = 10 + (int)floor($bond/14);
        $m['hp'][$uid] = min($max, $hp + $heal);
        $m['v2'][$uid]['clutchUsed'] = true;
        $m['v2'][$uid]['instability'] = max(0, (int)$m['v2'][$uid]['instability'] - 10);
        $log[] = prismtek_pvp_user_tag($uid).' triggered BOND CLUTCH (+'.$heal.' HP).';
    }

    function prismtek_pvp_resolve_round_v2(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        if(empty($m['cd'][$a])) $m['cd'][$a]=['heal'=>0,'charge'=>0];
        if(empty($m['cd'][$b])) $m['cd'][$b]=['heal'=>0,'charge'=>0];

        prismtek_pvp_v2_boot_state($m,$a);
        prismtek_pvp_v2_boot_state($m,$b);

        $ma = prismtek_pvp_v2_move_to_form($m['moves'][$a] ?? 'blade');
        $mb = prismtek_pvp_v2_move_to_form($m['moves'][$b] ?? 'blade');
        if(!$ma || !$mb) return;

        $ord=[
          ['uid'=>$a,'opp'=>$b,'form'=>$ma,'prio'=>prismtek_pvp_v2_priority($ma),'spd'=>prismtek_pvp_speed_stat($a)+($ma==='pulse'?4:0)],
          ['uid'=>$b,'opp'=>$a,'form'=>$mb,'prio'=>prismtek_pvp_v2_priority($mb),'spd'=>prismtek_pvp_speed_stat($b)+($mb==='pulse'?4:0)],
        ];
        usort($ord,function($x,$y){
            if($x['prio']!==$y['prio']) return $y['prio']<=>$x['prio'];
            if($x['spd']!==$y['spd']) return $y['spd']<=>$x['spd'];
            return mt_rand(0,1)?1:-1;
        });

        $log=[];
        foreach($ord as $turn){
            $uid=(int)$turn['uid']; $opp=(int)$turn['opp']; $form=(string)$turn['form'];
            if(($m['hp'][$uid]??0)<=0 || ($m['hp'][$opp]??0)<=0) continue;

            $cd=&$m['cd'][$uid];
            foreach($cd as $k=>$v) $cd[$k]=max(0,(int)$v-1);

            // Form disruption gate
            if((int)($m['v2'][$uid]['disrupted'] ?? 0) > 0){
                $m['v2'][$uid]['disrupted'] = max(0, (int)$m['v2'][$uid]['disrupted'] - 1);
                $log[] = prismtek_pvp_user_tag($uid).' was disrupted and lost the turn.';
                continue;
            }

            $p = $m['v2profiles'][$uid];
            $t = $p['traits'];
            $disc=(int)($t['disciplined'] ?? 0);
            $aggr=(int)($t['aggressive'] ?? 0);
            $calm=(int)($t['calm'] ?? 0);
            $curi=(int)($t['curious'] ?? 0);
            $unst=(int)($t['unstable'] ?? 0);
            $bond=(int)($p['bond'] ?? 20);

            $inst=(int)($m['v2'][$uid]['instability'] ?? 0);
            $instGain=0;
            $dmg=0;

            // Base form behavior
            if($form==='blade'){
                $dmg = prismtek_pvp_v2_rng(16,24) + (int)floor((int)($m['charge'][$uid] ?? 0)/2);
                $m['v2'][$uid]['exposed']=1; // defense down after committing to blade
                $instGain += 8;
            } elseif($form==='shield'){
                $m['guard'][$uid]=1;
                $stabilize = 8 + (int)floor($calm/18);
                $m['v2'][$uid]['instability'] = max(0, (int)$m['v2'][$uid]['instability'] - $stabilize);
                $log[] = prismtek_pvp_user_tag($uid).' shifted SHIELD and stabilized core (-'.$stabilize.' instability).';
                $m['charge'][$uid] = max(0, (int)($m['charge'][$uid] ?? 0)-2);
                $m['v2'][$uid]['lastForm']='shield';
                continue;
            } elseif($form==='pulse'){
                if((int)($cd['charge']??0)>0){
                    $log[] = prismtek_pvp_user_tag($uid).' tried PULSE (cooldown).';
                    continue;
                }
                $dmg = prismtek_pvp_v2_rng(11,18);
                $m['charge'][$uid]=min(18,(int)($m['charge'][$uid]??0)+4);
                $cd['charge']=1;
                $instGain += 4;
            } elseif($form==='flux'){
                if((int)($cd['heal']??0)>0){
                    $log[] = prismtek_pvp_user_tag($uid).' tried FLUX (cooldown).';
                    continue;
                }
                $dmg = prismtek_pvp_v2_rng(24,36) + (int)($m['charge'][$uid] ?? 0);
                $m['charge'][$uid]=0;
                $instGain += 14;
                $cd['heal']=2;
            }

            // Personality modifiers
            $dmg = (int)round($dmg * (1 + min(0.22, $aggr/450))); // aggressive damage up
            $instGain = (int)round($instGain * (1 + min(0.35, $aggr/300))); // aggressive instability up
            $instGain = (int)round($instGain * (1 - min(0.30, $disc/350))); // disciplined instability down

            // Bond mastery (passive + form mastery)
            if($bond >= 48) $dmg += 2;
            if($bond >= 72){
                $master=['aggressive'=>'blade','calm'=>'shield','curious'=>'pulse','unstable'=>'flux','disciplined'=>'shield'];
                $top='aggressive'; $topV=-1;
                foreach($t as $k=>$v){ if((int)$v>$topV){$top=$k;$topV=(int)$v;} }
                $fav=(string)($master[$top] ?? 'blade');
                if($form===$fav) $dmg=(int)round($dmg*1.12);
            }

            // Instability threshold effects
            $misfireChance=0; $critChance=0; $selfPenaltyChance=0; $disruptChance=0;
            if($inst>=35 && $inst<70){
                $dmg=(int)round($dmg*1.10); // mid instability power band
            } elseif($inst>=70){
                $dmg=(int)round($dmg*1.18);
                $misfireChance = 0.20 + ($unst/500);
                $critChance = 0.14 + ($unst/700);
                $selfPenaltyChance = 0.14 + ($unst/600);
                $disruptChance = 0.12 + ($unst/700);
            }

            // Curious adaptive spike
            if(prismtek_pvp_v2_rng(1,1000) <= (70 + (int)($curi*1.4))){
                $bonus = prismtek_pvp_v2_rng(3,8);
                $dmg += $bonus;
                $log[] = prismtek_pvp_user_tag($uid).' adaptive surge +'.$bonus.' (Curious).';
            }

            // High-instability chaos checks
            if($misfireChance>0 && (mt_rand()/mt_getrandmax()) < $misfireChance){
                $self=prismtek_pvp_v2_rng(7,14);
                $m['hp'][$uid]=max(0,(int)$m['hp'][$uid]-$self);
                $m['v2'][$uid]['instability']=min(100,(int)$m['v2'][$uid]['instability']+4);
                $log[] = prismtek_pvp_user_tag($uid).' misfired in '.$form.' and took '.$self.' self-damage!';
                prismtek_pvp_v2_apply_clutch($m,$uid,$log);
                continue;
            }
            if($critChance>0 && (mt_rand()/mt_getrandmax()) < $critChance){
                $dmg=(int)round($dmg*1.35);
                $log[] = prismtek_pvp_user_tag($uid).' landed a CHAOS CRIT!';
            }

            // Defender mitigation and attacker penalties
            if(!empty($m['guard'][$opp])){
                $mit = ($form==='pulse') ? 0.78 : 0.58;
                $dmg=(int)floor($dmg*$mit);
            }
            if(!empty($m['v2'][$uid]['exposed'])){
                // blade downside applies until next action
                $m['v2'][$uid]['exposed']=0;
            }
            if(!empty($m['v2'][$opp]['exposed'])){
                $dmg=(int)round($dmg*1.15); // punish exposed target
                $m['v2'][$opp]['exposed']=0;
            }

            // Apply damage
            $dmg=max(1,(int)$dmg);
            $m['hp'][$opp]=max(0,(int)$m['hp'][$opp]-$dmg);

            // Post-hit chaos outcomes for attacker
            if($selfPenaltyChance>0 && (mt_rand()/mt_getrandmax()) < $selfPenaltyChance){
                $self=prismtek_pvp_v2_rng(5,10);
                $m['hp'][$uid]=max(0,(int)$m['hp'][$uid]-$self);
                $log[] = prismtek_pvp_user_tag($uid).' suffered flux recoil ('.$self.').';
            }
            if($disruptChance>0 && (mt_rand()/mt_getrandmax()) < $disruptChance){
                $m['v2'][$uid]['disrupted']=1;
                $log[] = prismtek_pvp_user_tag($uid).' core disruption!';
            }

            $m['v2'][$uid]['instability'] = prismtek_pvp_v2_clamp((int)$m['v2'][$uid]['instability'] + $instGain);
            if($form==='shield') $m['v2'][$uid]['instability'] = max(0,(int)$m['v2'][$uid]['instability'] - (4 + (int)floor($calm/25)));

            $m['v2'][$uid]['lastForm']=$form;
            $log[] = prismtek_pvp_user_tag($uid).' used '.strtoupper($form).' for '.$dmg.'.';

            prismtek_pvp_v2_apply_clutch($m,$uid,$log);
            prismtek_pvp_v2_apply_clutch($m,$opp,$log);
        }

        $m['guard'][$a]=0; $m['guard'][$b]=0;
        $m['round']=(int)$m['round']+1;
        $m['log']=array_slice(array_merge((array)$m['log'],$log),-60);
        $m['moves']=[];

        $ha=(int)$m['hp'][$a]; $hb=(int)$m['hp'][$b];
        if($ha<=0||$hb<=0){
            $m['done']=true; $m['status']='done';
            if($ha===$hb){ $winner=0; $m['result']='draw'; }
            else { $winner=$ha>$hb?$a:$b; $m['result']='win'; }
            $m['winner']=$winner;
            if($winner){
                $loser=$winner===$a?$b:$a;
                prismtek_battle_v2_set_rating($winner,prismtek_battle_v2_rating($winner)+20);
                prismtek_battle_v2_set_rating($loser,prismtek_battle_v2_rating($loser)-14);
                $m['log'][]='Winner: '.prismtek_pvp_user_tag($winner);
            } else {
                $m['log'][]='Draw.';
            }
            if(function_exists('prismtek_pvp_history_add') && function_exists('prismtek_pvp_enrich_state')) prismtek_pvp_history_add(prismtek_pvp_enrich_state($m));
        }
    }
}

// Intercept existing move-pro endpoint and route through v2 resolver without removing legacy routes.
add_filter('rest_pre_dispatch', function($result, $server, $request){
    if ($result !== null) return $result;
    if (!($request instanceof WP_REST_Request)) return $result;
    $route = (string)$request->get_route();
    $method = strtoupper((string)$request->get_method());
    if ($route !== '/prismtek/v1/pet/pvp/move-pro' || $method !== 'POST') return $result;

    $uid=get_current_user_id();
    if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

    $id=sanitize_text_field((string)$request->get_param('matchId'));
    $move=sanitize_key((string)$request->get_param('move'));
    if(!in_array($move,['strike','guard','charge','heal','blade','shield','pulse','flux'],true)){
        return new WP_REST_Response(['ok'=>false,'error'=>'bad_move'],400);
    }

    if(!function_exists('prismtek_pvp_get_matches')) return new WP_REST_Response(['ok'=>false,'error'=>'pvp_unavailable'],500);
    $matches=prismtek_pvp_get_matches();
    if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);

    $m=$matches[$id];
    if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
    if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
    if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);

    $m['moves'][$uid]=$move;
    $m['queue'][]=['uid'=>$uid,'move'=>$move,'at'=>time(),'mode'=>'v2'];
    $m['queue']=array_slice((array)$m['queue'],-12);
    $m['updatedAt']=time();

    prismtek_pvp_resolve_round_v2($m);

    $matches[$id]=$m;
    prismtek_pvp_set_matches($matches);

    return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m),'rating'=>prismtek_battle_v2_rating($uid)]);
}, 9, 3);

// Lightweight UI patch: map existing PvP buttons to forms so live match actions match v2 language.
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-pvp-v2-form-ui-patch">
    (()=>{
      const remap=()=>{
        const btns=[...document.querySelectorAll('.pvp-m')];
        if(!btns.length) return;
        const map={strike:['blade','Blade'],guard:['shield','Shield'],charge:['pulse','Pulse'],heal:['flux','Flux']};
        btns.forEach(b=>{
          const m=(b.getAttribute('data-m')||'').trim();
          if(map[m]){
            b.setAttribute('data-m',map[m][0]);
            b.textContent=map[m][1];
            b.title=map[m][1]+' form';
          }
        });
      };
      remap();
      const mo=new MutationObserver(()=>remap());
      mo.observe(document.documentElement,{childList:true,subtree:true});
    })();
    </script>
    <?php
}, 1000002600);

// ===== Prism combat architecture refactor: strict turn-based + persistent growth stage (2026-03-10) =====
if (!function_exists('prismtek_prism_growth_from_pet')) {
    function prismtek_prism_growth_from_pet($pet){
        $pet = is_array($pet) ? $pet : [];
        $lvl = max(1, (int)($pet['level'] ?? (function_exists('prismtek_pet_level_from_xp') ? prismtek_pet_level_from_xp((int)($pet['xp'] ?? 0)) : 1)));
        if($lvl >= 18) return 'mythic';
        if($lvl >= 10) return 'champion';
        if($lvl >= 4) return 'rookie';
        return 'cub';
    }

    function prismtek_prism_growth_mod($growth){
        $g=sanitize_key((string)$growth);
        $m=['cub'=>0.92,'rookie'=>1.0,'champion'=>1.12,'mythic'=>1.24];
        return (float)($m[$g] ?? 1.0);
    }

    function prismtek_prism_species_bias($species){
        $s=sanitize_key((string)$species);
        $b=[
          'sprout'=>['attack'=>4,'defense'=>8,'speed'=>2,'tech'=>4],
          'ember'=>['attack'=>10,'defense'=>2,'speed'=>4,'tech'=>2],
          'tidal'=>['attack'=>3,'defense'=>9,'speed'=>1,'tech'=>6],
          'volt'=>['attack'=>5,'defense'=>2,'speed'=>10,'tech'=>4],
          'shade'=>['attack'=>7,'defense'=>3,'speed'=>7,'tech'=>6],
        ];
        return $b[$s] ?? $b['sprout'];
    }

    function prismtek_prism_personality_bias($personality){
        $p=sanitize_key((string)$personality);
        $b=[
          'brave'=>['attack'=>6,'defense'=>0,'speed'=>1,'tech'=>0],
          'calm'=>['attack'=>0,'defense'=>5,'speed'=>0,'tech'=>1],
          'curious'=>['attack'=>1,'defense'=>0,'speed'=>2,'tech'=>4],
          'chaotic'=>['attack'=>5,'defense'=>-2,'speed'=>3,'tech'=>2],
        ];
        return $b[$p] ?? $b['brave'];
    }

    function prismtek_prism_move_catalog(){
        return [
          // Blade
          ['name'=>'Shard Cleave','type'=>'kinetic','category'=>'blade','power'=>32,'accuracy'=>92,'priority'=>0,'energy_cost'=>13,'stability_effect'=>8,'status_effect'=>'expose','scaling'=>['atk'=>1.12,'bond'=>0.12,'growth'=>0.24]],
          ['name'=>'Edge Burst','type'=>'arc','category'=>'blade','power'=>28,'accuracy'=>96,'priority'=>1,'energy_cost'=>11,'stability_effect'=>7,'status_effect'=>'none','scaling'=>['atk'=>1.0,'bond'=>0.1,'growth'=>0.2]],
          ['name'=>'Rift Lancer','type'=>'pierce','category'=>'blade','power'=>36,'accuracy'=>86,'priority'=>0,'energy_cost'=>15,'stability_effect'=>10,'status_effect'=>'none','scaling'=>['atk'=>1.2,'bond'=>0.14,'growth'=>0.25]],
          // Shield
          ['name'=>'Aegis Fold','type'=>'ward','category'=>'shield','power'=>14,'accuracy'=>100,'priority'=>2,'energy_cost'=>8,'stability_effect'=>-12,'status_effect'=>'guard','scaling'=>['def'=>1.0,'bond'=>0.08,'growth'=>0.16]],
          ['name'=>'Mirror Bastion','type'=>'ward','category'=>'shield','power'=>12,'accuracy'=>100,'priority'=>1,'energy_cost'=>9,'stability_effect'=>-15,'status_effect'=>'guard_reflect','scaling'=>['def'=>1.08,'bond'=>0.1,'growth'=>0.18]],
          ['name'=>'Pulse Barrier','type'=>'ward','category'=>'shield','power'=>10,'accuracy'=>100,'priority'=>2,'energy_cost'=>7,'stability_effect'=>-10,'status_effect'=>'stabilize','scaling'=>['def'=>0.95,'bond'=>0.09,'growth'=>0.15]],
          // Pulse
          ['name'=>'Arc Needle','type'=>'energy','category'=>'pulse','power'=>24,'accuracy'=>96,'priority'=>1,'energy_cost'=>8,'stability_effect'=>4,'status_effect'=>'drain','scaling'=>['tech'=>1.05,'bond'=>0.1,'growth'=>0.18]],
          ['name'=>'Photon Lattice','type'=>'energy','category'=>'pulse','power'=>22,'accuracy'=>100,'priority'=>1,'energy_cost'=>7,'stability_effect'=>3,'status_effect'=>'mark','scaling'=>['tech'=>1.0,'bond'=>0.09,'growth'=>0.18]],
          ['name'=>'Vector Ping','type'=>'energy','category'=>'pulse','power'=>18,'accuracy'=>100,'priority'=>2,'energy_cost'=>6,'stability_effect'=>2,'status_effect'=>'focus','scaling'=>['tech'=>0.95,'bond'=>0.08,'growth'=>0.16]],
          // Flux
          ['name'=>'Flux Breaker','type'=>'chaos','category'=>'flux','power'=>40,'accuracy'=>78,'priority'=>0,'energy_cost'=>16,'stability_effect'=>16,'status_effect'=>'chaos','scaling'=>['atk'=>1.18,'tech'=>0.72,'bond'=>0.14,'growth'=>0.28]],
          ['name'=>'Overtone Collapse','type'=>'chaos','category'=>'flux','power'=>34,'accuracy'=>85,'priority'=>0,'energy_cost'=>14,'stability_effect'=>14,'status_effect'=>'disrupt','scaling'=>['atk'=>1.0,'tech'=>0.9,'bond'=>0.12,'growth'=>0.24]],
          ['name'=>'Critical Prism','type'=>'chaos','category'=>'flux','power'=>30,'accuracy'=>88,'priority'=>1,'energy_cost'=>13,'stability_effect'=>13,'status_effect'=>'unstable_crit','scaling'=>['atk'=>0.95,'tech'=>0.95,'bond'=>0.12,'growth'=>0.22]],
        ];
    }

    function prismtek_prism_pick_moves($species,$personality,$growth){
        $catalog = prismtek_prism_move_catalog();
        $groups=['blade'=>[],'shield'=>[],'pulse'=>[],'flux'=>[]];
        foreach($catalog as $m){ $groups[$m['category']][]=$m; }

        // deterministic-ish pick for persistence + variation
        $seed = crc32(strtolower((string)$species.'|'.(string)$personality.'|'.(string)$growth));
        mt_srand($seed);
        $set=[];
        foreach(['blade','shield','pulse','flux'] as $cat){
            $arr=$groups[$cat];
            $idx = mt_rand(0, max(0,count($arr)-1));
            $m = $arr[$idx];
            $slug = sanitize_title($m['name']);
            $m['id'] = $cat.'-'.$slug;
            $set[]=$m;
        }
        mt_srand();

        return $set;
    }

    function prismtek_prism_build_combat_model($uid, $overrides = []){
        $uid=(int)$uid;
        $pet = get_user_meta($uid,'prismtek_pet_state',true);
        $pet = is_array($pet) ? $pet : [];
        $v2 = get_user_meta($uid,'prismtek_prism_v2_profile',true);
        $v2 = is_array($v2) ? $v2 : [];

        $species = sanitize_key((string)($overrides['species'] ?? $pet['species'] ?? 'sprout'));
        $personality = sanitize_key((string)($overrides['personality'] ?? $pet['personality'] ?? 'brave'));
        $growth = sanitize_key((string)($overrides['current_growth_stage'] ?? prismtek_prism_growth_from_pet($pet)));

        $bond = max(0,min(100,(int)($v2['bond'] ?? 20)));
        $energy = max(0,min(100,(int)($v2['energy'] ?? (int)($pet['energy'] ?? 70))));
        $stability = max(0,min(100,(int)($v2['stability'] ?? 60)));

        $lvl = max(1,(int)($pet['level'] ?? 1));
        $base = ['attack'=>22+$lvl*2,'defense'=>22+$lvl*2,'speed'=>20+$lvl*2,'tech'=>22+$lvl*2];
        $sb = prismtek_prism_species_bias($species);
        $pb = prismtek_prism_personality_bias($personality);
        $gm = prismtek_prism_growth_mod($growth);

        $derived=[];
        foreach(['attack','defense','speed','tech'] as $k){
            $derived[$k] = (int)round(max(8, ($base[$k] + (int)($sb[$k]??0) + (int)($pb[$k]??0)) * $gm));
        }
        $derived['instability_base'] = max(0,min(100, (100-$stability) + (int)round(((100-$energy)*0.2)) ));

        $moveset = prismtek_prism_pick_moves($species,$personality,$growth);

        return [
            'version'=>1,
            'species'=>$species,
            'form'=>(string)($v2['lastForm'] ?? 'blade'),
            'current_growth_stage'=>$growth,
            'base_stats'=>$base,
            'derived_combat_stats'=>$derived,
            'generated_moveset'=>$moveset,
            'personality'=>$personality,
            'bond'=>$bond,
            'energy'=>$energy,
            'stability'=>$stability,
            'updatedAt'=>time(),
        ];
    }

    function prismtek_prism_combat_get_or_create($uid, $force=false, $overrides=[]){
        $uid=(int)$uid;
        $key='prismtek_prism_combat_model_v1';
        $raw = get_user_meta($uid,$key,true);
        $isValid = is_array($raw) && !empty($raw['generated_moveset']) && !empty($raw['current_growth_stage']);
        if(!$force && $isValid) return $raw;
        $m = prismtek_prism_build_combat_model($uid, $overrides);
        update_user_meta($uid,$key,$m);
        return $m;
    }

    function prismtek_prism_move_from_input($input, $model){
        $input=sanitize_key((string)$input);
        $moves = is_array($model['generated_moveset'] ?? null) ? $model['generated_moveset'] : [];
        if(empty($moves)) return null;

        $byCat=['blade'=>null,'shield'=>null,'pulse'=>null,'flux'=>null];
        foreach($moves as $m){
            $id=sanitize_key((string)($m['id'] ?? ''));
            $cat=sanitize_key((string)($m['category'] ?? ''));
            if($id===$input) return $m;
            if($cat && empty($byCat[$cat])) $byCat[$cat]=$m;
            if(sanitize_key((string)($m['name'] ?? ''))===$input) return $m;
        }

        $aliases=['strike'=>'blade','guard'=>'shield','charge'=>'pulse','heal'=>'flux','blade'=>'blade','shield'=>'shield','pulse'=>'pulse','flux'=>'flux'];
        $cat = $aliases[$input] ?? '';
        if($cat && !empty($byCat[$cat])) return $byCat[$cat];

        return null;
    }

    function prismtek_prism_match_boot(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        if(empty($m['prismModels']) || !is_array($m['prismModels'])) $m['prismModels']=[];
        if(empty($m['combat']) || !is_array($m['combat'])) $m['combat']=[];
        if(empty($m['combat']['energy'])) $m['combat']['energy']=[];
        if(empty($m['combat']['instability'])) $m['combat']['instability']=[];
        if(empty($m['combat']['status'])) $m['combat']['status']=[];
        if(empty($m['combat']['turn'])) $m['combat']['turn']=1;

        foreach([$a,$b] as $uid){
            if(empty($m['prismModels'][$uid])) $m['prismModels'][$uid]=prismtek_prism_combat_get_or_create($uid,false,[]);
            $model=$m['prismModels'][$uid];
            if(!isset($m['combat']['energy'][$uid])) $m['combat']['energy'][$uid]=(int)($model['energy'] ?? 70);
            if(!isset($m['combat']['instability'][$uid])) $m['combat']['instability'][$uid]=(int)($model['derived_combat_stats']['instability_base'] ?? 25);
            if(!isset($m['combat']['status'][$uid]) || !is_array($m['combat']['status'][$uid])) $m['combat']['status'][$uid]=[];
        }
    }

    function prismtek_prism_speed_for_turn($uid,$move,$m){
        $model = $m['prismModels'][$uid] ?? [];
        $spd = (int)($model['derived_combat_stats']['speed'] ?? prismtek_pvp_speed_stat($uid));
        if(!empty($m['combat']['status'][$uid]['focus'])) $spd += 4;
        if((string)($move['category'] ?? '')==='pulse') $spd += 3;
        return $spd;
    }

    function prismtek_prism_damage_calc($att,$def,$move,$m){
        $am = $m['prismModels'][$att] ?? [];
        $dm = $m['prismModels'][$def] ?? [];

        $acat=(string)($move['category'] ?? 'blade');
        $ast=(array)($am['derived_combat_stats'] ?? []);
        $dst=(array)($dm['derived_combat_stats'] ?? []);

        $atk = (int)($ast['attack'] ?? 30);
        $defv = (int)($dst['defense'] ?? 28);
        if($acat==='pulse') $atk=(int)($ast['tech'] ?? $atk);
        if($acat==='flux') $atk=(int)round(((int)($ast['attack'] ?? 30) + (int)($ast['tech'] ?? 30))*0.5);

        $power = (int)($move['power'] ?? 20);
        $growth = (string)($am['current_growth_stage'] ?? 'rookie');
        $gmod = prismtek_prism_growth_mod($growth);

        $person = sanitize_key((string)($am['personality'] ?? 'brave'));
        $bond = (int)($am['bond'] ?? 20);

        $pm = 1.0;
        if($person==='brave' && $acat==='blade') $pm += 0.10;
        if($person==='calm' && $acat==='shield') $pm += 0.08;
        if($person==='curious' && $acat==='pulse') $pm += 0.10;
        if($person==='chaotic' && $acat==='flux') $pm += 0.12;

        $bondm = 1.0 + min(0.18, $bond/560);

        $statusAtk = (array)($m['combat']['status'][$att] ?? []);
        $statusDef = (array)($m['combat']['status'][$def] ?? []);
        if(!empty($statusAtk['mark_boost'])) $pm += 0.08;
        if(!empty($statusDef['guard'])) $pm -= 0.22;
        if(!empty($statusDef['exposed'])) $pm += 0.16;

        $inst = (int)($m['combat']['instability'][$att] ?? 0);
        $instm = 1.0;
        if($inst >= 35 && $inst < 70) $instm += 0.10;
        if($inst >= 70) $instm += 0.17;

        $raw = ($power * max(8,$atk) / max(8,$defv)) * $gmod * $pm * $bondm * $instm;
        return max(1,(int)round($raw));
    }

    function prismtek_prism_apply_turn_move(&$m, $uid, $opp, $move, &$log){
        $model = $m['prismModels'][$uid] ?? [];
        $mv = is_array($move) ? $move : [];
        $name=(string)($mv['name'] ?? 'Unknown');
        $cat=sanitize_key((string)($mv['category'] ?? 'blade'));

        $energyCost = (int)($mv['energy_cost'] ?? 8);
        $acc = (int)($mv['accuracy'] ?? 95);

        $m['combat']['energy'][$uid] = max(0,(int)($m['combat']['energy'][$uid] ?? 0));
        if((int)$m['combat']['energy'][$uid] < $energyCost){
            $log[] = prismtek_pvp_user_tag($uid).' tried '.$name.' but lacked energy.';
            return;
        }
        $m['combat']['energy'][$uid] -= $energyCost;

        // never mutate growth stage in combat
        $growth = (string)($model['current_growth_stage'] ?? 'rookie');

        // instability gain/relief by form
        $gain = (int)($mv['stability_effect'] ?? 0);
        $m['combat']['instability'][$uid] = max(0,min(100,(int)($m['combat']['instability'][$uid] ?? 0) + $gain));

        // personality affects instability trend
        $person = sanitize_key((string)($model['personality'] ?? 'brave'));
        if($person==='calm') $m['combat']['instability'][$uid] = max(0, (int)$m['combat']['instability'][$uid]-2);
        if($person==='chaotic') $m['combat']['instability'][$uid] = min(100, (int)$m['combat']['instability'][$uid]+2);

        $statusAtt = (array)($m['combat']['status'][$uid] ?? []);
        $statusDef = (array)($m['combat']['status'][$opp] ?? []);

        $hitRoll = mt_rand(1,100);
        if($hitRoll > $acc){
            $log[] = prismtek_pvp_user_tag($uid).' used '.$name.' but missed.';
            return;
        }

        // High instability chaos (without changing growth stage)
        $inst = (int)($m['combat']['instability'][$uid] ?? 0);
        if($inst >= 70){
            if(mt_rand(1,100) <= 16){
                $self=mt_rand(4,10);
                $m['hp'][$uid] = max(0,(int)($m['hp'][$uid] ?? 0)-$self);
                $log[] = prismtek_pvp_user_tag($uid).' suffered instability backlash ('.$self.').';
            }
            if(mt_rand(1,100) <= 12){
                $statusAtt['disrupted']=1;
                $log[] = prismtek_pvp_user_tag($uid).' core disrupted.';
            }
        }

        if(!empty($statusAtt['disrupted'])){
            $statusAtt['disrupted']=0;
            $m['combat']['status'][$uid]=$statusAtt;
            $log[] = prismtek_pvp_user_tag($uid).' lost control this turn.';
            return;
        }

        $dmg = prismtek_prism_damage_calc($uid,$opp,$mv,$m);

        // Form architecture effects (separate from growth stage)
        if($cat==='shield') $dmg = (int)max(1,floor($dmg*0.72));
        if($cat==='blade') $statusAtt['exposed']=1;
        if($cat==='pulse') $dmg = (int)max(1,floor($dmg*0.94));
        if($cat==='flux'){
            $dmg = (int)round($dmg*1.16);
            $m['combat']['instability'][$uid] = min(100,(int)$m['combat']['instability'][$uid]+5);
        }

        if(!empty($statusDef['guard'])) $dmg = (int)max(1,floor($dmg*0.58));

        $m['hp'][$opp] = max(0, (int)($m['hp'][$opp] ?? 0) - $dmg);

        // status effects from move
        $eff = sanitize_key((string)($mv['status_effect'] ?? 'none'));
        if($eff==='guard') $statusAtt['guard']=1;
        if($eff==='guard_reflect'){ $statusAtt['guard']=1; $statusAtt['reflect']=1; }
        if($eff==='stabilize') $m['combat']['instability'][$uid]=max(0,(int)$m['combat']['instability'][$uid]-8);
        if($eff==='drain'){ $heal=max(1,(int)floor($dmg*0.2)); $m['hp'][$uid]=min((int)($m['maxHp'][$uid]??100),(int)$m['hp'][$uid]+$heal); }
        if($eff==='mark') $statusDef['marked']=1;
        if($eff==='focus') $statusAtt['focus']=1;
        if($eff==='chaos' && mt_rand(1,100)<=20) $statusDef['disrupted']=1;
        if($eff==='disrupt' && mt_rand(1,100)<=30) $statusDef['disrupted']=1;
        if($eff==='unstable_crit' && mt_rand(1,100)<=24){
            $bonus=mt_rand(5,10);
            $m['hp'][$opp]=max(0,(int)$m['hp'][$opp]-$bonus);
            $log[]='Chaos crit bonus +'.$bonus.'.';
        }

        $m['combat']['status'][$uid]=$statusAtt;
        $m['combat']['status'][$opp]=$statusDef;

        $log[] = prismtek_pvp_user_tag($uid).' used '.$name.' ['.$cat.', stage '.$growth.'] for '.$dmg.'.';
    }

    function prismtek_prism_resolve_strict_turn(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        prismtek_prism_match_boot($m);
        $ma = $m['moves'][$a] ?? null;
        $mb = $m['moves'][$b] ?? null;
        if(!$ma || !$mb) return;

        $moveA = prismtek_prism_move_from_input($ma, $m['prismModels'][$a]);
        $moveB = prismtek_prism_move_from_input($mb, $m['prismModels'][$b]);
        if(!$moveA || !$moveB){
            $m['log'][]='Move validation failed.';
            $m['moves']=[];
            return;
        }

        $ord=[
          ['uid'=>$a,'opp'=>$b,'move'=>$moveA,'prio'=>(int)($moveA['priority']??0),'spd'=>prismtek_prism_speed_for_turn($a,$moveA,$m)],
          ['uid'=>$b,'opp'=>$a,'move'=>$moveB,'prio'=>(int)($moveB['priority']??0),'spd'=>prismtek_prism_speed_for_turn($b,$moveB,$m)],
        ];
        usort($ord,function($x,$y){
            if($x['prio']!==$y['prio']) return $y['prio']<=>$x['prio'];
            if($x['spd']!==$y['spd']) return $y['spd']<=>$x['spd'];
            return mt_rand(0,1)?1:-1;
        });

        $log=[];
        foreach($ord as $t){
            $uid=(int)$t['uid']; $opp=(int)$t['opp'];
            if((int)($m['hp'][$uid]??0)<=0 || (int)($m['hp'][$opp]??0)<=0) continue;
            prismtek_prism_apply_turn_move($m,$uid,$opp,$t['move'],$log);
        }

        // end-of-turn cleanup (temporary statuses)
        foreach([$a,$b] as $uid){
            $st=(array)($m['combat']['status'][$uid] ?? []);
            if(isset($st['guard'])) unset($st['guard']);
            if(isset($st['mark_boost'])) unset($st['mark_boost']);
            if(isset($st['focus'])) unset($st['focus']);
            $m['combat']['status'][$uid]=$st;
        }

        // mark->boost for next attacker cycle
        foreach([[$a,$b],[$b,$a]] as $pair){
            [$u,$o]=$pair;
            $os=(array)($m['combat']['status'][$o] ?? []);
            $us=(array)($m['combat']['status'][$u] ?? []);
            if(!empty($os['marked'])){ $us['mark_boost']=1; unset($os['marked']); }
            $m['combat']['status'][$o]=$os; $m['combat']['status'][$u]=$us;
        }

        $m['moves']=[];
        $m['combat']['turn']=(int)($m['combat']['turn'] ?? 1)+1;
        $m['round']=(int)($m['round'] ?? 0)+1;
        $m['log']=array_slice(array_merge((array)($m['log'] ?? []),$log),-80);

        $ha=(int)($m['hp'][$a] ?? 0); $hb=(int)($m['hp'][$b] ?? 0);
        if($ha<=0||$hb<=0){
            $m['done']=true; $m['status']='done';
            if($ha===$hb){ $m['winner']=0; $m['result']='draw'; $m['log'][]='Draw.'; }
            else {
                $w = $ha>$hb ? $a : $b;
                $l = $w===$a ? $b : $a;
                $m['winner']=$w; $m['result']='win';
                prismtek_battle_v2_set_rating($w, prismtek_battle_v2_rating($w)+20);
                prismtek_battle_v2_set_rating($l, prismtek_battle_v2_rating($l)-14);
                $m['log'][]='Winner: '.prismtek_pvp_user_tag($w);
            }
            if(function_exists('prismtek_pvp_history_add') && function_exists('prismtek_pvp_enrich_state')) prismtek_pvp_history_add(prismtek_pvp_enrich_state($m));
        }
    }
}

// Migration hook: ensure combat model exists at prism generation/adopt/update endpoints.
add_filter('rest_request_after_callbacks', function($response, $handler, $request){
    if (!($request instanceof WP_REST_Request)) return $response;
    $route=(string)$request->get_route();
    $uid=get_current_user_id();
    if(!$uid) return $response;

    if ($route === '/prismtek/v1/pet/adopt' && strtoupper((string)$request->get_method())==='POST'){
        $species=sanitize_key((string)$request->get_param('species'));
        $personality=sanitize_key((string)$request->get_param('personality'));
        prismtek_prism_combat_get_or_create($uid,true,['species'=>$species,'personality'=>$personality]);
    } elseif ($route === '/prismtek/v1/pet/rpg' || $route === '/prismtek/v1/prism/v2/profile'){
        prismtek_prism_combat_get_or_create($uid,false,[]);
    }
    return $response;
}, 10, 3);

// Public model endpoint for UI (moveset + persistent growth stage shown independently)
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/prism/combat-model',[
      'methods'=>'GET',
      'permission_callback'=>'__return_true',
      'callback'=>function(){
          $uid=get_current_user_id();
          if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
          $model=prismtek_prism_combat_get_or_create($uid,false,[]);
          return rest_ensure_response(['ok'=>true,'model'=>$model]);
      }
    ]);
});

// Strict turn-based intercept for move-pro (choose move -> resolve order -> resolve turn)
add_filter('rest_pre_dispatch', function($result, $server, $request){
    if ($result !== null) return $result;
    if (!($request instanceof WP_REST_Request)) return $result;
    $route=(string)$request->get_route();
    $method=strtoupper((string)$request->get_method());
    if($route !== '/prismtek/v1/pet/pvp/move-pro' || $method !== 'POST') return $result;

    $uid=get_current_user_id();
    if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

    $id=sanitize_text_field((string)$request->get_param('matchId'));
    $move=sanitize_key((string)$request->get_param('move'));
    if($id==='') return new WP_REST_Response(['ok'=>false,'error'=>'match_required'],400);

    $matches=prismtek_pvp_get_matches();
    if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
    $m=$matches[$id];
    if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
    if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
    if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);

    prismtek_prism_match_boot($m);
    $model = $m['prismModels'][$uid] ?? prismtek_prism_combat_get_or_create($uid,false,[]);
    $legal = prismtek_prism_move_from_input($move,$model);
    if(!$legal) return new WP_REST_Response(['ok'=>false,'error'=>'illegal_move_for_prism'],400);

    $m['moves'][$uid] = (string)$legal['id'];
    $m['queue'][]=['uid'=>$uid,'move'=>(string)$legal['id'],'at'=>time(),'mode'=>'strict-turn'];
    $m['queue']=array_slice((array)$m['queue'],-20);
    $m['updatedAt']=time();

    prismtek_prism_resolve_strict_turn($m);

    $matches[$id]=$m;
    prismtek_pvp_set_matches($matches);

    return rest_ensure_response([
      'ok'=>true,
      'state'=>prismtek_pvp_enrich_state($m),
      'rating'=>prismtek_battle_v2_rating($uid),
      'moveset'=>$model['generated_moveset'],
      'growthStage'=>$model['current_growth_stage'],
    ]);
}, 1, 3);

// UI: show persistent growth stage + generated moveset and bind PvP buttons to real move IDs.
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-strict-moveset-ui">
    (()=>{
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s);
      const card=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(!card || q('#prism-moveset-box',card)) return;

      const box=document.createElement('div');
      box.id='prism-moveset-box';
      box.style.cssText='margin-top:8px;border:1px solid #5f6ad1;background:#0f1538;padding:8px;font-size:12px;color:#dbe5ff';
      box.innerHTML='<div id="prism-growth-stage" style="margin-bottom:6px">Growth Stage: ...</div><div id="prism-moves-list">Loading moveset...</div>';
      card.appendChild(box);

      function moveRow(m){
        const eff = m.status_effect||'none';
        return '<div style="padding:5px;border:1px solid #4f5aba;margin-bottom:5px">'
          +'<b>'+m.name+'</b> <span style="opacity:.8">['+m.category+']</span><br>'
          +'Power '+m.power+' · Acc '+m.accuracy+'% · Prio '+m.priority+' · Energy '+m.energy_cost+' · Stability '+(m.stability_effect>=0?'+':'')+m.stability_effect+'<br>'
          +'<span style="opacity:.85">Effect: '+eff+'</span></div>';
      }

      async function loadModel(){
        try{
          const r=await fetch(API+'prism/combat-model?ts='+Date.now(),{credentials:'include'});
          const j=await r.json();
          if(!r.ok||!j.ok||!j.model) return;
          const model=j.model;
          q('#prism-growth-stage',box).textContent='Growth Stage: '+(model.current_growth_stage||'rookie')+' (persistent)';
          const moves=Array.isArray(model.generated_moveset)?model.generated_moveset:[];
          q('#prism-moves-list',box).innerHTML=moves.map(moveRow).join('')||'No moves.';

          const btns=[...card.querySelectorAll('.pvp-m')];
          btns.forEach((b,i)=>{
            const m=moves[i];
            if(!m) return;
            b.setAttribute('data-m', m.id);
            b.textContent = m.name;
            b.title = m.category+' · P'+m.power+' · A'+m.accuracy+' · E'+m.energy_cost;
          });
        }catch{}
      }

      loadModel();
      const mo=new MutationObserver(()=>loadModel());
      mo.observe(card,{childList:true,subtree:true});
    })();
    </script>
    <?php
}, 1000002700);

// ===== Prism Intent + Unified AI Battles (strict resolver path, 2026-03-10) =====
if (!function_exists('prismtek_prism_intent_list')) {
    function prismtek_prism_intent_list(){ return ['aggress','guard','focus','stabilize','adapt']; }

    function prismtek_prism_form_list(){ return ['blade','shield','pulse','flux']; }

    function prismtek_prism_personality_intent_bias($personality, $intent){
        $p=sanitize_key((string)$personality);
        $i=sanitize_key((string)$intent);
        $map=[
          'brave'=>['aggress'=>0.18,'guard'=>0.02,'focus'=>0.05,'stabilize'=>0.02,'adapt'=>0.04],
          'calm'=>['aggress'=>0.02,'guard'=>0.16,'focus'=>0.05,'stabilize'=>0.18,'adapt'=>0.06],
          'curious'=>['aggress'=>0.05,'guard'=>0.03,'focus'=>0.12,'stabilize'=>0.04,'adapt'=>0.16],
          'chaotic'=>['aggress'=>0.16,'guard'=>-0.02,'focus'=>0.08,'stabilize'=>-0.06,'adapt'=>0.1],
        ];
        return (float)($map[$p][$i] ?? 0.0);
    }

    function prismtek_prism_match_boot_intent(&$m){
        prismtek_prism_match_boot($m);
        $a=(int)$m['a']; $b=(int)$m['b'];
        if(empty($m['combat']['intent']) || !is_array($m['combat']['intent'])) $m['combat']['intent']=[];
        if(empty($m['combat']['form']) || !is_array($m['combat']['form'])) $m['combat']['form']=[];
        foreach([$a,$b] as $uid){
            if(!isset($m['combat']['intent'][$uid])) $m['combat']['intent'][$uid]='adapt';
            if(!isset($m['combat']['form'][$uid])){
                $default=(string)($m['prismModels'][$uid]['form'] ?? 'blade');
                if(!in_array($default, prismtek_prism_form_list(), true)) $default='blade';
                $m['combat']['form'][$uid]=$default;
            }
        }
    }

    function prismtek_prism_apply_form_switch(&$m, $uid, $newForm, &$log){
        $newForm=sanitize_key((string)$newForm);
        if(!in_array($newForm, prismtek_prism_form_list(), true)) return;
        $cur=(string)($m['combat']['form'][$uid] ?? 'blade');
        if($cur===$newForm) return;
        $cost=5;
        $m['combat']['energy'][$uid]=max(0,(int)($m['combat']['energy'][$uid] ?? 0));
        if((int)$m['combat']['energy'][$uid] < $cost){
            $log[] = prismtek_pvp_user_tag($uid).' failed to switch form (low energy).';
            return;
        }
        $m['combat']['energy'][$uid]-=$cost;
        $m['combat']['form'][$uid]=$newForm;
        $m['prismModels'][$uid]['form']=$newForm;
        $log[] = prismtek_pvp_user_tag($uid).' switched form to '.strtoupper($newForm).'.';
    }

    function prismtek_prism_intent_mods($m, $uid, $opp){
        $intent=sanitize_key((string)($m['combat']['intent'][$uid] ?? 'adapt'));
        $person=sanitize_key((string)($m['prismModels'][$uid]['personality'] ?? 'brave'));
        $bias=prismtek_prism_personality_intent_bias($person,$intent);
        $mods=['dmg'=>1.0,'def'=>1.0,'acc'=>0,'inst'=>0,'energy'=>1.0,'spd'=>0,'note'=>''];

        if($intent==='aggress'){
            $mods['dmg'] += 0.16 + $bias;
            $mods['def'] -= 0.10;
            $mods['inst'] += 5;
            $mods['note']='Aggress';
        } elseif($intent==='guard'){
            $mods['dmg'] -= 0.12;
            $mods['def'] += 0.18 + $bias;
            $mods['inst'] -= 3;
            $mods['note']='Guard';
        } elseif($intent==='focus'){
            $mods['acc'] += (int)round(10 + $bias*28);
            $mods['spd'] += 4;
            $mods['inst'] += 2;
            $mods['note']='Focus';
        } elseif($intent==='stabilize'){
            $mods['dmg'] -= 0.08;
            $mods['def'] += 0.06;
            $mods['inst'] -= (int)round(8 + $bias*24);
            $mods['note']='Stabilize';
        } else { // adapt
            $myForm=(string)($m['combat']['form'][$uid] ?? 'blade');
            $oppForm=(string)($m['combat']['form'][$opp] ?? 'blade');
            $edge=[
              'blade'=>['shield'=>0.10,'pulse'=>0.06],
              'shield'=>['flux'=>0.12,'blade'=>0.08],
              'pulse'=>['blade'=>0.11,'shield'=>0.05],
              'flux'=>['pulse'=>0.13,'shield'=>0.07],
            ];
            $bonus=(float)($edge[$myForm][$oppForm] ?? 0.04);
            $mods['dmg'] += $bonus + $bias;
            $mods['energy'] -= 0.08;
            $mods['note']='Adapt';
        }
        return $mods;
    }

    function prismtek_prism_form_mods($form){
        $f=sanitize_key((string)$form);
        $mods=[
          'blade'=>['dmg'=>1.16,'def'=>0.90,'acc'=>0,'inst'=>6],
          'shield'=>['dmg'=>0.84,'def'=>1.2,'acc'=>2,'inst'=>-6],
          'pulse'=>['dmg'=>0.94,'def'=>1.0,'acc'=>8,'inst'=>2],
          'flux'=>['dmg'=>1.24,'def'=>0.82,'acc'=>-8,'inst'=>11],
        ];
        return $mods[$f] ?? $mods['blade'];
    }

    function prismtek_prism_damage_calc_intent($att,$def,$move,$m){
        $base = prismtek_prism_damage_calc($att,$def,$move,$m);
        $formA=(string)($m['combat']['form'][$att] ?? 'blade');
        $formD=(string)($m['combat']['form'][$def] ?? 'blade');
        $fA=prismtek_prism_form_mods($formA);
        $fD=prismtek_prism_form_mods($formD);
        $iA=prismtek_prism_intent_mods($m,$att,$def);
        $iD=prismtek_prism_intent_mods($m,$def,$att);

        $dmg=$base;
        $dmg=(int)round($dmg * (float)$fA['dmg'] * (float)$iA['dmg']);
        $defFactor=max(0.5, (float)$fD['def'] * (float)$iD['def']);
        $dmg=(int)round($dmg / $defFactor);
        if($formD==='shield') $dmg=(int)floor($dmg*0.92);
        if($formA==='flux' && mt_rand(1,100)<=18) $dmg=(int)round($dmg*1.28);
        return max(1,$dmg);
    }

    function prismtek_prism_apply_turn_move_intent(&$m, $uid, $opp, $move, &$log){
        $mv = is_array($move)?$move:[];
        $name=(string)($mv['name'] ?? 'Unknown Move');
        $acc=(int)($mv['accuracy'] ?? 95);
        $cost=(int)($mv['energy_cost'] ?? 8);

        $iA=prismtek_prism_intent_mods($m,$uid,$opp);
        $fA=prismtek_prism_form_mods((string)($m['combat']['form'][$uid] ?? 'blade'));

        $effCost=(int)max(1, round($cost * max(0.72, (float)$iA['energy'])));
        if((int)($m['combat']['energy'][$uid] ?? 0) < $effCost){
            $log[] = prismtek_pvp_user_tag($uid).' tried '.$name.' but lacked energy.';
            return;
        }
        $m['combat']['energy'][$uid]-=$effCost;

        // instability drift from move + form + intent
        $instGain=(int)($mv['stability_effect'] ?? 0) + (int)($fA['inst'] ?? 0) + (int)($iA['inst'] ?? 0);
        $m['combat']['instability'][$uid]=max(0,min(100,(int)($m['combat']['instability'][$uid] ?? 0)+$instGain));

        // personality drift
        $person=sanitize_key((string)($m['prismModels'][$uid]['personality'] ?? 'brave'));
        if($person==='calm') $m['combat']['instability'][$uid]=max(0,(int)$m['combat']['instability'][$uid]-2);
        if($person==='chaotic') $m['combat']['instability'][$uid]=min(100,(int)$m['combat']['instability'][$uid]+2);

        $stA=(array)($m['combat']['status'][$uid] ?? []);
        $stD=(array)($m['combat']['status'][$opp] ?? []);

        $acc = max(35,min(100, $acc + (int)($iA['acc'] ?? 0) + (int)($fA['acc'] ?? 0)));
        if(mt_rand(1,100) > $acc){
            $log[] = prismtek_pvp_user_tag($uid).' used '.$name.' but missed. ['.strtoupper((string)$m['combat']['intent'][$uid]).' · '.strtoupper((string)$m['combat']['form'][$uid]).']';
            return;
        }

        // chaos checks
        $inst=(int)($m['combat']['instability'][$uid] ?? 0);
        if($inst >= 70 && mt_rand(1,100)<=14){
            $self=mt_rand(4,11);
            $m['hp'][$uid]=max(0,(int)$m['hp'][$uid]-$self);
            $log[] = prismtek_pvp_user_tag($uid).' instability backlash '.$self.'.';
        }

        $dmg=prismtek_prism_damage_calc_intent($uid,$opp,$mv,$m);

        if(!empty($stD['guard'])) $dmg=(int)max(1,floor($dmg*0.58));
        $m['hp'][$opp]=max(0,(int)($m['hp'][$opp] ?? 0)-$dmg);

        // status effects (can explicitly alter form if needed)
        $eff=sanitize_key((string)($mv['status_effect'] ?? 'none'));
        if($eff==='guard') $stA['guard']=1;
        if($eff==='guard_reflect'){ $stA['guard']=1; $stA['reflect']=1; }
        if($eff==='stabilize') $m['combat']['instability'][$uid]=max(0,(int)$m['combat']['instability'][$uid]-8);
        if($eff==='drain'){ $heal=max(1,(int)floor($dmg*0.2)); $m['hp'][$uid]=min((int)($m['maxHp'][$uid]??100),(int)$m['hp'][$uid]+$heal); }
        if($eff==='mark') $stD['marked']=1;
        if($eff==='focus') $stA['focus']=1;
        if($eff==='chaos' && mt_rand(1,100)<=20) $stD['disrupted']=1;
        if($eff==='disrupt' && mt_rand(1,100)<=30) $stD['disrupted']=1;
        if($eff==='form_blade') $m['combat']['form'][$uid]='blade';
        if($eff==='form_shield') $m['combat']['form'][$uid]='shield';
        if($eff==='form_pulse') $m['combat']['form'][$uid]='pulse';
        if($eff==='form_flux') $m['combat']['form'][$uid]='flux';

        $m['combat']['status'][$uid]=$stA;
        $m['combat']['status'][$opp]=$stD;

        $growth=(string)($m['prismModels'][$uid]['current_growth_stage'] ?? 'rookie');
        $log[] = prismtek_pvp_user_tag($uid).' intent='.strtoupper((string)$m['combat']['intent'][$uid]).' form='.strtoupper((string)$m['combat']['form'][$uid]).' used '.$name.' [stage '.$growth.'] for '.$dmg.'.';
    }

    function prismtek_prism_resolve_strict_turn_intent(&$m){
        $a=(int)$m['a']; $b=(int)$m['b'];
        prismtek_prism_match_boot_intent($m);
        $ma=$m['moves'][$a] ?? null;
        $mb=$m['moves'][$b] ?? null;
        if(!$ma || !$mb) return;

        $moveA = prismtek_prism_move_from_input($ma, $m['prismModels'][$a]);
        $moveB = prismtek_prism_move_from_input($mb, $m['prismModels'][$b]);
        if(!$moveA || !$moveB){ $m['log'][]='Move validation failed.'; $m['moves']=[]; return; }

        $ia=prismtek_prism_intent_mods($m,$a,$b); $ib=prismtek_prism_intent_mods($m,$b,$a);
        $ord=[
          ['uid'=>$a,'opp'=>$b,'move'=>$moveA,'prio'=>(int)($moveA['priority']??0),'spd'=>prismtek_prism_speed_for_turn($a,$moveA,$m)+(int)($ia['spd']??0)],
          ['uid'=>$b,'opp'=>$a,'move'=>$moveB,'prio'=>(int)($moveB['priority']??0),'spd'=>prismtek_prism_speed_for_turn($b,$moveB,$m)+(int)($ib['spd']??0)],
        ];
        usort($ord,function($x,$y){ if($x['prio']!==$y['prio']) return $y['prio']<=>$x['prio']; if($x['spd']!==$y['spd']) return $y['spd']<=>$x['spd']; return mt_rand(0,1)?1:-1; });

        $log=[];
        foreach($ord as $t){
            $uid=(int)$t['uid']; $opp=(int)$t['opp'];
            if((int)($m['hp'][$uid]??0)<=0 || (int)($m['hp'][$opp]??0)<=0) continue;
            if((int)(($m['combat']['status'][$uid]['disrupted'] ?? 0))>0){
                $m['combat']['status'][$uid]['disrupted']=0;
                $log[] = prismtek_pvp_user_tag($uid).' was disrupted and skipped turn.';
                continue;
            }
            prismtek_prism_apply_turn_move_intent($m,$uid,$opp,$t['move'],$log);
        }

        // end turn status decay
        foreach([$a,$b] as $uid){
            $st=(array)($m['combat']['status'][$uid] ?? []);
            unset($st['guard']); unset($st['mark_boost']); unset($st['focus']);
            $m['combat']['status'][$uid]=$st;
        }

        foreach([[$a,$b],[$b,$a]] as $pair){ [$u,$o]=$pair; $os=(array)($m['combat']['status'][$o] ?? []); $us=(array)($m['combat']['status'][$u] ?? []); if(!empty($os['marked'])){ $us['mark_boost']=1; unset($os['marked']); } $m['combat']['status'][$o]=$os; $m['combat']['status'][$u]=$us; }

        $m['moves']=[];
        $m['combat']['turn']=(int)($m['combat']['turn'] ?? 1)+1;
        $m['round']=(int)($m['round'] ?? 0)+1;
        $m['log']=array_slice(array_merge((array)($m['log'] ?? []),$log),-100);

        $ha=(int)($m['hp'][$a] ?? 0); $hb=(int)($m['hp'][$b] ?? 0);
        if($ha<=0||$hb<=0){
            $m['done']=true; $m['status']='done';
            if($ha===$hb){ $m['winner']=0; $m['result']='draw'; $m['log'][]='Draw.'; }
            else {
                $w = $ha>$hb ? $a : $b; $l = $w===$a ? $b : $a;
                $m['winner']=$w; $m['result']='win';
                if($w>0 && $l>0){ prismtek_battle_v2_set_rating($w, prismtek_battle_v2_rating($w)+20); prismtek_battle_v2_set_rating($l, prismtek_battle_v2_rating($l)-14); }
                $m['log'][]='Winner: '.($w===0?'AI':prismtek_pvp_user_tag($w));
            }
            if(function_exists('prismtek_pvp_history_add') && function_exists('prismtek_pvp_enrich_state')) prismtek_pvp_history_add(prismtek_pvp_enrich_state($m));
        }
    }

    function prismtek_prism_ai_pick_form($m,$aiUid,$oppUid){
        $inst=(int)($m['combat']['instability'][$aiUid] ?? 20);
        $ene=(int)($m['combat']['energy'][$aiUid] ?? 70);
        $hp=(int)($m['hp'][$aiUid] ?? 80); $max=(int)($m['maxHp'][$aiUid] ?? 100);
        $oppHp=(int)($m['hp'][$oppUid] ?? 80); $oppMax=(int)($m['maxHp'][$oppUid] ?? 100);
        $person=sanitize_key((string)($m['prismModels'][$aiUid]['personality'] ?? 'brave'));

        if($inst>=72) return 'shield';
        if($hp < (int)floor($max*0.35) && $ene>=10) return 'shield';
        if($oppHp < (int)floor($oppMax*0.3) && $ene>=14) return 'flux';
        if($person==='curious') return (mt_rand(1,100)<=55)?'pulse':'blade';
        if($person==='calm') return (mt_rand(1,100)<=60)?'shield':'pulse';
        if($person==='chaotic') return (mt_rand(1,100)<=45)?'flux':'blade';
        return 'blade';
    }

    function prismtek_prism_ai_pick_intent($m,$aiUid,$oppUid){
        $inst=(int)($m['combat']['instability'][$aiUid] ?? 20);
        $ene=(int)($m['combat']['energy'][$aiUid] ?? 70);
        $hp=(int)($m['hp'][$aiUid] ?? 80); $max=(int)($m['maxHp'][$aiUid] ?? 100);
        $person=sanitize_key((string)($m['prismModels'][$aiUid]['personality'] ?? 'brave'));

        if($inst>=75) return 'stabilize';
        if($hp < (int)floor($max*0.38)) return 'guard';
        if($ene<18) return 'adapt';
        if($person==='brave') return (mt_rand(1,100)<=62)?'aggress':'focus';
        if($person==='calm') return (mt_rand(1,100)<=58)?'guard':'stabilize';
        if($person==='curious') return (mt_rand(1,100)<=62)?'adapt':'focus';
        if($person==='chaotic') return (mt_rand(1,100)<=54)?'aggress':'adapt';
        return 'adapt';
    }

    function prismtek_prism_ai_pick_move($m,$aiUid,$oppUid){
        $model=$m['prismModels'][$aiUid] ?? [];
        $moves=(array)($model['generated_moveset'] ?? []);
        $ene=(int)($m['combat']['energy'][$aiUid] ?? 0);
        $legal=[];
        foreach($moves as $mv){ if($ene >= (int)($mv['energy_cost'] ?? 8)) $legal[]=$mv; }
        if(empty($legal)) $legal=$moves;
        if(empty($legal)) return null;

        $inst=(int)($m['combat']['instability'][$aiUid] ?? 20);
        $oppHp=(int)($m['hp'][$oppUid] ?? 90);
        $oppMax=(int)($m['maxHp'][$oppUid] ?? 100);
        $form=(string)($m['combat']['form'][$aiUid] ?? 'blade');

        $best=null; $bestScore=-1e9;
        foreach($legal as $mv){
            $score=(float)($mv['power'] ?? 10);
            $score += ((int)($mv['accuracy'] ?? 90)-80)*0.4;
            $score -= (int)($mv['energy_cost'] ?? 8)*0.5;
            $score -= max(0,(int)($mv['stability_effect'] ?? 0))*0.35;
            if($inst>=70) $score -= max(0,(int)($mv['stability_effect'] ?? 0))*0.7;
            if($oppHp < (int)floor($oppMax*0.35)) $score += (int)($mv['power'] ?? 10)*0.45;
            if((string)($mv['category'] ?? '')===$form) $score += 4;
            if((string)($mv['category'] ?? '')==='shield' && $inst>=65) $score += 5;
            if((string)($mv['category'] ?? '')==='pulse' && $ene<24) $score += 3;
            if($score>$bestScore){ $bestScore=$score; $best=$mv; }
        }
        return $best;
    }

    function prismtek_prism_build_ai_model_from_player($playerModel){
        $speciesPool=['sprout','ember','tidal','volt','shade'];
        $personPool=['brave','calm','curious','chaotic'];
        $baseGrowth=(string)($playerModel['current_growth_stage'] ?? 'rookie');
        $roll=mt_rand(1,100);
        $growth=$baseGrowth;
        if($roll<=18) $growth='champion';
        if($roll>=90) $growth='mythic';
        $species=$speciesPool[array_rand($speciesPool)];
        $person=$personPool[array_rand($personPool)];

        $fakeUid=0;
        $model=[
          'version'=>1,
          'species'=>$species,
          'form'=>'blade',
          'current_growth_stage'=>$growth,
          'base_stats'=>['attack'=>28,'defense'=>26,'speed'=>24,'tech'=>26],
          'derived_combat_stats'=>['attack'=>34,'defense'=>32,'speed'=>31,'tech'=>33,'instability_base'=>26],
          'generated_moveset'=>prismtek_prism_pick_moves($species,$person,$growth),
          'personality'=>$person,
          'bond'=>max(18,min(88,(int)($playerModel['bond'] ?? 30) + mt_rand(-8,8))),
          'energy'=>max(40,min(95,(int)($playerModel['energy'] ?? 70) + mt_rand(-6,10))),
          'stability'=>max(28,min(90,(int)($playerModel['stability'] ?? 60) + mt_rand(-12,8))),
          'updatedAt'=>time(),
        ];
        return $model;
    }
}

// Enrich state so AI matches still render cleanly and expose moveset/intent/form details.
add_filter('rest_request_after_callbacks', function($response, $handler, $request){
    if (!($request instanceof WP_REST_Request)) return $response;
    $route=(string)$request->get_route();
    if(!in_array($route,['/prismtek/v1/pet/pvp/state-full','/prismtek/v1/pet/pvp/move-pro','/prismtek/v1/pet/pvp/move-full'],true)) return $response;
    if(!($response instanceof WP_REST_Response)) return $response;
    $data=$response->get_data();
    if(!is_array($data) || empty($data['state']) || !is_array($data['state'])) return $response;

    $s=&$data['state'];
    if(!empty($s['ai']) && !empty($s['participants']) && is_array($s['participants'])){
        if(isset($s['participants']['b'])){
            $s['participants']['b']['id']=0;
            $s['participants']['b']['user']='ai_core';
            $s['participants']['b']['displayName']='Prism AI';
            if(empty($s['participants']['b']['species']) && !empty($s['prismModels'][0]['species'])) $s['participants']['b']['species']=$s['prismModels'][0]['species'];
        }
    }
    if(!empty($s['prismModels']) && is_array($s['prismModels'])){
        $s['ui']=[
          'movesets'=>[],
          'growthStages'=>[],
          'forms'=>$s['combat']['form'] ?? [],
          'intents'=>$s['combat']['intent'] ?? [],
        ];
        foreach($s['prismModels'] as $uid=>$model){
            if(!is_array($model)) continue;
            $s['ui']['movesets'][(string)$uid]=array_values((array)($model['generated_moveset'] ?? []));
            $s['ui']['growthStages'][(string)$uid]=(string)($model['current_growth_stage'] ?? 'rookie');
        }
    }

    $response->set_data($data);
    return $response;
}, 10, 3);

// AI battle entrypoint (unified with same PvP resolver/match structure).
add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/ai/start',[
      'methods'=>'POST',
      'permission_callback'=>'__return_true',
      'callback'=>function(){
          $uid=get_current_user_id();
          if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
          if(!function_exists('prismtek_pvp_get_matches')) return new WP_REST_Response(['ok'=>false,'error'=>'pvp_unavailable'],500);

          $matches=prismtek_pvp_get_matches();
          $id='ai-'.wp_generate_uuid4();

          $playerModel=prismtek_prism_combat_get_or_create($uid,false,[]);
          $aiModel=prismtek_prism_build_ai_model_from_player($playerModel);

          $maxA=max(70, prismtek_pvp_hp_from_uid($uid));
          $maxB=max(70, (int)round($maxA + mt_rand(-8,10)));

          $m=[
            'id'=>$id,
            'a'=>$uid,
            'b'=>0,
            'status'=>'active',
            'done'=>false,
            'round'=>1,
            'maxHp'=>[$uid=>$maxA,0=>$maxB],
            'hp'=>[$uid=>$maxA,0=>$maxB],
            'guard'=>[$uid=>0,0=>0],
            'charge'=>[$uid=>0,0=>0],
            'moves'=>[],
            'queue'=>[],
            'log'=>['AI battle started. Strict turn rules active.'],
            'updatedAt'=>time(),
            'ai'=>true,
            'prismModels'=>[$uid=>$playerModel,0=>$aiModel],
            'combat'=>[
              'turn'=>1,
              'energy'=>[$uid=>(int)($playerModel['energy'] ?? 70),0=>(int)($aiModel['energy'] ?? 70)],
              'instability'=>[$uid=>(int)($playerModel['derived_combat_stats']['instability_base'] ?? 26),0=>(int)($aiModel['derived_combat_stats']['instability_base'] ?? 26)],
              'status'=>[$uid=>[],0=>[]],
              'form'=>[$uid=>(string)($playerModel['form'] ?? 'blade'),0=>(string)($aiModel['form'] ?? 'blade')],
              'intent'=>[$uid=>'adapt',0=>'adapt'],
            ],
          ];

          $matches[$id]=$m;
          prismtek_pvp_set_matches($matches);

          return rest_ensure_response(['ok'=>true,'matchId'=>$id,'state'=>prismtek_pvp_enrich_state($m)]);
      }
    ]);
});

// Highest-priority resolver for move-pro: intent + explicit form switch + AI autoplayer.
add_filter('rest_pre_dispatch', function($result, $server, $request){
    if ($result !== null) return $result;
    if (!($request instanceof WP_REST_Request)) return $result;
    $route=(string)$request->get_route();
    $method=strtoupper((string)$request->get_method());
    if($route !== '/prismtek/v1/pet/pvp/move-pro' || $method !== 'POST') return $result;

    $uid=get_current_user_id();
    if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

    $id=sanitize_text_field((string)$request->get_param('matchId'));
    $move=sanitize_key((string)$request->get_param('move'));
    $intent=sanitize_key((string)$request->get_param('intent'));
    $form=sanitize_key((string)$request->get_param('form'));

    if($id==='') return new WP_REST_Response(['ok'=>false,'error'=>'match_required'],400);

    $matches=prismtek_pvp_get_matches();
    if(empty($matches[$id])) return new WP_REST_Response(['ok'=>false,'error'=>'match_not_found'],404);
    $m=$matches[$id];
    if((int)$m['a']!==$uid && (int)$m['b']!==$uid) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden'],403);
    if(!empty($m['done'])) return rest_ensure_response(['ok'=>true,'state'=>prismtek_pvp_enrich_state($m)]);
    if(($m['status'] ?? '')==='pending') return new WP_REST_Response(['ok'=>false,'error'=>'awaiting_accept'],400);

    prismtek_prism_match_boot_intent($m);

    $model = $m['prismModels'][$uid] ?? prismtek_prism_combat_get_or_create($uid,false,[]);
    $legal = prismtek_prism_move_from_input($move,$model);
    if(!$legal) return new WP_REST_Response(['ok'=>false,'error'=>'illegal_move_for_prism'],400);

    if(!in_array($intent, prismtek_prism_intent_list(), true)) $intent='adapt';
    $m['combat']['intent'][$uid]=$intent;

    $log=[];
    if($form && in_array($form, prismtek_prism_form_list(), true)){
        prismtek_prism_apply_form_switch($m,$uid,$form,$log);
    }

    $m['moves'][$uid]=(string)$legal['id'];
    $m['queue'][]=['uid'=>$uid,'move'=>(string)$legal['id'],'intent'=>$intent,'form'=>(string)($m['combat']['form'][$uid] ?? 'blade'),'at'=>time(),'mode'=>'intent-turn'];
    $m['queue']=array_slice((array)$m['queue'],-30);
    $m['updatedAt']=time();

    // AI auto-pick using same rules if AI opponent exists.
    $opp = ((int)$m['a']===$uid) ? (int)$m['b'] : (int)$m['a'];
    if(!empty($m['ai']) && (int)$opp===0){
        $aiUid=0; $humanUid=$uid;
        if(empty($m['moves'][$aiUid])){
            $aiForm=prismtek_prism_ai_pick_form($m,$aiUid,$humanUid);
            prismtek_prism_apply_form_switch($m,$aiUid,$aiForm,$log);
            $aiIntent=prismtek_prism_ai_pick_intent($m,$aiUid,$humanUid);
            $m['combat']['intent'][$aiUid]=$aiIntent;
            $aiMove=prismtek_prism_ai_pick_move($m,$aiUid,$humanUid);
            if($aiMove){
                $m['moves'][$aiUid]=(string)$aiMove['id'];
                $m['queue'][]=['uid'=>0,'move'=>(string)$aiMove['id'],'intent'=>$aiIntent,'form'=>(string)($m['combat']['form'][0] ?? 'blade'),'at'=>time(),'mode'=>'ai'];
            }
        }
    }

    if(!empty($log)) $m['log']=array_slice(array_merge((array)($m['log'] ?? []),$log),-100);

    prismtek_prism_resolve_strict_turn_intent($m);

    $matches[$id]=$m;
    prismtek_pvp_set_matches($matches);

    return rest_ensure_response([
      'ok'=>true,
      'state'=>prismtek_pvp_enrich_state($m),
      'rating'=>prismtek_battle_v2_rating($uid),
      'moveset'=>$model['generated_moveset'],
      'growthStage'=>$model['current_growth_stage'],
      'intent'=>$intent,
      'form'=>(string)($m['combat']['form'][$uid] ?? 'blade'),
    ]);
}, 0, 3);

// UI patch: add intent selector + form switch + Battle AI button; submit move+intent+form together.
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <script id="prism-intent-ai-ui-patch">
    (()=>{
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s);
      const card=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(!card || q('#prism-intent-row',card)) return;

      let matchId=(q('#pvp-id',card)?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
      const status=q('#pvp-status',card);

      const row=document.createElement('div');
      row.id='prism-intent-row';
      row.style.cssText='display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-top:8px';
      row.innerHTML=''
        +'<select id="prism-intent-select">'
          +'<option value="adapt">Intent: Adapt</option>'
          +'<option value="aggress">Intent: Aggress</option>'
          +'<option value="guard">Intent: Guard</option>'
          +'<option value="focus">Intent: Focus</option>'
          +'<option value="stabilize">Intent: Stabilize</option>'
        +'</select>'
        +'<select id="prism-form-select">'
          +'<option value="">Form: Keep Current</option>'
          +'<option value="blade">Switch Blade</option>'
          +'<option value="shield">Switch Shield</option>'
          +'<option value="pulse">Switch Pulse</option>'
          +'<option value="flux">Switch Flux</option>'
        +'</select>'
        +'<button id="prism-ai-start" type="button">Battle AI</button>';
      card.appendChild(row);

      async function post(path,payload){
        const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify(payload||{})});
        const j=await r.json().catch(()=>({}));
        return {ok:r.ok,j};
      }

      q('#prism-ai-start',row)?.addEventListener('click', async ()=>{
        if(status) status.textContent='Creating AI battle...';
        const out=await post('pet/pvp/ai/start',{});
        if(!out.ok||!out.j?.ok){ if(status) status.textContent='AI battle failed.'; return; }
        matchId=out.j.matchId||'';
        const idInput=q('#pvp-id',card); if(idInput) idInput.value=matchId;
        if(matchId) localStorage.setItem('prism_pvp_match_id',matchId);
        if(status) status.textContent='AI battle ready.';
      });

      // intercept move button submits to include intent + form
      card.querySelectorAll('.pvp-m').forEach(btn=>{
        btn.addEventListener('click', async (e)=>{
          e.stopImmediatePropagation();
          matchId=(q('#pvp-id',card)?.value||'').trim()||matchId;
          if(!matchId){ if(status) status.textContent='Enter/load Match ID first.'; return; }
          const move=btn.getAttribute('data-m');
          const intent=q('#prism-intent-select',row)?.value||'adapt';
          const form=q('#prism-form-select',row)?.value||'';
          if(status) status.textContent='Submitting turn...';
          const out=await post('pet/pvp/move-pro',{matchId,move,intent,form});
          if(!out.ok||!out.j?.ok){ if(status) status.textContent=(out.j?.error||'Move failed'); return; }
          if(status) status.textContent='Turn submitted: '+(intent.toUpperCase())+(form?(' · '+form.toUpperCase()):'');
        }, true);
      });
    })();
    </script>
    <?php
}, 1000002800);

// ===== Prism Creatures onboarding + explainer layer (non-destructive, 2026-03-10) =====
if (!function_exists('prismtek_prism_build_ai_tutorial_model')) {
    function prismtek_prism_build_ai_tutorial_model($playerModel){
        $species='sprout';
        $person='calm';
        $growth='rookie';
        $moves=prismtek_prism_pick_moves($species,$person,$growth);
        return [
          'version'=>1,
          'species'=>$species,
          'form'=>'shield',
          'current_growth_stage'=>$growth,
          'base_stats'=>['attack'=>20,'defense'=>22,'speed'=>18,'tech'=>20],
          'derived_combat_stats'=>['attack'=>26,'defense'=>28,'speed'=>22,'tech'=>24,'instability_base'=>18],
          'generated_moveset'=>$moves,
          'personality'=>$person,
          'bond'=>max(16,min(70,(int)($playerModel['bond'] ?? 24)-8)),
          'energy'=>72,
          'stability'=>78,
          'updatedAt'=>time(),
        ];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/pet/pvp/ai/tutorial/start',[
      'methods'=>'POST',
      'permission_callback'=>'__return_true',
      'callback'=>function(){
          $uid=get_current_user_id();
          if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
          $matches=prismtek_pvp_get_matches();
          $id='ai-tutorial-'.wp_generate_uuid4();

          $playerModel=prismtek_prism_combat_get_or_create($uid,false,[]);
          $aiModel=prismtek_prism_build_ai_tutorial_model($playerModel);

          $maxA=max(70, prismtek_pvp_hp_from_uid($uid));
          $maxB=max(64, (int)floor($maxA*0.9));

          $m=[
            'id'=>$id,
            'a'=>$uid,
            'b'=>0,
            'status'=>'active',
            'done'=>false,
            'round'=>1,
            'maxHp'=>[$uid=>$maxA,0=>$maxB],
            'hp'=>[$uid=>$maxA,0=>$maxB],
            'guard'=>[$uid=>0,0=>0],
            'charge'=>[$uid=>0,0=>0],
            'moves'=>[],
            'queue'=>[],
            'log'=>[
              'Tutorial Battle started.',
              'Step 1: Pick an INTENT (Adapt is safe).',
              'Step 2: Optionally switch FORM.',
              'Step 3: Choose a MOVE and submit turn.',
              'Reminder: Growth Stage is persistent and never changes in battle.'
            ],
            'updatedAt'=>time(),
            'ai'=>true,
            'tutorial'=>true,
            'prismModels'=>[$uid=>$playerModel,0=>$aiModel],
            'combat'=>[
              'turn'=>1,
              'energy'=>[$uid=>(int)($playerModel['energy'] ?? 70),0=>(int)($aiModel['energy'] ?? 72)],
              'instability'=>[$uid=>(int)($playerModel['derived_combat_stats']['instability_base'] ?? 26),0=>(int)($aiModel['derived_combat_stats']['instability_base'] ?? 18)],
              'status'=>[$uid=>[],0=>[]],
              'form'=>[$uid=>(string)($playerModel['form'] ?? 'blade'),0=>'shield'],
              'intent'=>[$uid=>'adapt',0=>'guard'],
            ],
          ];

          $matches[$id]=$m;
          prismtek_pvp_set_matches($matches);
          return rest_ensure_response(['ok'=>true,'matchId'=>$id,'state'=>prismtek_pvp_enrich_state($m)]);
      }
    ]);
});

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    ?>
    <style id="prism-onboarding-layer-css">
      .prism-start-card{border:2px solid #7a86e2;background:#10173f;color:#e8edff;padding:12px;box-shadow:5px 5px 0 rgba(44,53,114,.7);margin-bottom:12px}
      .prism-start-card h3,.prism-start-card h4{margin:0 0 8px}
      .prism-start-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .prism-pill{display:inline-block;padding:3px 8px;border:1px solid #6f7cdc;background:#121c4c;margin:0 6px 6px 0;font-size:11px}
      .prism-kv{font-size:12px;line-height:1.45;opacity:.95}
      .prism-help-tip{font-size:11px;opacity:.9;border:1px dashed #6070cf;padding:6px;margin-top:6px;background:#0d1436}
      .prism-prompt-box textarea{width:100%;min-height:100px;background:#0b1230;color:#f2f4ff;border:1px solid #5c6ad1;padding:8px}
      .prism-prompt-box select,.prism-prompt-box button{background:#1a2458;color:#fff;border:1px solid #6f7cdc;padding:8px}
      .prism-prompt-row{display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px}
      .prism-turn-hud{margin-top:8px;border:1px solid #5967cf;background:#0e1435;padding:8px;font-size:12px}
      .prism-turn-hud .row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      @media (max-width:760px){.prism-start-grid,.prism-prompt-row,.prism-turn-hud .row{grid-template-columns:1fr}}
    </style>
    <script id="prism-onboarding-layer-js">
    (()=>{
      const API='/wp-json/prismtek/v1/';
      const q=(s,r=document)=>r.querySelector(s);
      const wrap=q('.pph-creatures-wrap')||q('.pph-wrap');
      if(!wrap || q('#prism-start-here-panel')) return;

      const start=document.createElement('article');
      start.className='pph-card prism-start-card';
      start.id='prism-start-here-panel';
      start.innerHTML=''
        +'<h3>Start Here: Prism Creatures</h3>'
        +'<div class="prism-kv">Tamagotchi-style care + tactical turn-based battles. Build bond with your Prism, then battle using <b>Intent</b>, <b>Form</b>, and <b>Moves</b>.</div>'
        +'<div style="margin-top:8px">'
          +'<span class="prism-pill" title="Combat actions you select each turn">Moves = Combat Actions</span>'
          +'<span class="prism-pill" title="Current combat stance">Form = Current Stance</span>'
          +'<span class="prism-pill" title="Turn posture that modifies calculations">Intent = Turn Posture</span>'
          +'<span class="prism-pill" title="Persistent progression value">Growth Stage = Persistent Progression</span>'
        +'</div>'
        +'<div class="prism-help-tip"><b>Critical rule:</b> Growth Stage never changes during battle. Using a move does not change form unless you explicitly switch form (or a specific effect says so).</div>';
      wrap.prepend(start);

      const how=document.createElement('article');
      how.className='pph-card prism-start-card';
      how.id='prism-how-to-play';
      how.innerHTML=''
        +'<h3>How to Play</h3>'
        +'<div class="prism-start-grid">'
          +'<div><h4>Core Loop</h4><ol style="margin:0;padding-left:18px"><li>Care for Prism (Energy/Mood/Stability)</li><li>Grow Bond + Training</li><li>Choose Intent + Form + Move each turn</li><li>Battle AI or PvP</li><li>Repeat and master your build</li></ol></div>'
          +'<div><h4>Battle Structure</h4><ol style="margin:0;padding-left:18px"><li>Select Intent</li><li>Optional form switch</li><li>Select move</li><li>Resolver checks priority/speed</li><li>Hit/miss, damage, effects</li><li>End turn cleanup</li></ol></div>'
        +'</div>'
        +'<div class="prism-help-tip">Resources: <b>Energy</b> powers actions, <b>Stability</b> controls chaos risk, <b>Bond</b> unlocks mastery/clutch effects.</div>';
      start.after(how);

      const creator=document.createElement('article');
      creator.className='pph-card prism-start-card prism-prompt-box';
      creator.id='prism-character-creator-guide';
      creator.innerHTML=''
        +'<h3>Guided Character Creation (PixelLab.ai / Base44)</h3>'
        +'<div class="prism-kv">Flow: choose archetype + theme → build prompt → generate character art/concept → confirm character → bind Prism → start battling.</div>'
        +'<div class="prism-prompt-row" style="margin-top:8px">'
          +'<select id="prism-arch"><option value="frontline striker">Archetype: Frontline Striker</option><option value="defensive guardian">Archetype: Defensive Guardian</option><option value="tactical ranger">Archetype: Tactical Ranger</option><option value="chaos caster">Archetype: Chaos Caster</option></select>'
          +'<select id="prism-theme"><option value="neon cyber">Theme: Neon Cyber</option><option value="arcane crystal">Theme: Arcane Crystal</option><option value="forest biolume">Theme: Forest Biolume</option><option value="void shadow">Theme: Void Shadow</option></select>'
          +'<button id="prism-build-prompt" type="button">Build Prompt</button>'
          +'<button id="prism-copy-prompt" type="button">Copy</button>'
        +'</div>'
        +'<textarea id="prism-prompt-text" spellcheck="false"></textarea>'
        +'<div class="prism-help-tip">Use with <a href="https://www.pixellab.ai/" target="_blank" rel="noopener">PixelLab.ai</a> or <a href="https://base44.com" target="_blank" rel="noopener">Base44</a>. After generation, adopt species/personality in Prism Creatures panel and start a tutorial AI battle.</div>';
      how.after(creator);

      function buildPrompt(){
        const arch=q('#prism-arch',creator)?.value||'frontline striker';
        const theme=q('#prism-theme',creator)?.value||'neon cyber';
        const txt='Design a pixel-style playable character for Prism Creatures. Archetype: '+arch+'. Theme: '+theme+'. Include silhouette clarity, readable combat stance, and 4 form variants (Blade/Shield/Pulse/Flux). Output: front-facing portrait, battle sprite concept, and short lore. Keep style game-ready, high-contrast, no copyrighted characters.';
        const ta=q('#prism-prompt-text',creator); if(ta) ta.value=txt;
      }
      q('#prism-build-prompt',creator)?.addEventListener('click',buildPrompt);
      q('#prism-copy-prompt',creator)?.addEventListener('click', async ()=>{
        const ta=q('#prism-prompt-text',creator); if(!ta) return;
        try{ await navigator.clipboard.writeText(ta.value||''); }catch{}
      });
      buildPrompt();

      // PvP/AI contextual help + tutorial launch + compact Turn Plan HUD
      const pvp=[...document.querySelectorAll('.pph-card h4')].map(h=>h.closest('.pph-card')).find(c=>(c?.querySelector('h4')?.textContent||'').toLowerCase().includes('pvp arena'));
      if(pvp && !q('#prism-prebattle-help',pvp)){
        const help=document.createElement('div');
        help.id='prism-prebattle-help';
        help.className='prism-help-tip';
        help.innerHTML='<b>Pre-Battle Help:</b> 1) Load/Start battle (or Tutorial AI) 2) Pick Intent 3) Optional form switch 4) Select Move 5) Submit turn. Resolver handles order via priority + speed. Logs show move + intent + form.';
        pvp.prepend(help);

        const tools=document.createElement('div');
        tools.className='prism-prompt-row';
        tools.style.marginTop='8px';
        tools.innerHTML='<button id="prism-start-tutorial" type="button">Start Tutorial Battle</button><div class="prism-kv" style="display:grid;place-items:center">First-time guided AI opponent</div><div></div><div></div>';
        pvp.appendChild(tools);

        const hud=document.createElement('div');
        hud.className='prism-turn-hud';
        hud.id='prism-turn-plan-hud';
        hud.innerHTML='<div><b>Turn Plan HUD</b></div><div class="row"><div id="prism-hud-you">You: -</div><div id="prism-hud-opp">Opp: -</div></div>';
        pvp.appendChild(hud);

        async function post(path,payload){ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify(payload||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }
        async function get(path){ const r=await fetch(API+path,{credentials:'include'}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; }

        q('#prism-start-tutorial',pvp)?.addEventListener('click', async ()=>{
          const out=await post('pet/pvp/ai/tutorial/start',{});
          if(!out.ok||!out.j?.ok) return;
          const id=out.j.matchId||'';
          const idInput=q('#pvp-id',pvp); if(idInput) idInput.value=id;
          if(id) localStorage.setItem('prism_pvp_match_id',id);
          const st=q('#pvp-status',pvp); if(st) st.textContent='Tutorial battle ready.';
        });

        async function refreshHud(){
          const id=(q('#pvp-id',pvp)?.value||localStorage.getItem('prism_pvp_match_id')||'').trim();
          if(!id) return;
          const out=await get('pet/pvp/state-full?matchId='+encodeURIComponent(id));
          if(!out.ok||!out.j?.ok||!out.j.state) return;
          const s=out.j.state;
          const uid=(window.__prism_uid||0);
          const pa=s.participants?.a||{}; const pb=s.participants?.b||{};
          const me=(Number(pa.id)===Number(uid))?pa:pb;
          const op=(Number(pa.id)===Number(uid))?pb:pa;
          const meId=String(me.id||''); const opId=String(op.id||'');
          const forms=s.ui?.forms||s.combat?.form||{};
          const intents=s.ui?.intents||s.combat?.intent||{};
          const gs=s.ui?.growthStages||{};
          const ene=s.combat?.energy||{};
          const ins=s.combat?.instability||{};
          const ym=q('#prism-hud-you',pvp); const om=q('#prism-hud-opp',pvp);
          if(ym) ym.textContent='You: intent '+String(intents[meId]||'-')+' · form '+String(forms[meId]||'-')+' · E '+String(ene[meId]??'-')+' · I '+String(ins[meId]??'-')+' · stage '+String(gs[meId]||'-');
          if(om) om.textContent='Opp: intent '+String(intents[opId]||'-')+' · form '+String(forms[opId]||'-')+' · E '+String(ene[opId]??'-')+' · I '+String(ins[opId]??'-')+' · stage '+String(gs[opId]||'-');
        }
        setInterval(refreshHud, 2000);
        refreshHud();
      }

      // lightweight glossary + tooltips
      const glossary=document.createElement('article');
      glossary.className='pph-card prism-start-card';
      glossary.id='prism-glossary';
      glossary.innerHTML=''
        +'<h3>Key Terms & Tooltips</h3>'
        +'<div class="prism-kv"><b>Energy:</b> resource spent to act.<br><b>Stability:</b> low stability increases chaos risk.<br><b>Bond:</b> trust/mastery power.<br><b>Growth Stage:</b> persistent progression, not a move.<br><b>Form:</b> current stance (explicitly switched).<br><b>Intent:</b> per-turn tactical posture.<br><b>Moves:</b> combat actions with power/accuracy/cost/effects.</div>';
      creator.after(glossary);

      document.querySelectorAll('#prism-moves-list div, .pvp-m, #prism-intent-select, #prism-form-select').forEach(el=>{
        if(!el.getAttribute('title')) el.setAttribute('title','Prism battle control');
      });
    })();
    </script>
    <?php
}, 1000002900);

// ===== Prism Creatures cohesive one-game layout + AI current prism refresh (non-destructive, 2026-03-10) =====
add_action('rest_api_init', function(){
    // Override ai/start with fresh current prism snapshot through pre-dispatch below.
});

// Ensure AI battle always uses the player's CURRENT Prism Creature model snapshot.
add_filter('rest_pre_dispatch', function($result, $server, $request){
    if ($result !== null) return $result;
    if (!($request instanceof WP_REST_Request)) return $result;
    $route=(string)$request->get_route();
    $method=strtoupper((string)$request->get_method());
    if($route !== '/prismtek/v1/pet/pvp/ai/start' || $method !== 'POST') return $result;

    $uid=get_current_user_id();
    if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

    if(!function_exists('prismtek_pvp_get_matches')) return new WP_REST_Response(['ok'=>false,'error'=>'pvp_unavailable'],500);

    // Build fresh model from current pet + v2 state (current prism creature), then persist.
    $playerModel = function_exists('prismtek_prism_build_combat_model')
      ? prismtek_prism_build_combat_model($uid, [])
      : (function_exists('prismtek_prism_combat_get_or_create') ? prismtek_prism_combat_get_or_create($uid,false,[]) : []);
    if (!empty($playerModel) && is_array($playerModel)) {
        update_user_meta($uid,'prismtek_prism_combat_model_v1',$playerModel);
    }

    $aiModel = function_exists('prismtek_prism_build_ai_model_from_player')
      ? prismtek_prism_build_ai_model_from_player($playerModel)
      : [];

    $matches=prismtek_pvp_get_matches();
    $id='ai-'.wp_generate_uuid4();
    $maxA=max(70, function_exists('prismtek_pvp_hp_from_uid') ? prismtek_pvp_hp_from_uid($uid) : 100);
    $maxB=max(70, (int)round($maxA + mt_rand(-8,10)));

    $m=[
      'id'=>$id,
      'a'=>$uid,
      'b'=>0,
      'status'=>'active',
      'done'=>false,
      'round'=>1,
      'maxHp'=>[$uid=>$maxA,0=>$maxB],
      'hp'=>[$uid=>$maxA,0=>$maxB],
      'guard'=>[$uid=>0,0=>0],
      'charge'=>[$uid=>0,0=>0],
      'moves'=>[],
      'queue'=>[],
      'log'=>['AI battle started. Using your current Prism Creature.'],
      'updatedAt'=>time(),
      'ai'=>true,
      'prismModels'=>[$uid=>$playerModel,0=>$aiModel],
      'combat'=>[
        'turn'=>1,
        'energy'=>[$uid=>(int)($playerModel['energy'] ?? 70),0=>(int)($aiModel['energy'] ?? 70)],
        'instability'=>[$uid=>(int)($playerModel['derived_combat_stats']['instability_base'] ?? 26),0=>(int)($aiModel['derived_combat_stats']['instability_base'] ?? 26)],
        'status'=>[$uid=>[],0=>[]],
        'form'=>[$uid=>(string)($playerModel['form'] ?? 'blade'),0=>(string)($aiModel['form'] ?? 'blade')],
        'intent'=>[$uid=>'adapt',0=>'adapt'],
      ],
    ];

    $matches[$id]=$m;
    prismtek_pvp_set_matches($matches);

    return rest_ensure_response(['ok'=>true,'matchId'=>$id,'state'=>function_exists('prismtek_pvp_enrich_state')?prismtek_pvp_enrich_state($m):$m]);
}, -10, 3);

add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $isAdmin = is_user_logged_in() && current_user_can('manage_options');
    ?>
    <style id="prism-cohesive-layout-css">
      #prism-game-shell{display:grid;gap:12px;margin-top:8px}
      .prism-section{border:2px solid #7582df;background:#101640;color:#edf1ff;padding:12px;box-shadow:5px 5px 0 rgba(44,53,112,.7)}
      .prism-section h2{margin:0 0 8px;font-size:18px}
      .prism-flow{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px}
      .prism-flow .node{border:1px solid #6f7cdc;background:#141e4c;padding:5px 8px}
      .prism-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
      .prism-actions button{background:#1a2458;color:#fff;border:1px solid #6f7cdc;padding:8px 10px}
      .prism-subgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .prism-mini{border:1px solid #5f6bd0;background:#0f1538;padding:8px;font-size:12px}
      .prism-kv{font-size:12px;line-height:1.45}
      .prism-kv b{color:#fff}
      .prism-hidden-dev{display:none !important}
      @media (max-width:860px){.prism-subgrid{grid-template-columns:1fr}}
    </style>
    <script id="prism-cohesive-layout-js">
    (()=>{
      const API='/wp-json/prismtek/v1/';
      const IS_ADMIN=<?php echo $isAdmin ? 'true':'false'; ?>;
      const url=new URL(location.href);
      const DEV_MODE = IS_ADMIN || url.searchParams.get('dev')==='1' || localStorage.getItem('prism_dev_mode')==='1';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>[...r.querySelectorAll(s)];
      const wrap=q('.pph-creatures-wrap')||q('.pph-wrap');
      if(!wrap || q('#prism-game-shell')) return;

      const cards=qa('.pph-card',wrap);
      const petPanel=q('#pph-pet-panel',wrap);
      const pvpCard=cards.find(c=>(q('h4',c)?.textContent||'').toLowerCase().includes('pvp arena'))||q('#prism-battle-v2-panel')?.closest('.pph-card');
      const startHere=q('#prism-start-here-panel');
      const how=q('#prism-how-to-play');
      const creator=q('#prism-character-creator-guide');
      const glossary=q('#prism-glossary');

      const shell=document.createElement('section');
      shell.id='prism-game-shell';

      const sec1=document.createElement('article');
      sec1.className='prism-section';
      sec1.id='prism-sec-start';
      sec1.innerHTML=''
        +'<h2>1) START HERE</h2>'
        +'<div class="prism-flow">'
          +'<div class="node">Create Character</div><div>→</div>'
          +'<div class="node">Generate Prism</div><div>→</div>'
          +'<div class="node">Care</div><div>→</div>'
          +'<div class="node">Battle</div>'
        +'</div>'
        +'<div class="prism-actions">'
          +'<button id="prism-go-character" type="button">Create Character</button>'
          +'<button id="prism-go-generate" type="button">Generate Prism</button>'
          +'<button id="prism-go-pvp" type="button">Battle PvP</button>'
          +'<button id="prism-go-ai" type="button">Battle AI</button>'
        +'</div>';
      if(startHere) sec1.appendChild(startHere);

      const sec2=document.createElement('article');
      sec2.className='prism-section';
      sec2.id='prism-sec-game';
      sec2.innerHTML='<h2>2) GAME PANEL</h2><div class="prism-subgrid"><div id="prism-game-main" class="prism-mini"></div><div id="prism-game-meta" class="prism-mini"></div></div>';

      const sec3=document.createElement('article');
      sec3.className='prism-section';
      sec3.id='prism-sec-battle';
      sec3.innerHTML='<h2>3) BATTLE UI</h2><div class="prism-kv">Player Prism + Opponent Prism + Form + Growth Stage + Moves + Intents + Combat Log. Moves never auto-switch form.</div>';

      const sec4=document.createElement('article');
      sec4.className='prism-section';
      sec4.id='prism-sec-how';
      sec4.innerHTML='<h2>4) HOW TO PLAY</h2><div class="prism-kv"><b>Moves</b>=actions · <b>Form</b>=stance · <b>Intent</b>=turn modifier · <b>Growth Stage</b>=persistent progression.</div>';
      if(how) sec4.appendChild(how);
      if(glossary) sec4.appendChild(glossary);

      const sec5=document.createElement('article');
      sec5.className='prism-section';
      sec5.id='prism-sec-char';
      sec5.innerHTML='<h2>5) CHARACTER CREATION</h2><div class="prism-kv">Generate your character via PixelLab.ai/Base44 with guided prompts, then bind Prism and play.</div>';
      if(creator) sec5.appendChild(creator);

      shell.append(sec1,sec2,sec3,sec4,sec5);
      wrap.prepend(shell);

      // Move existing main panels into the cohesive sections
      if(petPanel) q('#prism-game-main',sec2)?.appendChild(petPanel);

      const gameMeta=q('#prism-game-meta',sec2);
      ['#pcv2-root','#prism-moveset-box','#prism-turn-plan-hud','#prism-prebattle-help','#prism-intent-row'].forEach(sel=>{
        const el=q(sel,document);
        if(el && gameMeta) gameMeta.appendChild(el);
      });

      if(pvpCard) sec3.appendChild(pvpCard);

      // Build a clean summary panel in GAME PANEL
      const sum=document.createElement('div');
      sum.id='prism-quick-summary';
      sum.className='prism-mini';
      sum.style.marginTop='10px';
      sum.innerHTML=''
        +'<div><b>Character</b>: <span id="prism-sum-char">-</span></div>'
        +'<div><b>Current Prism</b>: <span id="prism-sum-prism">-</span></div>'
        +'<div><b>Stats</b>: Energy <span id="prism-sum-energy">-</span> · Stability <span id="prism-sum-stab">-</span> · Bond <span id="prism-sum-bond">-</span> · Mood <span id="prism-sum-mood">-</span></div>'
        +'<div><b>Growth Stage</b>: <span id="prism-sum-growth">-</span> (display only)</div>'
        +'<div><b>Current Form</b>: <span id="prism-sum-form">-</span></div>'
        +'<div><b>Moveset</b>: <span id="prism-sum-moves">-</span></div>'
        +'<div><b>Intent</b>: <span id="prism-sum-intent">-</span></div>'
        +'<div class="prism-actions" style="margin-top:8px">'
          +'<button id="prism-action-train" type="button">Train</button>'
          +'<button id="prism-action-stabilize" type="button">Stabilize</button>'
          +'<button id="prism-action-explore" type="button">Explore</button>'
          +'<button id="prism-action-pvp" type="button">Battle PvP</button>'
          +'<button id="prism-action-ai" type="button">Battle AI</button>'
        +'</div>';
      sec2.appendChild(sum);

      function scrollToEl(el){ if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); }

      const charTarget=sec5;
      const genTarget=petPanel||sec2;
      const pvpTarget=sec3;

      q('#prism-go-character',sec1)?.addEventListener('click',()=>scrollToEl(charTarget));
      q('#prism-go-generate',sec1)?.addEventListener('click',()=>scrollToEl(genTarget));
      q('#prism-go-pvp',sec1)?.addEventListener('click',()=>{scrollToEl(pvpTarget); const st=q('#pvp-status'); if(st) st.textContent='Ready for PvP battle.';});
      q('#prism-go-ai',sec1)?.addEventListener('click',async()=>{ scrollToEl(pvpTarget); const btn=q('#prism-ai-start')||q('#prism-start-tutorial'); if(btn) btn.click(); });

      // actions map to existing controls where possible
      q('#prism-action-train',sum)?.addEventListener('click',()=>q('#pph-pet-train')?.click());
      q('#prism-action-stabilize',sum)?.addEventListener('click',()=>{ const b=qa('button').find(x=>/stabilize/i.test(x.textContent||'')); if(b) b.click(); });
      q('#prism-action-explore',sum)?.addEventListener('click',()=>{ const b=qa('button').find(x=>/explore/i.test(x.textContent||'')); if(b) b.click(); });
      q('#prism-action-pvp',sum)?.addEventListener('click',()=>scrollToEl(sec3));
      q('#prism-action-ai',sum)?.addEventListener('click',()=>{ const btn=q('#prism-ai-start'); if(btn) btn.click(); scrollToEl(sec3); });

      async function get(path){
        const r=await fetch(API+path,{credentials:'include'});
        const j=await r.json().catch(()=>({}));
        return {ok:r.ok,j};
      }

      async function refreshSummary(){
        const [rpg,model,v2]=await Promise.all([
          get('pet/rpg'),
          get('prism/combat-model'),
          get('prism/v2/profile')
        ]);

        if(rpg.ok&&rpg.j?.ok&&rpg.j.pet){
          const p=rpg.j.pet;
          const char=(p.name||'Prism Hero')+' ('+(p.personality||'brave')+')';
          const prism=(p.species||'sprout');
          const mood=(v2.ok&&v2.j?.ok&&v2.j.state)?(v2.j.state.mood??p.happiness??'-'):(p.happiness??'-');
          q('#prism-sum-char',sum).textContent=char;
          q('#prism-sum-prism',sum).textContent=prism;
          q('#prism-sum-energy',sum).textContent=String(p.energy??'-');
          q('#prism-sum-stab',sum).textContent=String((v2.j?.state?.stability ?? '-'));
          q('#prism-sum-bond',sum).textContent=String((v2.j?.state?.bond ?? '-'));
          q('#prism-sum-mood',sum).textContent=String(mood);
        }

        if(model.ok&&model.j?.ok&&model.j.model){
          const m=model.j.model;
          q('#prism-sum-growth',sum).textContent=String(m.current_growth_stage||'-');
          q('#prism-sum-form',sum).textContent=String(m.form||'-');
          const moves=(Array.isArray(m.generated_moveset)?m.generated_moveset:[]).map(x=>x.name).join(', ');
          q('#prism-sum-moves',sum).textContent=moves||'-';
        }

        const intentSel=q('#prism-intent-select');
        q('#prism-sum-intent',sum).textContent=intentSel?.value || 'adapt';
      }

      // Hide dev/system panels unless admin/dev mode
      if(!DEV_MODE){
        qa('.pph-card').forEach(card=>{
          const id=(card.id||'').toLowerCase();
          const h=(q('h3,h4,summary',card)?.textContent||'').toLowerCase();
          const isSystem = id.includes('battle-v2') || h.includes('mod tools') || h.includes('quick tools') || h.includes('debug') || h.includes('native quicktools') || h.includes('replay') || h.includes('spectate');
          if(isSystem && !card.closest('#prism-sec-battle')) card.classList.add('prism-hidden-dev');
        });
      }

      // Ensure visible order by removing old duplicate top cards that are now nested
      [startHere,how,creator,glossary].forEach(el=>{ if(el && !el.closest('.prism-section')) el.classList.add('prism-hidden-dev'); });

      setInterval(refreshSummary, 3000);
      refreshSummary();
    })();
    </script>
    <?php
}, 1000003000);

// ===== Prism cohesive safe minimal cleanup (one-shot, low-risk, 2026-03-10) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $isAdmin = is_user_logged_in() && current_user_can('manage_options');
    ?>
    <style id="prism-safe-min-cleanup-css">
      .prism-safe-hidden{display:none !important}
      #prism-game-shell{display:grid;gap:12px}
      #prism-sec-start,#prism-sec-game,#prism-sec-battle,#prism-sec-how,#prism-sec-char{scroll-margin-top:76px}
    </style>
    <script id="prism-safe-min-cleanup-js">
    (()=>{
      const IS_ADMIN=<?php echo $isAdmin ? 'true':'false'; ?>;
      const DEV_MODE = IS_ADMIN || new URL(location.href).searchParams.get('dev')==='1' || localStorage.getItem('prism_dev_mode')==='1';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>[...r.querySelectorAll(s)];

      const shell=q('#prism-game-shell');
      if(!shell) return;

      // 1) enforce section order once (no observers)
      const ordered=['#prism-sec-start','#prism-sec-game','#prism-sec-battle','#prism-sec-how','#prism-sec-char'];
      ordered.forEach(sel=>{ const el=q(sel,shell); if(el) shell.appendChild(el); });

      // 2) hide known legacy panels only (low-risk explicit ids)
      if(!DEV_MODE){
        [
          '#prism-battle-v2-panel','#prism-bv2-panel','#prism-bv2-state','#prism-bv2-log','#prism-bv2-ranks','#prism-bv2-rank','#prism-bv2-start',
          '#prism-curated-grid','#prism-curated-pack-ui','#prism-showdown-plus-ui','#prism-showdown-pro-ui','#prism-showdown-unify-ui',
          '#prism-pixellab-direct-ui','#prism-pixellab-byok-ui','#prism-pixellab-starterpack-ui','#prism-creatures-premium-pass'
        ].forEach(sel=>{ const el=q(sel); if(el && !el.closest('#prism-sec-battle')) el.classList.add('prism-safe-hidden'); });

        // hide duplicate old top cards if still outside shell
        qa('.pph-card').forEach(card=>{
          if(card.closest('#prism-game-shell')) return;
          const t=(card.textContent||'').toLowerCase();
          if(t.includes('pvp arena') || t.includes('battle arena v2') || t.includes('mod tools') || t.includes('quick tools')){
            card.classList.add('prism-safe-hidden');
          }
        });
      }

      // 3) wire start buttons safely
      const jump=(btn, target)=>{ const b=q(btn); if(!b) return; b.addEventListener('click',()=>target?.scrollIntoView({behavior:'smooth',block:'start'})); };
      jump('#prism-go-character', q('#prism-sec-char'));
      jump('#prism-go-generate', q('#prism-sec-game'));
      jump('#prism-go-pvp', q('#prism-sec-battle'));
      jump('#prism-go-ai', q('#prism-sec-battle'));
    })();
    </script>
    <?php
}, 1000003950);

// ===== Prism Companion Lab persistence API (2026-03-10) =====
if (!function_exists('prismtek_companion_default_profile_v1')) {
    function prismtek_companion_default_profile_v1() {
        return [
            'name' => 'Prismo',
            'species' => 'blob',
            'personality' => 'brave',
            'palette' => 'neon blue',
            'hunger' => 65,
            'happy' => 70,
            'energy' => 75,
            'age' => 0,
            'careScore' => 0,
            'creatureId' => '',
            'imageUrl' => '',
            'anim' => [
                'seed' => 1,
                'bobAmp' => 2,
                'bobSpeed' => 10,
                'blinkRate' => 90,
                'wobble' => 1.2,
                'orbit' => 2,
            ],
            'lastSeenTs' => time(),
            'updatedAt' => time(),
        ];
    }

    function prismtek_companion_sanitize_profile_v1($in) {
        $d = prismtek_companion_default_profile_v1();
        $p = is_array($in) ? $in : [];

        $out = $d;
        $out['name'] = sanitize_text_field((string)($p['name'] ?? $d['name']));
        $out['species'] = sanitize_key((string)($p['species'] ?? $d['species']));
        $out['personality'] = sanitize_key((string)($p['personality'] ?? $d['personality']));
        $out['palette'] = sanitize_text_field((string)($p['palette'] ?? $d['palette']));

        foreach (['hunger','happy','energy','age','careScore'] as $k) {
            $v = isset($p[$k]) ? (float)$p[$k] : (float)$d[$k];
            if (in_array($k, ['hunger','happy','energy'], true)) {
                $v = max(0, min(100, $v));
            } else {
                $v = max(0, $v);
            }
            $out[$k] = $v;
        }

        $out['creatureId'] = sanitize_text_field((string)($p['creatureId'] ?? $d['creatureId']));
        $img = esc_url_raw((string)($p['imageUrl'] ?? $d['imageUrl']));
        $out['imageUrl'] = $img;

        $anim = is_array($p['anim'] ?? null) ? $p['anim'] : [];
        $out['anim'] = [
            'seed' => max(1, (int)($anim['seed'] ?? ($d['anim']['seed'] ?? 1))),
            'bobAmp' => max(0.5, min(6, (float)($anim['bobAmp'] ?? 2))),
            'bobSpeed' => max(4, min(24, (float)($anim['bobSpeed'] ?? 10))),
            'blinkRate' => max(30, min(220, (int)($anim['blinkRate'] ?? 90))),
            'wobble' => max(0, min(6, (float)($anim['wobble'] ?? 1.2))),
            'orbit' => max(0, min(5, (int)($anim['orbit'] ?? 2))),
        ];

        $out['lastSeenTs'] = max(0, (int)($p['lastSeenTs'] ?? time()));
        $out['updatedAt'] = time();
        return $out;
    }
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/companion/profile', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) {
                return rest_ensure_response([
                    'ok' => true,
                    'loggedIn' => false,
                    'profile' => prismtek_companion_default_profile_v1(),
                ]);
            }
            $raw = get_user_meta($uid, 'prismtek_companion_lab_profile_v1', true);
            $profile = prismtek_companion_sanitize_profile_v1(is_array($raw) ? $raw : []);
            update_user_meta($uid, 'prismtek_companion_lab_profile_v1', $profile);
            return rest_ensure_response(['ok' => true, 'loggedIn' => true, 'profile' => $profile]);
        },
    ]);

    register_rest_route('prismtek/v1', '/companion/profile', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $incoming = $r->get_json_params();
            if (!is_array($incoming)) $incoming = [];
            $profile = prismtek_companion_sanitize_profile_v1($incoming);
            update_user_meta($uid, 'prismtek_companion_lab_profile_v1', $profile);
            return rest_ensure_response(['ok' => true, 'profile' => $profile]);
        },
    ]);
});

// ===== Unified linked-integrations status for game surfaces (2026-03-10) =====
add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/integrations/linked-status', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = get_current_user_id();
            if (!$uid) {
                return rest_ensure_response([
                    'ok' => true,
                    'loggedIn' => false,
                    'pixellab' => ['connected' => false, 'error' => 'auth_required'],
                    'base44' => ['connected' => false, 'error' => 'auth_required'],
                ]);
            }

            $plConnected = false;
            if (function_exists('prismtek_pixellab_decrypt')) {
                $plEnc = (string)get_user_meta($uid, 'prismtek_pixellab_key_enc', true);
                $plToken = $plEnc ? prismtek_pixellab_decrypt($plEnc) : '';
                $plConnected = trim((string)$plToken) !== '';
            }

            $b44Connected = false;
            if (function_exists('prismtek_base44_decrypt')) {
                $b44Enc = (string)get_user_meta($uid, 'prismtek_base44_key_enc', true);
                $b44Token = $b44Enc ? prismtek_base44_decrypt($b44Enc) : '';
                $b44Connected = trim((string)$b44Token) !== '';
            }

            return rest_ensure_response([
                'ok' => true,
                'loggedIn' => true,
                'pixellab' => ['connected' => $plConnected],
                'base44' => ['connected' => $b44Connected],
            ]);
        },
    ]);
});

// ===== Companion/auth fallback routes for iframe contexts without REST nonce (2026-03-10) =====
if (!function_exists('prismtek_resolve_user_from_logged_in_cookie_v1')) {
    function prismtek_resolve_user_from_logged_in_cookie_v1() {
        $uid = get_current_user_id();
        if ($uid) return (int)$uid;
        if (!defined('LOGGED_IN_COOKIE')) return 0;
        $cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) ? (string)$_COOKIE[LOGGED_IN_COOKIE] : '';
        if ($cookie === '') return 0;
        $uid = wp_validate_auth_cookie($cookie, 'logged_in');
        if (!$uid) return 0;
        wp_set_current_user((int)$uid);
        return (int)$uid;
    }
}

add_action('rest_api_init', function () {
    register_rest_route('prismtek/v1', '/integrations/linked-status-lite', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = prismtek_resolve_user_from_logged_in_cookie_v1();
            if (!$uid) {
                $res = new WP_REST_Response([
                    'ok' => true,
                    'loggedIn' => false,
                    'pixellab' => ['connected' => false],
                    'base44' => ['connected' => false],
                ], 200);
                $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                return $res;
            }

            $plConnected = false;
            if (function_exists('prismtek_pixellab_decrypt')) {
                $plEnc = (string)get_user_meta($uid, 'prismtek_pixellab_key_enc', true);
                $plToken = $plEnc ? prismtek_pixellab_decrypt($plEnc) : '';
                $plConnected = trim((string)$plToken) !== '';
            }

            $b44Connected = false;
            if (function_exists('prismtek_base44_decrypt')) {
                $b44Enc = (string)get_user_meta($uid, 'prismtek_base44_key_enc', true);
                $b44Token = $b44Enc ? prismtek_base44_decrypt($b44Enc) : '';
                $b44Connected = trim((string)$b44Token) !== '';
            }

            $res = new WP_REST_Response([
                'ok' => true,
                'loggedIn' => true,
                'pixellab' => ['connected' => $plConnected],
                'base44' => ['connected' => $b44Connected],
            ], 200);
            $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            return $res;
        },
    ]);

    register_rest_route('prismtek/v1', '/companion/profile-lite', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $uid = prismtek_resolve_user_from_logged_in_cookie_v1();
            if (!$uid) {
                $res = new WP_REST_Response(['ok' => true, 'loggedIn' => false, 'profile' => prismtek_companion_default_profile_v1()], 200);
                $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                return $res;
            }
            $raw = get_user_meta($uid, 'prismtek_companion_lab_profile_v1', true);
            $profile = prismtek_companion_sanitize_profile_v1(is_array($raw) ? $raw : []);
            update_user_meta($uid, 'prismtek_companion_lab_profile_v1', $profile);
            $res = new WP_REST_Response(['ok' => true, 'loggedIn' => true, 'profile' => $profile], 200);
            $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            return $res;
        },
    ]);

    register_rest_route('prismtek/v1', '/companion/profile-lite', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $uid = prismtek_resolve_user_from_logged_in_cookie_v1();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            $incoming = $r->get_json_params();
            if (!is_array($incoming)) $incoming = [];
            $profile = prismtek_companion_sanitize_profile_v1($incoming);
            update_user_meta($uid, 'prismtek_companion_lab_profile_v1', $profile);
            $res = new WP_REST_Response(['ok' => true, 'loggedIn' => true, 'profile' => $profile], 200);
            $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            return $res;
        },
    ]);
});
