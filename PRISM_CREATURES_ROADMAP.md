# Prism Creatures Roadmap

## Vision
Build a creature ecosystem as deep and engaging as monster-collection franchises — fully pixel-art, with user-submitted custom sprites, social features, and battles.

---

## Phase 1: Foundation ✅ (Live)
- [x] Creature visible on Prism Creatures page
- [x] Care actions: feed, play, rest
- [x] Stats: hunger, happiness, energy, health
- [x] Stage progression: baby → teen → adult
- [x] Skin system (unlock by high score)
- [x] Custom name

---

## Phase 2: Personalization 🔄 (In Progress)
- [ ] Custom sprite upload (PNG, max 64x64)
- [ ] Multiple creature species/types
- [ ] Personality traits (affect evolution/form)
- [ ] Creature animations/poses based on mood

---

## Phase 3: Social Features 📋 (Planned)
- [ ] Visit other players' creatures
- [ ] Creature trading system
- [ ] Global creature directory
- [ ] Friend list / creature followers

---

## Phase 4: Battles ⚔️ (Planned)
- [ ] Turn-based creature battles
- [ ] Attack/defend/heal moves
- [ ] Stat-based damage calculation
- [ ] Battle history log
- [ ] Battle leaderboard

---

## Phase 5: Ecosystem Expansion 🌟 (Future)
- [ ] User-submitted custom creature sprites
- [ ] Creature breeding (combine traits)
- [ ] Rare/legendary spawn chances
- [ ] Seasonal events
- [ ] Achievement unlocks
- [ ] Cross-site creature portability

---

## Technical Notes
- Creature data stored in user_meta: `prismtek_pet_state`
- Sprite upload: `/wp-content/uploads/creatures/`
- Battle logic: stat-based (attack vs defense + random)
- Public profiles: `/profile/{username}`
