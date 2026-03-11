
// ===== Prism cohesive safe minimal cleanup (one-shot, low-risk, 2026-03-10) =====
add_action('wp_footer', function(){
    if (!function_exists('is_page') || !is_page('prism-creatures')) return;
    $isAdmin = is_user_logged_in() && current_user_can('manage_options');
    ?>
    <style id="prism-safe-min-cleanup-css">
      .prism-safe-hidden{display:none !important}
      #prism-game-shell{display:grid;gap:12px}
      #prism-sec-start,#prism-sec-game,#prism-sec-battle,#prism-sec-how,#prism-sec-char{scroll-margin-top:76px}
    </style>
    <script id="prism-safe-min-cleanup-js">
    (()=>{
      const IS_ADMIN=<?php echo $isAdmin ? 'true':'false'; ?>;
      const DEV_MODE = IS_ADMIN || new URL(location.href).searchParams.get('dev')==='1' || localStorage.getItem('prism_dev_mode')==='1';
      const q=(s,r=document)=>r.querySelector(s);
      const qa=(s,r=document)=>[...r.querySelectorAll(s)];

      const shell=q('#prism-game-shell');
      if(!shell) return;

      // 1) enforce section order once (no observers)
      const ordered=['#prism-sec-start','#prism-sec-game','#prism-sec-battle','#prism-sec-how','#prism-sec-char'];
      ordered.forEach(sel=>{ const el=q(sel,shell); if(el) shell.appendChild(el); });

      // 2) hide known legacy panels only (low-risk explicit ids)
      if(!DEV_MODE){
        [
          '#prism-battle-v2-panel','#prism-bv2-panel','#prism-bv2-state','#prism-bv2-log','#prism-bv2-ranks','#prism-bv2-rank','#prism-bv2-start',
          '#prism-curated-grid','#prism-curated-pack-ui','#prism-showdown-plus-ui','#prism-showdown-pro-ui','#prism-showdown-unify-ui',
          '#prism-pixellab-direct-ui','#prism-pixellab-byok-ui','#prism-pixellab-starterpack-ui','#prism-creatures-premium-pass'
        ].forEach(sel=>{ const el=q(sel); if(el && !el.closest('#prism-sec-battle')) el.classList.add('prism-safe-hidden'); });

        // hide duplicate old top cards if still outside shell
        qa('.pph-card').forEach(card=>{
          if(card.closest('#prism-game-shell')) return;
          const t=(card.textContent||'').toLowerCase();
          if(t.includes('pvp arena') || t.includes('battle arena v2') || t.includes('mod tools') || t.includes('quick tools')){
            card.classList.add('prism-safe-hidden');
          }
        });
      }

      // 3) wire start buttons safely
      const jump=(btn, target)=>{ const b=q(btn); if(!b) return; b.addEventListener('click',()=>target?.scrollIntoView({behavior:'smooth',block:'start'})); };
      jump('#prism-go-character', q('#prism-sec-char'));
      jump('#prism-go-generate', q('#prism-sec-game'));
      jump('#prism-go-pvp', q('#prism-sec-battle'));
      jump('#prism-go-ai', q('#prism-sec-battle'));
    })();
    </script>
    <?php
}, 1000003950);
