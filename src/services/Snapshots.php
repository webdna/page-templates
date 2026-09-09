<?php

namespace webdna\pagetemplates\services;

use webdna\pagetemplates\exceptions\UnsupportedSnapshotVersionException;
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
     * Prepares a snapshot for replay onto a new page, reporting anything it could not place.
     *
     * @param array $snapshot as written by capture()
     * @param array<string, string[]> $allowedBlockTypes field handle => the entry type handles that
     *   field currently allows. Field handles are globally unique in Craft 5, so one flat map
     *   covers every nesting level.
     * @return array{fields: array, droppedBlockTypes: string[]}
     */
    public function prepareForReproduction(array $snapshot, array $allowedBlockTypes): array
    {
        $this->assertReadableVersion($snapshot['version'] ?? null);

        $dropped = [];

        $fields = $this->prepareFields($snapshot['fields'] ?? [], $allowedBlockTypes, $dropped);

        return [
            'fields' => $fields,
            // Always present, even when empty: a caller must be able to tell "nothing was lost"
            // from "the question was never answered". Deduplicated because this is shown to an
            // editor — five dropped image blocks are one problem, not five.
            'droppedBlockTypes' => array_values(array_unique($dropped)),
        ];
    }

    /**
     * @throws UnsupportedSnapshotVersionException
     */
    private function assertReadableVersion(mixed $version): void
    {
        if (!is_int($version) || $version < 1) {
            throw new UnsupportedSnapshotVersionException(
                'This template records no usable snapshot format version, so it cannot be read.',
            );
        }

        if ($version > self::FORMAT_VERSION) {
            throw new UnsupportedSnapshotVersionException(sprintf(
                'This template was written in snapshot format %d, but this build understands up to %d.',
                $version,
                self::FORMAT_VERSION,
            ));
        }
    }

    /**
     * @param string[] $dropped collected by reference across every nesting level
     */
    private function prepareFields(array $fieldValues, array $allowedBlockTypes, array &$dropped): array
    {
        $prepared = [];

        foreach ($fieldValues as $handle => $value) {
            $prepared[$handle] = is_array($value) && $this->isBlockList($value)
                ? $this->prepareBlocks($handle, $value, $allowedBlockTypes, $dropped)
                : $value;
        }

        return $prepared;
    }

    /**
     * @param string[] $dropped
     */
    private function prepareBlocks(string $fieldHandle, array $blocks, array $allowedBlockTypes, array &$dropped): array
    {
        $allowed = $allowedBlockTypes[$fieldHandle] ?? [];
        $kept = [];

        foreach ($blocks as $key => $block) {
            if (!in_array($block['type'], $allowed, true)) {
                $dropped[] = $block['type'];
                continue;
            }

            // Belt and braces for BR-7: capture already strips uids, but replaying a snapshot
            // written by an older build must not be able to collide either.
            unset($block['uid']);

            if (isset($block['fields']) && is_array($block['fields'])) {
                $block['fields'] = $this->prepareFields($block['fields'], $allowedBlockTypes, $dropped);
            }

            $kept[$key] = $block;
        }

        return $kept;
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
     * A Matrix value is a non-empty list of blocks, each carrying an entry type handle *and* a
     * fields array — Craft's serialisation always emits both.
     *
     * Requiring `fields` as well as `type` is what keeps other array-valued fields out. A relation
     * field's list of ids fails on `is_array`, but a Table field whose column happens to be named
     * `type` would otherwise pass on `type` alone and have its rows rewritten as blocks.
     */
    private function isBlockList(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item) || !isset($item['type'], $item['fields'])) {
                return false;
            }
        }

        return true;
    }
}
