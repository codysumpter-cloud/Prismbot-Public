
// ===== Prism cohesive cleanup pass (final layering squash, non-destructive, 2026-03-10) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $isAdmin = is_user_logged_in() && current_user_can('manage_options');
    ?>
    <style id="prism-cohesive-cleanup-pass-css">
      .prism-clean-hide{display:none !important}
      #prism-game-shell .pph-card{margin-top:0}
      #prism-sec-start,#prism-sec-game,#prism-sec-battle,#prism-sec-how,#prism-sec-char{scroll-margin-top:78px}
      #prism-sec-battle .pph-card{border-color:#6f7cdc !important;background:#0f1538 !important}
      #prism-sec-game .pph-card{border-color:#6f7cdc !important}
    </style>
    <script id="prism-cohesive-cleanup-pass-js">
    (()=>{
      const IS_ADMIN=<?php echo $isAdmin ? 'true':'false'; ?>;
      const url=new URL(location.href);
      const DEV_MODE = IS_ADMIN || url.searchParams.get('dev')==='1' || localStorage.getItem('prism_dev_mode')==='1';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>[...r.querySelectorAll(s)];

      function hide(el){ if(el) el.classList.add('prism-clean-hide'); }

      function cleanup(){
        const shell=q('#prism-game-shell');
        if(!shell) return;

        // Ensure strict section order
        const order=['#prism-sec-start','#prism-sec-game','#prism-sec-battle','#prism-sec-how','#prism-sec-char'];
        const parent=shell;
        order.forEach(sel=>{ const el=q(sel,parent); if(el) parent.appendChild(el); });

        // Hide legacy/duplicate blocks outside the cohesive shell
        qa('.pph-card, details, section, article').forEach(el=>{
          if(el.closest('#prism-game-shell')) return;
          const id=(el.id||'').toLowerCase();
          const txt=((q('h1,h2,h3,h4,summary',el)?.textContent)||'').toLowerCase();
          const legacy = id.startsWith('prism-bv2') || id.startsWith('prism-battle-v2') || id.startsWith('prism-curated') || id.startsWith('prism-showdown') || id.startsWith('prism-stage') || id.startsWith('prism-hard') || id.startsWith('prism-next') || id.startsWith('prism-pixellab') || txt.includes('pvp arena') || txt.includes('battle arena v2') || txt.includes('quick tools') || txt.includes('mod tools') || txt.includes('replay') || txt.includes('spectate');
          if(legacy && !DEV_MODE) hide(el);
        });

        // Hide noisy legacy script-generated surfaces even if still in DOM
        const noisyIds=[
          '#prism-battle-v2-panel','#prism-bv2-panel','#prism-bv2-state','#prism-bv2-log','#prism-bv2-ranks','#prism-bv2-rank','#prism-bv2-start',
          '#prism-curated-grid','#prism-curated-pack-ui','#prism-showdown-plus-ui','#prism-showdown-pro-ui','#prism-showdown-unify-ui',
          '#prism-pixellab-direct-ui','#prism-pixellab-byok-ui','#prism-pixellab-starterpack-ui','#prism-creatures-premium-pass',
          '#prism-stage-mode-overlay','#prism-stagepack-ui-lock','#prism-next-targets-layout'
        ];
        if(!DEV_MODE) noisyIds.forEach(sel=>hide(q(sel)));

        // Keep only one combat log area in battle section
        const battle=q('#prism-sec-battle');
        if(battle){
          const logs=qa('#pvp-log, #prism-bv2-log, pre', battle).filter(el=>{
            const id=(el.id||'').toLowerCase();
            return id==='pvp-log' || id==='prism-bv2-log' || (id==='' && /match|winner|turn|used/i.test(el.textContent||''));
          });
          logs.slice(1).forEach(hide);
        }

        // Ensure login card appears inside GAME PANEL for logged-out users
        const game=q('#prism-sec-game #prism-game-main');
        if(game && !q('#pph-pet-panel')){
          const loginCard=qa('.pph-card').find(c=>{
            if(c.closest('#prism-game-shell')) return false;
            const t=(c.textContent||'').toLowerCase();
            return t.includes('log in to adopt') || t.includes('create account') || t.includes('login');
          });
          if(loginCard && !loginCard.closest('#prism-game-shell')) game.appendChild(loginCard);
        }

        // Compact start buttons should always point to the right section
        const sec={
          start:q('#prism-sec-start'), game:q('#prism-sec-game'), battle:q('#prism-sec-battle'), how:q('#prism-sec-how'), char:q('#prism-sec-char')
        };
        const bind=(id,target)=>{
          const b=q(id); if(!b) return;
          b.onclick=(e)=>{ e.preventDefault(); target?.scrollIntoView({behavior:'smooth',block:'start'}); };
        };
        bind('#prism-go-character',sec.char);
        bind('#prism-go-generate',sec.game);
        bind('#prism-go-pvp',sec.battle);
        bind('#prism-go-ai',sec.battle);
      }

      let runs=0;
      const pump=()=>{ cleanup(); runs++; if(runs<18) setTimeout(pump, 450); };
      pump();
      const mo=new MutationObserver(()=>cleanup());
      mo.observe(document.documentElement,{childList:true,subtree:true});
    })();
    </script>
    <?php
}, 1000003900);
