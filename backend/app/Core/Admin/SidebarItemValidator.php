<?php

namespace App\Core\Admin;

use Illuminate\Support\Facades\Log;

/**
 * Validates plugin-contributed sidebar sections before they are merged into
 * the admin sidebar. Strips invalid items and logs errors; never throws.
 */
class SidebarItemValidator
{
    private const REQUIRED_SECTION_FIELDS = ['id', 'label', 'icon', 'order'];

    /**
     * Validate and strip invalid plugin sidebar sections.
     *
     * @param  array  $sections     Plugin-contributed sections (not core sections)
     * @param  array  $existingIds  IDs already claimed by core sections (passed by reference to track collisions within plugins too)
     * @return array  Cleaned sections with invalid entries removed
     */
    public static function validate(array $sections, array &$existingIds): array
    {
        $valid = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                Log::warning('SidebarItemValidator: non-array sidebar section stripped', ['section' => $section]);
                continue;
            }

            // Check required fields
            $missing = array_filter(
                self::REQUIRED_SECTION_FIELDS,
                fn($field) => !isset($section[$field]) || $section[$field] === ''
            );

            if (!empty($missing)) {
                Log::warning('SidebarItemValidator: section missing required fields', [
                    'section_id' => $section['id'] ?? '(no id)',
                    'missing'    => array_values($missing),
                ]);
                continue;
            }

            // Check order is numeric
            if (!is_numeric($section['order'])) {
                Log::warning('SidebarItemValidator: section has non-numeric order', [
                    'section_id' => $section['id'],
                    'order'      => $section['order'],
                ]);
                continue;
            }

            // Check for id collision
            $id = $section['id'];
            if (in_array($id, $existingIds, true)) {
                Log::warning('SidebarItemValidator: duplicate sidebar section id stripped', [
                    'id' => $id,
                ]);
                continue;
            }

            // Register id to catch intra-plugin duplicates too
            $existingIds[] = $id;

            // Validate children (warn only — do not strip the section for bad children)
            if (isset($section['children']) && is_array($section['children'])) {
                $section['children'] = self::validateChildren($section['children'], $id);
            }

            $valid[] = $section;
        }

        return $valid;
    }

    /**
     * Validate children of a section. Children missing 'label' are stripped.
     * Children referencing a non-existent named route are warned but kept
     * (routes may not be loaded at sidebar-build time).
     */
    private static function validateChildren(array $children, string $parentId): array
    {
        $valid = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                Log::warning('SidebarItemValidator: non-array child stripped', ['parent' => $parentId]);
                continue;
            }

            if (empty($child['label'])) {
                Log::warning('SidebarItemValidator: child missing label, stripped', ['parent' => $parentId, 'child' => $child]);
                continue;
            }

            // Warn (but keep) if a named route is specified and does not exist
            if (!empty($child['route'])) {
                try {
                    if (!\Illuminate\Support\Facades\Route::has($child['route'])) {
                        Log::debug('SidebarItemValidator: child route not found (may load later)', [
                            'parent' => $parentId,
                            'route'  => $child['route'],
                        ]);
                    }
                } catch (\Throwable) {
                    // Route facade may not be ready during early boot — ignore
                }
            }

            $valid[] = $child;
        }

        return $valid;
    }
}
