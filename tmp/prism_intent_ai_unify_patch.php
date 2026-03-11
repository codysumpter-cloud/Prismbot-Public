
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
