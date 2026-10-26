<?php

declare(strict_types = 1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a config key read as a flag holds something that is neither a
 * boolean nor one of its unambiguous spellings ('true'/'false', 'yes'/'no',
 * 'on'/'off', 0/1). The caller's default is used, and this says which key
 * needs fixing.
 */
final class ConfigNotABoolDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $domain,
        private readonly string $key,
        /** The value's type as get_debug_type() reports it — never the value. */
        private readonly string $actual,
    ) {
        parent::__construct($id, $level);
    }

    public function domain(): string { return $this->domain; }

    public function key(): string { return $this->key; }

    public function actual(): string { return $this->actual; }
}
