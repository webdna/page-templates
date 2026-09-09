<?php

namespace webdna\pagetemplates\tests\unit;

use Codeception\Test\Unit;
use webdna\pagetemplates\services\Snapshots;

/**
 * The snapshot engine, which is where the whole fidelity promise lives.
 *
 * Everything here is a pure array transform over Craft's serialized field-value shape, so none of
 * it needs a booted Craft application. See docs/specs/2026-09-09-page-templates-engine.md.
 */
class SnapshotsTest extends Unit
{
    private Snapshots $snapshots;

    protected function _before(): void
    {
        $this->snapshots = new Snapshots();
    }

    public function testStripContentEmptiesAScalarField(): void
    {
        $stripped = $this->snapshots->stripContent([
            'heading' => 'Campaign landing page',
        ]);

        $this->assertSame(['heading' => null], $stripped);
    }

    public function testStripContentKeepsBlockTypeAndOrderWhileEmptyingBlockFields(): void
    {
        $stripped = $this->snapshots->stripContent([
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'First']],
                'b2' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Second']],
            ],
        ]);

        $this->assertSame([
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => null]],
                'b2' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => null]],
            ],
        ], $stripped);
    }

    /**
     * Regression guard: passes on the recursion that the previous test drove, and exists so a
     * later change cannot quietly stop stripping at the first level.
     */
    public function testStripContentRecursesIntoNestedBlocks(): void
    {
        $stripped = $this->snapshots->stripContent([
            'blocks' => [
                'b1' => [
                    'type' => 'columnsBlock',
                    'enabled' => true,
                    'fields' => [
                        'heading' => 'Two nested columns',
                        'columns' => [
                            'c1' => ['type' => 'column', 'enabled' => true, 'fields' => ['heading' => 'Left']],
                            'c2' => ['type' => 'column', 'enabled' => true, 'fields' => ['heading' => 'Right']],
                        ],
                    ],
                ],
            ],
        ]);

        $columns = $stripped['blocks']['b1']['fields']['columns'];

        $this->assertSame(['c1', 'c2'], array_keys($columns), 'nested block order is kept');
        $this->assertSame(['column', 'column'], array_column($columns, 'type'), 'nested block types are kept');
        $this->assertNull($columns['c1']['fields']['heading'], 'nested content is emptied');
        $this->assertNull($columns['c2']['fields']['heading'], 'nested content is emptied');
    }

    /**
     * Regression guard for BR-6: relations and assets arrive empty too, not merely stripped of
     * their text. An empty array is the right empty for a relation field, where null is not.
     */
    public function testStripContentEmptiesRelationAndAssetFields(): void
    {
        $stripped = $this->snapshots->stripContent([
            'image' => [30],
            'relatedPages' => [31, 32],
        ]);

        $this->assertSame(['image' => [], 'relatedPages' => []], $stripped);
    }

    /**
     * BR-7. Craft 5.11 applies a caller-supplied nested-element UID verbatim, so a snapshot that
     * carried one would give every page built from that template colliding nested UIDs. Craft's own
     * serialisation does not currently emit `uid`, which is exactly why this has to be enforced
     * rather than assumed: if a future release starts emitting it, capture must still drop it.
     */
    public function testCaptureNeverStoresANestedElementUid(): void
    {
        $snapshot = $this->snapshots->capture([
            'blocks' => [
                'b1' => [
                    'type' => 'textBlock',
                    'uid' => '11111111-2222-3333-4444-555555555555',
                    'enabled' => true,
                    'fields' => [
                        'columns' => [
                            'c1' => [
                                'type' => 'column',
                                'uid' => '66666666-7777-8888-9999-000000000000',
                                'enabled' => true,
                                'fields' => ['heading' => 'Left'],
                            ],
                        ],
                    ],
                ],
            ],
        ], true);

        $block = $snapshot['fields']['blocks']['b1'];

        $this->assertArrayNotHasKey('uid', $block, 'no uid at the top level');
        $this->assertArrayNotHasKey('uid', $block['fields']['columns']['c1'], 'and none when nested');
        $this->assertSame('Left', $block['fields']['columns']['c1']['fields']['heading'], 'content still survives');
    }

    public function testCaptureWithoutContentProducesAStructureOnlySnapshot(): void
    {
        $snapshot = $this->snapshots->capture([
            'heading' => 'Campaign landing page',
            'image' => [30],
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'First']],
            ],
        ], false);

        $this->assertSame([
            'heading' => null,
            'image' => [],
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => null]],
            ],
        ], $snapshot['fields']);
    }

    /**
     * BR-22. Without a recorded version, a later change to what is captured cannot migrate old
     * rows — it can only orphan them.
     */
    public function testCaptureRecordsTheFormatVersion(): void
    {
        $snapshot = $this->snapshots->capture(['heading' => 'x'], true);

        $this->assertSame(Snapshots::FORMAT_VERSION, $snapshot['version']);
    }
}
