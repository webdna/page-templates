<?php

namespace webdna\pagetemplates\models;

use craft\elements\Entry;

/**
 * The outcome of producing a page from a template.
 *
 * Carries the dropped block types alongside the page deliberately: a caller that only received
 * the entry could not tell a faithful reproduction from a lossy one, and BR-27 exists precisely
 * because Craft discards a disallowed block with no error and no log entry.
 */
readonly class Reproduction
{
    /**
     * @param string[] $droppedBlockTypes empty when nothing was lost — never absent
     */
    public function __construct(
        public Entry $entry,
        public array $droppedBlockTypes,
    ) {
    }

    public function isFaithful(): bool
    {
        return $this->droppedBlockTypes === [];
    }
}
