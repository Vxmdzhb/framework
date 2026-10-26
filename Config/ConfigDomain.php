<?php
declare(strict_types=1);

namespace AstrX\Config;

use Attribute;

/**
 * Declares which config section a class is configured from, and which file
 * that section lives in.
 *
 * By convention a class's section is its short name, falling back to the
 * parent namespace segment when there's no `{ShortName}.config.php`. Many
 * sections live in a file with another name (section `ImapClient` is in
 * `Mail.config.php`), and that is invisible at the call site. This attribute
 * makes the pairing explicit, and every side uses it:
 *   - {@see ConfigDomainResolver::forClass()}: reading (ModuleLoader);
 *   - {@see ConfigWriter::write()}: writing (admin editors);
 *   - tools/check_config.php: CI, so the declaration can't drift from the
 *     files on disk.
 *
 * Repeatable: a class may own several sections (an admin editor that saves
 * both `ImapClient` and `WebmailService` declares both).
 *
 *   #[ConfigDomain('ImapClient', file: 'Mail')]   // Mail.config.php['ImapClient']
 *   #[ConfigDomain('Routing')]                    // Routing.config.php['Routing']
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ConfigDomain
{
    /**
     * @param string $section Top-level key inside the config array.
     * @param string $file    Config file base name, i.e. `{file}.config.php`.
     *                        Empty means "same as the section name".
     */
    public function __construct(
        public readonly string $section,
        public readonly string $file = '',
    ) {}

    /** The `{name}.config.php` base name this section is declared in. */
    public function fileBaseName(): string
    {
        return $this->file !== '' ? $this->file : $this->section;
    }
}
