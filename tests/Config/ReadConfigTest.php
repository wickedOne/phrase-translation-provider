<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Bridge\Phrase\Config\ReadConfig;
use Symfony\Component\Translation\Provider\Dsn;

/**
 * @phpstan-import-type PhraseReadConfig from ReadConfig
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class ReadConfigTest extends TestCase
{
    /**
     * @param PhraseReadConfig $expectedOptions
     */
    #[DataProvider('dsnOptionsProvider')]
    public function testCreateFromDsn(string $dsn, array $expectedOptions): void
    {
        $config = ReadConfig::fromDsn(new Dsn($dsn));

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public function testWithTag(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject';

        /** @var PhraseReadConfig $expectedOptions */
        $expectedOptions = [
            'file_format' => 'symfony_xliff',
            'include_empty_translations' => '1',
            'tags' => 'messages',
            'format_options' => [
                'enclose_in_cdata' => '1',
            ],
        ];

        $config = ReadConfig::fromDsn(new Dsn($dsn));
        $config->withTag('messages');

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public function testWithTagAndFallbackLocale(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject';

        /** @var PhraseReadConfig $expectedOptions */
        $expectedOptions = [
            'file_format' => 'symfony_xliff',
            'include_empty_translations' => '1',
            'tags' => 'messages',
            'format_options' => [
                'enclose_in_cdata' => '1',
            ],
            'fallback_locale_id' => 'en',
        ];

        $config = ReadConfig::fromDsn(new Dsn($dsn));
        $config->withTag('messages')->withFallbackLocale('en');

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public function testFallbackLocaleEnabled(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&read[fallback_locale_enabled]=1';
        $config = ReadConfig::fromDsn(new Dsn($dsn));
        $this->assertTrue($config->isFallbackLocaleEnabled());
    }

    public function testFallbackLocaleDisabled(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject';
        $config = ReadConfig::fromDsn(new Dsn($dsn));
        $this->assertFalse($config->isFallbackLocaleEnabled());
    }

    public static function dsnOptionsProvider(): \Generator
    {
        yield 'default options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ],
        ];

        yield 'overwrite non protected options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&&read[format_options][enclose_in_cdata]=0&read[include_empty_translations]=0',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '0',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '0',
                ],
            ],
        ];

        yield 'every single option' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?read%5Binclude_empty_translations%5D=0&read%5Bformat_options%5D%5Binclude_translation_state%5D=1&read%5Bbranch%5D=foo&read%5Bexclude_empty_zero_forms%5D=1&read%5Binclude_translated_keys%5D=1&read%5Bkeep_notranslate_tags%5D=0&read%5Bencoding%5D=UTF-8&read%5Binclude_unverified_translations%5D=1&read%5Buse_last_reviewed_version%5D=1',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '0',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                    'include_translation_state' => '1',
                ],
                'branch' => 'foo',
                'exclude_empty_zero_forms' => '1',
                'include_translated_keys' => '1',
                'keep_notranslate_tags' => '0',
                'encoding' => 'UTF-8',
                'include_unverified_translations' => '1',
                'use_last_reviewed_version' => '1',
            ],
        ];

        yield 'overwrite protected options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&&read[file_format]=yaml&read[tags][]=foo&read[tags][]=bar',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ],
        ];

        yield 'fallback enabled empty translations disabled' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&read[include_empty_translations]=0&read[fallback_locale_enabled]=1',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ],
        ];

        yield 'fallback disabled empty translations disabled' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&read[include_empty_translations]=0&read[fallback_locale_enabled]=0',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '0',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ],
        ];
    }
}
