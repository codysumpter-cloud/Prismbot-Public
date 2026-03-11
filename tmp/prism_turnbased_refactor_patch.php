
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
