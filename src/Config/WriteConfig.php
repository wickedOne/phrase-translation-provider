<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Config;

use Symfony\Component\Translation\Provider\Dsn;

/**
 * @phpstan-type PhraseWriteConfig array{
 *  autotranslate?: string,
 *  file_encoding?: string,
 *  file_format: string,
 *  format_options?: array<string, string>,
 *  locale_id: string,
 *  locale_mapping?: array<string, string>,
 *  mark_reviewed?: string,
 *  skip_unverification?: string,
 *  skip_upload_tags?: string,
 *  tags: string,
 *  update_descriptions?: string,
 *  update_translations: string,
 * }
 * @phpstan-type PhraseDsnWriteConfig array{
 *  autotranslate?: string,
 *  file?: string,
 *  file_encoding?: string,
 *  file_format?: string,
 *  format_options?: array<string, string>,
 *  locale_id?: string,
 *  locale_mapping?: array<string, string>,
 *  mark_reviewed?: string,
 *  skip_unverification?: string,
 *  skip_upload_tags?: string,
 *  tags?: string,
 *  update_descriptions?: string,
 *  update_translations?: string,
 * }
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class WriteConfig
{
    private const DEFAULTS = [
        'file_format' => 'symfony_xliff',
        'update_translations' => '1',
    ];

    /**
     * @param PhraseWriteConfig $options
     */
    final private function __construct(
        private array $options,
    ) {
    }

    public function withTag(string $tag): self
    {
        $this->options['tags'] = $tag;

        return $this;
    }

    public function withLocale(string $locale): self
    {
        $this->options['locale_id'] = $locale;

        return $this;
    }

    /**
     * @return PhraseWriteConfig
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public static function fromDsn(Dsn $dsn): self
    {
        /** @var PhraseDsnWriteConfig&array $options */
        $options = $dsn->getOptions()['write'] ?? [];

        unset($options['file_format'], $options['tags'], $options['locale_id'], $options['file']);

        /** @var PhraseWriteConfig $configOptions */
        $configOptions = array_merge(self::DEFAULTS, $options);

        return new self($configOptions);
    }
}
