<?php

namespace webdna\pagetemplates\exceptions;

use webdna\pagetemplates\models\PageTemplate;
use yii\base\Exception;

/**
 * Thrown when a scratch page would be saved back over the template it came from, but was never a
 * complete copy of it (BR-30).
 *
 * Reproduction drops a block type the layout no longer allows, and empties a field whose
 * serialized form does not round-trip. On a *new* page that is a cosmetic loss the editor is
 * warned about. Saving such a page back over the template makes the loss permanent — the
 * template's only copy of the missing content is overwritten by a page that never had it.
 *
 * Carries what was lost so the refusal can name it. "Something could not be reproduced" gives a
 * curator nothing to act on.
 */
class IncompleteReproductionException extends Exception
{
    /**
     * @param array{droppedBlockTypes: string[], emptiedFields: string[]} $lost
     */
    public function __construct(
        public readonly PageTemplate $template,
        public readonly array $lost,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'The page produced from "%s" was not a complete copy of it, so saving it back '
                    . 'would permanently lose what could not be reproduced.',
                $template->name,
            ),
            $code,
            $previous,
        );
    }

    public function getName(): string
    {
        return 'Incomplete reproduction';
    }
}
