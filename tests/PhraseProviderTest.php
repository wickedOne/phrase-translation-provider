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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Bridge\Phrase\Cache\PhraseCachedResponse;
use Symfony\Component\Translation\Bridge\Phrase\Config\ReadConfig;
use Symfony\Component\Translation\Bridge\Phrase\Config\WriteConfig;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseReadEvent;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseWriteEvent;
use Symfony\Component\Translation\Bridge\Phrase\PhraseProvider;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderExceptionInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\LoggingTranslator;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phpstan-import-type PhraseReadConfig from ReadConfig
 *
 * @phpstan-type ExceptionDefinition array{statusCode: int, expectedExceptionMessage: string, expectedLoggerMessage: string}
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseProviderTest extends TestCase
{
    use HttpClientTrait {
        mergeQueryString as public;
    }

    private MockHttpClient $httpClient;
    private MockObject&LoggerInterface $logger;
    private MockObject&LoaderInterface $loader;
    private MockObject&XliffFileDumper $xliffFileDumper;
    private MockObject&EventDispatcherInterface $dispatcher;
    private MockObject&CacheItemPoolInterface $cache;
    private string $defaultLocale;
    private string $endpoint;
    private MockObject&ReadConfig $readConfig;
    private MockObject&WriteConfig $writeConfig;

    protected function tearDown(): void
    {
        PhraseProvider::resetPhraseLocales();
    }

    /**
     * @param array<string, string|array<string, string>> $providerOptions
     */
    #[DataProvider('toStringProvider')]
    public function testToString(array $providerOptions, ?string $endpoint, string $expected): void
    {
        $provider = $this->createProvider(httpClient: $this->getHttpClient()->withOptions($providerOptions), endpoint: $endpoint);

        self::assertSame($expected, (string) $provider);
    }

    #[DataProvider('readProvider')]
    public function testRead(string $locale, string $localeId, string $domain, string $responseContent, TranslatorBag $expectedTranslatorBag): void
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
            ->with(self::callback(function ($v) use ($locale, $domain) {
                $this->assertStringStartsWith($locale . '.' . $domain . '.', $v);

                return true;
            }))
            ->willReturn($item);

        $this->readConfigWithDefaultValues($domain);

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => $this->getDownloadLocaleResponseMock($domain, $localeId, $responseContent),
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

    #[DataProvider('readProvider')]
    public function testReadCached(string $locale, string $localeId, string $domain, string $responseContent, TranslatorBag $expectedTranslatorBag): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::exactly(2))->method('isHit')->willReturn(true);

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
            ->with(self::callback(function ($v) use ($locale, $domain) {
                $this->assertStringStartsWith($locale . '.' . $domain . '.', $v);

                return true;
            }))
            ->willReturn($item);

        $this->getCache()
            ->expects(self::once())
            ->method('save')
            ->with($item);

        $this->readConfigWithDefaultValues($domain);

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

    public function testReadFallbackLocale(): void
    {
        $locale = 'en_GB';
        $localeId = '13604ec993beefcdaba732812cdb828c';
        $domain = 'messages';

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

        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('isHit')->willReturn(false);
        $item->expects(self::never())->method('set');

        $this->getCache()
            ->expects(self::once())
            ->method('getItem')
            ->with(self::callback(function ($v) use ($locale, $domain) {
                $this->assertStringStartsWith($locale . '.' . $domain . '.', $v);

                return true;
            }))
            ->willReturn($item);

        $this->getCache()->expects(self::never())->method('save');

        $this->getReadConfig()
            ->method('getOptions')
            ->willReturn([
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => $domain,
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
                'fallback_locale_id' => 'de',
            ]);

        $this->getReadConfig()->expects(self::once())->method('withTag')->with($domain)->willReturnSelf();
        $this->getReadConfig()->expects(self::once())->method('withFallbackLocale')->with('de')->willReturnSelf();
        $this->getReadConfig()->expects(self::exactly(2))->method('isFallbackLocaleEnabled')->willReturn(true);
        $this->getLoader()->expects($this->once())->method('load')->willReturn($bag->getCatalogue($locale));

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => function (string $method, string $url, array $options) use ($localeId): ResponseInterface {
                $query = [
                    'file_format' => 'symfony_xliff',
                    'include_empty_translations' => '1',
                    'tags' => 'messages',
                    'format_options' => [
                        'enclose_in_cdata' => '1',
                    ],
                    'fallback_locale_id' => 'de',
                ];

                $queryString = $this->mergeQueryString(null, $query, true);

                $this->assertSame('GET', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales/' . $localeId . '/download?' . $queryString, $url);
                $this->assertNotContains('If-None-Match: W/"625d11cf081b1697cbc216edf6ebb13c"', $options['headers']);
                $this->assertArrayHasKey('query', $options);
                $this->assertSame($query, $options['query']);

                return new MockResponse();
            },
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read([$domain], [$locale]);
    }

    /**
     * @param PhraseReadConfig $options
     */
    #[DataProvider('cacheKeyProvider')]
    public function testCacheKeyOptionsSort(array $options, string $expectedKey): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();

        $this->getCache()->expects(self::once())->method('getItem')->with($expectedKey)->willReturn($item);
        $this->getReadConfig()->method('getOptions')->willReturn($options);

        $this->getReadConfig()->expects(self::once())
            ->method('withTag')
            ->with('messages')
            ->willReturnSelf();

        $this->getLoader()->method('load')->willReturn(new MessageCatalogue('en'));

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => function (string $method): ResponseInterface {
                $this->assertSame('GET', $method);

                return new MockResponse('', ['http_code' => 200, 'response_headers' => [
                    'ETag' => 'W/"625d11cf081b1697cbc216edf6ebb13c"',
                    'Last-Modified' => 'Wed, 28 Dec 2022 13:16:45 GMT',
                ]]);
            },
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

    #[DataProvider('cacheItemProvider')]
    public function testGetCacheItem(mixed $cachedValue, bool $hasMatchHeader): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cachedValue);

        $this->getCache()
            ->expects(self::once())
            ->method('getItem')
            ->willReturn($item);

        $this->getLoader()->method('load')->willReturn(new MessageCatalogue('en'));

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'download locale' => function ($method, $url, $options) use ($hasMatchHeader) {
                if ($hasMatchHeader) {
                    $this->assertArrayHasKey('if-none-match', $options['normalized_headers']);
                } else {
                    $this->assertArrayNotHasKey('if-none-match', $options['normalized_headers']);
                }

                return new MockResponse('', ['http_code' => 200, 'response_headers' => [
                    'ETag' => 'W/"625d11cf081b1697cbc216edf6ebb13c"',
                    'Last-Modified' => 'Wed, 28 Dec 2022 13:16:45 GMT',
                ]]);
            },
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

    public static function cacheItemProvider(): \Generator
    {
        yield 'null value' => [
            'cachedValue' => null,
            'hasMatchHeader' => false,
        ];

        yield 'wrong value' => [
            'cachedValue' => new \stdClass(),
            'hasMatchHeader' => false,
        ];

        $item = new PhraseCachedResponse('W\Foo', 'foo', 'bar');

        yield 'correct value' => [
            'cachedValue' => $item,
            'hasMatchHeader' => true,
        ];
    }

    public function testTranslatorBagAssert(): void
    {
        $this->expectExceptionMessage('assert($translatorBag instanceof TranslatorBag)');

        $trans = $this->createMock(LoggingTranslator::class);
        $provider = $this->createProvider();

        $provider->write($trans);
    }

    public static function cacheKeyProvider(): \Generator
    {
        yield 'sortorder one' => [
            'options' => [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => [],
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ],
            'expectedKey' => 'en_GB.messages.d8c311727922efc26536fc843bfee3e464850205',
        ];

        yield 'sortorder two' => [
            'options' => [
                'include_empty_translations' => '1',
                'file_format' => 'symfony_xliff',
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
                'tags' => [],
            ],
            'expectedKey' => 'en_GB.messages.d8c311727922efc26536fc843bfee3e464850205',
        ];
    }

    #[DataProvider('readProviderExceptionsProvider')]
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

    #[DataProvider('initLocalesExceptionsProvider')]
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

    public function testInitLocalesPaginated(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $this->getCache()->method('getItem')->willReturn($item);

        $this->readConfigWithDefaultValues('messages');

        $this->getLoader()->method('load')->willReturn(new MessageCatalogue('en'));

        $responses = [
            'init locales page 1' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales?per_page=100&page=1', $url);

                return new MockResponse(json_encode([
                    [
                        'id' => '5fea6ed5c21767730918a9400e420832',
                        'name' => 'de',
                        'code' => 'de',
                        'fallback_locale' => null,
                    ],
                ], \JSON_THROW_ON_ERROR), [
                    'http_code' => 200,
                    'response_headers' => [
                        'pagination' => '{"total_count":31,"current_page":1,"current_per_page":25,"previous_page":null,"next_page":2}',
                    ],
                ]);
            },
            'init locales page 2' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('GET', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales?per_page=100&page=2', $url);

                return new MockResponse(json_encode([
                    [
                        'id' => '5fea6ed5c21767730918a9400e420832',
                        'name' => 'de',
                        'code' => 'de',
                        'fallback_locale' => null,
                    ],
                ], \JSON_THROW_ON_ERROR), [
                    'http_code' => 200,
                    'response_headers' => [
                        'pagination' => '{"total_count":31,"current_page":2,"current_per_page":25,"previous_page":null,"next_page":null}',
                    ],
                ]);
            },
            'download locale' => $this->getDownloadLocaleResponseMock('messages', '5fea6ed5c21767730918a9400e420832', ''),
        ];

        $provider = $this->createProvider(httpClient: (new MockHttpClient($responses))->withOptions([
            'base_uri' => 'https://api.phrase.com/api/v2/projects/1/',
            'headers' => [
                'Authorization' => 'token API_TOKEN',
                'User-Agent' => 'myProject',
            ],
        ]), endpoint: 'api.phrase.com/api/v2');

        $provider->read(['messages'], ['de']);
    }

    public function testCreateUnknownLocale(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $this->getCache()->method('getItem')->willReturn($item);

        $this->readConfigWithDefaultValues('messages');

        $this->getLoader()->method('load')->willReturn(new MessageCatalogue('en'));

        $responses = [
            'init locales' => $this->getInitLocaleResponseMock(),
            'create locale' => function (string $method, string $url, array $options = []): ResponseInterface {
                $this->assertSame('POST', $method);
                $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales', $url);
                $this->assertSame('Content-Type: application/x-www-form-urlencoded', $options['normalized_headers']['content-type'][0]);
                $this->assertArrayHasKey('body', $options);
                $this->assertSame('name=nl-NL&code=nl-NL&default=0', $options['body']);

                return new MockResponse(json_encode([
                    'id' => 'zWlsCvkeSK0EBgBVmGpZ4cySWbQ0s1Dk4',
                    'name' => 'nl-NL',
                    'code' => 'nl-NL',
                    'fallback_locale' => null,
                ], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
            },
            'download locale' => $this->getDownloadLocaleResponseMock('messages', 'zWlsCvkeSK0EBgBVmGpZ4cySWbQ0s1Dk4', ''),
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

    #[DataProvider('createLocalesExceptionsProvider')]
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

        $bag->addCatalogue(new MessageCatalogue('de', [
            'validators' => [],
            'messages' => [
                'another:erroneous:key' => 'value to delete',
                'delete this,erroneous:key' => 'translated value',
            ],
        ]));

        $responses = [
            'delete key one' => function (string $method, string $url): ResponseInterface {
                $this->assertSame('DELETE', $method);
                $queryString = $this->mergeQueryString(null, ['q' => 'name:delete\\\\ this\\\\,erroneous\\\\:key'], true);

                $this->assertSame('https://api.phrase.com/api/v2/projects/1/keys?' . $queryString, $url);

                return new MockResponse('', [
                    'http_code' => 200,
                ]);
            },
            'delete key two' => function (string $method, string $url): ResponseInterface {
                $queryString = $this->mergeQueryString(null, ['q' => 'name:another\\\\:erroneous\\\\:key'], true);

                $this->assertSame('https://api.phrase.com/api/v2/projects/1/keys?' . $queryString, $url);

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

    #[DataProvider('deleteExceptionsProvider')]
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

    #[DataProvider('writeProvider')]
    public function testWrite(string $locale, string $localeId, string $domain, string $content, TranslatorBag $bag): void
    {
        $this->writeConfigWithDefaultValues($domain, $localeId);

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
                        $this->assertStringEndsWith($domain . '-' . $locale . '.xlf', $matches[1]);
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

    #[DataProvider('writeExceptionsProvider')]
    public function testWriteProviderExceptions(int $statusCode, string $expectedExceptionMessage, string $expectedLoggerMessage): void
    {
        $this->expectException(ProviderExceptionInterface::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->getLogger()
            ->expects(self::once())
            ->method('error')
            ->with($expectedLoggerMessage);

        $this->getXliffFileDumper()
            ->method('formatCatalogue')
            ->willReturn('');

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

    public static function writeProvider(): \Generator
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
            'content' => $expectedEnglishXliff,
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
            'content' => $expectedGermanXliff,
            'bag' => $bag,
        ];
    }

    public static function toStringProvider(): \Generator
    {
        yield 'default endpoint' => [
            'providerOptions' => [
                'base_uri' => 'https://api.phrase.com/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ],
            'endpoint' => null,
            'expected' => 'phrase://api.phrase.com',
        ];

        yield 'custom endpoint' => [
            'providerOptions' => [
                'base_uri' => 'https://api.us.app.phrase.com/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ],
            'endpoint' => 'api.us.app.phrase.com',
            'expected' => 'phrase://api.us.app.phrase.com',
        ];

        yield 'custom endpoint with port' => [
            'providerOptions' => [
                'base_uri' => 'https://api.us.app.phrase.com:8080/api/v2/projects/PROJECT_ID/',
                'headers' => [
                    'Authorization' => 'token API_TOKEN',
                    'User-Agent' => 'myProject',
                ],
            ],
            'endpoint' => 'api.us.app.phrase.com:8080',
            'expected' => 'phrase://api.us.app.phrase.com:8080',
        ];
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public static function deleteExceptionsProvider(): array
    {
        return self::getExceptionResponses(
            exceptionMessage: 'Unable to delete key in phrase.',
            loggerMessage: 'Unable to delete key "key.to.delete" in phrase: "provider error".',
            statusCode: 500
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public static function writeExceptionsProvider(): array
    {
        return self::getExceptionResponses(
            exceptionMessage: 'Unable to upload translations to phrase.',
            loggerMessage: 'Unable to upload translations for domain "messages" to phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public static function createLocalesExceptionsProvider(): array
    {
        return self::getExceptionResponses(
            exceptionMessage: 'Unable to create locale phrase.',
            loggerMessage: 'Unable to create locale "nl-NL" in phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public static function initLocalesExceptionsProvider(): array
    {
        return self::getExceptionResponses(
            exceptionMessage: 'Unable to get locales from phrase.',
            loggerMessage: 'Unable to get locales from phrase: "provider error".'
        );
    }

    /**
     * @return array<string, ExceptionDefinition>
     */
    public static function readProviderExceptionsProvider(): array
    {
        return self::getExceptionResponses(
            exceptionMessage: 'Unable to get translations from phrase.',
            loggerMessage: 'Unable to get translations for locale "en_GB" from phrase: "provider error".'
        );
    }

    public static function readProvider(): \Generator
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
            'localeId' => '13604ec993beefcdaba732812cdb828c',
            'domain' => 'messages',
            'responseContent' => <<<'XLIFF'
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
            'expectedTranslatorBag' => $bag,
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
            'localeId' => '5fea6ed5c21767730918a9400e420832',
            'domain' => 'validators',
            'responseContent' => <<<'XLIFF'
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
            'expectedTranslatorBag' => $bag,
        ];
    }

    /**
     * @return array<array-key, ExceptionDefinition>
     */
    private static function getExceptionResponses(string $exceptionMessage, string $loggerMessage, int $statusCode = 400): array
    {
        return [
            'bad request' => [
                'statusCode' => $statusCode,
                'expectedExceptionMessage' => $exceptionMessage,
                'expectedLoggerMessage' => $loggerMessage,
            ],
            'rate limit exceeded' => [
                'statusCode' => 429,
                'expectedExceptionMessage' => 'Rate limit exceeded (1000). please wait 60 seconds.',
                'expectedLoggerMessage' => $loggerMessage,
            ],
            'server unavailable' => [
                'statusCode' => 503,
                'expectedExceptionMessage' => 'Provider server error.',
                'expectedLoggerMessage' => $loggerMessage,
            ],
        ];
    }

    private function getDownloadLocaleResponseMock(string $domain, string $localeId, string $responseContent): \Closure
    {
        return function (string $method, string $url, array $options) use ($domain, $localeId, $responseContent): ResponseInterface {
            $query = [
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => $domain,
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ];

            $queryString = $this->mergeQueryString(null, $query, true);

            $this->assertSame('GET', $method);
            $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales/' . $localeId . '/download?' . $queryString, $url);
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
            $this->assertSame('https://api.phrase.com/api/v2/projects/1/locales?per_page=100&page=1', $url);

            return new MockResponse(json_encode([
                [
                    'id' => '5fea6ed5c21767730918a9400e420832',
                    'name' => 'de',
                    'code' => 'de',
                    'fallback_locale' => null,
                ],
                [
                    'id' => '13604ec993beefcdaba732812cdb828c',
                    'name' => 'en-GB',
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
            $endpoint ?? $this->getEndpoint(),
            $this->getReadConfig(),
            $this->getWriteConfig()
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

    private function getReadConfig(): ReadConfig&MockObject
    {
        return $this->readConfig ??= $this->createMock(ReadConfig::class);
    }

    private function getWriteConfig(): WriteConfig&MockObject
    {
        return $this->writeConfig ??= $this->createMock(WriteConfig::class);
    }

    private function readConfigWithDefaultValues(string $domain): void
    {
        $this->getReadConfig()
            ->method('getOptions')
            ->willReturn([
                'file_format' => 'symfony_xliff',
                'include_empty_translations' => '1',
                'tags' => $domain,
                'format_options' => [
                    'enclose_in_cdata' => '1',
                ],
            ]);
    }

    private function writeConfigWithDefaultValues(string $domain, string $phraseLocale): void
    {
        $this->getWriteConfig()
            ->method('getOptions')
            ->willReturn([
                'file_format' => 'symfony_xliff',
                'update_translations' => '1',
                'tags' => $domain,
                'locale_id' => $phraseLocale,
            ]);

        $this->getWriteConfig()
            ->expects(self::once())
            ->method('withTag')
            ->with($domain)
            ->willReturnSelf();

        $this->getWriteConfig()
            ->expects(self::once())
            ->method('withLocale')
            ->with($phraseLocale)
            ->willReturnSelf();
    }
}
