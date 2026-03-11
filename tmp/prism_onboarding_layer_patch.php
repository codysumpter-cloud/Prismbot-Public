
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
