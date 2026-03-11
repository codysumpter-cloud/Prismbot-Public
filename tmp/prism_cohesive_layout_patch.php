
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
