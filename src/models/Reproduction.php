<?php

namespace webdna\pagetemplates\models;

use craft\elements\Entry;

/**
 * The outcome of producing a page from a template.
 *
 * Carries what was lost alongside the page deliberately: a caller that only received the entry
 * could not tell a faithful reproduction from a lossy one. Losing content is survivable; losing it
 * silently is not, and Craft discards a disallowed block with no error and no log entry.
 */
readonly class Reproduction
{
    /**
     * @param string[] $droppedBlockTypes block types the target field no longer allows (BR-27).
     *   Empty when nothing was lost — never absent.
     * @param string[] $emptiedFields handles of fields that held content in the snapshot but
     *   arrived empty (BR-28). Catches a field type whose serialized form does not feed back
     *   through its own normalisation, and a relation whose target has since been deleted.
     */
    public function __construct(
        public Entry $entry,
        public array $droppedBlockTypes,
        public array $emptiedFields = [],
    ) {
    }

    /**
     * Whether the page is a complete reproduction of what was captured.
     */
    public function isFaithful(): bool
    {
        return $this->droppedBlockTypes === [] && $this->emptiedFields === [];
    }
}
