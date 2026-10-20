<?php

namespace webdna\pagetemplates\tests\unit;

use Codeception\Test\Unit;
use webdna\pagetemplates\events\KeepValueEvent;
use webdna\pagetemplates\exceptions\UnsupportedSnapshotVersionException;
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

    /**
     * BR-27. Craft drops a block whose type a field no longer allows with no error and no log
     * entry, so content disappears untraceably. Reproduction has to notice and say what it lost.
     */
    public function testPrepareDropsDisallowedBlockTypesAndReportsThem(): void
    {
        $prepared = $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION,
            'fields' => [
                'blocks' => [
                    'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Kept']],
                    'b2' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['heading' => 'Lost']],
                ],
            ],
        ], ['blocks' => ['textBlock']]);

        $this->assertSame(['b1'], array_keys($prepared['fields']['blocks']), 'the allowed block survives');
        $this->assertSame(['imageBlock'], $prepared['droppedBlockTypes'], 'the disallowed one is named');
    }

    /**
     * The report is shown to an editor, so it names each lost block type once rather than once per
     * occurrence — five dropped image blocks are one problem, not five.
     */
    public function testPrepareReportsEachDroppedBlockTypeOnce(): void
    {
        $prepared = $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION,
            'fields' => [
                'blocks' => [
                    'b1' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => []],
                    'b2' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => []],
                    'b3' => ['type' => 'quoteBlock', 'enabled' => true, 'fields' => []],
                ],
            ],
        ], ['blocks' => ['textBlock']]);

        $this->assertSame([], $prepared['fields']['blocks'], 'nothing survives');
        $this->assertSame(['imageBlock', 'quoteBlock'], $prepared['droppedBlockTypes']);
    }

    /**
     * Regression guard: a block type removed from a *nested* Matrix must be caught too, not just
     * one at the top level.
     */
    public function testPrepareValidatesNestedBlockTypes(): void
    {
        $prepared = $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION,
            'fields' => [
                'blocks' => [
                    'b1' => [
                        'type' => 'columnsBlock',
                        'enabled' => true,
                        'fields' => [
                            'columns' => [
                                'c1' => ['type' => 'column', 'enabled' => true, 'fields' => ['heading' => 'Kept']],
                                'c2' => ['type' => 'wideColumn', 'enabled' => true, 'fields' => ['heading' => 'Lost']],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['blocks' => ['columnsBlock'], 'columns' => ['column']]);

        $columns = $prepared['fields']['blocks']['b1']['fields']['columns'];

        $this->assertSame(['c1'], array_keys($columns), 'the allowed nested block survives');
        $this->assertSame(['wideColumn'], $prepared['droppedBlockTypes'], 'the nested loss is reported');
    }

    /**
     * Regression guard for the output contract: the report is empty, never absent, so a caller can
     * distinguish "nothing was lost" from "nobody checked".
     */
    public function testPrepareReportsAnEmptyListWhenNothingIsDropped(): void
    {
        $prepared = $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION,
            'fields' => [
                'heading' => 'Campaign landing page',
                'blocks' => [
                    'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Kept']],
                ],
            ],
        ], ['blocks' => ['textBlock']]);

        $this->assertArrayHasKey('droppedBlockTypes', $prepared);
        $this->assertSame([], $prepared['droppedBlockTypes']);
        $this->assertSame('Campaign landing page', $prepared['fields']['heading'], 'plain fields pass through');
    }

    /**
     * BR-22 / TN-17. Guessing at a format it does not understand is how a template silently
     * produces a wrong page, which is the exact failure this whole spec exists to prevent.
     */
    public function testPrepareRefusesASnapshotVersionFromTheFuture(): void
    {
        $this->expectException(UnsupportedSnapshotVersionException::class);

        $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION + 1,
            'fields' => ['heading' => 'Written by a newer build'],
        ], []);
    }

    /**
     * TN-7. A snapshot with no usable version is corrupt, not merely old — there has never been a
     * version of this format that omitted it.
     */
    public function testPrepareRefusesASnapshotWithNoVersion(): void
    {
        $this->expectException(UnsupportedSnapshotVersionException::class);

        $this->snapshots->prepareForReproduction(['fields' => ['heading' => 'x']], []);
    }

    /**
     * Matrix detection has to be narrow enough not to catch other array-valued fields. A Table
     * field whose column is named `type` serializes to rows that look superficially like blocks;
     * treating them as blocks would rewrite the value and corrupt it. Every real Matrix block
     * carries `fields` as well as `type`, so requiring both is what separates them.
     */
    public function testStripContentDoesNotMistakeATableFieldForBlocks(): void
    {
        $stripped = $this->snapshots->stripContent([
            'specs' => [
                ['type' => 'width', 'value' => '100cm'],
                ['type' => 'height', 'value' => '50cm'],
            ],
        ]);

        $this->assertSame(['specs' => []], $stripped, 'a table field is emptied, not restructured');
    }

    public function testPrepareLeavesATableFieldAloneRatherThanFilteringItsRows(): void
    {
        $prepared = $this->snapshots->prepareForReproduction([
            'version' => Snapshots::FORMAT_VERSION,
            'fields' => [
                'specs' => [
                    ['type' => 'width', 'value' => '100cm'],
                ],
            ],
        ], ['specs' => ['somethingElse']]);

        $this->assertSame(
            [['type' => 'width', 'value' => '100cm']],
            $prepared['fields']['specs'],
            'table rows pass through untouched, however the allowed-type map reads',
        );
        $this->assertSame([], $prepared['droppedBlockTypes'], 'and nothing is reported as lost');
    }

    /**
     * Issue #1, BR-40. The Matrix field is still non-empty, so comparing top-level fields alone
     * reported nothing; the lost image has to be named by its path.
     */
    public function testLostValuesNamesANestedLossByItsPath(): void
    {
        $lost = $this->snapshots->lostValues(
            ['blocks' => [['type' => 'imageBlock', 'fields' => ['heading' => 'Hero', 'image' => [101]]]]],
            ['blocks' => [7 => ['type' => 'imageBlock', 'fields' => ['heading' => 'Hero', 'image' => []]]]],
        );

        $this->assertSame(['blocks › imageBlock › image'], $lost);
    }

    public function testLostValuesWalksEveryDepth(): void
    {
        $block = fn(string $type, array $fields) => ['type' => $type, 'enabled' => true, 'fields' => $fields];

        $lost = $this->snapshots->lostValues(
            ['blocks' => [$block('columnsBlock', ['columns' => [$block('column', ['body' => '<p>Kept</p>', 'image' => [5]])]])]],
            ['blocks' => [$block('columnsBlock', ['columns' => [$block('column', ['body' => '<p>Kept</p>', 'image' => null])]])]],
        );

        $this->assertSame(['blocks › columnsBlock › columns › column › image'], $lost);
    }

    /**
     * BR-41. Blocks pair by position, so the second block's loss is not hidden by the first
     * block's value, and a block with no counterpart is itself the loss.
     */
    public function testLostValuesPairsBlocksByPositionAndReportsAMissingBlock(): void
    {
        $lost = $this->snapshots->lostValues(
            ['blocks' => [
                ['type' => 'imageBlock', 'fields' => ['image' => [1]]],
                ['type' => 'imageBlock', 'fields' => ['image' => [2]]],
                ['type' => 'textBlock', 'fields' => ['heading' => 'Last']],
            ]],
            ['blocks' => [
                ['type' => 'imageBlock', 'fields' => ['image' => [1]]],
                ['type' => 'imageBlock', 'fields' => ['image' => []]],
            ]],
        );

        $this->assertSame(['blocks › imageBlock › image', 'blocks › textBlock'], $lost);
    }

    /**
     * BR-41, BR-42. A field whose every block is gone is one loss, and repeated losses of the same
     * path are one problem.
     */
    public function testLostValuesReportsAnEmptiedListOnceAndDeduplicatesPaths(): void
    {
        $twoImages = [
            ['type' => 'imageBlock', 'fields' => ['image' => [1]]],
            ['type' => 'imageBlock', 'fields' => ['image' => [2]]],
        ];

        $this->assertSame(['blocks'], $this->snapshots->lostValues(['blocks' => $twoImages], ['blocks' => []]));
        $this->assertSame(
            ['blocks › imageBlock › image'],
            $this->snapshots->lostValues(['blocks' => $twoImages], ['blocks' => [
                ['type' => 'imageBlock', 'fields' => ['image' => []]],
                ['type' => 'imageBlock', 'fields' => ['image' => []]],
            ]]),
        );
    }

    /**
     * 1.0's behaviour, unchanged: a top-level loss is its bare handle, and a value that was empty
     * in the template is never reported.
     */
    public function testLostValuesKeepsTopLevelBehaviour(): void
    {
        $this->assertSame(
            ['relatedPages'],
            $this->snapshots->lostValues(
                ['heading' => 'Kept', 'relatedPages' => [9], 'text' => null, 'imageshop' => 'null'],
                ['heading' => 'Kept', 'relatedPages' => [], 'text' => null, 'imageshop' => 'null'],
            ),
        );
    }

    private function layoutPage(): array
    {
        return [
            'heading' => 'Top',
            'design' => '{"tone":"dark"}',
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'uid' => 'u-1', 'fields' => [
                    'heading' => 'Nested',
                    'design' => ['columns' => 2, 'spacing' => 'lg'],
                ]],
            ],
        ];
    }

    /**
     * Issue #4, BR-47. A kept handle keeps its value at every depth; everything else is emptied.
     */
    public function testStripContentKeepsTheNamedHandlesAtEveryDepth(): void
    {
        $this->assertSame([
            'heading' => null,
            'design' => '{"tone":"dark"}',
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => [
                    'heading' => null,
                    'design' => ['columns' => 2, 'spacing' => 'lg'],
                ]],
            ],
        ], $this->snapshots->stripContent($this->layoutPage(), ['design']));
    }

    /**
     * BR-48. A handler sees each value with its path and may set keep either way.
     */
    public function testAHandlerDecidesWhatIsKept(): void
    {
        $seen = [];
        $this->snapshots->on(Snapshots::EVENT_DEFINE_KEPT_VALUE, function(KeepValueEvent $event) use (&$seen) {
            $seen[] = implode(Snapshots::PATH_SEPARATOR, $event->path) . ($event->keep ? ' (kept)' : '');

            if ($event->path === ['blocks', 'textBlock', 'heading']) {
                $event->keep = true;
            }
            if ($event->handle === 'design' && count($event->path) === 1) {
                $event->keep = false;
            }
        });

        $stripped = $this->snapshots->stripContent($this->layoutPage(), ['design']);

        $this->assertNull($stripped['design'], 'a handler can drop a configured value');
        $this->assertSame('Nested', $stripped['blocks']['b1']['fields']['heading'], 'and keep one nobody configured');
        $this->assertSame([
            'heading',
            'design (kept)',
            'blocks',
            'blocks › textBlock › heading',
            'blocks › textBlock › design (kept)',
        ], $seen);
    }

    /**
     * BR-49. With nothing configured and nobody listening, 1.0's output exactly — and a kept
     * Matrix keeps its blocks' content but never their uids (BR-7).
     */
    public function testNothingConfiguredIsUnchangedAndAKeptMatrixLosesItsUids(): void
    {
        $this->assertSame(
            $this->snapshots->stripContent($this->layoutPage()),
            $this->snapshots->stripContent($this->layoutPage(), []),
        );
        $this->assertSame(['heading' => null, 'design' => null], array_intersect_key(
            $this->snapshots->stripContent($this->layoutPage()),
            ['heading' => 1, 'design' => 1],
        ));

        $kept = $this->snapshots->stripContent($this->layoutPage(), ['blocks']);
        $this->assertSame('Nested', $kept['blocks']['b1']['fields']['heading']);
        $this->assertArrayNotHasKey('uid', $kept['blocks']['b1']);
    }
}
