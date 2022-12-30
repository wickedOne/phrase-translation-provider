<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Bridge\Phrase\Cache\PhraseCachedResponse;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseReadEvent;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseWriteEvent;
use Symfony\Component\Translation\Bridge\Phrase\PhraseProvider;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderExceptionInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phpstan-type ExceptionDefinition array{statusCode: int, exceptionMessage: string, loggerMessage: string}
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private MockObject&LoggerInterface $logger;
    private MockObject&LoaderInterface $loader;
    private MockObject&XliffFileDumper $xliffFileDumper;
    private MockObject&EventDispatcherInterface $dispatcher;
    private MockObject&CacheItemPoolInterface $cache;
    private string $defaultLocale;
    private string $endpoint;

    protected function tearDown(): void
    {
        PhraseProvider::resetPhraseLocales();
    }

    /**
     * @dataProvider toStringProvider
     */
    public function testToString(ProviderInterface $provider, string $expected): void
    {
        self::assertSame($expected, (string) $provider);
    }

    /**
     * @dataProvider readProvider
     */
    public function testRead(string $locale, string $localeId, ?string $fallbackLocale, string $domain, string $responseContent, TranslatorBag $expectedTranslatorBag): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('isHit')->willReturn(false);

        $item
            ->expects(self::once())
            ->method('set')
            ->with(self::callback(function ($item) use ($responseContent) {
                $this->assertSame('W/"625d11cf081b1697cbc216edf6ebb13c"', $item->getEtag());
                $this->assertSame('Wed, 28 Dec 2022 13:16:45 GMT', $item->getModified());
                $this->assertSame($responseContent, $item->getContent());

                return true;
            }));

        $this->getCache()
            ->expects(self::once())
            ->method('getItem')
            ->with($localeId.'.'.$domain.'.'.$fallbackLocale)
            ->willReturn($item);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => $this->getDownloadLocaleResponseMock($domain, $localeId, $fallbackLocale, $responseContent),
        ];

        $this->getLoader()
            ->expects($this->once())
            ->method('load')
            ->willReturn($expectedTranslatorBag->getCatalogue($locale));

        $this->getDispatcher()
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PhraseReadEvent::class));

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $translatorBag = $provider->read([$domain], [$locale]);

        $this->assertSame($expectedTranslatorBag->getCatalogues(), $translatorBag->getCatalogues());
    }

    /**
     * @dataProvider readProvider
     */
    public function testReadCached(string $locale, string $localeId, ?string $fallbackLocale, string $domain, string $responseContent, TranslatorBag $expectedTranslatorBag): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('isHit')->willReturn(true);

        $cachedResponse = new PhraseCachedResponse('W/"625d11cf081b1697cbc216edf6ebb13c"', 'Wed, 28 Dec 2022 13:16:45 GMT', $responseContent);
        $item->expects(self::exactly(2))->method('get')->willReturn($cachedResponse);

        $item
            ->expects(self::once())
            ->method('set')
            ->with(self::callback(function ($item) use ($responseContent) {
                $this->assertSame('W/"625d11cf081b1697cbc216edf6ebb13c"', $item->getEtag());
                $this->assertSame('Wed, 28 Dec 2022 13:16:45 GMT', $item->getModified());
                $this->assertSame($responseContent, $item->getContent());

                return true;
            }));

        $this->getCache()
            ->expects(self::once())
            ->method('getItem')
            ->with($localeId.'.'.$domain.'.'.$fallbackLocale)
            ->willReturn($item);

        $this->getCache()
            ->expects(self::once())
            ->method('save')
            ->with($item);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => function (string $method, string $url, array $options = []): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertContains('If-None-Match: W/"625d11cf081b1697cbc216edf6ebb13c"', $options['headers']);

                return new MockResponse('', ['http_code' => 304, 'response_headers' => [
                    'ETag' => 'W/"625d11cf081b1697cbc216edf6ebb13c"',
                    'Last-Modified' => 'Wed, 28 Dec 2022 13:16:45 GMT',
                ]]);
            },
        ];

        $this->getLoader()
            ->expects($this->once())
            ->method('load')
            ->willReturn($expectedTranslatorBag->getCatalogue($locale));

        $this->getDispatcher()
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PhraseReadEvent::class));

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $translatorBag = $provider->read([$domain], [$locale]);

        $this->assertSame($expectedTranslatorBag->getCatalogues(), $translatorBag->getCatalogues());
    }

    /**
     * @dataProvider readProviderExceptionsProvider
     */
    public function testReadProviderExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'provider error' => new MockResponse('provider error', [
                'http_code' => $statusCode,
                'response_headers' => [
                    'x-rate-limit-limit' => ['1000'],
                    'x-rate-limit-reset' => ['60'],
                ],
            ]),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read(['messages'], ['en_GB']);
    }

    /**
     * @dataProvider initLocalesExceptionsProvider
     */
    public function testInitLocalesExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $responses = [
            'init locales' => new MockResponse('provider error', [
                'http_code' => $statusCode,
                'response_headers' => [
                    'x-rate-limit-limit' => ['1000'],
                    'x-rate-limit-reset' => ['60'],
                ],
            ]),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read(['messages'], ['en_GB']);
    }

    public function testCreateUnknownLocale(): void
    {
        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'create locale' => function (string $method, string $url, array $options = []): ResponseInterface {
                $this->assertSame('POST', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales', $url);
                $this->assertSame('Content-Type: application/x-www-form-urlencoded', $options['normalized_headers']['content-type'][0]);
                $this->assertArrayHasKey('body', $options);
                $this->assertSame('name=nl_NL&code=nl-NL', $options['body']);

                return new MockResponse(json_encode([
                    'id' => 'zWlsCvkeSK0EBgBVmGpZ4cySWbQ0s1Dk4',
                    'name' => 'nl_NL',
                    'code' => 'nl-NL',
                    'fallback_locale' => null,
                ], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
            },
            'download locale' => $this->getDownloadLocaleResponseMock('messages', 'zWlsCvkeSK0EBgBVmGpZ4cySWbQ0s1Dk4', null, ''),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read(['messages'], ['nl_NL']);
    }

    /**
     * @dataProvider createLocalesExceptionsProvider
     */
    public function testCreateLocaleExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'provider error' => new MockResponse('provider error', [
                'http_code' => $statusCode,
                'response_headers' => [
                    'x-rate-limit-limit' => ['1000'],
                    'x-rate-limit-reset' => ['60'],
                ],
            ]),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read(['messages'], ['nl_NL']);
    }

    public function testDelete(): void
    {
        $bag = new TranslatorBag();
        $bag->addCatalogue(new MessageCatalogue('en_GB', [
            'validators' => [],
            'messages' => [
                'delete this,erroneous:key' => 'translated value',
            ],
        ]));

        $responses = [
            'delete key' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('DELETE', $method);
                // https://api.phrase.com/api/v2/projects/1/keys?q=name:delete\\ this\\,erroneous\\:key
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/keys?q=name%3Adelete%5C%5C%20this%5C%5C%2Cerroneous%5C%5C%3Akey', $url);

                return new MockResponse('', [
                    'http_code' => 200,
                ]);
            },
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->delete($bag);
    }

    /**
     * @dataProvider deleteExceptionsProvider
     */
    public function testDeleteProviderExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $responses = [
            'provider error' => new MockResponse('provider error', [
                'http_code' => $statusCode,
                'response_headers' => [
                    'x-rate-limit-limit' => ['1000'],
                    'x-rate-limit-reset' => ['60'],
                ],
            ]),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $bag = new TranslatorBag();
        $bag->addCatalogue(new MessageCatalogue('en_GB', [
            'messages' => [
                'key.to.delete' => 'translated value',
            ],
        ]));

        $provider->delete($bag);
    }

    /**
     * @dataProvider writeProvider
     */
    public function testWrite(string $locale, string $localeId, string $domain, string $content, TranslatorBag $bag): void
    {
        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'upload file' => function (string $method, string $url, array $options = []) use ($domain, $locale, $localeId, $content): ResponseInterface {
                $this->assertSame('POST', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/uploads', $url);

                $testedFileFormat = $testedFileName = $testedContent = $testedLocaleId = $testedTags = $testedUpdateTranslations = false;

                do {
                    $part = $options['body']();

                    if (strpos($part, 'file_format')) {
                        $options['body']();
                        $this->assertSame('symfony_xliff', $options['body']());
                        $testedFileFormat = true;
                    }
                    if (preg_match('/filename="([^"]+)/', $part, $matches)) {
                        $this->assertStringEndsWith($domain.'-'.$locale.'.xlf', $matches[1]);
                        $testedFileName = true;
                    }

                    if (str_starts_with($part, '<?xml')) {
                        $this->assertSame($content, $part);
                        $testedContent = true;
                    }

                    if (strpos($part, 'locale_id')) {
                        $options['body']();
                        $this->assertSame($localeId, $options['body']());
                        $testedLocaleId = true;
                    }

                    if (strpos($part, 'name="tags"')) {
                        $options['body']();
                        $this->assertSame($domain, $options['body']());
                        $testedTags = true;
                    }

                    if (strpos($part, 'name="update_translations"')) {
                        $options['body']();
                        $this->assertSame('1', $options['body']());
                        $testedUpdateTranslations = true;
                    }
                } while ('' !== $part);

                $this->assertTrue($testedFileFormat);
                $this->assertTrue($testedFileName);
                $this->assertTrue($testedContent);
                $this->assertTrue($testedLocaleId);
                $this->assertTrue($testedTags);
                $this->assertTrue($testedUpdateTranslations);

                $this->assertStringStartsWith('Content-Type: multipart/form-data', $options['normalized_headers']['content-type'][0]);

                return new MockResponse('success', ['http_code' => 201]);
            },
        ];

        $this->getDispatcher()
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PhraseWriteEvent::class));

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2', dumper: new XliffFileDumper());

        $provider->write($bag);
    }

    /**
     * @dataProvider writeExceptionsProvider
     */
    public function testWriteProviderExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'provider error' => new MockResponse('provider error', [
                'http_code' => $statusCode,
                'response_headers' => [
                    'x-rate-limit-limit' => ['1000'],
                    'x-rate-limit-reset' => ['60'],
                ],
            ]),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $bag = new TranslatorBag();
        $bag->addCatalogue(new MessageCatalogue('en_GB', [
            'messages' => [
                'key.to.delete' => 'translated value',
            ],
        ]));

        $provider->write($bag);
    }

    public function writeProvider(): \Generator
    {
        $expectedEnglishXliff = <<<'XLIFF'
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en-GB" target-language="en-GB" datatype="plaintext" original="file.ext">
    <header>
      <tool tool-id="symfony" tool-name="Symfony"/>
    </header>
    <body>
      <trans-unit id="qdGDk9Z" resname="general.back">
        <source>general.back</source>
        <target><![CDATA[back &!]]></target>
      </trans-unit>
      <trans-unit id="0ESGki9" resname="general.cancel">
        <source>general.cancel</source>
        <target>Cancel</target>
      </trans-unit>
    </body>
  </file>
</xliff>

XLIFF;

        $bag = new TranslatorBag();
        $bag->addCatalogue(new MessageCatalogue('en_GB', [
            'validators' => [],
            'exceptions' => [],
            'messages' => [
                'general.back' => 'back &!',
                'general.cancel' => 'Cancel',
            ],
        ]));

        yield 'english messages' => [
            'locale' => 'en_GB',
            'localeId' => '13604ec993beefcdaba732812cdb828c',
            'domain' => 'messages',
            'responseContent' => $expectedEnglishXliff,
            'bag' => $bag,
        ];

        $expectedGermanXliff = <<<'XLIFF'
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en-GB" target-language="de" datatype="plaintext" original="file.ext">
    <header>
      <tool tool-id="symfony" tool-name="Symfony"/>
    </header>
    <body>
      <trans-unit id="qdGDk9Z" resname="general.back">
        <source>general.back</source>
        <target>zurück</target>
      </trans-unit>
      <trans-unit id="0ESGki9" resname="general.cancel">
        <source>general.cancel</source>
        <target>Abbrechen</target>
      </trans-unit>
    </body>
  </file>
</xliff>

XLIFF;

        $bag = new TranslatorBag();
        $bag->addCatalogue(new MessageCatalogue('de', [
            'validators' => [
                'general.back' => 'zurück',
                'general.cancel' => 'Abbrechen',
            ],
            'messages' => [],
        ]));

        yield 'german validators' => [
            'locale' => 'de',
            'localeId' => '5fea6ed5c21767730918a9400e420832',
            'domain' => 'validators',
            'responseContent' => $expectedGermanXliff,
            'bag' => $bag,
        ];
    }

    public function toStringProvider(): \Generator
    {
        yield 'default endpoint' => [
            'provider' => $this->createProvider(httpClient: $this->getHttpClient()->withOptions([
                'base_uri' => 'https://api.phrase.com/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ])),
            'expected' => 'phrase://api.phrase.com',
        ];

        yield 'custom endpoint' => [
            'provider' => $this->createProvider(httpClient: $this->getHttpClient()->withOptions([
                'base_uri' => 'https://api.us.app.phrase.com/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ]), endpoint: 'api.us.app.phrase.com'),
            'expected' => 'phrase://api.us.app.phrase.com',
        ];

        yield 'custom endpoint with port' => [
            'provider' => $this->createProvider(httpClient: $this->getHttpClient()->withOptions([
                'base_uri' => 'https://api.us.app.phrase.com:8080/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ]), endpoint: 'api.us.app.phrase.com:8080'),
            'expected' => 'phrase://api.us.app.phrase.com:8080',
        ];
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public function deleteExceptionsProvider(): array
    {
        return $this->getExceptionResponses(
            exceptionMessage: 'Unable to delete key in phrase.',
            loggerMessage: 'Unable to delete key "key.to.delete" in phrase: "provider error".',
            statusCode: 500
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public function writeExceptionsProvider(): array
    {
        return $this->getExceptionResponses(
            exceptionMessage: 'Unable to upload translations to phrase.',
            loggerMessage: 'Unable to upload translations for domain "messages" to phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public function createLocalesExceptionsProvider(): array
    {
        return $this->getExceptionResponses(
            exceptionMessage: 'Unable to create locale phrase.',
            loggerMessage: 'Unable to create locale "nl_NL" in phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public function initLocalesExceptionsProvider(): array
    {
        return $this->getExceptionResponses(
            exceptionMessage: 'Unable to get locales from phrase.',
            loggerMessage: 'Unable to get locales from phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public function readProviderExceptionsProvider(): array
    {
        return $this->getExceptionResponses(
            exceptionMessage: 'Unable to get translations from phrase.',
            loggerMessage: 'Unable to get translations for locale "en_GB" from phrase: "provider error".'
        );
    }

    public function readProvider(): \Generator
    {
        $bag = new TranslatorBag();
        $catalogue = new MessageCatalogue('en_GB', [
            'general.back' => 'back  {{ placeholder }} </rant >',
            'general.cancel' => 'Cancel',
        ]);

        $catalogue->setMetadata('general.back', [
            'notes' => [
                'this should have a cdata section',
            ],
            'target-attributes' => [
                'state' => 'signed-off',
            ],
        ]);

        $catalogue->setMetadata('general.cancel', [
            'target-attributes' => [
                'state' => 'translated',
            ],
        ]);

        $bag->addCatalogue($catalogue);

        yield [
            'locale' => 'en_GB',
            'locale_id' => '13604ec993beefcdaba732812cdb828c',
            'fallback_locale' => 'de',
            'domain' => 'messages',
            'content' => <<<'XLIFF'
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file original="global" datatype="plaintext" source-language="de" target-language="en-GB">
    <body>
      <trans-unit id="general.back" resname="general.back">
        <source xml:lang="de"><![CDATA[zurück </rant >]]></source>
        <target xml:lang="en" state="signed-off"><![CDATA[back  {{ placeholder }} </rant >]]></target>
        <note>this should have a cdata section</note>
      </trans-unit>
      <trans-unit id="general.cancel" resname="general.cancel">
        <source xml:lang="de">Abbrechen</source>
        <target xml:lang="en" state="translated">Cancel</target>
      </trans-unit>
    </body>
  </file>
</xliff>
XLIFF,
            'expected bag' => $bag,
        ];

        $bag = new TranslatorBag();
        $catalogue = new MessageCatalogue('de', [
            'A PHP extension caused the upload to fail.' => 'Eine PHP-Erweiterung verhinderte den Upload.',
            'An empty file is not allowed.' => 'Eine leere Datei ist nicht erlaubt.',
        ]);

        $catalogue->setMetadata('An empty file is not allowed.', [
            'notes' => [
                'be sure not to allow an empty file',
            ],
            'target-attributes' => [
                'state' => 'signed-off',
            ],
        ]);

        $catalogue->setMetadata('A PHP extension caused the upload to fail.', [
            'target-attributes' => [
                'state' => 'signed-off',
            ],
        ], 'validators');

        $bag->addCatalogue($catalogue);

        yield [
            'locale' => 'de',
            'locale_id' => '5fea6ed5c21767730918a9400e420832',
            'fallback_locale' => null,
            'domain' => 'validators',
            'content' => <<<'XLIFF'
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file original="file.ext" datatype="plaintext" source-language="de" target-language="de">
    <body>
      <trans-unit id="A PHP extension caused the upload to fail." resname="A PHP extension caused the upload to fail.">
        <source xml:lang="de">Eine PHP-Erweiterung verhinderte den Upload.</source>
        <target xml:lang="de" state="signed-off">Eine PHP-Erweiterung verhinderte den Upload.</target>
      </trans-unit>
      <trans-unit id="An empty file is not allowed." resname="An empty file is not allowed.">
        <source xml:lang="de">Eine leere Datei ist nicht erlaubt.</source>
        <target xml:lang="de" state="signed-off">Eine leere Datei ist nicht erlaubt.</target>
        <note>be sure not to allow an empty file</note>
      </trans-unit>
    </body>
  </file>
</xliff>
XLIFF,
            'expected bag' => $bag,
        ];
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    private function getExceptionResponses(string $exceptionMessage, string $loggerMessage, int $statusCode = 400): array
    {
        return [
            'bad request' => [
                'statusCode' => $statusCode,
                'exceptionMessage' => $exceptionMessage,
                'loggerMessage' => $loggerMessage,
            ],
            'rate limit exceeded' => [
                'statusCode' => 429,
                'exceptionMessage' => 'Rate limit exceeded (1000). please wait 60 seconds.',
                'loggerMessage' => $loggerMessage,
            ],
            'server unavailable' => [
                'statusCode' => 503,
                'exceptionMessage' => 'Provider server error.',
                'loggerMessage' => $loggerMessage,
            ],
        ];
    }

    private function getDownloadLocaleResponseMock(string $domain, string $localeId, ?string $fallbackLocale, string $responseContent): \Closure
    {
        return function (string $method, string $url, array $options) use ($domain, $localeId, $fallbackLocale, $responseContent): ResponseInterface {
            $query = [
                'file_format' => 'symfony_xliff',
                'tags' => $domain,
                'format_options' => ['enclose_in_cdata'],
                'include_empty_translations' => true,
                'fallback_locale_id' => $fallbackLocale,
            ];

            $this->assertSame('GET', $method);
            $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales/'.$localeId.'/download?'.http_build_query($query), $url);
            $this->assertArrayHasKey('query', $options);
            $this->assertSame($query, $options['query']);

            return new MockResponse($responseContent, ['response_headers' => [
                'ETag' => 'W/"625d11cf081b1697cbc216edf6ebb13c"',
                'Last-Modified' => 'Wed, 28 Dec 2022 13:16:45 GMT',
            ]]);
        };
    }

    private function getInitLocaleResponseMock(): \Closure
    {
        return function (string $method, string $url): ResponseInterface {
            $this->assertSame('GET', $method);
            $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales', $url);

            return new MockResponse(json_encode([
                [
                    'id' => '5fea6ed5c21767730918a9400e420832',
                    'name' => 'de',
                    'code' => 'de',
                    'fallback_locale' => null,
                ],
                [
                    'id' => '13604ec993beefcdaba732812cdb828c',
                    'name' => 'en',
                    'code' => 'en-GB',
                    'fallback_locale' => [
                        'id' => '5fea6ed5c21767730918a9400e420832',
                        'name' => 'de',
                        'code' => 'de',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR));
        };
    }

    private function createProvider(?MockHttpClient $httpClient = null, ?string $endpoint = null, ?XliffFileDumper $dumper = null): ProviderInterface
    {
        return new PhraseProvider(
            $httpClient ?? $this->getHttpClient(),
            $this->getLogger(),
            $this->getLoader(),
            $dumper ?? $this->getXliffFileDumper(),
            $this->getDispatcher(),
            $this->getCache(),
            $this->getDefaultLocale(),
            $endpoint ?? $this->getEndpoint()
        );
    }

    private function getHttpClient(): MockHttpClient
    {
        return $this->httpClient ??= new MockHttpClient();
    }

    private function getLogger(): MockObject&LoggerInterface
    {
        return $this->logger ??= $this->createMock(LoggerInterface::class);
    }

    private function getLoader(): MockObject&LoaderInterface
    {
        return $this->loader ??= $this->createMock(LoaderInterface::class);
    }

    private function getXliffFileDumper(): XliffFileDumper&MockObject
    {
        return $this->xliffFileDumper ??= $this->createMock(XliffFileDumper::class);
    }

    private function getDispatcher(): MockObject&EventDispatcherInterface
    {
        return $this->dispatcher ??= $this->createMock(EventDispatcherInterface::class);
    }

    private function getCache(): MockObject&CacheItemPoolInterface
    {
        return $this->cache ??= $this->createMock(CacheItemPoolInterface::class);
    }

    private function getDefaultLocale(): string
    {
        return $this->defaultLocale ??= 'en_GB';
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ??= 'api.phrase.com';
    }
}
