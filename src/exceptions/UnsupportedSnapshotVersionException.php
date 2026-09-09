<?php

namespace webdna\pagetemplates\exceptions;

use yii\base\Exception;

/**
 * Thrown when a template's snapshot was written in a format this build cannot read.
 *
 * A distinct type so callers can catch it precisely and tell the editor that this template is
 * unreadable, rather than reporting a generic failure — or worse, guessing at the format and
 * producing a page that looks right and is not.
 */
class UnsupportedSnapshotVersionException extends Exception
{
    public function getName(): string
    {
        return 'Unsupported snapshot version';
    }
}
