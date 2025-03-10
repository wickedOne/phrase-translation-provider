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
use Symfony\Component\Translation\Bridge\Phrase\Config\WriteConfig;
use Symfony\Component\Translation\Provider\Dsn;

/**
 * @phpstan-import-type PhraseWriteConfig from WriteConfig
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class WriteConfigTest extends TestCase
{
    /**
     * @param PhraseWriteConfig $expectedOptions
     */
    #[DataProvider('dsnOptionsProvider')]
    public function testCreateFromDsn(string $dsn, array $expectedOptions): void
    {
        $config = WriteConfig::fromDsn(new Dsn($dsn));

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public function testWithTag(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject';

        /** @var PhraseWriteConfig $expectedOptions */
        $expectedOptions = [
            'file_format' => 'symfony_xliff',
            'update_translations' => '1',
            'tags' => 'messages',
        ];

        $config = WriteConfig::fromDsn(new Dsn($dsn));
        $config->withTag('messages');

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public function testWithTagAndLocale(): void
    {
        $dsn = 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject';

        /** @var PhraseWriteConfig $expectedOptions */
        $expectedOptions = [
            'file_format' => 'symfony_xliff',
            'update_translations' => '1',
            'tags' => 'messages',
            'locale_id' => 'foo',
        ];

        $config = WriteConfig::fromDsn(new Dsn($dsn));
        $config->withTag('messages')->withLocale('foo');

        $this->assertSame($expectedOptions, $config->getOptions());
    }

    public static function dsnOptionsProvider(): \Generator
    {
        yield 'default options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'update_translations' => '1',
            ],
        ];

        yield 'overwrite non protected options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&write[update_translations]=0',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'update_translations' => '0',
            ],
        ];

        yield 'every single option' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?write%5Bupdate_translations%5D=1&write%5Bupdate_descriptions%5D=0&write%5Bskip_upload_tags%5D=1&write%5Bskip_unverification%5D=0&write%5Bfile_encoding%5D=UTF-8&write%5Blocale_mapping%5D%5Ben%5D=2&write%5Bformat_options%5D%5Bfoo%5D=bar&write%5Bautotranslate%5D=1&write%5Bmark_reviewed%5D=1',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'update_translations' => '1',
                'update_descriptions' => '0',
                'skip_upload_tags' => '1',
                'skip_unverification' => '0',
                'file_encoding' => 'UTF-8',
                'locale_mapping' => ['en' => '2'],
                'format_options' => ['foo' => 'bar'],
                'autotranslate' => '1',
                'mark_reviewed' => '1',
            ],
        ];

        yield 'overwrite protected options' => [
            'dsn' => 'phrase://PROJECT_ID:API_TOKEN@default?userAgent=myProject&write[file_format]=yaml',
            'expectedOptions' => [
                'file_format' => 'symfony_xliff',
                'update_translations' => '1',
            ],
        ];
    }
}
