<?php

namespace webdna\pagetemplates\models;

use Craft;
use craft\base\Model;
use craft\models\EntryType;
use craft\models\Section;
use webdna\pagetemplates\services\Snapshots;

/**
 * A saved, named starting point for new pages.
 *
 * Sections and entry types are held as **UIDs**, not ids: applying project config can delete and
 * recreate one with the same uid and a new id, so an id-keyed reference would silently come to
 * point at the wrong entity. They are resolved when read, and a uid that no longer resolves is
 * treated as absent rather than repaired behind the curator's back (BR-20, BR-21).
 */
class PageTemplate extends Model
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $description = null;

    /**
     * The kind of page this template makes. Set once at capture and never changed (BR-2).
     */
    public ?string $entryTypeUid = null;

    public bool $includeContent = true;

    /**
     * The captured field values, in Craft's serialized shape.
     */
    public array $snapshot = [];

    public int $snapshotVersion = Snapshots::FORMAT_VERSION;

    /**
     * The snapshot this template held before its content was last edited, as a whole envelope —
     * `['version' => n, 'fields' => [...]]` — or null if it has never been edited.
     *
     * One step of undo, not a history. Overwriting a template is otherwise irreversible, and the
     * page it was captured from may since have changed or gone.
     */
    public ?array $previousSnapshot = null;

    /**
     * Provenance only. Nullable, because deleting the example page must leave the template fully
     * usable (BR-25) — a snapshot is a copy, not a reference.
     */
    public ?int $sourceEntryId = null;
    public ?int $sourceSiteId = null;

    public ?int $sortOrder = null;
    public ?string $uid = null;

    /**
     * @var string[]
     */
    private array $_allowedSectionUids = [];

    /**
     * @param string[] $uids
     */
    public function setAllowedSectionUids(array $uids): void
    {
        $this->_allowedSectionUids = array_values(array_unique($uids));
    }

    /**
     * @return string[]
     */
    public function getAllowedSectionUids(): array
    {
        return $this->_allowedSectionUids;
    }

    /**
     * The entry type this template makes, or null if it no longer exists (BR-20).
     */
    public function getEntryType(): ?EntryType
    {
        if ($this->entryTypeUid === null) {
            return null;
        }

        return Craft::$app->getEntries()->getEntryTypeByUid($this->entryTypeUid);
    }

    /**
     * The areas this template may be used in, with any uid that no longer resolves left out
     * (BR-21). Ordering follows the stored uids so it is stable.
     *
     * @return Section[]
     */
    public function getAllowedSections(): array
    {
        $entriesService = Craft::$app->getEntries();

        $sections = array_map(
            fn(string $uid): ?Section => $entriesService->getSectionByUid($uid),
            $this->_allowedSectionUids,
        );

        return array_values(array_filter($sections));
    }

    /**
     * Set false when the stored snapshot could not be decoded at all.
     *
     * A corrupt snapshot must not stop a template being listed or deleted — a curator has to be
     * able to clear it up — so the storage layer records the failure here rather than throwing
     * while loading.
     */
    public bool $snapshotDecoded = true;

    /**
     * Whether this template can currently produce a page at all.
     *
     * Derived, never stored: it depends on whether the entry type still exists, which changes
     * outside this plugin, so a stored flag would go stale the moment a section was removed.
     */
    public function getIsUsable(): bool
    {
        return $this->getEntryType() !== null && $this->getIsReadable();
    }

    /**
     * Whether this build can read the stored snapshot: it decoded, and its format is one this
     * build understands (BR-22).
     */
    public function getIsReadable(): bool
    {
        return $this->snapshotDecoded && $this->snapshotVersion <= Snapshots::FORMAT_VERSION;
    }

    /**
     * Whether this template's content can be edited (BR-29).
     *
     * A structure-only template carries no content, so producing a page from it and capturing it
     * back would capture emptiness. Editing one is offered as re-capturing from a page instead.
     */
    public function getIsContentEditable(): bool
    {
        return $this->includeContent && $this->getIsUsable();
    }

    /**
     * Whether there is a previous snapshot to go back to (BR-33).
     */
    public function getHasPreviousSnapshot(): bool
    {
        return $this->previousSnapshot !== null;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['name', 'entryTypeUid', 'snapshotVersion'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['snapshotVersion'], 'integer', 'min' => 1],
            [['sourceEntryId', 'sourceSiteId', 'sortOrder'], 'integer'],
        ];
    }
}
