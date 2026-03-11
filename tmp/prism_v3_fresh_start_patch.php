
// ===== Prism v3 Fresh Start: mandatory guided character creation + isolated UI shell (2026-03-10) =====
if (!function_exists('prismtek_v3_version')) {
    function prismtek_v3_version(){ return '2026-03-10-v3'; }
    function prismtek_v3_ready($uid){
        $uid=(int)$uid;
        if($uid<=0) return false;
        $ready = (int)get_user_meta($uid,'prismtek_v3_ready',true) === 1;
        $ver = (string)get_user_meta($uid,'prismtek_v3_version',true);
        return $ready && $ver === prismtek_v3_version();
    }
    function prismtek_v3_character($uid){
        $raw = get_user_meta((int)$uid,'prismtek_v3_character',true);
        return is_array($raw) ? $raw : [];
    }
}

add_action('rest_api_init', function(){
    register_rest_route('prismtek/v1','/prism/v3/bootstrap',[
      'methods'=>'GET',
      'permission_callback'=>'__return_true',
      'callback'=>function(){
          $uid=get_current_user_id();
          if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);
          $ready=prismtek_v3_ready($uid);
          $char=prismtek_v3_character($uid);
          $pet=function_exists('prismtek_pet_get_state')?prismtek_pet_get_state($uid):[];
          return rest_ensure_response(['ok'=>true,'ready'=>$ready,'version'=>prismtek_v3_version(),'character'=>$char,'pet'=>$pet]);
      }
    ]);

    register_rest_route('prismtek/v1','/prism/v3/create-character',[
      'methods'=>'POST',
      'permission_callback'=>'__return_true',
      'callback'=>function(WP_REST_Request $r){
          $uid=get_current_user_id();
          if(!$uid) return new WP_REST_Response(['ok'=>false,'error'=>'auth_required'],401);

          $name=sanitize_text_field((string)$r->get_param('characterName'));
          $archetype=sanitize_text_field((string)$r->get_param('archetype'));
          $theme=sanitize_text_field((string)$r->get_param('theme'));
          $prompt=trim((string)$r->get_param('prompt'));
          $species=sanitize_key((string)$r->get_param('species'));
          $personality=sanitize_key((string)$r->get_param('personality'));
          $confirmed=(bool)$r->get_param('confirmOriginalCharacter');

          $speciesAllowed=['sprout','ember','tidal','volt','shade'];
          $personAllowed=['brave','curious','calm','chaotic'];
          if(!in_array($species,$speciesAllowed,true)) $species='sprout';
          if(!in_array($personality,$personAllowed,true)) $personality='brave';

          if($name==='') return new WP_REST_Response(['ok'=>false,'error'=>'character_name_required'],400);
          if(strlen($prompt)<40) return new WP_REST_Response(['ok'=>false,'error'=>'guided_prompt_required'],400);
          if(!$confirmed) return new WP_REST_Response(['ok'=>false,'error'=>'confirm_required'],400);

          // Fresh start: drop previous generated combat asset for this user.
          delete_user_meta($uid,'prismtek_prism_combat_model_v1');

          // Bind prism creature choices into existing pet state (non-destructive to other stats).
          $pet=function_exists('prismtek_pet_get_state')?prismtek_pet_get_state($uid):[];
          if(!is_array($pet)) $pet=[];
          $pet['name']=$name;
          $pet['species']=$species;
          $pet['personality']=$personality;
          if(function_exists('prismtek_pet_enrich_state')) $pet=prismtek_pet_enrich_state($pet);
          if(function_exists('prismtek_pet_set_state')) prismtek_pet_set_state($uid,$pet);

          // initialize v2 profile baseline if exists
          if(function_exists('prismtek_prism_v2_merge')){
            $raw=get_user_meta($uid,'prismtek_prism_v2_profile',true);
            $v2=prismtek_prism_v2_merge(is_array($raw)?$raw:[]);
            if(empty($v2['actions']) || !is_array($v2['actions'])) $v2['actions']=['training'=>0,'battles'=>0,'stabilizing'=>0,'exploration'=>0,'neglect'=>0];
            update_user_meta($uid,'prismtek_prism_v2_profile',$v2);
          }

          $char=[
            'characterName'=>$name,
            'archetype'=>$archetype,
            'theme'=>$theme,
            'prompt'=>$prompt,
            'species'=>$species,
            'personality'=>$personality,
            'createdAt'=>time(),
          ];
          update_user_meta($uid,'prismtek_v3_character',$char);
          update_user_meta($uid,'prismtek_v3_ready',1);
          update_user_meta($uid,'prismtek_v3_version',prismtek_v3_version());

          // Rebuild combat model from fresh character choices.
          if(function_exists('prismtek_prism_combat_get_or_create')) prismtek_prism_combat_get_or_create($uid,true,['species'=>$species,'personality'=>$personality]);

          return rest_ensure_response(['ok'=>true,'ready'=>true,'character'=>$char,'pet'=>$pet]);
      }
    ]);
});

// Gate all battle entry/move endpoints behind v3 character creation readiness.
add_filter('rest_pre_dispatch', function($result, $server, $request){
    if ($result !== null) return $result;
    if (!($request instanceof WP_REST_Request)) return $result;
    $route=(string)$request->get_route();
    $method=strtoupper((string)$request->get_method());
    $gated=[
      '/prismtek/v1/pet/pvp/challenge',
      '/prismtek/v1/pet/pvp/accept',
      '/prismtek/v1/pet/pvp/move-pro',
      '/prismtek/v1/pet/pvp/move-full',
      '/prismtek/v1/pet/pvp/ai/start',
      '/prismtek/v1/pet/pvp/ai/tutorial/start',
    ];
    if(!in_array($route,$gated,true)) return $result;
    if(!in_array($method,['POST','GET'],true)) return $result;
    $uid=get_current_user_id();
    if(!$uid) return $result;
    if(!prismtek_v3_ready($uid)) return new WP_REST_Response(['ok'=>false,'error'=>'character_creation_required_v3'],403);
    return $result;
}, -50, 3);

// Isolated Prism v3 shell (hides legacy surface for players, keeps dev escape).
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $isAdmin = is_user_logged_in() && current_user_can('manage_options');
    ?>
    <style id="prism-v3-shell-css">
      #prism-v3-root{max-width:1100px;margin:0 auto;display:grid;gap:12px;color:#eef2ff;font-family:ui-monospace,monospace}
      #prism-v3-root .sec{border:2px solid #7380df;background:#101740;padding:12px;box-shadow:5px 5px 0 rgba(43,52,108,.75)}
      #prism-v3-root h2{margin:0 0 8px;font-size:18px}
      #prism-v3-root .flow{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
      #prism-v3-root .chip{border:1px solid #6878d8;background:#121d4a;padding:4px 8px;font-size:12px}
      #prism-v3-root .row{display:flex;flex-wrap:wrap;gap:8px}
      #prism-v3-root .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      #prism-v3-root button,#prism-v3-root select,#prism-v3-root input,#prism-v3-root textarea{background:#18225a;color:#fff;border:1px solid #6f7cdc;padding:8px}
      #prism-v3-root textarea{width:100%;min-height:88px}
      #prism-v3-root .muted{font-size:12px;opacity:.9}
      #prism-v3-root .log{max-height:220px;overflow:auto;white-space:pre-wrap;background:#0d1334;border:1px solid #5562be;padding:8px;font-size:12px}
      #prism-v3-root .locked{opacity:.55;pointer-events:none;filter:grayscale(.25)}
      .prism-v3-hide-legacy{display:none !important}
      @media (max-width:860px){#prism-v3-root .grid2{grid-template-columns:1fr}}
    </style>
    <script id="prism-v3-shell-js">
    (()=>{
      const IS_ADMIN=<?php echo $isAdmin ? 'true':'false'; ?>;
      const qs=new URL(location.href).searchParams;
      const DEV = IS_ADMIN || qs.get('dev')==='1' || localStorage.getItem('prism_dev_mode')==='1';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>[...r.querySelectorAll(s)];
      const API='/wp-json/prismtek/v1/';

      const host=q('.pph-creatures-wrap')||q('.pph-wrap')||q('.entry-content')||document.body;
      if(!host || q('#prism-v3-root')) return;

      // hide old page surface for players; devs can still inspect with ?dev=1
      if(!DEV){
        qa('.pph-card, .pph-toggle, .pph-subtoggle, #prism-game-shell').forEach(el=>el.classList.add('prism-v3-hide-legacy'));
      }

      const root=document.createElement('section');
      root.id='prism-v3-root';
      root.innerHTML=''
        +'<article class="sec" id="v3-start"><h2>1) START HERE</h2><div class="flow"><span class="chip">Create Character</span><span>→</span><span class="chip">Generate Prism</span><span>→</span><span class="chip">Care</span><span>→</span><span class="chip">Battle</span></div><div class="row" style="margin-top:8px"><button id="v3-btn-char">Create Character</button><button id="v3-btn-generate">Generate Prism</button><button id="v3-btn-pvp">Battle PvP</button><button id="v3-btn-ai">Battle AI</button></div></article>'
        +'<article class="sec" id="v3-game"><h2>2) GAME PANEL</h2><div class="grid2"><div><div><b>Character:</b> <span id="v3-char">-</span></div><div><b>Current Prism:</b> <span id="v3-prism">-</span></div><div><b>Stats:</b> Energy <span id="v3-energy">-</span> · Stability <span id="v3-stab">-</span> · Bond <span id="v3-bond">-</span> · Mood <span id="v3-mood">-</span></div><div><b>Growth Stage:</b> <span id="v3-growth">-</span> (display only)</div><div><b>Current Form:</b> <span id="v3-form">-</span></div><div><b>Moveset:</b> <span id="v3-moves">-</span></div><div><b>Intent:</b> <span id="v3-intent-view">adapt</span></div></div><div><label>Intent<select id="v3-intent"><option value="adapt">Adapt</option><option value="aggress">Aggress</option><option value="guard">Guard</option><option value="focus">Focus</option><option value="stabilize">Stabilize</option></select></label><label>Form Switch<select id="v3-form"><option value="">Keep Current</option><option value="blade">Blade</option><option value="shield">Shield</option><option value="pulse">Pulse</option><option value="flux">Flux</option></select></label><div class="row"><button id="v3-train">Train</button><button id="v3-stabilize">Stabilize</button><button id="v3-explore">Explore</button><button id="v3-go-pvp">Battle PvP</button><button id="v3-go-ai">Battle AI</button></div></div></div><div class="muted" id="v3-game-status">Ready.</div></article>'
        +'<article class="sec" id="v3-battle"><h2>3) BATTLE UI</h2><div class="row"><input id="v3-opp" placeholder="Opponent username"/><button id="v3-challenge">Challenge PvP</button><button id="v3-ai">Battle AI</button><input id="v3-match" placeholder="Match ID"/><button id="v3-load">Load</button></div><div class="grid2" style="margin-top:8px"><div><b>You:</b> <span id="v3-you">-</span></div><div><b>Opponent:</b> <span id="v3-oppv">-</span></div></div><div class="row" id="v3-move-row" style="margin-top:8px"></div><div class="muted" id="v3-battle-status">No active match.</div><div class="log" id="v3-log">No battle loaded.</div></article>'
        +'<article class="sec" id="v3-how"><h2>4) HOW TO PLAY</h2><div class="muted"><b>Moves</b> = combat actions. <b>Form</b> = current stance. <b>Intent</b> = turn modifier. <b>Growth Stage</b> = persistent progression and never changes during battle.</div></article>'
        +'<article class="sec" id="v3-create"><h2>5) CHARACTER CREATION</h2><div class="muted">Use guided prompt to create your own original character with PixelLab.ai or Base44, then bind Prism and start battling.</div><div class="row" style="margin-top:8px"><select id="v3-arch"><option value="frontline striker">Archetype: Frontline Striker</option><option value="defensive guardian">Archetype: Defensive Guardian</option><option value="tactical ranger">Archetype: Tactical Ranger</option><option value="chaos caster">Archetype: Chaos Caster</option></select><select id="v3-theme"><option value="neon cyber">Theme: Neon Cyber</option><option value="arcane crystal">Theme: Arcane Crystal</option><option value="forest biolume">Theme: Forest Biolume</option><option value="void shadow">Theme: Void Shadow</option></select><select id="v3-species"><option value="sprout">Prism: Sprout</option><option value="ember">Prism: Ember</option><option value="tidal">Prism: Tidal</option><option value="volt">Prism: Volt</option><option value="shade">Prism: Shade</option></select><select id="v3-person"><option value="brave">Personality: Brave</option><option value="curious">Personality: Curious</option><option value="calm">Personality: Calm</option><option value="chaotic">Personality: Chaotic</option></select></div><div class="row" style="margin-top:8px"><input id="v3-name" maxlength="24" placeholder="Character name"/><button id="v3-build">Build Guided Prompt</button><button id="v3-copy">Copy Prompt</button></div><textarea id="v3-prompt"></textarea><label class="muted"><input type="checkbox" id="v3-confirm"/> I confirm this is my own original character prompt/content.</label><div class="row" style="margin-top:8px"><button id="v3-create-btn">Create Character + Bind Prism</button><a href="https://www.pixellab.ai/" target="_blank" rel="noopener">Open PixelLab.ai</a><a href="https://base44.com" target="_blank" rel="noopener">Open Base44</a></div><div class="muted" id="v3-create-status">Character not created yet.</div></article>';

      host.prepend(root);

      const S={ready:false,matchId:localStorage.getItem('prism_pvp_match_id')||'',moves:[],uid:0};
      const setTxt=(id,v)=>{ const el=q(id,root); if(el) el.textContent=v; };

      const jget=async(path)=>{ const r=await fetch(API+path,{credentials:'include'}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; };
      const jpost=async(path,p)=>{ const r=await fetch(API+path,{method:'POST',credentials:'include',headers:{'content-type':'application/json'},body:JSON.stringify(p||{})}); const j=await r.json().catch(()=>({})); return {ok:r.ok,j}; };

      function buildPrompt(){
        const arch=q('#v3-arch',root).value;
        const theme=q('#v3-theme',root).value;
        const species=q('#v3-species',root).value;
        const person=q('#v3-person',root).value;
        q('#v3-prompt',root).value='Design an original pixel character for Prism Creatures. Archetype: '+arch+'. Theme: '+theme+'. Bound Prism species: '+species+'. Personality direction: '+person+'. Include clear silhouette, readable stance, and form variants (Blade/Shield/Pulse/Flux). Include concise lore and battle role. No copyrighted characters.';
      }

      function lockGameplay(locked){
        ['#v3-game','#v3-battle'].forEach(sel=>{ const sec=q(sel,root); if(sec) sec.classList.toggle('locked',locked); });
      }

      async function refreshBootstrap(){
        const b=await jget('prism/v3/bootstrap?ts='+Date.now());
        if(!b.ok||!b.j?.ok){ setTxt('#v3-create-status','Login required.'); lockGameplay(true); return; }
        S.ready=!!b.j.ready;
        const c=b.j.character||{};
        const p=b.j.pet||{};
        setTxt('#v3-char', c.characterName || p.name || '-');
        setTxt('#v3-prism', p.species || c.species || '-');
        if(c.characterName) q('#v3-name',root).value=c.characterName;
        lockGameplay(!S.ready);
        setTxt('#v3-create-status', S.ready ? 'Character ready ✅' : 'Create your character to unlock gameplay.');
      }

      async function refreshGame(){
        const [rpg,v2,model]=await Promise.all([jget('pet/rpg'),jget('prism/v2/profile'),jget('prism/combat-model')]);
        if(rpg.ok&&rpg.j?.ok&&rpg.j.pet){
          const p=rpg.j.pet;
          setTxt('#v3-energy', String(p.energy??'-'));
        }
        if(v2.ok&&v2.j?.ok&&v2.j.state){
          const s=v2.j.state;
          setTxt('#v3-stab', String(s.stability??'-'));
          setTxt('#v3-bond', String(s.bond??'-'));
          setTxt('#v3-mood', String(s.mood??'-'));
        }
        if(model.ok&&model.j?.ok&&model.j.model){
          const m=model.j.model;
          setTxt('#v3-growth', String(m.current_growth_stage||'-'));
          setTxt('#v3-form', String(m.form||'-'));
          S.moves=Array.isArray(m.generated_moveset)?m.generated_moveset:[];
          setTxt('#v3-moves', S.moves.map(x=>x.name).join(', ')||'-');
          const row=q('#v3-move-row',root); if(row){
            row.innerHTML='';
            S.moves.forEach(mv=>{
              const b=document.createElement('button');
              b.textContent=mv.name;
              b.title='P'+mv.power+' A'+mv.accuracy+' E'+mv.energy_cost+' '+mv.status_effect;
              b.addEventListener('click',()=>submitMove(mv.id));
              row.appendChild(b);
            });
          }
        }
        setTxt('#v3-intent-view', q('#v3-intent',root)?.value||'adapt');
      }

      function renderState(s){
        if(!s) return;
        const pa=s.participants?.a||{}; const pb=s.participants?.b||{};
        const my=(Number(pa.id)===Number(S.uid))?pa:pb;
        const op=(Number(pa.id)===Number(S.uid))?pb:pa;
        const forms=s.ui?.forms||s.combat?.form||{};
        const intents=s.ui?.intents||s.combat?.intent||{};
        const gs=s.ui?.growthStages||{};
        const myId=String(my.id||''); const opId=String(op.id||'');
        setTxt('#v3-you', (my.displayName||'You')+' · form '+String(forms[myId]||'-')+' · stage '+String(gs[myId]||'-')+' · intent '+String(intents[myId]||'-'));
        setTxt('#v3-oppv', (op.displayName||'Opponent')+' · form '+String(forms[opId]||'-')+' · stage '+String(gs[opId]||'-')+' · intent '+String(intents[opId]||'-'));
        setTxt('#v3-battle-status', s.done ? ('Battle ended: '+(s.result||'done')) : ('Turn '+String(s.combat?.turn||s.round||1)));
        const log=q('#v3-log',root); if(log) log.textContent=(s.log||[]).join('\n');
      }

      async function loadMatch(){
        const id=(q('#v3-match',root).value||S.matchId||'').trim();
        if(!id) return;
        const out=await jget('pet/pvp/state-full?matchId='+encodeURIComponent(id));
        if(out.ok&&out.j?.ok){ S.matchId=id; localStorage.setItem('prism_pvp_match_id',id); renderState(out.j.state); }
      }

      async function submitMove(moveId){
        if(!S.ready){ setTxt('#v3-battle-status','Create character first.'); return; }
        const id=(q('#v3-match',root).value||S.matchId||'').trim();
        if(!id){ setTxt('#v3-battle-status','Load/start a match first.'); return; }
        const intent=q('#v3-intent',root).value||'adapt';
        const form=q('#v3-form',root).value||'';
        setTxt('#v3-intent-view', intent);
        const out=await jpost('pet/pvp/move-pro',{matchId:id,move:moveId,intent,form});
        if(!out.ok||!out.j?.ok){ setTxt('#v3-battle-status', out.j?.error || 'Move failed'); return; }
        renderState(out.j.state);
      }

      q('#v3-build',root)?.addEventListener('click',buildPrompt);
      q('#v3-copy',root)?.addEventListener('click',async()=>{ try{ await navigator.clipboard.writeText(q('#v3-prompt',root).value||''); }catch{} });
      q('#v3-create-btn',root)?.addEventListener('click', async ()=>{
        const payload={
          characterName:(q('#v3-name',root).value||'').trim(),
          archetype:q('#v3-arch',root).value,
          theme:q('#v3-theme',root).value,
          prompt:q('#v3-prompt',root).value||'',
          species:q('#v3-species',root).value,
          personality:q('#v3-person',root).value,
          confirmOriginalCharacter: !!q('#v3-confirm',root).checked,
        };
        const out=await jpost('prism/v3/create-character',payload);
        if(!out.ok||!out.j?.ok){ setTxt('#v3-create-status','Create failed: '+(out.j?.error||'unknown')); return; }
        setTxt('#v3-create-status','Character created and Prism bound ✅');
        await refreshBootstrap(); await refreshGame();
      });

      q('#v3-train',root)?.addEventListener('click', async ()=>{ await jpost('pet/train',{}); refreshGame(); });
      q('#v3-stabilize',root)?.addEventListener('click', async ()=>{
        const v=await jget('prism/v2/profile');
        if(!v.ok||!v.j?.ok) return;
        const s=v.j.state||{};
        s.stability=Math.min(100, Number(s.stability||60)+10);
        s.energy=Math.max(0, Number(s.energy||70)-4);
        s.actions=s.actions||{}; s.actions.stabilizing=(Number(s.actions.stabilizing||0)+1);
        await jpost('prism/v2/profile',s); refreshGame();
      });
      q('#v3-explore',root)?.addEventListener('click', async ()=>{
        const v=await jget('prism/v2/profile');
        if(!v.ok||!v.j?.ok) return;
        const s=v.j.state||{};
        s.energy=Math.max(0, Number(s.energy||70)-6);
        s.bond=Math.min(100, Number(s.bond||20)+2);
        s.actions=s.actions||{}; s.actions.exploration=(Number(s.actions.exploration||0)+1);
        await jpost('prism/v2/profile',s); refreshGame();
      });

      q('#v3-go-pvp',root)?.addEventListener('click',()=>q('#v3-battle',root)?.scrollIntoView({behavior:'smooth'}));
      q('#v3-go-ai',root)?.addEventListener('click',()=>q('#v3-ai',root)?.click());
      q('#v3-btn-char',root)?.addEventListener('click',()=>q('#v3-create',root)?.scrollIntoView({behavior:'smooth'}));
      q('#v3-btn-generate',root)?.addEventListener('click',()=>q('#v3-game',root)?.scrollIntoView({behavior:'smooth'}));
      q('#v3-btn-pvp',root)?.addEventListener('click',()=>q('#v3-battle',root)?.scrollIntoView({behavior:'smooth'}));
      q('#v3-btn-ai',root)?.addEventListener('click',()=>q('#v3-ai',root)?.click());

      q('#v3-challenge',root)?.addEventListener('click', async ()=>{
        const opp=(q('#v3-opp',root).value||'').trim();
        if(!opp) return;
        const out=await jpost('pet/pvp/challenge',{opponent:opp});
        if(!out.ok||!out.j?.ok){ setTxt('#v3-battle-status', out.j?.error || 'Challenge failed'); return; }
        S.matchId=out.j.matchId||''; q('#v3-match',root).value=S.matchId; localStorage.setItem('prism_pvp_match_id',S.matchId);
        setTxt('#v3-battle-status','Challenge created. Share Match ID.');
      });

      q('#v3-ai',root)?.addEventListener('click', async ()=>{
        const out=await jpost('pet/pvp/ai/start',{});
        if(!out.ok||!out.j?.ok){ setTxt('#v3-battle-status', out.j?.error || 'AI start failed'); return; }
        S.matchId=out.j.matchId||''; q('#v3-match',root).value=S.matchId; localStorage.setItem('prism_pvp_match_id',S.matchId);
        renderState(out.j.state);
      });

      q('#v3-load',root)?.addEventListener('click',loadMatch);
      q('#v3-intent',root)?.addEventListener('change',()=>setTxt('#v3-intent-view', q('#v3-intent',root).value));

      (async()=>{
        buildPrompt();
        const me=await jget('session?ts='+Date.now());
        S.uid = Number(me.j?.userId || 0);
        await refreshBootstrap();
        await refreshGame();
        if(S.matchId){ q('#v3-match',root).value=S.matchId; loadMatch(); }
      })();
    })();
    </script>
    <?php
}, 1000005000);
