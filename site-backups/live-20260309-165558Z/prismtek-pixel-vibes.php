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
            return current_user_can('manage_options');
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
          <?php if ($can_moderate): ?>
            <details class="pph-subtoggle" data-toggle-key="admin-tools">
              <summary>Upload / Admin Tools</summary>
              <p>Upload and metadata tools are hidden by default for cleaner browsing.</p>
              <form id="pph-game-upload-form" class="pph-form" enctype="multipart/form-data">
                <input name="title" type="text" maxlength="60" placeholder="Game title" />
                <input name="gameZip" type="file" accept=".zip,.html,.htm" required />
                <button type="submit">Upload Game</button>
              </form>
              <p id="pph-game-upload-status" class="pph-status"></p>
              <form id="pph-game-meta-form" class="pph-form">
                <input name="slug" type="text" placeholder="Game slug" />
                <input name="category" type="text" placeholder="Category (arcade/puzzle/racing)" />
                <input name="difficulty" type="text" placeholder="Difficulty (easy/normal/hard)" />
                <input name="controls" type="text" placeholder="Controls summary" />
                <input name="description" type="text" placeholder="Short description" />
                <button type="submit">Save Game Meta</button>
              </form>
              <p id="pph-game-meta-status" class="pph-status"></p>
            </details>
          <?php endif; ?>
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
        const fd = new FormData(gameUploadForm);
        if(gameUploadStatus) gameUploadStatus.textContent = 'Uploading game...';
        const r = await fetch(API+'games', { method:'POST', credentials:'include', headers:{'X-WP-Nonce':restNonce}, body:fd });
        const j = await r.json().catch(()=>({}));
        if(!r.ok){
          if(gameUploadStatus) gameUploadStatus.textContent = 'Upload failed: ' + (j.error || r.status);
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
            .pph-toggle[data-toggle-key="games"] summary { display:none !important; }
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
            .pph-toggle[data-toggle-key="studio"] summary { display:none !important; }
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
            #pph-pet-panel summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="games"] summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="studio"] summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="games"] summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="studio"] summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="games"] summary { display:none !important; }
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
        .pph-toggle[data-toggle-key="studio"] summary { display:none !important; }
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
        . '.pph-toggle[data-toggle-key="wall"] summary { display:none !important; }\n'
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
