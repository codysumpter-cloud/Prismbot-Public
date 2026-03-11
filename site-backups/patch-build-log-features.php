<?php
/**
 * Patch: All Build Log Features + Prism Creatures Roadmap
 * Features: Chat Moderation Queue, Weekly Spotlight, Per-Game Settings, Public Profiles
 */

// ===== 1. CHAT MODERATION QUEUE =====

function prismtek_mod_get_flags() {
    $rows = get_option('prismtek_chat_flags', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_mod_set_flags($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_chat_flags', $rows, false);
}

function prismtek_mod_flag_message($msg_id, $reason, $reporter_id) {
    $flags = prismtek_mod_get_flags();
    $flags[] = [
        'msgId' => $msg_id,
        'reason' => sanitize_text_field($reason),
        'reporterId' => (int)$reporter_id,
        'ts' => time(),
        'status' => 'pending',
    ];
    prismtek_mod_set_flags(array_slice($flags, -100));
}

function prismtek_mod_approve_flag($flag_idx) {
    $flags = prismtek_mod_get_flags();
    if (isset($flags[$flag_idx])) {
        $flags[$flag_idx]['status'] = 'approved';
        prismtek_mod_set_flags($flags);
    }
}

function prismtek_mod_reject_flag($flag_idx) {
    $flags = prismtek_mod_get_flags();
    if (isset($flags[$flag_idx])) {
        $flags[$flag_idx]['status'] = 'rejected';
        prismtek_mod_set_flags($flags);
    }
}

// Add REST endpoints for moderation queue
add_action('rest_api_init', function() {
    // Flag a message
    register_rest_route('prismtek/v1', '/chat/flag', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $request) {
            $msg_id = sanitize_text_field((string)$request->get_param('msgId'));
            $reason = sanitize_text_field((string)$request->get_param('reason'));
            $uid = get_current_user_id();
            if (!$uid) return new WP_REST_Response(['ok' => false, 'error' => 'auth_required'], 401);
            if ($msg_id === '' || $reason === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_payload'], 400);
            
            prismtek_mod_flag_message($msg_id, $reason, $uid);
            return rest_ensure_response(['ok' => true]);
        },
    ]);

    // Get moderation queue (admin only)
    register_rest_route('prismtek/v1', '/moderation/queue', [
        'methods' => 'GET',
        'permission_callback' => function() { return current_user_can('manage_options'); },
        'callback' => function() {
            $flags = prismtek_mod_get_flags();
            $msgs = prismtek_pixel_get_chat_messages();
            $enriched = [];
            foreach ($flags as $i => $f) {
                if (($f['status'] ?? 'pending') !== 'pending') continue;
                $msg_found = null;
                foreach ($msgs as $m) {
                    if (($m['id'] ?? '') === ($f['msgId'] ?? '')) { $msg_found = $m; break; }
                }
                if ($msg_found) {
                    $enriched[] = [
                        'index' => $i,
                        'msgId' => $f['msgId'],
                        'message' => $msg_found['message'] ?? '',
                        'author' => $msg_found['name'] ?? 'Unknown',
                        'reason' => $f['reason'] ?? '',
                        'reporterId' => $f['reporterId'] ?? 0,
                        'ts' => $f['ts'] ?? 0,
                    ];
                }
            }
            return rest_ensure_response(['ok' => true, 'queue' => $enriched]);
        },
    ]);

    // Resolve flag (approve = delete message, reject = keep message)
    register_rest_route('prismtek/v1', '/moderation/resolve', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('manage_options'); },
        'callback' => function(WP_REST_Request $request) {
            $index = (int)$request->get_param('index');
            $action = sanitize_key((string)$request->get_param('action')); // 'approve' or 'reject'
            $flags = prismtek_mod_get_flags();
            
            if (!isset($flags[$index])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
            }
            
            $flag = $flags[$index];
            
            if ($action === 'approve') {
                // Delete the flagged message
                $msgs = prismtek_pixel_get_chat_messages();
                $new_msgs = [];
                foreach ($msgs as $m) {
                    if (($m['id'] ?? '') !== ($flag['msgId'] ?? '')) {
                        $new_msgs[] = $m;
                    }
                }
                prismtek_pixel_set_chat_messages($new_msgs);
            }
            
            // Mark flag as resolved either way
            $flags[$index]['status'] = $action === 'approve' ? 'approved' : 'rejected';
            prismtek_mod_set_flags($flags);
            
            return rest_ensure_response(['ok' => true]);
        },
    ]);
});


// ===== 2. SCRAPBOOK WEEKLY SPOTLIGHT =====

function prismtek_spotlight_get() {
    $rows = get_option('prismtek_spotlight_history', []);
    return is_array($rows) ? $rows : [];
}

function prismtek_spotlight_set($rows) {
    if (!is_array($rows)) $rows = [];
    update_option('prismtek_spotlight_history', $rows, false);
}

function prismtek_spotlight_select_weekly() {
    $wall = prismtek_pixel_get_wall_items();
    if (empty($wall)) return null;
    
    // Filter to items from last 7 days
    $week_ago = time() - (7 * 86400);
    $recent = array_filter($wall, function($item) use ($week_ago) {
        return ($item['ts'] ?? 0) >= $week_ago;
    });
    
    if (empty($recent)) {
        // If no recent, pick random from all
        $recent = $wall;
    }
    
    // Pick random
    $pick = $recent[array_rand($recent)];
    
    // Save to history
    $history = prismtek_spotlight_get();
    $history[] = [
        'itemId' => $pick['id'] ?? '',
        'name' => $pick['name'] ?? 'Unknown',
        'caption' => $pick['caption'] ?? '',
        'url' => $pick['url'] ?? '',
        'featuredTs' => time(),
    ];
    prismtek_spotlight_set(array_slice($history, -52)); // Keep last year
    
    // Mark as featured on wall
    foreach ($wall as &$item) {
        if (($item['id'] ?? '') === ($pick['id'] ?? '')) {
            $item['featured'] = true;
        }
    }
    unset($item);
    prismtek_pixel_set_wall_items($wall);
    
    return $pick;
}

// Add REST endpoints for spotlight
add_action('rest_api_init', function() {
    // Get current spotlight
    register_rest_route('prismtek/v1', '/spotlight', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $wall = prismtek_pixel_get_wall_items();
            $current = null;
            foreach (array_reverse($wall) as $item) {
                if (!empty($item['featured'])) {
                    $current = $item;
                    break;
                }
            }
            $history = array_slice(prismtek_spotlight_get(), -12);
            return rest_ensure_response(['ok' => true, 'current' => $current, 'history' => $history]);
        },
    ]);

    // Generate new spotlight (admin only)
    register_rest_route('prismtek/v1', '/spotlight/pick', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('manage_options'); },
        'callback' => function() {
            $new = prismtek_spotlight_select_weekly();
            return rest_ensure_response(['ok' => true, 'spotlight' => $new]);
        },
    ]);
});


// ===== 3. PER-GAME SETTINGS PANEL =====

function prismtek_game_get_settings($slug) {
    $all = get_option('prismtek_game_settings', []);
    return is_array($all[$slug] ?? null) ? $all[$slug] : [
        'spawnRate' => 'normal',
        'difficultyPreset' => 'normal',
        'enabled' => true,
    ];
}

function prismtek_game_set_settings($slug, $settings) {
    $all = get_option('prismtek_game_settings', []);
    $all[$slug] = $settings;
    update_option('prismtek_game_settings', $all, false);
}

// Add REST endpoints for game settings
add_action('rest_api_init', function() {
    // Get game settings
    register_rest_route('prismtek/v1', '/games/settings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $request) {
            $slug = sanitize_title((string)$request->get_param('slug'));
            if ($slug === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_slug'], 400);
            $settings = prismtek_game_get_settings($slug);
            return rest_ensure_response(['ok' => true, 'settings' => $settings]);
        },
    ]);

    // Save game settings (admin only)
    register_rest_route('prismtek/v1', '/games/settings', [
        'methods' => 'POST',
        'permission_callback' => function() { return current_user_can('manage_options'); },
        'callback' => function(WP_REST_Request $request) {
            $slug = sanitize_title((string)$request->get_param('slug'));
            if ($slug === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_slug'], 400);
            
            $settings = [
                'spawnRate' => sanitize_text_field((string)$request->get_param('spawnRate')),
                'difficultyPreset' => sanitize_text_field((string)$request->get_param('difficultyPreset')),
                'enabled' => (bool)$request->get_param('enabled'),
            ];
            prismtek_game_set_settings($slug, $settings);
            return rest_ensure_response(['ok' => true]);
        },
    ]);
});


// ===== 4. PUBLIC PLAYER PROFILES + BADGES =====

function prismtek_profile_get_badges($uid) {
    $badges = get_user_meta((int)$uid, 'prismtek_badges', true);
    if (!is_array($badges)) $badges = [];
    
    // Auto-award badges based on stats
    $scores = prismtek_pixel_get_scores();
    $total_score = 0;
    foreach ($scores as $game => $rows) {
        if (!is_array($rows)) continue;
        foreach ($rows as $r) {
            if ((int)($r['userId'] ?? 0) === (int)$uid) {
                $total_score += (int)($r['score'] ?? 0);
            }
        }
    }
    
    $all_badges = [
        'first_play' => ['name' => 'First Play', 'desc' => 'Played your first game', 'icon' => '🎮'],
        'score_100' => ['name' => 'Century', 'desc' => 'Reached 100 total points', 'icon' => '💯'],
        'score_500' => ['name' => 'High Scorer', 'desc' => 'Reached 500 total points', 'icon' => '🏆'],
        'score_1000' => ['name' => 'Arcade Master', 'desc' => 'Reached 1000 total points', 'icon' => '👑'],
        'chat_active' => ['name' => 'Chatter', 'desc' => 'Sent 10+ chat messages', 'icon' => '💬'],
        'wall_artist' => ['name' => 'Wall Artist', 'desc' => 'Posted to Memory Wall', 'icon' => '🎨'],
        'creature_parent' => ['name' => 'Creature Parent', 'desc' => 'Adopted a Prism Creature', 'icon' => '🥚'],
        'week_streak' => ['name' => 'Weekly Player', 'desc' => 'Played 7 days in a row', 'icon' => '🔥'],
    ];
    
    // Auto-check badges
    $chat_msgs = get_user_meta((int)$uid, 'prismtek_chat_count', true) ?: 0;
    $wall_posts = get_user_meta((int)$uid, 'prismtek_wall_count', true) ?: 0;
    $pet_state = get_user_meta((int)$uid, 'prismtek_pet_state', true);
    
    $earned = [];
    if ($total_score >= 1) $earned[] = 'first_play';
    if ($total_score >= 100) $earned[] = 'score_100';
    if ($total_score >= 500) $earned[] = 'score_500';
    if ($total_score >= 1000) $earned[] = 'score_1000';
    if ($chat_msgs >= 10) $earned[] = 'chat_active';
    if ($wall_posts >= 1) $earned[] = 'wall_artist';
    if (is_array($pet_state) && !empty($pet_state)) $earned[] = 'creature_parent';
    
    // Build earned badge list
    $result = [];
    foreach ($earned as $e) {
        if (isset($all_badges[$e])) {
            $result[] = array_merge(['id' => $e], $all_badges[$e]);
        }
    }
    
    return $result;
}

// Add REST endpoints for public profiles
add_action('rest_api_init', function() {
    // Get public profile
    register_rest_route('prismtek/v1', '/profile/public', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function(WP_REST_Request $request) {
            $username = sanitize_text_field((string)$request->get_param('username'));
            if ($username === '') return new WP_REST_Response(['ok' => false, 'error' => 'missing_username'], 400);
            
            $user = get_user_by('login', $username);
            if (!$user) $user = get_user_by('slug', $username);
            if (!$user) return new WP_REST_Response(['ok' => false, 'error' => 'user_not_found'], 404);
            
            $uid = (int)$user->ID;
            
            // Get stats
            $scores = prismtek_pixel_get_scores();
            $total_score = 0;
            $games_played = 0;
            $seen_games = [];
            foreach ($scores as $game => $rows) {
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    if ((int)($r['userId'] ?? 0) === $uid) {
                        $total_score += (int)($r['score'] ?? 0);
                        if (!isset($seen_games[$game])) {
                            $seen_games[$game] = true;
                            $games_played++;
                        }
                    }
                }
            }
            
            $badges = prismtek_profile_get_badges($uid);
            $pet_state = get_user_meta($uid, 'prismtek_pet_state', true);
            
            return rest_ensure_response([
                'ok' => true,
                'profile' => [
                    'username' => $user->display_name,
                    'bio' => get_user_meta($uid, 'prismtek_bio', true),
                    'joined' => $user->user_registered,
                    'totalScore' => $total_score,
                    'gamesPlayed' => $games_played,
                    'badges' => $badges,
                    'creature' => is_array($pet_state) ? [
                        'name' => $pet_state['name'] ?? 'Prismo',
                        'stage' => prismtek_pet_compute_stage($pet_state),
                        'skin' => $pet_state['skin'] ?? 'default',
                    ] : null,
                ],
            ]);
        },
    ]);

    // List leaderboard (all users by total score)
    register_rest_route('prismtek/v1', '/leaderboard/global', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $scores = prismtek_pixel_get_scores();
            $user_scores = [];
            
            foreach ($scores as $game => $rows) {
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    $uid = (int)($r['userId'] ?? 0);
                    if ($uid === 0) continue;
                    if (!isset($user_scores[$uid])) {
                        $user_scores[$uid] = ['userId' => $uid, 'total' => 0, 'games' => []];
                    }
                    $user_scores[$uid]['total'] += (int)($r['score'] ?? 0);
                    $user_scores[$uid]['games'][] = $game;
                }
            }
            
            // Sort by total
            usort($user_scores, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });
            
            // Enrich with user data
            $top = [];
            foreach (array_slice($user_scores, 0, 20) as $i => $u) {
                $user = get_userdata($u['userId']);
                if (!$user) continue;
                $badges = prismtek_profile_get_badges($u['userId']);
                $top[] = [
                    'rank' => $i + 1,
                    'userId' => $u['userId'],
                    'username' => $user->display_name,
                    'totalScore' => $u['total'],
                    'gamesPlayed' => count(array_unique($u['games'])),
                    'badges' => array_slice($badges, 0, 3), // Show top 3 badges
                ];
            }
            
            return rest_ensure_response(['ok' => true, 'leaderboard' => $top]);
        },
    ]);
});


// ===== 5. AUTO-SPOTLIGHT SCHEDULING (weekly cron) =====

// Schedule weekly spotlight if not already scheduled
if (!wp_next_scheduled('prismtek_weekly_spotlight')) {
    wp_schedule_event(strtotime('Sunday 00:00 UTC'), 'weekly', 'prismtek_weekly_spotlight');
}

add_action('prismtek_weekly_spotlight', function() {
    prismtek_spotlight_select_weekly();
});


/**
 * =====================================================
 * PRISM CREATURES ROADMAP
 * =====================================================
 * 
 * Phase 1: Foundation (Current)
 * - Creature visible on Prism Creatures page
 * - Care actions: feed, play, rest
 * - Stats: hunger, happiness, energy, health
 * - Stage progression: baby → teen → adult
 * - Skin system (unlock by score)
 * 
 * Phase 2: Personalization
 * - Custom sprite upload (PNG, max 64x64)
 * - Multiple creature types/species
 * - Personality traits (affect evolution)
 * - Creature animations/poses
 * 
 * Phase 3: Social Features
 * - Visit other players' creatures
 * - Creature trading (future)
 * - Global creature directory
 * - Friend list / creature followers
 * 
 * Phase 4: Battles (MVP)
 * - Turn-based creature battles
 * - Attack/defend/heal moves
 * - Stat-based damage calculation
 * - Battle history log
 * - Tournament/leaderboard (future)
 * 
 * Phase 5: Ecosystem Expansion
 * - User-submitted custom creature sprites
 * - Creature breeding (combine traits)
 * - Rare/legendary spawn chance
 * - Seasonal events
 * - Achievement unlocks
 * - Cross-site creature portability (future)
 */
