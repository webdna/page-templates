<?php

namespace webdna\pagetemplates\services;

use yii\base\Component;

/**
 * Captures and replays a page's content.
 *
 * Every method here is a pure transform over Craft's serialized field-value shape — arrays in,
 * arrays out, no element queries and no booted Craft application. That is deliberate: this is
 * where the fidelity promise lives, so it has to be testable without the framework.
 */
class Snapshots extends Component
{
    /**
     * The snapshot format this build writes (BR-22).
     *
     * Every snapshot records the version it was written with, so a later change to what is
     * captured can migrate existing templates rather than orphan them — and so reproduction can
     * refuse a version it does not understand instead of guessing and producing a wrong page.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Captures serialized field values as a snapshot.
     *
     * @param array $fieldValues the output of Entry::getSerializedFieldValues()
     * @param bool $includeContent false for a structure-only template
     */
    public function capture(array $fieldValues, bool $includeContent): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'fields' => $includeContent
                ? $this->withoutUids($fieldValues)
                : $this->stripContent($fieldValues),
        ];
    }

    /**
     * Strips every nested-element uid from a block tree (BR-7).
     *
     * stripContent() has no need of this: it rebuilds each block from scratch with only its type,
     * enabled flag and fields, so a uid cannot survive it.
     */
    private function withoutUids(array $fieldValues): array
    {
        $clean = [];

        foreach ($fieldValues as $handle => $value) {
            $clean[$handle] = $this->withoutUidsInValue($value);
        }

        return $clean;
    }

    private function withoutUidsInValue(mixed $value): mixed
    {
        if (!is_array($value) || !$this->isBlockList($value)) {
            return $value;
        }

        return array_map(function(array $block): array {
            unset($block['uid']);

            if (isset($block['fields']) && is_array($block['fields'])) {
                $block['fields'] = $this->withoutUids($block['fields']);
            }

            return $block;
        }, $value);
    }

    /**
     * Empties every field value, leaving only the block structure behind.
     */
    public function stripContent(array $fieldValues): array
    {
        $stripped = [];

        foreach ($fieldValues as $handle => $value) {
            $stripped[$handle] = $this->stripValue($value);
        }

        return $stripped;
    }

    /**
     * @return mixed null for a scalar, [] for a plain array, the emptied block list for a Matrix
     */
    private function stripValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        if ($this->isBlockList($value)) {
            // array_map preserves the keys of a single array, so block order survives. The keys
            // themselves are arbitrary — Craft treats them only as sort order on replay.
            return array_map(fn(array $block): array => [
                'type' => $block['type'],
                'enabled' => $block['enabled'] ?? true,
                'fields' => $this->stripContent($block['fields'] ?? []),
            ], $value);
        }

        return [];
    }

    /**
     * A Matrix value is a non-empty list of blocks, each carrying its entry type handle. Any other
     * array — a relation field's list of ids, say — is not.
     */
    private function isBlockList(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item) || !isset($item['type'])) {
                return false;
            }
        }

        return true;
    }
}
