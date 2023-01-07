<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase;

use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Translation\Bridge\Phrase\Cache\PhraseCachedResponse;
use Symfony\Component\Translation\Bridge\Phrase\Config\ReadConfig;
use Symfony\Component\Translation\Bridge\Phrase\Config\WriteConfig;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseReadEvent;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseWriteEvent;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phpstan-import-type PhraseReadConfig from ReadConfig
 * @phpstan-import-type PhraseWriteConfig from WriteConfig
 *
 * @phpstan-type PhraseLocale array{id: string, name: string, code: string, fallback_locale?: ?array{id: string, name: string, code: string}}
 *
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseProvider implements ProviderInterface
{
    /**
     * @var array<string, PhraseLocale>
     */
    private static array $phraseLocales = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly LoaderInterface $loader,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $defaultLocale,
        private readonly string $endpoint,
        private readonly ReadConfig $readConfig,
        private readonly WriteConfig $writeConfig,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('phrase://%s', $this->endpoint);
    }

    /**
     * @param array<array-key, mixed> $domains
     * @param array<array-key, mixed> $locales
     */
    public function read(array $domains, array $locales): TranslatorBag
    {
        $translatorBag = new TranslatorBag();

        foreach ($locales as $locale) {
            $phraseLocale = $this->getLocale($locale);

            foreach ($domains as $domain) {
                $this->readConfig->withTag($domain);

                if ($this->readConfig->isFallbackLocaleEnabled() && null !== $fallbackLocale = $this->getFallbackLocale($locale)) {
                    $this->readConfig->withFallbackLocale($fallbackLocale);
                }

                $key = $this->key($locale, $domain, $this->readConfig->getOptions());
                $item = $this->cache->getItem($key);

                $headers = $item->isHit() ? ['If-None-Match' => $item->get()->getEtag()] : [];

                $response = $this->httpClient->request('GET', 'locales/'.$phraseLocale.'/download', [
                    'query' => $this->readConfig->getOptions(),
                    'headers' => $headers,
                ]);

                if (200 !== ($statusCode = $response->getStatusCode()) && 304 !== $statusCode) {
                    $this->logger->error(sprintf('Unable to get translations for locale "%s" from phrase: "%s".', $locale, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to get translations from phrase.');
                }

                $content = 304 === $statusCode ? $item->get()->getContent() : $response->getContent();
                $translatorBag->addCatalogue($this->loader->load($content, $locale, $domain));

                // using weak etags, responses for requests with fallback locale enabled can not be reliably cached...
                if (false === $this->readConfig->isFallbackLocaleEnabled()) {
                    $headers = $response->getHeaders(false);
                    $item->set(new PhraseCachedResponse($headers['etag'][0], $headers['last-modified'][0], $content));
                    $this->cache->save($item);
                }
            }
        }

        $event = new PhraseReadEvent($translatorBag);
        $this->dispatcher->dispatch($event);

        return $event->getBag();
    }

    public function write(TranslatorBagInterface $translatorBag): void
    {
        \assert($translatorBag instanceof TranslatorBag);

        $event = new PhraseWriteEvent($translatorBag);
        $this->dispatcher->dispatch($event);

        /** @var MessageCatalogue[] $catalogues */
        $catalogues = $event->getBag()->getCatalogues();

        foreach ($catalogues as $catalogue) {
            foreach ($catalogue->getDomains() as $domain) {
                if (0 === \count($catalogue->all($domain))) {
                    continue;
                }

                $phraseLocale = $this->getLocale($catalogue->getLocale());

                $content = $this->xliffFileDumper->formatCatalogue($catalogue, $domain, ['default_locale' => $this->defaultLocale]);
                $filename = sprintf('%d-%s-%s.xlf', date('YmdHis'), $domain, $catalogue->getLocale());

                $fields = array_merge($this->writeConfig->withTag($domain)->withLocale($phraseLocale)->getOptions(), ['file' => new DataPart($content, $filename, 'application/xml')]);

                $formData = new FormDataPart($fields);

                $response = $this->httpClient->request('POST', 'uploads', [
                    'body' => $formData->bodyToIterable(),
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                ]);

                if (201 !== $statusCode = $response->getStatusCode()) {
                    $this->logger->error(sprintf('Unable to upload translations for domain "%s" to phrase: "%s".', $domain, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to upload translations to phrase.');
                }
            }
        }
    }

    public function delete(TranslatorBagInterface $translatorBag): void
    {
        $defaultCatalogue = $translatorBag->getCatalogue($this->defaultLocale);

        foreach ($defaultCatalogue->getDomains() as $domain) {
            if ([] === $keys = array_keys($defaultCatalogue->all($domain))) {
                continue;
            }

            $names = array_map(static fn ($v): ?string => preg_replace('/([\s:,])/', '\\\\\\\\$1', $v), $keys);

            foreach ($names as $name) {
                $response = $this->httpClient->request('DELETE', 'keys', [
                    'query' => [
                        'q' => 'name:'.$name,
                    ],
                ]);

                if (200 !== $statusCode = $response->getStatusCode()) {
                    $this->logger->error(sprintf('Unable to delete key "%s" in phrase: "%s".', $name, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to delete key in phrase.');
                }
            }
        }
    }

    public static function resetPhraseLocales(): void
    {
        self::$phraseLocales = [];
    }

    /**
     * @param PhraseReadConfig|PhraseWriteConfig $options
     */
    private function key(string $locale, string $domain, array $options): string
    {
        array_multisort($options);

        return sprintf('%s.%s.%s', $locale, $domain, sha1(serialize($options)));
    }

    private function getLocale(string $locale): string
    {
        if ([] === self::$phraseLocales) {
            $this->initLocales();
        }

        if (!\array_key_exists($locale, self::$phraseLocales)) {
            $this->createLocale($locale);
        }

        return self::$phraseLocales[$locale]['id'];
    }

    private function getFallbackLocale(string $locale): ?string
    {
        return self::$phraseLocales[$locale]['fallback_locale']['code'] ?? null;
    }

    private function createLocale(string $locale): void
    {
        $response = $this->httpClient->request('POST', 'locales', [
            'body' => [
                'name' => $locale,
                'code' => $this->toPhraseLocale($locale),
            ],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (201 !== $statusCode = $response->getStatusCode()) {
            $this->logger->error(sprintf('Unable to create locale "%s" in phrase: "%s".', $locale, $response->getContent(false)));

            $this->throwProviderException($statusCode, $response, 'Unable to create locale phrase.');
        }

        /** @var PhraseLocale $phraseLocale */
        $phraseLocale = $response->toArray();

        self::$phraseLocales[$this->toSymfonyLocale($phraseLocale['code'])] = $phraseLocale;
    }

    private function initLocales(): void
    {
        $response = $this->httpClient->request('GET', 'locales');

        if (200 !== $statusCode = $response->getStatusCode()) {
            $this->logger->error(sprintf('Unable to get locales from phrase: "%s".', $response->getContent(false)));

            $this->throwProviderException($statusCode, $response, 'Unable to get locales from phrase.');
        }

        foreach ($response->toArray() as $phraseLocale) {
            self::$phraseLocales[$this->toSymfonyLocale($phraseLocale['code'])] = $phraseLocale;
        }
    }

    private function throwProviderException(int $statusCode, ResponseInterface $response, string $message): void
    {
        $headers = $response->getHeaders(false);

        throw match (true) {
            429 === $statusCode => new ProviderException(sprintf('Rate limit exceeded (%s). please wait %s seconds.',
                $headers['x-rate-limit-limit'][0],
                $headers['x-rate-limit-reset'][0]
            ), $response),
            $statusCode <= 500 => new ProviderException($message, $response),
            default => new ProviderException('Provider server error.', $response),
        };
    }

    private function toSymfonyLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }

    private function toPhraseLocale(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
