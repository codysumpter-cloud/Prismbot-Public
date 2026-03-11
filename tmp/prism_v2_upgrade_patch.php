<?php
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
