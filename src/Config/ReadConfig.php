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
 * @phpstan-type PhraseReadConfig array{
 *  file_format: string,
 *  format_options: array<array-key, string>,
 *  tags: string,
 *  fallback_locale_id?: string,
 *  branch?: string,
 *  include_empty_translations?: string,
 *  exclude_empty_zero_forms?: string,
 *  include_translated_keys?: string,
 *  keep_notranslate_tags?: string,
 *  encoding?: string,
 *  include_unverified_translations?: string,
 *  use_last_reviewed_version?: string,
 * }
 * @phpstan-type PhraseDsnReadConfig array{
 *  file_format?: string,
 *  format_options?: array<array-key, mixed>,
 *  tags?: string,
 *  tag?: string,
 *  fallback_locale_enabled?: string,
 *  fallback_locale_id?: string,
 *  branch?: string,
 *  include_empty_translations?: string,
 *  exclude_empty_zero_forms?: string,
 *  include_translated_keys?: string,
 *  keep_notranslate_tags?: string,
 *  encoding?: string,
 *  include_unverified_translations?: string,
 *  use_last_reviewed_version?: string,
 * }
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class ReadConfig
{
    private const DEFAULTS = [
        'file_format' => 'symfony_xliff',
        'include_empty_translations' => '1',
        'tags' => [],
        'format_options' => [
            'enclose_in_cdata' => '1',
        ],
    ];

    /**
     * @param PhraseReadConfig $options
     */
    final private function __construct(
        private array $options,
        private readonly bool $fallbackEnabled
    ) {
    }

    public function withTag(string $tag): self
    {
        $this->options['tags'] = $tag;

        return $this;
    }

    public function isFallbackLocaleEnabled(): bool
    {
        return $this->fallbackEnabled;
    }

    public function withFallbackLocale(string $locale): self
    {
        $this->options['fallback_locale_id'] = $locale;

        return $this;
    }

    /**
     * @return PhraseReadConfig
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public static function fromDsn(Dsn $dsn): self
    {
        /** @var PhraseDsnReadConfig&array $options */
        $options = $dsn->getOptions()['read'] ?? [];
        $fallbackLocale = $options['fallback_locale_enabled'] ?? '0';

        // enforce empty translations when fallback locale is enabled
        if (isset($options['fallback_locale_enabled'], $options['include_empty_translations']) && '1' === $options['fallback_locale_enabled']) {
            $options['include_empty_translations'] = '1';
        }

        unset($options['file_format'], $options['tags'], $options['tag'], $options['fallback_locale_id'], $options['fallback_locale_enabled']);

        $options['format_options'] = array_merge(self::DEFAULTS['format_options'], $options['format_options'] ?? []);

        /** @var PhraseReadConfig $configOptions */
        $configOptions = array_merge(self::DEFAULTS, $options);

        return new self($configOptions, (bool) $fallbackLocale);
    }
}
