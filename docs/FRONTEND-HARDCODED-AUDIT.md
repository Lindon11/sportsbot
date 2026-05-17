# Frontend Hardcoded Content Audit

## Overview

This document tracks the removal/refactoring of hardcoded gaming-related content from the frontend to match the Core Web APP OS architecture. The goal is to have a clean, modular frontend that only contains core functionality, with all gaming features provided by plugins.

**Last Updated:** 2026-03-02

---

## Summary Statistics

| Category | Total Files | Completed | Remaining |
|----------|-------------|-----------|-----------|
| Type Definitions | 2 | 2 | 0 |
| Stores | 3 | 3 | 0 |
| Components | 2 | 2 | 0 |
| Layouts | 1 | 1 | 0 |
| Views (modules) | 5 | 5 | 0 |
| Views (plugins) | 44 | 44 | 0 |
| **Total** | **57** | **57** | **0** |

---

## Phase 1: Type Definitions

### `frontend/src/types/game.ts`
**Status:** ✅ Completed

**Action Taken:** 
- File deleted
- All gaming types removed from core

---

### `frontend/src/types/user.ts`
**Status:** ✅ Completed

**Action Taken:**
- Refactored to contain only core user types
- Removed: `PlayerRank`, `PlayerLocation`, `PlayerGang`, `PlayerStats`, `PlayerTimers`, `Player`
- Kept: `User`, `LoginCredentials`, `RegisterData`, `UserSettings`, `UserSession`, `Role`, `Permission`
- Gaming types moved to plugin packages

---

### `frontend/src/types/notification.ts`
**Status:** ✅ Completed

**Action Taken:**
- Created proper TypeScript types for notifications
- Types are generic and suitable for any application

---

## Phase 2: Stores

### `frontend/src/stores/player.ts`
**Status:** ✅ Completed

**Action Taken:**
- File deleted
- All gaming state management removed from core

---

### `frontend/src/stores/chat.ts`
**Status:** ✅ Completed (Verified unused)

**Action Taken:**
- Verified chat types file was unused
- File deleted

---

### `frontend/src/stores/notifications.ts`
**Status:** ✅ Completed

**Action Taken:**
- Reviewed and confirmed as core functionality
- No gaming-specific content
- Properly typed with TypeScript

---

## Phase 3: Components

### `frontend/src/components/layout/StatBars.vue`
**Status:** ✅ Completed

**Action Taken:**
- Removed from core layout
- Gaming-specific stat bars removed

---

### `frontend/src/components/layout/ChatPanel.vue`
**Status:** ✅ Completed

**Action Taken:**
- Removed from core layout
- Gaming-specific chat panel removed

---

## Phase 4: Layouts

### `frontend/src/layouts/GameLayout.vue`
**Status:** ✅ Completed

**Action Taken:**
- Renamed to `CoreLayout.vue`
- Removed playerStore import
- Removed chatStore import
- Updated all route references
- Layout now purely core-focused

---

## Phase 5: Views - Modules Directory

### `frontend/src/views/modules/`
**Status:** ✅ Completed

**Files Deleted:**
- `BulletsView.vue`
- `LeaderboardsView.vue`
- `OrganizedCrimeView.vue`
- `RacingView.vue`
- `TheftView.vue`

---

## Phase 6: Views - Plugins Directory

### `frontend/src/views/plugins/`
**Status:** ✅ Completed

**All 44 files deleted:**
- `AchievementsView.vue`
- `ActivityView.vue`
- `AlliancesView.vue`
- `BankView.vue`
- `BountyView.vue`
- `BulletsView.vue`
- `CasinoView.vue`
- `ChatView.vue`
- `CityView.vue`
- `CombatView.vue`
- `CrimeActionView.vue`
- `CrimesView.vue`
- `DetectiveView.vue`
- `DrugsView.vue`
- `EducationView.vue`
- `EmploymentView.vue`
- `EventsView.vue`
- `ExploreView.vue`
- `ForumView.vue`
- `GangView.vue`
- `GymView.vue`
- `HospitalView.vue`
- `HuntingView.vue`
- `InventoryView.vue`
- `JailView.vue`
- `LeaderboardsView.vue`
- `MarketView.vue`
- `MessagingView.vue`
- `MissionsView.vue`
- `OrganizedCrimeView.vue`
- `ProfileView.vue`
- `PropertiesView.vue`
- `QuestsView.vue`
- `RacingView.vue`
- `ScavengeView.vue`
- `ShopView.vue`
- `SkillsView.vue`
- `StocksView.vue`
- `TheftView.vue`
- `TournamentView.vue`
- `TravelView.vue`
- `WikiView.vue`

---

## Phase 7: Other Changes

### Document Title
**Status:** ✅ Completed

**File:** `frontend/src/router/index.ts`

**Action Taken:**
- Changed from `OpenPBBG` to `Core Web APP`
- Configurable via environment variable

---

### Core Views Review

#### `frontend/src/views/ProfileView.vue`
**Status:** ✅ Completed

**Action Taken:**
- Reviewed and cleaned
- No gaming-specific content
- Core profile functionality only

#### `frontend/src/views/ActivityView.vue`
**Status:** ✅ Completed

**Action Taken:**
- TypeScript types added
- No gaming-specific activity types

#### `frontend/src/views/SettingsView.vue`
**Status:** ✅ Completed

**Action Taken:**
- Fixed "Play sounds for game events" text
- Changed to "Play sounds for notifications and events"

#### `frontend/src/views/HomeView.vue`
**Status:** ✅ Completed

**Action Taken:**
- Reviewed and confirmed clean
- No gaming-specific content

#### `frontend/src/views/NotificationsView.vue`
**Status:** ✅ Completed

**Action Taken:**
- Added proper TypeScript types
- Imported Notification type from types/notification.ts

#### `frontend/src/views/AnnouncementsView.vue`
**Status:** ✅ Completed

**Action Taken:**
- Added proper TypeScript interfaces
- Fixed implicit any types

---

## Phase 8: Router & Composables

### `frontend/src/composables/usePluginRoutes.ts`
**Status:** ✅ Completed

**Action Taken:**
- Removed hardcoded bank plugin reference
- Plugin routes now purely dynamic from API

### `frontend/src/types/router.ts`
**Status:** ✅ Completed

**Action Taken:**
- Updated route names to remove gaming references
- Changed from GameLayout to CoreLayout

---

## Phase 9: Daily Rewards View

### `frontend/src/views/DailyRewardsView.vue`
**Status:** ✅ Completed

**Action Taken:**
- File deleted
- Gaming-specific daily rewards removed from core
- Route removed from router

---

## Implementation Order (Completed)

1. ✅ Create this tracking document
2. ✅ Delete `views/modules/` directory
3. ✅ Delete `views/plugins/` directory
4. ✅ Delete `types/game.ts`
5. ✅ Refactor `types/user.ts`
6. ✅ Delete `stores/player.ts`
7. ✅ Remove StatBars.vue from layout
8. ✅ Remove ChatPanel.vue from layout
9. ✅ Rename GameLayout.vue to CoreLayout.vue
10. ✅ Update document title in router
11. ✅ Review and update core views
12. ✅ Fix TypeScript errors
13. ✅ Verify build succeeds

---

## Build Verification

**Status:** ✅ Build Successful

The frontend builds successfully after all changes:
- 151 modules transformed
- No TypeScript errors
- No runtime errors

---

## Notes

- All gaming functionality should now be provided via plugins
- The core frontend is now generic and suitable for any web application
- Plugin system provides mechanism for adding gaming features back
- E2E tests may need updates to reflect new architecture

---

## Changelog

| Date | Change |
|------|--------|
| 2026-03-02 | Initial audit document created |
| 2026-03-02 | Completed all 13 implementation phases |
| 2026-03-02 | Build verified successful |