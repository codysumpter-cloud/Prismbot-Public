
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
